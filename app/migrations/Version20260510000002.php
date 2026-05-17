<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add azyl_events, azyl_event_reservations tables; add azyl_event_id to photos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE azyl_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            azyl_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            short_description VARCHAR(512) NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            date_from DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            date_to DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            recurrence_type VARCHAR(20) NOT NULL DEFAULT \'none\',
            recurrence_end_date DATE NULL COMMENT \'(DC2Type:date_immutable)\',
            max_participants INT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            header_photo_id INT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_azyl_events_azyl (azyl_id),
            INDEX idx_azyl_events_dates (date_from, date_to),
            CONSTRAINT fk_azyl_events_azyl FOREIGN KEY (azyl_id) REFERENCES azyls(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE azyl_event_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            user_id INT NOT NULL,
            occurrence_date DATE NULL COMMENT \'(DC2Type:date_immutable)\',
            participants_count INT NOT NULL DEFAULT 1,
            note TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'confirmed\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_event_res_event (event_id),
            INDEX idx_event_res_user (user_id),
            CONSTRAINT fk_event_res_event FOREIGN KEY (event_id) REFERENCES azyl_events(id),
            CONSTRAINT fk_event_res_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('ALTER TABLE photos ADD COLUMN azyl_event_id INT NULL');
        $this->addSql('ALTER TABLE photos ADD CONSTRAINT fk_photos_azyl_event FOREIGN KEY (azyl_event_id) REFERENCES azyl_events(id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photos DROP FOREIGN KEY fk_photos_azyl_event');
        $this->addSql('ALTER TABLE photos DROP COLUMN azyl_event_id');
        $this->addSql('DROP TABLE azyl_event_reservations');
        $this->addSql('DROP TABLE azyl_events');
    }
}
