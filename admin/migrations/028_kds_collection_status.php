<?php
/**
 * Migration 028: KDS Collection Status + Deferred Stock Deduction.
 *
 * Changes:
 *  1. Adds 'collection' to stock_order_items.kds_status enum.
 *     New flow: pending → preparing → ready (stock deducts here) → collection → served
 *  2. Adds stock_deducted TINYINT(1) to stock_order_items.
 *     0 = no deduction yet, 1 = stock has been deducted for this line.
 *  3. Backfills: existing 'ready', 'served', 'void' lines get stock_deducted=1
 *     because they were deducted at order-creation time under the old flow.
 *  4. Adds 'collected' event to stock_kds_events.event — no schema change needed
 *     (event is VARCHAR, not enum).
 *
 * All steps idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config/database.php';

function out028(string $m, string $t = 'info'): void { echo "[$t] $m\n"; }

function colExists028(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

try {
    /* 1. Extend kds_status enum to include 'collection' */
    $pdo->exec("ALTER TABLE stock_order_items
        MODIFY COLUMN kds_status
        ENUM('pending','preparing','ready','collection','served','void')
        NOT NULL DEFAULT 'pending'");
    out028("stock_order_items.kds_status enum extended with 'collection'", 'ok');

    /* 2. Add stock_deducted column */
    if (!colExists028($pdo, 'stock_order_items', 'stock_deducted')) {
        $pdo->exec("ALTER TABLE stock_order_items
            ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0
            AFTER bumped_by");
        out028('stock_order_items.stock_deducted column added', 'ok');
    } else {
        out028('stock_order_items.stock_deducted already exists', 'skip');
    }

    /* 3. Backfill: items that were deducted at order-creation time */
    $affected = $pdo->exec("
        UPDATE stock_order_items
        SET stock_deducted = 1
        WHERE kds_status IN ('ready','collection','served','void')
          AND stock_deducted = 0
    ");
    out028("Backfilled {$affected} existing item rows with stock_deducted=1", 'ok');

    /* 4. Verify */
    $st = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='stock_order_items'
          AND COLUMN_NAME='kds_status'");
    $enum = $st->fetchColumn();
    out028("kds_status type: {$enum}", 'info');

    echo "\nMigration 028 complete.\n";

} catch (Throwable $e) {
    echo "[error] " . $e->getMessage() . "\n";
    exit(1);
}
