<?php
declare(strict_types=1);

namespace App\Presenters;


use App\Components\Datagrids\BankImportsDatagridFactory;
use App\Components\Datagrids\CollectionsDatagridFactory;
use App\Components\Datagrids\UsersDatagridFactory;
use App\Forms\collectionFormFactory;
use App\Forms\systemSettingsFormFactory;
use App\Model\Orm\Entity\FirewallLog;
use App\Model\Orm\Entity\Payments;
use App\Model\Orm\Entity\ShopPayoutBatch;
use App\Model\Orm\Entity\SystemSettings;
use App\Model\Orm\Enums\PaymentStatusEnum;
use App\Model\Orm\Enums\PayoutBatchStatusEnum;
use App\Model\Orm\Enums\PayoutStatusEnum;
use App\Model\Orm\Enums\RefundStatusEnum;
use App\Model\Orm\Enums\RoleTypeEnum;
use App\Model\Orm\Repository\AnalyticsRepository;
use App\Model\Orm\Repository\AzylRepository;
use App\Model\Orm\Repository\CollectionsRepository;
use App\Model\Orm\Repository\FirewallLogsRepository;
use App\Model\Orm\Repository\PaymentsInRepository;
use App\Model\Orm\Repository\PaymentsRepository;
use App\Model\Orm\Repository\PhotosRepository;
use App\Model\Orm\Repository\ShopOrderRepository;
use App\Model\Orm\Repository\ShopPayoutBatchRepository;
use App\Model\Orm\Repository\ShopPayoutRepository;
use App\Model\Orm\Repository\ShopRefundRepository;
use App\Model\Orm\Repository\SystemSetingsRepository;
use App\Model\Orm\Repository\UsersRepository;
use App\Services\GeocodingService;
use App\Services\IpInfoService;
use App\Services\SearchIndexerService;
use App\Services\SlugService;
use Doctrine\ORM\EntityManagerInterface;
use Contributte\Application\UI\BasePresenter;
use DateTimeImmutable;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Application\UI\Form;
use App\Forms\setAzylFormFactory;
use Nette\Security\AuthenticationException;
use Nette\Security\SimpleIdentity;
use Nette\Utils\Paginator;
use Ublaboo\DataGrid\Column\Action\Confirmation\StringConfirmation;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Exception\DataGridColumnStatusException;
use Ublaboo\DataGrid\Exception\DataGridException;

class SuperAdminPresenter extends BasePresenter
{
    #[\Nette\DI\Attributes\Inject]
    public SlugService $slugService;

    private setAzylFormFactory $setAzylFormFactory;
    private AzylRepository $azylRepository;
    private FirewallLogsRepository $firewallLogsRepository;
    private analyticsRepository $analyticsRepository;
    private ipInfoService $ipInfoService;
    private systemSettingsFormFactory $systemSettingsFormFactory;
    private collectionsRepository $collectionsRepository;
    private collectionFormFactory $collectionFormFactory;
    private collectionsDatagridFactory $collectionsDatagridFactory;
    private PhotosRepository $photosRepository;
    private SystemSetingsRepository $systemSetingsRepository;
    private UsersDatagridFactory $usersDatagridFactory;
    private UsersRepository $usersRepository;
    private RoleTypeEnum $roleTypeEnum;
    private ShopOrderRepository $shopOrderRepository;
    private ShopPayoutRepository $shopPayoutRepository;
    private ShopPayoutBatchRepository $shopPayoutBatchRepository;
    private ShopRefundRepository $shopRefundRepository;
    private PaymentsRepository $paymentsRepository;
    private EntityManagerInterface $entityManager;
    private GeocodingService $geocodingService;
    private SearchIndexerService $searchIndexerService;
    private PaymentsInRepository $paymentsInRepository;
    private BankImportsDatagridFactory $bankImportsDatagridFactory;

    public function __construct(setAzylFormFactory         $setAzylFormFactory,
                                azylRepository             $azylRepository,
                                firewallLogsRepository     $firewallLogsRepository,
                                analyticsRepository        $analyticsRepository,
                                ipInfoService              $ipInfoService,
                                systemSettingsFormFactory  $systemSettingsFormFactory,
                                collectionFormFactory      $collectionFormFactory,
                                CollectionsRepository      $collectionsRepository,
                                PhotosRepository           $photosRepository,
                                CollectionsDatagridFactory $collectionsDatagridFactory,
                                SystemSetingsRepository    $systemSetingsRepository,
                                UsersDatagridFactory       $usersDatagridFactory,
                                UsersRepository            $usersRepository,
                                RoleTypeEnum               $roleTypeEnum,
                                ShopOrderRepository        $shopOrderRepository,
                                ShopPayoutRepository       $shopPayoutRepository,
                                ShopPayoutBatchRepository  $shopPayoutBatchRepository,
                                ShopRefundRepository       $shopRefundRepository,
                                PaymentsRepository         $paymentsRepository,
                                EntityManagerInterface     $entityManager,
                                GeocodingService           $geocodingService,
                                SearchIndexerService       $searchIndexerService,
                                PaymentsInRepository       $paymentsInRepository,
                                BankImportsDatagridFactory $bankImportsDatagridFactory)
    {
        parent::__construct();
        $this->setAzylFormFactory = $setAzylFormFactory;
        $this->azylRepository = $azylRepository;
        $this->firewallLogsRepository = $firewallLogsRepository;
        $this->analyticsRepository = $analyticsRepository;
        $this->ipInfoService = $ipInfoService;
        $this->systemSettingsFormFactory = $systemSettingsFormFactory;
        $this->collectionsRepository = $collectionsRepository;
        $this->collectionFormFactory = $collectionFormFactory;
        $this->photosRepository = $photosRepository;
        $this->collectionsDatagridFactory = $collectionsDatagridFactory;
        $this->systemSetingsRepository = $systemSetingsRepository;
        $this->usersDatagridFactory = $usersDatagridFactory;
        $this->usersRepository = $usersRepository;
        $this->roleTypeEnum = $roleTypeEnum;
        $this->shopOrderRepository = $shopOrderRepository;
        $this->shopPayoutRepository = $shopPayoutRepository;
        $this->shopPayoutBatchRepository = $shopPayoutBatchRepository;
        $this->shopRefundRepository = $shopRefundRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->entityManager = $entityManager;
        $this->geocodingService = $geocodingService;
        $this->searchIndexerService = $searchIndexerService;
        $this->paymentsInRepository = $paymentsInRepository;
        $this->bankImportsDatagridFactory = $bankImportsDatagridFactory;
    }

    public function startup(): void
    {
        parent::startup();

        if (!$this->getPresenter()->getUser()->isLoggedIn()) {
            $this->getPresenter()->redirect('Home:signIn');
        }
        if (!$this->getPresenter()->getUser()->isInRole('superadmin')) {
            $this->getPresenter()->redirect('Home:default');
        }


    }

    protected function beforeRender(): void
    {
        $this->template->addFilter('json', fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->template->addFilter('safeHtml', function (string $html): string {
            $allowedTags = ['b', 'i', 'a'];
            $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

            // Povolit pouze bezpečné atributy v <a>
            return preg_replace_callback('/<a\s+([^>]+)>/i', function ($matches) {
                if (preg_match('/href=["\'](.*?)["\']/', $matches[1], $hrefMatch)) {
                    return '<a href="' . htmlspecialchars($hrefMatch[1], ENT_QUOTES) . '">';
                }
                return '<a>';
            }, $html);
        });

        $this->getTemplate()->personalPhoto = $this->photosRepository->findById($this->user->getIdentity()->getData()['User']->getPersonalPhoto());
    }

    public function renderDefault(): void
    {
        $this->template->title = 'Admin';
    }

    public function renderAnalytics(int $page = 1): void
    {
        $paginator = new Paginator();
        $paginator->setItemCount($this->analyticsRepository->countAll());
        $paginator->setItemsPerPage(25);
        $paginator->setPage($page);
        $paginator->setBase(1);
        $this->getTemplate()->paginator = $paginator;
        $this->getTemplate()->analytics = $this->analyticsRepository->findBy([], ['id' => 'DESC'], $paginator->getLength(), $paginator->getOffset());

        $this->getTemplate()->totalVisits    = $this->analyticsRepository->countAll();
        $this->getTemplate()->uniqueVisitors = $this->analyticsRepository->countUniqueVisitors();
        $this->getTemplate()->actionStats    = $this->analyticsRepository->countByAction();

        $visitsPerDay  = $this->analyticsRepository->countVisitsPerDay(30);
        $uniquePerDay  = $this->analyticsRepository->countUniqueVisitsPerDay(30);

        $chartLabels = array_column($visitsPerDay, 'day');
        $chartTotal  = array_column($visitsPerDay, 'count');

        $uniqueMap = [];
        foreach ($uniquePerDay as $r) {
            $uniqueMap[$r['day']] = (int)$r['count'];
        }
        $chartUnique = array_map(fn($d) => $uniqueMap[$d] ?? 0, $chartLabels);

        $this->getTemplate()->chartLabels = json_encode($chartLabels);
        $this->getTemplate()->chartTotal  = json_encode(array_map('intval', $chartTotal));
        $this->getTemplate()->chartUnique = json_encode($chartUnique);

        $blockedIps = [];
        foreach ($this->firewallLogsRepository->findByAction('blocked') as $fw) {
            $blockedIps[$fw->getIp()] = true;
        }
        $this->getTemplate()->blockedIps = $blockedIps;
    }

    /**
     * @throws \Throwable
     */
    public function handleIpInfo(int $id): void
    {
        $log = $this->analyticsRepository->findOneBy(['id' => $id]);
        if ($this->isAjax()) {
            $ipInfo = $this->ipInfoService->getIpInfo($log->getIpAdress());
            $this->getTemplate()->ipInfo = $ipInfo;
            $this->redrawControl('ipInfoIp' . $log->getIpAdress());
            $this->redrawControl('ipInfoTable');
        }
    }

    public function handleBlockIpAnalytics(string $ip): void
    {
        $existing = $this->firewallLogsRepository->findOneByIp($ip);
        if ($existing) {
            $existing->setAction('blocked');
            $this->firewallLogsRepository->save($existing);
        } else {
            $fw = new FirewallLog();
            $fw->setIp($ip);
            $fw->setAction('blocked');
            $fw->setAttempts(0);
            $fw->setCreatedAt(new DateTimeImmutable());
            $fw->setNotes('Blokováno z analytics');
            $this->firewallLogsRepository->save($fw);
        }
        $this->flashMessage('IP ' . $ip . ' zablokována.', 'alert-warning');
        $this->redirect('this');
    }

    public function actionCollections(?int $id = null): void
    {
        $this->getTemplate()->collections = $this->collectionsRepository->findByAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
    }

    public function renderSetAzyl(): void
    {
        $this->template->title = 'Nastavení azylu';
    }

    public function renderLogs(): void
    {
        $this->redirect('SuperAdmin:analytics');
    }

    public function renderAnimals(): void
    {
        $this->template->title = 'Animals';
    }

    public function renderAzyls(): void
    {
        $this->template->title = 'Azyls';
    }

    public function renderAzylslugs(): void
    {
        $this->template->title = 'Správa slugů azylů';
        $this->template->azyls = $this->azylRepository->fetchAll();
    }

    public function handleSetAzylSlug(int $id, string $slug): void
    {
        $azyl = $this->azylRepository->findById($id);
        if (!$azyl) {
            $this->flashMessage('Azyl nenalezen.', 'alert-danger');
            $this->redirect('this');
        }
        $newSlug = $this->slugService->slugify($slug);
        if ($newSlug === '') {
            $this->flashMessage('Slug nesmí být prázdný.', 'alert-danger');
            $this->redirect('this');
        }
        $existing = $this->azylRepository->findBySlug($newSlug);
        if ($existing !== null && $existing->getId() !== $azyl->getId()) {
            $this->flashMessage('Slug "' . $newSlug . '" je obsazen azylem #' . $existing->getId() . ' (' . $existing->getAzylName() . ').', 'alert-danger');
            $this->redirect('this');
        }
        $azyl->setSlug($newSlug);
        $this->azylRepository->saveAzyl($azyl);
        $this->flashMessage('Slug azylu "' . $azyl->getAzylName() . '" nastaven na: ' . $newSlug, 'alert-success');
        $this->redirect('this');
    }

    public function handleGenerateAllSlugs(): void
    {
        $count = 0;
        foreach ($this->azylRepository->fetchAll() as $azyl) {
            if ($azyl->getSlug() === null && $azyl->getAzylName() !== null) {
                $azyl->setSlug($this->slugService->generateUniqueSlug($azyl->getAzylName(), $azyl->getId()));
                $this->azylRepository->saveAzyl($azyl);
                $count++;
            }
        }
        $this->flashMessage('Vygenerováno ' . $count . ' nových slugů.', 'alert-success');
        $this->redirect('this');
    }

    public function renderNews(): void
    {
        $this->template->title = 'News';
    }

    public function renderOwner(): void
    {
        $this->template->title = 'Owner';
    }

    public function renderSystemsettings(): void
    {
        $this->template->title = 'System Settings';
    }

    public function renderSendmessages(): void
    {
        $this->template->title = 'Sendmessage';
    }

    public function renderAdoptions(): void
    {
        $this->template->title = 'Adoptions';
    }

    public function renderSignIn(): void
    {
        $this->template->title = 'SignIn';
    }

    public function renderFirewall(): void
    {
        $this->getTemplate()->title = 'Firewall setings';
        $this->getTemplate()->firewallLogs = $this->firewallLogsRepository->findAll();
    }

    //handle

    /**
     * @throws NonUniqueResultException
     */
    public function handleFirewallLogDelete(int $id): void
    {
        $firewallLog = $this->firewallLogsRepository->findOneById($id);
        if ($firewallLog) {
            $ip = $firewallLog->getIp();
            $this->firewallLogsRepository->delete($firewallLog);
            $this->flashMessage('Záznam IP ' . $ip . ' smazán.', 'alert-success');
        }
        $this->redirect('this');
    }

    public function handleFirewallLogAddToUFW(int $id): void
    {
        $firewallLog = $this->firewallLogsRepository->find($id);
        if ($firewallLog) {
            $firewallLog->setAction('firewall_blocked');
            $ip = $firewallLog->getIp();
            $this->firewallLogsRepository->save($firewallLog);
            // TODO: exec("sudo ufw deny from $ip to any port 80,443")
            // Vyžaduje: www-data ALL=(ALL) NOPASSWD: /usr/sbin/ufw  v sudoers
            $this->flashMessage('IP ' . $ip . ' označena pro UFW (aplikovat ručně nebo nastavit sudoers).', 'alert-warning');
        }
        $this->redirect('this');
    }


    public function handleFirewallLogBlock($id): void
    {
        $firewallLog = $this->firewallLogsRepository->find($id);
        $firewallLog->setAction('blocked');
        $ip = $firewallLog->getIp();
        $this->firewallLogsRepository->save($firewallLog);

        if ($this->isAjax()) {
            $this->redrawControl('firewallTable');
        }
        $this->flashMessage('IP ' . $ip . ' zablokována.', 'alert-warning');
        $this->redirect('this');
    }

    public function handleUnblockIp(int $id): void
    {
        $firewallLog = $this->firewallLogsRepository->find($id);
        if ($firewallLog) {
            $ip = $firewallLog->getIp();
            $firewallLog->setAction('unblocked');
            $this->firewallLogsRepository->save($firewallLog);
            $this->flashMessage('IP ' . $ip . ' odblokována.', 'alert-success');
        }
        $this->redirect('this');
    }

    public function handleClearFirewall(): void
    {
        foreach ($this->firewallLogsRepository->findAll() as $log) {
            $this->firewallLogsRepository->delete($log);
        }
        $this->flashMessage('Všechny záznamy firewallu smazány.', 'alert-success');
        $this->redirect('this');
    }


    //components

    public function createComponentSetAzylForm(): Form
    {
        $form = $this->setAzylFormFactory->create();
        $form->onSuccess[] = [$this, 'azylSetFormSuccessed'];
        return $form;

    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function createComponentSystemSettingsForm(): Form
    {
        $setings = $this->systemSetingsRepository->lastSetings();
        $form = $this->systemSettingsFormFactory->create();
        if ($setings) {
            $form->setDefaults([
                'fee' => $setings->getFee(),
                'dph' => $setings->getDph(),
                'language' => $setings->getLanguage(),
                'payOutInterval' => $setings->getPayOutInterval(),
                'depricated' => $setings->getDepricated(),
                'relevantFrom' => $setings->getRelevantFrom(),
                'cron' => $setings->isCron(),
                'analyticsGarbage' => $setings->isAnalyticsGarbage(),
                'databaseClear' => $setings->isDatabaseClear(),
                'dphUse' => $setings->isDphUse(),
                'lasPayOut' => $setings->getLastPayOut(),
                'nextPayOut' => $setings->getNextPayOut(),

            ]);
        }
        $form->onSuccess[] = [$this, 'systemSetingsFormSuccessed'];
        return $form;
    }

    /**
     * @throws DataGridException
     */
    public function createComponentCollectionDatagrid(): Datagrid
    {
        return $this->collectionsDatagridFactory->create();
    }

    /**
     * @throws AuthenticationException
     * @throws NonUniqueResultException
     */


    public function azylSetFormSuccessed(Form $form, \stdClass $values): void
    {

        $azyl = $this->azylRepository->findById($values->azyl);
        $identity = $this->getUser()->getIdentity();
        if ($identity) {

            $newData = $identity->getData();
            $newData['Azyl'] = $azyl;

            $newIdentity = new SimpleIdentity($identity->getId(), $identity->getRoles(), $newData);
            $this->getUser()->logout();
            $this->getUser()->login($newIdentity);

            $this->getPresenter()->redirect('Azyl:default');

        }

    }

    /**
     * @throws \DateMalformedStringException
     */

    public function systemSetingsFormSuccessed(Form $form, \stdClass $values): void
    {
        $systemSetings = new SystemSettings();
        $systemSetings->setFee($values->fee);
        $systemSetings->setCreatedAt(new DateTimeImmutable());
        $systemSetings->setDph($values->dph);
        $systemSetings->setCron($values->cron);
        $systemSetings->setDphUse($values->dphUse);
        $systemSetings->setNextPayOut(new DateTimeImmutable($values->nextPayOut->format('Y-m-d H:i:s')));
        $systemSetings->setPayOutInterval($values->payOutInterval);
        $systemSetings->setDepricated(false);
        $systemSetings->setDatabaseClear($values->databaseClear);
        $systemSetings->setAnalyticsGarbage($values->analyticsGarbage);
        $systemSetings->setRelevantFrom(new DateTimeImmutable());

        $this->systemSetingsRepository->save($systemSetings);

        $this->flashMessage('Nastavení systému byly uloženy', 'alert-success');
        $this->redirect('this');
    }

    /**
     * @throws DataGridColumnStatusException
     * @throws DataGridException
     */
    public function createComponentUsersDatagrid(): DataGrid
    {
        $grid = new UsersDatagridFactory($this->usersRepository);
        $grid->setPresenter($this->getPresenter());
        $dataGrid = $grid->create(); // upraví instanci, nepřepíše ji novým objektem
        $dataGrid->setDatasource($this->usersRepository->findAll());

        $dataGrid->addColumnStatus('role', 'Role')
            ->setTemplate(__DIR__ . '/../Components/Datagrids/templates/column_status.latte')
            ->setRenderer(function ($item) { return $item->getRole();})
            ->setSortable()
            ->setCaret(true)
            ->addOption(RoleTypeEnum::ROLE_GUEST, 'Nová registrace')
            ->setClass('btn-sm btn-info')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->addOption(RoleTypeEnum::ROLE_USER, 'Uživatel')
            ->setClass('btn-sm btn-primary')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->addOption(RoleTypeEnum::ROLE_OWNER, 'Nový uživatel')
            ->setClass('btn-sm btn-primary')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->addOption(RoleTypeEnum::ROLE_AZYL, 'Azyl')
            ->setClass('btn-sm btn-success')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->addOption(RoleTypeEnum::ROLE_ADMIN, 'Admin')
            ->setClass('btn-sm btn-warning')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->addOption(RoleTypeEnum::ROLE_SUPERADMIN, 'The GOD')
            ->setClass('btn-sm btn-danger')
            ->setIcon('fa fa-warning')
            ->endOption()
            ->onChange[] = [$this,'handleUpdateRole'];


        return $dataGrid;
    }

    public function handleUpdateRole($id): void
    {
        $user = $this->usersRepository->findOneBy(['id' => intval($id)]);
       if ($user->getId() == $this->getPresenter()->getRequest()->getParameter('usersDatagrid-id'))
       {
           $user->setRole($this->getPresenter()->getRequest()->getParameter('usersDatagrid-value'));
           $this->usersRepository->save($user);
           $this->flashMessage('Role nastavena','alert-success');
           if ($this->isAjax())
           {
               $this->getPresenter()->redrawControl('datagrid');
           }
           else
           {
               $this->getPresenter()->redirect('this');
           }
       }
    }
    public function handleEditUser($id): void
    {
        $this->redirect('SuperAdmin:userDetail', ['id' => (int)$id]);
    }

    public function handleDeleteUser($id): void
    {
        $user = $this->usersRepository->findOneBy(['id' => intval($id)]);
        if ($user) {
            $this->usersRepository->delete($user);
            $this->flashMessage('Uživatel ' . $user->getUserName() . ' byl označen jako smazaný.', 'alert-success');
        }
        $this->redirect('SuperAdmin:users');
    }

    public function renderUserDetail(int $id): void
    {
        $user = $this->usersRepository->findOneBy(['id' => $id]);
        if (!$user) {
            $this->error('Uživatel nenalezen', 404);
        }
        $this->template->editedUser = $user;
        $this->template->analyticsCount = $this->analyticsRepository->countAll();
    }

    public function createComponentEditUserForm(): Form
    {
        $id = (int)$this->getParameter('id');
        $user = $this->usersRepository->findOneBy(['id' => $id]);

        $form = new Form();
        $form->addText('userName', 'Uživatelské jméno')
            ->setRequired()
            ->setDefaultValue($user?->getUserName() ?? '');
        $form->addEmail('email', 'E-mail')
            ->setRequired()
            ->setDefaultValue($user?->getEmail() ?? '');
        $form->addText('firstName', 'Jméno')
            ->setDefaultValue($user?->getFirstName() ?? '');
        $form->addText('lastName', 'Příjmení')
            ->setDefaultValue($user?->getLastName() ?? '');
        $form->addText('phone', 'Telefon')
            ->setDefaultValue($user?->getPhone() ?? '');
        $form->addTextArea('description', 'Poznámka', null, 3)
            ->setDefaultValue($user?->getDescription() ?? '');
        $form->addHidden('userId', (string)$id);
        $form->addSubmit('save', 'Uložit změny');

        $form->onSuccess[] = [$this, 'editUserFormSucceeded'];
        return $form;
    }

    public function editUserFormSucceeded(Form $form, \stdClass $values): void
    {
        $user = $this->usersRepository->findOneBy(['id' => (int)$values->userId]);
        if (!$user) {
            $this->flashMessage('Uživatel nenalezen.', 'alert-danger');
            $this->redirect('SuperAdmin:users');
        }
        $user->setUserName($values->userName);
        $user->setEmail($values->email);
        $user->setFirstName($values->firstName);
        $user->setLastName($values->lastName);
        $user->setPhone($values->phone ?: null);
        $user->setDescription($values->description ?: null);
        $this->usersRepository->save($user);
        $this->flashMessage('Uživatel ' . $user->getUserName() . ' byl upraven.', 'alert-success');
        $this->redirect('this');
    }

    public function handleUpdateBanUser(int $id): void
    {
        $user = $this->usersRepository->findOneBy(['id' => $id]);
        if ($user) {
            $user->setBaned(!$user->isBaned());
            $this->usersRepository->save($user);
            $status = $user->isBaned() ? 'zabanován' : 'odbanován';
            $this->flashMessage('Uživatel ' . $user->getUserName() . ' byl ' . $status . '.', 'alert-warning');
        }
        $this->redirect('this');
    }

    // ===================== GEOLOKACE =====================

    public function renderGeolocation(): void
    {
        $allAzyls = $this->azylRepository->findAll();
        $withoutCoords = $this->azylRepository->findWithoutCoordinates();

        $this->template->azyls              = $allAzyls;
        $this->template->totalAzyls         = count($allAzyls);
        $this->template->missingAzyls       = count($withoutCoords);
        $this->template->missingAnimals     = $this->geocodingService->countAnimalsWithoutCoordinates();
    }

    public function handleGeocodeAzyl(int $id): void
    {
        $azyl = $this->azylRepository->findById($id);
        if (!$azyl) {
            $this->flashMessage('Azyl nenalezen.', 'alert-danger');
            $this->redirect('this');
        }
        $ok = $this->geocodingService->geocodeAzyl($azyl, true);
        if ($ok) {
            $animals = $this->geocodingService->propagateAzylToAnimals($azyl);
            $this->flashMessage(
                sprintf('Azyl „%s" geocodován: %.6f, %.6f. Propagováno na %d zvířat.',
                    $azyl->getAzylName(), $azyl->getLatitude(), $azyl->getLongitude(), $animals),
                'alert-success'
            );
        } else {
            $this->flashMessage('Geocodování selhalo — azyl nemá adresu ani město.', 'alert-warning');
        }
        $this->redirect('this');
    }

    public function handleGeocodeAllMissing(): void
    {
        // Max 30 azylů na jeden request (Nominatim rate limit ~1 req/s)
        $stats = $this->geocodingService->batchGeocodeAzyls(true, 30);
        $this->flashMessage(
            sprintf('Hromadné geocodování: zpracováno %d, úspěch %d, přeskočeno %d, chyba %d.',
                $stats['processed'], $stats['geocoded'], $stats['skipped'], $stats['failed']),
            'alert-info'
        );
        $this->redirect('this');
    }

    public function handlePropagateToAnimals(): void
    {
        $total = $this->geocodingService->batchPropagateToAnimals();
        $this->flashMessage("Souřadnice propagovány na {$total} zvířat.", 'alert-success');
        $this->redirect('this');
    }

    public function handleReindexOpenSearch(): void
    {
        $counts = $this->searchIndexerService->reindexAll();
        $this->flashMessage(
            sprintf('OpenSearch reindex dokončen: %d azylů, %d zvířat, %d měst.',
                $counts['azyls'], $counts['animals'], $counts['cities']),
            'alert-success'
        );
        $this->redirect('this');
    }

    // ===================== FINANCE =====================

    public function renderFinance(): void
    {
        $shopStats      = $this->shopOrderRepository->getGlobalStats();
        $pendingPayouts = $this->shopPayoutRepository->findPending();
        $pendingRefunds = $this->shopRefundRepository->findPending();

        $pendingPayoutTotal = array_sum(array_map(fn($p) => $p->getAmount(), $pendingPayouts));
        $pendingRefundTotal = array_sum(array_map(fn($r) => $r->getAmount(), $pendingRefunds));

        $azyls = $this->azylRepository->findAll();
        $azylFinance = [];
        foreach ($azyls as $azyl) {
            $shop  = $this->shopOrderRepository->getStatsByAzyl($azyl);
            $coll  = $this->paymentsRepository->getCollectionStatsByAzyl($azyl);
            $adopt = $this->paymentsRepository->getAdoptionStatsByAzyl($azyl);
            $total = $shop['deliveredPayout'] + $coll['totalPayout'] + $adopt['totalPayout'];
            if ($total > 0 || $shop['activeOrders'] > 0 || $coll['totalPayments'] > 0 || $adopt['totalPayments'] > 0) {
                $azylFinance[] = [
                    'azyl'        => $azyl,
                    'shopPayout'  => $shop['deliveredPayout'],
                    'shopPending' => $shop['pendingPayout'],
                    'collPayout'  => $coll['totalPayout'],
                    'adoptPayout' => $adopt['totalPayout'],
                    'totalPayout' => $total,
                    'totalFee'    => $shop['totalFee'] + $coll['totalFee'] + $adopt['totalFee'],
                ];
            }
        }
        usort($azylFinance, fn($a, $b) => $b['totalPayout'] <=> $a['totalPayout']);

        $this->getTemplate()->feesThisMonth      = $shopStats['feesThisMonth'];
        $this->getTemplate()->pendingPayouts      = $pendingPayouts;
        $this->getTemplate()->pendingRefunds      = $pendingRefunds;
        $this->getTemplate()->pendingPayoutTotal  = $pendingPayoutTotal;
        $this->getTemplate()->pendingRefundTotal  = $pendingRefundTotal;
        $this->getTemplate()->azylFinance         = $azylFinance;
        $this->getTemplate()->recentBatches       = $this->shopPayoutBatchRepository->findRecent(5);
    }

    public function renderShoppayouts(): void
    {
        $pendingPayouts = $this->shopPayoutRepository->findPending();
        $pendingRefunds = $this->shopRefundRepository->findPending();

        $pendingPayoutTotal = $this->shopPayoutRepository->getTotalPendingAmount();
        $pendingRefundTotal = $this->shopRefundRepository->getTotalPendingAmount();
        $shopStats = $this->shopOrderRepository->getGlobalStats();

        $this->getTemplate()->stats = [
            'pendingPayouts'      => $pendingPayoutTotal,
            'pendingPayoutsCount' => count($pendingPayouts),
            'pendingRefunds'      => $pendingRefundTotal,
            'pendingRefundsCount' => count($pendingRefunds),
            'feesThisMonth'       => $shopStats['feesThisMonth'],
        ];
        $this->getTemplate()->pendingPayouts = $pendingPayouts;
        $this->getTemplate()->pendingRefunds = $pendingRefunds;
        $this->getTemplate()->recentBatches  = $this->shopPayoutBatchRepository->findRecent(10);
        $this->getTemplate()->pythonAlive    = false;
        $this->getTemplate()->lastHeartbeat  = null;
    }

    public function renderShopreports(?int $year = null): void
    {
        $availableYears = $this->shopOrderRepository->getAvailableYears();
        $year = $year ?? (int)date('Y');
        if (!in_array($year, $availableYears)) {
            $availableYears[] = $year;
            sort($availableYears);
            $availableYears = array_reverse($availableYears);
        }

        $this->getTemplate()->availableYears = $availableYears;
        $this->getTemplate()->year           = $year;
        $this->getTemplate()->yearly         = $this->shopOrderRepository->getYearlyReport($year);
        $this->getTemplate()->azylSummary    = $this->shopOrderRepository->getAzylFinancialSummary($year);
        $this->getTemplate()->recentJournal  = [];
    }

    public function renderShopbatch(int $id): void
    {
        $batch = $this->shopPayoutBatchRepository->find($id);
        if (!$batch) {
            $this->error('Dávka nenalezena', 404);
        }
        $this->getTemplate()->batch   = $batch;
        $this->getTemplate()->payouts = $this->shopPayoutRepository->findByBatch($batch);
        $this->getTemplate()->refunds = $this->shopRefundRepository->findByBatch($batch);
    }

    public function renderShopbatches(): void
    {
        $this->getTemplate()->batches = $this->shopPayoutBatchRepository->findRecent(50);
    }

    public function handleShopbatchMarkSent(int $id): void
    {
        $batch = $this->shopPayoutBatchRepository->find($id);
        if ($batch) {
            $batch->markSent();
            foreach ($this->shopPayoutRepository->findByBatch($batch) as $payout) {
                $payout->markSent();
                $this->entityManager->persist($payout);
            }
            foreach ($this->shopRefundRepository->findByBatch($batch) as $refund) {
                $this->entityManager->persist($refund);
            }
            $this->entityManager->persist($batch);
            $this->entityManager->flush();
            $this->flashMessage('Dávka ' . $batch->getBatchNumber() . ' označena jako odeslaná.', 'alert-success');
        }
        $this->redirect('SuperAdmin:shopbatch', $id);
    }

    public function handleShopbatchCancel(int $id): void
    {
        $batch = $this->shopPayoutBatchRepository->find($id);
        if ($batch) {
            $batch->markCancelled();
            foreach ($this->shopPayoutRepository->findByBatch($batch) as $payout) {
                $payout->markCancelled('Batch ' . $batch->getBatchNumber() . ' zrušen');
                $this->entityManager->persist($payout);
            }
            $this->entityManager->persist($batch);
            $this->entityManager->flush();
            $this->flashMessage('Dávka zrušena, položky vráceny do fronty.', 'alert-warning');
        }
        $this->redirect('SuperAdmin:shoppayouts');
    }

    public function actionShopReportExport(string $type = 'journal', int $year = 0): void
    {
        if ($year === 0) {
            $year = (int)date('Y');
        }
        $filename = "vaz-report-{$type}-{$year}.csv";
        $rows = match ($type) {
            'azyls'   => $this->shopOrderRepository->getAzylFinancialSummary($year),
            'monthly' => $this->shopOrderRepository->getYearlyReport($year)['monthly'],
            default   => [],
        };

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        if (!empty($rows)) {
            fputcsv($out, array_keys(reset($rows)), ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
        }
        fclose($out);
        $this->getApplication()->terminate();
    }

    public function actionShopBatchExport(int $id = 0, string $format = 'csv_fio'): void
    {
        $batch = $this->shopPayoutBatchRepository->find($id);
        if (!$batch) {
            $this->error('Dávka nenalezena', 404);
        }
        $payouts = $this->shopPayoutRepository->findByBatch($batch);
        $refunds = $this->shopRefundRepository->findByBatch($batch);

        $filename = 'batch-' . $batch->getBatchNumber() . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Typ', 'Č. objednávky / VS', 'Příjemce', 'Účet', 'Kód banky', 'Částka', 'Měna'], ';');
        foreach ($payouts as $p) {
            fputcsv($out, [
                'VÝPLATA',
                $p->getOrder()->getOrderNumber(),
                $p->getAzyl()->getAzylName(),
                $p->getAzylBankAccount(),
                $p->getAzylBankCode(),
                number_format($p->getAmount(), 2, '.', ''),
                'CZK',
            ], ';');
        }
        foreach ($refunds as $r) {
            fputcsv($out, [
                'VRATKA',
                $r->getOrder()->getOrderNumber(),
                $r->getRefundReceiverName() ?? '',
                $r->getRefundAccount(),
                $r->getRefundBankCode(),
                number_format($r->getAmount(), 2, '.', ''),
                'CZK',
            ], ';');
        }
        fclose($out);

        $batch->markExported($format);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        $this->getApplication()->terminate();
    }

    public function createComponentCreateBatchForm(): \Nette\Application\UI\Form
    {
        $form = new \Nette\Application\UI\Form;
        $form->addSubmit('createBatch', 'Vytvořit platební dávku z vybraných');
        $form->onSuccess[] = [$this, 'createBatchFormSucceeded'];
        return $form;
    }

    public function createBatchFormSucceeded(\Nette\Application\UI\Form $form, \stdClass $values): void
    {
        $payoutIds = array_map('intval', array_filter(
            (array)$this->getHttpRequest()->getPost('payout_ids'),
            fn($v) => (int)$v > 0
        ));
        $refundIds = array_map('intval', array_filter(
            (array)$this->getHttpRequest()->getPost('refund_ids'),
            fn($v) => (int)$v > 0
        ));

        if (empty($payoutIds) && empty($refundIds)) {
            $this->flashMessage('Vyberte alespoň jednu položku.', 'alert-warning');
            $this->redirect('this');
            return;
        }

        $user = $this->usersRepository->find($this->getUser()->getId());
        $batch = new ShopPayoutBatch();
        $batch->setCreatedBy($user);

        $total = 0.0;
        $count = 0;

        foreach ($payoutIds as $pid) {
            $payout = $this->shopPayoutRepository->find($pid);
            if ($payout && $payout->getPayoutStatus() === PayoutStatusEnum::Pending) {
                $payout->markQueued($batch);
                $this->entityManager->persist($payout);
                $total += $payout->getAmount();
                $count++;
            }
        }
        foreach ($refundIds as $rid) {
            $refund = $this->shopRefundRepository->find($rid);
            if ($refund && $refund->getRefundStatus() === RefundStatusEnum::Pending) {
                $refund->markQueued($batch);
                $this->entityManager->persist($refund);
                $total += $refund->getAmount();
                $count++;
            }
        }

        if ($count === 0) {
            $this->flashMessage('Žádná platná položka nebyla vybrána.', 'alert-warning');
            $this->redirect('this');
            return;
        }

        $batch->setTotalAmount($total)->setItemCount($count);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        $this->flashMessage('Dávka ' . $batch->getBatchNumber() . ' vytvořena (' . $count . ' položek, ' . number_format($total, 0, ',', ' ') . ' Kč).', 'alert-success');
        $this->redirect('SuperAdmin:shopbatch', $batch->getId());
    }

    // =========================================================================
    // Výpisy z banky — ruční párování
    // =========================================================================

    public function renderBankimports(): void
    {
        $unpaired = count($this->paymentsInRepository->findUnpaired());
        $this->getTemplate()->unpairedCount = $unpaired;
    }

    public function createComponentBankImportsDatagrid(): DataGrid
    {
        return $this->bankImportsDatagridFactory->create();
    }

    public function actionBankimport(int $id): void
    {
        $payment = $this->paymentsInRepository->find($id);
        if (!$payment) {
            $this->error('Platba nenalezena', 404);
        }
        $this->getTemplate()->payment = $payment;

        // Kandidáti k párování: Payments se statusem 'expected'
        $candidates = $this->paymentsRepository->findBy(
            ['paymentStatus' => PaymentStatusEnum::Expected],
            ['createdAt' => 'DESC']
        );

        // Seřadíme — VS shoda jde první
        $vs = $payment->getVs();
        usort($candidates, function (Payments $a, Payments $b) use ($vs) {
            $aMatch = $vs && (string)$a->getVariableSymbol() === $vs ? 0 : 1;
            $bMatch = $vs && (string)$b->getVariableSymbol() === $vs ? 0 : 1;
            if ($aMatch !== $bMatch) {
                return $aMatch - $bMatch;
            }
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $this->getTemplate()->candidates = $candidates;
    }

    public function handlePairPayment(int $paymentsInId, int $paymentsId, string $note = ''): void
    {
        $bankEntry = $this->paymentsInRepository->find($paymentsInId);
        $payment   = $this->paymentsRepository->find($paymentsId);

        if (!$bankEntry || !$payment) {
            $this->flashMessage('Platba nenalezena.', 'alert-danger');
            $this->redirect('this');
            return;
        }

        if ($bankEntry->isPaired()) {
            $this->flashMessage('Tato bankovní platba je již spárována.', 'alert-warning');
            $this->redirect('this');
            return;
        }

        $currentUser = $this->usersRepository->getUserById($this->getUser()->getId());

        $bankEntry->setPairedPayment($payment);
        $bankEntry->setPairedAt(new DateTimeImmutable());
        $bankEntry->setPairedNote($note ?: null);
        $bankEntry->setPairedByUser($currentUser);
        $this->paymentsInRepository->save($bankEntry);

        $payment->setPaymentStatus(PaymentStatusEnum::Paired);
        $payment->setPayedAt($bankEntry->getDatum());
        $this->paymentsRepository->save($payment);

        $this->flashMessage('Platba úspěšně spárována.', 'alert-success');
        $this->redirect('SuperAdmin:bankimport', $paymentsInId);
    }

    public function handleUnpairPayment(int $paymentsInId): void
    {
        $bankEntry = $this->paymentsInRepository->find($paymentsInId);
        if (!$bankEntry || !$bankEntry->isPaired()) {
            $this->flashMessage('Nelze zrušit párování — platba není spárována.', 'alert-warning');
            $this->redirect('this');
            return;
        }

        $payment = $bankEntry->getPairedPayment();
        $payment->setPaymentStatus(PaymentStatusEnum::Expected);
        $payment->setPayedAt(null);
        $this->paymentsRepository->save($payment);

        $bankEntry->setPairedPayment(null);
        $bankEntry->setPairedAt(null);
        $bankEntry->setPairedNote(null);
        $bankEntry->setPairedByUser(null);
        $this->paymentsInRepository->save($bankEntry);

        $this->flashMessage('Párování zrušeno. Platba je opět ve stavu "očekávaná".', 'alert-warning');
        $this->redirect('SuperAdmin:bankimport', $paymentsInId);
    }
}