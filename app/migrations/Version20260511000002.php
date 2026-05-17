<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Z&N lost/found animals system tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lost_animals (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            species_id INT NOT NULL,
            sex VARCHAR(20) NULL,
            name VARCHAR(100) NULL,
            aliases VARCHAR(255) NULL,
            breed VARCHAR(150) NULL,
            color VARCHAR(100) NULL,
            description LONGTEXT NOT NULL,
            event_description LONGTEXT NOT NULL,
            has_chip TINYINT(1) NOT NULL DEFAULT 0,
            chip_number VARCHAR(50) NULL,
            has_tattoo TINYINT(1) NOT NULL DEFAULT 0,
            tattoo_value VARCHAR(50) NULL,
            special_marks VARCHAR(255) NULL,
            location VARCHAR(255) NOT NULL,
            city VARCHAR(100) NULL,
            lat DECIMAL(10,7) NULL,
            lon DECIMAL(10,7) NULL,
            lost_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            status VARCHAR(20) NOT NULL DEFAULT \'searching\',
            secret_token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_LA_TOKEN (secret_token),
            INDEX IDX_LA_USER (user_id),
            INDEX IDX_LA_SPECIES (species_id),
            INDEX IDX_LA_STATUS (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('CREATE TABLE found_animals (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NULL,
            reporter_name VARCHAR(100) NULL,
            reporter_email VARCHAR(150) NULL,
            reporter_phone VARCHAR(30) NULL,
            is_email_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            confirm_token VARCHAR(64) NULL,
            species_id INT NOT NULL,
            sex VARCHAR(20) NULL,
            breed VARCHAR(150) NULL,
            color VARCHAR(100) NULL,
            description LONGTEXT NOT NULL,
            location VARCHAR(255) NOT NULL,
            city VARCHAR(100) NULL,
            lat DECIMAL(10,7) NULL,
            lon DECIMAL(10,7) NULL,
            found_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            note LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'open\',
            secret_token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_FA_TOKEN (secret_token),
            INDEX IDX_FA_USER (user_id),
            INDEX IDX_FA_SPECIES (species_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('CREATE TABLE animal_sightings (
            id INT AUTO_INCREMENT NOT NULL,
            lost_animal_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            message LONGTEXT NOT NULL,
            location VARCHAR(255) NULL,
            lat DECIMAL(10,7) NULL,
            lon DECIMAL(10,7) NULL,
            contact_name VARCHAR(100) NULL,
            contact_email VARCHAR(150) NOT NULL,
            contact_phone VARCHAR(30) NULL,
            is_notified TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_AS_LOST (lost_animal_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('ALTER TABLE lost_animals
            ADD CONSTRAINT FK_LA_USER FOREIGN KEY (user_id) REFERENCES users(id),
            ADD CONSTRAINT FK_LA_SPECIES FOREIGN KEY (species_id) REFERENCES species(id)');

        $this->addSql('ALTER TABLE found_animals
            ADD CONSTRAINT FK_FA_USER FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_FA_SPECIES FOREIGN KEY (species_id) REFERENCES species(id)');

        $this->addSql('ALTER TABLE animal_sightings
            ADD CONSTRAINT FK_AS_LOST FOREIGN KEY (lost_animal_id) REFERENCES lost_animals(id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE photos
            ADD COLUMN lost_animal_id INT NULL,
            ADD COLUMN found_animal_id INT NULL,
            ADD CONSTRAINT FK_PHOTO_LOST FOREIGN KEY (lost_animal_id) REFERENCES lost_animals(id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_PHOTO_FOUND FOREIGN KEY (found_animal_id) REFERENCES found_animals(id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photos DROP FOREIGN KEY FK_PHOTO_LOST, DROP FOREIGN KEY FK_PHOTO_FOUND, DROP COLUMN lost_animal_id, DROP COLUMN found_animal_id');
        $this->addSql('DROP TABLE animal_sightings');
        $this->addSql('DROP TABLE found_animals');
        $this->addSql('DROP TABLE lost_animals');
    }
}
