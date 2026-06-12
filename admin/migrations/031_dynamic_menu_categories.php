<?php
/**
 * Migration 031: Dynamic Menu Categories + Unified menu_items Table
 *
 * Replaces the hardcoded food_menu / drink_menu two-table split with:
 *   - menu_categories  — admin-managed category list (any slug, any number)
 *   - menu_items       — single unified table (category_id FK)
 *
 * Existing food/drink items are migrated:
 *   - food_menu  rows → menu_items with their ORIGINAL IDs (1..74)
 *   - drink_menu rows → menu_items with IDs = 10000 + original_drink_id
 *
 * References in stock_order_items, stock_recipes, booking_charges are
 * updated to the new menu_items IDs. The menu_type ENUM is widened to
 * VARCHAR(50) so any category slug can be used in future.
 *
 * Idempotent: safe to re-run. All DDL uses IF NOT EXISTS / column-exists
 * guards. Data migration is skipped when menu_items already has rows.
 *
 * Usage:
 *   CLI:     php admin/migrations/031_dynamic_menu_categories.php
 *   Browser: /admin/migrations/031_dynamic_menu_categories.php (admin only)
 */

$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    require_once __DIR__ . '/../admin-init.php';
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:20px;'>";
} else {
    require_once __DIR__ . '/../../config/database.php';
}

function mig_out(string $msg, string $level = 'info'): void
{
    $prefix = ['ok' => '[OK]   ', 'info' => '[INFO] ', 'warn' => '[WARN] ', 'err' => '[ERR]  '][$level] ?? '';
    echo $prefix . $msg . PHP_EOL;
}

mig_out('=== Migration 031: Dynamic Menu Categories ===');

global $pdo;

// ─────────────────────────────────────────────────────────────────────────────
// 1. Create menu_categories
// ─────────────────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS menu_categories (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name          VARCHAR(100) NOT NULL,
        slug          VARCHAR(50)  NOT NULL,
        description   VARCHAR(255) NULL,
        color         VARCHAR(20)  NULL     DEFAULT '#8A775F',
        icon          VARCHAR(50)  NULL     DEFAULT 'fa-utensils',
        default_station ENUM('kitchen','bar','coffee_bar') NOT NULL DEFAULT 'kitchen',
        sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
        shows_on_pos          TINYINT(1) NOT NULL DEFAULT 1,
        shows_on_room_service TINYINT(1) NOT NULL DEFAULT 1,
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_mc_slug (slug),
        KEY idx_mc_sort (sort_order, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
mig_out('menu_categories table ready', 'ok');

// Add any columns that may be missing (idempotent — column already exists → no-op)
$existingCols = array_column($pdo->query("SHOW COLUMNS FROM menu_categories")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$colsToAdd = [
    'color'               => "ALTER TABLE menu_categories ADD COLUMN color VARCHAR(20) NULL DEFAULT '#8A775F' AFTER description",
    'icon'                => "ALTER TABLE menu_categories ADD COLUMN icon VARCHAR(50) NULL DEFAULT 'fa-utensils' AFTER color",
    'default_station'     => "ALTER TABLE menu_categories ADD COLUMN default_station ENUM('kitchen','bar','coffee_bar') NOT NULL DEFAULT 'kitchen' AFTER icon",
    'sort_order'          => "ALTER TABLE menu_categories ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER default_station",
    'shows_on_pos'        => "ALTER TABLE menu_categories ADD COLUMN shows_on_pos TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
    'shows_on_room_service' => "ALTER TABLE menu_categories ADD COLUMN shows_on_room_service TINYINT(1) NOT NULL DEFAULT 1 AFTER shows_on_pos",
    'is_active'           => "ALTER TABLE menu_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER shows_on_room_service",
];
foreach ($colsToAdd as $col => $sql) {
    if (!in_array($col, $existingCols, true)) {
        $pdo->exec($sql);
        mig_out("Added column menu_categories.$col", 'ok');
    }
}


$pdo->exec("
    INSERT IGNORE INTO menu_categories
        (name, slug, description, color, icon, default_station, sort_order, shows_on_pos, shows_on_room_service)
    VALUES
        ('Food',   'food',  'Kitchen food items',  '#8A775F', 'fa-utensils',     'kitchen', 1, 1, 1),
        ('Drinks', 'drink', 'Bar & soft drinks',   '#5B8EA6', 'fa-glass-water',  'bar',     2, 1, 1)
");
mig_out('Default categories seeded (food, drink)', 'ok');

// Ensure menu_categories.id is INT UNSIGNED (needed for FK compatibility)
$mcIdType = (string)$pdo->query("
    SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_categories' AND COLUMN_NAME = 'id'
")->fetchColumn();
if (stripos($mcIdType, 'unsigned') === false) {
    $pdo->exec("ALTER TABLE menu_categories MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    mig_out("Fixed menu_categories.id → INT UNSIGNED", 'ok');
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Create menu_items
// ─────────────────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS menu_items (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id     INT UNSIGNED NOT NULL,
        item_name       VARCHAR(255) NOT NULL,
        description     TEXT NULL,
        price           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency_code   VARCHAR(10)   NOT NULL DEFAULT 'MWK',
        category        VARCHAR(100)  NULL COMMENT 'Sub-category label within the parent category',
        is_available    TINYINT(1) NOT NULL DEFAULT 1,
        is_featured     TINYINT(1) NOT NULL DEFAULT 0,
        is_vegetarian   TINYINT(1) NOT NULL DEFAULT 0,
        is_vegan        TINYINT(1) NOT NULL DEFAULT 0,
        allergens       VARCHAR(500) NULL,
        tags            VARCHAR(500) NULL,
        image_path      VARCHAR(500) NULL,
        display_order   INT UNSIGNED NOT NULL DEFAULT 0,
        station         ENUM('kitchen','bar','coffee_bar') NULL COMMENT 'Per-item override; NULL = use category default',
        show_pos          TINYINT(1) NOT NULL DEFAULT 1,
        show_room_service TINYINT(1) NOT NULL DEFAULT 1,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_mi_category   (category_id, is_available),
        KEY idx_mi_available  (is_available),
        KEY idx_mi_pos        (show_pos, is_available),
        KEY idx_mi_rs         (show_room_service, is_available),
        CONSTRAINT fk_mi_category FOREIGN KEY (category_id)
            REFERENCES menu_categories(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
mig_out('menu_items table ready', 'ok');

// ─────────────────────────────────────────────────────────────────────────────
// 4. Migrate data (skip if already done)
// ─────────────────────────────────────────────────────────────────────────────
$miCount      = (int)$pdo->query("SELECT COUNT(*) FROM menu_items")->fetchColumn();
$drinkMiCount = (int)$pdo->query("SELECT COUNT(*) FROM menu_items WHERE category_id = (SELECT id FROM menu_categories WHERE slug='drink' LIMIT 1)")->fetchColumn();

if ($miCount > 0 && $drinkMiCount > 0) {
    mig_out("menu_items already has {$miCount} rows (drink={$drinkMiCount}) — skipping data migration", 'info');
} else {
    $foodCatId  = (int)$pdo->query("SELECT id FROM menu_categories WHERE slug='food' LIMIT 1")->fetchColumn();
    $drinkCatId = (int)$pdo->query("SELECT id FROM menu_categories WHERE slug='drink' LIMIT 1")->fetchColumn();

    if (!$foodCatId || !$drinkCatId) {
        mig_out('Could not find food/drink category IDs — aborting data migration', 'err');
        exit(1);
    }

    // ── 4a. Get max food_menu ID to calculate drink offset ───────────────────
    $maxFoodId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM food_menu")->fetchColumn();
    // Drink items will be inserted with IDs 10001..10018 (offset = 10000).
    // This is safe as long as food items never exceed 9999 — more than enough.
    $drinkOffset = 10000;
    mig_out("food_menu max id = {$maxFoodId} | drink offset = {$drinkOffset}", 'info');

    // ── 4b. Migrate food_menu → menu_items (preserve original IDs) ──────────
    $foodCols  = array_column($pdo->query("SHOW COLUMNS FROM food_menu")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $foodSelect = implode(', ', array_map(static function($col) use ($foodCols) {
        return in_array($col, $foodCols, true) ? $col : "NULL AS $col";
    }, ['id','item_name','description','price','category','is_available','is_featured','is_vegetarian','is_vegan','allergens','image_path','display_order','station','show_pos','show_room_service']));
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE menu_items AUTO_INCREMENT = 1");

    $foodRows = $pdo->query("SELECT $foodSelect FROM food_menu ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $insFood = $pdo->prepare("
        INSERT IGNORE INTO menu_items
            (id, category_id, item_name, description, price, currency_code, category,
             is_available, is_featured, is_vegetarian, is_vegan, allergens,
             image_path, display_order, station, show_pos, show_room_service)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $foodMigrated = 0;
    foreach ($foodRows as $r) {
        $station = in_array($r['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $r['station'] : null;
        $insFood->execute([
            (int)$r['id'],
            $foodCatId,
            $r['item_name'],
            $r['description'],
            $r['price'],
            'MWK',
            $r['category'],
            (int)$r['is_available'],
            (int)$r['is_featured'],
            (int)($r['is_vegetarian'] ?? 0),
            (int)($r['is_vegan'] ?? 0),
            $r['allergens'] ?? null,
            $r['image_path'] ?? null,
            (int)($r['display_order'] ?? 0),
            $station,
            (int)($r['show_pos'] ?? 1),
            (int)($r['show_room_service'] ?? 0),
        ]);
        $foodMigrated++;
    }
    mig_out("Migrated {$foodMigrated} food items (original IDs preserved)", 'ok');

    // ── 4c. Migrate drink_menu → menu_items (IDs = drinkOffset + original) ──
    // Use INFORMATION_SCHEMA to build a safe column list
    $drinkCols = array_column($pdo->query("SHOW COLUMNS FROM drink_menu")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $drinkSelect = implode(', ', array_map(static function($col) use ($drinkCols) {
        return in_array($col, $drinkCols, true) ? $col : "NULL AS $col";
    }, ['id','item_name','description','price','category','is_available','is_featured','allergens','tags','image_path','display_order','station','show_pos','show_room_service']));
    $drinkRows = $pdo->query("SELECT $drinkSelect FROM drink_menu ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $insDrink = $pdo->prepare("
        INSERT IGNORE INTO menu_items
            (id, category_id, item_name, description, price, currency_code, category,
             is_available, is_featured, tags, allergens,
             image_path, display_order, station, show_pos, show_room_service)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $drinkMigrated = 0;
    foreach ($drinkRows as $r) {
        $newId = $drinkOffset + (int)$r['id'];
        $station = in_array($r['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $r['station'] : null;
        $insDrink->execute([
            $newId,
            $drinkCatId,
            $r['item_name'],
            $r['description'],
            $r['price'],
            $r['currency_code'] ?? 'MWK',
            $r['category'],
            (int)$r['is_available'],
            (int)$r['is_featured'],
            $r['tags'] ?? null,
            $r['allergens'] ?? null,
            $r['image_path'] ?? null,
            (int)($r['display_order'] ?? 0),
            $station,
            (int)($r['show_pos'] ?? 1),
            (int)($r['show_room_service'] ?? 0),
        ]);
        $drinkMigrated++;
    }
    mig_out("Migrated {$drinkMigrated} drink items (IDs offset by {$drinkOffset})", 'ok');

    // Reset AUTO_INCREMENT past the highest inserted ID
    $maxInserted = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM menu_items")->fetchColumn();
    $nextAi = $maxInserted + 1;
    $pdo->exec("ALTER TABLE menu_items AUTO_INCREMENT = {$nextAi}");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    mig_out("AUTO_INCREMENT set to {$nextAi}", 'info');

    // ── 4d. Update stock_order_items: drink menu_item_id → new IDs ────────
    $soiDrinkUpdated = $pdo->exec("
        UPDATE stock_order_items
        SET menu_item_id = menu_item_id + {$drinkOffset}
        WHERE menu_type = 'drink'
    ");
    mig_out("stock_order_items drink references updated: {$soiDrinkUpdated} rows", 'ok');

    // ── 4e. Update stock_recipes: drink menu_item_id → new IDs ────────────
    $srDrinkUpdated = $pdo->exec("
        UPDATE stock_recipes
        SET menu_item_id = menu_item_id + {$drinkOffset}
        WHERE menu_type = 'drink'
    ");
    mig_out("stock_recipes drink references updated: {$srDrinkUpdated} rows", 'ok');

    // ── 4f. Update booking_charges.source_item_id for drink folio charges ──
    $bcDrinkUpdated = $pdo->exec("
        UPDATE booking_charges
        SET source_item_id = source_item_id + {$drinkOffset}
        WHERE charge_type = 'drink'
          AND source_item_id IS NOT NULL
          AND source_item_id > 0
    ");
    mig_out("booking_charges drink source_item_id updated: {$bcDrinkUpdated} rows", 'ok');
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Widen menu_type ENUM → VARCHAR(50) in stock_recipes
// ─────────────────────────────────────────────────────────────────────────────
$srTypeCol = $pdo->prepare("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_recipes' AND COLUMN_NAME = 'menu_type'
");
$srTypeCol->execute();
$srColType = strtolower((string)$srTypeCol->fetchColumn());
if (strpos($srColType, 'varchar') === false) {
    $pdo->exec("ALTER TABLE stock_recipes MODIFY COLUMN menu_type VARCHAR(50) NOT NULL");
    mig_out('stock_recipes.menu_type changed from ENUM to VARCHAR(50)', 'ok');
} else {
    mig_out('stock_recipes.menu_type already VARCHAR — skipped', 'info');
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. Widen menu_type ENUM → VARCHAR(50) in stock_order_items
// ─────────────────────────────────────────────────────────────────────────────
$soiTypeCol = $pdo->prepare("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_order_items' AND COLUMN_NAME = 'menu_type'
");
$soiTypeCol->execute();
$soiColType = strtolower((string)$soiTypeCol->fetchColumn());
if (strpos($soiColType, 'varchar') === false) {
    $pdo->exec("ALTER TABLE stock_order_items MODIFY COLUMN menu_type VARCHAR(50) NOT NULL");
    mig_out('stock_order_items.menu_type changed from ENUM to VARCHAR(50)', 'ok');
} else {
    mig_out('stock_order_items.menu_type already VARCHAR — skipped', 'info');
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. Verify row counts
// ─────────────────────────────────────────────────────────────────────────────
$foodCount  = (int)$pdo->query("SELECT COUNT(*) FROM food_menu")->fetchColumn();
$drinkCount = (int)$pdo->query("SELECT COUNT(*) FROM drink_menu")->fetchColumn();
$miTotal    = (int)$pdo->query("SELECT COUNT(*) FROM menu_items")->fetchColumn();
$miFood     = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id=mi.category_id WHERE mc.slug='food'")->fetchColumn();
$miDrink    = (int)$pdo->query("SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id=mi.category_id WHERE mc.slug='drink'")->fetchColumn();
$catCount   = (int)$pdo->query("SELECT COUNT(*) FROM menu_categories")->fetchColumn();

mig_out('');
mig_out('=== Verification ===');
mig_out("menu_categories: {$catCount} rows", 'info');
mig_out("food_menu (legacy): {$foodCount} | menu_items food: {$miFood}" . ($foodCount === $miFood ? ' ✓' : ' MISMATCH'), ($foodCount === $miFood ? 'ok' : 'err'));
mig_out("drink_menu (legacy): {$drinkCount} | menu_items drink: {$miDrink}" . ($drinkCount === $miDrink ? ' ✓' : ' MISMATCH'), ($drinkCount === $miDrink ? 'ok' : 'err'));
mig_out("menu_items total: {$miTotal}", 'info');
mig_out('');
mig_out('=== Migration 031 complete ===');

if (!$is_cli) {
    echo '</pre>';
}

