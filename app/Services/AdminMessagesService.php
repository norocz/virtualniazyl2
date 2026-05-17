<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Conversations;
use App\Model\Orm\Entity\Messages;
use App\Model\Orm\Entity\Users;
use App\Model\Orm\Repository\ConversationsRepository;
use App\Model\Orm\Repository\MessagesRepository;
use App\Model\Orm\Repository\UsersRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Tracy\Debugger;

/**
 * Moderace zpráv adminem.
 *
 * Umí:
 *  - seznam konverzací s filtrováním (všechny/zablokované/problematické)
 *  - vyhledávání v textu zpráv
 *  - blokování konverzace (bool $block na Conversations)
 *  - banování uživatele (bool $baned na Users) s historií
 *  - zpětné odblokování
 */
class AdminMessagesService
{
    private EntityManagerInterface $em;
    private ConversationsRepository $conversationsRepo;
    private MessagesRepository $messagesRepo;
    private UsersRepository $usersRepo;

    public function __construct(
        EntityManagerInterface $em,
        ConversationsRepository $conversationsRepo,
        MessagesRepository $messagesRepo,
        UsersRepository $usersRepo
    )
    {
        $this->em = $em;
        $this->conversationsRepo = $conversationsRepo;
        $this->messagesRepo = $messagesRepo;
        $this->usersRepo = $usersRepo;
    }

    // =============================================================
    // Seznam konverzací
    // =============================================================

    /**
     * @param string $filter 'all' | 'blocked' | 'recent' | 'banned_users'
     * @param string|null $search vyhledávání v posledních zprávách
     * @return array<int, array{
     *   conversation: Conversations,
     *   user: Users|null,
     *   azyl_name: string|null,
     *   message_count: int,
     *   last_message_excerpt: string|null,
     *   last_message_at: DateTimeImmutable|null,
     *   is_blocked: bool,
     *   user_banned: bool
     * }>
     */
    public function getConversationsList(
        string $filter = 'recent',
        ?string $search = null,
        int $limit = 100
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('c', 'u', 'a')
            ->from(Conversations::class, 'c')
            ->leftJoin('c.user', 'u')
            ->leftJoin('c.azyl', 'a')
            ->orderBy('c.lastMessage', 'DESC')
            ->setMaxResults($limit);

        switch ($filter) {
            case 'blocked':
                $qb->where('c.block = true');
                break;
            case 'recent':
                $qb->where('c.lastMessage >= :from')
                    ->setParameter('from', new DateTimeImmutable('-7 days'));
                break;
            case 'banned_users':
                $qb->innerJoin('c.user', 'u2')
                    ->where('u2.baned = true');
                break;
            case 'all':
            default:
                // bez filtrace
                break;
        }

        if (!empty($search)) {
            // Najít konverzace obsahující zprávu s hledaným textem
            $convIds = $this->em->createQueryBuilder()
                ->select('DISTINCT IDENTITY(m.conversation)')
                ->from(Messages::class, 'm')
                ->where('m.message LIKE :s')
                ->setParameter('s', '%' . $search . '%')
                ->getQuery()
                ->getSingleColumnResult();

            if (empty($convIds)) {
                return [];
            }
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $convIds);
        }

        $conversations = $qb->getQuery()->getResult();

        $result = [];
        foreach ($conversations as $conv) {
            /** @var Conversations $conv */
            $lastMessage = $this->getLastMessage($conv);
            $messageCount = $this->messagesRepo->count(['conversation' => $conv]);

            $result[] = [
                'conversation'          => $conv,
                'user'                  => $conv->getUser(),
                'azyl_name'             => $conv->getAzyl()?->getAzylName(),
                'message_count'         => $messageCount,
                'last_message_excerpt'  => $lastMessage !== null
                    ? $this->truncate($lastMessage->getMessage(), 120)
                    : null,
                'last_message_at'       => $conv->getLastMessage(),
                'is_blocked'            => $conv->isBlock(),
                'user_banned'           => $conv->getUser()?->isBaned() ?? false,
            ];
        }
        return $result;
    }

    /**
     * Všechny zprávy konverzace.
     * @return Messages[]
     */
    public function getConversationMessages(Conversations $conversation): array
    {
        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Messages::class, 'm')
            ->where('m.conversation = :c')
            ->setParameter('c', $conversation)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function getLastMessage(Conversations $conv): ?Messages
    {
        $results = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Messages::class, 'm')
            ->where('m.conversation = :c')
            ->setParameter('c', $conv)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        return $results[0] ?? null;
    }

    // =============================================================
    // Blokace konverzace (pouze tato konverzace; user může psát jinde)
    // =============================================================

    public function blockConversation(Conversations $conv, string $reason, Users $admin): void
    {
        $conv->setBlock(true);
        $this->conversationsRepo->save($conv);

        // Systémová zpráva pro auditu
        $sysMessage = new Messages();
        $sysMessage->setConversation($conv);
        $sysMessage->setMessage(sprintf(
            '[SYSTÉM] Konverzace byla zablokována adminem (%s). Důvod: %s',
            $admin->getUserName(),
            $reason
        ));
        $sysMessage->setCreatedAt(new DateTimeImmutable());
        $sysMessage->setReaded(false);
        // Pozn: neřešíme typ - sysmessage je jen informativní
        $this->em->persist($sysMessage);
        $this->em->flush();
    }

    public function unblockConversation(Conversations $conv, Users $admin): void
    {
        $conv->setBlock(false);
        $this->conversationsRepo->save($conv);

        $sysMessage = new Messages();
        $sysMessage->setConversation($conv);
        $sysMessage->setMessage(sprintf(
            '[SYSTÉM] Blokace konverzace zrušena adminem (%s).',
            $admin->getUserName()
        ));
        $sysMessage->setCreatedAt(new DateTimeImmutable());
        $sysMessage->setReaded(false);
        $this->em->persist($sysMessage);
        $this->em->flush();
    }

    // =============================================================
    // Ban uživatele (globální - nemůže se přihlásit / psát nikde)
    // =============================================================

    public function banUser(Users $user, string $reason, Users $admin): void
    {
        $user->setBaned(true);
        $this->em->persist($user);

        // Pokud má nějakou aktivní konverzaci, zablokujeme všechny
        $conversations = $this->em->getRepository(Conversations::class)
            ->findBy(['user' => $user]);
        foreach ($conversations as $c) {
            $c->setBlock(true);
            $this->em->persist($c);
        }

        $this->em->flush();
        Debugger::log(sprintf(
            'User banned: id=%d username=%s by=%s reason=%s',
            $user->getId(),
            $user->getUserName(),
            $admin->getUserName(),
            $reason
        ), 'admin-moderation');
    }

    public function unbanUser(Users $user, Users $admin): void
    {
        $user->setBaned(false);
        $this->em->persist($user);
        $this->em->flush();
        Debugger::log(sprintf(
            'User unbanned: id=%d username=%s by=%s',
            $user->getId(),
            $user->getUserName(),
            $admin->getUserName()
        ), 'admin-moderation');
    }

    // =============================================================
    // Helpery
    // =============================================================

    private function truncate(string $text, int $length = 120): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 1) . '…';
    }
}
