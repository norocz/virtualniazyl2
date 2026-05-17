<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Poštovné a balné pro eshop azylu (shipping_fee, packaging_fee na tabulce azyls).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE azyls
            ADD COLUMN shipping_fee DECIMAL(8, 2) NULL COMMENT 'Poštovné za celou objednávku (Kč)',
            ADD COLUMN packaging_fee DECIMAL(8, 2) NULL COMMENT 'Balné za objednávku, počítá se jednou (Kč)'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE azyls DROP COLUMN shipping_fee, DROP COLUMN packaging_fee");
    }
}
