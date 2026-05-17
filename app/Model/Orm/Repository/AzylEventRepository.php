<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\AzylEvent;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class AzylEventRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(AzylEvent::class));
    }

    public function findByAzyl(Azyl $azyl): array
    {
        return $this->findBy(['azyl' => $azyl, 'isDeleted' => false], ['dateFrom' => 'DESC']);
    }

    public function findUpcomingByAzyl(Azyl $azyl, int $limit = 10): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.azyl = :azyl')
            ->andWhere('e.isDeleted = false')
            ->andWhere('e.isPublished = true')
            ->andWhere('e.dateTo >= :now OR e.recurrenceType != :none')
            ->setParameter('azyl', $azyl)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('none', 'none')
            ->orderBy('e.dateFrom', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findPublicUpcoming(int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.isDeleted = false')
            ->andWhere('e.isPublished = true')
            ->andWhere('e.dateTo >= :now OR e.recurrenceType != :none')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('none', 'none')
            ->orderBy('e.dateFrom', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findPublicPastByAzyl(Azyl $azyl, int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.azyl = :azyl')
            ->andWhere('e.isDeleted = false')
            ->andWhere('e.isPublished = true')
            ->andWhere('e.dateTo < :now')
            ->andWhere('e.recurrenceType = :none')
            ->setParameter('azyl', $azyl)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('none', 'none')
            ->orderBy('e.dateFrom', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function searchPublicUpcoming(string $query = '', int $limit = 30): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.isDeleted = false')
            ->andWhere('e.isPublished = true')
            ->andWhere('e.dateTo >= :now OR e.recurrenceType != :none')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('none', 'none')
            ->orderBy('e.dateFrom', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('e.title LIKE :q OR e.location LIKE :q OR e.shortDescription LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function save(AzylEvent $event): void
    {
        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();
    }
}
