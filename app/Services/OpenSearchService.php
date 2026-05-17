<?php
declare(strict_types=1);

namespace App\Services;

use Tracy\Debugger;

/**
 * Vrstva nad OpenSearch HTTP API bez nutnosti composer balíčku.
 *
 * - Poskytuje základní CRUD pro indexy: vaz_animals, vaz_azyls, vaz_cities
 * - Fulltext + geo dotazy (geo_distance)
 * - Pokud je vypnuto (parameters.opensearch.enabled = false), všechny operace tiše vrací null/[]
 *   a v presenteru se dá fallbackovat na MySQL AnimalsRepository::search()
 *
 * Doporučené použití:
 *   if ($openSearchService->isEnabled()) {
 *       $hits = $openSearchService->searchAnimalsNearCity($cityId, $radiusKm);
 *   } else {
 *       $hits = $animalsRepository->search($query); // původní fallback
 *   }
 */
class OpenSearchService
{
    public const INDEX_ANIMALS = 'animals';
    public const INDEX_AZYLS = 'azyls';
    public const INDEX_CITIES = 'cities';

    private bool $enabled;
    /** @var string[] */
    private array $hosts;
    private string $username;
    private string $password;
    private string $indexPrefix;
    private bool $verifySsl;

    /**
     * @param array{enabled: bool, hosts: string[], username: string, password: string, indexPrefix: string, verifySsl: bool} $config
     */
    public function __construct(array $config)
    {
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->hosts = $config['hosts'] ?? ['http://localhost:9200'];
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->indexPrefix = $config['indexPrefix'] ?? 'vaz_';
        $this->verifySsl = (bool)($config['verifySsl'] ?? false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function indexName(string $logicalName): string
    {
        return $this->indexPrefix . $logicalName;
    }

    // ================================================================
    // Indexace
    // ================================================================

    /**
     * Uloží/aktualizuje dokument v indexu.
     * @param array<string,mixed> $document
     */
    public function indexDocument(string $index, string|int $id, array $document): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            $this->request('PUT', '/' . $this->indexName($index) . '/_doc/' . $id, $document);
            return true;
        } catch (\Throwable $e) {
            Debugger::log('OpenSearch index error: ' . $e->getMessage(), 'opensearch');
            return false;
        }
    }

    public function deleteDocument(string $index, string|int $id): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            $this->request('DELETE', '/' . $this->indexName($index) . '/_doc/' . $id);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ================================================================
    // Vyhledávání
    // ================================================================

    /**
     * Fulltext vyhledávání zvířat - kombinuje tags/description/name/breed.
     *
     * @return array<int, array<string,mixed>> pole dokumentů (source) seřazené podle relevance
     */
    public function searchAnimals(string $query, int $limit = 50): array
    {
        if (!$this->enabled) {
            return [];
        }

        $body = [
            'size' => $limit,
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => ['name^3', 'breed^2', 'tags', 'description', 'cityName^2'],
                                'fuzziness' => 'AUTO',
                            ],
                        ],
                    ],
                    'filter' => [
                        ['term' => ['toAdoption' => true]],
                        ['term' => ['isDeleted' => false]],
                    ],
                ],
            ],
        ];

        return $this->executeSearch(self::INDEX_ANIMALS, $body);
    }

    /**
     * Vyhledávání zvířat v okolí zadaného bodu.
     *
     * @return array<int, array<string,mixed>>
     */
    public function searchAnimalsNearLocation(float $lat, float $lon, float $radiusKm, ?string $query = null, int $limit = 50): array
    {
        if (!$this->enabled) {
            return [];
        }

        $must = [];
        if ($query !== null && trim($query) !== '') {
            $must[] = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['name^3', 'breed^2', 'tags', 'description'],
                    'fuzziness' => 'AUTO',
                ],
            ];
        } else {
            $must[] = ['match_all' => (object)[]];
        }

        $body = [
            'size' => $limit,
            'query' => [
                'bool' => [
                    'must' => $must,
                    'filter' => [
                        ['term' => ['toAdoption' => true]],
                        ['term' => ['isDeleted' => false]],
                        [
                            'geo_distance' => [
                                'distance' => $radiusKm . 'km',
                                'location' => ['lat' => $lat, 'lon' => $lon],
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => [
                [
                    '_geo_distance' => [
                        'location' => ['lat' => $lat, 'lon' => $lon],
                        'order' => 'asc',
                        'unit' => 'km',
                    ],
                ],
            ],
        ];

        return $this->executeSearch(self::INDEX_ANIMALS, $body);
    }

    /**
     * Autocomplete měst - pro filtr ve vyhledávání.
     *
     * @return array<int, array{id:int, name:string, region:string, lat:?float, lon:?float}>
     */
    public function suggestCities(string $prefix, int $limit = 10): array
    {
        if (!$this->enabled) {
            return [];
        }

        $body = [
            'size' => $limit,
            'query' => [
                'multi_match' => [
                    'query' => $prefix,
                    'type' => 'bool_prefix',
                    'fields' => ['name', 'name._2gram', 'name._3gram'],
                ],
            ],
        ];

        $results = $this->executeSearch(self::INDEX_CITIES, $body);
        return array_map(static fn($h) => [
            'id' => (int)($h['id'] ?? 0),
            'name' => $h['name'] ?? '',
            'region' => $h['region'] ?? '',
            'lat' => isset($h['location']['lat']) ? (float)$h['location']['lat'] : null,
            'lon' => isset($h['location']['lon']) ? (float)$h['location']['lon'] : null,
        ], $results);
    }

    // ================================================================
    // Správa indexů
    // ================================================================

    /**
     * Vytvoří indexy s mapováním pokud ještě neexistují.
     * Zavolat jednou přes konzoli (nebo z AdminPresenter::handleRebuildIndexes).
     */
    public function ensureIndexes(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->createIndexIfMissing(self::INDEX_ANIMALS, [
            'mappings' => [
                'properties' => [
                    'id'            => ['type' => 'integer'],
                    'name'          => ['type' => 'text', 'analyzer' => 'standard'],
                    'description'   => ['type' => 'text', 'analyzer' => 'standard'],
                    'breed'         => ['type' => 'text'],
                    'tags'          => ['type' => 'text'],
                    'adoptionType'  => ['type' => 'keyword'],
                    'toAdoption'    => ['type' => 'boolean'],
                    'isDeleted'     => ['type' => 'boolean'],
                    'azylId'        => ['type' => 'integer'],
                    'cityId'        => ['type' => 'integer'],
                    'cityName'      => ['type' => 'text'],
                    'location'      => ['type' => 'geo_point'], // lat/lon pro geo_distance
                    'speciesName'   => ['type' => 'keyword'],
                    'createdAt'     => ['type' => 'date'],
                ],
            ],
        ]);

        $this->createIndexIfMissing(self::INDEX_AZYLS, [
            'mappings' => [
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'azylName'    => ['type' => 'text'],
                    'description' => ['type' => 'text'],
                    'shortDescription' => ['type' => 'text'],
                    'cityId'      => ['type' => 'integer'],
                    'cityName'    => ['type' => 'text'],
                    'location'    => ['type' => 'geo_point'],
                    'ico'         => ['type' => 'keyword'],
                ],
            ],
        ]);

        $this->createIndexIfMissing(self::INDEX_CITIES, [
            'mappings' => [
                'properties' => [
                    'id'       => ['type' => 'integer'],
                    'name'     => [
                        'type' => 'search_as_you_type', // pro autocomplete
                    ],
                    'region'   => ['type' => 'keyword'],
                    'country'  => ['type' => 'keyword'],
                    'countryCode' => ['type' => 'keyword'],
                    'psc'      => ['type' => 'keyword'],
                    'location' => ['type' => 'geo_point'],
                ],
            ],
        ]);
    }

    /**
     * Smaže indexy (destructive) - pro reindex.
     */
    public function dropIndexes(): void
    {
        if (!$this->enabled) {
            return;
        }
        foreach ([self::INDEX_ANIMALS, self::INDEX_AZYLS, self::INDEX_CITIES] as $idx) {
            try {
                $this->request('DELETE', '/' . $this->indexName($idx));
            } catch (\Throwable $e) {
                // ignore - index nemusí existovat
            }
        }
    }

    // ================================================================
    // Interní HTTP vrstva
    // ================================================================

    private function createIndexIfMissing(string $logicalName, array $settings): void
    {
        $index = $this->indexName($logicalName);
        try {
            $this->request('HEAD', '/' . $index);
            return; // existuje
        } catch (\Throwable $e) {
            // vytvoříme
            $this->request('PUT', '/' . $index, $settings);
        }
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function executeSearch(string $logicalIndex, array $body): array
    {
        try {
            $response = $this->request('POST', '/' . $this->indexName($logicalIndex) . '/_search', $body);
            if (!isset($response['hits']['hits']) || !is_array($response['hits']['hits'])) {
                return [];
            }
            return array_map(static fn($hit) => array_merge(
                $hit['_source'] ?? [],
                ['_score' => $hit['_score'] ?? 0]
            ), $response['hits']['hits']);
        } catch (\Throwable $e) {
            Debugger::log('OpenSearch search error: ' . $e->getMessage(), 'opensearch');
            return [];
        }
    }

    /**
     * @return mixed
     * @throws \RuntimeException
     */
    private function request(string $method, string $path, ?array $body = null)
    {
        $host = $this->hosts[array_rand($this->hosts)];
        $url = rtrim($host, '/') . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nelze inicializovat cURL.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        if ($this->username !== '') {
            $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('OpenSearch cURL: ' . $err);
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException('OpenSearch HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
        }
        if ($method === 'HEAD') {
            return true;
        }

        $data = json_decode((string)$response, true);
        return $data ?? [];
    }
}
