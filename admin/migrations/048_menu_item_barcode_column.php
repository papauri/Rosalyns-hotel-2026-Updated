<?php
/**
 * Migration 048 — menu_items barcode column + Retail Items category
 *
 * Ensures:
 *  1. menu_items.barcode column exists (VARCHAR 100, unique, nullable)
 *  2. A "Retail Items" menu category (slug=retail) exists so barcode-scanned
 *     retail products (Coca Cola, etc.) have a home on the POS without needing
 *     admin to pre-create a category.
 */
declare(strict_types=1);

$isCli048 = PHP_SAPI === 'cli';
if ($isCli048) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only.'); }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:24px;line-height:1.6;'>";
}

function out048(string $msg, string $tag = 'info'): void {
    $icons = ['ok'=>'✓','warn'=>'⚠','done'=>'★','info'=>'→','skip'=>'·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}
function col048(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]); return (int)$s->fetchColumn() > 0;
}

out048('Migration 048 — menu_items barcode + Retail category');

// ── 1. barcode column on menu_items ──────────────────────────────────────────
if (!col048($pdo, 'menu_items', 'barcode')) {
    $pdo->exec("ALTER TABLE menu_items ADD COLUMN barcode VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional barcode for POS scanner lookup'");
    out048('Added menu_items.barcode column', 'ok');
} else {
    out048('menu_items.barcode already exists', 'skip');
}

// Unique index (ignore error if already exists)
try {
    $pdo->exec("ALTER TABLE menu_items ADD UNIQUE INDEX uq_mi_barcode (barcode)");
    out048('Added unique index on menu_items.barcode', 'ok');
} catch (PDOException $e) {
    out048('Unique index already exists or could not add: ' . $e->getMessage(), 'skip');
}

// ── 2. Retail Items category ──────────────────────────────────────────────────
$existing = $pdo->prepare("SELECT id FROM menu_categories WHERE slug = 'retail'");
$existing->execute();
if (!$existing->fetch()) {
    // Find max sort_order
    $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM menu_categories")->fetchColumn();
    $pdo->prepare("
        INSERT INTO menu_categories
            (name, slug, description, color, icon, default_station, sort_order, shows_on_pos, shows_on_room_service, is_active)
        VALUES
            ('Retail Items', 'retail', 'Scanned retail products sold at POS (drinks, snacks, etc.)',
             '#8A775F', 'fa-shopping-basket', 'bar', ?, 1, 0, 1)
    ")->execute([$maxSort + 10]);
    out048('Created Retail Items menu category (slug=retail)', 'ok');
} else {
    out048('Retail Items category already exists', 'skip');
}

out048('Migration 048 complete.', 'done');
if (!$isCli048) { echo "</pre>"; }
