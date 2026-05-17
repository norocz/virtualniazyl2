<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add address and geolocation fields to azyls; ensure lat/lon on animals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azyls
            ADD COLUMN street VARCHAR(255) NULL,
            ADD COLUMN house_number VARCHAR(20) NULL,
            ADD COLUMN zip_code VARCHAR(10) NULL,
            ADD COLUMN country_code VARCHAR(5) NULL DEFAULT \'CZ\',
            ADD COLUMN latitude DECIMAL(9,6) NULL,
            ADD COLUMN longitude DECIMAL(9,6) NULL
        ');

        $this->addSql('ALTER TABLE animals
            ADD COLUMN latitude DECIMAL(9,6) NULL,
            ADD COLUMN longitude DECIMAL(9,6) NULL
        ');

        $this->addSql('CREATE INDEX idx_azyls_geo ON azyls (latitude, longitude)');
        $this->addSql('CREATE INDEX idx_animals_geo ON animals (latitude, longitude)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azyls DROP COLUMN IF EXISTS street, DROP COLUMN IF EXISTS house_number, DROP COLUMN IF EXISTS zip_code, DROP COLUMN IF EXISTS country_code');
        $this->addSql('DROP INDEX IF EXISTS idx_azyls_geo ON azyls');
        $this->addSql('DROP INDEX IF EXISTS idx_animals_geo ON animals');
    }
}
