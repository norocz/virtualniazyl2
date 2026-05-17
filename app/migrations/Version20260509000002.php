<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Konfigurace eshopu per-azyl: dobrovolný příspěvek VAZ (shop_fee_percent) a téma barvy (shop_theme_color).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE azyls
            ADD COLUMN shop_fee_percent DECIMAL(5, 2) NULL COMMENT 'Dobrovolný příspěvek na VAZ v % (null = systémový default)',
            ADD COLUMN shop_theme_color VARCHAR(16) NULL COMMENT 'Barva eshopu: green | blue | orange'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE azyls DROP COLUMN shop_fee_percent, DROP COLUMN shop_theme_color");
    }
}
