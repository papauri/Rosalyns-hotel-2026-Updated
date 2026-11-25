<?php

/**
 * 092 - Conference packages (owner 2026-11-26; plan .claude/CONF_PACKAGES_PLAN.md).
 *
 *   conference_packages           catalog: add-ons for conference rooms, priced per event, per day or per delegate
 *                                 per day; all rooms or chosen conference rooms.
 *   conference_inquiry_packages   the lines on an enquiry: a snapshot of name, price type and unit price taken when the
 *                                 line was added (a later catalog change never touches it), the days and delegates it
 *                                 was last priced for, and a staff flag when the enquiry moved to a room the package is
 *                                 not offered in. No FK: a deleted catalog package leaves the lines as they are.
 *   conference_inquiries          + package_total (sum of the lines; total_amount = room hire + package_total)
 *                                 + booked_daily_rate / booked_half_day_rate (room rates at booking, so a later rate
 *                                   change is not mistaken for a manual price). NULL = an enquiry from before 092.
 *
 * Additive only (CREATE TABLE IF NOT EXISTS, ADD COLUMN guarded). No existing row is updated. Re-running is safe.
 *
 * ROLLBACK (manual, never run automatically): take a backup first (php scripts/backup_database.php), never on a
 * database listed in .claude/LIVE_TARGETS without the owner asking for that exact change:
 *     DROP TABLE IF EXISTS conference_inquiry_packages;
 *     DROP TABLE IF EXISTS conference_packages;
 *     ALTER TABLE conference_inquiries DROP COLUMN package_total, DROP COLUMN booked_daily_rate, DROP COLUMN booked_half_day_rate;
 * Dropping the lines loses package detail on enquiries whose total_amount includes them: only before first use.
 */

$rhConf092Col = static function (PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'conference_packages',

    'check' => function (PDO $pdo) use ($rhConf092Col): bool {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('conference_packages', 'conference_inquiry_packages')");
        return (int)$st->fetchColumn() === 2
            && $rhConf092Col($pdo, 'conference_inquiries', 'package_total')
            && $rhConf092Col($pdo, 'conference_inquiries', 'booked_daily_rate')
            && $rhConf092Col($pdo, 'conference_inquiries', 'booked_half_day_rate');
    },

    'up' => function (PDO $pdo) use ($rhConf092Col): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS conference_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            short_description VARCHAR(255) NULL,
            description TEXT NULL,
            icon VARCHAR(60) NULL,
            price_type ENUM('per_event','per_day','per_delegate_per_day') NOT NULL DEFAULT 'per_event',
            price_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            inclusions TEXT NULL,
            applies_to ENUM('all','rooms') NOT NULL DEFAULT 'all',
            conference_room_ids TEXT NULL,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_conf_pkg_name (name),
            KEY idx_conf_pkg_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS conference_inquiry_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            inquiry_id INT NOT NULL,
            package_id INT UNSIGNED NULL,
            package_name VARCHAR(100) NOT NULL,
            price_type ENUM('per_event','per_day','per_delegate_per_day') NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            delegates SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            total_cost DECIMAL(12,2) NOT NULL,
            needs_review TINYINT(1) NOT NULL DEFAULT 0,
            review_reason VARCHAR(255) NULL,
            added_by INT UNSIGNED NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cip_line (inquiry_id, package_id),
            KEY idx_cip_inquiry (inquiry_id),
            KEY idx_cip_package (package_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!$rhConf092Col($pdo, 'conference_inquiries', 'package_total')) {
            $pdo->exec("ALTER TABLE conference_inquiries ADD COLUMN package_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
        }
        if (!$rhConf092Col($pdo, 'conference_inquiries', 'booked_daily_rate')) {
            $pdo->exec("ALTER TABLE conference_inquiries ADD COLUMN booked_daily_rate DECIMAL(10,2) NULL AFTER package_total");
        }
        if (!$rhConf092Col($pdo, 'conference_inquiries', 'booked_half_day_rate')) {
            $pdo->exec("ALTER TABLE conference_inquiries ADD COLUMN booked_half_day_rate DECIMAL(10,2) NULL AFTER booked_daily_rate");
        }
    },
];
