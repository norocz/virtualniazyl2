<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\ShopProduct;
use App\Model\Orm\Entity\Azyl;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ShopProductRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, string $class = ShopProduct::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));
    }

    /**
     * Produkty jednoho azylu (pro Azyl presenter).
     * @return ShopProduct[]
     */
    public function findByAzyl(Azyl $azyl, bool $onlyActive = false): array
    {
        $criteria = ['azyl' => $azyl];
        if ($onlyActive) {
            $criteria['isActive'] = true;
            $criteria['isApproved'] = true;
        }
        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * Všechny veřejně viditelné produkty (pro eshop katalog).
     * @return ShopProduct[]
     */
    public function findAvailable(?string $category = null, int $limit = 60, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.isApproved = true')
            ->andWhere('p.unlimitedStock = true OR p.stock > 0')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($category !== null) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Fulltext vyhledávání (MySQL fallback).
     * @return ShopProduct[]
     */
    public function search(string $query, int $limit = 60): array
    {
        $words = array_filter(
            preg_split('/\s+/', trim($query)),
            fn($w) => mb_strlen($w) > 2
        );
        if (empty($words)) {
            return $this->findAvailable(null, $limit);
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.isApproved = true')
            ->setMaxResults($limit);

        $orX = $qb->expr()->orX();
        foreach ($words as $i => $word) {
            $param = 'w' . $i;
            $orX->add($qb->expr()->like('p.name', ':' . $param));
            $orX->add($qb->expr()->like('p.description', ':' . $param));
            $orX->add($qb->expr()->like('p.shortDescription', ':' . $param));
            $qb->setParameter($param, '%' . $word . '%');
        }
        $qb->andWhere($orX)->orderBy('p.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Produkty čekající na schválení adminem.
     * @return ShopProduct[]
     */
    public function findPendingApproval(): array
    {
        return $this->findBy(
            ['isActive' => true, 'isApproved' => false],
            ['createdAt' => 'ASC']
        );
    }

    public function countForAzyl(Azyl $azyl): int
    {
        return $this->count(['azyl' => $azyl, 'isActive' => true]);
    }

    public function fetchCategories(): array
    {
        $rows = $this->_em->createQueryBuilder()
            ->select('DISTINCT p.category')
            ->from(ShopProduct::class, 'p')
            ->where('p.isActive = true AND p.isApproved = true AND p.category IS NOT NULL')
            ->getQuery()
            ->getArrayResult();
        return array_values(array_filter(array_map(fn($r) => $r['category'], $rows)));
    }

    public function save(ShopProduct $product): void
    {
        $this->getEntityManager()->persist($product);
        $this->getEntityManager()->flush();
    }

    public function remove(ShopProduct $product): void
    {
        $this->getEntityManager()->remove($product);
        $this->getEntityManager()->flush();
    }
}
