<?php
/**
 * Migration 035 — Dynamic Pricing & Room Packages
 *
 * Creates the tables needed for dynamic pricing rules and room packages:
 *  1. rate_plans      — flexible pricing rules (seasonal, weekend, LOS, last-minute, early-bird)
 *  2. room_packages   — bookable add-on packages (breakfast, romance, corporate, etc.)
 *  3. booking_packages— junction table: which packages were added to a booking
 *
 * Also alters bookings to store the applied rate plan and total package cost.
 *
 * Idempotent: safe to re-run.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

function colExists035(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
function tableExists035(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function out035(string $msg, string $tag = 'info'): void { echo "[$tag] $msg\n"; }

try {

    /* --------------------------------------------------------
     * 1. rate_plans
     * -------------------------------------------------------- */
    if (!tableExists035($pdo, 'rate_plans')) {
        $pdo->exec("
            CREATE TABLE rate_plans (
                id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                name            VARCHAR(100)    NOT NULL,
                description     TEXT            NULL,
                rule_type       ENUM('seasonal','weekend','los_discount','last_minute','early_bird','promotion')
                                NOT NULL,
                -- Seasonal: inclusive date range the plan applies to
                start_date      DATE            NULL,
                end_date        DATE            NULL,
                -- Weekend: JSON array of day-of-week numbers (0=Sun … 6=Sat)
                days_of_week    VARCHAR(20)     NULL,
                -- Length-of-stay discount
                min_nights      TINYINT UNSIGNED NULL,
                max_nights      TINYINT UNSIGNED NULL,
                -- Last-minute (book close to arrival) or Early-bird (book far ahead)
                days_before_min SMALLINT UNSIGNED NULL,
                days_before_max SMALLINT UNSIGNED NULL,
                -- Price adjustment
                adjustment_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
                adjustment_value DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
                -- Scope
                applies_to      ENUM('all','room_types') NOT NULL DEFAULT 'all',
                room_type_ids   TEXT            NULL,
                -- Control
                priority        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                is_stacking     TINYINT(1)      NOT NULL DEFAULT 0,
                is_active       TINYINT(1)      NOT NULL DEFAULT 1,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        out035('rate_plans table created', 'ok');
    } else {
        out035('rate_plans table exists', 'skip');
    }

    /* --------------------------------------------------------
     * 2. room_packages
     * -------------------------------------------------------- */
    if (!tableExists035($pdo, 'room_packages')) {
        $pdo->exec("
            CREATE TABLE room_packages (
                id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                name                VARCHAR(100)    NOT NULL,
                slug                VARCHAR(100)    NOT NULL,
                description         TEXT            NULL,
                short_description   VARCHAR(255)    NULL,
                icon                VARCHAR(60)     NULL,
                price_type          ENUM('per_night','per_stay','per_person_per_night')
                                    NOT NULL DEFAULT 'per_night',
                price_amount        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
                inclusions          TEXT            NULL,
                applies_to          ENUM('all','room_types') NOT NULL DEFAULT 'all',
                room_type_ids       TEXT            NULL,
                is_featured         TINYINT(1)      NOT NULL DEFAULT 0,
                is_active           TINYINT(1)      NOT NULL DEFAULT 1,
                sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY  uq_pkg_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        out035('room_packages table created', 'ok');
    } else {
        out035('room_packages table exists', 'skip');
    }

    /* --------------------------------------------------------
     * 3. booking_packages  (junction)
     * -------------------------------------------------------- */
    if (!tableExists035($pdo, 'booking_packages')) {
        $pdo->exec("
            CREATE TABLE booking_packages (
                id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                booking_id      INT UNSIGNED    NOT NULL,
                package_id      INT UNSIGNED    NOT NULL,
                package_name    VARCHAR(100)    NOT NULL,
                price_type      ENUM('per_night','per_stay','per_person_per_night') NOT NULL,
                price_amount    DECIMAL(10,2)   NOT NULL,
                quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                total_cost      DECIMAL(10,2)   NOT NULL,
                created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_bp_booking (booking_id),
                CONSTRAINT fk_bp_booking
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        out035('booking_packages table created', 'ok');
    } else {
        out035('booking_packages table exists', 'skip');
    }

    /* --------------------------------------------------------
     * 4. bookings — add rate-plan + package columns
     * -------------------------------------------------------- */
    $bookingCols = [
        'rate_plan_id'      => "ALTER TABLE bookings ADD COLUMN rate_plan_id INT UNSIGNED NULL DEFAULT NULL AFTER client_uuid",
        'rate_plan_label'   => "ALTER TABLE bookings ADD COLUMN rate_plan_label VARCHAR(120) NULL DEFAULT NULL AFTER rate_plan_id",
        'rate_plan_discount'=> "ALTER TABLE bookings ADD COLUMN rate_plan_discount DECIMAL(10,2) NULL DEFAULT NULL AFTER rate_plan_label",
        'package_total'     => "ALTER TABLE bookings ADD COLUMN package_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER rate_plan_discount",
    ];
    foreach ($bookingCols as $col => $sql) {
        if (!colExists035($pdo, 'bookings', $col)) {
            $pdo->exec($sql);
            out035("bookings.{$col} added", 'ok');
        } else {
            out035("bookings.{$col} exists", 'skip');
        }
    }

    echo "\n[done] Migration 035 complete.\n";

} catch (PDOException $e) {
    echo "[error] " . $e->getMessage() . "\n";
    exit(1);
}
