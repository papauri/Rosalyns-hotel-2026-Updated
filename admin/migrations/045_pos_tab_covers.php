<?php

/**
 * Migration 045 — POS Tab Enhancements
 *
 * Adds:
 *  - stock_orders.covers : number of guests/covers on a tab (for per-head reporting
 *    and bar-tab context). Nullable; existing rows stay NULL.
 *
 * Idempotent: safe to re-run.
 */

declare(strict_types=1);

$isCli045 = PHP_SAPI === 'cli';

if ($isCli045) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    /** @var array $user */
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:24px;line-height:1.6;'>";
}

function out045(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function colExists045(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

out045('Migration 045 — POS Tab Enhancements', 'info');
out045('');

// ── 1. stock_orders.covers ───────────────────────────────────────────────────
if (!colExists045($pdo, 'stock_orders', 'covers')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN covers INT UNSIGNED NULL DEFAULT NULL");
    out045('Added stock_orders.covers', 'ok');
} else {
    out045('stock_orders.covers already exists — skipped', 'skip');
}

out045('');
out045('Migration 045 complete.', 'done');

if (!$isCli045) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../dashboard.php'>← Dashboard</a></p>";
}
