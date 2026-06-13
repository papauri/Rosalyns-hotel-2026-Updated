<?php

/**
 * Migration 044 — POS Standalone Features
 *
 * Adds:
 *  - stock_shift_opens   : opening float declarations per cashier shift
 *  - stock_orders.status : adds 'refunded' to the ENUM
 */

declare(strict_types=1);

$isCli044 = PHP_SAPI === 'cli';

if ($isCli044) {
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

function out044(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function colExists044(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

function tableExists044(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

out044('Migration 044 — POS Standalone Features', 'info');
out044('');

// ── 1. stock_shift_opens ─────────────────────────────────────────────────────
if (!tableExists044($pdo, 'stock_shift_opens')) {
    $pdo->exec("CREATE TABLE stock_shift_opens (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED NOT NULL,
        user_name     VARCHAR(120) NOT NULL DEFAULT '',
        shift_date    DATE NOT NULL,
        float_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes         VARCHAR(255) NULL,
        opened_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address    VARCHAR(45) NULL,
        INDEX idx_sso_user_date (user_id, shift_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out044('Created table: stock_shift_opens', 'ok');
} else {
    out044('Table stock_shift_opens already exists — skipped', 'skip');
}

// ── 2. stock_orders.status ENUM: add 'refunded' ───────────────────────────────
// Check current ENUM values
$enumStmt = $pdo->prepare("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_orders' AND COLUMN_NAME = 'status'
");
$enumStmt->execute();
$enumType = (string)$enumStmt->fetchColumn();

if (strpos($enumType, "'refunded'") !== false) {
    out044('stock_orders.status already has refunded — skipped', 'skip');
} else {
    $pdo->exec("ALTER TABLE stock_orders MODIFY COLUMN status ENUM('placed','paid','cancelled','voided','pending','confirmed','completed','refunded') NOT NULL DEFAULT 'placed'");
    out044('Added refunded to stock_orders.status ENUM', 'ok');
}

// ── 3. stock_orders.refunded_at (timestamp for when refund was processed) ────
if (!colExists044($pdo, 'stock_orders', 'refunded_at')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN refunded_at TIMESTAMP NULL DEFAULT NULL AFTER voided_at");
    out044('Added stock_orders.refunded_at', 'ok');
} else {
    out044('stock_orders.refunded_at already exists — skipped', 'skip');
}

// ── 4. stock_orders.refund_reason ────────────────────────────────────────────
if (!colExists044($pdo, 'stock_orders', 'refund_reason')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN refund_reason VARCHAR(255) NULL DEFAULT NULL AFTER refunded_at");
    out044('Added stock_orders.refund_reason', 'ok');
} else {
    out044('stock_orders.refund_reason already exists — skipped', 'skip');
}

out044('');
out044('Migration 044 complete.', 'done');

if (!$isCli044) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../dashboard.php'>← Dashboard</a></p>";
}
