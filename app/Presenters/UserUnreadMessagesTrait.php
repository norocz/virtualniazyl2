<?php
declare(strict_types=1);

namespace App\Presenters;

/**
 * Rozšíření UserPresenter o počítání nepřečtených zpráv per konverzace.
 *
 * APLIKACE V UserPresenter.php:
 *
 *   class UserPresenter extends BasePresenter {
 *       use UserUnreadMessagesTrait;        // přidat 1 řádek
 *
 *       // smazat původní actionMessages a handleChat
 *
 *       // ZBYTEK BEZE ZMĚNY - žádné nové use, žádné injekce navíc
 *   }
 *
 * Trait používá existující property z presenteru:
 *   $this->messagesRepository, $this->messagesService,
 *   $this->conversationsRepository, $this->usersRepository
 *
 * Po aplikaci traitu má MessagesRepository navíc metodu
 * countUnreadPerConversationForUser (přes UnreadMessagesQueryTrait).
 */
trait UserUnreadMessagesTrait
{
    public function actionMessages($id): void
    {
        $tpl = $this->getTemplate();
        $tpl->title = 'Zprávy';

        $user = $this->usersRepository->findOneBy(['id' => $this->getUser()->getId()]);
        $chats = $this->conversationsRepository->findByUser($user);
        $unreadByConversation = $this->messagesRepository->countUnreadPerConversationForUser($user);

        $tpl->chats = $chats;
        $tpl->unreadByConversation = $unreadByConversation;
        $tpl->totalUnread = array_sum($unreadByConversation);

        $this->redrawControl('contacts');
        $this->redrawControl('messagesCount');
        $this->redrawControl('messages');
    }

    public function handleChat(string $id): void
    {
        $messages = $this->messagesRepository->findBytConversationMessages($id);

        $this->messagesService->markMessagesAsRead($id);

        $user = $this->usersRepository->findOneBy(['id' => $this->getUser()->getId()]);
        $unreadByConversation = $this->messagesRepository->countUnreadPerConversationForUser($user);

        $tpl = $this->getTemplate();
        $tpl->messages = $messages;
        $tpl->conversation = $id;
        $tpl->unreadByConversation = $unreadByConversation;
        $tpl->totalUnread = array_sum($unreadByConversation);
        $tpl->chats = $this->conversationsRepository->findByUser($user);

        $this->redrawControl('messagesCount');
        $this->redrawControl('chats');
        $this->redrawControl('messages');
    }
}
