<?php
/**
 * Small made-up test menu for the LIWONDE TEST HOTEL ONLY (step 14), so the POS till, the station
 * screens and the POS tests have something to sell there.
 *
 *   php scripts/seed_liwonde_test_menu.php            show what would be added (writes nothing)
 *   php scripts/seed_liwonde_test_menu.php --apply    add it
 *
 * HARD REFUSAL anywhere else: before anything is read or written, BOTH the configured database and the
 * database actually connected must be the Liwonde test database (compared by sha1 so the name stays out
 * of the public repo). This file is copied to Rosalyn's repository with every port; there it refuses.
 *
 * Re-runnable: a category is matched on its slug and an item on its name within that category, so a
 * second run adds nothing. Adds menu rows only - no recipes, no stock, no prices on existing items.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const SEED_TEST_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde test database name

require_once __DIR__ . '/../config/database.php';

$connectedDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (sha1((string)(defined('DB_NAME') ? DB_NAME : '')) !== SEED_TEST_DB_SHA1 || sha1($connectedDb) !== SEED_TEST_DB_SHA1) {
    fwrite(STDERR, "Refused: this is not the Liwonde test database. The test menu is never added to a live hotel. Nothing was written.\n");
    exit(9);
}

$apply = in_array('--apply', $argv ?? [], true);

$menu = [
    ['name' => 'Test Kitchen', 'slug' => 'test-menu-kitchen', 'station' => 'kitchen', 'icon' => 'fa-utensils', 'items' => [
        ['Test Beef Stew', 9500, 'Made-up test dish', 0],
        ['Test Chicken Wrap', 7500, 'Made-up test dish', 0],
        ['Test Vegetable Curry', 6500, 'Made-up test dish', 1],
    ]],
    ['name' => 'Test Bar', 'slug' => 'test-menu-bar', 'station' => 'bar', 'icon' => 'fa-glass-martini-alt', 'items' => [
        ['Test Mango Juice', 2500, 'Made-up test drink', 1],
        ['Test Soda', 1500, 'Made-up test drink', 1],
        ['Test Lager', 3000, 'Made-up test drink', 1],
    ]],
    ['name' => 'Test Coffee Bar', 'slug' => 'test-menu-coffee', 'station' => 'coffee_bar', 'icon' => 'fa-mug-hot', 'items' => [
        ['Test Cappuccino', 3500, 'Made-up test drink', 1],
        ['Test Tea', 2000, 'Made-up test drink', 1],
    ]],
];

$findCat = $pdo->prepare("SELECT id FROM menu_categories WHERE slug = ? LIMIT 1");
$insCat = $pdo->prepare("INSERT INTO menu_categories (name, slug, business_context, description, icon, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active)
                         VALUES (?, ?, 'food_service', 'Made-up test menu (Liwonde test hotel)', ?, ?, 90, 1, 1, 90, 1)");
$findItem = $pdo->prepare("SELECT id FROM menu_items WHERE category_id = ? AND item_name = ? LIMIT 1");
$insItem = $pdo->prepare("INSERT INTO menu_items (category_id, item_name, description, price, category, is_available, is_vegetarian, station, show_pos, show_room_service, display_order)
                          VALUES (?, ?, ?, ?, ?, 1, ?, ?, 1, 1, ?)");

$added = ['categories' => 0, 'items' => 0];
try {
    if ($apply) { $pdo->beginTransaction(); }
    foreach ($menu as $cat) {
        $findCat->execute([$cat['slug']]);
        $catId = (int)$findCat->fetchColumn();
        if ($catId <= 0) {
            echo ($apply ? 'Adding' : 'Would add') . " category {$cat['name']} ({$cat['station']})\n";
            if ($apply) {
                $insCat->execute([$cat['name'], $cat['slug'], $cat['icon'], $cat['station']]);
                $catId = (int)$pdo->lastInsertId();
            }
            $added['categories']++;
        }
        foreach ($cat['items'] as $i => [$itemName, $price, $desc, $veg]) {
            if ($catId > 0) {
                $findItem->execute([$catId, $itemName]);
                if ((int)$findItem->fetchColumn() > 0) { continue; }
            }
            echo '  ' . ($apply ? 'Adding' : 'Would add') . " {$itemName} - " . number_format((float)$price, 2) . "\n";
            if ($apply) {
                $insItem->execute([$catId, $itemName, $desc, $price, $cat['name'], $veg, $cat['station'], $i + 1]);
            }
            $added['items']++;
        }
    }
    if ($apply) { $pdo->commit(); }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Failed, nothing was added: ' . $e->getMessage() . "\n");
    exit(1);
}
echo ($apply ? 'Added' : 'Would add') . " {$added['categories']} categories and {$added['items']} items." . ($apply ? '' : ' Re-run with --apply to add them.') . "\n";
exit(0);
