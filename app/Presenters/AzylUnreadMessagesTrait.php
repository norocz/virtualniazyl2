<?php
declare(strict_types=1);

namespace App\Presenters;

/**
 * Rozšíření AzylPresenter o počítání nepřečtených zpráv per konverzace.
 *
 * APLIKACE V AzylPresenter.php:
 *
 *   class AzylPresenter extends BasePresenter {
 *       use AzylUnreadMessagesTrait;
 *
 *       // smazat původní actionMessages, handleChat, handleDeleteMsg
 *
 *       // ZBYTEK BEZE ZMĚNY
 *   }
 *
 * Trait používá existující property:
 *   $this->messagesRepository, $this->messagesService,
 *   $this->conversationsRepository, $this->azylRepository
 */
trait AzylUnreadMessagesTrait
{
    public function actionMessages(?string $id = null): void
    {
        $tpl = $this->getTemplate();
        $tpl->title = 'Zprávy';

        $azyl = $this->azylRepository->findOneBy([
            'id' => $this->getUser()->getIdentity()->getData()['Azyl']->getId()
        ]);

        $chats = $this->conversationsRepository->findByAzyl($azyl);
        $unreadByConversation = $this->messagesRepository->countUnreadPerConversationForAzyl($azyl);

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

        $azyl = $this->azylRepository->findOneBy([
            'id' => $this->getUser()->getIdentity()->getData()['Azyl']->getId()
        ]);
        $unreadByConversation = $this->messagesRepository->countUnreadPerConversationForAzyl($azyl);

        $tpl = $this->getTemplate();
        $tpl->messages = $messages;
        $tpl->conversation = $id;
        $tpl->unreadByConversation = $unreadByConversation;
        $tpl->totalUnread = array_sum($unreadByConversation);
        $tpl->chats = $this->conversationsRepository->findByAzyl($azyl);

        if ($this->isAjax()) {
            $this->redrawControl('messagesCount');
            $this->redrawControl('chats');
            $this->redrawControl('messages');
        }
    }

    public function handleDeleteMsg(int $id): void
    {
        $redirectAddress = $this->messagesRepository->getMessagesById(
            $id,
            $this->getUser()->getIdentity()->getData()['user']
        );
        if ($this->messagesService->deleteMessage($id, $this->getPresenter())) {
            $this->flashMessage('Vzkaz byl smazán.', 'alert-success');
        } else {
            $this->flashMessage('Při mazání vzkazu nastala chyba.', 'alert-danger');
        }

        if ($this->isAjax()) {
            $azyl = $this->azylRepository->findOneBy([
                'id' => $this->getUser()->getIdentity()->getData()['Azyl']->getId()
            ]);
            $unreadByConversation = $this->messagesRepository->countUnreadPerConversationForAzyl($azyl);

            $tpl = $this->getTemplate();
            $tpl->unreadByConversation = $unreadByConversation;
            $tpl->totalUnread = array_sum($unreadByConversation);
            $tpl->chats = $this->conversationsRepository->findByAzyl($azyl);

            $this->redrawControl('messagesCount');
            $this->redrawControl('chats');
            $this->redrawControl('messages');
        } else {
            $url = $this->link('Azyl:messages', $redirectAddress) . '?do=chat';
            $this->redirectUrl($url);
        }
    }
}
