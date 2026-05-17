<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\PaymentsIn;
use App\Model\Orm\Entity\ShopOrder;
use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Entity\ShopRefund;
use App\Model\Orm\Enums\PayoutStatusEnum;
use App\Model\Orm\Enums\RefundStatusEnum;
use App\Model\Orm\Enums\ShopOrderStatusEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generuje účetní přehledy pro daňové přiznání.
 *
 * Filosofie: Spolek Virtuální azyl je TRANSPARENTNÍ ZPROSTŘEDKOVATEL.
 *   - Zákazník platí spolku (příjem cash na účtu).
 *   - Spolek přepošle azylu (výdaj).
 *   - Rozdíl = příjem spolku = provize (§ výnos pro daň).
 *
 * Klíčové výstupy:
 *   1. Journal (deník) - kronologicky všechny cash pohyby s protistranami
 *   2. Monthly summary - souhrn za měsíc pro jednoduchou evidenci
 *   3. Per-azyl summary - kolik komu vyplaceno (pro potvrzení pro azyl)
 *   4. VAT report - pokud bude spolek někdy plátcem DPH, připraveno
 *
 * Vše exportovatelné do CSV / XLSX pro účetní.
 */
class AccountingReportService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    // =================================================================
    // 1. ÚČETNÍ DENÍK (journal)
    // =================================================================
    //
    // Vrací jeden řádek za každý cash pohyb.
    // Typy: INCOMING_PAYMENT, PAYOUT_TO_AZYL, REFUND_TO_CUSTOMER, FEE_REVENUE
    //
    // Sloupce (pro účetní software / Excel):
    //   datum | typ | doklad | protistrana | účet | částka | DPH | poznámka
    //

    /**
     * @return array<int, array<string,mixed>>
     */
    public function getJournal(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];

        // --- 1a. Příchozí platby (INCOMING_PAYMENT) ---
        $incomingPayments = $this->em->getRepository(PaymentsIn::class)
            ->createQueryBuilder('p')
            ->where('p.datum BETWEEN :from AND :to')
            ->andWhere('p.objem > 0')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.datum', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($incomingPayments as $p) {
            /** @var PaymentsIn $p */
            $order = $p->getShopOrderId() !== null
                ? $this->em->find(ShopOrder::class, $p->getShopOrderId())
                : null;

            $rows[] = [
                'datum'        => $p->getDatum(),
                'typ'          => 'INCOMING_PAYMENT',
                'typ_cz'       => 'Příchozí platba',
                'doklad'       => $p->getVs() ?? '-',
                'protistrana'  => $p->getNazevProtiuctu() ?: 'Neznámý plátce',
                'ucet'         => $p->getProtiucet() . '/' . $p->getKodBanky(),
                'castka'       => $p->getObjem(),
                'mena'         => 'CZK',
                'dph_sazba'    => 0,
                'dph_castka'   => 0,
                'zaklad'       => $p->getObjem(),
                'poznamka'     => $order !== null
                    ? 'Objednávka ' . $order->getOrderNumber()
                    : ($p->getMatchStatus() === 'unmatched'
                        ? 'NESPÁROVÁNO - k vyřešení'
                        : 'Platba mimo eshop'),
                'source_id'    => 'PaymentsIn:' . $p->getId(),
                'related_order' => $order?->getId(),
            ];
        }

        // --- 1b. Výplaty azylům (PAYOUT_TO_AZYL) - jen SENT ---
        $payouts = $this->em->getRepository(ShopPayout::class)
            ->createQueryBuilder('p')
            ->where('p.sentAt BETWEEN :from AND :to')
            ->andWhere('p.payoutStatus = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', PayoutStatusEnum::Sent)
            ->orderBy('p.sentAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($payouts as $p) {
            /** @var ShopPayout $p */
            $rows[] = [
                'datum'        => $p->getSentAt(),
                'typ'          => 'PAYOUT_TO_AZYL',
                'typ_cz'       => 'Výplata azylu',
                'doklad'       => $p->getOrder()->getOrderNumber(),
                'protistrana'  => $p->getAzyl()->getAzylName(),
                'ucet'         => $p->getFullAccount(),
                'castka'       => -$p->getAmount(), // záporné = výdaj
                'mena'         => $p->getCurrency(),
                'dph_sazba'    => 0,
                'dph_castka'   => 0,
                'zaklad'       => -$p->getAmount(),
                'poznamka'     => 'Výplata za obj. ' . $p->getOrder()->getOrderNumber()
                    . ' (batch ' . ($p->getBatch()?->getBatchNumber() ?? '-') . ')',
                'source_id'    => 'ShopPayout:' . $p->getId(),
                'related_order' => $p->getOrder()->getId(),
            ];
        }

        // --- 1c. Vratky zákazníkům (REFUND_TO_CUSTOMER) ---
        $refunds = $this->em->getRepository(ShopRefund::class)
            ->createQueryBuilder('r')
            ->where('r.sentAt BETWEEN :from AND :to')
            ->andWhere('r.refundStatus = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', RefundStatusEnum::Sent)
            ->orderBy('r.sentAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($refunds as $r) {
            /** @var ShopRefund $r */
            $rows[] = [
                'datum'        => $r->getSentAt(),
                'typ'          => 'REFUND_TO_CUSTOMER',
                'typ_cz'       => 'Vratka zákazníkovi',
                'doklad'       => $r->getOrder()->getOrderNumber(),
                'protistrana'  => $r->getRefundReceiverName() ?? '-',
                'ucet'         => $r->getRefundAccount() . '/' . ($r->getRefundBankCode() ?? ''),
                'castka'       => -$r->getAmount(),
                'mena'         => $r->getCurrency(),
                'dph_sazba'    => 0,
                'dph_castka'   => 0,
                'zaklad'       => -$r->getAmount(),
                'poznamka'     => 'Vratka: ' . $r->getReason(),
                'source_id'    => 'ShopRefund:' . $r->getId(),
                'related_order' => $r->getOrder()->getId(),
            ];
        }

        // --- 1d. Provize spolku (FEE_REVENUE) - virtuální zápis ---
        // Provize není samostatný cash pohyb - zůstává na účtu spolku.
        // Ale pro daňovou evidenci ji musíme vykázat jako VÝNOS.
        // Zápis vytváříme v okamžiku ODESLÁNÍ výplaty (cash basis accounting).

        foreach ($payouts as $p) {
            /** @var ShopPayout $p */
            if ($p->getFeeAmount() <= 0) {
                continue;
            }
            $rows[] = [
                'datum'        => $p->getSentAt(),
                'typ'          => 'FEE_REVENUE',
                'typ_cz'       => 'Provize spolku',
                'doklad'       => $p->getOrder()->getOrderNumber(),
                'protistrana'  => $p->getAzyl()->getAzylName(),
                'ucet'         => 'vlastní účet',
                'castka'       => $p->getFeeAmount(),
                'mena'         => $p->getCurrency(),
                'dph_sazba'    => 0, // spolek aktuálně neplátce DPH
                'dph_castka'   => 0,
                'zaklad'       => $p->getFeeAmount(),
                'poznamka'     => sprintf(
                    'Provize z obj. %s (%.1f %% z %.2f Kč)',
                    $p->getOrder()->getOrderNumber(),
                    $p->getOrder()->getFeePercent(),
                    $p->getOrder()->getTotalAmount()
                ),
                'source_id'    => 'Fee:' . $p->getId(),
                'related_order' => $p->getOrder()->getId(),
            ];
        }

        // Seřadit chronologicky
        usort($rows, fn($a, $b) => $a['datum'] <=> $b['datum']);

        return $rows;
    }

    // =================================================================
    // 2. MĚSÍČNÍ SOUHRN
    // =================================================================

    /**
     * @return array{
     *   month: string,
     *   incoming_total: float,
     *   incoming_count: int,
     *   payouts_total: float,
     *   payouts_count: int,
     *   refunds_total: float,
     *   refunds_count: int,
     *   fees_total: float,
     *   cash_balance_change: float,
     *   unmatched_incoming: float,
     *   pending_payouts: float
     * }
     */
    public function getMonthlySummary(int $year, int $month): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $to = $from->modify('last day of this month')->setTime(23, 59, 59);

        $rows = $this->getJournal($from, $to);

        $incomingTotal = 0.0;
        $incomingCount = 0;
        $payoutsTotal = 0.0;
        $payoutsCount = 0;
        $refundsTotal = 0.0;
        $refundsCount = 0;
        $feesTotal = 0.0;
        $unmatchedIncoming = 0.0;

        foreach ($rows as $r) {
            switch ($r['typ']) {
                case 'INCOMING_PAYMENT':
                    $incomingTotal += $r['castka'];
                    $incomingCount++;
                    if (str_starts_with($r['poznamka'], 'NESPÁROVÁNO')) {
                        $unmatchedIncoming += $r['castka'];
                    }
                    break;
                case 'PAYOUT_TO_AZYL':
                    $payoutsTotal += abs($r['castka']);
                    $payoutsCount++;
                    break;
                case 'REFUND_TO_CUSTOMER':
                    $refundsTotal += abs($r['castka']);
                    $refundsCount++;
                    break;
                case 'FEE_REVENUE':
                    $feesTotal += $r['castka'];
                    break;
            }
        }

        // Pending payouts ke konci měsíce (závazek spolku)
        $pendingPayouts = (float)$this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0)')
            ->from(ShopPayout::class, 'p')
            ->where('p.payoutStatus IN (:statuses)')
            ->andWhere('p.createdAt <= :to')
            ->setParameter('statuses', [PayoutStatusEnum::Pending, PayoutStatusEnum::Queued])
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'month'               => $from->format('Y-m'),
            'period_from'         => $from,
            'period_to'           => $to,
            'incoming_total'      => round($incomingTotal, 2),
            'incoming_count'      => $incomingCount,
            'payouts_total'       => round($payoutsTotal, 2),
            'payouts_count'       => $payoutsCount,
            'refunds_total'       => round($refundsTotal, 2),
            'refunds_count'       => $refundsCount,
            'fees_total'          => round($feesTotal, 2),
            'cash_balance_change' => round($incomingTotal - $payoutsTotal - $refundsTotal, 2),
            'unmatched_incoming'  => round($unmatchedIncoming, 2),
            'pending_payouts'     => round($pendingPayouts, 2),
        ];
    }

    // =================================================================
    // 3. SOUHRN ZA AZYL (pro potvrzení o výplatě, daňová evidence)
    // =================================================================

    /**
     * Kolik konkrétní azyl dostal v období + souhrn objednávek.
     * Užitečné pro:
     *   - potvrzení o příjmech pro azyl (on si to dani sám, spolek není plátcem)
     *   - kontrolu konzistence
     *
     * @return array{
     *   azyl_id: int,
     *   azyl_name: string,
     *   orders_count: int,
     *   gross_revenue: float,
     *   fees_deducted: float,
     *   paid_out: float,
     *   pending: float
     * }
     */
    public function getPerAzylSummary(int $azylId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = $this->em->createQueryBuilder()
            ->select(
                'COUNT(DISTINCT o.id) as orders_count',
                'COALESCE(SUM(o.totalAmount), 0) as gross',
                'COALESCE(SUM(o.feeAmount), 0) as fees',
                'COALESCE(SUM(o.payoutAmount), 0) as payout_total'
            )
            ->from(ShopOrder::class, 'o')
            ->where('o.azyl = :azyl')
            ->andWhere('o.paymentReceivedAt BETWEEN :from AND :to')
            ->andWhere('o.orderStatus NOT IN (:excluded)')
            ->setParameter('azyl', $azylId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('excluded', [
                ShopOrderStatusEnum::Cancelled,
                ShopOrderStatusEnum::Refunded,
            ])
            ->getQuery()
            ->getSingleResult();

        // Z toho už reálně vyplaceno
        $paid = (float)$this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0)')
            ->from(ShopPayout::class, 'p')
            ->where('p.azyl = :azyl')
            ->andWhere('p.sentAt BETWEEN :from AND :to')
            ->andWhere('p.payoutStatus = :status')
            ->setParameter('azyl', $azylId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', PayoutStatusEnum::Sent)
            ->getQuery()
            ->getSingleScalarResult();

        $azylName = (string)$this->em->createQueryBuilder()
            ->select('a.azylName')
            ->from(\App\Model\Orm\Entity\Azyl::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $azylId)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'azyl_id'        => $azylId,
            'azyl_name'      => $azylName,
            'period_from'    => $from,
            'period_to'      => $to,
            'orders_count'   => (int)$result['orders_count'],
            'gross_revenue'  => round((float)$result['gross'], 2),
            'fees_deducted'  => round((float)$result['fees'], 2),
            'payout_earned'  => round((float)$result['payout_total'], 2),
            'paid_out'       => round($paid, 2),
            'pending'        => round((float)$result['payout_total'] - $paid, 2),
        ];
    }

    /**
     * Souhrn za všechny azyly v období.
     * @return array<int, array<string,mixed>>
     */
    public function getAllAzylsSummary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $azylIds = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(o.azyl) as azyl_id')
            ->from(ShopOrder::class, 'o')
            ->where('o.paymentReceivedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($azylIds as $row) {
            $result[] = $this->getPerAzylSummary((int)$row['azyl_id'], $from, $to);
        }
        usort($result, fn($a, $b) => $b['gross_revenue'] <=> $a['gross_revenue']);
        return $result;
    }

    // =================================================================
    // 4. ROČNÍ UZÁVĚRKA pro daň z příjmů
    // =================================================================

    /**
     * Agregované hodnoty za celý rok - připraveno pro daňové přiznání.
     */
    public function getYearlyTaxSummary(int $year): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $to = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year));

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[] = $this->getMonthlySummary($year, $m);
        }

        $yearTotal = array_reduce($monthly, function ($acc, $m) {
            $acc['incoming']  += $m['incoming_total'];
            $acc['payouts']   += $m['payouts_total'];
            $acc['refunds']   += $m['refunds_total'];
            $acc['fees']      += $m['fees_total'];
            $acc['orders']    += $m['payouts_count']; // počet vyplacených = počet úspěšných transakcí
            return $acc;
        }, ['incoming' => 0.0, 'payouts' => 0.0, 'refunds' => 0.0, 'fees' => 0.0, 'orders' => 0]);

        return [
            'year'      => $year,
            'from'      => $from,
            'to'        => $to,
            'monthly'   => $monthly,
            'year_totals' => [
                'incoming'       => round($yearTotal['incoming'], 2),
                'payouts'        => round($yearTotal['payouts'], 2),
                'refunds'        => round($yearTotal['refunds'], 2),
                'fees_revenue'   => round($yearTotal['fees'], 2),
                'net_cash_flow'  => round($yearTotal['incoming'] - $yearTotal['payouts'] - $yearTotal['refunds'], 2),
                'orders_done'    => $yearTotal['orders'],
            ],
        ];
    }

    // =================================================================
    // 5. EXPORT DO CSV (pro účetní software / Excel)
    // =================================================================

    /**
     * Deník v CSV formátu pro účetní (ISO dates, semicolon separator, UTF-8 BOM).
     */
    public function exportJournalToCsv(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $rows = $this->getJournal($from, $to);

        // UTF-8 BOM - kvůli Excel
        $csv = "\xEF\xBB\xBF";
        $csv .= implode(';', [
            'Datum', 'Typ', 'Typ_CZ', 'Doklad', 'Protistrana', 'Účet',
            'Částka', 'Měna', 'DPH_sazba', 'DPH_částka', 'Základ', 'Poznámka', 'ID_zdroje'
        ]) . "\r\n";

        foreach ($rows as $r) {
            $csv .= implode(';', [
                $r['datum']->format('Y-m-d H:i:s'),
                $r['typ'],
                '"' . str_replace('"', '""', $r['typ_cz']) . '"',
                '"' . str_replace('"', '""', (string)$r['doklad']) . '"',
                '"' . str_replace('"', '""', (string)$r['protistrana']) . '"',
                '"' . str_replace('"', '""', (string)$r['ucet']) . '"',
                number_format((float)$r['castka'], 2, '.', ''),
                $r['mena'],
                $r['dph_sazba'],
                number_format((float)$r['dph_castka'], 2, '.', ''),
                number_format((float)$r['zaklad'], 2, '.', ''),
                '"' . str_replace('"', '""', (string)$r['poznamka']) . '"',
                $r['source_id'],
            ]) . "\r\n";
        }

        return $csv;
    }

    /**
     * Per-azyl souhrn v CSV.
     */
    public function exportAzylSummaryToCsv(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $summary = $this->getAllAzylsSummary($from, $to);

        $csv = "\xEF\xBB\xBF";
        $csv .= implode(';', [
            'Azyl_ID', 'Azyl', 'Objednávek', 'Hrubý_obrat', 'Provize_strženo',
            'Azylu_náleží', 'Skutečně_vyplaceno', 'Dluh_spolku_azylu'
        ]) . "\r\n";

        foreach ($summary as $s) {
            $csv .= implode(';', [
                $s['azyl_id'],
                '"' . str_replace('"', '""', $s['azyl_name']) . '"',
                $s['orders_count'],
                number_format($s['gross_revenue'], 2, '.', ''),
                number_format($s['fees_deducted'], 2, '.', ''),
                number_format($s['payout_earned'], 2, '.', ''),
                number_format($s['paid_out'], 2, '.', ''),
                number_format($s['pending'], 2, '.', ''),
            ]) . "\r\n";
        }

        return $csv;
    }
}
