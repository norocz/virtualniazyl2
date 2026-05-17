<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\FoundAnimal;
use App\Model\Orm\Entity\Species;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class FoundAnimalRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(FoundAnimal::class));
    }

    public function findPublicOpen(int $limit = 40): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.isDeleted = false')
            ->andWhere('a.status = :status')
            ->andWhere('a.isEmailConfirmed = true OR a.user IS NOT NULL')
            ->setParameter('status', FoundAnimal::STATUS_OPEN)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findNearbyBySpecies(Species $species, float $lat, float $lon, float $radiusKm = 30): array
    {
        $all = $this->createQueryBuilder('a')
            ->where('a.isDeleted = false')
            ->andWhere('a.status = :status')
            ->andWhere('a.species = :species')
            ->andWhere('a.isEmailConfirmed = true OR a.user IS NOT NULL')
            ->setParameter('status', FoundAnimal::STATUS_OPEN)
            ->setParameter('species', $species)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($all, function (FoundAnimal $a) use ($lat, $lon, $radiusKm) {
            $d = $a->distanceTo($lat, $lon);
            return $d === null || $d <= $radiusKm;
        }));
    }

    public function findByToken(string $token): ?FoundAnimal
    {
        return $this->findOneBy(['secretToken' => $token, 'isDeleted' => false]);
    }

    public function findByConfirmToken(string $token): ?FoundAnimal
    {
        return $this->findOneBy(['confirmToken' => $token, 'isDeleted' => false]);
    }

    public function save(FoundAnimal $animal): void
    {
        $this->getEntityManager()->persist($animal);
        $this->getEntityManager()->flush();
    }
}
