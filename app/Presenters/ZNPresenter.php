<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Model\Orm\Entity\AnimalSighting;
use App\Model\Orm\Entity\FoundAnimal;
use App\Model\Orm\Entity\LostAnimal;
use App\Model\Orm\Entity\Photo;
use App\Model\Orm\Repository\AnimalSightingRepository;
use App\Model\Orm\Repository\FoundAnimalRepository;
use App\Model\Orm\Repository\LostAnimalRepository;
use App\Model\Orm\Repository\PhotosRepository;
use App\Repository\SpeciesRepository;
use App\Model\Orm\Repository\UsersRepository;
use App\Services\NominatimService;
use App\Model\Services\Menu;
use App\Services\ZNMailService;
use Contributte\Application\UI\BasePresenter;
use Contributte\PdfResponse\PdfResponse;
use Contributte\PdfResponse\PdfResponseFactory;
use DateTimeImmutable;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Writer\PngWriter;
use Nette;
use Nette\Application\UI\Form;

class ZNPresenter extends BasePresenter
{
    public function __construct(
        private readonly LostAnimalRepository   $lostAnimalRepository,
        private readonly FoundAnimalRepository  $foundAnimalRepository,
        private readonly AnimalSightingRepository $animalSightingRepository,
        private readonly PhotosRepository       $photosRepository,
        private readonly SpeciesRepository      $speciesRepository,
        private readonly UsersRepository        $usersRepository,
        private readonly NominatimService       $nominatimService,
        private readonly ZNMailService          $znMailService,
        private readonly PdfResponseFactory     $pdfResponseFactory,
    ) {
        parent::__construct();
    }

    protected function beforeRender(): void
    {
        parent::beforeRender();
        $this->getTemplate()->speciesList    = $this->speciesRepository->findAll();
        $this->getTemplate()->isLoggedIn     = $this->getUser()->isLoggedIn();
        $this->getTemplate()->mainMenuItems  = (new Menu())->getMenu();
        $this->getTemplate()->cartItemCount  = 0;
    }

    // =========================================================================
    // Hlavní listing
    // =========================================================================

    public function renderDefault(?int $species = null, ?string $city = null, string $tab = 'lost'): void
    {
        $speciesEntity = $species ? $this->speciesRepository->find($species) : null;
        $tab = in_array($tab, ['lost', 'found']) ? $tab : 'lost';

        if ($tab === 'lost') {
            $items = $this->lostAnimalRepository->findByCityAndSpecies(
                $city, $speciesEntity, LostAnimal::STATUS_SEARCHING
            );
        } else {
            $items = $this->foundAnimalRepository->findPublicOpen();
            if ($speciesEntity) {
                $items = array_filter($items, fn($a) => $a->getSpecies()->getId() === $speciesEntity->getId());
            }
            if ($city) {
                $items = array_filter($items, fn($a) =>
                    stripos((string)$a->getCity(), $city) !== false ||
                    stripos($a->getLocation(), $city) !== false
                );
            }
        }

        $this->getTemplate()->tab          = $tab;
        $this->getTemplate()->items        = array_values($items);
        $this->getTemplate()->filterSpecies = $species;
        $this->getTemplate()->filterCity   = $city ?? '';
        $this->getTemplate()->lostCount    = count($this->lostAnimalRepository->findPublicSearching(1000));
        $this->getTemplate()->foundCount   = count($this->foundAnimalRepository->findPublicOpen(1000));
    }

    // =========================================================================
    // Detail ztraceného
    // =========================================================================

    public function renderLost(int $id): void
    {
        $animal = $this->lostAnimalRepository->find($id);
        if (!$animal || $animal->isDeleted()) {
            $this->error('Ztracenec nenalezen', 404);
        }

        $nearbyFound = [];
        if ($animal->hasGps()) {
            $nearbyFound = $this->foundAnimalRepository->findNearbyBySpecies(
                $animal->getSpecies(), $animal->getLat(), $animal->getLon(), 30
            );
        }

        $this->getTemplate()->animal      = $animal;
        $this->getTemplate()->nearbyFound = $nearbyFound;
        $this->getTemplate()->photos      = $animal->getPhotos()->filter(fn($p) => !$p->isDeleted())->toArray();
        $this->getTemplate()->sightings   = $animal->getSightings()->toArray();
        $this->getTemplate()->title       = 'Hledáme: ' . ($animal->getName() ?? $animal->getSpecies()->getName());
    }

    // =========================================================================
    // Detail nalezeného
    // =========================================================================

    public function renderFound(int $id): void
    {
        $animal = $this->foundAnimalRepository->find($id);
        if (!$animal || $animal->isDeleted()) {
            $this->error('Nalezenec nenalezen', 404);
        }
        if (!$animal->isContactVisible()) {
            $this->flashMessage('Tento nalezenec čeká na potvrzení emailu.', 'alert-warning');
        }

        $nearbyLost = [];
        if ($animal->hasGps()) {
            $nearbyLost = $this->lostAnimalRepository->findBySpeciesNearby(
                $animal->getSpecies(), $animal->getLat(), $animal->getLon(), 30
            );
        }

        $this->getTemplate()->animal      = $animal;
        $this->getTemplate()->nearbyLost  = $nearbyLost;
        $this->getTemplate()->photos      = $animal->getPhotos()->filter(fn($p) => !$p->isDeleted())->toArray();
        $this->getTemplate()->title       = 'Nalezeno: ' . $animal->getSpecies()->getName();
    }

    // =========================================================================
    // Formulář — ztracené zvíře (jen přihlášení)
    // =========================================================================

    public function actionReportLost(?int $id = null): void
    {
        if (!$this->getUser()->isLoggedIn()) {
            $this->flashMessage('Pro nahlášení ztraceného zvířete se přihlaste.', 'alert-warning');
            $this->redirect('Home:signIn');
        }

        $animal = null;
        if ($id !== null) {
            $animal = $this->lostAnimalRepository->find($id);
            if (!$animal || $animal->getUser()->getId() !== $this->getUser()->getId()) {
                $this->error('Informace nenalezena :-(', 404);
            }
        }
        $this->getTemplate()->animal = $animal;
    }

    private function applyRequiredSuffix(Form $form): void
    {
        $form->getRenderer()->wrappers['label']['requiredsuffix'] =
            ' <span class="badge bg-danger ms-1" style="font-size:10px;font-weight:500;vertical-align:middle;">povinné</span>';
    }

    public function createComponentLostForm(): Form
    {
        $form = new Form();
        $this->applyRequiredSuffix($form);

        $id = $this->getParameter('id');
        $animal = $id ? $this->lostAnimalRepository->find((int)$id) : null;

        $form->addHidden('id', $id ? (string)$id : '');
        $form->addSelect('species', 'Druh zvířete', $this->speciesRepository->toFormArray())
             ->setPrompt('— Vyberte druh —')
             ->setRequired('Vyberte druh zvířete');
        $form->addText('name', 'Jméno zvířete')
             ->setMaxLength(100);
        $form->addText('aliases', 'Další jména / přezdívky')
             ->setMaxLength(255);
        $form->addText('breed', 'Rasa / plemeno')
             ->setMaxLength(150);
        $form->addText('color', 'Barva / zbarvení')
             ->setMaxLength(100);
        $form->addTextArea('description', 'Popis zvířete')
             ->setRequired('Popište zvíře')
             ->setHtmlAttribute('rows', 4);
        $form->addTextArea('eventDescription', 'Jak se zvíře ztratilo')
             ->setHtmlAttribute('placeholder', 'Kdy, kde a jak se zvíře ztratilo...')
             ->setRequired('Popište, jak se zvíře ztratilo')
             ->setHtmlAttribute('rows', 3);
        $form->addCheckbox('hasChip', 'Zvíře má čip');
        $form->addText('chipNumber', 'Číslo čipu')
             ->setMaxLength(50);
        $form->addCheckbox('hasTattoo', 'Zvíře má tetování');
        $form->addText('tattooValue', 'Hodnota tetování')
             ->setMaxLength(50);
        $form->addText('specialMarks', 'Zvláštní znamení')
             ->setMaxLength(255);
        $form->addText('location', 'Místo ztráty')
             ->setRequired('Zadejte místo ztráty')
             ->setMaxLength(255);
        $form->addText('lostAt', 'Datum a čas ztráty')
             ->setHtmlType('datetime-local')
             ->setRequired('Zadejte datum ztráty');
        $form->addMultiUpload('photos', 'Fotografie (max. 3)')
             ->addRule(Form::MaxLength, 'Maximálně 3 fotografie', 3)
             ->addCondition(Form::Filled)
             ->addRule(Form::MaxFileSize, 'Max 5 MB na soubor', 5 * 1024 * 1024);
        $form->addSubmit('save', 'Uložit inzerát');

        if ($animal) {
            $form->setDefaults([
                'id'               => $animal->getId(),
                'species'          => $animal->getSpecies()->getId(),
                'name'             => $animal->getName(),
                'aliases'          => $animal->getAliases(),
                'breed'            => $animal->getBreed(),
                'color'            => $animal->getColor(),
                'description'      => $animal->getDescription(),
                'eventDescription' => $animal->getEventDescription(),
                'hasChip'          => $animal->isHasChip(),
                'chipNumber'       => $animal->getChipNumber(),
                'hasTattoo'        => $animal->isHasTattoo(),
                'tattooValue'      => $animal->getTattooValue(),
                'specialMarks'     => $animal->getSpecialMarks(),
                'location'         => $animal->getLocation(),
                'lostAt'           => $animal->getLostAt()->format('Y-m-d\TH:i'),
            ]);
        } else {
            $form->setDefaults(['lostAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i')]);
        }

        $form->onSuccess[] = [$this, 'lostFormSucceeded'];
        return $form;
    }

    public function lostFormSucceeded(Form $form, \stdClass $values): void
    {
        $user   = $this->usersRepository->getUserById($this->getUser()->getId());
        $id     = (int)$values->id ?: null;
        $species = $this->speciesRepository->find((int)$values->species);

        if ($id) {
            $animal = $this->lostAnimalRepository->find($id);
            if (!$animal || $animal->getUser()->getId() !== $user->getId()) {
                $this->error('Inzerát nenalezen', 404);
            }
        } else {
            $animal = new LostAnimal();
            $animal->setUser($user);
        }

        $animal->setSpecies($species);
        $animal->setSex($species->getSex());
        $animal->setName($values->name ?: null);
        $animal->setAliases($values->aliases ?: null);
        $animal->setBreed($values->breed ?: null);
        $animal->setColor($values->color ?: null);
        $animal->setDescription($values->description);
        $animal->setEventDescription($values->eventDescription);
        $animal->setHasChip($values->hasChip);
        $animal->setChipNumber($values->hasChip ? ($values->chipNumber ?: null) : null);
        $animal->setHasTattoo($values->hasTattoo);
        $animal->setTattooValue($values->hasTattoo ? ($values->tattooValue ?: null) : null);
        $animal->setSpecialMarks($values->specialMarks ?: null);
        $animal->setLocation($values->location);
        $animal->setLostAt(new DateTimeImmutable($values->lostAt));
        $animal->setUpdatedAt(new DateTimeImmutable());

        // Geocoding
        $geo = $this->nominatimService->geocodeQuery($values->location);
        if ($geo) {
            $animal->setLat($geo['lat']);
            $animal->setLon($geo['lon']);
            $parts = explode(',', $geo['displayName'] ?? '');
            $animal->setCity(trim($parts[0] ?? ''));
        }

        $this->lostAnimalRepository->save($animal);

        // Nahrání fotek (max 3)
        $photoCount = 0;
        foreach ($values->photos as $upload) {
            if ($photoCount >= 3 || !$upload->isOk()) {
                continue;
            }
            $photo = new Photo();
            $photo->setDate(new DateTimeImmutable());
            $photo->setLostAnimal($animal);
            $photo->uploadZNPhoto($upload, 'lost_' . $animal->getId());
            $this->photosRepository->save($photo);
            $photoCount++;
        }

        $this->flashMessage('Inzerát byl uložen.', 'alert-success');
        $this->redirect('ZN:lost', $animal->getId());
    }

    // =========================================================================
    // Formulář — nalezené zvíře
    // =========================================================================

    public function renderReportFound(): void
    {
        // template handles display
    }

    public function createComponentFoundForm(): Form
    {
        $form = new Form();
        $this->applyRequiredSuffix($form);

        $form->addSelect('species', 'Druh zvířete', $this->speciesRepository->toFormArray())
             ->setPrompt('— Vyberte druh —')
             ->setRequired('Vyberte druh zvířete');
        $form->addText('breed', 'Rasa / plemeno')->setMaxLength(150);
        $form->addText('color', 'Barva / zbarvení')->setMaxLength(100);
        $form->addTextArea('description', 'Popis zvířete')
             ->setRequired('Popište, jak zvíře vypadá')
             ->setHtmlAttribute('rows', 4);
        $form->addText('location', 'Místo nálezu')
             ->setRequired('Zadejte místo nálezu')
             ->setMaxLength(255);
        $form->addText('foundAt', 'Datum a čas nálezu')
             ->setHtmlType('datetime-local')
             ->setRequired('Zadejte datum nálezu');
        $form->addTextArea('note', 'Poznámka (kde je momentálně zvíře apod.)')
             ->setHtmlAttribute('rows', 3);

        if (!$this->getUser()->isLoggedIn()) {
            $form->addText('reporterName', 'Vaše jméno')
                 ->setRequired('Zadejte jméno')
                 ->setMaxLength(100);
            $form->addEmail('reporterEmail', 'Email')
                 ->setRequired('Zadejte email — potřebujeme jej pro potvrzení')
                 ->setMaxLength(150);
            $form->addText('reporterPhone', 'Telefon (nepovinný)')
                 ->setMaxLength(30);
        } else {
            $form->addText('reporterPhone', 'Telefon (nepovinný)')
                 ->setMaxLength(30);
        }

        $form->addMultiUpload('photos', 'Fotografie (max. 3)')
             ->addRule(Form::MaxLength, 'Maximálně 3 fotografie', 3)
             ->addCondition(Form::Filled)
             ->addRule(Form::MaxFileSize, 'Max 5 MB na soubor', 5 * 1024 * 1024);

        $form->addSubmit('save', 'Uložit nález');
        $form->setDefaults(['foundAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i')]);

        $form->onSuccess[] = [$this, 'foundFormSucceeded'];
        return $form;
    }

    public function foundFormSucceeded(Form $form, \stdClass $values): void
    {
        $species = $this->speciesRepository->find((int)$values->species);
        $animal  = new FoundAnimal();
        $animal->setSpecies($species);
        $animal->setSex($species->getSex());
        $animal->setBreed($values->breed ?: null);
        $animal->setColor($values->color ?: null);
        $animal->setDescription($values->description);
        $animal->setLocation($values->location);
        $animal->setFoundAt(new DateTimeImmutable($values->foundAt));
        $animal->setNote($values->note ?: null);
        $animal->setReporterPhone($values->reporterPhone ?: null);

        if ($this->getUser()->isLoggedIn()) {
            $user = $this->usersRepository->getUserById($this->getUser()->getId());
            $animal->setUser($user);
            $animal->setIsEmailConfirmed(true);
        } else {
            $animal->setReporterName($values->reporterName);
            $animal->setReporterEmail($values->reporterEmail);
            $animal->setIsEmailConfirmed(false);
        }

        // Geocoding
        $geo = $this->nominatimService->geocodeQuery($values->location);
        if ($geo) {
            $animal->setLat($geo['lat']);
            $animal->setLon($geo['lon']);
            $parts = explode(',', $geo['displayName'] ?? '');
            $animal->setCity(trim($parts[0] ?? ''));
        }

        $this->foundAnimalRepository->save($animal);

        // Fotky
        $photoCount = 0;
        foreach ($values->photos as $upload) {
            if ($photoCount >= 3 || !$upload->isOk()) {
                continue;
            }
            $photo = new Photo();
            $photo->setDate(new DateTimeImmutable());
            $photo->setFoundAnimal($animal);
            $photo->uploadZNPhoto($upload, 'found_' . $animal->getId());
            $this->photosRepository->save($photo);
            $photoCount++;
        }

        // Potvrzovací email pro anonymního nálezce
        if (!$this->getUser()->isLoggedIn()) {
            $this->znMailService->sendFoundConfirmation($animal);
            $this->flashMessage(
                'Nález byl přijat. Zkontrolujte email a klikněte na potvrzovací odkaz.',
                'alert-info'
            );
        } else {
            $this->flashMessage('Nález byl uložen.', 'alert-success');
        }

        // Přesměrovat na stránku s nalezenými zvířaty blízko (pokud máme GPS)
        if ($animal->hasGps()) {
            $this->redirect('ZN:foundMatch', $animal->getId());
        } else {
            $this->redirect('ZN:found', $animal->getId());
        }
    }

    // =========================================================================
    // Po uložení nalezeného — shoda v okolí
    // =========================================================================

    public function renderFoundMatch(int $id): void
    {
        $found = $this->foundAnimalRepository->find($id);
        if (!$found) {
            $this->redirect('ZN:default');
        }

        $nearby = [];
        if ($found->hasGps()) {
            $nearby = $this->lostAnimalRepository->findBySpeciesNearby(
                $found->getSpecies(), $found->getLat(), $found->getLon(), 30
            );
        } else {
            $nearby = $this->lostAnimalRepository->findByCityAndSpecies(
                $found->getCity(), $found->getSpecies(), LostAnimal::STATUS_SEARCHING
            );
        }

        $this->getTemplate()->found  = $found;
        $this->getTemplate()->nearby = array_values($nearby);
        $this->getTemplate()->title  = 'Nalezené zvíře — shody v okolí';
    }

    // =========================================================================
    // QR sighting stránka
    // =========================================================================

    public function renderSighting(string $token): void
    {
        $animal = $this->lostAnimalRepository->findByToken($token);
        if (!$animal) {
            $this->error('Inzerát nenalezen', 404);
        }
        $this->getTemplate()->animal = $animal;
        $this->getTemplate()->title  = 'Zanechat vzkaz — ' . ($animal->getName() ?? $animal->getSpecies()->getName());
    }

    public function createComponentSightingForm(): Form
    {
        $form = new Form();
        $this->applyRequiredSuffix($form);

        $form->addHidden('token');
        $form->addRadioList('type', 'Co chcete nahlásit?', [
            AnimalSighting::TYPE_SIGHTING   => 'Viděl/a jsem zvíře',
            AnimalSighting::TYPE_HAS_ANIMAL => 'Mám zvíře u sebe',
        ])->setRequired('Vyberte typ hlášení')->setDefaultValue(AnimalSighting::TYPE_SIGHTING);
        $form->addTextArea('message', 'Váš vzkaz')
             ->setRequired('Napište vzkaz')
             ->setHtmlAttribute('placeholder', 'Kde jste zvíře viděli, jak se chová, kde momentálně je...')
             ->setHtmlAttribute('rows', 4);
        $form->addText('location', 'Kde jste zvíře viděli (adresa/popis)')
             ->setMaxLength(255);
        $form->addText('contactName', 'Vaše jméno')
             ->setMaxLength(100);
        $form->addEmail('contactEmail', 'Váš email')
             ->setRequired('Zadejte email pro zpětný kontakt')
             ->setMaxLength(150);
        $form->addText('contactPhone', 'Váš telefon')
             ->setMaxLength(30);
        $form->addSubmit('send', 'Odeslat vzkaz');

        $form->onSuccess[] = [$this, 'sightingFormSucceeded'];
        return $form;
    }

    public function sightingFormSucceeded(Form $form, \stdClass $values): void
    {
        $token  = $this->getParameter('token');
        $animal = $this->lostAnimalRepository->findByToken($token);
        if (!$animal) {
            $this->error('Inzerát nenalezen', 404);
        }

        $sighting = new AnimalSighting();
        $sighting->setLostAnimal($animal);
        $sighting->setType($values->type);
        $sighting->setMessage($values->message);
        $sighting->setLocation($values->location ?: null);
        $sighting->setContactName($values->contactName ?: null);
        $sighting->setContactEmail($values->contactEmail);
        $sighting->setContactPhone($values->contactPhone ?: null);

        // Geocoding lokace vzkazku
        if ($values->location) {
            $geo = $this->nominatimService->geocodeQuery($values->location);
            if ($geo) {
                $sighting->setLat($geo['lat']);
                $sighting->setLon($geo['lon']);
            }
        }

        $this->animalSightingRepository->save($sighting);

        // Odeslat email majiteli
        try {
            $this->znMailService->sendSightingNotification($sighting);
            $sighting->setIsNotified(true);
            $this->animalSightingRepository->save($sighting);
        } catch (\Throwable) {
            // Email selhal — zaznamená se, ale nepřerušíme UX
        }

        $this->flashMessage('Vzkaz byl odeslán majiteli zvířete. Děkujeme!', 'alert-success');
        $this->redirect('ZN:sightingThank', $animal->getId());
    }

    public function renderSightingThank(int $id): void
    {
        $animal = $this->lostAnimalRepository->find($id);
        $this->getTemplate()->animal = $animal;
        $this->getTemplate()->title  = 'Vzkaz odeslán';
    }

    // =========================================================================
    // Uzavření reportu z emailu
    // =========================================================================

    public function renderClose(string $token, string $status = 'found'): void
    {
        $animal = $this->lostAnimalRepository->findByToken($token);
        if (!$animal) {
            $this->error('Inzerát nenalezen', 404);
        }
        $this->getTemplate()->animal = $animal;
        $this->getTemplate()->status = $status;
    }

    public function handleCloseReport(string $token, string $status): void
    {
        $animal = $this->lostAnimalRepository->findByToken($token);
        if (!$animal) {
            $this->error('Inzerát nenalezen', 404);
        }

        $validStatus = in_array($status, [LostAnimal::STATUS_FOUND, LostAnimal::STATUS_NOT_FOUND])
            ? $status : LostAnimal::STATUS_FOUND;

        $animal->setStatus($validStatus);
        $animal->setUpdatedAt(new DateTimeImmutable());
        $this->lostAnimalRepository->save($animal);

        $label = $validStatus === LostAnimal::STATUS_FOUND ? 'Inzerát uzavřen — zvíře nalezeno! 🎉' : 'Inzerát uzavřen.';
        $this->flashMessage($label, 'alert-success');
        $this->redirect('ZN:lost', $animal->getId());
    }

    // =========================================================================
    // Potvrzení emailu pro anonymní nálezce
    // =========================================================================

    public function renderConfirm(string $token): void
    {
        $found = $this->foundAnimalRepository->findByConfirmToken($token);
        if (!$found) {
            $this->flashMessage('Potvrzovací odkaz je neplatný nebo již byl použit.', 'alert-danger');
            $this->redirect('ZN:default');
        }

        $found->setIsEmailConfirmed(true);
        $found->clearConfirmToken();
        $this->foundAnimalRepository->save($found);

        $this->flashMessage('Email potvrzen. Váš inzerát je nyní viditelný.', 'alert-success');
        $this->redirect('ZN:found', $found->getId());
    }

    // =========================================================================
    // PDF plakát
    // =========================================================================

    public function actionPdf(int $id): void
    {
        $animal = $this->lostAnimalRepository->find($id);
        if (!$animal || $animal->isDeleted()) {
            $this->error('Inzerát nenalezen', 404);
        }

        // QR kód
        $sightingUrl = $this->link('//ZN:sighting', $animal->getSecretToken());
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->data($sightingUrl)
            ->size(200)
            ->margin(10)
            ->build();
        $qrBase64 = base64_encode($qrResult->getString());

        // Statická mapa (OSM) — pokud máme GPS
        $mapBase64 = null;
        if ($animal->hasGps()) {
            $lat = $animal->getLat();
            $lon = $animal->getLon();
            $mapUrl = "https://staticmap.openstreetmap.de/staticmap.php?center={$lat},{$lon}&zoom=14&size=500x200&markers={$lat},{$lon},lightblue1";
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'VAZ-ZN/1.0']]);
                $mapData = @file_get_contents($mapUrl, false, $ctx);
                if ($mapData !== false) {
                    $mapBase64 = base64_encode($mapData);
                }
            } catch (\Throwable) {
                // mapa není kritická
            }
        }

        // Fotky: načíst binárně a předat přes mPDF imageVars (var: mechanismus)
        $wwwDir = realpath(__DIR__ . '/../../www');
        $photoEntities = $animal->getPhotos()->filter(fn($p) => !$p->isDeleted())->slice(0, 3);
        $photoVarNames = [];
        $photoData = [];
        foreach ($photoEntities as $i => $p) {
            $file = $wwwDir . $p->getPath() . $p->getName();
            if (is_file($file)) {
                $varName = 'znphoto_' . $i;
                $photoVarNames[] = $varName;
                $photoData[$varName] = file_get_contents($file);
            }
        }

        // Sestavit HTML pro PDF
        $latte = new \Latte\Engine();
        $latte->setTempDirectory(__DIR__ . '/../../temp/latte');
        $html = $latte->renderToString(
            __DIR__ . '/templates/ZN/pdf.latte',
            [
                'animal'        => $animal,
                'photoVarNames' => $photoVarNames,
                'qrBase64'      => $qrBase64,
                'mapBase64'     => $mapBase64,
                'sightingUrl'   => $sightingUrl,
            ]
        );

        $response = $this->pdfResponseFactory->createResponse();
        $response->setTemplate($html);
        $response->setPageMargins('8,8,8,8,0,0');

        // Předat binární data fotek do mPDF před odesláním
        $mpdf = $response->getMPDF();
        foreach ($photoData as $varName => $data) {
            $mpdf->imageVars[$varName] = $data;
        }

        $response->setSaveMode(PdfResponse::DOWNLOAD);
        $response->setDocumentTitle('plakat-' . ($animal->getName() ?? 'zvire') . '-' . $animal->getId());
        $this->sendResponse($response);
    }
}
