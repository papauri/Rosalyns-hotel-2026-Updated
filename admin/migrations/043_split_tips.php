<?php

/**
 * Migration 043 — Split Bills & Tips
 *
 * Adds:
 *  - stock_orders.tip_amount         : total tip collected for the order
 *  - stock_orders.split_count        : number of ways the bill was split (1 = no split)
 *  - stock_orders.split_paid_count   : number of split legs paid so far
 *  - stock_order_splits              : one row per split leg (amount, tip, method, etc.)
 */

declare(strict_types=1);

$isCli043 = PHP_SAPI === 'cli';

if ($isCli043) {
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

function out043(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function colExists043(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

function tableExists043(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

out043('Migration 043 — Split Bills & Tips', 'info');
out043('');

// ── 1. stock_orders.tip_amount ────────────────────────────────────────────────
if (!colExists043($pdo, 'stock_orders', 'tip_amount')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN tip_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
    out043('Added stock_orders.tip_amount', 'ok');
} else {
    out043('stock_orders.tip_amount already exists — skipped', 'skip');
}

// ── 2. stock_orders.split_count ───────────────────────────────────────────────
if (!colExists043($pdo, 'stock_orders', 'split_count')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN split_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER tip_amount");
    out043('Added stock_orders.split_count', 'ok');
} else {
    out043('stock_orders.split_count already exists — skipped', 'skip');
}

// ── 3. stock_orders.split_paid_count ─────────────────────────────────────────
if (!colExists043($pdo, 'stock_orders', 'split_paid_count')) {
    $pdo->exec("ALTER TABLE stock_orders ADD COLUMN split_paid_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER split_count");
    out043('Added stock_orders.split_paid_count', 'ok');
} else {
    out043('stock_orders.split_paid_count already exists — skipped', 'skip');
}

// ── 4. stock_order_splits table ───────────────────────────────────────────────
if (!tableExists043($pdo, 'stock_order_splits')) {
    $pdo->exec("CREATE TABLE stock_order_splits (
        id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id                INT UNSIGNED NOT NULL,
        split_number            TINYINT UNSIGNED NOT NULL,
        split_amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
        tip_amount              DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_method          ENUM('cash','mobile_money','card_manual','card_pos','other') NOT NULL,
        tendered_amount         DECIMAL(12,2) NULL,
        change_due              DECIMAL(12,2) NULL,
        mobile_wallet_provider  VARCHAR(50)   NULL,
        mobile_wallet_reference VARCHAR(100)  NULL,
        card_last4              VARCHAR(4)    NULL,
        card_auth_code          VARCHAR(50)   NULL,
        paid_at                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_by_user_id         INT UNSIGNED  NULL,
        INDEX idx_sos_order (order_id),
        UNIQUE KEY uq_sos_leg (order_id, split_number),
        CONSTRAINT fk_sos_order FOREIGN KEY (order_id) REFERENCES stock_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out043('Created table: stock_order_splits', 'ok');
} else {
    out043('Table stock_order_splits already exists — skipped', 'skip');
}

out043('');
out043('Migration 043 complete.', 'done');

if (!$isCli043) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../dashboard.php'>← Dashboard</a></p>";
}
