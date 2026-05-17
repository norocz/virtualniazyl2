<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Components\Datagrids\AnimalsDatagridFactory;
use App\Components\Datagrids\CitysDatagridFactory;
use App\Components\Datagrids\ContractPartsDatagridFactory;
use App\Components\Datagrids\PagesDatagridFactory;
use App\Components\Datagrids\UsersDatagridFactory;
use App\Components\Datagrids\SpeciesDatagridFactory;
use App\Forms\contractEditFormFactory;
use App\Forms\newsFormFactory;
use App\Forms\PageFormFactory;
use App\Forms\SpeciesFormFactory;
use App\Forms\PhotoUploadFormFactory;
use App\Forms\RegisterFormFactory;
use App\Forms\roleFormFactory;
use App\Forms\userDetailsFormFactory;
use App\Model\Orm\Entity\ContractParts;
use App\Model\Orm\Entity\Pages;
use App\Model\Orm\Enums\RoleTypeEnum;
use App\Model\Orm\Repository\adoptionsRepository;
use App\Model\Orm\Repository\AnalyticsRepository;
use App\Model\Orm\Repository\AnimalsRepository;
use App\Model\Orm\Repository\CollectionsRepository;
use App\Model\Orm\Repository\ContractPartsRepository;
use App\Model\Orm\Repository\PageRepository;
use App\Model\Orm\Repository\PhotosRepository;
use App\Model\Orm\Repository\UsersRepository;
use App\Model\Services\Menu;
use App\Model\VersionService;
use App\Repository\SpeciesRepository;
use App\Services\MessagesService;
use Nette\Application\UI\Presenter;
use Contributte\Application\UI\BasePresenter;
use Contributte\PdfResponse\PdfResponse;
use App\Config\I18nConfig;
use App\Config\OpenAiConfig;
use App\Services\OpenSearchService;
use Contributte\PdfResponse\PdfResponseFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Runtime\Template;
use Nette\Application\UI\Form;
use setasign\Fpdi\PdfReader\PdfReader;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Exception\DataGridException;
use App\Components\Datagrids\NewsDatagridFactory;
use App\Model\Orm\Entity\News;
use App\Model\Orm\Repository\NewsRepository;
use App\Model\Orm\Entity\Species;
use App\Model\Orm\Repository\ShopProductRepository;

class AdminPresenter extends BasePresenter
{
    use AdminMessagesAndDashboardTrait;

    private roleFormFactory $roleFormFactory;
    private UsersRepository $usersRepository;
    private EntityManagerInterface $entityManager;
    private PageFormFactory $pageFormFactory;
    private PageRepository $pageRepository;

    public function __construct(roleFormFactory                     $roleFormFactory,
                                UsersRepository                     $usersRepository,
                                PageRepository                      $pageRepository,
                                EntityManagerInterface              $entityManager,
                                PageFormFactory                     $pageFormFactory,
                                public UserDetailsFormFactory       $userDetailsFormFactory,
                                public registerFormFactory          $registerFormFactory,
                                public PhotoUploadFormFactory       $photoUploadFormFactory,
                                public UsersDatagridFactory         $usersDatagridFactory,
                                public CitysDatagridFactory         $citysDatagridFactory,
                                public PagesDatagridFactory         $pagesDatagridFactory,
                                public readonly newsFormFactory     $newsFormFactory,
                                public readonly speciesFormFactory  $speciesFormFactory,
                                public readonly newsDatagridFactory $newsDatagridFactory,
                                public readonly speciesDatagridFactory  $speciesDatagridFactory,
                                public newsRepository               $newsRepository,
                                public MessagesService              $messagesService,
                                public SpeciesRepository            $speciesRepository,
                                public AnimalsDatagridFactory      $animalsDatagridFactory,
                                private AnalyticsRepository           $analyticsRepository,
                                private AnimalsRepository              $animalsRepository,
                                private adoptionsRepository             $adoptionsRepository,
                                private PhotosRepository                $photosRepository,
                                private readonly VersionService         $versionService,
                                private readonly CollectionsRepository  $collectionsRepository,
                                private readonly contractEditFormFactory $contractEditFormFactory,
                                private ContractPartsRepository $contractPartsRepository,
                                private ContractPartsDatagridFactory $contractPartsDatagridFactory,
                                private PdfResponseFactory $pdfResponseFactory,
                                private OpenAiConfig $openAiConfig,
                                private I18nConfig $i18nConfig,
                                private OpenSearchService $openSearchService,
                                private readonly ShopProductRepository $shopProductRepository,
                                private array $smtpParams = [])
    {
        parent::__construct();
        $this->roleFormFactory = $roleFormFactory;
        $this->usersRepository = $usersRepository;
        $this->entityManager = $entityManager;
        $this->photoUploadFormFactory = $photoUploadFormFactory;
        $this->pageFormFactory = $pageFormFactory;
        $this->pageRepository = $pageRepository;
        $this->usersDatagridFactory = $usersDatagridFactory;
        $this->citysDatagridFactory = $citysDatagridFactory;
        $this->pagesDatagridFactory = $pagesDatagridFactory;
        $this->newsRepository = $newsRepository;
        $this->messagesService = $messagesService;
        $this->speciesRepository = $speciesRepository;
        $this->animalsDatagridFactory = $animalsDatagridFactory;
        $this->analyticsRepository = $analyticsRepository;
        $this->animalsRepository = $animalsRepository;
        $this->adoptionsRepository = $adoptionsRepository;


        $this->usersDatagridFactory = $usersDatagridFactory;
        $this->photosRepository = $photosRepository;
        $this->contractPartsDatagridFactory = $contractPartsDatagridFactory;
        $this->pdfResponseFactory = $pdfResponseFactory;

    }

    public function startup()
    { parent::startup();
        if (!$this->getPresenter()->getUser()->isLoggedIn()) {
            $this->redirect('Home:SignIn');

        } else {
           $userData = $this->getPresenter()->getUser()->getIdentity()->getData();
            if (!$this->getPresenter()->getUser()->isInRole('admin') && !$this->getPresenter()->getUser()->isInRole('superadmin')) {
                $this->flashMessage('Nemáte dostatečná oprávnění pro tuto akci. Akce byla zalogována!', 'alert-danger');
                $this->redirect('Home:default');
            } else {

                $menu = new Menu();

            }
        }
        $this->getTemplate()->mainMenuItems = $menu->getMenu();
    }

    protected function beforeRender(): void
    {

        $this->getTemplate()->addFilter('safeHtml', function (string $html): string {
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

        $this->getTemplate()->addFilter('formatDescription', function (string $text): string {
            // Nahrazení *Nadpis* za <strong>Nadpis</strong><br>
            $text = preg_replace('/^\*([^\n]+)/m', '<strong>$1</strong><br>', $text);

            // Nahrazení - Odrážka za <li>Odrážka</li>
            $text = preg_replace('/^- (.+)/m', '<li>$1</li>', $text);

            // Obalení odrážek do <ul>, pokud nějaké existují
            if (strpos($text, '<li>') !== false) {
                $text = '<ul>' . $text . '</ul>';
            }

            return nl2br($text); // Zachování odřádkování
        });


        $this->getTemplate()->personalPhoto = $this->photosRepository->findById($this->user->getIdentity()->getData()['User']->getPersonalPhoto());
        $this->getTemplate()->version = $this->versionService->getLastVersion();
    }

    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'Leden', 2 => 'Únor', 3 => 'Březen', 4 => 'Duben',
            5 => 'Květen', 6 => 'Červen', 7 => 'Červenec', 8 => 'Srpen',
            9 => 'Září', 10 => 'Říjen', 11 => 'Listopad', 12 => 'Prosinec'
        ];

        return $months[$month];
    }

    public function renderSettings(): void
    {
        $this->getTemplate()->title = 'Nastavení systému';
        $this->getTemplate()->aiInfo = [
            'enabled'   => $this->openAiConfig->enabled,
            'model'     => $this->openAiConfig->model,
            'languages' => $this->i18nConfig->available,
        ];
        $this->getTemplate()->searchInfo = [
            'opensearchEnabled' => $this->openSearchService->isEnabled(),
            'animalsCount'      => '—',
            'citiesCount'       => '—',
        ];
        $this->getTemplate()->smtpInfo = [
            'host'      => $this->smtpParams['host'] ?? '—',
            'port'      => $this->smtpParams['port'] ?? '—',
            'secure'    => $this->smtpParams['secure'] ?? '—',
            'fromEmail' => $this->smtpParams['username'] ?? '—',
        ];
    }

    public function renderPhotos(): void
    {
        $this->getTemplate()->title = 'Správa fotek';
        $this->getTemplate()->photos = $this->photosRepository->fetchAll();
    }

    public function renderFeedDocs(): void
    {
        $this->getTemplate()->title = 'Feed API – dokumentace';
        $this->getTemplate()->baseUrl = $this->getHttpRequest()->getUrl()->getBaseUrl();
    }

    public function renderVersions(): void
    {
        $this->getTemplate()->title = 'Aktualizace a migrace';
        $this->getTemplate()->versions = $this->versionService->getVersions();
        $this->getTemplate()->newUsersCount = $this->usersRepository->CountNewUsers();
        $this->getTemplate()->usersCount = $this->usersRepository->CountUsers();
        $this->getTemplate()->azylsCount = $this->usersRepository->CountAzyls();
    }

    public function renderUpdateMessagesAddress(): void
    {
        $this->setView('default');
        $this->getTemplate()->title = 'Aktualizace a migrace';
        $this->getTemplate()->newUsersCount = $this->usersRepository->CountNewUsers();
        $this->getTemplate()->usersCount = $this->usersRepository->CountUsers();
        $this->getTemplate()->azylsCount = $this->usersRepository->CountAzyls();
        $this->messagesService->UpdateMessages();
        $this->flashMessage('Aktualizace a migrace proběhly', 'alert-success');
    }
    public function renderAnimals(): void
    {
        $this->getTemplate()->title = 'Animals';
    }

    public function actionCollections(int $key = null): void
    {
        $this->getTemplate()->title = 'Collections';
        $this->getTemplate()->collections = $this->collectionsRepository->findAll();
    }

    public function handleStopCollection(int $key): void
    {
        if ($this->isAjax()) {

            $collection = $this->collectionsRepository->findOneByKey($key);
            $collection->setIsActive(false);
            $this->collectionsRepository->save($collection);
            $this->redrawControl('collections');
        }

    }


    public function handleApproveCollection(int $key): void
    {
        if ($this->isAjax())
        {

        $collection = $this->collectionsRepository->findOneByKey($key);
        $collection->setApproved(true);
        $this->collectionsRepository->save($collection);
        $this->redrawControl('collections');
        }
    }

    public function renderSpecies(): void
    {
        $this->getTemplate()->title = 'Species';
    }

    public function renderAzyls(): void
    {
        $this->getTemplate()->title = 'Azyls';
    }

    public function renderOwner(): void
    {
        $this->getTemplate()->title = 'Owner';
    }

    public function renderSendmessages(): void
    {
        $this->getTemplate()->title = 'Sendmessage';
    }

    public function renderAdoptions(): void
    {
        $this->getTemplate()->title = 'Adoptions';
    }

    public function renderCitys(): void
    {
        $this->getTemplate()->title = 'Citys';
    }

    public function actionNews(?int $id): void
    {
        if ($id !== null) {
            $news = $this->newsRepository->findOneBy(['id' => $id]);
            if($news === null) {
                $this->flashMessage('Novinka nebyla nalezena.', 'alert-danger');
                $this->redirect('Admin:news');
            }
            $this->getTemplate()->title = 'Editace novinky'. $news->getTitle();
            $newsForm = $this->getComponent('newsForm');
            $newsForm->setDefaults($news->toArray());
        }

        $this->getTemplate()->title = 'Novinky';
    }

    public function actionAdoptions(?int $id): void
    {

        if (!is_null($id))
        {
            $adoption = $this->adoptionsRepository->findOneBy(['id'=>$id]);
            $this->getTemplate()->adoption = $adoption;

        }
        else
        {

            $adoptions = $this->adoptionsRepository->fetchAll();

            $this->getTemplate()->adoptions = $adoptions;
        }

    }

    public function handleStopAdoption(int $id): void
    {

    }

    public function handleEndAdoption(int $id): void
    {

    }
    public function actionContractsParts()
    {
        $this->getTemplate()->title = 'Contracts';
       // $this->getTemplate()->contracts = $this->contractPartsRepository->findAll();
    }

    public function actionContracts()
    {
        $this->getTemplate()->title = 'Contracts';
        $this->getTemplate()->contracts = $this->contractPartsRepository->findAll();
    }

    public function actionContractPdf($contractId) //PDF creator
    {
        $params = ['predavajici' => 'Předávající',
            'zadatel' => 'Jméno Příjmení',
            'zvire' => 'TADY JE Jmeno zvířete',
            'druh_zvirete' => 'DRUH',
            'vek_zvirete' => 'Věk',
            'datum_pece' => 'Přijmuto',
            'zdravotni_stav' => 'Zdravotní stav',
            'datum_vlastnictvi' => date('Y-m-d'),
            'misto' => 'Ostopovice',
            'datum' => date('Y-m-d')
        ];
        $template = new Engine();
        $template->setTempDirectory(__DIR__ . '/../../temp');
        $template->setLoader(new StringLoader(['string' => $this->contractPartsRepository->findOneById(intval($contractId))->getContent()]));
        $toPDF = $template->renderToString('string', $params);
        $response = $this->pdfResponseFactory->createResponse();
        $response->setTemplate($toPDF);
        $response->setSaveMode(PdfResponse::INLINE);

        $this->sendResponse($response);
    }

    public function actionPage(?int $id): void
    {
        $this->getTemplate()->Title = 'Pages';
        if ($id !== null) {
            $page = $this->pageRepository->find($id);
            if ($page === null) {
                $this->flashMessage('Stránka nebyla nalezena.', 'alert-danger');
                $this->redirect('Admin:pages');
            }

            $pageForm = $this->getComponent('pageForm');
            $pageForm->setDefaults($page->toArray());



            /*
            $this['pageForm']['link']->setDefaults($page->getLink());
            $this['pageForm']['visibleFrom']->setDefaults($page->getVisibleFrom());
            $this['pageForm']['title']->setDefaults($page->getTitle());
            $this['pageForm']['content']->setDefaults($page->getContent());
            $this['pageForm']['important']->setDefaults($page->getImportant());
            $this['pageForm']['global']->setDefaults($page->getGlobal());
            */
        }

        $this->getTemplate()->title = 'Pages';
    }


    // Actions


    // Handle

    public function handleNewsDelete(?int $id): void
    {
        $news = $this->newsRepository->findOneBy(['id' => $id]);
        if ($news === null) {
            $this->flashMessage('Novinka nebyla nalezena.', 'alert-warning');
            if ($this->isAjax()) {
                $this->redrawControl('flashes');
                $this['actionsGrid']->reload();

            } else {
                $this->redirect('Admin:news');
            }
        } else {

            $news->setDeleted(true);
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla smazána.', 'alert-success');
            if ($this->isAjax()) {
                $this->redrawControl('flashes');
                $this['actionsGrid']->reload();

            } else {
                $this->redirect('Admin:news');
            }
        }
    }

    public function handleDelete(int $id): void
    {
        $animal = $this->animalsRepository->findById($id);
        $animal->setIsDeleted(true);
        $this->animalsRepository->saveAnimal($animal);
        $this->flashMessage('Zvířátko bylo smazáno.', 'alert-success');
        $this->redirect('this');
    }

    public function handleGlobalNewsChange(int $id, $new_global): void
    {
        $news = $this->newsRepository->findOneBy(['id' => $id]);
        $news->setGlobal($new_global);
        $this->newsRepository->save($news);
            if ($this->isAjax()) {
                $this['columnsGrid']->redrawItem($id);
            }

    }


    // Components
    public function createComponentPageForm(): Form
    {
        $form = $this->pageFormFactory->create();
        $form->onSuccess[] = [$this, 'pageFormSucceeded'];
        return $form;
    }

    public function pageFormSucceeded(Form $form, \stdClass $values): void
    {

        if ($this->getPresenter()->getParameter('id') !== null) {
            $page = $this->pageRepository->findOneBy(['id' => $this->getPresenter()->getParameter('id')]);


            if ($page) {
                $page->setLink($values->link);
                $page->setVisibleFrom($values->visibleFrom);
                $page->setTitle($values->title);
                $page->setContent($values->content);
                $page->setImportant($values->important);
                $page->setGlobal($values->global);
                $page->setUpdatedAt(new DateTimeImmutable());
                $this->pageRepository->save($page);
                $this->flashMessage('Stránka byla aktualizována.', 'alert-success');
                $this->redirect('Admin:pages');
            }
        } else {
            $page = new Pages();
            $page->setCreated(new DateTimeImmutable());
            $data = $this->getPresenter()->getUser()->getIdentity()->getData();
            $page->setAuthor($data['User']);
            $page->setLink($values->link);
            $page->setVisibleFrom($values->visibleFrom);
            $page->setTitle($values->title);
            $page->setContent($values->content);
            $page->setImportant($values->important);
            $page->setGlobal($values->global);
            $this->pageRepository->save($page);
            $this->flashMessage('Stránka byla uložena.', 'alert-success');
            $this->redirect('Admin:pages');
        }
    }

    /**
     * @throws DataGridException
     */
    public function createComponentUsersDatagrid(): DataGrid
    {
        $grid = $this->usersDatagridFactory->create();
        return $grid;
    }

    public function createComponentAzylsDatagrid(): DataGrid
    {
        $grid = new UsersDatagridFactory($this->usersRepository);
        $grid->setPresenter($this->getPresenter());

       $dataGrid =  $grid->create(); // upraví instanci, nepřepíše ji novým objektem
       $dataGrid->setDataSource($this->usersRepository->findBy(['role' => RoleTypeEnum::ROLE_AZYL]));

        return $dataGrid;

    }

    public function createComponentGuestsDatagrid(): DataGrid
    {
        $grid = new UsersDatagridFactory($this->usersRepository);
        $grid->setPresenter($this->getPresenter());

        $dataGrid = $grid->create(); // upraví instanci, nepřepíše ji novým objektem
        $dataGrid->setDataSource($this->usersRepository->findBy(['role' => RoleTypeEnum::ROLE_GUEST]));

        return $dataGrid;

    }


        public function actionContractparts(?int $id):void
        {
            $this->getTemplate()->title = 'Smlouvy';
            $this->getTemplate()->contracts = $this->contractPartsRepository->findAll();

        }
    public function createComponentOwnersDatagrid(): DataGrid
    {
        $grid = new UsersDatagridFactory($this->usersRepository);
        $grid->setPresenter($this->getPresenter());
        $dataGrid =  $grid->create(); // upraví instanci, nepřepíše ji novým objektem
        $dataGrid->setDatasource($this->usersRepository->findAll());


        return $dataGrid;
    }
    public function handleEditUser($id) : void
    {
        $user = $this->usersRepository->findOneBy(['id' => intval($id)]);
    }

    public function handleDeleteUser($id) : void
    {
        $user = $this->usersRepository->findOneBy(['id' => intval($id)]);
    }

    public function createComponentCitysDatagrid(): DataGrid
    {
        $grid = $this->citysDatagridFactory->create();
        return $grid;
    }

    public function createComponentPagesDatagrid(): DataGrid
    {
        $grid = $this->pagesDatagridFactory->create();
        return $grid;
    }

    public function createComponentSpeciesDatagrid(): DataGrid
    {
        $grid = $this->speciesDatagridFactory->create();
        return $grid;
    }

    public function createComponentSpeciesForm(): Form
    {
        $form = $this->speciesFormFactory->create();
        $form->onSuccess[] = [$this, 'speciesFormSucceeded'];

        if ($this->getPresenter()->getParameter('id') !== null)
        {
            $species = $this->speciesRepository->findOneBy(['id' => $this->getPresenter()->getParameter('id')]);
            $form->addHidden('id', $this->getPresenter()->getParameter('id'));
            $form->setDefaults([
                'name' => $species->getName(),
                'tags' => $species->getTags() ?? null,
                'description' => $species->getDescription(),
                'sex' => $species->getSex(),
                'id' => $species->getId(),
            ]);

        }

        return $form;
    }



    public function speciesFormSucceeded(Form $form, \stdClass $values): void
    {
        $species = $this->speciesRepository->findOneById(intval($this->getRequest()->getPost('id')));

        if ($species) {

                $species->setSex($values->sex);
                $species->setDescription($values->description);
                $species->setTags($values->tags);
                $this->speciesRepository->save($species);
                $this->flashMessage('Druh byl aktualizován.', 'alert-success');
                $this->redirect('Admin:species');
        }
        else
        {
            $species = new Species();
            $species->setName($values->name);
            $species->setSex($values->sex);
            $species->setDescription($values->description);
            $species->setTags($values->tags);
            $this->speciesRepository->save($species);
            $this->flashMessage('Druh byl uložen.', 'alert-success');
            $this->redirect('Admin:species');
        }
    }

    public function createComponentNewsForm(): Form
    {
        $form = $this->newsFormFactory->create();
        $form->onSuccess[] = [$this, 'newsFormSucceeded'];
        return $form;
    }

    public function newsFormSucceeded(Form $form, \stdClass $values): void
    {
        if ($this->getPresenter()->getParameter('id') !== null) {
            $news = $this->newsRepository->findOneBy(['id' => $this->getPresenter()->getParameter('id')]);
            if ($news) {
                $news->setTitle($values->title);
                $news->setContent($values->content);
                $news->setGlobal($values->global);
                $news->setVisibleFrom($values->visibleFrom);
                $news->setUpdatedAt(new DateTimeImmutable());
                $news->setDeleted(false);
                $news->setImportant($values->important);
                $news->setPined(is_null($values->pined) ? false:boolval($values->pined));
                $this->newsRepository->save($news);
                $this->flashMessage('Novinka byla aktualizována.', 'alert-success');
                $this->redirect('Admin:news');
            }
        } else {

            $news = new News();
            $author = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getIdentity()->getId());
            $news->setAuthor($author);
            $news->setTitle($values->title);
            $news->setContent($values->content);
            $news->setGlobal($values->global);
            $news->setVisibleFrom($values->visibleFrom);
            $news->setImportant($values->important);
            $news->setCreatedAt(new DateTimeImmutable());
            $news->setDeleted(false);
            $news->setPined( is_null($values->pined) ? false:boolval($values->pined));
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla uložena.', 'alert-success');
            $this->redirect('Admin:news');
        }
    }

    public function createComponentNewsDatagrid(): DataGrid
    {
        $grid = new NewsDatagridFactory($this->newsRepository);
        $grid->setPresenter($this->getPresenter());
        $grid->create(); // upraví instanci, nepřepíše ji novým objektem

        return $grid;
    }

    public function createComponentAdoptionsDatagrid(): DataGrid
    {
        $grid = $this->adoptionsDatagridFactory->create();
        return $grid;
    }

    public function createComponentAnimalsAdminDatagrid(): DataGrid
    {
        $grid = new AnimalsDatagridFactory($this->animalsRepository);
        $grid->setPresenter($this->getPresenter());
        $grid->setDatasource($this->animalsRepository->findAll());
        $grid->create(); // upraví instanci, nepřepíše ji novým objektem

        return $grid;
    }

    public function createComponentContractEditForm(): Form
    {
        $form = $this->contractEditFormFactory->create();
        if($this->getPresenter()->getParameter('id') !== null) {
            $contract = $this->contractPartsRepository->findOneById(intval($this->getPresenter()->getParameter('id')));

                if ($contract) {
                $form->setDefaults([
                    'name' => $contract->getName(),
                    'content' => $contract->getContent(),
                    'closedAt' => $contract->getClosedAt(),
                ]);
                $form->addHidden('id',intval($this->getPresenter()->getParameter('id')));
                }
        }
        $form->onSuccess[] = [$this, 'contractEditFormSucceeded'];
        return $form;
    }

    public function contractEditFormSucceeded(Form $form, \stdClass $values): void
    {
        $contractPart = new ContractParts();
        $contractPart->setName($values->name);
        $contractPart->setContent($values->content);
        $contractPart->setPartNumber(1);
        $contractPart->setCreatedAt(new DateTimeImmutable());
        $contractPart->setClosedAt( is_null($values->closedAt) ? null : new DateTimeImmutable($values->closedAt->format('Y-m-d')));
        $contractPart->setInUsage(true);
        $this->contractPartsRepository->persist($contractPart);

        if (isset($values->id) && !is_null($values->id))
            {
            $oldContract = $this->contractPartsRepository->findOneById(intval($this->getPresenter()->getParameter('id')));
            $oldContract->setInUsage(false);
            $oldContract->setClosedAt(new DateTimeImmutable());
            $this->contractPartsRepository->persist($oldContract);
            $contractPart->setOldVersion($oldContract);
            $this->flashMessage('Smlouva byla přesunuta do archivu a je vytvořena nová verze', 'alert-success');
            }
        $this->contractPartsRepository->persist($contractPart);
        $this->contractPartsRepository->flush();
        $this->flashMessage('Smlouva byla uložena', 'alert-success');
        $this->redirect('admin:contractparts');
    }

    public function createComponentContractPartsDatagrid(): DataGrid
    {
        $datagrid = $this->contractPartsDatagridFactory->create();
        return $datagrid;
    }

    public function handleContractPartClose(int $id): void
    {
        $oldContract = $this->contractPartsRepository->findOneById(intval($id));
        $oldContract->setInUsage(false);
        $oldContract->setClosedAt(new DateTimeImmutable());
        $this->contractPartsRepository->persist($oldContract);
        $this->contractPartsRepository->flush();
        $this->flashMessage('Smlouva byla nastavena jako nepoužívaná', 'alert-success');
        if($this->isAjax())
        {
            $this->redrawControl('datagrid');

        }
        else
        {
            $this->redirect('admin:contractparts');
        }

    }

    // ==================== Eshop ====================

    public function renderShopProducts(?string $filter = null): void
    {
        $this->getTemplate()->title = 'Eshop – schvalování produktů';
        $this->getTemplate()->filter = $filter;

        $pending = $this->shopProductRepository->findPendingApproval();
        $this->getTemplate()->pendingCount = count($pending);

        if ($filter === 'approved') {
            $this->getTemplate()->products = $this->shopProductRepository->findBy(['isApproved' => true], ['createdAt' => 'DESC']);
        } elseif ($filter === 'all') {
            $this->getTemplate()->products = $this->shopProductRepository->findBy([], ['createdAt' => 'DESC']);
        } else {
            $this->getTemplate()->products = $pending;
        }
    }

    public function renderShopProductDetail(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if (!$product) {
            $this->error('Produkt nenalezen', 404);
        }
        $this->getTemplate()->title = 'Eshop – detail produktu';
        $this->getTemplate()->product = $product;
        $this['rejectForm']['productId']->setDefaultValue($id);
        $this->getTemplate()->azylProducts = $this->shopProductRepository->findBy(
            ['azyl' => $product->getAzyl()],
            ['createdAt' => 'DESC']
        );
    }

    public function handleShopProductApprove(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if ($product) {
            $product->approve($this->getUser()->getId());
            $this->shopProductRepository->save($product);
            $this->flashMessage('Produkt byl schválen.', 'alert-success');
        }
        $this->redirect('Admin:shopProducts');
    }

    public function handleShopProductReject(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if ($product) {
            $product->unapprove();
            $this->shopProductRepository->save($product);
            $this->flashMessage('Produkt byl zamítnut.', 'alert-danger');
        }
        $this->redirect('Admin:shopProducts');
    }

    public function handleShopProductUnapprove(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if ($product) {
            $product->unapprove();
            $this->shopProductRepository->save($product);
            $this->flashMessage('Produkt byl odschválen a skryt z eshopu.', 'alert-warning');
        }
        $this->redirect('Admin:shopProductDetail', $id);
    }

    public function createComponentRejectForm(): Form
    {
        $form = new Form;
        $form->addHidden('productId');
        $form->addTextArea('reason', 'Důvod zamítnutí')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows', 2)
            ->setHtmlAttribute('placeholder', 'Důvod zamítnutí (azyl ho uvidí)');
        $form->addSubmit('reject', 'Zamítnout')
            ->setHtmlAttribute('class', 'btn btn-danger w-100');
        $form->onSuccess[] = [$this, 'rejectFormSucceeded'];
        return $form;
    }

    public function rejectFormSucceeded(Form $form, \stdClass $values): void
    {
        $id = (int)($values->productId ?: $this->getParameter('id'));
        $product = $this->shopProductRepository->find($id);
        if ($product) {
            $product->unapprove();
            $this->shopProductRepository->save($product);
            $this->flashMessage('Produkt byl zamítnut.', 'alert-danger');
        }
        $this->redirect('Admin:shopProducts');
    }

}