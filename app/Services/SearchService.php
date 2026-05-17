<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Repository\AnimalsRepository;
use App\Model\Orm\Repository\CityRepository;

/**
 * Unifikovaný vyhledávací backend.
 *
 * - Pokud je OpenSearch zapnutý, používá OpenSearch (s geo_distance)
 * - Pokud vypnutý, fallback na MySQL AnimalsRepository::search()
 *
 * V presenterech pak stačí volat:
 *   $results = $searchService->searchAnimals($query, $cityId, $radiusKm);
 * a nezajímá nás backend.
 */
class SearchService
{
    private OpenSearchService $openSearch;
    private AnimalsRepository $animalsRepository;
    private CityRepository $cityRepository;

    public function __construct(
        OpenSearchService $openSearch,
        AnimalsRepository $animalsRepository,
        CityRepository $cityRepository
    )
    {
        $this->openSearch = $openSearch;
        $this->animalsRepository = $animalsRepository;
        $this->cityRepository = $cityRepository;
    }

    /**
     * Vyhledá zvířata podle dotazu a případně města + radiusu.
     *
     * Chování:
     * - $cityId+$radiusKm => geo vyhledávání (OpenSearch) / fallback na město z DB
     * - jen $query => fulltext
     * - nic => všechna k adopci
     *
     * @param string|null $query    Fulltext dotaz
     * @param int|null    $cityId   ID města ze kterého vycházíme
     * @param int         $radiusKm Okruh v km
     * @param int         $limit
     * @return array<int, Animal|array<string,mixed>>
     *         Vrací Animal entity (MySQL) nebo pole source (OpenSearch).
     *         V šabloně testujte přes `$result instanceof Animal` nebo `$result['id']`.
     */
    public function searchAnimals(?string $query = null, ?int $cityId = null, int $radiusKm = 50, int $limit = 50): array
    {
        $query = $query !== null ? trim($query) : null;

        if ($this->openSearch->isEnabled()) {
            return $this->searchViaOpenSearch($query, $cityId, $radiusKm, $limit);
        }

        return $this->searchViaMysql($query, $cityId, $radiusKm, $limit);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function searchViaOpenSearch(?string $query, ?int $cityId, int $radiusKm, int $limit): array
    {
        if ($cityId !== null) {
            $city = $this->cityRepository->findCityById($cityId);
            if ($city !== null && $city->getLatitude() !== null && $city->getLongitude() !== null) {
                return $this->openSearch->searchAnimalsNearLocation(
                    (float)$city->getLatitude(),
                    (float)$city->getLongitude(),
                    (float)$radiusKm,
                    $query,
                    $limit
                );
            }
        }

        if ($query !== null && $query !== '') {
            return $this->openSearch->searchAnimals($query, $limit);
        }

        // Bez query a bez města - použijeme match_all s filtrem
        return $this->openSearch->searchAnimals('', $limit);
    }

    /**
     * MySQL fallback - používá AnimalsRepository::search().
     * Pro geo filtrování aplikuje Haversine na loaded výsledky.
     *
     * @return Animal[]
     */
    private function searchViaMysql(?string $query, ?int $cityId, int $radiusKm, int $limit): array
    {
        // Pokud nic nehledáme a není město, vrátíme prázdně (původní search vyžaduje string)
        if (($query === null || $query === '') && $cityId === null) {
            return $this->animalsRepository->findBy(
                ['toAdoption' => true, 'isDeleted' => false],
                ['id' => 'DESC'],
                $limit
            );
        }

        $searchQuery = $query ?? '';

        // Pokud je zadané město, doplníme ho do dotazu - AnimalsRepository::search()
        // hledá podle tags, kde je cityName přidaný při ukládání zvířete
        if ($cityId !== null) {
            $city = $this->cityRepository->findCityById($cityId);
            if ($city !== null) {
                $searchQuery = trim($searchQuery . ' ' . $city->getCityName());
            }
        }

        $results = $this->animalsRepository->search($searchQuery);

        // Pokud bylo zadáno město a máme souřadnice, filtrujeme dle radiusu
        if ($cityId !== null) {
            $city = $this->cityRepository->findCityById($cityId);
            if ($city !== null && $city->getLatitude() !== null && $city->getLongitude() !== null) {
                $cityLat = (float)$city->getLatitude();
                $cityLon = (float)$city->getLongitude();
                $results = array_values(array_filter($results, function (Animal $animal) use ($cityLat, $cityLon, $radiusKm) {
                    $azyl = $animal->getAzyl();
                    if ($azyl === null || $azyl->getCity() === null) {
                        return false;
                    }
                    $azylCity = $this->cityRepository->findCityById((int)$azyl->getCity());
                    if ($azylCity === null
                        || $azylCity->getLatitude() === null
                        || $azylCity->getLongitude() === null) {
                        return false;
                    }
                    $dist = NominatimService::distanceKm(
                        $cityLat,
                        $cityLon,
                        (float)$azylCity->getLatitude(),
                        (float)$azylCity->getLongitude()
                    );
                    return $dist <= $radiusKm;
                }));
            }
        }

        if (count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }
}
