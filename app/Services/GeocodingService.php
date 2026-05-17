<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Repository\AnimalsRepository;
use App\Model\Orm\Repository\AzylRepository;
use App\Model\Orm\Repository\CityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Tracy\Debugger;

/**
 * Geokódování azylů a jejich zvířat pomocí Nominatim.
 *
 * Strategie pro azyl:
 *  1. Má ulici + PSČ → geocodovat adresu přes geocodeQuery()
 *  2. Má jen city (int FK) → zkopírovat souřadnice z Citys entity
 *  3. Nic nemá → přeskočit
 *
 * Zvířata zdědí souřadnice od svého azylu (jsou na stejném místě).
 */
class GeocodingService
{
    public function __construct(
        private readonly NominatimService     $nominatim,
        private readonly AzylRepository      $azylRepository,
        private readonly CityRepository      $cityRepository,
        private readonly AnimalsRepository   $animalsRepository,
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexerService $indexer,
    ) {}

    // ================================================================
    // Jednotlivý azyl
    // ================================================================

    /**
     * Geocoduje jeden azyl. Vrací true pokud byly souřadnice nastaveny.
     */
    public function geocodeAzyl(Azyl $azyl, bool $force = false): bool
    {
        if ($azyl->hasCoordinates() && !$force) {
            return false;
        }

        $lat = null;
        $lon = null;

        // Strategie 1: přesná adresa přes Nominatim
        $address = $azyl->getFullAddress();
        if ($address !== null) {
            $cityName = $this->resolveCityName($azyl);
            $query = $azyl->getAzylName() . ', ' . $address . ($cityName ? ', ' . $cityName : '');
            $result = $this->nominatim->geocodeQuery($query, $azyl->getCountryCode(), $azyl->getZipCode());

            // Fallback: jen adresa bez názvu azylu
            if ($result === null && $cityName) {
                $result = $this->nominatim->geocodeQuery($address . ', ' . $cityName, $azyl->getCountryCode());
            }

            if ($result !== null) {
                $lat = $result['lat'];
                $lon = $result['lon'];
            }
        }

        // Strategie 2: zkopírovat souřadnice z města
        if ($lat === null && $azyl->getCity() !== null) {
            $city = $this->cityRepository->findCityById($azyl->getCity());
            if ($city !== null && $city->getLatitude() !== null && $city->getLongitude() !== null) {
                $lat = (float)$city->getLatitude();
                $lon = (float)$city->getLongitude();
            }
        }

        if ($lat === null) {
            return false;
        }

        $azyl->setLatitude($lat);
        $azyl->setLongitude($lon);
        $this->azylRepository->saveAzyl($azyl);
        $this->indexer->indexAzyl($azyl);

        return true;
    }

    // ================================================================
    // Propagace na zvířata
    // ================================================================

    /**
     * Zkopíruje souřadnice azylu na všechna jeho zvířata.
     * Vrací počet aktualizovaných zvířat.
     */
    public function propagateAzylToAnimals(Azyl $azyl): int
    {
        if (!$azyl->hasCoordinates()) {
            return 0;
        }

        $count = 0;
        foreach ($azyl->getAnimals() as $animal) {
            $animal->setLatitude($azyl->getLatitude());
            $animal->setLongitude($azyl->getLongitude());
            $this->em->persist($animal);
            $this->indexer->indexAnimal($animal);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    // ================================================================
    // Hromadné operace
    // ================================================================

    /**
     * Geocoduje všechny azyly (nebo jen ty bez souřadnic).
     *
     * @return array{processed:int, geocoded:int, skipped:int, failed:int}
     */
    public function batchGeocodeAzyls(bool $onlyMissing = true, ?int $limit = null): array
    {
        $azyls = $onlyMissing
            ? $this->azylRepository->findWithoutCoordinates($limit)
            : ($limit ? array_slice($this->azylRepository->findAll(), 0, $limit) : $this->azylRepository->findAll());

        $stats = ['processed' => 0, 'geocoded' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($azyls as $azyl) {
            $stats['processed']++;
            try {
                $result = $this->geocodeAzyl($azyl, !$onlyMissing);
                $result ? $stats['geocoded']++ : $stats['skipped']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                Debugger::log('GeocodingService batch error azyl#' . $azyl->getId() . ': ' . $e->getMessage(), 'geocoding');
            }
        }

        return $stats;
    }

    /**
     * Propaguje souřadnice ze VŠECH azylů na jejich zvířata.
     * Vrací celkový počet aktualizovaných zvířat.
     */
    public function batchPropagateToAnimals(): int
    {
        $total = 0;
        foreach ($this->azylRepository->findAll() as $azyl) {
            if ($azyl->hasCoordinates()) {
                $total += $this->propagateAzylToAnimals($azyl);
            }
        }
        return $total;
    }

    /**
     * Vrací počet azylů bez souřadnic.
     */
    public function countAzylsWithoutCoordinates(): int
    {
        return count($this->azylRepository->findWithoutCoordinates());
    }

    /**
     * Vrací počet zvířat bez souřadnic.
     */
    public function countAnimalsWithoutCoordinates(): int
    {
        return $this->animalsRepository->countWithoutCoordinates();
    }

    // ================================================================
    // Pomocné metody
    // ================================================================

    private function resolveCityName(Azyl $azyl): ?string
    {
        if ($azyl->getCity() === null) {
            return null;
        }
        $city = $this->cityRepository->findCityById($azyl->getCity());
        return $city?->getCityName();
    }
}
