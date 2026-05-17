<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Entity\ShopRefund;
use App\Model\Orm\Enums\RefundStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ShopRefundRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopRefund::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    /**
     * @return ShopRefund[]
     */
    public function findPending(): array
    {
        return $this->findBy(
            ['refundStatus' => RefundStatusEnum::Pending],
            ['createdAt' => 'ASC']
        );
    }

    /** @return ShopRefund[] */
    public function findByBatch(ShopPayoutBatch $batch): array
    {
        return $this->findBy(['batch' => $batch]);
    }

    public function getTotalPendingAmount(): float
    {
        return (float)$this->createQueryBuilder('r')
            ->select('SUM(r.amount)')
            ->where('r.refundStatus = :status')
            ->setParameter('status', RefundStatusEnum::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalPendingCount(): int
    {
        return (int)$this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.refundStatus = :status')
            ->setParameter('status', RefundStatusEnum::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ShopRefund $r): void
    {
        $this->getEntityManager()->persist($r);
        $this->getEntityManager()->flush();
    }
}
