<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\PaymentsIn;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class PaymentsInRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(PaymentsIn::class));
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.datum', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findUnpaired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.pairedPayment IS NULL')
            ->orderBy('p.datum', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.pairedPayment IS NOT NULL')
            ->orderBy('p.datum', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Vrací řádky, kde VS odpovídá danému variabilnímu symbolu (pro návrh párování). */
    public function findByVs(string $vs): array
    {
        return $this->findBy(['vs' => $vs]);
    }

    public function save(PaymentsIn $payment): void
    {
        $this->getEntityManager()->persist($payment);
        $this->getEntityManager()->flush();
    }
}
