<?php
/**
 * Migration 034: Restaurant table registry for POS table locking.
 *
 * Adds admin-managed restaurant tables with optional sitting capacity. POS uses
 * these rows as lock records so two terminals cannot open duplicate active
 * orders for the same dine-in table.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

function out034(string $message, string $tag = 'info'): void { echo "[$tag] $message\n"; }

function tableExists034(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists034(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (!tableExists034($pdo, 'restaurant_tables')) {
        $pdo->exec("CREATE TABLE restaurant_tables (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            table_number VARCHAR(50) NOT NULL,
            capacity SMALLINT UNSIGNED NULL,
            notes VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_restaurant_table_number (table_number),
            KEY idx_restaurant_tables_active_order (is_active, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        out034('restaurant_tables table created', 'ok');
    } else {
        out034('restaurant_tables table exists', 'skip');
    }

    if (!indexExists034($pdo, 'stock_orders', 'idx_stock_orders_pos_table_active')) {
        $pdo->exec("CREATE INDEX idx_stock_orders_pos_table_active ON stock_orders (order_type, table_number, status, kitchen_status)");
        out034('stock_orders table active-location index created', 'ok');
    } else {
        out034('stock_orders table active-location index exists', 'skip');
    }

    if (!indexExists034($pdo, 'stock_orders', 'idx_stock_orders_pos_room_active')) {
        $pdo->exec("CREATE INDEX idx_stock_orders_pos_room_active ON stock_orders (order_type, room_number, status, kitchen_status)");
        out034('stock_orders room active-location index created', 'ok');
    } else {
        out034('stock_orders room active-location index exists', 'skip');
    }

    $existing = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables")->fetchColumn();
    if ($existing === 0) {
        $ins = $pdo->prepare("INSERT INTO restaurant_tables (table_number, capacity, display_order) VALUES (?, NULL, ?)");
        for ($i = 1; $i <= 20; $i++) {
            $ins->execute([(string)$i, $i]);
        }
        out034('seeded default tables 1-20 without fixed capacity', 'ok');
    } else {
        out034('restaurant table seed skipped; existing rows found', 'skip');
    }

    $kv = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group)
        VALUES (?, ?, 'restaurant')
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group)");
    $kv->execute(['restaurant_table_range_start', '1']);
    $kv->execute(['restaurant_table_range_end', '20']);
    out034('restaurant table settings rows ensured', 'ok');

    out034('Migration 034 complete', 'done');
} catch (Throwable $e) {
    out034('Migration 034 FAILED: ' . $e->getMessage(), 'fail');
    exit(1);
}

