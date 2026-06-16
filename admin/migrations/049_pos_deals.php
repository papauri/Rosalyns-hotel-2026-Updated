<?php

/**
 * Migration 049 — POS Deals & Promotions
 *
 * Adds:
 *  - pos_deals : deal/promotion definitions (happy hour, multi-buy, % off, fixed off)
 */

declare(strict_types=1);

$isCli049 = PHP_SAPI === 'cli';

if ($isCli049) {
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

function out049(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function tableExists049(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

out049('Migration 049 — POS Deals & Promotions', 'info');
out049('');

// ── 1. pos_deals ──────────────────────────────────────────────────────────────
if (!tableExists049($pdo, 'pos_deals')) {
    $pdo->exec("CREATE TABLE pos_deals (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name             VARCHAR(100) NOT NULL,
        description      VARCHAR(255) NULL,
        deal_type        ENUM('happy_hour','multi_buy','percent_off','fixed_off') NOT NULL,

        -- Time / date window (all nullable = always applies)
        days_of_week     VARCHAR(20) NULL COMMENT 'JSON array: [1,2,3,4,5] Mon=1 Sun=7. NULL = all days',
        start_time       TIME NULL COMMENT 'For happy_hour / timed deals. NULL = no time restriction',
        end_time         TIME NULL,
        valid_from       DATE NULL,
        valid_to         DATE NULL,

        -- Scope
        applies_to       ENUM('all','item_types','items') NOT NULL DEFAULT 'all',
        item_types       JSON NULL COMMENT 'Array of menu_type strings: [\"bar\",\"coffee_bar\"]',
        item_ids         JSON NULL COMMENT 'Array of menu item IDs',

        -- Discount parameters
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'For happy_hour and percent_off',
        discount_fixed   DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'For fixed_off',
        multi_buy_qty    TINYINT UNSIGNED NULL COMMENT 'Buy X (multi_buy)',
        multi_buy_pay    TINYINT UNSIGNED NULL COMMENT 'Pay for Y (multi_buy). Free items = qty - pay',

        is_active        TINYINT(1) NOT NULL DEFAULT 1,
        sort_order       SMALLINT  NOT NULL DEFAULT 0,
        created_by       INT UNSIGNED NULL,
        created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_pd_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out049('Created table: pos_deals', 'ok');
} else {
    out049('Table pos_deals already exists — skipped', 'skip');
}

out049('');
out049('Migration 049 complete.', 'done');

if (!$isCli049) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../deals.php'>← Deals</a> &nbsp; <a href='../dashboard.php'>← Dashboard</a></p>";
}
