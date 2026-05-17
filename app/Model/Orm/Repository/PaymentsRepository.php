<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Collections;
use App\Model\Orm\Entity\Payments;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

class PaymentsRepository extends EntityRepository
{

    public function __construct(EntityManagerInterface $em, string $class = Payments::class)
    {
        parent::__construct($em, $em->getClassMetadata($class));

    }

    private function getTotalPayResult($query): int
    {
        return (int) ($query->getSingleScalarResult() ?? 0);
    }


    public function findOneByCollectionKey(int $collectionKey): Payments
    {
        return $this->findOneBy(['variableSymbol' => $collectionKey]);

    }


    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getTotalPayByCollectionKey(int $collectionKey): ?int
    {
        $query = $this->createQueryBuilder('p')
            ->select('SUM(p.pay)')
            ->where('p.variableSymbol = :variableSymbol')
            ->andWhere('p.paymentStatus = :paymentStatus')
            ->setParameter('variableSymbol', $collectionKey)
            ->setParameter('paymentStatus', 'paired')
            ->getQuery();
            return $this->getTotalPayResult($query);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getTotalPayByCollection(int $collectionId): ?int
    {
        $query = $this->createQueryBuilder('p')
            ->select('SUM(p.pay)')
            ->where('p.collections = :collectionId')
            ->setParameter('collectionId', $collectionId)
            ->getQuery();
             return $this->getTotalPayResult($query);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getTotalPayByCollectionKeyAndDate(int $collectionKey, \DateTimeImmutable $date): ?int
    {
        $query = $this->createQueryBuilder('p')
            ->select('SUM(p.pay)')
            ->where('p.variableSymbol = :variableSymbol')
            ->andWhere('p.payedAt BETWEEN :startOfDay AND :endOfDay')
            ->setParameter('variableSymbol', $collectionKey)
            ->setParameter('startOfDay', $date->setTime(0, 0, 0))
            ->setParameter('endOfDay', $date->setTime(23, 59, 59))
            ->getQuery();
             return $this->getTotalPayResult($query);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function getTotalPayByAzyl(int $azylId): ?int
    {
        $query = $this->createQueryBuilder('p')
            ->select('SUM(p.pay)')
            ->where('p.azyl = :azylId')
            ->setParameter('azylId', $azylId)
            ->getQuery();
             return $this->getTotalPayResult($query);
    }

    /**
     * Statistiky plateb ze sbírek pro daný azyl (platby napárované k sbírkám tohoto azylu).
     */
    public function getCollectionStatsByAzyl(Azyl $azyl): array
    {
        $result = $this->createQueryBuilder('p')
            ->select(
                'COUNT(p.id) as totalPayments',
                'SUM(p.pay) as totalAmount',
                'SUM(p.fee) as totalFee'
            )
            ->join('p.collections', 'c')
            ->where('c.azyl = :azyl')
            ->andWhere('p.paymentStatus = :status')
            ->setParameter('azyl', $azyl)
            ->setParameter('status', 'paired')
            ->getQuery()
            ->getSingleResult();

        $total = (float)($result['totalAmount'] ?? 0);
        $fee = (float)($result['totalFee'] ?? 0);
        return [
            'totalPayments' => (int)($result['totalPayments'] ?? 0),
            'totalAmount'   => $total,
            'totalFee'      => $fee,
            'totalPayout'   => $total - $fee,
        ];
    }

    /**
     * Statistiky plateb z virtuálních adopcí pro daný azyl.
     */
    public function getAdoptionStatsByAzyl(Azyl $azyl): array
    {
        $result = $this->createQueryBuilder('p')
            ->select(
                'COUNT(p.id) as totalPayments',
                'SUM(p.pay) as totalAmount',
                'SUM(p.fee) as totalFee'
            )
            ->join('p.adoption', 'a')
            ->where('a.azyl = :azyl')
            ->andWhere('p.paymentStatus = :status')
            ->setParameter('azyl', $azyl)
            ->setParameter('status', 'paired')
            ->getQuery()
            ->getSingleResult();

        $total = (float)($result['totalAmount'] ?? 0);
        $fee = (float)($result['totalFee'] ?? 0);
        return [
            'totalPayments' => (int)($result['totalPayments'] ?? 0),
            'totalAmount'   => $total,
            'totalFee'      => $fee,
            'totalPayout'   => $total - $fee,
        ];
    }

    public function save(Payments $payments): void
    {
        $this->getEntityManager()->persist($payments);
        $this->getEntityManager()->flush();

    }

    public function delete(Payments $payments): void
    {
        $this->getEntityManager()->remove($payments);
    }

    /*
     * $total = $paymentsRepository->getTotalPayByVariableSymbol(123456);
$totalForDay = $paymentsRepository->getTotalPayByVariableSymbolAndDate(123456, new \DateTimeImmutable('today'));
$totalForCollection = $paymentsRepository->getTotalPayByCollection(1);
$totalForAzyl = $paymentsRepository->getTotalPayByAzyl(5);
     */

}