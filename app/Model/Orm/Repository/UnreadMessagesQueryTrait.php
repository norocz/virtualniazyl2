<?php
declare(strict_types=1);

namespace App\Model\Orm\Repository;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Users;

/**
 * Rozšíření MessagesRepository o počítání nepřečtených zpráv.
 *
 * APLIKACE V MessagesRepository.php:
 *
 *   class MessagesRepository extends EntityRepository {
 *       use UnreadMessagesQueryTrait;       // přidat 1 řádek
 *       // ostatní kód beze změny
 *   }
 *
 * Logika "nepřečtená": zpráva poslaná NĚKÝM JINÝM (ne příjemcem samotným).
 *   - Pro Users: m.user IS NULL OR m.user != $user
 *   - Pro Azyl:  m.azyl IS NULL OR m.azyl != $azyl
 */
trait UnreadMessagesQueryTrait
{
    /**
     * @return array<string, int>  [conversationId => unreadCount]
     */
    public function countUnreadPerConversationForUser(Users $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.conversation) AS convId', 'COUNT(m.id) AS cnt')
            ->innerJoin('m.conversation', 'c')
            ->where('c.user = :user')
            ->andWhere('m.readed = false')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.user IS NULL OR m.user != :user')
            ->setParameter('user', $user)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $r) {
            $result[(string)$r['convId']] = (int)$r['cnt'];
        }
        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function countUnreadPerConversationForAzyl(Azyl $azyl): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.conversation) AS convId', 'COUNT(m.id) AS cnt')
            ->innerJoin('m.conversation', 'c')
            ->where('c.azyl = :azyl')
            ->andWhere('m.readed = false')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.azyl IS NULL OR m.azyl != :azyl')
            ->setParameter('azyl', $azyl)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $r) {
            $result[(string)$r['convId']] = (int)$r['cnt'];
        }
        return $result;
    }

    public function countUnreadMessagesForAzyl(Azyl $azyl): int
    {
        return (int)$this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.conversation', 'c')
            ->where('c.azyl = :azyl')
            ->andWhere('m.readed = false')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.azyl IS NULL OR m.azyl != :azyl')
            ->setParameter('azyl', $azyl)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadMessagesForUser(Users $user): int
    {
        return (int)$this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.conversation', 'c')
            ->where('c.user = :user')
            ->andWhere('m.readed = false')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.user IS NULL OR m.user != :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
