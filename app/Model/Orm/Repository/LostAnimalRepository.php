<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\LostAnimal;
use App\Model\Orm\Entity\Species;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class LostAnimalRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(LostAnimal::class));
    }

    public function findPublicSearching(int $limit = 40): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.isDeleted = false')
            ->andWhere('a.status = :status')
            ->setParameter('status', LostAnimal::STATUS_SEARCHING)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findBySpeciesNearby(Species $species, float $lat, float $lon, float $radiusKm = 30): array
    {
        $all = $this->createQueryBuilder('a')
            ->where('a.isDeleted = false')
            ->andWhere('a.status = :status')
            ->andWhere('a.species = :species')
            ->setParameter('status', LostAnimal::STATUS_SEARCHING)
            ->setParameter('species', $species)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_filter($all, function (LostAnimal $a) use ($lat, $lon, $radiusKm) {
            $d = $a->distanceTo($lat, $lon);
            return $d === null || $d <= $radiusKm;
        });
    }

    public function findByCityAndSpecies(?string $city, ?Species $species, string $status = ''): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.isDeleted = false');

        if ($status !== '') {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ($species !== null) {
            $qb->andWhere('a.species = :species')->setParameter('species', $species);
        }
        if ($city !== null && $city !== '') {
            $qb->andWhere('a.city LIKE :city OR a.location LIKE :city')
               ->setParameter('city', '%' . $city . '%');
        }

        return $qb->orderBy('a.createdAt', 'DESC')->getQuery()->getResult();
    }

    public function findByToken(string $token): ?LostAnimal
    {
        return $this->findOneBy(['secretToken' => $token, 'isDeleted' => false]);
    }

    public function save(LostAnimal $animal): void
    {
        $this->getEntityManager()->persist($animal);
        $this->getEntityManager()->flush();
    }
}
