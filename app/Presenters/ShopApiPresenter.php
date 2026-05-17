<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Model\Orm\Entity\PaymentsIn;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Repository\ShopOrderRepository;
use App\Services\ShopService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\UI\Presenter;
use Tracy\Debugger;

/**
 * JSON API pro Python démon stahující bankovní výpisy.
 *
 * Všechny endpointy vyžadují header `X-Api-Key` s hodnotou
 * parameters.shopApi.key ze secrets.neon.
 *
 * Endpointy:
 *   POST /shop-api/payments-in    - Python posílá nové platby z banky
 *   GET  /shop-api/orders         - Python může stáhnout čekající objednávky pro párování
 *   POST /shop-api/heartbeat      - Python posílá keepalive
 */
class ShopApiPresenter extends Presenter
{
    private string $apiKey;
    private ShopService $shopService;
    private ShopOrderRepository $orderRepo;
    private EntityManagerInterface $em;

    public function __construct(
        string $apiKey,
        ShopService $shopService,
        ShopOrderRepository $orderRepo,
        EntityManagerInterface $em
    )
    {
        parent::__construct();
        $this->apiKey = $apiKey;
        $this->shopService = $shopService;
        $this->orderRepo = $orderRepo;
        $this->em = $em;
    }

    public function startup(): void
    {
        parent::startup();

        // Kontrola API klíče
        $headerKey = $this->getHttpRequest()->getHeader('X-Api-Key');
        if (empty($this->apiKey) || $headerKey !== $this->apiKey) {
            $this->sendJsonError('Unauthorized', 401);
        }
    }

    /**
     * POST /shop-api/payments-in
     *
     * Body (JSON):
     * {
     *   "payments": [
     *     {
     *       "id": "bank transaction id",
     *       "account_id": "spolek account id",
     *       "datum": "2026-04-22T10:30:00+02:00",
     *       "objem": 1200.00,
     *       "protiucet": "1234567890",
     *       "nazev_protiuctu": "Jan Novák",
     *       "kod_banky": "0100",
     *       "vs": "2604220001",
     *       "ks": null,
     *       "ss": null,
     *       "user_identification": "...",
     *       "recipient_message": "...",
     *       "operation_type": "bezhot_platba",
     *       ...
     *     }
     *   ]
     * }
     *
     * Response:
     * {
     *   "received": 5,
     *   "matched": 3,
     *   "already_exists": 1,
     *   "errors": []
     * }
     */
    public function actionPaymentsIn(): void
    {
        if (!$this->getHttpRequest()->isMethod('POST')) {
            $this->sendJsonError('Method not allowed', 405);
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['payments']) || !is_array($data['payments'])) {
            $this->sendJsonError('Invalid JSON body', 400);
        }

        $stats = [
            'received'        => count($data['payments']),
            'matched'         => 0,
            'already_exists'  => 0,
            'unmatched'       => 0,
            'errors'          => [],
        ];

        foreach ($data['payments'] as $paymentData) {
            try {
                // Deduplicite check - podle bank transaction id
                $existing = $this->em->getRepository(PaymentsIn::class)
                    ->findOneBy(['accountId' => $paymentData['id'] ?? '', 'orderId' => $paymentData['order_id'] ?? null]);

                // Jednodušší check jen podle ID
                if (!empty($paymentData['id'])) {
                    $existing = $this->em->getRepository(PaymentsIn::class)
                        ->findOneBy(['orderId' => $paymentData['id']]);
                    if ($existing !== null) {
                        $stats['already_exists']++;
                        continue;
                    }
                }

                $payment = $this->createPaymentsInFromData($paymentData);
                $this->em->persist($payment);
                $this->em->flush();

                $matched = $this->shopService->matchIncomingPayment($payment);
                if ($matched) {
                    $stats['matched']++;
                } else {
                    $stats['unmatched']++;
                }
            } catch (\Throwable $e) {
                Debugger::log('ShopApi paymentsIn error: ' . $e->getMessage(), 'shopApi');
                $stats['errors'][] = [
                    'payment_id' => $paymentData['id'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->sendJson($stats);
    }

    /**
     * GET /shop-api/orders?status=new
     *
     * Pro Python je užitečné znát seznam čekajících objednávek
     * (pro rychlejší lookup podle VS).
     */
    public function actionOrders(): void
    {
        $status = $this->getHttpRequest()->getQuery('status') ?? 'new';
        $validStatuses = ['new', 'paid', 'all'];
        if (!in_array($status, $validStatuses, true)) {
            $this->sendJsonError('Invalid status', 400);
        }

        $qb = $this->em->createQueryBuilder()
            ->select('o.orderNumber', 'o.totalAmount', 'o.currency', 'o.expiresAt', 'o.orderStatus')
            ->from(ShopOrder::class, 'o')
            ->setMaxResults(1000);

        if ($status !== 'all') {
            $qb->where('o.orderStatus = :s')
                ->setParameter('s', \App\Model\Orm\Enums\ShopOrderStatusEnum::from($status));
        }

        $orders = array_map(static fn($r) => [
            'vs' => $r['orderNumber'],
            'expected_amount' => (float)$r['totalAmount'],
            'currency' => $r['currency'],
            'expires_at' => $r['expiresAt']->format('c'),
            'status' => $r['orderStatus']->value,
        ], $qb->getQuery()->getResult());

        $this->sendJson(['orders' => $orders, 'count' => count($orders)]);
    }

    /**
     * POST /shop-api/heartbeat - Python hlásí že běží.
     * Uložíme timestamp do system_settings jako 'shop.python_last_heartbeat'.
     */
    public function actionHeartbeat(): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO system_settings (setting_key, setting_value, created_at)
             VALUES ('shop.python_last_heartbeat', :t, NOW())
             ON DUPLICATE KEY UPDATE setting_value = :t",
            ['t' => (new DateTimeImmutable())->format('c')]
        );
        $this->sendJson(['ok' => true, 'server_time' => (new DateTimeImmutable())->format('c')]);
    }

    private function createPaymentsInFromData(array $data): PaymentsIn
    {
        $p = new PaymentsIn();
        $p->setAccountId($data['account_id'] ?? '');
        $p->setDatum(new DateTimeImmutable($data['datum'] ?? 'now'));
        $p->setObjem((float)($data['objem'] ?? 0));
        $p->setProtiucet((string)($data['protiucet'] ?? ''));
        $p->setNazevProtiuctu((string)($data['nazev_protiuctu'] ?? ''));
        $p->setKodBanky((string)($data['kod_banky'] ?? ''));
        $p->setBankName($data['bank_name'] ?? null);
        $p->setKs($data['ks'] ?? null);
        $p->setVs($data['vs'] ?? null);
        $p->setSs($data['ss'] ?? null);
        $p->setUserIdentification($data['user_identification'] ?? null);
        $p->setRecipientMessage($data['recipient_message'] ?? null);
        $p->setOperationType((string)($data['operation_type'] ?? 'unknown'));
        $p->setExecutedBy($data['executed_by'] ?? null);
        $p->setDetails($data['details'] ?? null);
        $p->setComment($data['comment'] ?? null);
        $p->setBic($data['bic'] ?? null);
        $p->setOrderId((string)($data['id'] ?? ''));   // použijeme pro deduplikaci
        $p->setPayerReference($data['payer_reference'] ?? null);
        return $p;
    }

    private function sendJsonError(string $message, int $code): void
    {
        $this->getHttpResponse()->setCode($code);
        $this->sendJson(['error' => $message, 'code' => $code]);
    }
}
