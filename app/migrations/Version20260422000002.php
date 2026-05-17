<?php
declare(strict_types=1);

namespace DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eshop pro azyly:
 * - shop_products      - zboží azylu (merch + drobnosti)
 * - shop_product_photos - fotky zboží (max 10/produkt řešeno na aplikační vrstvě)
 * - shop_orders        - objednávky
 * - shop_order_items   - položky objednávky (snapshot ceny)
 * - shop_payouts       - fronta výplat azylům (po zaplacení)
 * - shop_payouts_out   - skutečně odeslané platby (vytvořené superadminem pro bankovní app)
 * - shop_refunds       - storno/vratky
 *
 * Přidává také do PaymentStatusEnum nové stavy pro eshop.
 */
final class Version20260422000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Integrovaný eshop pro azyly s QR platbami, frontou výplat a vratkami.';
    }

    public function up(Schema $schema): void
    {
        // ==================== PRODUKTY ====================
        $this->addSql("
            CREATE TABLE shop_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                azyl_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                short_description VARCHAR(512) NULL,
                description TEXT NULL,
                price DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',
                stock INT NOT NULL DEFAULT 0,
                unlimited_stock TINYINT(1) NOT NULL DEFAULT 0,
                main_photo INT NULL,
                category VARCHAR(100) NULL,
                sku VARCHAR(64) NULL,
                weight_grams INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_approved TINYINT(1) NOT NULL DEFAULT 0,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                INDEX idx_products_azyl (azyl_id),
                INDEX idx_products_active (is_active, is_approved),
                INDEX idx_products_category (category),
                FULLTEXT INDEX ft_products_search (name, short_description, description),
                CONSTRAINT fk_product_azyl FOREIGN KEY (azyl_id) REFERENCES azyls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->addSql("
            CREATE TABLE shop_product_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                path VARCHAR(512) NOT NULL,
                name VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_photos_product (product_id, sort_order),
                CONSTRAINT fk_photo_product FOREIGN KEY (product_id)
                    REFERENCES shop_products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ==================== OBJEDNÁVKY ====================
        // order_number je VS pro QR platbu (unikátní, 10 číslic)
        // user_id je NULL pro anonymní objednávku
        $this->addSql("
            CREATE TABLE shop_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(10) NOT NULL UNIQUE,
                user_id INT NULL,
                azyl_id INT NOT NULL,

                -- kontaktní údaje (pro anonymní objednávky)
                buyer_name VARCHAR(255) NOT NULL,
                buyer_email VARCHAR(255) NOT NULL,
                buyer_phone VARCHAR(32) NULL,

                -- doručovací adresa
                delivery_street VARCHAR(255) NULL,
                delivery_house_number VARCHAR(32) NULL,
                delivery_city VARCHAR(255) NULL,
                delivery_psc VARCHAR(10) NULL,
                delivery_country VARCHAR(64) NULL DEFAULT 'Česká republika',
                delivery_note TEXT NULL,

                -- částky
                items_total DECIMAL(10, 2) NOT NULL,
                shipping_cost DECIMAL(10, 2) NOT NULL DEFAULT 0,
                total_amount DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',

                -- poplatek spolku (snapshot z okamžiku vytvoření objednávky)
                fee_percent DECIMAL(5, 2) NOT NULL DEFAULT 5.00,
                fee_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
                payout_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,

                -- stavy
                order_status ENUM(
                    'new',          -- čeká na zaplacení
                    'paid',         -- zaplaceno (z PaymentsIn spárováno s VS)
                    'accepted',     -- azyl přijal objednávku
                    'shipped',      -- azyl odeslal
                    'delivered',    -- doručeno
                    'cancelled',    -- storno před platbou
                    'refunded',     -- vratka peněz zpět
                    'problem'       -- problém (k řešení superadminem)
                ) NOT NULL DEFAULT 'new',

                payment_received_at DATETIME NULL,
                accepted_at DATETIME NULL,
                shipped_at DATETIME NULL,
                shipping_tracking VARCHAR(100) NULL,
                delivered_at DATETIME NULL,

                -- platební info - kdy expiruje nezaplacená objednávka
                expires_at DATETIME NOT NULL,

                internal_note TEXT NULL,           -- poznámky pro azyl/spolek
                preferred_language VARCHAR(5) NULL DEFAULT 'cs',

                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,

                INDEX idx_orders_azyl (azyl_id),
                INDEX idx_orders_user (user_id),
                INDEX idx_orders_status (order_status),
                INDEX idx_orders_number (order_number),
                INDEX idx_orders_email (buyer_email),
                INDEX idx_orders_expires (expires_at, order_status),
                CONSTRAINT fk_order_azyl FOREIGN KEY (azyl_id) REFERENCES azyls(id),
                CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->addSql("
            CREATE TABLE shop_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_id INT NULL,

                -- snapshot produktu (pro případ že se smaže/změní)
                product_name VARCHAR(255) NOT NULL,
                unit_price DECIMAL(10, 2) NOT NULL,
                quantity INT NOT NULL,
                subtotal DECIMAL(10, 2) NOT NULL,
                product_photo_path VARCHAR(512) NULL,

                INDEX idx_items_order (order_id),
                INDEX idx_items_product (product_id),
                CONSTRAINT fk_item_order FOREIGN KEY (order_id)
                    REFERENCES shop_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_product FOREIGN KEY (product_id)
                    REFERENCES shop_products(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ==================== PAYOUTS (FRONTA VÝPLAT AZYLŮM) ====================
        // Každá zaplacená objednávka vytvoří záznam v shop_payouts
        // SuperAdmin pak vybere záznamy a vytvoří z nich bulk paymentsOut
        $this->addSql("
            CREATE TABLE shop_payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL UNIQUE,     -- 1:1 s objednávkou
                azyl_id INT NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                fee_amount DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',

                -- stav v queue
                payout_status ENUM(
                    'pending',      -- čeká na zařazení do platebního příkazu
                    'queued',       -- zařazeno do platebního příkazu (batch)
                    'sent',         -- platba odeslána z banky
                    'cancelled',    -- zrušeno (např. kvůli vratce)
                    'on_hold'       -- superadmin pozastavil (spor, problém)
                ) NOT NULL DEFAULT 'pending',

                -- bankovní údaje azylu v okamžiku vytvoření (snapshot)
                azyl_bank_account VARCHAR(64) NULL,
                azyl_bank_code VARCHAR(8) NULL,

                batch_id INT NULL,                -- odkaz na shop_payout_batches
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                queued_at DATETIME NULL,
                sent_at DATETIME NULL,

                INDEX idx_payouts_status (payout_status),
                INDEX idx_payouts_azyl (azyl_id),
                INDEX idx_payouts_batch (batch_id),
                CONSTRAINT fk_payout_order FOREIGN KEY (order_id)
                    REFERENCES shop_orders(id),
                CONSTRAINT fk_payout_azyl FOREIGN KEY (azyl_id)
                    REFERENCES azyls(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Batche - skupiny vyplat, které se zpracovávají najednou
        // SuperAdmin vybere batch, vyexportuje do formátu pro banku, pak označí jako odeslané
        $this->addSql("
            CREATE TABLE shop_payout_batches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_number VARCHAR(20) NOT NULL UNIQUE,
                created_by INT NOT NULL,
                total_amount DECIMAL(12, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',
                item_count INT NOT NULL,

                batch_status ENUM(
                    'draft',        -- rozpracováno, lze ještě editovat
                    'exported',     -- exportováno do formátu pro banku
                    'sent',         -- potvrzeno že odesláno z banky
                    'cancelled'
                ) NOT NULL DEFAULT 'draft',

                export_format VARCHAR(20) NULL,   -- 'abo', 'csv_fio', 'sepa', ...
                exported_at DATETIME NULL,
                sent_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,

                INDEX idx_batches_status (batch_status),
                CONSTRAINT fk_batch_user FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ==================== VRATKY / REFUNDS ====================
        // Když se stornuje objednávka po zaplacení, musí se poslat peníze zpět
        $this->addSql("
            CREATE TABLE shop_refunds (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                payments_in_id INT NULL,        -- odkaz na původní příchozí platbu

                amount DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'CZK',

                -- kam vrátit - obvykle zpět na protiúčet z PaymentsIn
                refund_account VARCHAR(64) NOT NULL,
                refund_bank_code VARCHAR(8) NULL,
                refund_receiver_name VARCHAR(255) NULL,

                reason VARCHAR(512) NOT NULL,
                initiated_by ENUM('buyer', 'azyl', 'admin', 'system') NOT NULL,
                initiated_by_user_id INT NULL,

                refund_status ENUM(
                    'pending',
                    'queued',
                    'sent',
                    'cancelled'
                ) NOT NULL DEFAULT 'pending',

                batch_id INT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL,

                INDEX idx_refunds_status (refund_status),
                INDEX idx_refunds_order (order_id),
                CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES shop_orders(id),
                CONSTRAINT fk_refund_user FOREIGN KEY (initiated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ==================== ROZŠÍŘENÍ EXISTUJÍCÍCH TABULEK ====================
        // Payments - propojení s objednávkou
        $this->addSql("
            ALTER TABLE payments
            ADD COLUMN shop_order_id INT NULL AFTER adoption_id,
            ADD INDEX idx_payments_shop_order (shop_order_id),
            ADD CONSTRAINT fk_payment_shop_order FOREIGN KEY (shop_order_id)
                REFERENCES shop_orders(id) ON DELETE SET NULL
        ");

        // PaymentsIn - propojení s objednávkou po spárování
        $this->addSql("
            ALTER TABLE paymentsIn
            ADD COLUMN shop_order_id INT NULL,
            ADD COLUMN matched_at DATETIME NULL,
            ADD COLUMN match_status ENUM('unmatched', 'matched', 'unmatched_review', 'refund') NOT NULL DEFAULT 'unmatched',
            ADD INDEX idx_paymentsin_order (shop_order_id),
            ADD INDEX idx_paymentsin_match (match_status),
            ADD CONSTRAINT fk_paymentsin_order FOREIGN KEY (shop_order_id)
                REFERENCES shop_orders(id) ON DELETE SET NULL
        ");

        // ==================== SYSTÉMOVÁ NASTAVENÍ ESHOPU ====================
        // Výchozí hodnoty do system_settings (pokud tabulka existuje)
        $this->addSql("
            INSERT IGNORE INTO system_settings (setting_key, setting_value, description, created_at)
            VALUES
                ('shop.fee_percent', '5.00', 'Procento poplatku spolku z objednávky', NOW()),
                ('shop.spolek_account', '', 'Bankovní účet spolku (kam přijdou platby)', NOW()),
                ('shop.spolek_bank_code', '', 'Kód banky spolku', NOW()),
                ('shop.order_expiration_hours', '72', 'Za jak dlouho vyprší nezaplacená objednávka', NOW()),
                ('shop.max_photos_per_product', '10', 'Max počet fotek produktu', NOW()),
                ('shop.default_shipping_cost', '99.00', 'Výchozí poštovné v Kč', NOW()),
                ('shop.enabled', '1', 'Zapnout/vypnout eshop jako celek', NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE paymentsIn
            DROP FOREIGN KEY fk_paymentsin_order,
            DROP INDEX idx_paymentsin_order,
            DROP INDEX idx_paymentsin_match,
            DROP COLUMN shop_order_id,
            DROP COLUMN matched_at,
            DROP COLUMN match_status");

        $this->addSql("ALTER TABLE payments
            DROP FOREIGN KEY fk_payment_shop_order,
            DROP INDEX idx_payments_shop_order,
            DROP COLUMN shop_order_id");

        $this->addSql("DROP TABLE IF EXISTS shop_refunds");
        $this->addSql("DROP TABLE IF EXISTS shop_payouts");
        $this->addSql("DROP TABLE IF EXISTS shop_payout_batches");
        $this->addSql("DROP TABLE IF EXISTS shop_order_items");
        $this->addSql("DROP TABLE IF EXISTS shop_orders");
        $this->addSql("DROP TABLE IF EXISTS shop_product_photos");
        $this->addSql("DROP TABLE IF EXISTS shop_products");

        $this->addSql("DELETE FROM system_settings WHERE setting_key LIKE 'shop.%'");
    }
}
