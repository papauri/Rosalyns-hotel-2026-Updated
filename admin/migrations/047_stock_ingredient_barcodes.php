<?php
/**
 * Migration 047 — stock_ingredient_barcodes
 *
 * Maps product barcodes (EAN/UPC/Code128 etc.) to stock ingredients.
 * One barcode → one ingredient, but many barcodes can map to the same ingredient
 * (different suppliers, different pack sizes).
 */
declare(strict_types=1);

$isCli047 = PHP_SAPI === 'cli';
if ($isCli047) {
    require_once __DIR__ . '/../../config/database.php';
} else {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only.'); }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:24px;line-height:1.6;'>";
}

function out047(string $msg, string $tag = 'info'): void {
    $icons = ['ok'=>'✓','warn'=>'⚠','done'=>'★','info'=>'→','skip'=>'·'];
    echo ($icons[$tag] ?? '→') . '  ' . $msg . PHP_EOL;
}
function col047(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]); return (int)$s->fetchColumn() > 0;
}
function tbl047(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$table]); return (int)$s->fetchColumn() > 0;
}

out047('Migration 047 — stock_ingredient_barcodes');

if (tbl047($pdo, 'stock_ingredient_barcodes')) {
    out047('stock_ingredient_barcodes already exists — skipping', 'skip');
} else {
    $pdo->exec("
        CREATE TABLE stock_ingredient_barcodes (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            barcode      VARCHAR(100) NOT NULL,
            ingredient_id INT UNSIGNED NOT NULL,
            pack_size    DECIMAL(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Units of the ingredient per scan (1 = single, 24 = case)',
            pack_label   VARCHAR(50)  NULL     COMMENT 'Human label e.g. can, bottle, case of 24',
            created_by   INT UNSIGNED NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sib_barcode (barcode),
            KEY idx_sib_ingredient (ingredient_id),
            CONSTRAINT fk_sib_ingredient FOREIGN KEY (ingredient_id)
                REFERENCES stock_ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out047('Created stock_ingredient_barcodes', 'ok');
}

out047('Migration 047 complete.', 'done');
if (!$isCli047) { echo "</pre>"; }
