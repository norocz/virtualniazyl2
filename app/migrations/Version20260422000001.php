<?php

declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Příprava na OpenSearch + vyhledávání podle měst.
 *
 * - Přidává indexy pro rychlejší fulltext vyhledávání přes MySQL fallback
 * - Přidává latitude/longitude k azylu (cache ze souřadnic města)
 * - Sloupce language pro překlady zpráv
 */
final class Version20260422000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Příprava na OpenSearch, geo souřadnice a jazykové značení zpráv.';
    }

    public function up(Schema $schema): void
    {
        // ---- Přidání jazyka do messages pro AI překlady ----
        $this->addSql("ALTER TABLE messages
            ADD COLUMN language VARCHAR(5) NULL DEFAULT 'cs' AFTER type,
            ADD COLUMN original_message TEXT NULL AFTER message,
            ADD INDEX idx_messages_language (language)");

        // ---- Denormalizace city_id a souřadnic na animals pro rychlé geo queries ----
        // (primárně pro MySQL fallback, OpenSearch si to bere z azyl->city)
        $this->addSql("ALTER TABLE animals
            ADD COLUMN city_id INT NULL AFTER azyl_id,
            ADD COLUMN latitude DECIMAL(9,6) NULL AFTER city_id,
            ADD COLUMN longitude DECIMAL(9,6) NULL AFTER latitude,
            ADD INDEX idx_animals_city (city_id),
            ADD INDEX idx_animals_location (latitude, longitude),
            ADD INDEX idx_animals_to_adoption (to_adoption, is_deleted)");

        // ---- Fulltext index na tags a description (MySQL fallback) ----
        $this->addSql("ALTER TABLE animals
            ADD FULLTEXT INDEX ft_animals_search (name, breed, tags, description)");

        // ---- Souřadnice pro azyly (cache z města) ----
        $this->addSql("ALTER TABLE azyls
            ADD COLUMN latitude DECIMAL(9,6) NULL,
            ADD COLUMN longitude DECIMAL(9,6) NULL,
            ADD INDEX idx_azyls_location (latitude, longitude),
            ADD INDEX idx_azyls_city (city)");

        // ---- Nastavení uživatelů - preferovaný jazyk ----
        $this->addSql("ALTER TABLE users
            ADD COLUMN preferred_language VARCHAR(5) NULL DEFAULT 'cs',
            ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1");

        // ---- Tabulka pro cache přeložených textů (backup k file cache) ----
        // Pomáhá když OpenAI neodpovídá a potřebujeme trvalý překlad
        $this->addSql("CREATE TABLE IF NOT EXISTS translations_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_hash VARCHAR(32) NOT NULL,
            source_language VARCHAR(5) NOT NULL,
            target_language VARCHAR(5) NOT NULL,
            source_text TEXT NOT NULL,
            translated_text TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_translation (source_hash, source_language, target_language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- Tabulka sponzorů (pokud ještě nebyla) ----
        $this->addSql("CREATE TABLE IF NOT EXISTS sponsors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            web VARCHAR(512) NULL,
            logo VARCHAR(512) NULL,
            amount INT NULL,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX idx_sponsors_visible (visible)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE messages
            DROP INDEX idx_messages_language,
            DROP COLUMN language,
            DROP COLUMN original_message");

        $this->addSql("ALTER TABLE animals
            DROP INDEX idx_animals_city,
            DROP INDEX idx_animals_location,
            DROP INDEX idx_animals_to_adoption,
            DROP INDEX ft_animals_search,
            DROP COLUMN city_id,
            DROP COLUMN latitude,
            DROP COLUMN longitude");

        $this->addSql("ALTER TABLE azyls
            DROP INDEX idx_azyls_location,
            DROP INDEX idx_azyls_city,
            DROP COLUMN latitude,
            DROP COLUMN longitude");

        $this->addSql("ALTER TABLE users
            DROP COLUMN preferred_language,
            DROP COLUMN email_notifications");

        $this->addSql("DROP TABLE IF EXISTS translations_cache");
        $this->addSql("DROP TABLE IF EXISTS sponsors");
    }
}
