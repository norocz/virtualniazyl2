<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manual pairing fields to paymentsIn table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paymentsIn
            ADD COLUMN paired_payment_id INT NULL,
            ADD COLUMN paired_at DATETIME NULL,
            ADD COLUMN paired_note VARCHAR(500) NULL,
            ADD COLUMN paired_by_user_id INT NULL,
            ADD CONSTRAINT fk_paymentsIn_payment FOREIGN KEY (paired_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_paymentsIn_user FOREIGN KEY (paired_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paymentsIn
            DROP FOREIGN KEY fk_paymentsIn_payment,
            DROP FOREIGN KEY fk_paymentsIn_user,
            DROP COLUMN paired_payment_id,
            DROP COLUMN paired_at,
            DROP COLUMN paired_note,
            DROP COLUMN paired_by_user_id
        ');
    }
}
