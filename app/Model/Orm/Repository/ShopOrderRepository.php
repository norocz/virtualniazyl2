<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Entity\Users;
use App\Model\Orm\Enums\ShopOrderStatusEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;

class ShopOrderRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopOrder::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    public function findByOrderNumber(string $orderNumber): ?ShopOrder
    {
        return $this->findOneBy(['orderNumber' => $orderNumber]);
    }

    /**
     * @return ShopOrder[]
     */
    public function findByAzyl(Azyl $azyl, ?ShopOrderStatusEnum $status = null, int $limit = 100): array
    {
        $criteria = ['azyl' => $azyl];
        if ($status !== null) {
            $criteria['orderStatus'] = $status;
        }
        return $this->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }

    /**
     * @return ShopOrder[]
     */
    public function findByUser(Users $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Nalezení expirovaných nezaplacených objednávek (pro CRON cleanup).
     * @return ShopOrder[]
     */
    public function findExpiredUnpaid(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.orderStatus = :status')
            ->andWhere('o.expiresAt < :now')
            ->setParameter('status', ShopOrderStatusEnum::New)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiky pro admin dashboard.
     */
    public function getStats(): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select(
                'COUNT(o.id) as total',
                'SUM(CASE WHEN o.orderStatus = :new THEN 1 ELSE 0 END) as newCount',
                'SUM(CASE WHEN o.orderStatus = :paid THEN 1 ELSE 0 END) as paidCount',
                'SUM(CASE WHEN o.orderStatus = :shipped THEN 1 ELSE 0 END) as shippedCount',
                'SUM(CASE WHEN o.orderStatus = :delivered THEN o.totalAmount ELSE 0 END) as totalRevenue'
            )
            ->setParameter('new', ShopOrderStatusEnum::New)
            ->setParameter('paid', ShopOrderStatusEnum::Paid)
            ->setParameter('shipped', ShopOrderStatusEnum::Shipped)
            ->setParameter('delivered', ShopOrderStatusEnum::Delivered);

        $result = $qb->getQuery()->getSingleResult();
        return [
            'total'          => (int)($result['total'] ?? 0),
            'newCount'       => (int)($result['newCount'] ?? 0),
            'paidCount'      => (int)($result['paidCount'] ?? 0),
            'shippedCount'   => (int)($result['shippedCount'] ?? 0),
            'totalRevenue'   => (float)($result['totalRevenue'] ?? 0),
        ];
    }

    /**
     * Finanční statistiky objednávek pro daný azyl.
     */
    public function getStatsByAzyl(Azyl $azyl): array
    {
        $result = $this->createQueryBuilder('o')
            ->select(
                'COUNT(o.id) as totalOrders',
                'SUM(CASE WHEN o.orderStatus IN (:activeStatuses) THEN 1 ELSE 0 END) as activeOrders',
                'SUM(CASE WHEN o.orderStatus IN (:activeStatuses) THEN o.totalAmount ELSE 0 END) as totalRevenue',
                'SUM(CASE WHEN o.orderStatus IN (:activeStatuses) THEN o.feeAmount ELSE 0 END) as totalFee',
                'SUM(CASE WHEN o.orderStatus IN (:activeStatuses) THEN o.payoutAmount ELSE 0 END) as totalPayout',
                'SUM(CASE WHEN o.orderStatus = :delivered THEN o.payoutAmount ELSE 0 END) as deliveredPayout',
                'SUM(CASE WHEN o.orderStatus IN (:pendingStatuses) THEN o.payoutAmount ELSE 0 END) as pendingPayout'
            )
            ->where('o.azyl = :azyl')
            ->setParameter('azyl', $azyl)
            ->setParameter('activeStatuses', [ShopOrderStatusEnum::Paid, ShopOrderStatusEnum::Shipped, ShopOrderStatusEnum::Delivered])
            ->setParameter('delivered', ShopOrderStatusEnum::Delivered)
            ->setParameter('pendingStatuses', [ShopOrderStatusEnum::Paid, ShopOrderStatusEnum::Shipped])
            ->getQuery()
            ->getSingleResult();

        return [
            'totalOrders'    => (int)($result['totalOrders'] ?? 0),
            'activeOrders'   => (int)($result['activeOrders'] ?? 0),
            'totalRevenue'   => (float)($result['totalRevenue'] ?? 0),
            'totalFee'       => (float)($result['totalFee'] ?? 0),
            'totalPayout'    => (float)($result['totalPayout'] ?? 0),
            'deliveredPayout' => (float)($result['deliveredPayout'] ?? 0),
            'pendingPayout'  => (float)($result['pendingPayout'] ?? 0),
        ];
    }

    public function save(ShopOrder $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }

    public function persist(ShopOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /** @return int[] Years for which paid orders exist — uses DBAL (DQL has no YEAR()) */
    public function getAvailableYears(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT DISTINCT YEAR(payment_received_at) AS y
             FROM shop_orders
             WHERE payment_received_at IS NOT NULL
             ORDER BY y DESC'
        );
        $years = array_column($rows, 'y');
        if (empty($years)) {
            $years = [(int)date('Y')];
        }
        return array_map('intval', $years);
    }

    /** Full yearly financial breakdown — uses DBAL (DQL has no YEAR/MONTH) */
    public function getYearlyReport(int $year): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $statuses = "'paid','shipped','delivered'";

        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT MONTH(payment_received_at) AS m,
                    SUM(total_amount)  AS incoming_total,
                    SUM(payout_amount) AS payouts_total,
                    SUM(fee_amount)    AS fees_total,
                    COUNT(id)          AS payouts_count
             FROM shop_orders
             WHERE order_status IN ($statuses)
               AND YEAR(payment_received_at) = ?
             GROUP BY m
             ORDER BY m ASC",
            [$year]
        );

        $czechMonths = ['', 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
            'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
        $monthly = [];
        foreach ($monthlyRows as $row) {
            $monthly[] = [
                'month'          => $czechMonths[(int)$row['m']] . ' ' . $year,
                'incoming_total' => (float)$row['incoming_total'],
                'payouts_total'  => (float)$row['payouts_total'],
                'refunds_total'  => 0.0,
                'fees_total'     => (float)$row['fees_total'],
                'payouts_count'  => (int)$row['payouts_count'],
            ];
        }

        $totals = $conn->fetchAssociative(
            "SELECT SUM(total_amount)  AS incoming,
                    SUM(payout_amount) AS payouts,
                    SUM(fee_amount)    AS fees_revenue,
                    COUNT(id)          AS orders_done
             FROM shop_orders
             WHERE order_status IN ($statuses)
               AND YEAR(payment_received_at) = ?",
            [$year]
        );

        $incoming = (float)($totals['incoming'] ?? 0);
        $payouts  = (float)($totals['payouts'] ?? 0);
        $fees     = (float)($totals['fees_revenue'] ?? 0);

        return [
            'monthly'     => $monthly,
            'year_totals' => [
                'incoming'      => $incoming,
                'payouts'       => $payouts,
                'fees_revenue'  => $fees,
                'refunds'       => 0.0,
                'net_cash_flow' => $incoming - $payouts,
                'orders_done'   => (int)($totals['orders_done'] ?? 0),
            ],
        ];
    }

    /** Per-azyl financial summary for a given year — uses DBAL */
    public function getAzylFinancialSummary(int $year): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $statuses = "'paid','shipped','delivered'";

        $rows = $conn->fetchAllAssociative(
            "SELECT a.azyl_name, COUNT(o.id) AS orders_count,
                    SUM(o.total_amount)  AS gross_revenue,
                    SUM(o.fee_amount)    AS fees_deducted,
                    SUM(o.payout_amount) AS payout_earned
             FROM shop_orders o
             JOIN azyls a ON a.id = o.azyl_id
             WHERE o.order_status IN ($statuses)
               AND YEAR(o.payment_received_at) = ?
             GROUP BY a.id, a.azyl_name
             ORDER BY payout_earned DESC",
            [$year]
        );

        return array_map(fn($r) => [
            'azyl_name'     => $r['azyl_name'],
            'orders_count'  => (int)$r['orders_count'],
            'gross_revenue' => (float)$r['gross_revenue'],
            'fees_deducted' => (float)$r['fees_deducted'],
            'payout_earned' => (float)$r['payout_earned'],
            'paid_out'      => 0.0,
            'pending'       => (float)$r['payout_earned'],
        ], $rows);
    }

    public function getGlobalStats(): array
    {
        $now = new DateTimeImmutable();
        $monthStart = $now->modify('first day of this month midnight');

        $fees = (float)$this->createQueryBuilder('o')
            ->select('SUM(o.feeAmount)')
            ->where('o.orderStatus IN (:statuses)')
            ->andWhere('o.paymentReceivedAt >= :from')
            ->setParameter('statuses', [ShopOrderStatusEnum::Paid, ShopOrderStatusEnum::Shipped, ShopOrderStatusEnum::Delivered])
            ->setParameter('from', $monthStart)
            ->getQuery()
            ->getSingleScalarResult();

        return ['feesThisMonth' => $fees ?? 0.0];
    }
}
