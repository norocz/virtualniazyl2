<?php
declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Tenký wrapper pro čtení system_settings.
 *
 * Používá raw DBAL aby nebyl závislý na SystemSettingsRepository
 * (který může ale nemusí existovat v projektu).
 */
class SystemSettingsReader
{
    private Connection $connection;
    /** @var array<string, string|null> In-memory cache v rámci requestu */
    private array $cache = [];
    private bool $loaded = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function get(string $key, string|float|int|null $default = null): mixed
    {
        if (!$this->loaded) {
            $this->loadAll();
        }
        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->connection->executeStatement(
            'INSERT INTO system_settings (setting_key, setting_value, created_at)
             VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE setting_value = :v',
            ['k' => $key, 'v' => $value]
        );
        $this->cache[$key] = $value;
    }

    private function loadAll(): void
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT setting_key, setting_value FROM system_settings'
            );
            foreach ($rows as $row) {
                $this->cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // tabulka neexistuje / jiná chyba - cache zůstane prázdná
        }
        $this->loaded = true;
    }
}
