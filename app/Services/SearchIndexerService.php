<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Citys;
use App\Model\Orm\Repository\AnimalsRepository;
use App\Model\Orm\Repository\AzylRepository;
use App\Model\Orm\Repository\CityRepository;
use Tracy\Debugger;

/**
 * Most mezi Doctrine entitami a OpenSearch.
 *
 * - indexAnimal($animal) voláme vždy po uložení zvířátka v AzylPresenter
 * - reindexAll() pro kompletní přebudování indexu (admin akce)
 * - delete* metody při mazání
 *
 * Služba sama zjistí zda je OpenSearch enabled a pokud ne, tiše nic nedělá.
 * Díky tomu jde volání přidat bezpečně do presenterů, i když je OpenSearch vypnutý.
 */
class SearchIndexerService
{
    private OpenSearchService $openSearch;
    private AnimalsRepository $animalsRepository;
    private AzylRepository $azylRepository;
    private CityRepository $cityRepository;

    public function __construct(
        OpenSearchService $openSearch,
        AnimalsRepository $animalsRepository,
        AzylRepository $azylRepository,
        CityRepository $cityRepository
    )
    {
        $this->openSearch = $openSearch;
        $this->animalsRepository = $animalsRepository;
        $this->azylRepository = $azylRepository;
        $this->cityRepository = $cityRepository;
    }

    // ================================================================
    // Jednotlivé entity
    // ================================================================

    public function indexAnimal(Animal $animal): void
    {
        if (!$this->openSearch->isEnabled()) {
            return;
        }

        $azyl = $animal->getAzyl();
        $city = $azyl !== null && $azyl->getCity() !== null
            ? $this->cityRepository->findCityById((int)$azyl->getCity())
            : null;

        $doc = [
            'id'           => $animal->getId(),
            'name'         => $animal->getName() ?? '',
            'description'  => $animal->getDescription() ?? '',
            'breed'        => $animal->getBreed() ?? '',
            'tags'         => $animal->getTags() ?? '',
            'adoptionType' => $animal->getAdoptionType() ?? '',
            'toAdoption'   => $animal->isToAdoption(),
            'isDeleted'    => $animal->isDeleted(),
            'azylId'       => $azyl !== null ? $azyl->getId() : null,
            'speciesName'  => $animal->getSpecies() !== null ? $animal->getSpecies()->getName() : null,
            'createdAt'    => (new \DateTimeImmutable())->format('c'),
        ];

        if ($city !== null) {
            $doc['cityId'] = $city->getId();
            $doc['cityName'] = $city->getCityName();
        }

        // Přednost: vlastní souřadnice zvířete, pak azylové, pak městské
        $lat = $animal->getLatitude() ?? ($azyl?->getLatitude()) ?? ($city?->getLatitude() !== null ? (float)$city->getLatitude() : null);
        $lon = $animal->getLongitude() ?? ($azyl?->getLongitude()) ?? ($city?->getLongitude() !== null ? (float)$city->getLongitude() : null);
        if ($lat !== null && $lon !== null) {
            $doc['location'] = ['lat' => $lat, 'lon' => $lon];
        }

        $this->openSearch->indexDocument(OpenSearchService::INDEX_ANIMALS, $animal->getId(), $doc);
    }

    public function deleteAnimal(int $animalId): void
    {
        $this->openSearch->deleteDocument(OpenSearchService::INDEX_ANIMALS, $animalId);
    }

    public function indexAzyl(Azyl $azyl): void
    {
        if (!$this->openSearch->isEnabled()) {
            return;
        }

        $city = $azyl->getCity() !== null
            ? $this->cityRepository->findCityById((int)$azyl->getCity())
            : null;

        $doc = [
            'id'               => $azyl->getId(),
            'azylName'         => $azyl->getAzylName() ?? '',
            'description'      => $azyl->getDescription() ?? '',
            'shortDescription' => method_exists($azyl, 'getShortDescription') ? ($azyl->getShortDescription() ?? '') : '',
            'ico'              => method_exists($azyl, 'getIco') ? ($azyl->getIco() ?? '') : '',
        ];

        if ($city !== null) {
            $doc['cityId'] = $city->getId();
            $doc['cityName'] = $city->getCityName();
        }

        // Přednost: vlastní souřadnice azylu, pak městské
        $lat = $azyl->getLatitude() ?? ($city?->getLatitude() !== null ? (float)$city->getLatitude() : null);
        $lon = $azyl->getLongitude() ?? ($city?->getLongitude() !== null ? (float)$city->getLongitude() : null);
        if ($lat !== null && $lon !== null) {
            $doc['location'] = ['lat' => $lat, 'lon' => $lon];
        }

        $this->openSearch->indexDocument(OpenSearchService::INDEX_AZYLS, $azyl->getId(), $doc);
    }

    public function deleteAzyl(int $azylId): void
    {
        $this->openSearch->deleteDocument(OpenSearchService::INDEX_AZYLS, $azylId);
    }

    public function indexCity(Citys $city): void
    {
        if (!$this->openSearch->isEnabled()) {
            return;
        }

        $doc = [
            'id'          => $city->getId(),
            'name'        => $city->getCityName(),
            'region'      => $city->getRegion(),
            'country'     => $city->getCountry(),
            'countryCode' => $city->getCountryCode(),
            'psc'         => $city->getPsc(),
        ];
        if ($city->getLatitude() !== null && $city->getLongitude() !== null) {
            $doc['location'] = [
                'lat' => (float)$city->getLatitude(),
                'lon' => (float)$city->getLongitude(),
            ];
        }

        $this->openSearch->indexDocument(OpenSearchService::INDEX_CITIES, $city->getId(), $doc);
    }

    // ================================================================
    // Hromadné operace
    // ================================================================

    /**
     * Smaže a znovu vybuduje všechny indexy. Pouze pro admin akce!
     * Vrací počty úspěšně naindexovaných entit.
     *
     * @return array{animals:int, azyls:int, cities:int}
     */
    public function reindexAll(): array
    {
        if (!$this->openSearch->isEnabled()) {
            return ['animals' => 0, 'azyls' => 0, 'cities' => 0];
        }

        try {
            $this->openSearch->dropIndexes();
            $this->openSearch->ensureIndexes();
        } catch (\Throwable $e) {
            Debugger::log('ReindexAll ensureIndexes error: ' . $e->getMessage(), 'opensearch');
        }

        $counts = ['animals' => 0, 'azyls' => 0, 'cities' => 0];

        // Cities první - referencují je ostatní
        foreach ($this->cityRepository->findAll() as $city) {
            $this->indexCity($city);
            $counts['cities']++;
        }

        foreach ($this->azylRepository->findAll() as $azyl) {
            $this->indexAzyl($azyl);
            $counts['azyls']++;
        }

        foreach ($this->animalsRepository->findAll() as $animal) {
            $this->indexAnimal($animal);
            $counts['animals']++;
        }

        return $counts;
    }
}
