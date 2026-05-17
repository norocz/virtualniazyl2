<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\ShopPayoutBatch;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ShopPayoutBatchRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopPayoutBatch::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    public function findByBatchNumber(string $number): ?ShopPayoutBatch
    {
        return $this->findOneBy(['batchNumber' => $number]);
    }

    /** @return ShopPayoutBatch[] */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    public function save(ShopPayoutBatch $batch): void
    {
        $this->getEntityManager()->persist($batch);
        $this->getEntityManager()->flush();
    }
}
