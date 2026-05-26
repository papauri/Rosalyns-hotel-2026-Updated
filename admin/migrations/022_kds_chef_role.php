<?php
/**
 * Migration 022: Kitchen Display System (KDS) + chef role.
 *
 *  - Extends admin_users.role enum to include 'chef'.
 *  - Adds kitchen_status to stock_orders so we can track what is on KDS regardless of payment.
 *  - Adds per-line kds_status, started_at, ready_at, served_at, station, bumped_by to stock_order_items.
 *  - Creates stock_kds_events audit table (item-level state transitions for analytics & undo).
 *  - All steps idempotent.
 */
require_once __DIR__ . '/../../config/database.php';

function out022(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }

function colExists022(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function tableExists022(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

try {
    /* 1. Extend role enum */
    $pdo->exec("ALTER TABLE admin_users MODIFY role ENUM('admin','manager','receptionist','housekeeping','accountant','viewer','restaurant_staff','chef') NOT NULL DEFAULT 'receptionist'");
    out022('admin_users.role extended with chef', 'ok');

    /* 2. Order-level kitchen status */
    if (!colExists022($pdo, 'stock_orders', 'kitchen_status')) {
        $pdo->exec("ALTER TABLE stock_orders ADD COLUMN kitchen_status ENUM('none','new','in_progress','ready','served','recalled') NOT NULL DEFAULT 'none' AFTER served_at");
        out022('stock_orders.kitchen_status added', 'ok');
    } else { out022('stock_orders.kitchen_status exists', 'skip'); }

    if (!colExists022($pdo, 'stock_orders', 'fired_at')) {
        $pdo->exec("ALTER TABLE stock_orders ADD COLUMN fired_at DATETIME NULL AFTER kitchen_printed_at");
        out022('stock_orders.fired_at added', 'ok');
    } else { out022('stock_orders.fired_at exists', 'skip'); }

    /* 3. Item-level KDS state */
    foreach ([
        ['kds_status', "ENUM('pending','preparing','ready','served','void') NOT NULL DEFAULT 'pending' AFTER notes"],
        ['started_at', "DATETIME NULL AFTER kds_status"],
        ['ready_at',   "DATETIME NULL AFTER started_at"],
        ['served_at',  "DATETIME NULL AFTER ready_at"],
        ['station',    "VARCHAR(40) NULL AFTER served_at"],
        ['bumped_by',  "INT(11) NULL AFTER station"],
    ] as $c) {
        if (!colExists022($pdo, 'stock_order_items', $c[0])) {
            $pdo->exec("ALTER TABLE stock_order_items ADD COLUMN {$c[0]} {$c[1]}");
            out022("stock_order_items.{$c[0]} added", 'ok');
        } else { out022("stock_order_items.{$c[0]} exists", 'skip'); }
    }

    /* 4. KDS event log */
    if (!tableExists022($pdo, 'stock_kds_events')) {
        $pdo->exec("CREATE TABLE stock_kds_events (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) NOT NULL,
            order_item_id INT(11) NULL,
            event ENUM('fired','started','ready','served','recalled','bumped','voided') NOT NULL,
            from_status VARCHAR(20) NULL,
            to_status VARCHAR(20) NULL,
            user_id INT(11) NULL,
            user_name VARCHAR(120) NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_item (order_item_id),
            KEY idx_event (event),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        out022('stock_kds_events created', 'ok');
    } else { out022('stock_kds_events exists', 'skip'); }

    /* 5. Backfill kitchen_status='served' for old paid orders so they don't pollute KDS */
    $pdo->exec("UPDATE stock_orders SET kitchen_status='served' WHERE kitchen_status='none' AND status IN ('paid','voided','cancelled') AND created_at < (NOW() - INTERVAL 1 DAY)");
    out022('Backfilled kitchen_status=served on old historic orders', 'ok');

    out022('Migration 022 done.', 'done');
} catch (Throwable $e) {
    out022('FAIL: ' . $e->getMessage(), 'err');
    exit(1);
}
