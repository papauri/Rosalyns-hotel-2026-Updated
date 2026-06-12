<?php
/**
 * Migration 033 — Idempotency for bookings, payments, and generic API writes
 *
 * Goal: a network blip, a duplicate form-submit, or an offline-queue replay
 * must NEVER create two bookings or charge a guest twice. The fix is a
 * client-supplied `client_uuid` that is unique-indexed at the database level.
 *
 *  1. bookings.client_uuid VARCHAR(64) UNIQUE NULL
 *  2. payments.client_uuid VARCHAR(64) UNIQUE NULL
 *  3. idempotency_keys table  — generic per-endpoint dedup with cached
 *     response body so an exact replay returns the original 200 response.
 *
 * Idempotent: safe to re-run.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

function colExists033(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
function indexExists033(PDO $pdo, string $table, string $index): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    $st->execute([$table, $index]);
    return (int)$st->fetchColumn() > 0;
}
function tableExists033(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function out033(string $msg, string $tag = 'info'): void { echo "[$tag] $msg\n"; }

try {
    /* 1. bookings.client_uuid */
    if (!colExists033($pdo, 'bookings', 'client_uuid')) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN client_uuid VARCHAR(64) NULL DEFAULT NULL");
        out033('bookings.client_uuid added', 'ok');
    } else { out033('bookings.client_uuid exists', 'skip'); }
    if (!indexExists033($pdo, 'bookings', 'uniq_bookings_client_uuid')) {
        $pdo->exec("ALTER TABLE bookings ADD UNIQUE INDEX uniq_bookings_client_uuid (client_uuid)");
        out033('bookings.client_uuid unique index added', 'ok');
    } else { out033('bookings unique index exists', 'skip'); }

    /* 2. payments.client_uuid */
    if (!colExists033($pdo, 'payments', 'client_uuid')) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN client_uuid VARCHAR(64) NULL DEFAULT NULL");
        out033('payments.client_uuid added', 'ok');
    } else { out033('payments.client_uuid exists', 'skip'); }
    if (!indexExists033($pdo, 'payments', 'uniq_payments_client_uuid')) {
        $pdo->exec("ALTER TABLE payments ADD UNIQUE INDEX uniq_payments_client_uuid (client_uuid)");
        out033('payments.client_uuid unique index added', 'ok');
    } else { out033('payments unique index exists', 'skip'); }

    /* 3. idempotency_keys — generic per-endpoint dedup */
    if (!tableExists033($pdo, 'idempotency_keys')) {
        $pdo->exec("CREATE TABLE idempotency_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_uuid VARCHAR(64) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            request_hash CHAR(64) NULL,
            response_status SMALLINT NOT NULL DEFAULT 200,
            response_body MEDIUMTEXT NULL,
            entity_type VARCHAR(48) NULL,
            entity_id INT NULL,
            entity_reference VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            UNIQUE KEY uniq_idem_uuid_endpoint (client_uuid, endpoint),
            INDEX idx_idem_expires (expires_at),
            INDEX idx_idem_endpoint (endpoint(60))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        out033('idempotency_keys table created', 'ok');
    } else { out033('idempotency_keys exists', 'skip'); }

    /* 4. Site settings: track infrastructure state */
    $kv = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group)
        VALUES (?, ?, 'system')
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group)");
    $kv->execute(['last_backup_at', '']);
    $kv->execute(['last_backup_path', '']);
    $kv->execute(['last_backup_size', '']);
    $kv->execute(['last_tentative_sweep_at', '']);
    out033('site_settings infrastructure rows ensured', 'ok');

    out033('Migration 033 complete', 'done');
} catch (Throwable $e) {
    out033('Migration 033 FAILED: ' . $e->getMessage(), 'fail');
    exit(1);
}

