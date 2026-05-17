<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\AnimalSighting;
use App\Model\Orm\Entity\LostAnimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class AnimalSightingRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(AnimalSighting::class));
    }

    public function findByLostAnimal(LostAnimal $animal): array
    {
        return $this->findBy(['lostAnimal' => $animal], ['createdAt' => 'DESC']);
    }

    public function save(AnimalSighting $sighting): void
    {
        $this->getEntityManager()->persist($sighting);
        $this->getEntityManager()->flush();
    }
}
