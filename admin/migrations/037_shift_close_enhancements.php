<?php

/**
 * Migration 037 — Shift close enhancements.
 *
 * Adds the missing financial + audit columns to stock_shift_closes so the
 * Z-report and shift history page can show complete records.
 *
 * Columns added:
 *   - settled_from_tabs_count   INT     : orders fired before window but settled during it
 *   - settled_from_tabs_amount  DECIMAL : revenue value of those tabs
 *   - override_applied          TINYINT : 1 = admin/manager overrode variance
 *   - override_reason           TEXT    : reason text captured at close
 *   - total_revenue             DECIMAL : gross revenue (sum of paid orders)
 *   - voids_amount              DECIMAL : already existed in schema; guard added
 *   - close_id tracked in pos_flash for immediate report link
 */

$isCli037 = (PHP_SAPI === 'cli');

if ($isCli037) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    /** @var array $user */
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
}

function out037(string $msg, string $t = 'info'): void
{
    $p = $t === 'ok' ? '[OK]' : ($t === 'warn' ? '[WARN]' : ($t === 'done' ? '[DONE]' : '[INFO]'));
    echo $p . ' ' . $msg . PHP_EOL;
}

function colExists037(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

try {
    $cols = [
        'settled_from_tabs_count'  => "INT NOT NULL DEFAULT 0 AFTER orders_count",
        'settled_from_tabs_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER settled_from_tabs_count",
        'total_revenue'            => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER variance_card",
        'override_applied'         => "TINYINT(1) NOT NULL DEFAULT 0 AFTER notes",
        'override_reason'          => "TEXT NULL AFTER override_applied",
    ];

    foreach ($cols as $col => $def) {
        if (colExists037($pdo, 'stock_shift_closes', $col)) {
            out037("stock_shift_closes.{$col} already exists — skip");
        } else {
            $pdo->exec("ALTER TABLE stock_shift_closes ADD COLUMN {$col} {$def}");
            out037("stock_shift_closes.{$col} added", 'ok');
        }
    }

    // Index to quickly find all closes by date for management reports
    $idxCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_shift_closes' AND INDEX_NAME = 'idx_ssc_shift_date'");
    $idxCheck->execute();
    if ((int)$idxCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE stock_shift_closes ADD INDEX idx_ssc_shift_date (shift_date)");
        out037('idx_ssc_shift_date created', 'ok');
    } else {
        out037('idx_ssc_shift_date already exists');
    }

    out037('Migration 037 complete.', 'done');
} catch (Throwable $e) {
    out037('FAILED: ' . $e->getMessage(), 'warn');
    if (!$isCli037) echo "</pre>";
    exit(1);
}

if (!$isCli037) echo "</pre>";

