<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\ShopPayout;
use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Enums\PayoutStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ShopPayoutRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopPayout::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    /**
     * Všechny výplaty čekající na zařazení do batche.
     * @return ShopPayout[]
     */
    public function findPending(): array
    {
        return $this->findBy(
            ['payoutStatus' => PayoutStatusEnum::Pending],
            ['createdAt' => 'ASC']
        );
    }

    /**
     * Výplaty pro konkrétní azyl.
     * @return ShopPayout[]
     */
    public function findByAzyl(int $azylId, ?PayoutStatusEnum $status = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.azyl = :azyl')
            ->setParameter('azyl', $azylId)
            ->orderBy('p.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('p.payoutStatus = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function getTotalPendingAmount(): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) as total')
            ->where('p.payoutStatus = :status')
            ->setParameter('status', PayoutStatusEnum::Pending)
            ->getQuery()
            ->getSingleScalarResult();
        return (float)($result ?? 0);
    }

    /** @return ShopPayout[] */
    public function findByBatch(ShopPayoutBatch $batch): array
    {
        return $this->findBy(['batch' => $batch]);
    }

    public function getTotalPendingCount(): int
    {
        return (int)$this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.payoutStatus = :status')
            ->setParameter('status', PayoutStatusEnum::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ShopPayout $p): void
    {
        $this->getEntityManager()->persist($p);
        $this->getEntityManager()->flush();
    }
}
