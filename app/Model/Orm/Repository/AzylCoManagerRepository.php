<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\AzylCoManager;
use App\Model\Orm\Entity\Users;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class AzylCoManagerRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(AzylCoManager::class));
    }

    public function findByToken(string $token): ?AzylCoManager
    {
        return $this->findOneBy(['inviteToken' => $token]);
    }

    public function findAcceptedForUser(Users $user): ?AzylCoManager
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.acceptedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllForAzyl(Azyl $azyl): array
    {
        return $this->findBy(['azyl' => $azyl]);
    }

    public function findPendingForAzylAndUser(Azyl $azyl, Users $user): ?AzylCoManager
    {
        return $this->createQueryBuilder('m')
            ->where('m.azyl = :azyl')
            ->andWhere('m.user = :user')
            ->setParameter('azyl', $azyl)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(AzylCoManager $m): void
    {
        $this->getEntityManager()->persist($m);
        $this->getEntityManager()->flush();
    }

    public function remove(AzylCoManager $m): void
    {
        $this->getEntityManager()->remove($m);
        $this->getEntityManager()->flush();
    }
}
