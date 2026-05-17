<?php
declare(strict_types=1);

namespace App\Console;

use App\Model\Orm\Repository\CityRepository;
use App\Services\NominatimService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Doplní do tabulky citys souřadnice (latitude, longitude) přes OpenStreetMap Nominatim.
 *
 * Spuštění:
 *   php bin/console app:geocode-cities
 *   php bin/console app:geocode-cities --force   (přepíše i ta co už souřadnice mají)
 *   php bin/console app:geocode-cities --limit=100 (jen 100 měst)
 *
 * POZOR: Nominatim má limit 1 req/sec, tisíc měst = 17 minut.
 */
class GeocodeCitiesCommand extends Command
{
    protected static $defaultName = 'app:geocode-cities';

    private CityRepository $cityRepository;
    private NominatimService $nominatim;
    private EntityManagerInterface $em;

    public function __construct(
        CityRepository $cityRepository,
        NominatimService $nominatim,
        EntityManagerInterface $em
    )
    {
        parent::__construct();
        $this->cityRepository = $cityRepository;
        $this->nominatim = $nominatim;
        $this->em = $em;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Doplní souřadnice městům z OpenStreetMap Nominatim.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Přepíše i města která už souřadnice mají.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximální počet zpracovaných měst.', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool)$input->getOption('force');
        $limit = (int)$input->getOption('limit');

        $cities = $this->cityRepository->findAll();
        $output->writeln(sprintf('Celkem měst v DB: <info>%d</info>', count($cities)));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($cities as $city) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            // Přeskakujeme města co už souřadnice mají (pokud není --force)
            if (!$force && $city->getLatitude() !== null && $city->getLongitude() !== null) {
                $skipped++;
                continue;
            }

            $processed++;
            $output->write(sprintf(
                '[%d/%d] %s (%s)... ',
                $processed,
                count($cities),
                $city->getCityName(),
                $city->getCountryCode()
            ));

            $result = $this->nominatim->geocodeCity(
                $city->getCityName(),
                $city->getCountryCode(),
                $city->getPsc() ?: null
            );

            if ($result === null) {
                $output->writeln('<error>NENALEZENO</error>');
                $failed++;
                continue;
            }

            $city->setLatitude($result['lat']);
            $city->setLongitude($result['lon']);
            $this->em->persist($city);
            $this->em->flush();

            $output->writeln(sprintf(
                '<info>OK</info> %.4f, %.4f',
                $result['lat'],
                $result['lon']
            ));
            $updated++;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Hotovo.</info> Zpracováno: %d | Aktualizováno: %d | Přeskočeno: %d | Nenalezeno: %d',
            $processed,
            $updated,
            $skipped,
            $failed
        ));

        return Command::SUCCESS;
    }
}
