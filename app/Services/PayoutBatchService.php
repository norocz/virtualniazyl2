<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Entity\ShopRefund;
use App\Model\Orm\Entity\Users;
use App\Model\Orm\Enums\PayoutStatusEnum;
use App\Model\Orm\Enums\RefundStatusEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tvorba platebních dávek pro SuperAdmina.
 *
 * Flow:
 * 1. SuperAdmin otevře SuperAdmin:shopPayouts - vidí pending payouts a refunds
 * 2. Vytvoří batch (označí které položky chce zařadit)
 * 3. Exportuje batch do formátu banky (ABO, CSV Fio, SEPA XML)
 * 4. Nahraje export do bankovní aplikace, platby odešle
 * 5. Označí batch jako 'sent' → všechny položky v batchi se označí jako sent
 */
class PayoutBatchService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Vytvoří nový batch z vybraných payouts a refunds.
     *
     * @param int[] $payoutIds   IDs výplat azylům k zařazení
     * @param int[] $refundIds   IDs vratek k zařazení
     */
    public function createBatch(array $payoutIds, array $refundIds, Users $createdBy, ?string $notes = null): ShopPayoutBatch
    {
        $this->em->beginTransaction();
        try {
            $batch = new ShopPayoutBatch();
            $batch->setCreatedBy($createdBy);
            $batch->setNotes($notes);
            $this->em->persist($batch);
            $this->em->flush(); // abychom měli ID

            $totalAmount = 0.0;
            $itemCount = 0;

            foreach ($payoutIds as $id) {
                $payout = $this->em->find(ShopPayout::class, $id);
                if ($payout === null || $payout->getPayoutStatus() !== PayoutStatusEnum::Pending) {
                    continue;
                }
                $payout->markQueued($batch);
                $totalAmount += $payout->getAmount();
                $itemCount++;
                $this->em->persist($payout);
            }

            foreach ($refundIds as $id) {
                $refund = $this->em->find(ShopRefund::class, $id);
                if ($refund === null || $refund->getRefundStatus() !== RefundStatusEnum::Pending) {
                    continue;
                }
                $refund->markQueued($batch);
                $totalAmount += $refund->getAmount();
                $itemCount++;
                $this->em->persist($refund);
            }

            $batch->setTotalAmount($totalAmount);
            $batch->setItemCount($itemCount);
            $this->em->persist($batch);

            $this->em->flush();
            $this->em->commit();
            return $batch;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Exportuje batch do CSV ve formátu Fio banky.
     * Formát: datum;částka;kód měny;účet příjemce;kód banky;VS;KS;SS;zpráva pro příjemce
     *
     * Batch se označí jako 'exported'.
     */
    public function exportBatchToFioCsv(ShopPayoutBatch $batch): string
    {
        $lines = [];
        $lines[] = '"datum";"castka";"mena";"ucet_prijemce";"kod_banky";"vs";"ks";"ss";"zprava_prijemci"';
        $today = date('d.m.Y');

        $payouts = $this->em->getRepository(ShopPayout::class)
            ->findBy(['batch' => $batch]);
        foreach ($payouts as $p) {
            $lines[] = implode(';', [
                '"' . $today . '"',
                '"' . number_format($p->getAmount(), 2, '.', '') . '"',
                '"' . $p->getCurrency() . '"',
                '"' . $p->getAzylBankAccount() . '"',
                '"' . $p->getAzylBankCode() . '"',
                '"' . $p->getOrder()->getOrderNumber() . '"',
                '"0558"', // KS pro platby za zboží
                '""',
                '"Eshop ' . $p->getOrder()->getOrderNumber() . '"',
            ]);
        }

        $refunds = $this->em->getRepository(ShopRefund::class)
            ->findBy(['batch' => $batch]);
        foreach ($refunds as $r) {
            $lines[] = implode(';', [
                '"' . $today . '"',
                '"' . number_format($r->getAmount(), 2, '.', '') . '"',
                '"' . $r->getCurrency() . '"',
                '"' . $r->getRefundAccount() . '"',
                '"' . ($r->getRefundBankCode() ?? '') . '"',
                '"' . $r->getOrder()->getOrderNumber() . '"',
                '"0558"',
                '""',
                '"Vratka ' . $r->getOrder()->getOrderNumber() . '"',
            ]);
        }

        $batch->markExported('csv_fio');
        $this->em->persist($batch);
        $this->em->flush();

        return implode("\n", $lines);
    }

    /**
     * Označí batch jako odeslaný - všechny položky přejdou do stavu 'sent'.
     */
    public function markBatchSent(ShopPayoutBatch $batch): void
    {
        $this->em->beginTransaction();
        try {
            $batch->markSent();
            $this->em->persist($batch);

            $payouts = $this->em->getRepository(ShopPayout::class)
                ->findBy(['batch' => $batch]);
            foreach ($payouts as $p) {
                $p->markSent();
                $this->em->persist($p);
            }

            $refunds = $this->em->getRepository(ShopRefund::class)
                ->findBy(['batch' => $batch]);
            foreach ($refunds as $r) {
                $r->markSent();
                $this->em->persist($r);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }
}
