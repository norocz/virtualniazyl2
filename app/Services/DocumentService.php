<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopDocument;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Enums\ShopDocumentTypeEnum;
use App\Model\Orm\Repository\ShopDocumentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vystavování a správa dokladů.
 *
 * Vytváří 3 typy dokladů:
 *
 * 1. CustomerReceipt - potvrzení o přijetí platby
 *    vydavatel: spolek
 *    příjemce:  zákazník
 *    předmět:   celá částka objednávky (zboží + doprava)
 *    vystavuje se: po spárování platby (stav PAID)
 *    účetní princip: spolek je komisionář, platbu přijímá jménem azylu;
 *                    pro zákazníka je to doklad že zaplatil
 *
 * 2. CommissionInvoice - faktura spolku azylu za provozní poplatek
 *    vydavatel: spolek
 *    příjemce:  azyl (IČO azylu!)
 *    předmět:   jen fee_amount (bez DPH pokud spolek není plátce, s DPH pokud ano)
 *    vystavuje se: při odeslání výplaty (status SENT)
 *    účetní princip: spolek si fakturuje svou provizi, která je jeho výnosem;
 *                    azyl si to zaúčtuje jako provozní náklad
 *
 * 3. PayoutStatement - výpis o přijaté výplatě pro azyl (informativní)
 *    vydavatel: spolek
 *    příjemce:  azyl
 *    předmět:   payout_amount (= kolik azyl skutečně dostal)
 *    vystavuje se: při odeslání výplaty (status SENT)
 *    účetní princip: azyl dostává peníze komisionářsky získané spolkem;
 *                    pro azyl je to doklad o příjmu z prodeje
 */
class DocumentService
{
    private EntityManagerInterface $em;
    private ShopDocumentRepository $docRepo;
    private SystemSettingsReader $settings;
    private PdfGeneratorService $pdfGenerator;

    public function __construct(
        EntityManagerInterface $em,
        ShopDocumentRepository $docRepo,
        SystemSettingsReader $settings,
        PdfGeneratorService $pdfGenerator
    )
    {
        $this->em = $em;
        $this->docRepo = $docRepo;
        $this->settings = $settings;
        $this->pdfGenerator = $pdfGenerator;
    }

    // =============================================================
    // 1. Customer Receipt - potvrzení zákazníkovi
    // =============================================================

    public function issueCustomerReceipt(ShopOrder $order): ShopDocument
    {
        // Idempotence - pokud už existuje, vrať ho
        $existing = $this->docRepo->findOneByOrderAndType(
            $order,
            ShopDocumentTypeEnum::CustomerReceipt
        );
        if ($existing !== null) {
            return $existing;
        }

        $doc = new ShopDocument();
        $doc->setDocumentType(ShopDocumentTypeEnum::CustomerReceipt);
        $doc->setDocumentNumber($this->generateNumber(ShopDocumentTypeEnum::CustomerReceipt));
        $doc->setOrder($order);

        $this->applyIssuer($doc);

        // Buyer je zákazník - jméno + adresa z objednávky, IČO/DIČ pokud zadal
        $doc->setBuyer(
            $this->buildBuyerName($order),
            $order->getBillingIco() ?? null,
            $order->getBillingDic() ?? null,
            $order->getFullDeliveryAddress(),
            $order->getBuyerEmail()
        );

        $doc->setDates(
            new DateTimeImmutable(),
            $order->getPaymentReceivedAt(),  // DUZP = den přijetí platby
            null,                            // splatnost N/A - už zaplaceno
            $order->getPaymentReceivedAt()
        );

        // Pro customer receipt se neúčtuje DPH spolku (spolek je jen komisionář)
        $doc->setAmounts(
            $order->getTotalAmount(),
            0,
            $order->getTotalAmount(),
            $order->getCurrency()
        );

        $doc->setPayment($order->getOrderNumber(), 'Bankovní převod (QR)');

        // Položky se snapshot uloží jako JSON
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'name'       => $item->getProductName(),
                'quantity'   => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'subtotal'   => $item->getSubtotal(),
            ];
        }
        // + doprava jako poslední řádek
        if ($order->getShippingCost() > 0) {
            $items[] = [
                'name'       => 'Poštovné a balné',
                'quantity'   => 1,
                'unit_price' => $order->getShippingCost(),
                'subtotal'   => $order->getShippingCost(),
            ];
        }
        $doc->setItems($items);

        // Meta - pro šablonu
        $doc->setMetadata([
            'azyl_name'        => $order->getAzyl()->getAzylName(),
            'azyl_ico'         => method_exists($order->getAzyl(), 'getIco') ? $order->getAzyl()->getIco() : null,
            'order_number'     => $order->getOrderNumber(),
            'commission_model' => true,  // klíč pro šablonu: "komisionářsky"
            'fee_percent'      => $order->getFeePercent(),
            'fee_amount'       => $order->getFeeAmount(),
            'payout_amount'    => $order->getPayoutAmount(),
            'customer_phone'   => $order->getBuyerPhone(),
        ]);

        $this->docRepo->save($doc);
        return $doc;
    }

    // =============================================================
    // 2. Commission Invoice - faktura azylu za provizi
    // =============================================================

    public function issueCommissionInvoice(ShopOrder $order, ShopPayout $payout): ShopDocument
    {
        $existing = $this->docRepo->findOneByOrderAndType(
            $order,
            ShopDocumentTypeEnum::CommissionInvoice
        );
        if ($existing !== null) {
            return $existing;
        }

        $doc = new ShopDocument();
        $doc->setDocumentType(ShopDocumentTypeEnum::CommissionInvoice);
        $doc->setDocumentNumber($this->generateNumber(ShopDocumentTypeEnum::CommissionInvoice));
        $doc->setOrder($order);
        $doc->setPayoutId($payout->getId());

        $this->applyIssuer($doc);

        $azyl = $order->getAzyl();
        $doc->setBuyer(
            $azyl->getAzylName(),
            method_exists($azyl, 'getIco') ? $azyl->getIco() : null,
            method_exists($azyl, 'getDic') ? $azyl->getDic() : null,
            $this->formatAzylAddress($azyl),
            $azyl->getEmail()
        );

        $issuedAt = $payout->getSentAt() ?? new DateTimeImmutable();
        $doc->setDates(
            $issuedAt,
            $issuedAt, // DUZP = den poskytnutí služby (zprostředkování)
            $issuedAt, // splatnost = ihned (už sraženo z výplaty)
            $issuedAt  // zaplaceno - už sraženo
        );

        // Pokud je spolek plátce DPH, účtuje se z provize
        $isVatPayer = (bool)$this->settings->get('invoice.issuer_vat_payer', '0');
        $vatRate = $isVatPayer ? (float)$this->settings->get('invoice.issuer_vat_rate', '21') : 0.0;

        if ($isVatPayer) {
            // Provize je s DPH -> fee_amount obsahuje DPH -> vypočítáme základ
            $subtotal = round($order->getFeeAmount() / (1 + $vatRate / 100), 2);
            $total = $order->getFeeAmount();
        } else {
            $subtotal = $order->getFeeAmount();
            $total = $order->getFeeAmount();
        }

        $doc->setAmounts($subtotal, $vatRate, $total, $order->getCurrency());
        $doc->setPayment($order->getOrderNumber(), 'Sraženo z výplaty');

        $doc->setItems([[
            'name' => sprintf(
                'Provozní poplatek za zprostředkování prodeje objednávky %s (%.1f %% z %.2f Kč)',
                $order->getOrderNumber(),
                $order->getFeePercent(),
                $order->getTotalAmount()
            ),
            'quantity'   => 1,
            'unit_price' => $subtotal,
            'subtotal'   => $subtotal,
        ]]);

        $doc->setMetadata([
            'azyl_id'       => $azyl->getId(),
            'gross_amount'  => $order->getTotalAmount(),
            'fee_percent'   => $order->getFeePercent(),
            'fee_amount'    => $order->getFeeAmount(),
            'payout_amount' => $order->getPayoutAmount(),
            'deduction_model' => true, // "sraženo z výplaty"
        ]);

        $this->docRepo->save($doc);
        return $doc;
    }

    // =============================================================
    // 3. Payout Statement - informativní výpis pro azyl
    // =============================================================

    public function issuePayoutStatement(ShopOrder $order, ShopPayout $payout): ShopDocument
    {
        $existing = $this->docRepo->findOneByOrderAndType(
            $order,
            ShopDocumentTypeEnum::PayoutStatement
        );
        if ($existing !== null) {
            return $existing;
        }

        $doc = new ShopDocument();
        $doc->setDocumentType(ShopDocumentTypeEnum::PayoutStatement);
        $doc->setDocumentNumber($this->generateNumber(ShopDocumentTypeEnum::PayoutStatement));
        $doc->setOrder($order);
        $doc->setPayoutId($payout->getId());

        $this->applyIssuer($doc);

        $azyl = $order->getAzyl();
        $doc->setBuyer(
            $azyl->getAzylName(),
            method_exists($azyl, 'getIco') ? $azyl->getIco() : null,
            method_exists($azyl, 'getDic') ? $azyl->getDic() : null,
            $this->formatAzylAddress($azyl),
            $azyl->getEmail()
        );

        $issuedAt = $payout->getSentAt() ?? new DateTimeImmutable();
        $doc->setDates($issuedAt, $issuedAt, null, $issuedAt);
        $doc->setAmounts($payout->getAmount(), 0, $payout->getAmount(), $payout->getCurrency());
        $doc->setPayment($order->getOrderNumber(), 'Bankovní převod');

        $doc->setItems([[
            'name' => sprintf(
                'Výplata z objednávky %s (zákazník %s zaplatil %.2f Kč, provize %.2f Kč)',
                $order->getOrderNumber(),
                $order->getBuyerName(),
                $order->getTotalAmount(),
                $order->getFeeAmount()
            ),
            'quantity'   => 1,
            'unit_price' => $payout->getAmount(),
            'subtotal'   => $payout->getAmount(),
        ]]);

        $doc->setMetadata([
            'azyl_id'        => $azyl->getId(),
            'gross_amount'   => $order->getTotalAmount(),
            'fee_amount'     => $order->getFeeAmount(),
            'payout_amount'  => $payout->getAmount(),
            'informative'    => true,
        ]);

        $this->docRepo->save($doc);
        return $doc;
    }

    // =============================================================
    // Načtení / generování PDF
    // =============================================================

    /**
     * Vrátí cestu k PDF souboru. Pokud ještě neexistuje, vygeneruje ho.
     */
    public function ensurePdf(ShopDocument $doc): string
    {
        if ($doc->getPdfPath() !== null) {
            $absolute = $this->getAbsolutePdfPath($doc->getPdfPath());
            if (file_exists($absolute)) {
                return $absolute;
            }
        }

        $path = $this->pdfGenerator->generate($doc);
        $doc->attachPdf($path);
        $this->docRepo->save($doc);
        return $this->getAbsolutePdfPath($path);
    }

    /**
     * Smaže cache PDF - užitečné pokud se změní šablona a chceme regenerovat.
     */
    public function invalidatePdf(ShopDocument $doc): void
    {
        if ($doc->getPdfPath() !== null) {
            $absolute = $this->getAbsolutePdfPath($doc->getPdfPath());
            if (file_exists($absolute)) {
                @unlink($absolute);
            }
            $doc->attachPdf('');
            $this->docRepo->save($doc);
        }
    }

    public function getAbsolutePdfPath(string $relative): string
    {
        $storage = rtrim((string)$this->settings->get('invoice.pdf_storage', ''), '/');
        if ($storage === '') {
            $storage = dirname(__DIR__, 2) . '/data/invoices';
        }
        return $storage . '/' . ltrim($relative, '/');
    }

    // =============================================================
    // Interní helpery
    // =============================================================

    /**
     * Naplní issuer data z system_settings.
     */
    private function applyIssuer(ShopDocument $doc): void
    {
        $doc->setIssuer(
            (string)$this->settings->get('invoice.issuer_name', 'Virtuální Azyl z.s.'),
            (string)$this->settings->get('invoice.issuer_ico', '') ?: null,
            (string)$this->settings->get('invoice.issuer_dic', '') ?: null,
            (string)$this->settings->get('invoice.issuer_address', '') ?: null,
            (string)$this->settings->get('shop.spolek_account', '') ?: null,
            (string)$this->settings->get('shop.spolek_bank_code', '') ?: null,
            (string)$this->settings->get('invoice.issuer_registration', '') ?: null,
            (bool)$this->settings->get('invoice.issuer_vat_payer', '0')
        );
    }

    private function buildBuyerName(ShopOrder $order): string
    {
        if (!empty($order->getBillingCompany())) {
            return $order->getBillingCompany();
        }
        return $order->getBuyerName();
    }

    private function formatAzylAddress($azyl): ?string
    {
        if (method_exists($azyl, 'getFullAddress')) {
            return $azyl->getFullAddress();
        }
        $parts = [];
        if (method_exists($azyl, 'getStreet')) $parts[] = $azyl->getStreet();
        if (method_exists($azyl, 'getCity')) $parts[] = $azyl->getCity();
        return implode(', ', array_filter($parts)) ?: null;
    }

    /**
     * Formát čísla dokladu: PREFIX + YYYY + NNNNNN (6 číslic)
     * Příklady:
     *   PP2026000001  - potvrzení
     *   F2026000001   - faktura
     *   VV2026000001  - výpis
     */
    private function generateNumber(ShopDocumentTypeEnum $type): string
    {
        $year = (int)date('Y');
        $next = $this->docRepo->getNextSequenceNumber($year, $type);
        return sprintf('%s%04d%06d', $type->numberPrefix(), $year, $next);
    }
}
