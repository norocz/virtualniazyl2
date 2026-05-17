<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Systém dokladů (faktury, potvrzení) pro eshop.
 *
 * Každý doklad má jednoznačné a nevratně přidělené číslo, uchovává se jeho
 * obsah jako snapshot (i když se mění data protistrany, doklad se nemění).
 *
 * Typy:
 *  - customer_receipt  = potvrzení zákazníkovi o přijetí platby za zboží azylu
 *  - commission_invoice = faktura spolku azylu za provozní poplatek
 *  - payout_statement   = výpis azylu o přijaté výplatě (informativní)
 */
final class Version20260422000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka dokladů (faktury/potvrzení) pro eshop.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE shop_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_number VARCHAR(20) NOT NULL UNIQUE,
                document_type ENUM(
                    'customer_receipt',
                    'commission_invoice',
                    'payout_statement'
                ) NOT NULL,

                -- Vazba na objednávku
                order_id INT NOT NULL,
                payout_id INT NULL,

                -- Vydavatel (issuer) - vždy spolek pro customer_receipt/commission_invoice
                issuer_name VARCHAR(255) NOT NULL,
                issuer_ico VARCHAR(20) NULL,
                issuer_dic VARCHAR(20) NULL,
                issuer_address TEXT NULL,
                issuer_account VARCHAR(64) NULL,
                issuer_bank_code VARCHAR(8) NULL,
                issuer_registration TEXT NULL,
                issuer_vat_payer TINYINT(1) NOT NULL DEFAULT 0,

                -- Příjemce (buyer/recipient)
                buyer_name VARCHAR(255) NOT NULL,
                buyer_ico VARCHAR(20) NULL,
                buyer_dic VARCHAR(20) NULL,
                buyer_address TEXT NULL,
                buyer_email VARCHAR(255) NULL,

                -- Datumy
                issued_at DATETIME NOT NULL,
                taxable_supply_date DATE NULL,
                due_date DATE NULL,
                paid_at DATETIME NULL,

                -- Částky
                subtotal DECIMAL(10, 2) NOT NULL,
                vat_rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
                vat_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
                total DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',

                -- Platba
                variable_symbol VARCHAR(20) NULL,
                payment_method VARCHAR(32) NULL,

                -- Snapshot dat (JSON) - položky, poznámky, atd.
                -- Když se později změní produkt nebo azyl, doklad zůstává stejný.
                items_json JSON NOT NULL,
                metadata_json JSON NULL,

                -- PDF cache
                pdf_path VARCHAR(512) NULL,
                pdf_generated_at DATETIME NULL,

                created_at DATETIME NOT NULL,

                INDEX idx_docs_order (order_id),
                INDEX idx_docs_type (document_type),
                INDEX idx_docs_issued (issued_at),
                INDEX idx_docs_number (document_number),
                CONSTRAINT fk_doc_order FOREIGN KEY (order_id)
                    REFERENCES shop_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Sekvence pro číslování (rok + typ + pořadové číslo)
        $this->addSql("
            CREATE TABLE shop_document_sequences (
                sequence_year INT NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                last_number INT NOT NULL DEFAULT 0,
                PRIMARY KEY (sequence_year, document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Nastavení spolku pro vystavování dokladů
        $this->addSql("
            INSERT IGNORE INTO system_settings (setting_key, setting_value, description, created_at)
            VALUES
                ('invoice.issuer_name', 'Virtuální Azyl z.s.', 'Název spolku na faktuře', NOW()),
                ('invoice.issuer_ico', '', 'IČO spolku', NOW()),
                ('invoice.issuer_dic', '', 'DIČ spolku (pokud plátce)', NOW()),
                ('invoice.issuer_address', '', 'Sídlo spolku', NOW()),
                ('invoice.issuer_registration', '', 'Údaj o registraci (spolkový rejstřík)', NOW()),
                ('invoice.issuer_vat_payer', '0', 'Je spolek plátcem DPH? (0/1)', NOW()),
                ('invoice.issuer_vat_rate', '21', 'Sazba DPH pro provize (%)', NOW()),
                ('invoice.logo_path', '', 'Cesta k logu na fakturách', NOW())
        ");

        // Do shop_orders přidáme kontakt pro fakturaci (může se lišit od dodací)
        $this->addSql("
            ALTER TABLE shop_orders
            ADD COLUMN billing_ico VARCHAR(20) NULL,
            ADD COLUMN billing_dic VARCHAR(20) NULL,
            ADD COLUMN billing_company VARCHAR(255) NULL,
            ADD COLUMN receipt_requested TINYINT(1) NOT NULL DEFAULT 1
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shop_orders
            DROP COLUMN billing_ico,
            DROP COLUMN billing_dic,
            DROP COLUMN billing_company,
            DROP COLUMN receipt_requested");
        $this->addSql("DROP TABLE IF EXISTS shop_document_sequences");
        $this->addSql("DROP TABLE IF EXISTS shop_documents");
        $this->addSql("DELETE FROM system_settings WHERE setting_key LIKE 'invoice.%'");
    }
}
