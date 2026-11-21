<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * POS drink tiles: how one sale is served and the same drink's other servings (admin/includes/pos-drink-servings.php).
 * Usage (project root): php scripts/tests/pos_drink_servings_test.php
 *
 *  1. Pure: the serving wording from a stock link (typed measure, the item's own bottle/shot measure, a whole can, a glass
 *     worked out from ml, ml alone, weights and "each" items show nothing, a stale typed measure is not trusted).
 *  2. Pure: grouping by stock item: the shot tile lists the bottle's price and the bottle tile the shot's; an 86'd or
 *     off-till item is not listed; biggest first; same name + price once; at most 3.
 *  3. Test hotel database, one rolled-back transaction: a bottle and a shot of one made-up brandy on the till, read the
 *     way pos.php reads them (a cocktail with two stock lines and a kitchen item are left alone).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/includes/form-validation.php';
require_once $root . '/admin/includes/menu-recipe-lib.php';
require_once $root . '/admin/includes/menu-pos-sync.php';
require_once $root . '/admin/includes/pos-drink-servings.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$list = [['name' => 'shot', 'ml' => 25.0], ['name' => 'double', 'ml' => 50.0], ['name' => 'glass of wine', 'ml' => 175.0]];
$brandy = ['name' => 'Premier Brandy', 'unit' => 'bottle', 'volume_ml' => 750,
    'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => 30, 'measure_stock_side' => 'big'];
$lbl = static function (array $line, array $ing) use ($list): string {
    $s = rh_pos_serving_of($line, $ing, $list);
    return $s === null ? '(none)' : rh_pos_serving_label($s);
};

/* ---- 1. Serving wording ---- */
$r = $lbl(['quantity_per_portion' => 0.033333, 'entry_measure' => 'shot', 'entry_qty' => 1, 'entry_ml' => 25], $brandy);
check($r === 'Shot · 25 ml', 'A shot typed on the stock link: "Shot · 25 ml"', $r);
$r = $lbl(['quantity_per_portion' => 1], $brandy);
check($r === 'Bottle · 750 ml', 'One whole bottle (the item\'s big measure): "Bottle · 750 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.033333], $brandy);
check($r === 'Shot · 25 ml', 'One shot with no typed measure (the item\'s own bottle of 30 shots): "Shot · 25 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.066667, 'entry_measure' => 'double', 'entry_qty' => 1], $brandy);
check($r === 'Double · 50 ml', 'A double from the hotel\'s list: "Double · 50 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.066667, 'entry_measure' => 'shot', 'entry_qty' => 2], $brandy);
check($r === '2 shots · 50 ml', 'Two shots typed: "2 shots · 50 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.05, 'entry_measure' => 'shot', 'entry_qty' => 1], $brandy);
check($r === '37.5 ml', 'A typed measure that no longer gives the saved figure is not trusted: the ml is shown', $r);
$wine = ['name' => 'House red', 'unit' => 'bottle', 'volume_ml' => 750];
$r = $lbl(['quantity_per_portion' => 0.233333], $wine);
check($r === 'Glass of wine · 175 ml', 'A glass worked out from ml matches the hotel\'s "glass of wine": "Glass of wine · 175 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.233333, 'entry_measure' => 'ml', 'entry_qty' => 175], $wine);
check($r === '175 ml', 'Typed in ml: the size alone ("175 ml")', $r);
$r = $lbl(['quantity_per_portion' => 1], ['name' => 'Coke 330ml', 'unit' => 'can']);
check($r === 'Can · 330 ml', 'A whole can with the size in its name: "Can · 330 ml"', $r);
$r = $lbl(['quantity_per_portion' => 0.25], ['name' => 'Orange juice', 'unit' => 'litre']);
check($r === '250 ml', 'Stock kept in litres, a quarter sold: "250 ml"', $r);
$r = $lbl(['quantity_per_portion' => 1], ['name' => 'Soda', 'unit' => 'each']);
check($r === '(none)', '"each" with no size shows nothing', $r);
$r = $lbl(['quantity_per_portion' => 0.2], ['name' => 'Sugar', 'unit' => 'kg']);
check($r === '(none)', 'A weight shows nothing', $r);
$r = $lbl(['quantity_per_portion' => 0], $brandy);
check($r === '(none)', 'A zero line shows nothing', $r);
check(rh_pos_serving_label(['name' => 'bottle', 'ml' => null]) === 'Bottle', 'Size unknown: the name alone');
$r = $lbl(['quantity_per_portion' => 1], ['name' => 'Malawi Gin <b>', 'unit' => 'bottle', 'volume_ml' => 1000]);
check($r === 'Bottle · 1,000 ml', 'Thousands get a comma ("1,000 ml")', $r);

/* ---- 2. Grouping ---- */
$row = static fn(string $key, float $price, bool $sell, int $ing, array $line, array $ingRow): array =>
    ['key' => $key, 'price' => $price, 'sellable' => $sell, 'ing_id' => $ing, 'line' => $line, 'ing' => $ingRow];
$tiles = rh_pos_drink_tiles([
    $row('bar:1', 4000.0, true, 7, ['quantity_per_portion' => 0.033333], $brandy),
    $row('bar:2', 40000.0, true, 7, ['quantity_per_portion' => 1], $brandy),
    $row('bar:3', 7500.0, false, 7, ['quantity_per_portion' => 0.066667, 'entry_measure' => 'double', 'entry_qty' => 1], $brandy),
    $row('bar:4', 2500.0, true, 8, ['quantity_per_portion' => 1], ['name' => 'Coke 330ml', 'unit' => 'can']),
    $row('bar:5', 900.0, true, 9, ['quantity_per_portion' => 1], ['name' => 'Soda', 'unit' => 'each']),
], $list);
check(($tiles['bar:1']['serving'] ?? '') === 'Shot · 25 ml' && ($tiles['bar:1']['others'] ?? null) === [['name' => 'Bottle', 'price' => 40000.0]],
    'Shot tile: "Shot · 25 ml" and the bottle\'s price (the 86\'d double is not listed)', json_encode($tiles['bar:1'] ?? null));
check(($tiles['bar:2']['others'] ?? null) === [['name' => 'Shot', 'price' => 4000.0]], 'Bottle tile lists the shot\'s price', json_encode($tiles['bar:2'] ?? null));
check(($tiles['bar:3']['others'] ?? null) === [['name' => 'Bottle', 'price' => 40000.0], ['name' => 'Shot', 'price' => 4000.0]],
    'An 86\'d tile still shows its serving and the others, biggest first', json_encode($tiles['bar:3'] ?? null));
check(($tiles['bar:4'] ?? null) === ['serving' => 'Can · 330 ml', 'others' => []], 'A drink with one serving: its serving, no others', json_encode($tiles['bar:4'] ?? null));
check(!isset($tiles['bar:5']), 'A drink with nothing to say gets no entry');
$many = [];
foreach ([1, 0.5, 0.2, 0.1, 0.05] as $i => $q) {
    $many[] = $row('bar:' . (10 + $i), 1000.0 * ($i + 1), true, 20, ['quantity_per_portion' => $q], ['name' => 'Vodka', 'unit' => 'litre']);
}
$many[] = $row('bar:30', 3000.0, true, 20, ['quantity_per_portion' => 0.5], ['name' => 'Vodka', 'unit' => 'litre']);
$many[] = $row('bar:31', 2000.0, true, 20, ['quantity_per_portion' => 0.5], ['name' => 'Vodka', 'unit' => 'litre']);
$t = rh_pos_drink_tiles($many, $list);
check(count($t['bar:14']['others'] ?? []) === RH_POS_DRINK_OTHERS_MAX, 'At most ' . RH_POS_DRINK_OTHERS_MAX . ' other servings on one tile', json_encode($t['bar:14'] ?? null));
$names = array_map(static fn(array $o): string => $o['name'] . '|' . $o['price'], $t['bar:14']['others'] ?? []);
check($names === ['1,000 ml|1000', '500 ml|2000', '500 ml|3000'], 'Biggest first; the same 500 ml at the same price once, at another price kept', json_encode($names));

/* ---- 3. Test hotel database, rolled back ---- */
if (stripos((string)DB_NAME, 'rosalyn') !== false) { bad('Not the live hotel database', (string)DB_NAME); echo "\n$pass passed, $fail failed\n"; exit(1); }
if (!ensureStockTablesExist()) { bad('Stock tables exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$run = strtoupper(bin2hex(random_bytes(3)));
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES (?, 'ZZPD', 'bottle', 0, 20000, 750)")
        ->execute(["ZZPD $run Brandy"]);
    $ingId = (int)$pdo->lastInsertId();
    if (rh_stock_measure_ready($pdo)) {
        $pdo->prepare("UPDATE stock_ingredients SET measure_big_name = 'bottle', measure_small_name = 'shot', measure_per_big = 30, measure_stock_side = 'big' WHERE id = ?")->execute([$ingId]);
    }
    $other = $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'ZZPD', 'litre', 0, 3000)");
    $other->execute(["ZZPD $run Juice"]);
    $juice = (int)$pdo->lastInsertId();

    $web = static fn(string $name, float $price, string $station): array => [
        'item_name' => $name, 'description' => '', 'price' => number_format($price, 2, '.', ''), 'category' => "ZZPD Bar $run", 'station' => $station,
        'is_available' => 1, 'display_order' => 1, 'show_pos' => 1, 'show_room_service' => 0, 'tags' => '',
    ];
    $shot = rh_menu_pos_sync_item($pdo, 'drink', $web("ZZPD $run Brandy shot", 4000.0, 'bar'), null, null);
    $bottle = rh_menu_pos_sync_item($pdo, 'drink', $web("ZZPD $run Brandy bottle", 40000.0, 'bar'), null, null);
    $cocktail = rh_menu_pos_sync_item($pdo, 'drink', $web("ZZPD $run Brandy punch", 6000.0, 'bar'), null, null);
    $kitchen = rh_menu_pos_sync_item($pdo, 'drink', $web("ZZPD $run Brandy cake", 5000.0, 'kitchen'), null, null);
    rh_menu_recipe_save($pdo, $shot['id'], $shot['slug'], null, null, [['ingredient_id' => $ingId, 'quantity' => round(1 / 30, 6), 'unit' => 'base']], $adminId);
    rh_menu_recipe_save($pdo, $bottle['id'], $bottle['slug'], null, null, [['ingredient_id' => $ingId, 'quantity' => 1, 'unit' => 'base']], $adminId);
    rh_menu_recipe_save($pdo, $cocktail['id'], $cocktail['slug'], null, null, [['ingredient_id' => $ingId, 'quantity' => round(1 / 30, 6), 'unit' => 'base'], ['ingredient_id' => $juice, 'quantity' => 0.1, 'unit' => 'base']], $adminId);
    rh_menu_recipe_save($pdo, $kitchen['id'], $kitchen['slug'], null, null, [['ingredient_id' => $ingId, 'quantity' => round(1 / 30, 6), 'unit' => 'base']], $adminId);

    // The list as pos.php builds it (id, type, price, show_pos, is_available, station).
    $menuList = [];
    foreach ($pdo->query("SELECT mi.id, mc.slug AS type, mi.price, mi.show_pos, mi.is_available, COALESCE(mi.station, mc.default_station) AS station
                            FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.item_name LIKE 'ZZPD $run %'") as $m) {
        $menuList[] = ['id' => (int)$m['id'], 'type' => $m['type'], 'price' => (float)$m['price'], 'show_pos' => (int)$m['show_pos'],
            'is_available' => (int)$m['is_available'], 'station' => $m['station']];
    }
    $info = rh_pos_drink_info($pdo, $menuList);
    $kShot = $shot['slug'] . ':' . $shot['id'];
    $kBottle = $bottle['slug'] . ':' . $bottle['id'];
    $shotMl = rh_stock_measure_ready($pdo) ? 'Shot · 25 ml' : '25 ml';
    check(($info[$kShot]['serving'] ?? '') === $shotMl, 'Database: the shot item reads "' . $shotMl . '"', json_encode($info[$kShot] ?? null));
    check(($info[$kShot]['others'] ?? null) === [['name' => rh_stock_measure_ready($pdo) ? 'Bottle' : '750 ml', 'price' => 40000.0]], 'Database: the shot tile lists the bottle at its menu price', json_encode($info[$kShot] ?? null));
    check(($info[$kBottle]['others'][0]['price'] ?? null) === 4000.0, 'Database: the bottle tile lists the shot at its menu price', json_encode($info[$kBottle] ?? null));
    check(!isset($info[$cocktail['slug'] . ':' . $cocktail['id']]), 'Database: a cocktail (two stock lines) gets nothing');
    check(!isset($info[$kitchen['slug'] . ':' . $kitchen['id']]), 'Database: a kitchen item gets nothing');

    $pdo->prepare("UPDATE menu_items SET is_available = 0 WHERE id = ?")->execute([$bottle['id']]);
    foreach ($menuList as &$m) { if ($m['id'] === $bottle['id']) { $m['is_available'] = 0; } }
    unset($m);
    $info = rh_pos_drink_info($pdo, $menuList);
    check(($info[$kShot]['others'] ?? null) === [], 'Database: with the bottle 86\'d, the shot tile lists nothing', json_encode($info[$kShot] ?? null));
} catch (Throwable $e) {
    bad('Database section ran', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
check((int)col($pdo, "SELECT COUNT(*) FROM menu_items WHERE item_name LIKE ?", ["ZZPD $run %"]) === 0, 'Rolled back: no test rows left');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
