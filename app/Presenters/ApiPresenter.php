<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Model\Orm\Repository\ConversationsRepository;
use App\Model\Orm\Repository\MessagesRepository;
use Nette\Application\UI\Presenter;

/**
 * Legerní endpoint pro JS polling.
 *
 * Vrací JSON:
 * {
 *   "total": 5,
 *   "perConversation": {
 *     "abc123...": 3,
 *     "def456...": 2
 *   }
 * }
 *
 * Dostupný na URL: /api/unread-count
 *
 * Pro nepřihlášené vrací 401.
 * Pro přihlášeného automaticky detekuje user/azyl a vrátí správné počty.
 */
class ApiPresenter extends Presenter
{
    public function __construct(
        private readonly MessagesRepository $messagesRepository,
        private readonly ConversationsRepository $conversationsRepository
    )
    {
        parent::__construct();
    }

    public function actionUnreadCount(): void
    {
        if (!$this->getUser()->isLoggedIn()) {
            $this->getHttpResponse()->setCode(401);
            $this->sendJson(['error' => 'Not authenticated']);
        }

        $identity = $this->getUser()->getIdentity()->getData();

        try {
            if ($this->getUser()->isInRole('azyl') && isset($identity['Azyl'])) {
                $azyl = $identity['Azyl'];
                $perConversation = $this->messagesRepository->countUnreadPerConversationForAzyl($azyl);
                $total = array_sum($perConversation);
            } elseif (isset($identity['User'])) {
                $user = $identity['User'];
                $perConversation = $this->messagesRepository->countUnreadPerConversationForUser($user);
                $total = array_sum($perConversation);
            } else {
                $perConversation = [];
                $total = 0;
            }

            // Cache-Control aby CDN/proxy necachovaly
            $this->getHttpResponse()->setHeader('Cache-Control', 'no-store, private');

            $this->sendJson([
                'total' => $total,
                'perConversation' => (object)$perConversation, // JSON object (ne array) i když prázdné
            ]);
        } catch (\Throwable $e) {
            \Tracy\Debugger::log('API unreadCount error: ' . $e->getMessage(), 'api');
            $this->getHttpResponse()->setCode(500);
            $this->sendJson(['error' => 'Internal error']);
        }
    }
}
