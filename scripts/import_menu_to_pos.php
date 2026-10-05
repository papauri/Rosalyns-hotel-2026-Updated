<?php
/**
 * scripts/import_menu_to_pos.php
 *
 * Copy the website menu (food_menu + drink_menu) into the POS catalogue
 * (menu_categories + menu_items).
 *
 * Why this exists
 * ---------------
 * The site keeps two menus that nothing connected:
 *
 *   food_menu / drink_menu  — the WEBSITE menu. restaurant.php and menu-pdf.php
 *                             read it; admin/menu-management.php edits it.
 *   menu_categories/items   — the POS catalogue. admin/pos.php sells from it,
 *                             and the KDS routes tickets by its station column.
 *
 * `menu_categories` was already populated with 13 kitchen categories matching the
 * food menu exactly, but `menu_items` was left empty — so the till listed nothing
 * and no order could be rung up. This script finishes that setup step.
 *
 * Usage (CLI only):
 *   php scripts/import_menu_to_pos.php            # dry run — prints the plan
 *   php scripts/import_menu_to_pos.php --run      # apply
 *
 * Idempotent: an item already present in the target category (matched on
 * category_id + item_name) is skipped, so re-running only adds what is missing.
 * Nothing is ever updated or deleted — this only inserts.
 *
 * The website menu stays the source of truth. Re-run after adding dishes there.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden: CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$apply = in_array('--run', array_slice($argv, 1), true);

/** Slug helper matching the existing menu_categories convention. */
function pos_slug(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim($s, '-');
}

// ---------------------------------------------------------------------------
// 1. Build the category map
// ---------------------------------------------------------------------------
$existing = [];
foreach ($pdo->query("SELECT id, name, default_station, sort_order FROM menu_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $existing[strtolower($c['name']) . '|' . $c['default_station']] = (int)$c['id'];
}
$maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM menu_categories")->fetchColumn();

$sources = [
    ['table' => 'food_menu',  'station' => 'kitchen', 'extra' => ['is_vegetarian', 'is_vegan', 'allergens']],
    ['table' => 'drink_menu', 'station' => 'bar',     'extra' => ['tags']],
];

$categoryPlan = [];   // key => ['name'=>, 'station'=>, 'id'=>int|null, 'slug'=>]
foreach ($sources as $src) {
    $rows = $pdo->query("SELECT DISTINCT category, station FROM `{$src['table']}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $name    = (string)$r['category'];
        $station = (string)$r['station'];
        $key     = strtolower($name) . '|' . $station;
        if (isset($categoryPlan[$key])) {
            continue;
        }
        // "Desserts" exists on both menus — kitchen puddings and bar ice creams.
        // Two identically named categories would be unreadable on the till, so the
        // non-kitchen one is suffixed. Station is part of the match key throughout,
        // so the kitchen category is never reused for drinks.
        $displayName = $name;
        if (!isset($existing[$key])) {
            foreach ($existing as $ek => $eid) {
                if (str_starts_with($ek, strtolower($name) . '|')) {
                    $displayName = $name . ' (' . ucfirst(str_replace('_', ' ', $station)) . ')';
                    break;
                }
            }
        }
        // Resolve against BOTH the source name and the suffixed display name.
        // Without the second lookup a disambiguated category ("Desserts (Bar)")
        // is stored under a key the next run never checks, so re-running would
        // create it again and re-insert its items — the script would not be
        // idempotent, which is the one property it has to have.
        $displayKey = strtolower($displayName) . '|' . $station;
        $categoryPlan[$key] = [
            'name'    => $displayName,
            'station' => $station,
            'id'      => $existing[$key] ?? $existing[$displayKey] ?? null,
            'slug'    => pos_slug($displayName),
        ];
    }
}

echo "=== CATEGORIES ===\n";
$toCreate = 0;
foreach ($categoryPlan as $c) {
    if ($c['id']) {
        printf("  reuse   #%-4s %-28s (%s)\n", $c['id'], $c['name'], $c['station']);
    } else {
        $toCreate++;
        printf("  CREATE       %-28s (%s)  slug=%s\n", $c['name'], $c['station'], $c['slug']);
    }
}
printf("  -> %d existing reused, %d to create\n\n", count($categoryPlan) - $toCreate, $toCreate);

// ---------------------------------------------------------------------------
// 2. Work out which items are missing
// ---------------------------------------------------------------------------
$present = [];
foreach ($pdo->query("SELECT category_id, LOWER(item_name) n FROM menu_items")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $present[$r['category_id'] . '|' . $r['n']] = true;
}

$plan = [];   // rows to insert, category resolved later for new categories
$skipped = 0;
foreach ($sources as $src) {
    $rows = $pdo->query("SELECT * FROM `{$src['table']}` ORDER BY category, display_order, item_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $key = strtolower((string)$r['category']) . '|' . (string)$r['station'];
        $cat = $categoryPlan[$key];
        if ($cat['id'] !== null && isset($present[$cat['id'] . '|' . strtolower((string)$r['item_name'])])) {
            $skipped++;
            continue;
        }
        $plan[] = ['cat_key' => $key, 'src' => $src, 'row' => $r];
    }
}

echo "=== ITEMS ===\n";
$byCat = [];
foreach ($plan as $p) {
    $byCat[$categoryPlan[$p['cat_key']]['name']] = ($byCat[$categoryPlan[$p['cat_key']]['name']] ?? 0) + 1;
}
ksort($byCat);
foreach ($byCat as $n => $c) {
    printf("  %-34s %d\n", $n, $c);
}
printf("  -> %d to insert, %d already present (skipped)\n\n", count($plan), $skipped);

if (!$apply) {
    echo "DRY RUN — nothing written. Re-run with --run to apply.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 3. Apply
// ---------------------------------------------------------------------------
$pdo->beginTransaction();
try {
    $catIns = $pdo->prepare(
        "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order,
                                      shows_on_pos, shows_on_room_service, display_order, is_active)
         VALUES (?, ?, 'food_service', ?, ?, 1, 1, ?, 1)"
    );
    foreach ($categoryPlan as $key => &$c) {
        if ($c['id'] !== null) {
            continue;
        }
        $maxSort++;
        $catIns->execute([$c['name'], $c['slug'], $c['station'], $maxSort, $maxSort]);
        $c['id'] = (int)$pdo->lastInsertId();
        printf("  created category #%d %s\n", $c['id'], $c['name']);
    }
    unset($c);

    $itemIns = $pdo->prepare(
        "INSERT INTO menu_items (category_id, item_name, description, price, currency_code,
                                 category, is_available, is_featured, is_vegetarian, is_vegan,
                                 allergens, tags, image_path, display_order, station,
                                 show_pos, show_room_service)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $inserted = 0;
    foreach ($plan as $p) {
        $r    = $p['row'];
        $cid  = $categoryPlan[$p['cat_key']]['id'];
        $isFood = $p['src']['table'] === 'food_menu';
        $itemIns->execute([
            $cid,
            (string)$r['item_name'],
            $r['description'] ?? null,
            (float)$r['price'],
            (string)($r['currency_code'] ?: 'MWK'),
            (string)$r['category'],
            (int)($r['is_available'] ?? 1),
            (int)($r['is_featured'] ?? 0),
            $isFood ? (int)($r['is_vegetarian'] ?? 0) : 0,
            $isFood ? (int)($r['is_vegan'] ?? 0) : 0,
            $isFood ? ($r['allergens'] ?? null) : null,
            $isFood ? null : ($r['tags'] ?? null),
            $r['image_path'] ?? null,
            (int)($r['display_order'] ?? 0),
            (string)$r['station'],
            (int)($r['show_pos'] ?? 1),
            (int)($r['show_room_service'] ?? 1),
        ]);
        $inserted++;
    }

    $pdo->commit();
    printf("\nDone. categories created=%d  items inserted=%d\n", $toCreate, $inserted);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ROLLED BACK: " . $e->getMessage() . "\n");
    exit(1);
}

$sellable = (int)$pdo->query(
    "SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
     WHERE mc.is_active = 1 AND (mi.show_pos = 1 OR mi.show_room_service = 1)"
)->fetchColumn();
echo "POS now lists {$sellable} sellable items.\n";
