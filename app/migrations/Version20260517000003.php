<?php

declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column to azyls table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azyls ADD COLUMN slug VARCHAR(255) NULL DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_azyls_slug ON azyls (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_azyls_slug ON azyls');
        $this->addSql('ALTER TABLE azyls DROP COLUMN slug');
    }
}
