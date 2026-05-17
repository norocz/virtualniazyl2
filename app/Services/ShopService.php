<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Payments;
use App\Model\Orm\Entity\PaymentsIn;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Entity\ShopOrderItem;
use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopProduct;
use App\Model\Orm\Entity\ShopRefund;
use App\Model\Orm\Entity\Users;
use App\Model\Orm\Enums\PaymentStatusEnum;
use App\Model\Orm\Enums\RefundInitiatorEnum;
use App\Model\Orm\Enums\ShopOrderStatusEnum;
use App\Model\Orm\Repository\PaymentsRepository;
use App\Model\Orm\Repository\ShopOrderRepository;
use App\Model\Orm\Repository\ShopPayoutRepository;
use App\Model\Orm\Repository\ShopProductRepository;
use App\Model\Orm\Repository\ShopRefundRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Tracy\Debugger;

/**
 * Hlavní byznys logika eshopu.
 *
 * Zodpovědnosti:
 * - vytvoření objednávky (včetně validace skladu)
 * - generování VS (order_number) unikátního napříč systémem
 * - výpočet částek (items + poštovné + poplatek spolku)
 * - spárování příchozí platby s objednávkou podle VS
 * - vytvoření fronty výplat (payout) po platbě
 * - vytvoření refund požadavku při stornu
 *
 * NESAHÁ na odchozí platby - ty vytváří SuperAdmin ručně v batch.
 */
class ShopService
{
    /** Kolik procent si spolek strhává (default; lze přepsat přes system_settings) */
    private const DEFAULT_FEE_PERCENT = 5.0;

    /** Defaultní poštovné v CZK */
    private const DEFAULT_SHIPPING_COST = 99.0;

    /** Kolik hodin platí nezaplacená objednávka */
    private const DEFAULT_EXPIRATION_HOURS = 72;

    private EntityManagerInterface $em;
    private ShopOrderRepository $orderRepo;
    private ShopProductRepository $productRepo;
    private ShopPayoutRepository $payoutRepo;
    private ShopRefundRepository $refundRepo;
    private PaymentsRepository $paymentsRepo;
    private SystemSettingsReader $settings;
    private EmailService $emailService;

    public function __construct(
        EntityManagerInterface $em,
        ShopOrderRepository $orderRepo,
        ShopProductRepository $productRepo,
        ShopPayoutRepository $payoutRepo,
        ShopRefundRepository $refundRepo,
        PaymentsRepository $paymentsRepo,
        SystemSettingsReader $settings,
        EmailService $emailService
    )
    {
        $this->em = $em;
        $this->orderRepo = $orderRepo;
        $this->productRepo = $productRepo;
        $this->payoutRepo = $payoutRepo;
        $this->refundRepo = $refundRepo;
        $this->paymentsRepo = $paymentsRepo;
        $this->settings = $settings;
        $this->emailService = $emailService;
    }

    // =============================================================
    // Vytvoření objednávky
    // =============================================================

    /**
     * Vytvoří novou objednávku z košíku.
     *
     * @param array<int, array{product: ShopProduct, quantity: int}> $cart
     * @param array{name: string, email: string, phone?: string} $buyer
     * @param array{street?: string, houseNumber?: string, city?: string, psc?: string, country?: string, note?: string} $address
     * @param Users|null $user Pokud registrovaný, jinak null = anonymní
     * @param string $preferredLanguage
     * @throws \RuntimeException při nedostatku skladu
     * @throws \InvalidArgumentException při invalidních datech
     */
    public function createOrder(
        array $cart,
        array $buyer,
        array $address,
        ?Users $user = null,
        string $preferredLanguage = 'cs'
    ): ShopOrder
    {
        if (empty($cart)) {
            throw new \InvalidArgumentException('Košík je prázdný.');
        }
        if (empty($buyer['email']) || empty($buyer['name'])) {
            throw new \InvalidArgumentException('Je potřeba vyplnit jméno a e-mail.');
        }

        // Všechny produkty musí patřit jednomu azylu (eshop je per-azyl)
        $azyl = null;
        foreach ($cart as $row) {
            $product = $row['product'];
            if ($azyl === null) {
                $azyl = $product->getAzyl();
            } elseif ($azyl->getId() !== $product->getAzyl()->getId()) {
                throw new \InvalidArgumentException(
                    'Všechny položky musí být z jednoho azylu. ' .
                    'Pro nákup z více azylů vytvořte samostatné objednávky.'
                );
            }
            if (!$product->isAvailable()) {
                throw new \RuntimeException(sprintf(
                    'Produkt "%s" není dostupný.', $product->getName()
                ));
            }
            if (!$product->isUnlimitedStock() && $product->getStock() < $row['quantity']) {
                throw new \RuntimeException(sprintf(
                    'Produktu "%s" není dost skladem (máme %d, požadováno %d).',
                    $product->getName(), $product->getStock(), $row['quantity']
                ));
            }
        }

        $this->em->beginTransaction();
        try {
            $order = new ShopOrder();
            $order->setOrderNumber($this->generateOrderNumber());
            $order->setUser($user);
            $order->setAzyl($azyl);
            $order->setBuyerName($buyer['name']);
            $order->setBuyerEmail($buyer['email']);
            $order->setBuyerPhone($buyer['phone'] ?? null);
            $order->setDeliveryAddress(
                $address['street'] ?? null,
                $address['houseNumber'] ?? null,
                $address['city'] ?? null,
                $address['psc'] ?? null,
                $address['country'] ?? 'Česká republika'
            );
            $order->setDeliveryNote($address['note'] ?? null);
            $order->setPreferredLanguage($preferredLanguage);

            $expiresAt = new DateTimeImmutable(
                '+' . $this->getExpirationHours() . ' hours'
            );
            $order->setExpiresAt($expiresAt);

            $itemsTotal = 0.0;
            foreach ($cart as $row) {
                $product = $row['product'];
                $qty = $row['quantity'];

                $item = new ShopOrderItem();
                $item->setProduct($product);
                $item->setProductName($product->getName());
                $item->setPricing($product->getPrice(), $qty);
                // Snapshot fotky - vezmeme první
                if ($product->getPhotos()->count() > 0) {
                    $firstPhoto = $product->getPhotos()->first();
                    $item->setProductPhotoPath($firstPhoto->getFullPath());
                }

                $order->addItem($item);
                $itemsTotal += $item->getSubtotal();

                // Rezervujeme sklad ihned
                $product->decreaseStock($qty);
            }

            $order->setAmounts(
                $itemsTotal,
                $this->getShippingCost(),
                $this->getFeePercent($azyl)
            );

            $this->orderRepo->persist($order);

            // Vytvoříme také Payments (expected) pro zachování kompatibility se stávajícím systémem
            $payment = new Payments();
            $payment->setPay((int)round($order->getTotalAmount()));
            $payment->setCreatedAt(new DateTimeImmutable());
            $payment->setVariableSymbol((int)$order->getOrderNumber());
            $payment->setComment('Objednávka ' . $order->getOrderNumber());
            $payment->setCurrency($order->getCurrency());
            $payment->setPaymentStatus(PaymentStatusEnum::Expected);
            $payment->setAzyl($azyl);
            $this->paymentsRepo->save($payment);

            $this->em->flush();
            $this->em->commit();

            $this->sendOrderConfirmationEmail($order);
            return $order;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    // =============================================================
    // Spárování platby (z Pythonu nebo ručně)
    // =============================================================

    /**
     * Spárování příchozí platby s objednávkou podle VS.
     * Volá se z PaymentsInService po stažení výpisu.
     *
     * @return bool true pokud se spárovala, false pokud neproběhla žádná akce
     */
    public function matchIncomingPayment(PaymentsIn $payment): bool
    {
        $vs = $payment->getVs();
        if (empty($vs)) {
            return false;
        }

        $order = $this->orderRepo->findByOrderNumber($vs);
        if ($order === null) {
            return false;
        }

        // Objednávka už byla zaplacená nebo ve stavu kdy se platba nepřipouští
        if ($order->getOrderStatus() !== ShopOrderStatusEnum::New) {
            // přišla platba na uzavřenou objednávku → vytvořit refund
            Debugger::log(sprintf(
                'Platba VS=%s přišla na objednávku ve stavu %s. Vytvářím refund.',
                $vs, $order->getOrderStatus()->value
            ), 'shop');

            $this->createRefundForOverpayment($order, $payment);
            return true;
        }

        // Ověření částky (tolerance ±1 Kč na zaokrouhlení)
        $expected = $order->getTotalAmount();
        $received = $payment->getObjem();
        if (abs($received - $expected) > 1.0) {
            // Podplacení / přeplacení - manuální kontrola
            $order->setOrderStatus(ShopOrderStatusEnum::Problem);
            $order->setInternalNote(
                ($order->getInternalNote() ?? '')
                . "\n[system] Částka platby " . $received . ' Kč neodpovídá očekávané ' . $expected . ' Kč.'
            );
            $this->orderRepo->save($order);
            return true;
        }

        // Označit objednávku jako zaplacenou
        $order->markPaid();

        // Vytvořit záznam ve frontě výplat
        $this->createPayoutForOrder($order);

        // Spárovat PaymentsIn
        $payment->setShopOrderId($order->getId());
        $payment->setMatchedAt(new DateTimeImmutable());
        $payment->setMatchStatus('matched');
        $this->em->persist($payment);

        // Aktualizovat stávající Payments (expected → paired)
        $legacyPayment = $this->paymentsRepo->findOneBy([
            'variableSymbol' => (int)$order->getOrderNumber(),
        ]);
        if ($legacyPayment !== null) {
            $legacyPayment->setPaymentStatus(PaymentStatusEnum::Paired);
            $legacyPayment->setPayedAt(new DateTimeImmutable());
            $this->em->persist($legacyPayment);
        }

        $this->em->flush();

        $this->sendOrderPaidEmail($order);
        $this->sendAzylNotificationEmail($order);
        return true;
    }

    // =============================================================
    // Storno / vratka
    // =============================================================

    /**
     * Storno objednávky. Pokud je zaplaceno, vytvoří se refund.
     */
    public function cancelOrder(
        ShopOrder $order,
        RefundInitiatorEnum $initiator,
        ?Users $initiatingUser,
        string $reason
    ): void
    {
        if ($order->getOrderStatus() === ShopOrderStatusEnum::Shipped
            || $order->getOrderStatus() === ShopOrderStatusEnum::Delivered) {
            throw new \RuntimeException(
                'Nelze stornovat už odeslanou/doručenou objednávku. ' .
                'Řešte reklamaci přes komunikaci se zákazníkem.'
            );
        }

        $this->em->beginTransaction();
        try {
            // Vrátit sklad
            foreach ($order->getItems() as $item) {
                if ($item->getProduct() !== null) {
                    $item->getProduct()->increaseStock($item->getQuantity());
                    $this->em->persist($item->getProduct());
                }
            }

            if ($order->isPaid()) {
                // Už zaplaceno - vytvořit refund
                $this->createRefundFromPaidOrder($order, $initiator, $initiatingUser, $reason);
                $order->setOrderStatus(ShopOrderStatusEnum::Refunded);

                // Zrušit případný pending payout
                $payout = $this->em->getRepository(ShopPayout::class)
                    ->findOneBy(['order' => $order]);
                if ($payout !== null) {
                    $payout->markCancelled($reason);
                    $this->em->persist($payout);
                }
            } else {
                $order->setOrderStatus(ShopOrderStatusEnum::Cancelled);
            }

            $order->setInternalNote(
                ($order->getInternalNote() ?? '')
                . "\n[cancel by " . $initiator->value . "] " . $reason
            );
            $this->orderRepo->persist($order);

            $this->em->flush();
            $this->em->commit();

            $this->sendOrderCancelledEmail($order, $reason);
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Expirace nezaplacených objednávek - volá se z CRONu.
     * @return int počet zrušených objednávek
     */
    public function expireUnpaidOrders(): int
    {
        $expired = $this->orderRepo->findExpiredUnpaid();
        $count = 0;
        foreach ($expired as $order) {
            try {
                $this->cancelOrder(
                    $order,
                    RefundInitiatorEnum::System,
                    null,
                    'Automatická expirace - objednávka nebyla zaplacena včas.'
                );
                $count++;
            } catch (\Throwable $e) {
                Debugger::log('Expire order ' . $order->getId() . ' failed: ' . $e->getMessage(), 'shop');
            }
        }
        return $count;
    }

    // =============================================================
    // Change of shipping state (azyl)
    // =============================================================

    public function markOrderAccepted(ShopOrder $order): void
    {
        if ($order->getOrderStatus() !== ShopOrderStatusEnum::Paid) {
            throw new \RuntimeException(
                'Přijmout lze jen zaplacenou objednávku (aktuální stav: '
                . $order->getOrderStatus()->value . ').'
            );
        }
        $order->markAccepted();
        $this->orderRepo->save($order);
    }

    public function markOrderShipped(ShopOrder $order, ?string $trackingNumber = null): void
    {
        if (!in_array($order->getOrderStatus(), [
            ShopOrderStatusEnum::Paid,
            ShopOrderStatusEnum::Accepted,
        ], true)) {
            throw new \RuntimeException('Nelze označit jako odeslané.');
        }
        $order->markShipped($trackingNumber);
        $this->orderRepo->save($order);
        $this->sendOrderShippedEmail($order);
    }

    public function markOrderDelivered(ShopOrder $order): void
    {
        $order->markDelivered();
        $this->orderRepo->save($order);
    }

    // =============================================================
    // Interní helpery
    // =============================================================

    /**
     * Vygeneruje unikátní 10-místné číslo objednávky (= VS pro QR platbu).
     * Formát: YYMMDD + 4 random digits
     */
    private function generateOrderNumber(): string
    {
        $tries = 0;
        while ($tries < 10) {
            $candidate = date('ymd') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if ($this->orderRepo->findByOrderNumber($candidate) === null) {
                return $candidate;
            }
            $tries++;
        }
        throw new \RuntimeException('Nepodařilo se vygenerovat unikátní číslo objednávky.');
    }

    private function createPayoutForOrder(ShopOrder $order): ShopPayout
    {
        $payout = new ShopPayout();
        $payout->setOrder($order);
        $payout->setAzyl($order->getAzyl());
        $payout->setAmount($order->getPayoutAmount());
        $payout->setFeeAmount($order->getFeeAmount());
        $payout->setCurrency($order->getCurrency());
        // Snapshot bankovních údajů
        $azyl = $order->getAzyl();
        $payout->setAzylBankAccount($azyl->getBankAccount());
        $payout->setAzylBankCode($azyl->getBankCode());
        $this->em->persist($payout);
        return $payout;
    }

    private function createRefundFromPaidOrder(
        ShopOrder $order,
        RefundInitiatorEnum $initiator,
        ?Users $user,
        string $reason
    ): ShopRefund
    {
        // Najít původní PaymentsIn aby jsme věděli kam vrátit
        $paymentIn = $this->em->getRepository(PaymentsIn::class)
            ->findOneBy(['shopOrderId' => $order->getId()]);

        if ($paymentIn === null) {
            throw new \RuntimeException(
                'Objednávka je paid, ale nelze najít PaymentsIn - nelze automaticky vytvořit refund.'
            );
        }

        $refund = new ShopRefund();
        $refund->setOrder($order);
        $refund->setPaymentsInId($paymentIn->getId());
        $refund->setAmount($order->getTotalAmount());
        $refund->setRefundAccount($paymentIn->getProtiucet());
        $refund->setRefundBankCode($paymentIn->getKodBanky());
        $refund->setRefundReceiverName($paymentIn->getNazevProtiuctu());
        $refund->setReason($reason);
        $refund->setInitiatedBy($initiator);
        $refund->setInitiatedByUser($user);
        $this->em->persist($refund);
        return $refund;
    }

    private function createRefundForOverpayment(ShopOrder $order, PaymentsIn $payment): ShopRefund
    {
        $refund = new ShopRefund();
        $refund->setOrder($order);
        $refund->setPaymentsInId($payment->getId());
        $refund->setAmount($payment->getObjem());
        $refund->setRefundAccount($payment->getProtiucet());
        $refund->setRefundBankCode($payment->getKodBanky());
        $refund->setRefundReceiverName($payment->getNazevProtiuctu());
        $refund->setReason('Platba přišla na uzavřenou objednávku ' . $order->getOrderNumber());
        $refund->setInitiatedBy(RefundInitiatorEnum::System);

        $payment->setMatchStatus('refund');
        $this->em->persist($payment);
        $this->em->persist($refund);
        $this->em->flush();
        return $refund;
    }

    private function getFeePercent(?Azyl $azyl = null): float
    {
        if ($azyl !== null && $azyl->getShopFeePercent() !== null) {
            return $azyl->getShopFeePercent();
        }
        return (float)$this->settings->get('shop.fee_percent', self::DEFAULT_FEE_PERCENT);
    }

    private function getShippingCost(): float
    {
        return (float)$this->settings->get('shop.default_shipping_cost', self::DEFAULT_SHIPPING_COST);
    }

    private function getExpirationHours(): int
    {
        return (int)$this->settings->get('shop.order_expiration_hours', self::DEFAULT_EXPIRATION_HOURS);
    }

    // =============================================================
    // E-maily - placeholder, integrovat dle existující šablon
    // =============================================================

    private function sendOrderConfirmationEmail(ShopOrder $order): void
    {
        try {
            $this->emailService->sendEmail(
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>',
                $order->getBuyerEmail(),
                'Objednávka ' . $order->getOrderNumber() . ' přijata',
                $this->renderEmailBody('orderConfirmation', $order)
            );
        } catch (\Throwable $e) {
            Debugger::log('Order confirmation email failed: ' . $e->getMessage(), 'shop');
        }
    }

    private function sendOrderPaidEmail(ShopOrder $order): void
    {
        try {
            $this->emailService->sendEmail(
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>',
                $order->getBuyerEmail(),
                'Platba objednávky ' . $order->getOrderNumber() . ' přijata',
                $this->renderEmailBody('orderPaid', $order)
            );
        } catch (\Throwable $e) {
            Debugger::log('Order paid email failed: ' . $e->getMessage(), 'shop');
        }
    }

    private function sendAzylNotificationEmail(ShopOrder $order): void
    {
        $azylEmail = $order->getAzyl()->getEmail();
        if (empty($azylEmail)) {
            return;
        }
        try {
            $this->emailService->sendEmail(
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>',
                $azylEmail,
                'Nová zaplacená objednávka ' . $order->getOrderNumber(),
                $this->renderEmailBody('azylNewOrder', $order)
            );
        } catch (\Throwable $e) {
            Debugger::log('Azyl notification email failed: ' . $e->getMessage(), 'shop');
        }
    }

    private function sendOrderShippedEmail(ShopOrder $order): void
    {
        try {
            $this->emailService->sendEmail(
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>',
                $order->getBuyerEmail(),
                'Vaše objednávka ' . $order->getOrderNumber() . ' byla odeslána',
                $this->renderEmailBody('orderShipped', $order)
            );
        } catch (\Throwable $e) {
            Debugger::log('Order shipped email failed: ' . $e->getMessage(), 'shop');
        }
    }

    private function sendOrderCancelledEmail(ShopOrder $order, string $reason): void
    {
        try {
            $this->emailService->sendEmail(
                'Eshop Virtuální Azyl <shop@virtualniazyl.cz>',
                $order->getBuyerEmail(),
                'Objednávka ' . $order->getOrderNumber() . ' byla stornována',
                $this->renderEmailBody('orderCancelled', $order, ['reason' => $reason])
            );
        } catch (\Throwable $e) {
            Debugger::log('Order cancelled email failed: ' . $e->getMessage(), 'shop');
        }
    }

    /**
     * Jednoduché vykreslení e-mailu. Pro produkci nahradit Latte šablonami.
     */
    private function renderEmailBody(string $type, ShopOrder $order, array $extra = []): string
    {
        $greeting = '<p>Dobrý den ' . htmlspecialchars($order->getBuyerName()) . ',</p>';
        $footer = '<p>S pozdravem,<br>Tým Virtuální Azyl</p>';

        $body = match ($type) {
            'orderConfirmation' => $greeting
                . '<p>Vaše objednávka <strong>' . $order->getOrderNumber() . '</strong> byla přijata.</p>'
                . '<p>Částka k úhradě: <strong>' . $order->getTotalAmount() . ' Kč</strong></p>'
                . '<p>Variabilní symbol: <strong>' . $order->getOrderNumber() . '</strong></p>'
                . '<p>Objednávka čeká na zaplacení do ' . $order->getExpiresAt()->format('d.m.Y H:i') . '.</p>',
            'orderPaid' => $greeting
                . '<p>Děkujeme, platba objednávky <strong>' . $order->getOrderNumber() . '</strong> byla přijata.</p>'
                . '<p>Azyl vás bude brzy kontaktovat ohledně odeslání.</p>',
            'azylNewOrder' => '<p>Nová zaplacená objednávka <strong>' . $order->getOrderNumber() . '</strong>.</p>'
                . '<p>Přejděte do administrace pro vyřízení.</p>',
            'orderShipped' => $greeting
                . '<p>Objednávka <strong>' . $order->getOrderNumber() . '</strong> byla odeslána.</p>'
                . ($order->getShippingTracking()
                    ? '<p>Sledovací číslo: <strong>' . htmlspecialchars($order->getShippingTracking()) . '</strong></p>'
                    : ''),
            'orderCancelled' => $greeting
                . '<p>Vaše objednávka <strong>' . $order->getOrderNumber() . '</strong> byla stornována.</p>'
                . '<p>Důvod: ' . htmlspecialchars($extra['reason'] ?? '-') . '</p>'
                . ($order->isPaid()
                    ? '<p>Peníze vám budou vráceny v nejbližší platební dávce.</p>'
                    : ''),
            default => $greeting,
        };

        return '<html><body>' . $body . $footer . '</body></html>';
    }
}
