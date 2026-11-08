<?php

/**
 * 076 - receipt-printer print queue (Restaurant & Bar printers, owner approval 2026-11-09).
 *
 * Additive only. Nothing here switches printing on: a hotel adds its printers in admin/printers.php, and until it
 * does every print hook in the till / stations finds no printer and does nothing. Re-running is safe.
 *
 *   print_printers    one row per receipt printer: its name, how it is fed (off / cloud printer polling our server /
 *                     kitchen-screen device), the station it serves, paper (58 / 80 mm roll, or A6 / A5 / A4 sheet), what it prints,
 *                     copies, and the long random token the cloud printer authenticates with (in its URL).
 *   print_jobs        the one print queue: a ticket / receipt / test page, rendered at enqueue (the line model as
 *                     JSON), with its status (queued / sent / printed / failed / cancelled) and attempts.
 *   print_job_items   which order lines each printer has already had on a ticket. UNIQUE (printer_id, order_item_id)
 *                     is what stops a re-fired order (or a tab round added later) printing the same line twice.
 */

return [
    'name' => 'print_queue',

    'check' => function (PDO $pdo): bool {
        foreach (['print_printers', 'print_jobs', 'print_job_items'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '" . $t . "'")->rowCount() === 0) {
                return false;
            }
        }
        return true;
    },

    'up' => function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `print_printers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(80) NOT NULL,
            `mode` VARCHAR(10) NOT NULL DEFAULT 'off' COMMENT 'off | cloud | screen',
            `protocol` VARCHAR(10) NOT NULL DEFAULT 'star' COMMENT 'cloud printers only: star (CloudPRNT) | epson (Server Direct Print)',
            `star_format` VARCHAR(10) NOT NULL DEFAULT 'markup' COMMENT 'Star only: markup (bold, large) | text (plain, every model)',
            `station` VARCHAR(12) NOT NULL DEFAULT 'kitchen' COMMENT 'kitchen | bar | coffee_bar | all | receipt',
            `paper` VARCHAR(4) NOT NULL DEFAULT '80' COMMENT '58 | 80 (rolls) | a6 | a5 | a4 (sheets, kitchen-screen device only)',
            `cols` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'characters per line override; NULL = 32 for 58 mm, 48 for 80 mm',
            `prints` VARCHAR(10) NOT NULL DEFAULT 'tickets' COMMENT 'tickets | receipts | both',
            `copies` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `token` CHAR(48) NOT NULL,
            `last_seen_at` DATETIME NULL DEFAULT NULL,
            `last_status` VARCHAR(80) NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Receipt printers (cloud printer or kitchen-screen device)'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `print_jobs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `printer_id` INT UNSIGNED NOT NULL,
            `kind` VARCHAR(16) NOT NULL COMMENT 'kitchen_ticket | receipt | test',
            `order_id` INT UNSIGNED NULL DEFAULT NULL,
            `order_ref` VARCHAR(50) NULL DEFAULT NULL,
            `title` VARCHAR(120) NOT NULL DEFAULT '',
            `payload` MEDIUMTEXT NOT NULL COMMENT 'JSON line model rendered at enqueue; encoders turn it into Star / Epson / HTML',
            `status` VARCHAR(12) NOT NULL DEFAULT 'queued' COMMENT 'queued | sent | printed | failed | cancelled',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `dedupe_key` VARCHAR(40) NULL DEFAULT NULL,
            `last_error` VARCHAR(160) NULL DEFAULT NULL,
            `claimed_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `printed_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_print_jobs_queue` (`printer_id`, `status`, `id`),
            KEY `idx_print_jobs_order` (`order_id`),
            UNIQUE KEY `uq_print_jobs_dedupe` (`printer_id`, `dedupe_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Print queue: one row per ticket / receipt / test page per printer'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `print_job_items` (
            `job_id` BIGINT UNSIGNED NOT NULL,
            `printer_id` INT UNSIGNED NOT NULL,
            `order_item_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`printer_id`, `order_item_id`),
            KEY `idx_print_job_items_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Order lines already on a ticket, per printer (stops double printing)'");
    },
];
