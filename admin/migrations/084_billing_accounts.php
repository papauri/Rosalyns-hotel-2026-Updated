<?php

/**
 * 084 - company pays later / on account (owner 2026-11-13, decisions D1-D6 in .claude/BUILD_PLAN.md, design in
 * .claude/COMPANY_BILLING_PLAN.md).
 *
 *   billing_accounts            the companies that pay later (billing email + CC, terms)
 *   billing_remittances         one company payment received
 *   billing_remittance_lines    how that payment was split across bookings: each line points at the normal room
 *                               payment row it created (payments is NOT altered)
 *   bookings.billing_account_id    NULL = the guest pays; set = the company pays the room stay (always, D1)
 *   bookings.billing_reference     LPO / order number
 *   bookings.billing_due_date      NULL = check-out date + the company's terms
 *   bookings.billing_covers        'stay' = extras (folio, room service) are the guest's; 'everything' = company (D1)
 *
 * Additive only: every table / column is added only when missing, no existing row is touched, re-running is safe.
 * Until this runs, company billing is hidden everywhere and the system works exactly as before.
 */

$rhBillHasTable = static function (PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
};
$rhBillHasColumn = static function (PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
};

$rhBillTables = [
    'billing_accounts' => "CREATE TABLE `billing_accounts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(150) NOT NULL,
        `contact_name` VARCHAR(150) NULL DEFAULT NULL,
        `billing_email` VARCHAR(190) NULL DEFAULT NULL,
        `billing_cc` VARCHAR(255) NULL DEFAULT NULL,
        `phone` VARCHAR(50) NULL DEFAULT NULL,
        `address` VARCHAR(255) NULL DEFAULT NULL,
        `tax_number` VARCHAR(60) NULL DEFAULT NULL,
        `terms_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `notes` VARCHAR(255) NULL DEFAULT NULL,
        `created_by` INT NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_billing_account_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'billing_remittances' => "CREATE TABLE `billing_remittances` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `billing_account_id` INT UNSIGNED NOT NULL,
        `remittance_reference` VARCHAR(40) NOT NULL,
        `received_date` DATE NOT NULL,
        `payment_method` VARCHAR(30) NOT NULL,
        `bank_reference` VARCHAR(80) NULL DEFAULT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `notes` VARCHAR(255) NULL DEFAULT NULL,
        `recorded_by` INT NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_billing_remittance_ref` (`remittance_reference`),
        KEY `idx_billing_remittance_account` (`billing_account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'billing_remittance_lines' => "CREATE TABLE `billing_remittance_lines` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `remittance_id` INT UNSIGNED NOT NULL,
        `booking_id` INT UNSIGNED NOT NULL,
        `payment_id` INT UNSIGNED NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_billing_line_payment` (`payment_id`),
        KEY `idx_billing_line_remittance` (`remittance_id`),
        KEY `idx_billing_line_booking` (`booking_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$rhBillColumns = [
    'billing_account_id' => "ADD COLUMN `billing_account_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Company that pays later (billing_accounts.id); NULL = guest pays'",
    'billing_reference'  => "ADD COLUMN `billing_reference` VARCHAR(80) NULL DEFAULT NULL COMMENT 'LPO / order number'",
    'billing_due_date'   => "ADD COLUMN `billing_due_date` DATE NULL DEFAULT NULL COMMENT 'NULL = check-out + company terms'",
    'billing_covers'     => "ADD COLUMN `billing_covers` VARCHAR(12) NULL DEFAULT NULL COMMENT 'stay = guest pays extras; everything = company pays extras too'",
];

return [
    'name' => 'billing_accounts',

    'check' => function (PDO $pdo) use ($rhBillTables, $rhBillColumns, $rhBillHasTable, $rhBillHasColumn): bool {
        foreach (array_keys($rhBillTables) as $t) {
            if (!$rhBillHasTable($pdo, $t)) {
                return false;
            }
        }
        foreach (array_keys($rhBillColumns) as $c) {
            if (!$rhBillHasColumn($pdo, 'bookings', $c)) {
                return false;
            }
        }
        return true;
    },

    'up' => function (PDO $pdo) use ($rhBillTables, $rhBillColumns, $rhBillHasTable, $rhBillHasColumn): void {
        foreach ($rhBillTables as $t => $ddl) {
            if ($rhBillHasTable($pdo, $t)) {
                continue;
            }
            $pdo->exec($ddl);
            echo "    {$t}: created\n";
        }
        foreach ($rhBillColumns as $c => $ddl) {
            if ($rhBillHasColumn($pdo, 'bookings', $c)) {
                continue;
            }
            $pdo->exec('ALTER TABLE `bookings` ' . $ddl);
            echo "    bookings.{$c}: added\n";
        }
        $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'idx_bookings_billing_account'");
        if ((int)$idx->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `bookings` ADD INDEX `idx_bookings_billing_account` (`billing_account_id`)');
            echo "    bookings.idx_bookings_billing_account: added\n";
        }
    },
];
