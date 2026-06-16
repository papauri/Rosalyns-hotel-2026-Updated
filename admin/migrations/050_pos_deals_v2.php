<?php

/**
 * Migration 050 — POS Deals v2
 *
 * Adds:
 *  - Two new deal types: spend_save, combo (ENUM extension)
 *  - spend_threshold  : minimum cart total for spend_save deals
 *  - combo_requires   : JSON array of {item_types[], min_qty} groups for combo deals
 *  - max_uses_per_order: cap how many times a deal fires per order (NULL = unlimited)
 *  - exclusive        : if 1, this deal cannot stack with other deals
 */

declare(strict_types=1);

$isCli050 = PHP_SAPI === 'cli';

if ($isCli050) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    /** @var array $user */
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only.'); }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:24px;line-height:1.6;'>";
}

function out050(string $msg, string $tag = 'info'): void
{
    $icons = ['ok' => '✓', 'warn' => '⚠', 'done' => '★', 'info' => '→', 'skip' => '·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}

function colExists050(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

function tableExists050(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

out050('Migration 050 — POS Deals v2', 'info');
out050('');

if (!tableExists050($pdo, 'pos_deals')) {
    out050('pos_deals table does not exist — run migration 049 first', 'warn');
    if (!$isCli050) echo "</pre>";
    exit(1);
}

// ── 1. Extend deal_type ENUM ─────────────────────────────────────────────────
$enumStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_deals' AND COLUMN_NAME='deal_type'");
$enumStmt->execute();
$enumType = (string)$enumStmt->fetchColumn();

if (strpos($enumType, "'spend_save'") !== false && strpos($enumType, "'combo'") !== false) {
    out050('deal_type ENUM already has spend_save, combo — skipped', 'skip');
} else {
    $pdo->exec("ALTER TABLE pos_deals MODIFY COLUMN deal_type ENUM('happy_hour','multi_buy','percent_off','fixed_off','spend_save','combo') NOT NULL");
    out050('Extended deal_type ENUM with spend_save, combo', 'ok');
}

// ── 2. spend_threshold ────────────────────────────────────────────────────────
if (!colExists050($pdo, 'pos_deals', 'spend_threshold')) {
    $pdo->exec("ALTER TABLE pos_deals ADD COLUMN spend_threshold DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'For spend_save: minimum cart total to trigger deal' AFTER multi_buy_pay");
    out050('Added pos_deals.spend_threshold', 'ok');
} else {
    out050('pos_deals.spend_threshold already exists — skipped', 'skip');
}

// ── 3. combo_requires ─────────────────────────────────────────────────────────
if (!colExists050($pdo, 'pos_deals', 'combo_requires')) {
    $pdo->exec("ALTER TABLE pos_deals ADD COLUMN combo_requires JSON NULL DEFAULT NULL COMMENT 'For combo: [{\"item_types\":[\"food\"],\"min_qty\":1},{\"item_types\":[\"drink\"],\"min_qty\":1}]' AFTER spend_threshold");
    out050('Added pos_deals.combo_requires', 'ok');
} else {
    out050('pos_deals.combo_requires already exists — skipped', 'skip');
}

// ── 4. max_uses_per_order ────────────────────────────────────────────────────
if (!colExists050($pdo, 'pos_deals', 'max_uses_per_order')) {
    $pdo->exec("ALTER TABLE pos_deals ADD COLUMN max_uses_per_order TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = unlimited applications per order' AFTER combo_requires");
    out050('Added pos_deals.max_uses_per_order', 'ok');
} else {
    out050('pos_deals.max_uses_per_order already exists — skipped', 'skip');
}

// ── 5. exclusive flag ────────────────────────────────────────────────────────
if (!colExists050($pdo, 'pos_deals', 'exclusive')) {
    $pdo->exec("ALTER TABLE pos_deals ADD COLUMN exclusive TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = cannot stack with other deals' AFTER max_uses_per_order");
    out050('Added pos_deals.exclusive', 'ok');
} else {
    out050('pos_deals.exclusive already exists — skipped', 'skip');
}

out050('');
out050('Migration 050 complete.', 'done');

if (!$isCli050) {
    echo "</pre>";
    echo "<p style='font-family:sans-serif;'><a href='../deals.php'>← Deals</a></p>";
}
