<?php
declare(strict_types=1);

namespace App\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
use Tracy\Debugger;

/**
 * Geokódovací služba využívající OpenStreetMap Nominatim API.
 *
 * - ZDARMA, bez registrace
 * - Limit 1 request/sec (respektujeme)
 * - Vyžaduje unikátní User-Agent s kontaktem
 * - Výsledky cachujeme navždy (souřadnice města se nemění)
 */
class NominatimService
{
    private Cache $cache;
    private string $endpoint;
    private string $userAgent;
    private int $rateLimit;
    private string $language;

    /**
     * @param array{endpoint: string, userAgent: string, rateLimit: int, language: string} $config
     */
    public function __construct(array $config, string $cacheDir)
    {
        $this->endpoint = rtrim($config['endpoint'] ?? 'https://nominatim.openstreetmap.org', '/');
        $this->userAgent = $config['userAgent'] ?? 'VirtualniAzyl/1.0';
        $this->rateLimit = (int)($config['rateLimit'] ?? 1);
        $this->language = $config['language'] ?? 'cs';

        $storage = new FileStorage($cacheDir);
        $this->cache = new Cache($storage, 'nominatim');
    }

    /**
     * Vrací souřadnice pro město.
     *
     * @param string $cityName   Název města
     * @param string $countryCode ISO kód země (CZ, SK, DE, ...)
     * @param string|null $psc   PSČ pro přesnější match (nepovinné)
     * @return array{lat: float, lon: float, displayName: string}|null null pokud nenalezeno
     */
    public function geocodeCity(string $cityName, string $countryCode, ?string $psc = null): ?array
    {
        $cacheKey = 'city-' . md5($cityName . '|' . $countryCode . '|' . ($psc ?? ''));
        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'format' => 'json',
            'city' => $cityName,
            'country' => $countryCode,
            'limit' => 1,
            'accept-language' => $this->language,
        ];
        if (!empty($psc)) {
            $params['postalcode'] = $psc;
        }

        try {
            $response = $this->request('/search', $params);
            if (empty($response) || !isset($response[0]['lat'], $response[0]['lon'])) {
                // uložíme i negativní výsledek krátkodobě, ať netlučeme API
                $this->cache->save($cacheKey, null, [Cache::Expire => '1 day']);
                return null;
            }

            $result = [
                'lat' => (float)$response[0]['lat'],
                'lon' => (float)$response[0]['lon'],
                'displayName' => $response[0]['display_name'] ?? '',
            ];

            // Souřadnice města jsou stabilní - cachujeme na dlouho
            $this->cache->save($cacheKey, $result, [Cache::Expire => '180 days']);
            return $result;
        } catch (\Throwable $e) {
            Debugger::log('NominatimService geocodeCity error: ' . $e->getMessage(), 'nominatim');
            return null;
        }
    }

    /**
     * Geokódování volným dotazem (název organizace, adresa, kombinace).
     *
     * @param string $query        Volný dotaz, např. "Azyl Brno", "Náměstí 1, 60200 Brno"
     * @param string $countryCode  ISO kód země
     * @param string|null $postalCode  PSČ pro přesnější match
     * @return array{lat: float, lon: float, displayName: string}|null
     */
    public function geocodeQuery(string $query, string $countryCode = 'CZ', ?string $postalCode = null): ?array
    {
        $cacheKey = 'query-' . md5($query . '|' . $countryCode . '|' . ($postalCode ?? ''));
        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'format'          => 'json',
            'q'               => $query,
            'countrycodes'    => strtolower($countryCode),
            'limit'           => 1,
            'accept-language' => $this->language,
            'addressdetails'  => 0,
        ];
        if (!empty($postalCode)) {
            $params['postalcode'] = $postalCode;
        }

        try {
            $response = $this->request('/search', $params);
            if (empty($response) || !isset($response[0]['lat'], $response[0]['lon'])) {
                $this->cache->save($cacheKey, null, [Cache::Expire => '1 day']);
                return null;
            }

            $result = [
                'lat'         => (float)$response[0]['lat'],
                'lon'         => (float)$response[0]['lon'],
                'displayName' => $response[0]['display_name'] ?? '',
            ];

            $this->cache->save($cacheKey, $result, [Cache::Expire => '180 days']);
            return $result;
        } catch (\Throwable $e) {
            Debugger::log('NominatimService geocodeQuery error: ' . $e->getMessage(), 'nominatim');
            return null;
        }
    }

    /**
     * Reverzní geokódování - ze souřadnic na město/adresu.
     *
     * @return array{city: string|null, country: string|null, countryCode: string|null, postcode: string|null, displayName: string}|null
     */
    public function reverseGeocode(float $lat, float $lon): ?array
    {
        $cacheKey = 'rev-' . md5($lat . '|' . $lon);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->request('/reverse', [
                'format' => 'json',
                'lat' => $lat,
                'lon' => $lon,
                'accept-language' => $this->language,
            ]);

            if (empty($response) || !isset($response['address'])) {
                return null;
            }

            $address = $response['address'];
            $result = [
                'city' => $address['city']
                    ?? $address['town']
                    ?? $address['village']
                    ?? $address['municipality']
                    ?? null,
                'country' => $address['country'] ?? null,
                'countryCode' => isset($address['country_code']) ? strtoupper($address['country_code']) : null,
                'postcode' => $address['postcode'] ?? null,
                'displayName' => $response['display_name'] ?? '',
            ];

            $this->cache->save($cacheKey, $result, [Cache::Expire => '180 days']);
            return $result;
        } catch (\Throwable $e) {
            Debugger::log('NominatimService reverseGeocode error: ' . $e->getMessage(), 'nominatim');
            return null;
        }
    }

    /**
     * Vzdálenost mezi dvěma body v km (Haversine formula).
     */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * @param array<string,mixed> $params
     * @return mixed JSON-decoded odpověď
     * @throws \RuntimeException
     */
    private function request(string $path, array $params)
    {
        // Respektování rate limitu
        $lastCallKey = 'last-call';
        $lastCall = $this->cache->load($lastCallKey);
        if ($lastCall !== null) {
            $elapsed = microtime(true) - $lastCall;
            if ($elapsed < $this->rateLimit) {
                usleep((int)(($this->rateLimit - $elapsed) * 1_000_000));
            }
        }
        $this->cache->save($lastCallKey, microtime(true), [Cache::Expire => '10 seconds']);

        $url = $this->endpoint . $path . '?' . http_build_query($params);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nelze inicializovat cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('cURL selhal: ' . $err);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException('Nominatim HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Neplatný JSON: ' . json_last_error_msg());
        }

        return $data;
    }
}
