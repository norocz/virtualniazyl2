<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event registration: enable flag on events, extend reservations (nullable user, email, name, token, waitlist status)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azyl_events
            ADD COLUMN registration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER max_participants');

        $this->addSql('ALTER TABLE azyl_event_reservations
            MODIFY user_id INT NULL,
            ADD COLUMN email VARCHAR(150) NULL AFTER user_id,
            ADD COLUMN name VARCHAR(100) NULL AFTER email,
            ADD COLUMN token VARCHAR(64) NULL AFTER name,
            ADD UNIQUE INDEX UNIQ_ER_TOKEN (token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE azyl_event_reservations
            DROP INDEX UNIQ_ER_TOKEN,
            DROP COLUMN token,
            DROP COLUMN name,
            DROP COLUMN email,
            MODIFY user_id INT NOT NULL');

        $this->addSql('ALTER TABLE azyl_events DROP COLUMN registration_enabled');
    }
}
