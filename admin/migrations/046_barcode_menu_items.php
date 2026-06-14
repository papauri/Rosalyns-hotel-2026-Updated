<?php

/**
 * Migration 046 — Barcode support for menu items
 *
 * Adds:
 *  - menu_items.barcode  : VARCHAR(100) UNIQUE NULL — stores the barcode/SKU
 *    that a USB/Bluetooth wedge scanner can read at the POS till.
 */

declare(strict_types=1);

$isCli046 = PHP_SAPI === 'cli';

if ($isCli046) {
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

function out046(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function colExists046(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

out046('Migration 046 — Barcode support for menu items', 'info');

// ── 1. barcode column ────────────────────────────────────────────────────────
if (colExists046($pdo, 'menu_items', 'barcode')) {
    out046('menu_items.barcode already exists — skipping', 'skip');
} else {
    $pdo->exec("ALTER TABLE menu_items ADD COLUMN barcode VARCHAR(100) UNIQUE NULL AFTER item_name");
    out046('Added menu_items.barcode', 'ok');
}

// ── 2. Index for fast barcode lookup ────────────────────────────────────────
try {
    $idxRows = $pdo->query("SHOW INDEX FROM menu_items WHERE Key_name = 'idx_mi_barcode'")->fetchAll();
    if (empty($idxRows)) {
        $pdo->exec("CREATE INDEX idx_mi_barcode ON menu_items (barcode)");
        out046('Created index idx_mi_barcode', 'ok');
    } else {
        out046('Index idx_mi_barcode already exists — skipping', 'skip');
    }
} catch (Throwable $e) {
    out046('Index check/create warning: ' . $e->getMessage(), 'warn');
}

out046('Migration 046 complete.', 'done');

if (!$isCli046) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;padding:12px 24px;'><a href='../pos.php'>← Back to POS</a></p>";
}
