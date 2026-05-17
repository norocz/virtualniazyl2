<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sledování azylů — tabulka user_azyl_follows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE user_azyl_follows (
                id          INT AUTO_INCREMENT NOT NULL,
                user_id     INT NOT NULL,
                azyl_id     INT NOT NULL,
                created_at  DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY (id),
                UNIQUE KEY UNIQ_USER_AZYL (user_id, azyl_id),
                KEY IDX_UAF_USER (user_id),
                KEY IDX_UAF_AZYL (azyl_id),
                CONSTRAINT FK_UAF_USER FOREIGN KEY (user_id) REFERENCES users  (id) ON DELETE CASCADE,
                CONSTRAINT FK_UAF_AZYL FOREIGN KEY (azyl_id) REFERENCES azyls  (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_azyl_follows');
    }
}
