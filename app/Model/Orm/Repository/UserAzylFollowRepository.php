<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\UserAzylFollow;
use App\Model\Orm\Entity\Users;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class UserAzylFollowRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(UserAzylFollow::class));
    }

    public function isFollowing(Users $user, Azyl $azyl): bool
    {
        return $this->findOneBy(['user' => $user, 'azyl' => $azyl]) !== null;
    }

    /** Toggles follow. Returns true if now following, false if unfollowed. */
    public function toggle(Users $user, Azyl $azyl): bool
    {
        $em = $this->getEntityManager();
        $existing = $this->findOneBy(['user' => $user, 'azyl' => $azyl]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
            return false;
        }
        $em->persist(new UserAzylFollow($user, $azyl));
        $em->flush();
        return true;
    }

    /** @return Azyl[] */
    public function findFollowedAzyls(Users $user): array
    {
        $follows = $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
        return array_map(fn(UserAzylFollow $f) => $f->getAzyl(), $follows);
    }

    /** @return int[] */
    public function findFollowedAzylIds(Users $user): array
    {
        $follows = $this->findBy(['user' => $user]);
        return array_map(fn(UserAzylFollow $f) => $f->getAzyl()->getId(), $follows);
    }
}
