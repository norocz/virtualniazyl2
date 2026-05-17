<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Components\CityDataSource;
use App\Components\Datagrids\AnimalsDatagridFactory;
use App\Components\Datagrids\NewsDatagridFactory;
use App\Forms\adoptionStateChangeFormFactory;
use App\Forms\animalFormFactory;
use App\Forms\azylSendMessageFormFactory;
use App\Forms\azylSetingsFormFactory;
use App\Forms\CollectionFormFactory;
use App\Forms\messagesFormFactory;
use App\Forms\newsFormFactory;
use App\Forms\PhotoUploadFormFactory;
use App\Forms\RegisterFormFactory;
use App\Forms\userDetailsFormFactory;
use App\Forms\userScoreFormFactory;
use App\Model\Orm\Entity\AdoptionAction;
use App\Model\Orm\Entity\AdoptionLog;
use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Entity\Collections;
use App\Model\Orm\Entity\News;
use App\Model\Orm\Entity\Photo;
use App\Model\Orm\Entity\UsersRatings;
use App\Model\Orm\Enums\ActionTypeEnum;
use App\Model\Orm\Repository\AdoptionLogRepository;
use App\Model\Orm\Repository\AdoptionsRepository;
use App\Model\Orm\Repository\AnalyticsRepository;
use App\Model\Orm\Repository\AnimalsRepository;
use App\Model\Orm\Repository\AzylRepository;
use App\Model\Orm\Repository\CityRepository;
use App\Model\Orm\Repository\CollectionsRepository;
use App\Model\Orm\Repository\ConversationsRepository;
use App\Model\Orm\Repository\MessagesRepository;
use App\Model\Orm\Repository\NewsRepository;
use App\Model\Orm\Repository\PaymentsRepository;
use App\Model\Orm\Repository\PhotosRepository;
use App\Model\Orm\Repository\UsersRatingsRepository;
use App\Model\Orm\Repository\UsersRepository;
use App\Model\Services\Menu;
use App\Repository\SpeciesRepository;
use App\Services\AnalyticsService;
use App\Services\CollectionKeyService;
use App\Services\MessagesService;
use Brick\PhoneNumber\PhoneNumberFormat;
use Brick\PhoneNumber\PhoneNumberParseException;
use Contributte\Application\UI\BasePresenter;
use DateTimeImmutable;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use JetBrains\PhpStorm\NoReturn;
use libphonenumber\NumberParseException;
use Nette;
use Nette\Application\Attributes\Parameter;
use Nette\Application\UI\Form;
use Nette\Application\UI\InvalidLinkException;
use Random\RandomException;
use Selectt\SelecttAutocompleteControl;
use Symfony\Component\VarExporter\Internal\Values;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Exception\DataGridColumnStatusException;
use Ublaboo\DataGrid\Exception\DataGridException;
use App\Services\AzylAddressService;
use App\Model\Orm\Repository\ShopProductRepository;
use App\Model\Orm\Repository\ShopOrderRepository;
use App\Model\Orm\Entity\ShopProduct;
use App\Model\Orm\Entity\ShopProductPhoto;
use App\Model\Orm\Enums\ShopOrderStatusEnum;
use App\Forms\ShopProductFormFactory;
use App\Services\SystemSettingsReader;
use App\Services\ShopService;
use App\Model\Orm\Entity\AzylEvent;
use App\Model\Orm\Entity\AzylEventReservation;
use App\Model\Orm\Enums\RecurrenceTypeEnum;
use App\Model\Orm\Repository\AzylEventRepository;
use App\Model\Orm\Repository\AzylEventReservationRepository;
use App\Forms\AzylEventFormFactory;
use App\Services\AzylCoManagerMailService;
use App\Services\EventRegistrationMailService;
use App\Services\SlugService;
use App\Model\Orm\Repository\AzylCoManagerRepository;
use App\Model\Orm\Entity\AzylCoManager;

class AzylPresenter extends BasePresenter
{
    #[\Nette\DI\Attributes\Inject]
    public EventRegistrationMailService $eventRegistrationMailService;

    #[\Nette\DI\Attributes\Inject]
    public SlugService $slugService;

    #[\Nette\DI\Attributes\Inject]
    public AzylCoManagerRepository $azylCoManagerRepository;

    #[\Nette\DI\Attributes\Inject]
    public AzylCoManagerMailService $azylCoManagerMailService;

    #[Parameter]
    public ?int $id;
    private AnimalsRepository $animalsRepository;
    private AnimalFormFactory $animalFormFactory;
    private AzylSetingsFormFactory $azylSetingsFormFactory;


    public function __construct(AnimalsRepository                           $animalsRepository,
                                AnimalFormFactory                           $animalFormFactory,
                                AzylSetingsFormFactory                      $azylSetingsFormFactory,
                                public NewsRepository                       $newsRepository,
                                public NewsFormFactory                      $newsFormFactory,
                                public NewsDatagridFactory                  $newsDatagridFactory,
                                public AnimalsDatagridFactory               $animalsDatagridFactory,
                                public Photo                                $photos,
                                public PhotosRepository                     $photosRepository,
                                public SpeciesRepository                    $speciesRepository,
                                public UsersRepository                      $usersRepository,
                                public AzylRepository                       $azylRepository,
                                private MessagesRepository                  $messagesRepository,
                                private messagesFormFactory                 $messagesFormFactory,
                                private messagesService                     $messagesService,
                                private AnalyticsRepository                 $analyticsRepository,
                                private AnalyticsService                    $analyticsService,
                                private AdoptionsRepository                 $adoptionsRepository,
                                private CollectionsRepository               $collectionsRepository,
                                private CollectionFormFactory               $collectionFormFactory,
                                private collectionKeyService                $collectionKeyService,
                                private PhotoUploadFormFactory              $photoUploadFormFactory,
                                private readonly PaymentsRepository         $paymentsRepository,
                                private readonly cityRepository             $cityRepository,
                                private readonly CityDataSource             $cityDataSource,
                                private readonly RegisterFormFactory        $registerFormFactory,
                                private readonly userDetailsFormFactory     $userDetailsFormFactory,
                                private readonly azylSendMessageFormFactory $azylSendMessageFormFactory,
                                private AzylAddressService                  $azylAddressService,
                                private ConversationsRepository             $conversationsRepository,
                                private EntityManagerInterface              $entityManager,
                                private AdoptionLogRepository               $adoptionLogRepository,
                                private AdoptionStateChangeFormFactory      $adoptionStateChangeFormFactory,
                                private UserScoreFormFactory                $userScoreFormFactory,
                                private UsersRatingsRepository              $usersRatingsRepository,
                                private readonly ShopProductRepository      $shopProductRepository,
                                private readonly ShopOrderRepository        $shopOrderRepository,
                                private readonly ShopProductFormFactory     $shopProductFormFactory,
                                private readonly SystemSettingsReader       $systemSettings,
                                private readonly ShopService                $shopService,
                                private readonly AzylEventRepository        $azylEventRepository,
                                private readonly AzylEventReservationRepository $azylEventReservationRepository,
                                private readonly AzylEventFormFactory       $azylEventFormFactory,
    )
    {
        parent::__construct();
        $this->animalsRepository = $animalsRepository;
        $this->animalFormFactory = $animalFormFactory;
        $this->azylSetingsFormFactory = $azylSetingsFormFactory;
        $this->entityManager = $entityManager;


    }

    public function startup(): void
    {
        parent::startup();
        if (!$this->getPresenter()->getUser()->loggedIn) {
            //  $session = $this->getPresenter()->getSession();
            //  $session->set('backUrl', 'User:'.$this->getPresenter()->getAction());


            $this->redirect('Home:SignIn');
        } else {

            if (!($this->getPresenter()->getUser()->isInRole('azyl') || $this->getPresenter()->getUser()->isInRole('superadmin') || $this->getPresenter()->getUser()->isInRole('azyladmin'))) {
                $this->flashMessage('Nemáte dostatečná oprávnění pro tuto akci. Akce byla zalogována!', 'alert-danger');
                $this->analyticsService->setPresenter($this);
                $this->analyticsService->setComment('Azyl presenter, nepovolený přístup|' . $this->getPresenter()->getAction() . ' | ' . $this->getPresenter()->getUser()->getIdentity()->getId());
                $this->analyticsService->logVisit();
                $this->redirect('Home:default');
            } else {
                if (!is_null($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl'])) {

                    $menu = new Menu();
                    $this->getTemplate()->mainMenuItems = $menu->getMenu();
                    $this->analyticsService->setPresenter($this);
                    $this->analyticsService->setComment('Azyl presenter |' . $this->getPresenter()->getAction() . ' | ' . $this->getPresenter()->getUser()->getIdentity()->getId());
                    $this->analyticsService->logVisit();
                } else {
                    $this->getPresenter()->redirect('SuperAdmin:SetAzyl');
                }
            }
        }

    }

    /**
     * @throws NonUniqueResultException
     */
    protected function beforeRender(): void
    {
        $this->getTemplate()->addFilter('json', fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->getTemplate()->addFilter('safeHtml', function (string $html): string {
            $allowedTags = ['b', 'i', 'a', 'p', 'br'];
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
        $this->getTemplate()->random = $this->getUser()->getIdentity()->getData()['Azyl']->getRandom();

        $azyl = $this->getUser()->getIdentity()->getData()['Azyl'];
        $this->getTemplate()->totalUnread = $this->messagesRepository->countUnreadMessagesForAzyl($azyl);
    }


    public function handleDelete(int $id): void
    {
        $animal = $this->animalsRepository->findById($id);
        $animal->setIsDeleted(true);
        $this->animalsRepository->saveAnimal($animal);
        $this->flashMessage('Zvířátko bylo smazáno.', 'alert-success');
        $this->redirect('this');
    }


    public function createComponentAdoptionStateForm(): Form
    {
        $form = $this->adoptionStateChangeFormFactory->create();
        $form->onSuccess[] = [$this, 'adoptionStateChangeFormSubmitted'];
        return $form;
    }

    public function adoptionStateChangeFormSubmitted(Form $form, \stdClass $values): void
    {
        $log = new AdoptionLog();
        $adoption = $this->adoptionsRepository->findOneBy(['id' => intval($this->getPresenter()->getParameter('id'))]);
        $animal = $this->animalsRepository->findOneBy(['id' => $adoption->getAnimal()->getId()]);
        $adoption->setUpdatedAt(new DateTimeImmutable());
        $log->setAdoption($adoption);
        $log->setCreatedAt(new DateTimeImmutable());

        if ($form['comment']->isSubmittedBy())  //jen komentář
        {
            $log->setComment($values->commentText);
            $log->setActionType($adoption->getActionType());
            $this->flashMessage('Komentář k adopci přidán.', 'alert-primary');
        } elseif ($form['writ']->isSubmittedBy()) //písemný kontakt
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(false);
            $animal->setToAdoption(true);
            $adoption->setActionType(ActionTypeEnum::CONTACT_ADOPTION);
            $log->setActionType(ActionTypeEnum::CONTACT_ADOPTION);
            $this->flashMessage('Písemný kontakt.', 'alert-primary');
        } elseif ($form['phon']->isSubmittedBy()) //telefonický kontakt
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(false);
            $animal->setToAdoption(true);
            $adoption->setActionType(ActionTypeEnum::PHONE_CALL_ADOPTION);
            $log->setActionType(ActionTypeEnum::PHONE_CALL_ADOPTION);
            $this->flashMessage('Telefonický kontakt.', 'alert-primary');
        } elseif ($form['pers']->isSubmittedBy()) //osobní kontakt
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(false);
            $animal->setToAdoption(true);
            $adoption->setActionType(ActionTypeEnum::PERSONAL_VISIT_ADOPTION);
            $log->setActionType(ActionTypeEnum::PERSONAL_VISIT_ADOPTION);
            $this->flashMessage('Osobní kontakt.', 'alert-primary');
        } elseif ($form['pre']->isSubmittedBy()) //předschválení adopce
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(false);
            $animal->setToAdoption(false);
            $adoption->setActionType(ActionTypeEnum::VERIFICATION_ADOPTION);
            $log->setActionType(ActionTypeEnum::VERIFICATION_ADOPTION);
            $this->flashMessage('Pro adopci byla vystavena smlouva a adoptující byl vyzván aby podepsal smlouvu a adopční podmínky', 'alert-success');
        } elseif ($form['ok']->isSubmittedBy()) //potvrzení adopce
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(true);
            $animal->setToAdoption(false);
            $adoption->setActionType(ActionTypeEnum::POSITIVE_ADOPTION_END);
            $log->setActionType(ActionTypeEnum::POSITIVE_ADOPTION_END);
            $this->flashMessage('Super! Adopce dobře dopadlo... paráda na světě je zase o něco víc lásky! :-)', 'alert-success');
        } elseif ($form['stop']->isSubmittedBy()) //zrušení adopce
        {
            $log->setComment($values->commentText);
            $animal->setAdopted(false);
            $animal->setToAdoption(true);
            $adoption->setActionType(ActionTypeEnum::NEGATIVE_ADOPTION_END);
            $log->setActionType(ActionTypeEnum::NEGATIVE_ADOPTION_END);
            $this->flashMessage('Adopce zastavena', 'alert-warning');
        }

        $this->animalsRepository->saveAnimal($animal);
        $this->adoptionsRepository->saveAdoption($adoption);
        $this->adoptionLogRepository->save($log);
        if ($this->isAjax()) {
            $this->redrawControl('adoptionInteraction');
        } else {
            $this->redirect('this');
        }
    }

    public function createComponentUserScoreForm(): Form
    {
        $form = $this->userScoreFormFactory->create();
        $form->onSuccess[] = [$this, 'userScoreFormSubmitted'];
        return $form;

    }

    public function userScoreFormSubmitted(Form $form, \stdClass $values): void
    {
        $userRating = new UsersRatings();
        $userRating->setReviewer($this->usersRepository->getUserById($this->getUser()->getId()));
        $userRating->setCreatedAt(new DateTimeImmutable());
        $userRating->setAzyl($this->azylRepository->findOneBy(['id' => $this->getUser()->getIdentity()->getData()['Azyl']->getId()]));
        $userRating->setUser($this->adoptionsRepository->findOneBy(['id' => intval($this->getParameter('id'))])->getUser());
        if ($form['1']->isSubmittedBy()) {
            $userRating->setRating(1);
        } elseif ($form['2']->isSubmittedBy()) {
            $userRating->setRating(2);
        } elseif ($form['3']->isSubmittedBy()) {
            $userRating->setRating(3);
        } elseif ($form['4']->isSubmittedBy()) {
            $userRating->setRating(4);
        } elseif ($form['5']->isSubmittedBy()) {
            $userRating->setRating(5);
        }

        $userRating->setReview($values->comment);
        $this->usersRatingsRepository->save($userRating);

        $this->flashMessage('Hodnocení adoptujícího uloženo', ' alert-success');
        if ($this->isAjax()) {
            $this->redrawControl('rating');
        } else {
            $this->redirect('this');
        }
    }

    public function handleUserReview($user, $r): void
    {

        $this->flashMessage('Hodnocení uloženo', 'alert-success');
    }

    public function renderDefault(): void
    {
        if (empty($this->getUser()->getIdentity()->getData()['Azyl']->getAzylName())) {
            $this->flashMessage('Nejprve nastavte základní informace o Vašem azylu (nejsou stejné jako vaše uživatelská nastavení), důležitý je název nějaký smypatický popis a kontakt
                                         především město, podle něj se dá vyhodnotit jak blízko jste k zájemcům o adopci!', 'alert-warning');

            $this->redirect('Azyl:settings');

        }

        $this->getTemplate()->title = 'Azyl';

        if ($this->getPresenter()->getUser()->getRoles()[0] === 'superadmin') {
            $this->getTemplate()->countAnimals = $this->animalsRepository->countByAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->countVisits = $this->analyticsRepository->countVisitsForAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $this->getTemplate()->visitors = $this->analyticsRepository->getVisitorsForAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
        } else {
            $this->getTemplate()->countAnimals = $this->animalsRepository->countByAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->countVisits = $this->analyticsRepository->countVisitsForAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $this->getTemplate()->visitors = $this->analyticsRepository->getVisitorsForAzyl($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
        }

    }

    public function renderAnimals(): void
    {
        $this->template->title = 'Animals';
    }

    public function renderCollection(): void
    {
        $this->getTemplate()->title = 'Collection';
    }

    public function actionCollections(?int $key = null): void
    {
        if ($key === null) {
            $this->getTemplate()->collectionsNoActive = $this->collectionsRepository->findByAzylNoActive($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->collections = $this->collectionsRepository->findByAzylActive($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->collectionsWaiting = $this->collectionsRepository->findByAzylWaiting($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
        } else {
            $this->getTemplate()->collectionsNoActive = $this->collectionsRepository->findByAzylNoActive($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->collections = $this->collectionsRepository->findByAzylActive($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->collectionsWaiting = $this->collectionsRepository->findByAzylWaiting($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']);
            $this->getTemplate()->paymentsAll = $this->paymentsRepository->findBy(['variableSymbol' => $key, 'azyl' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']]);
        }
    }

    public function handleStopCollection(int $key): void //TODO: platby a stopování sbírky
    {
        $collection = $this->collectionsRepository->findOneByKey(intval($key));
        $collection->setIsActive(false); //nastavit na vypnuto
        $this->collectionsRepository->save($collection);
        $this->flashMessage('Sbírka byla nastavena na neaktivní', 'alert-success');
        if ($this->isAjax()) {
            $this->redrawControl();
        } else {
            $this->redirect('this');
        }

    }

    public function handleCollectionPayments(int $key): void
    {
        $this->getTemplate()->payments = $this->collectionsRepository->findOneByKey($key)->getPayments();
        if ($this->isAjax()) {
            $this->redrawControl('payments-' . $key);
        }
    }

    public function createComponentCollectionForm(): Form
    {
        $form = $this->collectionFormFactory->create();
        $form->removeComponent($form->getComponent('send'));
        $form->addSubmit('send', 'Uložit sbírku')->setHtmlAttribute('class', 'btn btn-primary');

        $form->onSuccess[] = [$this, 'collectionFormSucceeded'];

        if (is_null($this->getPresenter()->getParameter('key'))) {
            return $form;
        } else {
            $collection = $this->collectionsRepository->findOneByKey(intval($this->getPresenter()->getParameter('key')));
            $form->setDefaults([
                'collectionName' => $collection->getCollectionName(),
                'collectionId' => $collection->getId(),
                'collectionDescription' => $collection->getCollectionDescription(),
                'minimalAmount' => $collection->getMinimalAmount() ?? 50,
                'resultAmount' => $collection->getResultAmount(),
                'extendedAmount' => $collection->getExtendedAmount() ?? 0,
                'startAt' => $collection->getStartAt(),
                'endingAt' => $collection->getEndingAt(),
                'extendTo' => $collection->getExtendTo(),
                'currency' => $collection->getCurrency(),
                'extend' => 'true',
                'isActive' => $collection->isActive()

            ]);
            return $form;
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws RandomException
     */
    public function collectionFormSucceeded(Form $form, $values): void
    {
        if (is_null($this->getPresenter()->getParameter('key'))) {
            $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $user = $this->usersRepository->findOneBy(['id' => $this->getPresenter()->getUser()->getIdentity()->getData()['User']->getId()]);
            $collection = new Collections();
            $collection->setAzyl($azyl);
            $collection->setCurrency($values['currency']);
            $collection->setCollectionDescription($values['collectionDescription']);
            $collection->setCollectionName($values['collectionName']);
            $collection->setMinimalAmount($values['minimalAmount']);
            $collection->setResultAmount($values['resultAmount']);
            $collection->setExtendedAmount($values['extendedAmount']);
            $collection->setCreatedAt(new DateTimeImmutable('now'));
            $collection->setEndingAt($values['endingAt']);
            $collection->setUser($user);
            $collection->setExtend($values['extend']);
            $collection->setStartAt($values['startAt']);
            $collection->setIsActive($values['isActive']);
            $collection->setApproved(false);
            $this->collectionsRepository->save($collection);
            $collection->setCollectionKey($this->collectionKeyService->createCollectionKey($azyl->getId(), $collection->getId()));

            if ($values['headline']->hasFile()) {
                $photo = new Photo();
                $photo->setAzyl($azyl);
                $photo->setCollections($collection);
                $photo->setUser($user);
                $photo->setDate(new DateTimeImmutable());
                $photo->uploadCollectionHeadlinePhoto($values['headline']);
                $this->photosRepository->save($photo);
                $collection->setPhoto($photo);
            }
            $this->collectionsRepository->save($collection);
            $this->flashMessage('Sbírka byla uložena, pokud je datum nastavené na dnešek ihned se spustí', 'alert-success');
            $this->getPresenter()->redirect('Azyl:Collections');
        } else {
            $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $user = $this->usersRepository->findOneBy(['id' => $this->getPresenter()->getUser()->getIdentity()->getData()['User']->getId()]);
            $collection = $this->collectionsRepository->findOneByKey(intval($this->getPresenter()->getParameter('key')));
            $collection->setAzyl($azyl);
            $collection->setCurrency($values['currency']);
            $collection->setCollectionDescription($values['collectionDescription']);
            $collection->setCollectionName($values['collectionName']);
            $collection->setMinimalAmount($values['minimalAmount']);
            $collection->setResultAmount($values['resultAmount']);
            $collection->setExtendedAmount($values['extendedAmount']);
            $collection->setCreatedAt(new DateTimeImmutable('now'));
            $collection->setEndingAt($values['endingAt']);
            $collection->setUser($user);
            $collection->setExtend($values['extend']);
            $collection->setStartAt($values['startAt']);
            $collection->setIsActive($values['isActive']);
            $collection->setApproved(false);
            if ($values['headline']->hasFile()) {

                $photo = new Photo();
                $photo->setAzyl($azyl);
                $photo->setCollections($collection);
                $photo->setUser($user);
                $photo->setDate(new DateTimeImmutable());
                $photo->uploadCollectionHeadlinePhoto($values['headline']);
                $this->photosRepository->save($photo);
                $collection->setPhoto($photo);
            } else {
                $collection->setPhoto(null);
            }

            $this->collectionsRepository->save($collection);
            $this->flashMessage('Sbírka byla uložena, pokud je datum nastavené na dnešek ihned se spustí', 'alert-success');
            $this->getPresenter()->redirect('Azyl:Collections');
        }
    }

    public function renderProfil(): void
    {
        $user = $this->usersRepository->getUserById($this->getUser()->getId());
        $photos = $user->getPhotos();

        $this->getTemplate()->title = 'Uživatelský Profil';
        $this->getTemplate()->personalPhoto = $this->photosRepository->findById($user->getPersonalPhoto());
        $this->getTemplate()->adoptions = empty($user->getAdoptions()) ? null : $user->getAdoptions();
        $this->getTemplate()->photos = $photos;

        $city = $this->cityRepository->findOneBy(['id' => $user->getCity()]); //co to tady je
        if ($city !== null) {
            $this->getTemplate()->regions = $this->cityRepository->findRegionByCountry($city->getCountry());
            $this->getTemplate()->cities = $this->cityRepository->findCityByRegionArray($city->getRegion());

            $this->getTemplate()->city = $city->getId();
            $this->getTemplate()->region = $city->getRegion();
        } else {
            $this->getTemplate()->regions = $this->cityRepository->fetchCountries();
            $this->getTemplate()->cities = $this->cityRepository->findCityByRegionArray('Kroměříž');

            $this->getTemplate()->city = null;
            $this->getTemplate()->region = null;
        }
    }

    public function actionAnimal(?int $id = null): void
    {
        if ($id === null) {
            $this->getTemplate()->title = 'Azyl - Přidání nového zvířátka';
        } else {
            if (!$animal = $this->animalsRepository->findById($id)) {
                $this->getTemplate()->title = 'Azyl - Přidání nového zvířátka';

            } else {
                $this->getTemplate()->photos = $animal->getPhotos();
                if ($animal->getAdoption()) {
                    $this->getTemplate()->adoptions = $animal->getAdoption();
                }
                $this->getTemplate()->title = 'Azyl - Editace zvířátka';
                $this['animalForm']->setDefaults($animal);
            }
        }
    }

    public function actionMessages(?string $id): void
    {
        $this->getTemplate()->title = 'Zprávy';
        $azyl = $this->azylRepository->findOneBy(['id' => $this->getUser()->getIdentity()->getData()['Azyl']->getId()]);
        $currentUserId = $this->getUser()->getId();

        $allChats = $this->conversationsRepository->findByAzyl($azyl);

        // Azyl nevidí sám sebe — vyloučíme konverzace kde je user == přihlášený admin
        $chats = array_values(array_filter($allChats ?? [], function ($chat) use ($currentUserId) {
            $chatUser = $chat->getUser();
            return $chatUser !== null && $chatUser->getId() !== $currentUserId;
        }));

        // Počty nepřečtených zpráv per konverzace (pro odznačky)
        $unreadByConversation = [];
        foreach ($chats as $chat) {
            $msgs = $this->messagesRepository->findBytConversationMessages($chat->getId());
            $unreadByConversation[$chat->getId()] = count(array_filter($msgs ?? [], fn($m) => !$m->getReaded() && $m->getAzyl() === null));
        }

        $this->getTemplate()->chats = $chats;
        $this->getTemplate()->unreadByConversation = $unreadByConversation;
        $this->getTemplate()->totalUnread = array_sum($unreadByConversation);

        // Pokud je $id zadáno (kliknutý kontakt), načteme zprávy dané konverzace
        if ($id !== null) {
            $this->getTemplate()->messages = $this->messagesRepository->findBytConversationMessages($id);
            $this->getTemplate()->conversation = $id;
            $this->messagesService->markMessagesAsRead($id);
        }
    }

    public function actionAdoptions(?int $id): void
    {

        if (!is_null($id)) {
            $adoption = $this->adoptionsRepository->findOneBy(['id' => $id]);
            if (!$adoption) {
                throw new Nette\Application\BadRequestException('Stránka není dostupná', 404);
            }
            $reviews = $this->usersRatingsRepository->findByUser($adoption->getUser());
            $this->getTemplate()->adoption = $adoption;
            $this->getTemplate()->userPhotos = $adoption->getUser()->getPhotos();
            $this->getTemplate()->hodnoceni = $reviews;

        } else {

            $adoptions = $this->adoptionsRepository->findBy(['azyl' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']], ['updatedAt' => 'DESC']);

            $this->getTemplate()->adoptions = $adoptions;
        }

    }


    public function handleChat(string $conversation): void
    {
        $this->getTemplate()->messages = $this->messagesRepository->findBytConversationMessages($conversation);
        $this->getTemplate()->conversation = $conversation;
        $this->messagesService->markMessagesAsRead($conversation);

        if ($this->isAjax()) {
            $unread = $this->getTemplate()->unreadByConversation ?? [];
            $unread[$conversation] = 0;
            $this->getTemplate()->unreadByConversation = $unread;
            $this->getTemplate()->totalUnread = array_sum($unread);

            $this->redrawControl('chats');
            $this->redrawControl('messages');
        }
    }

    /**
     * @throws NonUniqueResultException
     * @throws InvalidLinkException
     */
    public function handleDeleteMsg(int $id): void
    {
        $redirectAddress = $this->messagesRepository->getMessagesById($id, $this->getUser()->getIdentity()->getData()['user']);
        if ($this->messagesService->deleteMessage($id, $this->getPresenter())) {
            $this->flashMessage('Vzkaz byl smazán.', 'alert-success');
        } else {

            $this->flashMessage('Při mazání vzkazu nastala chyba.', 'alert-danger');
        }
        if ($this->isAjax()) {
            $this->redrawControl('messagesCount');
            $this->redrawControl('chats');
            $this->redrawControl('messages');
        } else {
            // Use signal link to avoid sha256 hash in URL path (causes 404)
            $this->redirectUrl($this->link('this') . '?do=chat&id=' . urlencode($redirectAddress));
        }
    }

    public function messagesFormSucceeded(Form $form, \stdClass $values): void
    {
        $this->messagesService->messagesFormSucceeded($form, $values, $this);

        if ($this->isAjax()) {
            $form->reset();
            $this->getTemplate()->messages = $this->messagesRepository->findBytConversationMessages($values->address);
            $this->getTemplate()->conversation = $values->address;
            $this->redrawControl('messages');
        }
        // Non-AJAX: service already called redirectUrl (AbortException) — this code is unreachable
    }

    public function actionNews(?int $id): void
    {
        $this->getTemplate()->title = 'News';
    }

    public function renderPhotos(): void //TODO: Zobrazení Fotek azylu
    {
        $this->getTemplate()->title = 'Photos';
        $this->getTemplate()->basepath = '';
        $this->getTemplate()->photos = $this->photosRepository->fetchByAzylId($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
    }

    public function renderMessages(): void
    {
        $this->getTemplate()->title = 'Message';
    }

    public function renderManagers(): void
    {
        $user = $this->getUser();
        if (!$user->isInRole('azyl') && !$user->isInRole('superadmin')) {
            $this->flashMessage('Přidávání správců je vyhrazeno zakladateli azylu.', 'alert-warning');
            $this->redirect('Azyl:default');
        }
        $azyl = $this->azylRepository->findById($user->getIdentity()->getData()['Azyl']->getId());
        $this->getTemplate()->managers = $this->azylCoManagerRepository->findAllForAzyl($azyl);
        $this->getTemplate()->title = 'Správci azylu';
    }

    public function handleRemoveManager(int $managerId): void
    {
        $user = $this->getUser();
        if (!$user->isInRole('azyl') && !$user->isInRole('superadmin')) {
            $this->flashMessage('Tato akce je vyhrazena zakladateli azylu.', 'alert-warning');
            $this->redirect('Azyl:default');
        }

        $azyl = $this->azylRepository->findById($user->getIdentity()->getData()['Azyl']->getId());
        $cm   = $this->azylCoManagerRepository->find($managerId);

        if (!$cm || $cm->getAzyl()->getId() !== $azyl->getId()) {
            $this->flashMessage('Správce nebyl nalezen.', 'alert-warning');
            $this->redirect('Azyl:managers');
        }

        $this->azylCoManagerRepository->remove($cm);
        $this->flashMessage('Správce byl odebrán.', 'alert-success');
        $this->redirect('Azyl:managers');
    }

    public function renderSettings(): void
    {
        $this->getTemplate()->title = 'Settings';
        $azyl = $this->azylRepository->findById($this->getUser()->getIdentity()->getData()['Azyl']->getId());
        $this->getTemplate()->azylLat  = $azyl->getLatitude();
        $this->getTemplate()->azylLon  = $azyl->getLongitude();
        $this->getTemplate()->azylHasCoords = $azyl->hasCoordinates();
    }


    public function renderAdoptions(): void
    {
        $this->getTemplate()->title = 'Adoptions - Azyl: ' . $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getAzylName();

    }

    // Actions

    // Handle
    public function handleDeleteAzylPhoto(int $photoId): void  // označí fotku jako smazanou kontroluje ID fotky a ID azylu, aby nemohl fotku smazat někdo jiný podvrhnutím IDfotky
    {
        $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
        $photo = $this->photosRepository->findOneBy(['id' => $photoId, 'azyl' => $azyl]);
        if (!empty($photo)) {
            $photo->setDeleted(true);
            $this->photosRepository->save($photo);
            $this->flashMessage('Fotka smazána', 'alert-success');
            if ($this->isAjax()) {

                $this->redrawControl('photos');
            } else {
                $this->redirect('this');
            }
        } else {
            $this->flashMessage('Fotku nelze smazat', 'alert-danger');
            if ($this->isAjax()) {

                $this->redrawControl('photos');
            } else {
                $this->redirect('this');
            }

        }
    }

    public function handleDeleteUserPhoto(int $id): void
    {

        $photo = $this->photosRepository->findOneBy(['id' => $id, 'user' => $this->getPresenter()->getUser()->getId()]);
        if (!empty($photo)) {
            $photo->setDeleted(true);
            $this->photosRepository->save($photo);
            $this->flashMessage('Fotka smazána', 'alert-success');
            if ($this->isAjax()) {

                $this->redrawControl('photos');
            } else {
                $this->redirect('this');
            }
        } else {
            $this->flashMessage('Fotku nelze smazat', 'alert-danger');
            if ($this->isAjax()) {

                $this->redrawControl('photos');
            } else {
                $this->redirect('this');
            }

        }

    }

    public function handleSetHomeAzylPhoto(int $id): void //nastaví fotku jako hlavní fotku Azylového profilu kontorluje to azyl podle profilu a id fotky
    {
        $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
        $photo = $this->photosRepository->findOneBy(['id' => $id, 'azyl' => $azyl]);

        if (!empty($photo)) {
            $azyl->setMainPhoto($photo->getId());
            $this->azylRepository->saveAzyl($azyl);
            $this->flashMessage('Fotka nastavena', 'alert-success');
        } else {
            $this->flashMessage('Fotku nelze nastavit', 'alert-danger');

        }

    }


    public function handleNewsDelete(?int $id): void
    {
        $news = $this->newsRepository->findOneBy(['id' => $id]);
        if (!$news) {
            $this->flashMessage('Novinka nebyla nalezena.', 'alert-warning');
            if ($this->isAjax()) {
                $this->redrawControl('flashes');
                $this['actionsGrid']->reload();

            } else {
                $this->redirect('Azyl:news');
            }
        } else {

            $news->setDeleted(true);
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla smazána.', 'alert-success');
            if ($this->isAjax()) {
                $this->redrawControl('flashes');
                $this['actionsGrid']->reload();

            } else {
                $this->redirect('Azyl:news');
            }
        }
    }

    // Components

    public function createComponentAnimalForm(): Form
    {
        $form = $this->animalFormFactory->create();
        $form->onSuccess[] = [$this, 'animalFormSucceeded'];
        if ($this->getPresenter()->getParameter('id') !== null) {
            //  $animal = $this->animalsRepository->findById(intval($this->getPresenter()->getParameter('id')));
            $animal = $this->animalsRepository->findOneBy(['id' => intval($this->getPresenter()->getParameter('id')), 'azyl' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']]);
            $form->setDefaults([
                    'name' => $animal->getName(),
                    'description' => $animal->getDescription(),
                    'species' => $animal->getSpecies()->getId(),
                    'birthDate' => !is_null($animal->getBirthdate()) ? $animal->getBirthdate()->format('d-m-Y') : null,
                    'reception' => !is_null($animal->getReception()) ? $animal->getReception()->format('d-m-Y') : null,
                    'breed' => $animal->getBreed(),
                    'toAdoption' => $animal->isToAdoption(),
                    'adoptionType' => $animal->getAdoptionType(),
                    'multiAdoption' => $animal->getMultiAdoption(),
                    'signed' => !is_null($animal->getSigned()) ? $animal->getSigned() : 'no',
                    'weight' => $animal->getWeight(),
                    'height' => $animal->getHeight(),
                    'howMuch' => $animal->getHowMuch()]
            );
        }
        return $form;
    }

    /**
     * @throws InvalidLinkException
     * @throws NonUniqueResultException
     */
    public function createComponentAzylSettingsForm(): Form
    {
        $formDef = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
        $city = $this->cityRepository->findCityById(intval($formDef->getCity()));
        $factory = $this->azylSetingsFormFactory;
        $factory->setLink($this->link('Json:select2'));
        $form = $factory->create();
        if ($city !== null) {
            $form['city']->setItems([$city->getId() => $city->getCityName()], true);
        }

        $form->setDefaults([
            'id' => $formDef->getId(),
            'azylName' => $formDef->getAzylName(),
            'description' => $formDef->getDescription(),
            'bankAccount' => $formDef->getBankAccount(),
            'bankCode' => $formDef->getBankCode(),
            'bankSpecificCode' => $formDef->getBankSpecificCode(),
            'phoneNumber' => $formDef->getPhoneNumber(),
            'city' => $formDef->getCity(),
            'web' => $formDef->getWeb(),
            'email' => $formDef->getEmail(),
            'ico' => $formDef->getIco(),
            'shortDescription' => $formDef->getShortDescription(),
            'shippingFee' => $formDef->getShippingFee(),
            'packagingFee' => $formDef->getPackagingFee(),
            'shopFeePercent' => $formDef->getShopFeePercent(),
            'shopThemeColor' => $formDef->getShopThemeColor() ?? '',
            'street' => $formDef->getStreet(),
            'houseNumber' => $formDef->getHouseNumber(),
            'zipCode' => $formDef->getZipCode(),
            'countryCode' => $formDef->getCountryCode(),
            'latitude' => $formDef->getLatitude(),
            'longitude' => $formDef->getLongitude(),
            'slug' => $formDef->getSlug() ?? '',
        ]);

        $form->onSuccess[] = [$this, 'azylSettingsFormSucceeded'];
        return $form;
    }

    #[NoReturn] public function azylSettingsFormSucceeded(Form $form, \stdClass $values): void
    {
        $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());

        $azyl->setAzylName($values->azylName);
        $azyl->setDescription($values->description);
        $azyl->setBankAccount($values->bankAccount);
        $azyl->setBankCode($values->bankCode);
        $azyl->setBankSpecificCode($values->bankSpecificCode);
        $azyl->setPhoneNumber($values->phoneNumber);
        $azyl->setEmail($values->email);
        $azyl->setWeb($values->web);
        $azyl->setIco($values->ico);
        $azyl->setShortDescription($values->shortDescription);
        $azyl->setShippingFee($values->shippingFee !== '' && $values->shippingFee !== null ? (float)$values->shippingFee : null);
        $azyl->setPackagingFee($values->packagingFee !== '' && $values->packagingFee !== null ? (float)$values->packagingFee : null);
        $azyl->setShopFeePercent($values->shopFeePercent !== '' && $values->shopFeePercent !== null ? (float)$values->shopFeePercent : null);
        $azyl->setShopThemeColor($values->shopThemeColor !== '' ? $values->shopThemeColor : null);
        $azyl->setCity(intval($this->getRequest()->getPost('city')));
        $azyl->setStreet($values->street ?: null);
        $azyl->setHouseNumber($values->houseNumber ?: null);
        $azyl->setZipCode($values->zipCode ?: null);
        $azyl->setCountryCode($values->countryCode ?: 'CZ');
        if ($values->latitude !== '' && $values->longitude !== '') {
            $azyl->setLatitude((float)$values->latitude);
            $azyl->setLongitude((float)$values->longitude);
        }
        if (is_null($azyl->getMessageAddress())) {
            $azyl->setMessageAddress($this->azylAddressService->generateCommunicationAddress($azyl->getId(), $azyl->getEmail(), $azyl->getAzylName()));
            $this->flashMessage('POZOR! Nastavena komunikační adresa interního systému.', 'alert-warning');
        }

        $rawSlug = trim($values->slug ?? '');
        if ($rawSlug === '') {
            // Auto-generate from name only when no slug is set yet
            if ($azyl->getSlug() === null) {
                $azyl->setSlug($this->slugService->generateUniqueSlug($values->azylName, $azyl->getId()));
            }
        } else {
            $newSlug = $this->slugService->slugify($rawSlug);
            $existing = $this->azylRepository->findBySlug($newSlug);
            if ($existing !== null && $existing->getId() !== $azyl->getId()) {
                $this->flashMessage('Slug "' . $newSlug . '" je již obsazen jiným azylem. Slug nebyl změněn.', 'alert-warning');
            } else {
                $azyl->setSlug($newSlug);
            }
        }

        $this->azylRepository->saveAzyl($azyl);
        $this->flashMessage('Nastavení azylu bylo aktualizováno.', 'alert-success');
        $this->redirect('this');
    }

    /**
     * @throws NonUniqueResultException
     */
    #[NoReturn] public function animalFormSucceeded(Form $form, $values): void
    {

        $id = $this->getParameter('id');
        //todo: ověření práv uživatele na úpravu zvířátka
        if (is_null($id)) {

            $animal = new Animal();
            $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $city = $this->cityRepository->findCityById(intval($azyl->getCity()));
            $cityName = $city->getCityName();
            $region = $city->getRegion();
            $office = $city->getCityOffice();

            $animal->setAzyl($azyl);
            $animal->setIsDeleted(false);
            $animal->setAdopted(false);
            $animal->setToAdoption($values->toAdoption);
            $animal->setAdoptionType($values->adoptionType);
            $animal->setName($values->name);
            $animal->setDescription($values->description);
            $animal->setSpecies($this->speciesRepository->findOneById($values->species));
            $animal->setBirthDate($values->birthDate);
            $animal->setBreed($values->breed);
            $animal->setHowMuch($values->howMuch);
            $animal->setSigned($values->signed);
            $animal->setHeight($values->height);
            $animal->setWeight($values->weight);
            $animal->setMultiAdoption($values->multiAdoption);
            $animal->setReception($values->reception);
            $tags = $cityName . ' ' . $region . ' ' . $office . ' ' . $values->name . ' ' . $values->breed . ' ' . $this->speciesRepository->findOneById($values->species)->getName() . ' ' . $this->speciesRepository->findOneById($values->species)->getTags();
            $animal->setTags($tags);
            $this->animalsRepository->persist($animal);
            foreach ($values->photos as $photo) {

                $photoUpload = new Photo();
                $photoUpload->setAzyl($azyl);
                $photoUpload->setDate(new DateTimeImmutable('now'));
                $photoUpload->setAnimal($animal);
                $photoUpload->uploadAzylPhoto($photo);
                $this->photosRepository->save($photoUpload);
            }
            $this->animalsRepository->flush($animal);
            $this->flashMessage('Zvířátko bylo úspěšně přidáno.', 'alert-success');
            $this->redirect('Azyl:animals');
        } else {

            $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $city = $this->cityRepository->findCityById(intval($azyl->getCity()));
            $cityName = $city->getCityName();
            $region = $city->getRegion();
            $office = $city->getCityOffice();

            $animal = $this->animalsRepository->findById(intval($id));
            $animal->setName($values->name);
            $animal->setDescription($values->description);
            $animal->setSpecies($this->speciesRepository->findOneById(intval($values->species)));
            $animal->setBirthDate($values->birthDate);
            $animal->setBreed($values->breed);
            $animal->setToAdoption($values->toAdoption);
            $animal->setAdoptionType($values->adoptionType);
            $animal->setHowMuch($values->howMuch);
            $animal->setMultiAdoption($values->multiAdoption);
            $animal->setSigned($values->signed);
            $animal->setHeight($values->height);
            $animal->setWeight($values->weight);
            $animal->setReception($values->reception);

            $tags = $cityName . ' ' . $region . ' ' . $office . ' ' . $values->name . ' ' . $values->breed . ' ' . $this->speciesRepository->findOneById($values->species)->getName() . ' ' . $this->speciesRepository->findOneById($values->species)->getTags();
            $animal->setTags($tags);

            foreach ($values->photos as $photo) {
                $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
                $photoUpload = new Photo();
                $photoUpload->setAnimal($animal);
                $photoUpload->setDate(new DateTimeImmutable('now'));
                $photoUpload->setAzyl($azyl);
                $photoUpload->uploadAzylPhoto($photo);
                $this->photosRepository->save($photoUpload);
            }

            $this->animalsRepository->saveAnimal($animal);
            $this->flashMessage('Zvířátko bylo úspěšně upraveno.', 'alert-success');
        }
        $this->redirect('this');
    }

    public function createComponentNewsForm(): Form
    {
        $form = $this->newsFormFactory->create();
        $pined = $form->getComponent('pined');
        $form->removeComponent($pined);

        if ($this->getPresenter()->getParameter('id') !== null) {
            $news = $this->newsRepository->findOneBy(['id' => $this->getPresenter()->getParameter('id')]);
            if ($news) {
                $form->addHidden('newsid', $news->getId());
                $form->setDefaults(
                    [
                        'title' => $news->getTitle(),
                        'content' => $news->getContent(),
                        'global' => $news->getGlobal(),
                        'visibleFrom' => $news->getVisibleFrom(),
                        'important' => $news->getImportant(),
                    ]);
                $form->onSuccess[] = [$this, 'newsFormSucceededUpdate'];
                return $form;

            } else {
                $this->flashMessage('Novinka nebyla nalezena.', 'alert-danger');
                $this->redirect('Azyl:news');
            }
        } else {
            $form->onSuccess[] = [$this, 'newsFormSucceededNew'];
            return $form;
        }

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
                $news->setPined(false);
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
            $news->setPined(false);
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla uložena.', 'alert-success');
            $this->redirect('Admin:news');
        }
    }

    public function createComponentMessagesForm(): Form
    {
        $form = $this->messagesFormFactory->create();
        $form->onSuccess[] = [$this, 'messagesFormSucceeded'];
        return $form;
    }


    //TODO: tohle by se mělo překopat a zpřehlednit je tu bordel!!!!
    #[NoReturn] public function newsFormSucceededUpdate(Form $form, \stdClass $values): void
    {

        $news = $this->newsRepository->findOneBy(['id' => $this->getPresenter()->getParameter('id')]);
        if ($news) {

            $news->setTitle($values->title);
            $news->setContent($values->content);
            $news->setGlobal($values->global);
            $news->setVisibleFrom($values->visibleFrom);
            $news->setUpdatedAt(new DateTimeImmutable());
            $news->setDeleted(false);
            $news->setImportant($values->important);
            $news->setPined(false);
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla aktualizována.', 'alert-success');
            $this->redirect('this');
        } else
        {
            $this->flashMessage('Novinka nebyla aktualizována.', 'alert-warning');
            $this->redirect('this');
        }
    }

    #[NoReturn] public function newsFormSucceededNew(Form $form, \stdClass $values): void
    {

            $news = new News();
            $author = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getIdentity()->getId());
            $azyl = $this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId());
            $news->setAuthor($author);
            $news->setTitle($values->title);
            $news->setContent($values->content);
            $news->setGlobal($values->global);
            $news->setVisibleFrom($values->visibleFrom);
            $news->setImportant($values->important);
            $news->setCreatedAt(new DateTimeImmutable());
            $news->setDeleted(false);
            $news->setPined(false);
            $news->setAzyl($azyl);
            $this->newsRepository->save($news);
            $this->flashMessage('Novinka byla uložena.', 'alert-success');
            $this->redirect('this');

    }

    //todo: Tady je BUG z nějakého důvodu se zobrazují i smazané novinky !!!! URGENT!!!!
    public function newsFormSucceededUpdate2(Form $form, \stdClass $values): void
    {
        $id = $this->getPresenter()->getParameter('id');
        if ($id !== null) {
            $news = $this->newsRepository->findOneBy(['id' => $id]);

            if ($news) {
                $azyl = $this->azylRepository->findOneBy(['id' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId()]);

                $news->setTitle($values->title);
                $news->setContent($values->content);
                $news->setGlobal($values->global);
                $news->setVisibleFrom($values->visibleFrom);
                $news->setUpdatedAt(new DateTimeImmutable());
                $news->setImportant($values->important);
                $news->setPined(false);
                $news->setAzyl($azyl);

                $this->newsRepository->save($news);

                $this->flashMessage('Novinka byla aktualizována.', 'alert-success');
                $this->redirect('Azyl:news');
            }
        }
    }


    /**
     * @throws DataGridColumnStatusException
     * @throws DataGridException
     */
    public function createComponentNewsDatagrid(): DataGrid
    {
        $grid = new NewsDatagridFactory($this->newsRepository);
        $grid->setPresenter($this->getPresenter());
        $grid->create(); // upraví instanci, nepřepíše ji novým objektem


        $grid->removeColumn('pined');
        $azyl = $this->azylRepository->findOneBy(['id' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId()]);
        $news = $this->newsRepository->findBy(['azyl' => $azyl, 'deleted' => false], ['id' => 'DESC']);
        $grid->setDataSource($news);
        return $grid;
    }

    public function createComponentAnimalsAzylDatagrid(): DataGrid

    {
        $grid = new AnimalsDatagridFactory($this->animalsRepository);
        $grid->setPresenter($this->getPresenter());

        $grid->create(); // upraví instanci, nepřepíše ji novým objektem
        $grid->setDataSource($this->animalsRepository->findBy(['azyl' => $this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']]));
        return $grid;

    }

    public function createComponentAzylPhotoUploadForm(): Form
    {
        $factory = new PhotoUploadFormFactory();
        $form = $factory->create();
        $form-> onSuccess[] = [$this, 'photoUploadFormSucceeded'];
        return $form;
    }

    public function photoUploadFormSucceeded(Form $form, \stdClass $values): void
    {
        foreach ($values->photos as $photoFile) {

            $photo = new Photo();
            $photo->setAzyl($this->azylRepository->findById($this->getPresenter()->getUser()->getIdentity()->getData()['Azyl']->getId()));
            $photo->setUser($this->usersRepository->findOneBy(['id' => $this->getPresenter()->getUser()->getId()]));
            $photo->setDate(new DateTimeImmutable());
            $photo->uploadAzylPhoto($photoFile);
            $this->photosRepository->save($photo);
            $this->flashMessage('Fotka <b>'.$photo->getOriginalName().'</b> se nahrála úspěšně', 'alert-success');

        }

        if ($this->isAjax())
        {

            $this->redrawControl('photos');
        }
        else
        {
            $this->redirect('Azyl:photos');
        }

    }

    public function createComponentUserDetailsForm(): Form
    {
        $factory = $this->userDetailsFormFactory;
        $factory->setLink($this->link('Json:select2'));
        $form = $factory->create($this->getPresenter());
        $user = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getId());
        $city = $this->cityRepository->findOneBy(['id'=>$user->getCity()]);
        if (!is_null($user->getCity())) {
            $form['city']->setItems([$city->getId() => $city->getCityName()], true);
        }



        $form->setDefaults(['firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'phone' => $user->getPhone(),
            'street' => $user->getStreet(),
            'city' => is_null($user->getCity()) ? null : $city->getId(),
            'orientation' => $user->getOrientationNumber(),
            'house' => $user->getHouseNumber(),
            'description' => $user->getDescription()
        ]);

        $form['send']->setHtmlAttribute('class', 'btn btn-primary');
        $form['send']->setCaption('Uložit změny');

        $form->onSuccess[] = [$this, 'userDetailsFormSucceeded'];
        return $form;
    }

    /**
     * @throws NonUniqueResultException
     * @throws NumberParseException
     * @throws ConversionException
     * @throws PhoneNumberParseException
     */
    public function userDetailsFormSucceeded(Form $form, \stdClass $values) : void
    {

        $post = $this->getPresenter()->getHttpRequest()->getPost();
        $user = $this->usersRepository->getUserById($this->getUser()->getId());

        if (!is_null($user))
        {
            $rawPhone = $post['phone'] ?? '';
            $phone = null;
            if (!empty($rawPhone)) {
                try {
                    $parsed = \Brick\PhoneNumber\PhoneNumber::parse($rawPhone);
                    $phone = $parsed->format(\Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);
                } catch (\Brick\PhoneNumber\PhoneNumberParseException) {
                    $phone = $rawPhone;
                }
            }
            $user->setFirstName($post['firstName'] ?? '');
            $user->setLastName($post['lastName'] ?? '');
            $user->setUpdatedAt(new DateTimeImmutable());
            $user->setUpdatedBy($this->usersRepository->getUserById($this->getPresenter()->getUser()->getId()));
            $user->setPhone(phone: $phone);
            $user->setOrientationNumber($post['orientation'] ?? null);
            $user->setStreet($values->street);
            $user->setDescription($values->description);
            $user->setHouseNumber($post['house'] ?? null);
            $rawCity = $post['city'] ?? '';
            $user->setCity($rawCity !== '' ? intval($rawCity) : null);
            // $user->setCity($this->cityRepository->findCityById(intval($post['city'])));
            $this->usersRepository->save($user);
            $this->flashMessage('Uživatelské informace aktualizovány.', 'alert-success');

        }
        if($this->isAjax()){
            $this->redrawControl('citySelect');
        }
        else
        {
            $this->redirect('this');
        }

    }


    public function createComponentUserUpdateForm(string $name): Form
    {
        $form = $this->registerFormFactory->create();
        $user = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getId());

        $form->addUpload('personalPhoto','Profilová fotka');
        $pass = $form->getComponent('password');
        $pass->setRequired(false);
        $pass2 = $form->getComponent('password2');
        $pass2->setRequired(false);
        $form->removeComponent($form->getComponent('phone'));
        $form->removeComponent($form->getComponent('legalTerms'));
        $form->removeComponent($form->getComponent('send'));
        $form->removeComponent($form->getComponent('adoptionVerification'));
        $form->removeComponent($form->getComponent('email'));
        $form->addEmail('email', 'Email')
            ->addRule(Nette\Forms\Form::Email, 'Zadejte platný email.')
            ->addRule(function ($input) {
                $existingUser = $this->usersRepository->findOneBy(['email' => $input->value]);
                return !$existingUser || $existingUser->getId() === $this->getPresenter()->getUser()->getId();
            }, 'Tento email je již registrován.');
        $form->setDefaults($user->toArray());
        $form->addSubmit('update','Aktualizovat');
        $form->onSuccess[] = [$this, 'userUpdateFormSucceeded'];
        return $form;
    }


    #[NoReturn] public function userUpdateFormSucceeded(Form $form, \stdClass $values) : void
    {
        $user = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getId());
        $user->setUpdatedAt(new DateTimeImmutable('now'));
        $user->setUpdatedBy($user);

        if($user->getEmail() !== $values->email) {
            $sendUserEmail = $this->usersRepository->findOneBy(['email' => $values->email]);

            if ($this->getPresenter()->getUser()->getId() == $sendUserEmail->getId())
            {

                $this->flashMessage('Email už je v systému nebyl aktualizován!', 'alert-success');
            }
            else
            {
                $user->setEmail($values->email);
                $this->flashMessage('Email byl aktualizován. <b>POZOR!</b> email se používá pro přihlašovíní!! Email byl nastaven na: '.$values->email.'.', 'alert-success');
            }
        }

        if (!empty($values->password))
        {
            if($values->password == $values->password2)
            {
                $user->setPassword($this->passwords->hash($values->password));
                $this->flashMessage('POZOR! Heslo bylo aktualizováno!', 'alert-success');
            }
            else
            {
                $this->flashMessage('POZOR! Problém při aktualizaci hesla!', 'alert-danger');
            }
        }
        if ($values->personalPhoto->hasFile())
        {
            $photo = new Photo();
            $photo->setUser($user);
            $photo->setDate(new DateTimeImmutable('now'));
            $photo->uploadUserPersonalPhoto($values->personalPhoto);
            $this->photosRepository->save($photo);
            $user->setPersonalPhoto($photo->getId());
            $this->flashMessage('Osobní fotka nastavena!', 'alert-success');
        }
        $this->usersRepository->save($user);
        $this->flashMessage('Nastavení uživatele aktualizováno', 'alert-success');

        $this->redirect('this');
    }

    public function createComponentOwnerPhotoUploadForm(): Form
    {
        $form = $this->photoUploadFormFactory->create();
        $form['photos']->setHtmlAttribute('class', 'btn btn-outline-success form-control');
        $form['send']->setHtmlAttribute('class','btn btn-outline-success form-control');

        $form->onSuccess[] = [$this, 'ownerPhotoUploadFormSucceeded'];
        return $form;
    }


    public function ownerPhotoUploadFormSucceeded(Form $form, \stdClass $values): void
    {
        $user = $this->usersRepository->getUserById($this->getPresenter()->getUser()->getId());
        foreach ($values->photos as $photo)
        {

            $photoUpload = New Photo();
            $photoUpload->setUser($user);
            $photoUpload->setDate(new DateTimeImmutable('now'));
            $photoUpload->uploadUserPhoto($photo);
            $this->photosRepository->save($photoUpload);
        }
        $this->usersRepository->addUser($user);
        $this->getPresenter()->flashMessage('Fotky byly úspěšně nahrány!', 'alert-success');
        $this->getPresenter()->redrawControl('photos');
    }

    // ==================== Eshop ====================

    private function getAzyl(): \App\Model\Orm\Entity\Azyl
    {
        return $this->azylRepository->findById(
            $this->getUser()->getIdentity()->getData()['Azyl']->getId()
        );
    }

    public function renderShop(): void
    {
        $azyl = $this->getAzyl();
        $products = $this->shopProductRepository->findByAzyl($azyl);
        $orders = $this->shopOrderRepository->findByAzyl($azyl);

        $activeProducts = count(array_filter($products, fn($p) => $p->isActive() && $p->isApproved()));
        $pendingOrders = count(array_filter($orders, fn($o) => $o->getOrderStatus() === ShopOrderStatusEnum::Paid));
        $totalEarnings = (float)array_sum(array_map(
            fn($o) => $o->getPayoutAmount(),
            array_filter($orders, fn($o) => $o->getOrderStatus() === ShopOrderStatusEnum::Delivered)
        ));
        $pendingPayout = (float)array_sum(array_map(
            fn($o) => $o->getPayoutAmount(),
            array_filter($orders, fn($o) => in_array($o->getOrderStatus(), [ShopOrderStatusEnum::Paid, ShopOrderStatusEnum::Shipped]))
        ));

        $this->getTemplate()->eshopEnabled = (int)$this->systemSettings->get('shop.enabled', 1) === 1;
        $this->getTemplate()->stats = [
            'activeProducts' => $activeProducts,
            'pendingOrders'  => $pendingOrders,
            'totalEarnings'  => $totalEarnings,
            'pendingPayout'  => $pendingPayout,
        ];
        $this->getTemplate()->products = $products;
    }

    public function renderShopOrders(?string $status = null): void
    {
        $azyl = $this->getAzyl();
        $statusEnum = $status ? ShopOrderStatusEnum::tryFrom($status) : null;
        $this->getTemplate()->orders = $this->shopOrderRepository->findByAzyl($azyl, $statusEnum);
        $this->getTemplate()->statusFilter = $status;
    }

    public function renderShopOrder(int $id): void
    {
        $order = $this->shopOrderRepository->find($id);
        if (!$order || $order->getAzyl()->getId() !== $this->getAzyl()->getId()) {
            $this->error('Objednávka nenalezena', 404);
        }
        $this->getTemplate()->order = $order;
    }

    public function renderFinance(): void
    {
        $azyl = $this->getAzyl();

        $shopStats = $this->shopOrderRepository->getStatsByAzyl($azyl);
        $collectionStats = $this->paymentsRepository->getCollectionStatsByAzyl($azyl);
        $adoptionStats = $this->paymentsRepository->getAdoptionStatsByAzyl($azyl);

        $this->getTemplate()->shopStats = $shopStats;
        $this->getTemplate()->collectionStats = $collectionStats;
        $this->getTemplate()->adoptionStats = $adoptionStats;
        $this->getTemplate()->totalPayout =
            $shopStats['deliveredPayout'] +
            $collectionStats['totalPayout'] +
            $adoptionStats['totalPayout'];
        $this->getTemplate()->totalFee =
            $shopStats['totalFee'] +
            $collectionStats['totalFee'] +
            $adoptionStats['totalFee'];
        $this->getTemplate()->recentOrders = $this->shopOrderRepository->findByAzyl($azyl, null, 10);
    }

    public function actionShopProduct(?int $id = null): void
    {
        $azyl = $this->getAzyl();
        $feePercent = (float)$this->systemSettings->get('shop.fee_percent', 5.0);
        $categories = array_unique(array_filter(array_map(
            fn($p) => $p->getCategory(),
            $this->shopProductRepository->findByAzyl($azyl)
        )));

        $this->getTemplate()->feePercent = $feePercent;
        $this->getTemplate()->existingCategories = array_values($categories);
        $this->getTemplate()->maxPhotos = 10;
        $this->getTemplate()->productForm = $this['productForm'];

        if ($id !== null) {
            $product = $this->shopProductRepository->find($id);
            if (!$product || $product->getAzyl()->getId() !== $azyl->getId()) {
                $this->error('Produkt nenalezen', 404);
            }
            $this->getTemplate()->product = $product;
            $this->getTemplate()->currentPhotoCount = $product->getPhotos()->count();
            $this['productForm']->setDefaults([
                'name'             => $product->getName(),
                'sku'              => $product->getSku(),
                'shortDescription' => $product->getShortDescription(),
                'description'      => $product->getDescription(),
                'price'            => $product->getPrice(),
                'stock'            => $product->getStock(),
                'weightGrams'      => $product->getWeightGrams(),
                'category'         => $product->getCategory(),
                'unlimitedStock'   => $product->isUnlimitedStock(),
                'isActive'         => $product->isActive(),
            ]);
        } else {
            $this->getTemplate()->product = null;
            $this->getTemplate()->currentPhotoCount = 0;
        }
    }

    public function handleShopProductToggle(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if ($product && $product->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $product->setIsActive(!$product->isActive());
            $this->shopProductRepository->save($product);
            $this->flashMessage($product->isActive() ? 'Produkt zapnut.' : 'Produkt vypnut.', 'alert-success');
        }
        $this->redirect('Azyl:shop');
    }

    public function handleShopProductDelete(int $id): void
    {
        $product = $this->shopProductRepository->find($id);
        if ($product && $product->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $product->setIsActive(false);
            $this->shopProductRepository->save($product);
            $this->flashMessage('Produkt byl skryt z eshopu.', 'alert-success');
        }
        $this->redirect('Azyl:shop');
    }

    public function handleShopPhotoDelete(int $id): void
    {
        $photo = $this->entityManager->find(ShopProductPhoto::class, $id);
        if ($photo && $photo->getProduct()->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $productId = $photo->getProduct()->getId();
            $this->entityManager->remove($photo);
            $this->entityManager->flush();
            $this->flashMessage('Fotka smazána.', 'alert-success');
            $this->redirect('Azyl:shopProduct', $productId);
        }
        $this->redirect('Azyl:shop');
    }

    public function handleShopPhotoSetMain(int $id): void
    {
        $photo = $this->entityManager->find(ShopProductPhoto::class, $id);
        if ($photo && $photo->getProduct()->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $product = $photo->getProduct();
            $product->setMainPhoto($photo->getId());
            $this->shopProductRepository->save($product);
            $this->flashMessage('Hlavní fotka nastavena.', 'alert-success');
            $this->redirect('Azyl:shopProduct', $product->getId());
        }
        $this->redirect('Azyl:shop');
    }

    public function handleShopOrderCancel(int $id): void
    {
        $order = $this->shopOrderRepository->find($id);
        if ($order && $order->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $this->shopService->cancelOrder($order);
            $this->flashMessage('Objednávka byla stornována.', 'alert-warning');
        }
        $this->redirect('Azyl:shopOrders');
    }

    public function handleShopOrderDeliver(int $id): void
    {
        $order = $this->shopOrderRepository->find($id);
        if ($order && $order->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $this->shopService->markOrderDelivered($order);
            $this->flashMessage('Objednávka označena jako doručená.', 'alert-success');
        }
        $this->redirect('Azyl:shopOrders');
    }

    public function createComponentProductForm(): Form
    {
        $form = $this->shopProductFormFactory->create();
        $form->onSuccess[] = [$this, 'productFormSucceeded'];
        return $form;
    }

    public function productFormSucceeded(Form $form, \stdClass $values): void
    {
        $azyl = $this->getAzyl();
        $id = $this->getParameter('id');

        if ($id) {
            $product = $this->shopProductRepository->find((int)$id);
            if (!$product || $product->getAzyl()->getId() !== $azyl->getId()) {
                $this->error('Produkt nenalezen', 404);
            }
            $product->unapprove();
        } else {
            $product = new ShopProduct();
            $product->setAzyl($azyl);
        }

        $product->setName($values->name)
            ->setSku($values->sku ?: null)
            ->setShortDescription($values->shortDescription ?: null)
            ->setDescription($values->description ?: null)
            ->setPrice((float)$values->price)
            ->setStock((int)($values->stock ?? 0))
            ->setWeightGrams($values->weightGrams ? (int)$values->weightGrams : null)
            ->setCategory($values->category ?: null)
            ->setUnlimitedStock((bool)$values->unlimitedStock)
            ->setIsActive((bool)$values->isActive)
            ->touchUpdatedAt();

        $this->shopProductRepository->save($product);
        $this->flashMessage($id ? 'Produkt aktualizován (čeká znovu na schválení).' : 'Produkt přidán, čeká na schválení.', 'alert-success');
        $this->redirect('Azyl:shopProduct', $product->getId());
    }

    public function createComponentPhotoUploadForm(): Form
    {
        $form = new Form;
        $form->addUpload('photo', 'Fotka')
            ->setRequired('Vyberte soubor')
            ->addRule(Form::MaxFileSize, 'Max 5 MB', 5 * 1024 * 1024)
            ->addRule(function (\Nette\Forms\Controls\UploadControl $control) {
                $upload = $control->getValue();
                if (!$upload->isOk()) return false;
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $mime = $upload->getContentType();
                if (in_array($mime, $allowed, true)) return true;
                $ext = strtolower(pathinfo($upload->getSanitizedName(), PATHINFO_EXTENSION));
                return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
            }, 'Povoleny jsou obrázky JPG, PNG nebo WEBP')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('accept', 'image/jpeg,image/png,image/webp');
        $form->addSubmit('upload', 'Nahrát')
            ->setHtmlAttribute('class', 'btn btn-primary w-100');
        $form->onSuccess[] = [$this, 'shopPhotoUploadFormSucceeded'];
        return $form;
    }

    public function shopPhotoUploadFormSucceeded(Form $form, \stdClass $values): void
    {
        $id = (int)$this->getParameter('id');
        $product = $this->shopProductRepository->find($id);
        if (!$product || $product->getAzyl()->getId() !== $this->getAzyl()->getId()) {
            $this->error('Produkt nenalezen', 404);
        }
        try {
            $photo = new ShopProductPhoto();
            $photo->setProduct($product)
                  ->setSortOrder($product->getPhotos()->count())
                  ->uploadProductPhoto($values->photo);
            $this->entityManager->persist($photo);
            $this->entityManager->flush();
            $this->flashMessage('Fotka nahrána.', 'alert-success');
        } catch (\RuntimeException $e) {
            $this->flashMessage($e->getMessage(), 'alert-danger');
        }
        $this->redirect('Azyl:shopProduct', $id);
    }

    public function createComponentShipForm(): Form
    {
        $form = new Form;
        $form->addText('tracking', 'Sledovací číslo')
            ->setHtmlAttribute('class', 'form-control mb-2');
        $form->addSubmit('ship', 'Označit jako odesláno')
            ->setHtmlAttribute('class', 'btn btn-primary w-100');
        $form->onSuccess[] = [$this, 'shipFormSucceeded'];
        return $form;
    }

    public function shipFormSucceeded(Form $form, \stdClass $values): void
    {
        $id = (int)$this->getParameter('id');
        $order = $this->shopOrderRepository->find($id);
        if ($order && $order->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $this->shopService->markOrderShipped($order, $values->tracking ?: null);
            $this->flashMessage('Objednávka označena jako odeslaná.', 'alert-success');
        }
        $this->redirect('Azyl:shopOrders');
    }

    // ==================== Události (Events) ====================

    public function renderEvents(): void
    {
        $azyl = $this->getAzyl();
        $this->getTemplate()->events = $this->azylEventRepository->findByAzyl($azyl);
    }

    public function actionEvent(?int $id = null): void
    {
        $azyl = $this->getAzyl();
        $event = null;

        if ($id !== null) {
            $event = $this->azylEventRepository->find($id);
            if (!$event || $event->getAzyl()->getId() !== $azyl->getId()) {
                $this->error('Událost nenalezena', 404);
            }
        }

        $this->getTemplate()->event = $event;

        $headerPhoto = null;
        if ($event && $event->getHeaderPhotoId()) {
            $headerPhoto = $this->photosRepository->find($event->getHeaderPhotoId());
        }
        $this->getTemplate()->headerPhoto = $headerPhoto;

        $reservations = $event ? $this->azylEventReservationRepository->findByEvent($event) : [];
        $waitlist     = $event ? $this->azylEventReservationRepository->findWaitlistByEvent($event) : [];
        $this->getTemplate()->reservations = $reservations;
        $this->getTemplate()->waitlist     = $waitlist;
    }

    public function createComponentEventForm(): Form
    {
        $form = $this->azylEventFormFactory->create();
        $form->onSuccess[] = [$this, 'eventFormSucceeded'];

        $id = $this->getParameter('id');
        if ($id !== null) {
            $event = $this->azylEventRepository->find((int)$id);
            if ($event) {
                $form->setDefaults([
                    'id'                => $event->getId(),
                    'title'             => $event->getTitle(),
                    'shortDescription'  => $event->getShortDescription(),
                    'description'       => $event->getDescription(),
                    'location'          => $event->getLocation(),
                    'dateFrom'          => $event->getDateFrom()->format('Y-m-d\TH:i'),
                    'dateTo'            => $event->getDateTo()->format('Y-m-d\TH:i'),
                    'recurrenceType'    => $event->getRecurrenceType()->value,
                    'recurrenceEndDate' => $event->getRecurrenceEndDate()?->format('Y-m-d'),
                    'maxParticipants'       => $event->getMaxParticipants(),
                    'registrationEnabled'   => $event->isRegistrationEnabled(),
                    'isPublished'           => $event->isPublished(),
                ]);
            }
        }

        return $form;
    }

    public function eventFormSucceeded(Form $form, \stdClass $values): void
    {
        $azyl = $this->getAzyl();
        $id = (int)$values->id ?: null;

        if ($id) {
            $event = $this->azylEventRepository->find($id);
            if (!$event || $event->getAzyl()->getId() !== $azyl->getId()) {
                $this->error('Událost nenalezena', 404);
            }
        } else {
            $event = new AzylEvent();
            $event->setAzyl($azyl);
        }

        $event->setTitle($values->title);
        $event->setShortDescription($values->shortDescription ?: null);
        $event->setDescription($values->description ?: null);
        $event->setLocation($values->location ?: null);
        $event->setDateFrom(new DateTimeImmutable($values->dateFrom));
        $event->setDateTo(new DateTimeImmutable($values->dateTo));
        $event->setRecurrenceType(RecurrenceTypeEnum::from($values->recurrenceType));
        $event->setRecurrenceEndDate(
            ($values->recurrenceEndDate && $values->recurrenceEndDate !== '')
                ? new DateTimeImmutable($values->recurrenceEndDate)
                : null
        );
        $event->setMaxParticipants(
            ($values->maxParticipants !== '' && $values->maxParticipants !== null)
                ? (int)$values->maxParticipants
                : null
        );
        $event->setRegistrationEnabled((bool)$values->registrationEnabled);
        $event->setIsPublished((bool)$values->isPublished);
        $event->setUpdatedAt(new DateTimeImmutable());

        $this->azylEventRepository->save($event);
        $this->flashMessage('Událost byla uložena.', 'alert-success');
        $this->redirect('Azyl:event', ['id' => $event->getId()]);
    }

    public function handleTogglePublishEvent(int $id): void
    {
        $event = $this->azylEventRepository->find($id);
        if ($event && $event->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $event->setIsPublished(!$event->isPublished());
            $this->azylEventRepository->save($event);
            $this->flashMessage(
                $event->isPublished() ? 'Událost zveřejněna.' : 'Událost skryta.',
                'alert-success'
            );
        }
        $this->redirect('Azyl:events');
    }

    public function handleDeleteEvent(int $id): void
    {
        $event = $this->azylEventRepository->find($id);
        if ($event && $event->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $event->setIsDeleted(true);
            $this->azylEventRepository->save($event);
            $this->flashMessage('Událost byla smazána.', 'alert-success');
        }
        $this->redirect('Azyl:events');
    }

    public function handleCancelReservation(int $reservationId): void
    {
        $reservation = $this->azylEventReservationRepository->find($reservationId);
        if ($reservation && $reservation->getEvent()->getAzyl()->getId() === $this->getAzyl()->getId()) {
            $event = $reservation->getEvent();
            $reservation->setStatus('cancelled');
            $this->azylEventReservationRepository->save($reservation);

            // Promote first waitlist entry if event now has capacity
            if ($reservation->isConfirmed() && $event->hasCapacity()) {
                $next = $this->azylEventReservationRepository->findFirstWaitlist($event);
                if ($next) {
                    $next->setStatus('confirmed');
                    $this->azylEventReservationRepository->save($next);
                    $cancelUrl = $this->link('//Home:cancelRegistration', ['token' => $next->getToken()]);
                    try {
                        $this->eventRegistrationMailService->sendPromoted($next, $cancelUrl);
                    } catch (\Throwable) {
                        // email není kritický
                    }
                }
            }

            $this->flashMessage('Rezervace zrušena.', 'alert-warning');
        }
        $this->redirect('this');
    }

    public function createComponentEventMessageForm(): Form
    {
        $form = new Form;
        $form->addText('subject', 'Předmět')
            ->setRequired('Zadejte předmět')
            ->setHtmlAttribute('class', 'form-control')
            ->setMaxLength(150);
        $form->addTextArea('body', 'Zpráva')
            ->setRequired('Napište zprávu')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows', '5');
        $form->addHidden('eventId');
        $form->addSubmit('send', 'Odeslat všem registrovaným')
            ->setHtmlAttribute('class', 'btn btn-primary');
        $form->onSuccess[] = [$this, 'eventMessageFormSucceeded'];
        return $form;
    }

    public function eventMessageFormSucceeded(Form $form, \stdClass $values): void
    {
        $event = $this->azylEventRepository->find((int)$values->eventId);
        if (!$event || $event->getAzyl()->getId() !== $this->getAzyl()->getId()) {
            $this->error('Událost nenalezena', 404);
        }
        $confirmed = $this->azylEventReservationRepository->findByEvent($event);
        try {
            $this->eventRegistrationMailService->sendOrganizerMessage(
                $event, $confirmed, $values->subject, $values->body
            );
            $this->flashMessage('Zpráva odeslána ' . count($confirmed) . ' registrovaným.', 'alert-success');
        } catch (\Throwable $e) {
            $this->flashMessage('Chyba při odesílání: ' . $e->getMessage(), 'alert-danger');
        }
        $this->redirect('this');
    }

    public function createComponentHeaderPhotoForm(): Form
    {
        $form = new Form;
        $form->addUpload('headerPhoto', 'Hlavní foto')
            ->setRequired('Vyberte soubor')
            ->addRule(Form::MaxFileSize, 'Max 5 MB', 5 * 1024 * 1024)
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('accept', 'image/jpeg,image/png,image/webp');
        $form->addSubmit('uploadHeader', 'Nahrát hlavní foto')
            ->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary w-100');
        $form->onSuccess[] = [$this, 'headerPhotoFormSucceeded'];
        return $form;
    }

    public function headerPhotoFormSucceeded(Form $form, \stdClass $values): void
    {
        $id = (int)$this->getParameter('id');
        $event = $this->azylEventRepository->find($id);
        if (!$event || $event->getAzyl()->getId() !== $this->getAzyl()->getId()) {
            $this->error('Událost nenalezena', 404);
        }

        $photo = new Photo();
        $photo->setAzyl($this->getAzyl());
        $photo->setAzylEvent($event);
        $photo->setDate(new DateTimeImmutable());
        $photo->uploadAzylPhoto($values->headerPhoto);
        $this->photosRepository->save($photo);

        $event->setHeaderPhotoId($photo->getId());
        $this->azylEventRepository->save($event);

        $this->flashMessage('Hlavní foto nahráno.', 'alert-success');
        $this->redirect('this');
    }

    public function createComponentEventPhotoForm(): Form
    {
        $form = new Form;
        $form->addMultiUpload('photos', 'Fotky')
            ->setRequired('Vyberte alespoň jednu fotku')
            ->addRule(Form::MaxFileSize, 'Max 5 MB na soubor', 5 * 1024 * 1024)
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('accept', 'image/jpeg,image/png,image/webp');
        $form->addSubmit('uploadPhotos', 'Nahrát fotky')
            ->setHtmlAttribute('class', 'btn btn-sm btn-outline-success w-100');
        $form->onSuccess[] = [$this, 'eventPhotoFormSucceeded'];
        return $form;
    }

    public function eventPhotoFormSucceeded(Form $form, \stdClass $values): void
    {
        $id = (int)$this->getParameter('id');
        $event = $this->azylEventRepository->find($id);
        if (!$event || $event->getAzyl()->getId() !== $this->getAzyl()->getId()) {
            $this->error('Událost nenalezena', 404);
        }

        $azyl = $this->getAzyl();
        $count = 0;
        foreach ($values->photos as $upload) {
            if (!$upload->isOk()) {
                continue;
            }
            $photo = new Photo();
            $photo->setAzyl($azyl);
            $photo->setAzylEvent($event);
            $photo->setDate(new DateTimeImmutable());
            $photo->uploadAzylPhoto($upload);
            $this->photosRepository->save($photo);
            $count++;
        }

        // Pokud je událost proběhlá, auto-vytvoříme aktualitu
        if ($count > 0 && $event->isPast()) {
            $author = $this->usersRepository->getUserById($this->getUser()->getId());
            $news = new News();
            $news->setAuthor($author);
            $news->setAzyl($azyl);
            $news->setTitle('Fotky z události: ' . $event->getTitle());
            $news->setContent(
                '<p>Prohlédněte si fotky z naší události <strong>' . htmlspecialchars($event->getTitle()) . '</strong>.</p>'
                . ($event->getShortDescription() ? '<p>' . htmlspecialchars($event->getShortDescription()) . '</p>' : '')
            );
            $news->setGlobal(false);
            $news->setImportant(false);
            $news->setPined(false);
            $news->setDeleted(false);
            $news->setCreatedAt(new DateTimeImmutable());
            $news->setVisibleFrom(new DateTimeImmutable());
            $this->newsRepository->save($news);
            $this->flashMessage("Nahráno {$count} fotek — automaticky vytvořena aktualita.", 'alert-success');
        } else {
            $this->flashMessage("Nahráno {$count} fotek.", 'alert-success');
        }

        $this->redirect('this');
    }

    public function handleDeleteEventPhoto(int $photoId): void
    {
        $azyl = $this->getAzyl();
        $photo = $this->photosRepository->findOneBy(['id' => $photoId, 'azyl' => $azyl]);
        if ($photo) {
            $photo->setDeleted(true);
            $this->photosRepository->save($photo);
            $this->flashMessage('Fotka smazána.', 'alert-success');
        }
        $this->redirect('this');
    }

    public function createComponentInviteManagerForm(): Form
    {
        $form = new Form;
        $form->addEmail('email', 'E-mail uživatele')
            ->setRequired('Zadejte e-mail')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('placeholder', 'uzivatel@example.cz');
        $form->addSubmit('send', 'Odeslat pozvánku')
            ->setHtmlAttribute('class', 'btn btn-primary');
        $form->onSuccess[] = [$this, 'inviteManagerFormSucceeded'];
        return $form;
    }

    public function inviteManagerFormSucceeded(Form $form, \stdClass $values): void
    {
        $user = $this->getUser();
        if (!$user->isInRole('azyl') && !$user->isInRole('superadmin')) {
            $this->flashMessage('Tato akce je vyhrazena zakladateli azylu.', 'alert-warning');
            $this->redirect('Azyl:default');
        }

        $founder     = $this->usersRepository->getUserById($user->getId());
        $azyl        = $this->azylRepository->findById($user->getIdentity()->getData()['Azyl']->getId());
        $invitedUser = $this->usersRepository->findOneBy(['email' => $values->email]);

        if (!$invitedUser) {
            $this->flashMessage('Uživatel s tímto e-mailem není registrován.', 'alert-warning');
            $this->redirect('Azyl:managers');
        }

        if ($invitedUser->getId() === $founder->getId()) {
            $this->flashMessage('Nemůžete pozvat sebe sama.', 'alert-warning');
            $this->redirect('Azyl:managers');
        }

        if ($this->azylCoManagerRepository->findPendingForAzylAndUser($azyl, $invitedUser)) {
            $this->flashMessage('Tento uživatel již byl pozván nebo je správcem.', 'alert-warning');
            $this->redirect('Azyl:managers');
        }

        $token = bin2hex(random_bytes(32));
        $cm    = new AzylCoManager();
        $cm->setAzyl($azyl)
           ->setUser($invitedUser)
           ->setInvitedBy($founder)
           ->setInviteToken($token)
           ->setInvitedAt(new \DateTimeImmutable());
        $this->azylCoManagerRepository->save($cm);

        $this->azylCoManagerMailService->sendInvitation($invitedUser, $azyl, $token);

        $this->flashMessage('Pozvánka byla odeslána na ' . $values->email, 'alert-success');
        $this->redirect('Azyl:managers');
    }

}