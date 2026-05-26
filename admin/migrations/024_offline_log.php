<?php
/**
 * Migration 024 — Offline replay log
 *
 *  - Adds offline_queued_at + offline_replayed_at to stock_orders so we can
 *    visualise the lag between the queued action (offline) and its actual
 *    server-side processing (when the device reconnected).
 *  - Creates offline_replay_log: a generic audit trail for ALL offline-queued
 *    POSTs (orders, payments, KDS bumps, void requests, anything carrying a
 *    client_uuid + client_queued_at). Lets admins answer:
 *       "What happened while connectivity was lost, and exactly when?"
 *
 *  - Adds a permission key 'offline_log_view' wired in permissions.php.
 *
 * Idempotent: safe to re-run.
 */
require_once __DIR__ . '/../../config/database.php';

function colExists024(PDO $pdo, string $table, string $column) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
function tableExists024(PDO $pdo, string $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function out024(string $msg, string $tag = 'info') { echo "[$tag] $msg\n"; }

try {
    /* 1. stock_orders: offline timestamps */
    foreach (['offline_queued_at', 'offline_replayed_at'] as $col) {
        if (!colExists024($pdo, 'stock_orders', $col)) {
            $pdo->exec("ALTER TABLE stock_orders ADD COLUMN $col DATETIME NULL DEFAULT NULL");
            out024("stock_orders.$col added", 'ok');
        } else { out024("stock_orders.$col exists", 'skip'); }
    }

    /* 2. offline_replay_log: generic audit table */
    if (!tableExists024($pdo, 'offline_replay_log')) {
        $pdo->exec("CREATE TABLE offline_replay_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_uuid VARCHAR(64) NOT NULL,
            user_id INT NULL,
            username VARCHAR(64) NULL,
            endpoint VARCHAR(255) NOT NULL,
            action VARCHAR(64) NULL,
            entity_type VARCHAR(48) NULL,
            entity_id INT NULL,
            entity_reference VARCHAR(64) NULL,
            client_queued_at DATETIME NULL,
            replayed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            response_status SMALLINT NULL,
            response_summary VARCHAR(500) NULL,
            details_json TEXT NULL,
            INDEX idx_client_uuid (client_uuid),
            INDEX idx_user (user_id),
            INDEX idx_replayed (replayed_at),
            INDEX idx_endpoint (endpoint(60))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        out024('offline_replay_log table created', 'ok');
    } else { out024('offline_replay_log exists', 'skip'); }

    out024('Migration 024 complete', 'done');
} catch (Throwable $e) {
    out024('Migration 024 FAILED: ' . $e->getMessage(), 'fail');
    exit(1);
}
