<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Adoption;
use App\Model\Orm\Entity\Animal;
use App\Model\Orm\Entity\Photo;
use App\Model\Orm\Enums\AdoptionsTypeEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generuje veřejné feedy adopcí pro integrace s třetími stranami
 * (veganský vyhledávač, další web, RSS čtečky).
 *
 * Podporované formáty:
 *   - RSS 2.0 (XML) - klasický feed pro RSS čtečky
 *   - Atom - moderní alternativa k RSS
 *   - JSON Feed (jsonfeed.org) - pro JavaScript
 *   - GeoRSS rozšíření (se souřadnicemi) - pro mapové aplikace
 *   - Vlastní XML - bohatší struktura pro specifickou integraci
 *
 * Režimy řazení:
 *   - latest - nejnovější k adopci (default)
 *   - random - náhodně vybrané, obnovuje se každou hodinu
 *   - geo   - podle vzdálenosti od zadaných souřadnic
 */
class AdoptionsFeedService
{
    public const MODE_LATEST = 'latest';
    public const MODE_RANDOM = 'random';
    public const MODE_GEO = 'geo';

    public const FORMAT_RSS = 'rss';
    public const FORMAT_ATOM = 'atom';
    public const FORMAT_JSON = 'json';
    public const FORMAT_XML = 'xml';
    public const FORMAT_GEOJSON = 'geojson';

    private EntityManagerInterface $em;
    private SystemSettingsReader $settings;

    public function __construct(EntityManagerInterface $em, SystemSettingsReader $settings)
    {
        $this->em = $em;
        $this->settings = $settings;
    }

    // =============================================================
    // Hlavní API: získat zvířata pro feed
    // =============================================================

    /**
     * @param array{
     *   mode?: string,
     *   limit?: int,
     *   species?: string|null,
     *   adoption_type?: string|null,
     *   lat?: float|null,
     *   lon?: float|null,
     *   radius_km?: int,
     * } $params
     *
     * @return array<int, array<string, mixed>>
     *         Plně hydrovaná zvířata + vypočítaná pole (distance_km, main_photo_url, ...)
     */
    public function getAnimals(array $params = []): array
    {
        $mode = $params['mode'] ?? self::MODE_LATEST;
        $limit = min((int)($params['limit'] ?? 50), 200); // cap - ochrana

        $qb = $this->em->createQueryBuilder()
            ->select('a', 'az', 'sp', 'c')
            ->from(Animal::class, 'a')
            ->innerJoin('a.azyl', 'az')
            ->innerJoin('a.species', 'sp')
            ->leftJoin('App\Model\Orm\Entity\Citys', 'c', 'WITH', 'c.id = az.city')
            ->where('a.isDeleted = false')
            ->andWhere('a.toAdoption = true')
            ->andWhere('a.adopted = false');

        if (!empty($params['species'])) {
            $qb->andWhere('sp.name = :species')->setParameter('species', $params['species']);
        }

        if (!empty($params['adoption_type'])) {
            $qb->andWhere('a.adoptionType = :at')->setParameter('at', $params['adoption_type']);
        }

        // Geo filter - jen zvířata v azylech se známou polohou
        if ($mode === self::MODE_GEO && !empty($params['lat']) && !empty($params['lon'])) {
            $qb->andWhere('c.latitude IS NOT NULL AND c.longitude IS NOT NULL');
        }

        switch ($mode) {
            case self::MODE_RANDOM:
                // "Deterministicky náhodné" - při stejné seed se vrátí stejná data
                // seed se mění každou hodinu, takže feed se obnoví každou hodinu
                $seed = (int)date('YmdH');
                $qb->orderBy("RAND($seed)");
                break;
            case self::MODE_GEO:
                // pořadí doplníme PHP side (Doctrine DQL nepodporuje haversine out of box)
                $qb->orderBy('a.id', 'DESC');
                break;
            case self::MODE_LATEST:
            default:
                $qb->orderBy('a.id', 'DESC');
                break;
        }

        $qb->setMaxResults($limit * ($mode === self::MODE_GEO ? 3 : 1)); // geo chce víc pro filtraci

        $animals = $qb->getQuery()->getResult();

        // Hydratace do pole + případný geo výpočet
        $result = [];
        foreach ($animals as $animal) {
            /** @var Animal $animal */
            $row = $this->hydrateAnimal($animal);

            if ($mode === self::MODE_GEO) {
                if (!isset($row['lat']) || !isset($row['lon'])) {
                    continue;
                }
                $row['distance_km'] = $this->haversine(
                    (float)$params['lat'],
                    (float)$params['lon'],
                    $row['lat'],
                    $row['lon']
                );

                $radius = (int)($params['radius_km'] ?? 100);
                if ($row['distance_km'] > $radius) {
                    continue;
                }
            }

            $result[] = $row;
        }

        // Geo seřadíme podle vzdálenosti a limitneme
        if ($mode === self::MODE_GEO) {
            usort($result, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
            $result = array_slice($result, 0, $limit);
        }

        return $result;
    }

    // =============================================================
    // Hydratace Animal → pole pro feed
    // =============================================================

    private function hydrateAnimal(Animal $a): array
    {
        $baseUrl = rtrim((string)$this->settings->get('shop.base_url', 'https://virtualniazyl.cz'), '/');

        $azyl = $a->getAzyl();
        $city = $azyl !== null && $azyl->getCity() !== null
            ? $this->em->getRepository(\App\Model\Orm\Entity\Citys::class)->find((int)$azyl->getCity())
            : null;

        // Hlavní fotka
        $mainPhotoUrl = null;
        $allPhotoUrls = [];
        if ($a->getPhotos() !== null) {
            foreach ($a->getPhotos() as $photo) {
                /** @var Photo $photo */
                $url = $baseUrl . $photo->getPath() . $photo->getName();
                $allPhotoUrls[] = $url;
                if ($mainPhotoUrl === null) {
                    $mainPhotoUrl = $url;
                }
            }
        }

        // Věk
        $ageInYears = null;
        if ($a->getBirthdate() !== null) {
            $diff = (new DateTimeImmutable())->diff($a->getBirthdate());
            $ageInYears = $diff->y + round($diff->m / 12, 1);
        } elseif ($a->getAge() !== null) {
            $ageInYears = $a->getAge();
        }

        // Permalink (pro rss)
        $permalink = $baseUrl . '/adopce-zvirate/' . $a->getId();

        return [
            'id'               => $a->getId(),
            'name'             => $a->getName(),
            'species'          => $a->getSpecies()->getName(),
            'breed'            => $a->getBreed(),
            'adoption_type'    => $a->getAdoptionType(),
            'description'      => $a->getDescription(),
            'age_years'        => $ageInYears,
            'height_cm'        => $a->getHeight(),
            'weight_kg'        => $a->getWeight(),
            'main_photo_url'   => $mainPhotoUrl,
            'all_photos'       => $allPhotoUrls,
            'azyl_id'          => $azyl?->getId(),
            'azyl_name'        => $azyl?->getAzylName(),
            'azyl_url'         => $azyl ? $baseUrl . '/azyl/' . $azyl->getId() : null,
            'city'             => $city?->getCityName(),
            'country_code'     => $city?->getCountryCode(),
            'lat'              => $city?->getLatitude() !== null ? (float)$city->getLatitude() : null,
            'lon'              => $city?->getLongitude() !== null ? (float)$city->getLongitude() : null,
            'permalink'        => $permalink,
            'is_virtual'       => $a->getAdoptionType() === AdoptionsTypeEnum::VIRTUAL_ADOPTION_TYPE,
        ];
    }

    // =============================================================
    // Render do formátů
    // =============================================================

    /**
     * @return array{content: string, mime: string, filename: string}
     */
    public function render(array $animals, string $format, array $feedMeta = []): array
    {
        return match ($format) {
            self::FORMAT_RSS     => $this->renderRss($animals, $feedMeta),
            self::FORMAT_ATOM    => $this->renderAtom($animals, $feedMeta),
            self::FORMAT_JSON    => $this->renderJsonFeed($animals, $feedMeta),
            self::FORMAT_XML     => $this->renderXml($animals, $feedMeta),
            self::FORMAT_GEOJSON => $this->renderGeoJson($animals, $feedMeta),
            default => throw new \InvalidArgumentException('Neznámý formát: ' . $format),
        };
    }

    // =============================================================
    // RSS 2.0 (s GeoRSS rozšířením)
    // =============================================================

    private function renderRss(array $animals, array $feedMeta): array
    {
        $baseUrl = rtrim((string)$this->settings->get('shop.base_url', 'https://virtualniazyl.cz'), '/');
        $title = $feedMeta['title'] ?? 'Virtuální azyl - zvířata k adopci';
        $description = $feedMeta['description'] ?? 'Aktuální seznam zvířátek hledajících domov';
        $link = $feedMeta['link'] ?? $baseUrl . '/adopce';

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:geo', 'http://www.w3.org/2003/01/geo/wgs84_pos#');
        $rss->setAttribute('xmlns:georss', 'http://www.georss.org/georss');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:media', 'http://search.yahoo.com/mrss/');
        $rss->setAttribute('xmlns:vaz', $baseUrl . '/feed/schema');
        $xml->appendChild($rss);

        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($xml->createElement('title', $this->xmlSafe($title)));
        $channel->appendChild($xml->createElement('link', $this->xmlSafe($link)));
        $channel->appendChild($xml->createElement('description', $this->xmlSafe($description)));
        $channel->appendChild($xml->createElement('language', 'cs'));
        $channel->appendChild($xml->createElement('pubDate', date(DATE_RSS)));
        $channel->appendChild($xml->createElement('ttl', '60'));

        foreach ($animals as $a) {
            $item = $xml->createElement('item');
            $channel->appendChild($item);

            $titleText = sprintf('%s %s',
                $a['species'] ?? '',
                $a['name'] ? "'{$a['name']}'" : ''
            );
            $item->appendChild($xml->createElement('title', $this->xmlSafe(trim($titleText))));
            $item->appendChild($xml->createElement('link', $this->xmlSafe($a['permalink'])));

            $guid = $xml->createElement('guid', $this->xmlSafe($a['permalink']));
            $guid->setAttribute('isPermaLink', 'true');
            $item->appendChild($guid);

            // Obsah - plný popis jako HTML
            $html = $this->buildAnimalHtml($a);
            $content = $xml->createElement('content:encoded');
            $content->appendChild($xml->createCDATASection($html));
            $item->appendChild($content);

            // Krátký description
            $short = $this->truncate(strip_tags($a['description'] ?? ''), 300);
            $item->appendChild($xml->createElement('description', $this->xmlSafe($short)));

            // Obrázek přes media:content
            if (!empty($a['main_photo_url'])) {
                $media = $xml->createElement('media:content');
                $media->setAttribute('url', $a['main_photo_url']);
                $media->setAttribute('medium', 'image');
                $item->appendChild($media);
            }

            // Kategorie (druh + typ adopce)
            if (!empty($a['species'])) {
                $item->appendChild($xml->createElement('category', $this->xmlSafe($a['species'])));
            }
            if (!empty($a['adoption_type'])) {
                $cat = $xml->createElement('category', $this->xmlSafe($a['adoption_type']));
                $cat->setAttribute('domain', 'adoption_type');
                $item->appendChild($cat);
            }

            // Geo souřadnice (GeoRSS)
            if (!empty($a['lat']) && !empty($a['lon'])) {
                $point = $xml->createElement('georss:point', $a['lat'] . ' ' . $a['lon']);
                $item->appendChild($point);
                $item->appendChild($xml->createElement('geo:lat', (string)$a['lat']));
                $item->appendChild($xml->createElement('geo:long', (string)$a['lon']));
            }

            // Custom namespace s detailními daty
            $this->appendVazElement($xml, $item, 'animalId', (string)$a['id']);
            $this->appendVazElement($xml, $item, 'species', $a['species'] ?? '');
            $this->appendVazElement($xml, $item, 'breed', $a['breed'] ?? '');
            $this->appendVazElement($xml, $item, 'adoptionType', $a['adoption_type'] ?? '');
            if ($a['age_years'] !== null) {
                $this->appendVazElement($xml, $item, 'ageYears', (string)$a['age_years']);
            }
            $this->appendVazElement($xml, $item, 'azylName', $a['azyl_name'] ?? '');
            $this->appendVazElement($xml, $item, 'city', $a['city'] ?? '');
            if (isset($a['distance_km'])) {
                $this->appendVazElement($xml, $item, 'distanceKm', number_format($a['distance_km'], 1, '.', ''));
            }
        }

        return [
            'content'  => $xml->saveXML(),
            'mime'     => 'application/rss+xml; charset=utf-8',
            'filename' => 'adopce-feed.rss',
        ];
    }

    // =============================================================
    // Atom
    // =============================================================

    private function renderAtom(array $animals, array $feedMeta): array
    {
        $baseUrl = rtrim((string)$this->settings->get('shop.base_url', 'https://virtualniazyl.cz'), '/');
        $title = $feedMeta['title'] ?? 'Virtuální azyl - zvířata k adopci';
        $feedUrl = $feedMeta['self_url'] ?? $baseUrl . '/feed/adopce.atom';

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $feed = $xml->createElement('feed');
        $feed->setAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $feed->setAttribute('xmlns:georss', 'http://www.georss.org/georss');
        $xml->appendChild($feed);

        $feed->appendChild($xml->createElement('title', $this->xmlSafe($title)));

        $selfLink = $xml->createElement('link');
        $selfLink->setAttribute('rel', 'self');
        $selfLink->setAttribute('href', $feedUrl);
        $feed->appendChild($selfLink);

        $feed->appendChild($xml->createElement('id', $feedUrl));
        $feed->appendChild($xml->createElement('updated', date(DATE_ATOM)));

        $author = $xml->createElement('author');
        $author->appendChild($xml->createElement('name', 'Virtuální azyl'));
        $feed->appendChild($author);

        foreach ($animals as $a) {
            $entry = $xml->createElement('entry');
            $feed->appendChild($entry);

            $titleText = trim(sprintf('%s %s',
                $a['species'] ?? '',
                $a['name'] ? "'{$a['name']}'" : ''
            ));
            $entry->appendChild($xml->createElement('title', $this->xmlSafe($titleText)));
            $entry->appendChild($xml->createElement('id', $this->xmlSafe($a['permalink'])));
            $entry->appendChild($xml->createElement('updated', date(DATE_ATOM)));

            $link = $xml->createElement('link');
            $link->setAttribute('href', $a['permalink']);
            $entry->appendChild($link);

            $content = $xml->createElement('content');
            $content->setAttribute('type', 'html');
            $content->appendChild($xml->createCDATASection($this->buildAnimalHtml($a)));
            $entry->appendChild($content);

            if (!empty($a['lat']) && !empty($a['lon'])) {
                $entry->appendChild($xml->createElement(
                    'georss:point',
                    $a['lat'] . ' ' . $a['lon']
                ));
            }
        }

        return [
            'content'  => $xml->saveXML(),
            'mime'     => 'application/atom+xml; charset=utf-8',
            'filename' => 'adopce-feed.atom',
        ];
    }

    // =============================================================
    // JSON Feed (jsonfeed.org)
    // =============================================================

    private function renderJsonFeed(array $animals, array $feedMeta): array
    {
        $baseUrl = rtrim((string)$this->settings->get('shop.base_url', 'https://virtualniazyl.cz'), '/');

        $items = [];
        foreach ($animals as $a) {
            $item = [
                'id'              => $a['permalink'],
                'url'             => $a['permalink'],
                'title'           => trim(sprintf('%s %s',
                    $a['species'] ?? '',
                    $a['name'] ? "'{$a['name']}'" : ''
                )),
                'content_html'    => $this->buildAnimalHtml($a),
                'summary'         => $this->truncate(strip_tags($a['description'] ?? ''), 300),
                'image'           => $a['main_photo_url'],
                'tags'            => array_filter([$a['species'], $a['adoption_type']]),
                '_virtualni_azyl' => [
                    'animal_id'      => $a['id'],
                    'species'        => $a['species'],
                    'breed'          => $a['breed'],
                    'adoption_type'  => $a['adoption_type'],
                    'age_years'      => $a['age_years'],
                    'azyl_name'      => $a['azyl_name'],
                    'azyl_url'       => $a['azyl_url'],
                    'city'           => $a['city'],
                    'lat'            => $a['lat'],
                    'lon'            => $a['lon'],
                    'is_virtual_adoption' => $a['is_virtual'],
                    'distance_km'    => $a['distance_km'] ?? null,
                ],
            ];
            $items[] = $item;
        }

        $data = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $feedMeta['title'] ?? 'Virtuální azyl - zvířata k adopci',
            'description'   => $feedMeta['description'] ?? 'Aktuální seznam zvířátek hledajících domov',
            'home_page_url' => $baseUrl,
            'feed_url'      => $feedMeta['self_url'] ?? $baseUrl . '/feed/adopce.json',
            'language'      => 'cs',
            'items'         => $items,
        ];

        return [
            'content'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'mime'     => 'application/feed+json; charset=utf-8',
            'filename' => 'adopce-feed.json',
        ];
    }

    // =============================================================
    // Vlastní XML (rich struktura pro specifickou integraci)
    // =============================================================

    private function renderXml(array $animals, array $feedMeta): array
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElement('animals');
        $root->setAttribute('source', 'virtualniazyl.cz');
        $root->setAttribute('generated', date('c'));
        $root->setAttribute('count', (string)count($animals));
        $xml->appendChild($root);

        foreach ($animals as $a) {
            $animalEl = $xml->createElement('animal');
            $animalEl->setAttribute('id', (string)$a['id']);
            $root->appendChild($animalEl);

            $animalEl->appendChild($xml->createElement('name', $this->xmlSafe($a['name'] ?? '')));
            $animalEl->appendChild($xml->createElement('species', $this->xmlSafe($a['species'] ?? '')));
            $animalEl->appendChild($xml->createElement('breed', $this->xmlSafe($a['breed'] ?? '')));
            $animalEl->appendChild($xml->createElement('adoption-type', $this->xmlSafe($a['adoption_type'] ?? '')));

            $desc = $xml->createElement('description');
            $desc->appendChild($xml->createCDATASection($a['description'] ?? ''));
            $animalEl->appendChild($desc);

            if ($a['age_years'] !== null) {
                $animalEl->appendChild($xml->createElement('age-years', (string)$a['age_years']));
            }
            if (!empty($a['height_cm'])) {
                $animalEl->appendChild($xml->createElement('height-cm', (string)$a['height_cm']));
            }
            if (!empty($a['weight_kg'])) {
                $animalEl->appendChild($xml->createElement('weight-kg', (string)$a['weight_kg']));
            }
            $animalEl->appendChild($xml->createElement('permalink', $this->xmlSafe($a['permalink'])));

            // Photos
            if (!empty($a['all_photos'])) {
                $photosEl = $xml->createElement('photos');
                $photosEl->setAttribute('main', $a['main_photo_url'] ?? '');
                foreach ($a['all_photos'] as $url) {
                    $photoEl = $xml->createElement('photo');
                    $photoEl->setAttribute('url', $url);
                    $photosEl->appendChild($photoEl);
                }
                $animalEl->appendChild($photosEl);
            }

            // Azyl
            $azylEl = $xml->createElement('azyl');
            $azylEl->setAttribute('id', (string)$a['azyl_id']);
            $azylEl->appendChild($xml->createElement('name', $this->xmlSafe($a['azyl_name'] ?? '')));
            $azylEl->appendChild($xml->createElement('url', $this->xmlSafe($a['azyl_url'] ?? '')));
            if (!empty($a['city'])) {
                $azylEl->appendChild($xml->createElement('city', $this->xmlSafe($a['city'])));
            }
            if (!empty($a['country_code'])) {
                $azylEl->appendChild($xml->createElement('country-code', $a['country_code']));
            }
            if (!empty($a['lat']) && !empty($a['lon'])) {
                $locEl = $xml->createElement('location');
                $locEl->setAttribute('lat', (string)$a['lat']);
                $locEl->setAttribute('lon', (string)$a['lon']);
                if (isset($a['distance_km'])) {
                    $locEl->setAttribute('distance-km', number_format($a['distance_km'], 2, '.', ''));
                }
                $azylEl->appendChild($locEl);
            }
            $animalEl->appendChild($azylEl);
        }

        return [
            'content'  => $xml->saveXML(),
            'mime'     => 'application/xml; charset=utf-8',
            'filename' => 'adopce-feed.xml',
        ];
    }

    // =============================================================
    // GeoJSON - pro mapové aplikace
    // =============================================================

    private function renderGeoJson(array $animals, array $feedMeta): array
    {
        $features = [];
        foreach ($animals as $a) {
            if (empty($a['lat']) || empty($a['lon'])) {
                continue;
            }
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [$a['lon'], $a['lat']], // GeoJSON: lon first!
                ],
                'properties' => [
                    'id'             => $a['id'],
                    'name'           => $a['name'],
                    'species'        => $a['species'],
                    'breed'          => $a['breed'],
                    'adoption_type'  => $a['adoption_type'],
                    'description'    => $this->truncate(strip_tags($a['description'] ?? ''), 200),
                    'main_photo'     => $a['main_photo_url'],
                    'azyl_name'      => $a['azyl_name'],
                    'city'           => $a['city'],
                    'permalink'      => $a['permalink'],
                    'distance_km'    => $a['distance_km'] ?? null,
                ],
            ];
        }

        $data = [
            'type'     => 'FeatureCollection',
            'features' => $features,
        ];

        return [
            'content'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mime'     => 'application/geo+json; charset=utf-8',
            'filename' => 'adopce-feed.geojson',
        ];
    }

    // =============================================================
    // Helpery
    // =============================================================

    private function buildAnimalHtml(array $a): string
    {
        $html = '';
        if (!empty($a['main_photo_url'])) {
            $html .= sprintf('<p><img src="%s" alt="%s" style="max-width:400px;"></p>',
                htmlspecialchars($a['main_photo_url']),
                htmlspecialchars($a['name'] ?? '')
            );
        }
        $html .= '<p>';
        $html .= sprintf('<strong>%s</strong>', htmlspecialchars($a['species'] ?? ''));
        if ($a['breed']) {
            $html .= ' &middot; ' . htmlspecialchars($a['breed']);
        }
        if ($a['age_years'] !== null) {
            $html .= ' &middot; ' . $a['age_years'] . ' let';
        }
        $html .= '<br>';
        $html .= '<em>' . htmlspecialchars($a['adoption_type'] ?? '') . '</em>';
        if (!empty($a['azyl_name'])) {
            $html .= ' v ' . htmlspecialchars($a['azyl_name']);
            if (!empty($a['city'])) {
                $html .= ' (' . htmlspecialchars($a['city']) . ')';
            }
        }
        $html .= '</p>';

        if (!empty($a['description'])) {
            $html .= '<p>' . nl2br(htmlspecialchars($a['description'])) . '</p>';
        }

        $html .= sprintf(
            '<p><a href="%s"><strong>Zobrazit detail a kontakty na azyl →</strong></a></p>',
            htmlspecialchars($a['permalink'])
        );
        return $html;
    }

    private function xmlSafe(string $s): string
    {
        return $s; // DOM si s escape poradí sám
    }

    private function truncate(string $text, int $len): string
    {
        if (mb_strlen($text) <= $len) return $text;
        return mb_substr($text, 0, $len - 1) . '…';
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function appendVazElement(\DOMDocument $xml, \DOMElement $parent, string $name, string $value): void
    {
        if ($value === '') return;
        $el = $xml->createElement('vaz:' . $name, $this->xmlSafe($value));
        $parent->appendChild($el);
    }
}
