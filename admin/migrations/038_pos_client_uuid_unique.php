<?php
/**
 * Migration 038 — Add UNIQUE constraint on stock_orders.client_uuid
 *
 * Enforces DB-level idempotency for POS order creation so that concurrent
 * requests carrying the same client_uuid cannot produce duplicate orders
 * even if they slip past the app-level check simultaneously.
 *
 * Safe to run multiple times (checks existence before adding).
 */

require_once __DIR__ . '/../../config/database.php';

try {
    // Check if the unique index already exists
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = 'stock_orders'
          AND index_name   = 'uq_stock_orders_client_uuid'
    ");
    $checkStmt->execute();
    if ((int)$checkStmt->fetchColumn() > 0) {
        echo "Migration 038: unique index on stock_orders.client_uuid already exists — skipped.\n";
        exit(0);
    }

    // Check for existing duplicate client_uuids that would block the constraint
    $dupStmt = $pdo->query("
        SELECT client_uuid, COUNT(*) as c
        FROM stock_orders
        WHERE client_uuid IS NOT NULL AND client_uuid != ''
        GROUP BY client_uuid
        HAVING c > 1
    ");
    $dups = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($dups) {
        echo "Migration 038 WARNING: duplicate client_uuid values found — cannot add unique index without data cleanup:\n";
        foreach ($dups as $d) {
            echo "  client_uuid=" . $d['client_uuid'] . "  count=" . $d['c'] . "\n";
        }
        echo "Resolve duplicates first, then re-run this migration.\n";
        exit(1);
    }

    // Add the unique index (NULL values are excluded from uniqueness in MySQL)
    $pdo->exec("ALTER TABLE stock_orders ADD UNIQUE INDEX uq_stock_orders_client_uuid (client_uuid)");
    echo "Migration 038: unique index uq_stock_orders_client_uuid added to stock_orders.client_uuid\n";

} catch (Throwable $e) {
    echo "Migration 038 FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
