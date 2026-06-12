<?php
/**
 * Migration 041 - Room combinations and booking room ledger
 * Adds per-room capacity overrides, joined-room definitions, and a physical-room ledger per booking.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
/** @var PDO $pdo */

function rh041ColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function rh041IndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!rh041ColumnExists($pdo, 'individual_rooms', 'max_guests_override')) {
    $pdo->exec("ALTER TABLE individual_rooms ADD COLUMN max_guests_override INT UNSIGNED NULL DEFAULT NULL AFTER status");
}

if (!rh041ColumnExists($pdo, 'bookings', 'room_combination_id')) {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN room_combination_id INT UNSIGNED NULL DEFAULT NULL AFTER individual_room_id");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS room_combinations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        combined_name VARCHAR(160) NOT NULL,
        combined_room_type_id INT UNSIGNED NOT NULL,
        room_a_id INT UNSIGNED NOT NULL,
        room_b_id INT UNSIGNED NOT NULL,
        price_override DECIMAL(12,2) NULL DEFAULT NULL,
        max_guests_combined INT UNSIGNED NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_room_pair (room_a_id, room_b_id),
        KEY idx_combined_room_type (combined_room_type_id),
        KEY idx_room_a (room_a_id),
        KEY idx_room_b (room_b_id),
        KEY idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Joined physical rooms bookable as one room product'
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS booking_rooms (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id INT UNSIGNED NOT NULL,
        individual_room_id INT UNSIGNED NOT NULL,
        room_combination_id INT UNSIGNED NULL DEFAULT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        released_at DATETIME NULL DEFAULT NULL,
        status_snapshot VARCHAR(50) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_booking_room_active (booking_id, individual_room_id),
        KEY idx_booking (booking_id),
        KEY idx_individual_room (individual_room_id),
        KEY idx_room_combination (room_combination_id),
        KEY idx_released_at (released_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Physical room assignments per booking, including joined rooms'
");

if (!rh041IndexExists($pdo, 'bookings', 'idx_bookings_room_combination')) {
    $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bookings_room_combination (room_combination_id)");
}

if (!rh041IndexExists($pdo, 'individual_rooms', 'idx_individual_rooms_capacity_override')) {
    $pdo->exec("ALTER TABLE individual_rooms ADD INDEX idx_individual_rooms_capacity_override (max_guests_override)");
}

$pdo->exec("
    INSERT IGNORE INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, status_snapshot)
    SELECT id, individual_room_id, room_combination_id, 1, status
    FROM bookings
    WHERE individual_room_id IS NOT NULL
");

echo "Migration 041 complete: room combinations and booking room ledger are ready.\n";

