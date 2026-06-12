<?php
/**
 * Migration 023: Multi-station routing (Kitchen / Bar / Coffee Bar) + offline-friendly columns.
 *
 *  - Extends admin_users.role enum to include 'bar_staff' and 'coffee_staff'.
 *  - Adds `station` ENUM('kitchen','bar','coffee_bar') to food_menu and drink_menu.
 *  - Backfills sensible defaults (food→kitchen, plain drinks→bar, coffee/tea drinks→coffee_bar).
 *  - Backfills stock_order_items.station for legacy rows so existing tickets stay routed correctly.
 *  - Adds `client_uuid` VARCHAR(64) NULL UNIQUE KEY to stock_orders so the offline queue can
 *    de-duplicate replayed POSTs (idempotency token from the POS tablet).
 *  - All steps idempotent.
 */
require_once __DIR__ . '/../../config/database.php';

function out023(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }
function colExists023(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function indexExists023(PDO $pdo, string $table, string $idx): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$table, $idx]);
    return (bool)$st->fetchColumn();
}

try {
    /* 1. Extend role enum (add bar_staff + coffee_staff) */
    $pdo->exec("ALTER TABLE admin_users MODIFY role ENUM('admin','manager','receptionist','housekeeping','accountant','viewer','restaurant_staff','chef','bar_staff','coffee_staff') NOT NULL DEFAULT 'receptionist'");
    out023('admin_users.role extended with bar_staff + coffee_staff', 'ok');

    /* 2. food_menu.station */
    if (!colExists023($pdo, 'food_menu', 'station')) {
        $pdo->exec("ALTER TABLE food_menu ADD COLUMN station ENUM('kitchen','bar','coffee_bar') NOT NULL DEFAULT 'kitchen' AFTER category");
        out023('food_menu.station added (default kitchen)', 'ok');
    } else { out023('food_menu.station exists', 'skip'); }

    /* 3. drink_menu.station */
    if (!colExists023($pdo, 'drink_menu', 'station')) {
        $pdo->exec("ALTER TABLE drink_menu ADD COLUMN station ENUM('kitchen','bar','coffee_bar') NOT NULL DEFAULT 'bar' AFTER category");
        out023('drink_menu.station added (default bar)', 'ok');
    } else { out023('drink_menu.station exists', 'skip'); }

    /* 4. Backfill drink_menu — coffee/tea/espresso/cappuccino/latte/mocha/macchiato → coffee_bar */
    $upd = $pdo->exec("UPDATE drink_menu
        SET station='coffee_bar'
        WHERE station='bar'
          AND (
                LOWER(item_name) REGEXP '(coffee|espresso|cappuccino|latte|mocha|macchiato|americano|tea|chai|hot chocolate)'
             OR LOWER(IFNULL(category,'')) REGEXP '(coffee|tea|hot)'
             OR LOWER(IFNULL(tags,'')) REGEXP '(coffee|tea|espresso|cappuccino|latte|mocha|hot)'
          )");
    out023("drink_menu.station backfilled to 'coffee_bar' for $upd row(s)", 'ok');

    /* 5. stock_order_items.station backfill (column added in mig 022) */
    if (colExists023($pdo, 'stock_order_items', 'station')) {
        $bf1 = $pdo->exec("UPDATE stock_order_items SET station='kitchen' WHERE station IS NULL AND menu_type='food'");
        out023("stock_order_items.station backfilled kitchen on $bf1 food row(s)", 'ok');
        $bf2 = $pdo->exec("
            UPDATE stock_order_items oi
            INNER JOIN drink_menu dm ON dm.id = oi.menu_item_id
            SET oi.station = dm.station
            WHERE oi.station IS NULL AND oi.menu_type='drink'
        ");
        out023("stock_order_items.station backfilled from drink_menu on $bf2 drink row(s)", 'ok');
        // Anything still NULL → bar
        $bf3 = $pdo->exec("UPDATE stock_order_items SET station='bar' WHERE station IS NULL");
        out023("stock_order_items.station fallback bar on $bf3 row(s)", 'ok');
    }

    /* 6. stock_orders.client_uuid for offline idempotency */
    if (!colExists023($pdo, 'stock_orders', 'client_uuid')) {
        $pdo->exec("ALTER TABLE stock_orders ADD COLUMN client_uuid VARCHAR(64) NULL AFTER reference");
        out023('stock_orders.client_uuid added', 'ok');
    } else { out023('stock_orders.client_uuid exists', 'skip'); }
    if (!indexExists023($pdo, 'stock_orders', 'uniq_client_uuid')) {
        try {
            $pdo->exec("ALTER TABLE stock_orders ADD UNIQUE KEY uniq_client_uuid (client_uuid)");
            out023('stock_orders.uniq_client_uuid created', 'ok');
        } catch (Throwable $e) {
            out023('uniq_client_uuid skipped: ' . $e->getMessage(), 'warn');
        }
    } else { out023('uniq_client_uuid exists', 'skip'); }

    /* 7. Make sure stock_kds_events event enum supports 'voided' (already provisioned) */
    out023('Migration 023 done.', 'done');
} catch (Throwable $e) {
    out023('FAIL: ' . $e->getMessage(), 'err');
    exit(1);
}

