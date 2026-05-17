<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Services\AdminMessagesService;
use App\Services\DashboardStatsService;
use App\Model\Orm\Repository\ConversationsRepository;
use Nette\Application\UI\Form;

/**
 * Rozšíření AdminPresenter - dashboard, moderace zpráv.
 *
 * APLIKACE V AdminPresenter.php:
 *
 *   class AdminPresenter extends BasePresenter {
 *       use AdminMessagesAndDashboardTrait;     // přidat 1 řádek
 *
 *       // smazat původní renderDefault() - trait ho nahrazuje
 *
 *       // ZBYTEK BEZE ZMĚNY - žádné nové use, žádné injekce v __construct
 *   }
 *
 * Trait si služby vytahuje přes DI lookup (getContext()->getByType()),
 * takže nepotřebuje injekce v konstruktoru a nezvyšuje počet use
 * statementů v presenteru.
 *
 * Předpokládá že presenter MÁ existující property:
 *   $this->usersRepository, $this->analyticsRepository
 * (které už máš v AdminPresenter)
 */
trait AdminMessagesAndDashboardTrait
{
    private DashboardStatsService $_dashboardStats;
    private AdminMessagesService $_adminMessages;
    private ConversationsRepository $_conversationsRepoForMessages;

    public function injectAdminTraitServices(
        DashboardStatsService $dashboardStats,
        AdminMessagesService $adminMessages,
        ConversationsRepository $conversationsRepo,
    ): void {
        $this->_dashboardStats = $dashboardStats;
        $this->_adminMessages = $adminMessages;
        $this->_conversationsRepoForMessages = $conversationsRepo;
    }

    private function getDashboardStats(): DashboardStatsService
    {
        return $this->_dashboardStats;
    }

    private function getAdminMessages(): AdminMessagesService
    {
        return $this->_adminMessages;
    }

    private function getConversationsRepoForMessages(): ConversationsRepository
    {
        return $this->_conversationsRepoForMessages;
    }

    public function renderDefault(): void
    {
        $lastMonths = 24;
        $tpl = $this->getTemplate();
        $tpl->title = 'Admin';

        $tpl->newUsersCount = $this->usersRepository->CountNewUsers();
        $tpl->usersCount = $this->usersRepository->CountUsers();
        $tpl->azylsCount = $this->usersRepository->CountAzyls();

        $registrations = $this->usersRepository->getUsersByMonthOfRegistration(6);
        $visitorsByDay = $this->analyticsRepository->countUniqueVisitsPerDay(30);
        $allVisitorsByDay = $this->analyticsRepository->countVisitsPerDay(30);
        $tpl->visitorsByWeek = $this->analyticsRepository->countUniqueVisitsPerWeek(4);
        $tpl->visitorsByMonth = $this->analyticsRepository->countUniqueVisitsPerMonth(6);

        $lineLabels = [];
        $lineData = [];
        foreach ($visitorsByDay as $item) {
            $lineLabels[] = $item['day'];
            $lineData[] = $item['count'];
        }
        $allData = [];
        foreach ($allVisitorsByDay as $item) {
            $allData[] = $item['count'];
        }
        $tpl->visitorsByDayLabel = json_encode($lineLabels);
        $tpl->visitorsByDayData = json_encode($lineData);
        $tpl->allVisitorsByDayData = json_encode($allData);

        $resultsMap = [];
        foreach ($registrations as $r) {
            $resultsMap[$r['year'] . '-' . $r['month']] = $r['count'];
        }
        $labels = [];
        $data = [];
        $now = new \DateTime();
        for ($i = 0; $i < $lastMonths; $i++) {
            $date = (clone $now)->modify("-$i months");
            $key = $date->format('Y') . '-' . intval($date->format('n'));
            $labels[] = $this->getMonthName(intval($date->format('n'))) . ' ' . $date->format('Y');
            $data[] = $resultsMap[$key] ?? 0;
        }
        $tpl->barId = random_int(0, 10000);
        $tpl->lineId = random_int(0, 10000);
        $tpl->labels = json_encode(array_reverse($labels));
        $tpl->data = json_encode(array_reverse($data));
        $tpl->label = json_encode('Počet registrací');

        // === NOVÁ DATA - z DashboardStatsService ===
        $stats = $this->getDashboardStats();
        $tpl->adoptionsStats = $stats->getAdoptionsByType(30);
        $tpl->adoptionsMonthlyTrend = json_encode($stats->getAdoptionsMonthlyTrend(12));
        $tpl->animalsStats = $stats->getAnimalsStats();
        $tpl->collectionsStats = $stats->getCollectionsStats();
        $tpl->mostActiveAzyls = $stats->getMostActiveAzyls(30, 10);
        $tpl->azylsOverview = $stats->getAzylsOverview();
        $tpl->messagingStats = $stats->getMessagingStats(7);
    }

    public function renderMessages(string $filter = 'recent', ?string $search = null): void
    {
        $tpl = $this->getTemplate();
        $tpl->title = 'Moderace zpráv';
        $tpl->filter = $filter;
        $tpl->search = $search;
        $tpl->conversations = $this->getAdminMessages()->getConversationsList($filter, $search, 100);
        $tpl->stats = $this->getDashboardStats()->getMessagingStats(7);
    }

    public function renderMessageDetail(string $id): void
    {
        $conversation = $this->getConversationsRepoForMessages()->findOneById($id);
        if ($conversation === null) {
            $this->error('Konverzace nenalezena.');
        }
        $tpl = $this->getTemplate();
        $tpl->title = 'Detail konverzace';
        $tpl->conversation = $conversation;
        $tpl->messages = $this->getAdminMessages()->getConversationMessages($conversation);
    }

    public function createComponentBlockForm(): Form
    {
        $form = new Form;
        $form->addTextArea('reason', 'Důvod blokace')->setRequired('Zadejte důvod');
        $form->addSubmit('block', 'Zablokovat');
        $form->onSuccess[] = function (Form $form, \stdClass $values): void {
            $convId = $this->getParameter('id');
            $conversation = $this->getConversationsRepoForMessages()->findOneById($convId);
            if ($conversation === null) {
                $this->error('Konverzace nenalezena.');
            }
            $admin = $this->getUser()->getIdentity()->getData()['User'];
            $this->getAdminMessages()->blockConversation($conversation, $values->reason, $admin);
            $this->flashMessage('Konverzace zablokována.', 'alert-warning');
            $this->redirect('this');
        };
        return $form;
    }

    public function createComponentBanForm(): Form
    {
        $form = new Form;
        $form->addTextArea('reason', 'Důvod banu')->setRequired('Zadejte důvod');
        $form->addSubmit('ban', 'BAN');
        $form->onSuccess[] = function (Form $form, \stdClass $values): void {
            $convId = $this->getParameter('id');
            $conversation = $this->getConversationsRepoForMessages()->findOneById($convId);
            if ($conversation === null || $conversation->getUser() === null) {
                $this->error('Nelze banovat.');
            }
            $admin = $this->getUser()->getIdentity()->getData()['User'];
            $this->getAdminMessages()->banUser($conversation->getUser(), $values->reason, $admin);
            $this->flashMessage(
                sprintf('Uživatel %s zablokován.', $conversation->getUser()->getUserName()),
                'alert-danger'
            );
            $this->redirect('messages');
        };
        return $form;
    }

    public function handleBlockConversation(string $id): void
    {
        $conv = $this->getConversationsRepoForMessages()->findOneById($id);
        if ($conv === null) { $this->error('Nenalezeno.'); }
        $admin = $this->getUser()->getIdentity()->getData()['User'];
        $this->getAdminMessages()->blockConversation($conv, 'Z přehledu adminem', $admin);
        $this->flashMessage('Konverzace zablokována.', 'alert-warning');
        $this->redirect('messages');
    }

    public function handleUnblockConversation(string $id): void
    {
        $conv = $this->getConversationsRepoForMessages()->findOneById($id);
        if ($conv === null) { $this->error('Nenalezeno.'); }
        $admin = $this->getUser()->getIdentity()->getData()['User'];
        $this->getAdminMessages()->unblockConversation($conv, $admin);
        $this->flashMessage('Blokace zrušena.', 'alert-success');
        $this->redirect('messages');
    }

    public function handleBanUser(int $id): void
    {
        $user = $this->usersRepository->getUserById($id);
        if ($user === null) { $this->error('Nenalezen.'); }
        $admin = $this->getUser()->getIdentity()->getData()['User'];
        $this->getAdminMessages()->banUser($user, 'Z přehledu adminem', $admin);
        $this->flashMessage(sprintf('Uživatel %s zablokován.', $user->getUserName()), 'alert-danger');
        $this->redirect('this');
    }

    public function handleUnbanUser(int $id): void
    {
        $user = $this->usersRepository->getUserById($id);
        if ($user === null) { $this->error('Nenalezen.'); }
        $admin = $this->getUser()->getIdentity()->getData()['User'];
        $this->getAdminMessages()->unbanUser($user, $admin);
        $this->flashMessage('Ban zrušen.', 'alert-success');
        $this->redirect('this');
    }
}
