<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\AzylEvent;
use App\Model\Orm\Entity\AzylEventReservation;
use App\Model\Orm\Entity\Users;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class AzylEventReservationRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(AzylEventReservation::class));
    }

    /** Returns confirmed registrations for an event, ordered by signup time. */
    public function findByEvent(AzylEvent $event): array
    {
        return $this->findBy(['event' => $event, 'status' => 'confirmed'], ['createdAt' => 'ASC']);
    }

    /** Returns waitlist entries for an event, ordered by signup time. */
    public function findWaitlistByEvent(AzylEvent $event): array
    {
        return $this->findBy(['event' => $event, 'status' => 'waitlist'], ['createdAt' => 'ASC']);
    }

    /** Returns the first (oldest) waitlist entry — the next to be promoted. */
    public function findFirstWaitlist(AzylEvent $event): ?AzylEventReservation
    {
        return $this->findOneBy(['event' => $event, 'status' => 'waitlist'], ['createdAt' => 'ASC']);
    }

    public function findByUser(Users $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function findByToken(string $token): ?AzylEventReservation
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findUserReservation(AzylEvent $event, Users $user, ?DateTimeImmutable $occurrenceDate = null): ?AzylEventReservation
    {
        $criteria = ['event' => $event, 'user' => $user];
        if ($occurrenceDate !== null) {
            $criteria['occurrenceDate'] = $occurrenceDate;
        }
        return $this->findOneBy($criteria);
    }

    public function findEmailReservation(AzylEvent $event, string $email): ?AzylEventReservation
    {
        return $this->findOneBy(['event' => $event, 'email' => $email]);
    }

    public function save(AzylEventReservation $reservation): void
    {
        $this->getEntityManager()->persist($reservation);
        $this->getEntityManager()->flush();
    }
}
