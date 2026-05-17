<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Services\AdoptionsFeedService;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;

/**
 * Veřejné feedy adopcí.
 *
 * URL patterny (doporučené v RouterFactory):
 *   /feed/adopce[.<format>]              - všechny adopce, latest
 *   /feed/adopce/random[.<format>]       - náhodné
 *   /feed/adopce/near/<lat>,<lon>[.<format>]  - podle geo
 *
 * Parametry přes query string:
 *   ?limit=50
 *   ?species=Pes
 *   ?adoption_type=Virtuální adopce
 *   ?radius_km=50  (jen pro geo mode)
 *
 * CORS: endpoint vrací Access-Control-Allow-Origin: * pro veřejný přístup
 * (feed je veřejný z principu, nic citlivého nevrací).
 *
 * Cachování: HTTP cache header 10 minut (náhodný 1h, latest 10min, geo 5min).
 */
class FeedPresenter extends Presenter
{
    /** Maximální limit položek - ochrana proti DoS */
    private const HARD_LIMIT = 200;

    public function __construct(
        private readonly AdoptionsFeedService $feedService
    )
    {
        parent::__construct();
    }

    public function startup(): void
    {
        parent::startup();

        // CORS - feed je veřejný
        $response = $this->getHttpResponse();
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Content-Type');

        if ($this->getHttpRequest()->isMethod('OPTIONS')) {
            $response->setCode(204);
            $this->terminate();
        }
    }

    // =============================================================
    // /feed/adopce  nebo  /feed/adopce.rss
    // =============================================================

    public function actionAdopce(string $format = 'rss'): void
    {
        $params = $this->parseCommonParams();
        $params['mode'] = AdoptionsFeedService::MODE_LATEST;

        $this->renderFeed($format, $params, [
            'title'       => 'Virtuální azyl - nejnovější zvířátka k adopci',
            'description' => 'Nejnovější zvířátka hledající domov na virtualniazyl.cz',
            'self_url'    => $this->getHttpRequest()->getUrl()->getAbsoluteUrl(),
        ], cacheMinutes: 10);
    }

    // =============================================================
    // /feed/adopce/random
    // =============================================================

    public function actionRandom(string $format = 'rss'): void
    {
        $params = $this->parseCommonParams();
        $params['mode'] = AdoptionsFeedService::MODE_RANDOM;

        $this->renderFeed($format, $params, [
            'title'       => 'Virtuální azyl - náhodný výběr zvířátek',
            'description' => 'Náhodný výběr zvířátek k adopci (obnovuje se každou hodinu)',
            'self_url'    => $this->getHttpRequest()->getUrl()->getAbsoluteUrl(),
        ], cacheMinutes: 60);
    }

    // =============================================================
    // /feed/adopce/near/<lat>,<lon>
    // =============================================================

    public function actionNear(string $coords, string $format = 'rss'): void
    {
        // Parse "50.0755,14.4378"
        if (!preg_match('/^(-?\d+\.?\d*),(-?\d+\.?\d*)$/', $coords, $m)) {
            $this->error('Neplatný formát souřadnic. Použijte lat,lon (např. 50.0755,14.4378).', 400);
        }
        $lat = (float)$m[1];
        $lon = (float)$m[2];

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            $this->error('Souřadnice mimo rozsah.', 400);
        }

        $params = $this->parseCommonParams();
        $params['mode'] = AdoptionsFeedService::MODE_GEO;
        $params['lat'] = $lat;
        $params['lon'] = $lon;

        $this->renderFeed($format, $params, [
            'title'       => sprintf('Virtuální azyl - zvířátka v okolí %.4f, %.4f', $lat, $lon),
            'description' => sprintf('Zvířátka k adopci do %d km od zadaných souřadnic',
                $params['radius_km'] ?? 100),
            'self_url'    => $this->getHttpRequest()->getUrl()->getAbsoluteUrl(),
        ], cacheMinutes: 5);
    }

    // =============================================================
    // Debug / dokumentace endpoint  /feed
    // =============================================================

    public function renderDefault(): void
    {
        $this->template->title = 'Feed API - Virtuální azyl';
        $this->template->baseUrl = $this->getHttpRequest()->getUrl()->getBaseUrl();
        $this->template->setFile(__DIR__ . '/templates/Feed/default.latte');
    }

    // =============================================================
    // Interní
    // =============================================================

    private function parseCommonParams(): array
    {
        $query = $this->getHttpRequest();

        $limit = (int)$query->getQuery('limit', 50);
        $limit = max(1, min($limit, self::HARD_LIMIT));

        return [
            'limit'          => $limit,
            'species'        => $query->getQuery('species'),
            'adoption_type'  => $query->getQuery('adoption_type'),
            'radius_km'      => (int)$query->getQuery('radius_km', 100),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $feedMeta
     */
    public function renderFeed(
        string $format,
        array $params,
        array $feedMeta,
        int $cacheMinutes = 10
    ): void
    {
        // Normalizace formátu
        $format = strtolower($format);
        $allowedFormats = ['rss', 'atom', 'json', 'xml', 'geojson'];
        if (!in_array($format, $allowedFormats, true)) {
            $this->error('Neznámý formát. Podporované: ' . implode(', ', $allowedFormats), 400);
        }

        try {
            $animals = $this->feedService->getAnimals($params);
            $output = $this->feedService->render($animals, $format, $feedMeta);
        } catch (\Throwable $e) {
            \Tracy\Debugger::log('Feed render error: ' . $e->getMessage(), 'feed');
            $this->error('Chyba generování feedu: ' . $e->getMessage(), 500);
        }

        $response = $this->getHttpResponse();
        $response->setContentType(explode(';', $output['mime'])[0], 'utf-8');
        $response->setHeader('Cache-Control', 'public, max-age=' . ($cacheMinutes * 60));
        $response->setHeader('X-Feed-Items', (string)count($animals));

        echo $output['content'];
        $this->terminate();
    }
}
