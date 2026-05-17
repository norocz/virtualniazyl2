<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create azyl_co_managers table for co-manager invitation system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE azyl_co_managers (
            id INT AUTO_INCREMENT NOT NULL,
            azyl_id INT NOT NULL,
            user_id INT NOT NULL,
            invited_by_id INT NOT NULL,
            invite_token VARCHAR(64) NOT NULL,
            invited_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_azyl_co_managers_token (invite_token),
            INDEX IDX_azyl_co_managers_azyl (azyl_id),
            INDEX IDX_azyl_co_managers_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE azyl_co_managers');
    }
}
