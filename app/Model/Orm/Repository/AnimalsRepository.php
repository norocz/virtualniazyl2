<?php

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Animal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;


class AnimalsRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = Animal::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    public function findAll(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    public function findBySpecies(string $species): array
    {
        return $this->findBy(['species' => $species]);
    }

    public function fetchAll(): array
    {
        return $this->createQueryBuilder('a')
            ->getQuery()
            ->getResult();
    }

    public function saveAnimal(Animal $animal): void
    {
        $this->getEntityManager()->persist($animal);
        $this->getEntityManager()->flush();
    }

    public function findByName(string $name): ?Animal
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findById(int $id): ?Animal
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function deleteAnimal(Animal $animal): void
    {
        $this->getEntityManager()->remove($animal);
        $this->getEntityManager()->flush();
    }

    public function countByAzyl($azyl):int
    {
        return $this->count(['azyl' => $azyl]);

    }
    public function toArray($id): array
    {
        return $this->findOneBy(['id' => $id])->toArray();

    }

    public function persist(Animal $animal)
    {
        $this->getEntityManager()->persist($animal);
    }

    public function remove(Animal $animal)
    {
        $this->getEntityManager()->remove($animal);
    }

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    public function search(string $search): array
    {
        $words = array_filter(
            preg_split('/\s+/', trim($search)),
            fn($word) => mb_strlen($word) > 2
        );

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('a')
            ->from(Animal::class, 'a')
            ->where('a.toAdoption = true')
            ->andWhere('a.isDeleted = false'); // Přidání podmínky pro adopci

        $orX = $qb->expr()->orX();

        foreach ($words as $key => $word) {
            $param = "word$key";
            $orX->add($qb->expr()->like('a.tags', ":$param"));
            $orX->add($qb->expr()->like('a.description', ":$param"));
            $qb->setParameter($param, "%$word%");
        }

        $qb->andWhere($orX)
            ->orderBy('a.id', 'DESC');
        $query = $qb->getQuery();
        $results = $query->getResult();
        usort($results, function ($a, $b) use ($words) {
            $scoreA = 0;
            $scoreB = 0;

            foreach ($words as $word) {
                $scoreA += substr_count($a->getTags() ?? '', $word);
                $scoreA += substr_count($a->getDescription() ?? '', $word);

                $scoreB += substr_count($b->getTags() ?? '', $word);
                $scoreB += substr_count($b->getDescription() ?? '', $word);
            }

            return $scoreB <=> $scoreA;
        });
        $export['results'] = $results;
        $export['words'] = $words;
        return $export;
    }

    public function findNearby(float $lat, float $lng, int $radius): array
    {
        $sql = "
            SELECT a.*
            FROM animals a
            INNER JOIN azyls az ON a.azyl_id = az.id
            WHERE a.to_adoption = 1
              AND a.is_deleted = 0
              AND az.latitude IS NOT NULL
              AND az.longitude IS NOT NULL
              AND (6371 * acos(LEAST(1.0,
                    cos(radians(:lat)) * cos(radians(az.latitude)) * cos(radians(az.longitude) - radians(:lng))
                    + sin(radians(:lat)) * sin(radians(az.latitude))
                  ))) <= :radius
            ORDER BY (6371 * acos(LEAST(1.0,
                    cos(radians(:lat)) * cos(radians(az.latitude)) * cos(radians(az.longitude) - radians(:lng))
                    + sin(radians(:lat)) * sin(radians(az.latitude))
                  )))
        ";

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Animal::class, 'a');

        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm);
        $query->setParameter('lat', $lat);
        $query->setParameter('lng', $lng);
        $query->setParameter('radius', $radius);

        return $query->getResult();
    }

    public function countWithoutCoordinates(): int
    {
        return (int)$this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.latitude IS NULL OR a.longitude IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}