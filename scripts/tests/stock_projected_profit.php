<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock Reports > Projected profit (drinks profit projection P2, owner 2026-11-19).
 * Usage (project root): php scripts/tests/stock_projected_profit.php
 *
 * Part 1 is pure arithmetic on made-up rows (rh_spp_build: no database writes at all).
 * Part 2 runs scripts/tests/stock_projected_profit_child.php through page_harness.php: ONE outer transaction that can never
 * commit, rolled back at the end, to check the database read (rh_spp_load). Every row there is named 'ZZP <run> ...'.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/admin/includes/stock-projected-profit.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function money_eq(?float $a, float $b): bool { return $a !== null && abs($a - $b) <= BALANCE_TOLERANCE; }

// ---------------- Part 1: the arithmetic ----------------
$list = [['name' => 'shot', 'ml' => 25.0], ['name' => 'glass of wine', 'ml' => 175.0]];
$bottle = static fn(int $id, string $name, float $qty, $cost, $ml = 750.0): array => [
    'id' => $id, 'name' => $name, 'category' => 'WHISKYS', 'unit' => 'bottle', 'current_quantity' => $qty, 'cost_per_unit' => $cost,
    'volume_ml' => $ml, 'measure_big_name' => null, 'measure_small_name' => null, 'measure_per_big' => null, 'measure_stock_side' => null,
];
$shotLink = static fn(int $mid, string $drink, int $ingId, float $price, float $ml = 750.0): array => [
    'menu_item_id' => $mid, 'item_name' => $drink, 'menu_category' => 'Bar', 'price' => $price, 'is_available' => 1,
    'ingredient_id' => $ingId, 'quantity_per_portion' => round(25 / $ml, 6), 'yield_percent' => 100,
    'entry_measure' => 'shot', 'entry_qty' => 1, 'entry_ml' => 25,
];
$unitLink = static fn(int $mid, string $drink, int $ingId, float $price): array => [
    'menu_item_id' => $mid, 'item_name' => $drink, 'menu_category' => 'Bar', 'price' => $price, 'is_available' => 1,
    'ingredient_id' => $ingId, 'quantity_per_portion' => 1, 'yield_percent' => 100, 'entry_measure' => null, 'entry_qty' => null, 'entry_ml' => null,
];

// 1. Jameson 12 x 750 ml, MK 45,000 a bottle, shot 25 ml at MK 3,500 (VAT left in)
$items = [1 => $bottle(1, 'Jameson', 12, 45000)];
$r = rh_spp_build($items, [$shotLink(101, 'Jameson shot', 1, 3500)], [], 17.5, false, $list);
$l = $r['lines'][0] ?? [];
ok('Jameson: 30 shots in a 750 ml bottle (not 30.0003 from the saved 6 dp figure)', abs(($l['per_unit'] ?? 0) - 30.0) < 1e-9, (string)($l['per_unit'] ?? ''));
ok('Jameson: projected sales 1,260,000', money_eq($l['sales'] ?? null, 1260000.00), (string)($l['sales'] ?? ''));
ok('Jameson: stock cost 540,000', money_eq($l['stock_cost'] ?? null, 540000.00), (string)($l['stock_cost'] ?? ''));
ok('Jameson: projected profit 720,000', money_eq($l['profit'] ?? null, 720000.00), (string)($l['profit'] ?? ''));
ok('Jameson: margin 57.1%', abs(($l['margin'] ?? 0) - 57.1) < 0.001, (string)($l['margin'] ?? ''));
ok('Jameson: totals match the one line', money_eq($r['totals']['sales'], 1260000.00) && money_eq($r['totals']['cost'], 540000.00)
    && money_eq($r['totals']['profit'], 720000.00) && abs((float)$r['totals']['margin'] - 57.1) < 0.001, json_encode($r['totals']));
ok('Jameson: one sale is written as "1 shot (25 ml)"', ($l['sale'] ?? '') === '1 shot (25 ml)', (string)($l['sale'] ?? ''));

// 2. Take VAT out: the till's rule (gross / (1 + rate)) on the line total; same as the hotel's VAT components when prices include VAT
$rv = rh_spp_build($items, [$shotLink(101, 'Jameson shot', 1, 3500)], [], 17.5, true, $list);
$lv = $rv['lines'][0];
ok('VAT out at 17.5%: sales 1,260,000 / 1.175 = 1,072,340.43', money_eq($lv['sales'], 1072340.43), (string)$lv['sales']);
ok('VAT out: profit = sales - cost (532,340.43), margin 49.6%', money_eq($lv['profit'], 532340.43) && abs((float)$lv['margin'] - 49.6) < 0.001, $lv['profit'] . ' ' . $lv['margin']);
ok('VAT out: price shown without VAT (2,978.72), menu price kept (3,500)', money_eq($lv['price'], 2978.72) && money_eq($lv['price_gross'], 3500.0), $lv['price'] . ' ' . $lv['price_gross']);
ok('VAT out: totals say VAT was taken out', $rv['totals']['vat_out'] === true && $r['totals']['vat_out'] === false);
$hotelRate = rh_spp_vat_rate();
if ($hotelRate > 0 && vat_mode() === 'inclusive') {
    $vc = vat_components(1260000.00);
    ok('VAT out with the hotel\'s own rate matches its VAT components (net of 1,260,000)', money_eq(rh_spp_net(1260000.00, $hotelRate), (float)$vc['net']), rh_spp_net(1260000.00, $hotelRate) . ' vs ' . $vc['net']);
} else {
    ok('hotel VAT is ' . ($hotelRate > 0 ? 'added on top' : 'off') . ': net = gross / (1 + rate), the till\'s rule', money_eq(rh_spp_net(1175.0, 17.5), 1000.0));
}
$r0 = rh_spp_build($items, [$shotLink(101, 'Jameson shot', 1, 3500)], [], 0.0, true, $list);
ok('VAT off (rate 0): switch changes nothing', money_eq($r0['lines'][0]['sales'], 1260000.00) && $r0['totals']['vat_out'] === false);

// 3. No selling price yet: no link, a link at price 0; kitchen ingredients and weights are not listed
$items3 = [
    2 => $bottle(2, 'Gin no link', 5, 20000),
    3 => $bottle(3, 'Rum free', 4, 18000),
    4 => ['id' => 4, 'name' => 'Lemons', 'category' => 'FRUIT', 'unit' => 'each', 'current_quantity' => 50, 'cost_per_unit' => 100],
    5 => ['id' => 5, 'name' => 'Sugar', 'category' => 'DRY', 'unit' => 'kg', 'current_quantity' => 10, 'cost_per_unit' => 1500],
    8 => ['id' => 8, 'name' => 'Bleach', 'category' => 'CLEANING', 'unit' => 'pcs', 'current_quantity' => 6, 'cost_per_unit' => 3000],
    9 => ['id' => 9, 'name' => 'Toilet roll', 'category' => 'HOUSEKEEPING', 'unit' => 'roll', 'current_quantity' => 40, 'cost_per_unit' => 500],
    10 => ['id' => 10, 'name' => 'Coke', 'category' => 'SOFT DRINKS', 'unit' => 'can', 'current_quantity' => 24, 'cost_per_unit' => 700],
    11 => ['id' => 11, 'name' => 'Juice 1L', 'category' => 'JUICE', 'unit' => 'each', 'current_quantity' => 5, 'cost_per_unit' => 2500],
    12 => ['id' => 12, 'name' => 'Brandy', 'category' => 'SPIRITS', 'unit' => 'each', 'current_quantity' => 2, 'cost_per_unit' => 15000, 'measure_big_name' => 'bottle', 'measure_small_name' => 'tot', 'measure_per_big' => 30, 'measure_stock_side' => 'big'],
];
$r3 = rh_spp_build($items3, [$unitLink(301, 'Rum free', 3, 0)], [4 => true], 17.5, false, $list);
$np = array_column($r3['no_price'], 'stock_name');
ok('no price: an item with no link is listed under "No selling price yet"', in_array('Gin no link', $np, true), json_encode($np));
ok('no price: an item whose only menu price is 0 is listed too (0 is not a selling price)', in_array('Rum free', $np, true) && count($r3['lines']) === 0, json_encode($np));
ok('no price: kitchen ingredients and weight units are not listed', !in_array('Lemons', $np, true) && !in_array('Sugar', $np, true), json_encode($np));
ok('no price: drinks only, cleaning and housekeeping stock is not listed', !in_array('Bleach', $np, true) && !in_array('Toilet roll', $np, true), json_encode($np));
ok('no price: a can, an item with a size in its name and one with its own pour measure are drinks', in_array('Coke', $np, true) && in_array('Brandy', $np, true) && in_array('Juice 1L', $np, true), json_encode($np));
ok('no price: count on the card', (int)$r3['totals']['no_price'] === 5, (string)$r3['totals']['no_price']);

// 4. Sells below cost (one shot costs 333.33, sold at 200)
$items4 = [6 => $bottle(6, 'Cheap pour', 2, 10000)];
$r4 = rh_spp_build($items4, [$shotLink(401, 'Cheap shot', 6, 200)], [], 17.5, false, $list);
ok('below cost: flagged and counted', $r4['lines'][0]['below_cost'] === true && (int)$r4['totals']['below_cost'] === 1, json_encode($r4['lines'][0]));
ok('below cost: the profit is negative, so the flag agrees with it', (float)$r4['lines'][0]['profit'] < 0);
$r4b = rh_spp_build($items4, [$shotLink(401, 'Even shot', 6, 333.33)], [], 0.0, false, $list);
ok('below cost: a price equal to the cost (within a cent) is not flagged', $r4b['lines'][0]['below_cost'] === false, json_encode($r4b['lines'][0]));

// 5. Two prices for one item: two lines, stock cost once, sales from the lowest line
$r5 = rh_spp_build($items, [$shotLink(101, 'Jameson shot', 1, 3500), $unitLink(102, 'Jameson bottle', 1, 80000)], [], 17.5, false, $list);
$in = array_values(array_filter($r5['lines'], static fn($x) => $x['in_totals']));
ok('two prices: one line per menu price', count($r5['lines']) === 2);
ok('two prices: exactly one line counts in the totals, the bottle (960,000 < 1,260,000)', count($in) === 1 && $in[0]['menu_item_id'] === 102, json_encode($in));
ok('two prices: stock cost counted once (540,000, not 1,080,000)', money_eq($r5['totals']['cost'], 540000.00) && money_eq($r5['totals']['sales'], 960000.00)
    && money_eq($r5['totals']['profit'], 420000.00) && (int)$r5['totals']['items'] === 1, json_encode($r5['totals']));

// 6. Cost not known: shown as such, never 0, left out of every total
$items6 = $items + [7 => $bottle(7, 'No cost', 6, 0)];
$r6 = rh_spp_build($items6, [$shotLink(101, 'Jameson shot', 1, 3500), $shotLink(701, 'No cost shot', 7, 3000)], [], 17.5, false, $list);
$nc = array_values(array_filter($r6['lines'], static fn($x) => $x['ingredient_id'] === 7))[0] ?? [];
ok('cost not known: stock cost and profit are null, not 0', ($nc['cost_known'] ?? true) === false && $nc['stock_cost'] === null && $nc['profit'] === null && $nc['margin'] === null, json_encode($nc));
ok('cost not known: not in the totals (sales still 1,260,000) and counted on its card', money_eq($r6['totals']['sales'], 1260000.00) && money_eq($r6['totals']['cost'], 540000.00)
    && (int)$r6['totals']['cost_unknown'] === 1 && empty($nc['in_totals']), json_encode($r6['totals']));
ok('cost not known: never flagged below cost', ($nc['below_cost'] ?? true) === false);

// 7. Stock below zero counts as nothing
$r7 = rh_spp_build([1 => $bottle(1, 'Jameson', -3, 45000)], [$shotLink(101, 'Jameson shot', 1, 3500)], [], 17.5, false, $list);
ok('negative stock counts as nothing', money_eq($r7['lines'][0]['sales'], 0.0) && money_eq($r7['lines'][0]['stock_cost'], 0.0) && $r7['totals']['margin'] === null);

// 8. Yield on the link: 90% yield -> 27 shots a bottle
$y = $shotLink(101, 'Jameson shot', 1, 3500);
$y['yield_percent'] = 90;
$r8 = rh_spp_build($items, [$y], [], 17.5, false, $list);
ok('yield 90%: 27 shots a bottle, the same as the till takes off', abs($r8['lines'][0]['per_unit'] - 27.0) < 1e-9, (string)$r8['lines'][0]['per_unit']);

// ---------------- Part 2: the page itself, on seeded rows that are rolled back ----------------
// Stock Reports is rendered (GET) by page_harness.php inside ONE outer transaction that can never commit. Rows are named
// '0ZZP <run> ...' (the leading 0 puts them first in the name-sorted "No selling price yet" list; it is also shown 50 a page).
$run = strtoupper(bin2hex(random_bytes(3)));
$p = '0ZZP ' . $run . ' ';
$q = static fn(string $s): string => $pdo->quote($s);
$slug = 'zzp-' . strtolower($run);
// Kept short: page_harness.php takes its config as one command-line argument (8 KB at most), so rows are written as tables joined by name.
$shot = round(25 / 750, 6);
$setup = [
    "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES"
        . " (CONCAT(@p,'Bar'), CONCAT(@s,'-bar'), 'food_service', 'bar', 999, 1, 1, 999, 1), (CONCAT(@p,'Kitchen'), CONCAT(@s,'-kit'), 'food_service', 'kitchen', 999, 1, 1, 999, 1)",
    // Lonely gin is only in a cocktail, an archived till item and an old-category link: it has no selling price. Lime is a kitchen ingredient.
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES"
        . " (CONCAT(@p,'Jameson'),'ZZP','bottle',12,45000,750), (CONCAT(@p,'Cheap pour'),'ZZP','bottle',2,10000,750), (CONCAT(@p,'No cost'),'ZZP','bottle',6,0,750),"
        . " (CONCAT(@p,'Lonely gin'),'ZZP','bottle',5,20000,750), (CONCAT(@p,'Tonic'),'ZZP','can',10,500,NULL), (CONCAT(@p,'Lime'),'ZZP','each',40,100,NULL), (CONCAT(@p,'Mop'),'ZZP','each',3,2000,NULL)",
    // till items: name, price, available, on till/room service, station (Archived gin = archived; Paused shot = switched off for now)
    "INSERT INTO menu_items (category_id, item_name, price, category, is_available, display_order, station, show_pos, show_room_service)"
        . " SELECT c.id, CONCAT(@p,t.n), t.p, c.name, t.a, 0, c.default_station, t.s, t.s FROM ("
        . "SELECT 'Jameson shot' n, 3500 p, 1 a, 1 s, 'bar' k UNION ALL SELECT 'Jameson bottle',80000,1,1,'bar' UNION ALL SELECT 'Cheap shot',200,1,1,'bar'"
        . " UNION ALL SELECT 'No cost shot',3000,1,1,'bar' UNION ALL SELECT 'Old category shot',3000,1,1,'bar' UNION ALL SELECT 'Gin cocktail',6000,1,1,'bar'"
        . " UNION ALL SELECT 'Archived gin',5000,0,0,'bar' UNION ALL SELECT 'Lime dish',2000,1,1,'kit' UNION ALL SELECT 'Paused shot',3500,0,1,'bar'"
        . ") t JOIN menu_categories c ON c.slug = CONCAT(@s,'-',t.k)",
    // Old category shot's recipe is under a slug its item is no longer in (the till finds nothing)
    "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes) SELECT m.id, IF(m.item_name = CONCAT(@p,'Old category shot'), CONCAT(@s,'-old'), c.slug), 1, ''"
        . " FROM menu_items m JOIN menu_categories c ON c.id = m.category_id WHERE m.item_name LIKE CONCAT(@p,'%')",
    // recipe lines: drink, stock item, quantity per sale, measure typed (Gin cocktail has two stock items: left out)
    "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent, entry_measure, entry_qty, entry_ml)"
        . " SELECT r.id, i.id, t.q, 100, t.m, t.mq, t.ml FROM ("
        . "SELECT 'Jameson shot' d, 'Jameson' g, $shot q, 'shot' m, 1 mq, 25 ml UNION ALL SELECT 'Jameson bottle','Jameson',1,NULL,NULL,NULL"
        . " UNION ALL SELECT 'Cheap shot','Cheap pour',$shot,'shot',1,25 UNION ALL SELECT 'Paused shot','Cheap pour',$shot,'shot',1,25"
        . " UNION ALL SELECT 'No cost shot','No cost',$shot,'shot',1,25 UNION ALL SELECT 'Old category shot','Lonely gin',$shot,'shot',1,25"
        . " UNION ALL SELECT 'Archived gin','Lonely gin',$shot,'shot',1,25 UNION ALL SELECT 'Gin cocktail','Lonely gin'," . ($shot * 2) . ",NULL,NULL,NULL"
        . " UNION ALL SELECT 'Gin cocktail','Tonic',1,NULL,NULL,NULL UNION ALL SELECT 'Lime dish','Lime',1,NULL,NULL,NULL"
        . ") t JOIN menu_items mi ON mi.item_name = CONCAT(@p,t.d) JOIN stock_recipes r ON r.menu_item_id = mi.id AND r.menu_type LIKE CONCAT(@s,'%')"
        . " JOIN stock_ingredients i ON i.name = CONCAT(@p,t.g)",
];

$setup = array_map(static fn(string $s): string => str_replace(['@p', '@s'], [$q($p), $q($slug)], $s), $setup); // literals: user variables clash with the table collation

$render = static function (array $get, array $grep, string $dump = '') use ($root, $setup): array {
    $cfg = ['method' => 'GET', 'get' => $get, 'setup' => $setup, 'grep' => $grep];
    if ($dump !== '') { $cfg['dump_html'] = $dump; }
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' admin/stock-reports.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $h = ['error' => 'no result', 'raw' => substr((string)$out, -800)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
    return $h;
};
$safe = static fn(array $h): bool => !empty($h['bytes']) && !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']);
$found = static fn(array $h, string $k): bool => !empty($h['found'][$k]);

// The cards add the hotel's own drinks to these rows (an import of "Drinks for the menu" links some), so they are checked as
// the hotel's own totals now (read before the render, same rule) plus what the seeded rows add: Jameson once at its bottle row
// (960,000 < 1,260,000), Cheap pour once at its 200 shot (12,000 < 210,000), No cost left out; cost 540,000 + 20,000.
$vr = rh_spp_vat_rate();
$baseSrc = rh_spp_load($pdo);
$cards = static function (bool $vatOut) use ($baseSrc, $vr): array {
    $b = rh_spp_build($baseSrc['items'], $baseSrc['links'], $baseSrc['kitchen'], $vr, $vatOut, $baseSrc['list'])['totals'];
    $sales = (float)$b['sales'] + ($vatOut ? rh_spp_net(960000.00, $vr) + rh_spp_net(12000.00, $vr) : 972000.00);
    $cost = (float)$b['cost'] + 560000.00;
    return [number_format($sales, 2), number_format($cost, 2), number_format($sales - $cost, 2)];
};
[$inSales, $inCost, $inProfit] = $cards(false);
[$outSales, $outCost, $outProfit] = $cards(true);

// VAT left in
$h1 = $render(['tab' => 'profit', 'vat' => '0', 'size' => '50'], [$inSales, $inCost, $inProfit, 'Profit as a share of projected sales, VAT included',
    $p . 'Jameson shot', $p . 'Jameson bottle', $p . 'Cheap shot', $p . 'Paused shot', $p . 'No cost shot',
    $p . 'Old category shot', $p . 'Gin cocktail', $p . 'Archived gin', $p . 'Lime dish',
    '960,000.00', '210,000.00', 'Sells below cost', 'In totals', 'Cost not known', '1 shot (25 ml)', 'Projected profit', 'Take VAT out',
], getenv('SPP_DUMP') ?: '');
ok('page (VAT left in): rendered and rolled back, nothing leaked', $safe($h1), json_encode(array_intersect_key($h1, array_flip(['error', 'raw', 'rolled_back', 'leak', 'marker_lost', 'bytes']))));
ok('page: one row per menu price (Jameson shot and Jameson bottle)', $found($h1, $p . 'Jameson shot') && $found($h1, $p . 'Jameson bottle'));
ok('page: bottle row 960,000 and paused shot row 210,000 (2 x 30 x 3,500)', $found($h1, '960,000.00') && $found($h1, '210,000.00'));
ok('page: cards = the hotel\'s own totals + each seeded stock item once at its lowest line (+972,000 sales, +560,000 cost, +412,000 profit)',
    $found($h1, $inSales) && $found($h1, $inCost) && $found($h1, $inProfit), "$inSales / $inCost / $inProfit " . json_encode($h1['found'] ?? []));
ok('page: the margin card says VAT included when the switch is off', $found($h1, 'Profit as a share of projected sales, VAT included'));
ok('page: below-cost flag and "In totals" mark shown', $found($h1, 'Sells below cost') && $found($h1, 'In totals'));
ok('page: unknown cost shown as "Cost not known"', $found($h1, 'Cost not known') && $found($h1, $p . 'No cost shot'));
ok('page: the link written as the measure it was typed in', $found($h1, '1 shot (25 ml)'));
ok('page: a drink switched off for now still has its row', $found($h1, $p . 'Paused shot'));
ok('page: a link under an old category, a cocktail, an archived till item and a kitchen dish are not rows',
    !$found($h1, $p . 'Old category shot') && !$found($h1, $p . 'Gin cocktail') && !$found($h1, $p . 'Archived gin') && !$found($h1, $p . 'Lime dish'));
ok('page: the VAT switch is there at a hotel with VAT on', rh_spp_vat_rate() <= 0 || $found($h1, 'Take VAT out'));

// Default: VAT taken out when VAT is on
$net = number_format(rh_spp_net(960000.00, $vr), 2);
$h2 = $render(['tab' => 'profit', 'size' => '50'], [$net, $outSales, $outCost, $outProfit, 'Profit as a share of projected sales, VAT taken out']);
ok('page (default): VAT taken out of the bottle row (' . $net . ') and the cards (' . $outSales . ' / ' . $outProfit . ')',
    $safe($h2) && $found($h2, $net) && $found($h2, $outSales) && $found($h2, $outCost) && $found($h2, $outProfit), json_encode($h2['found'] ?? $h2));
ok('page (default): the margin card says VAT taken out', $vr <= 0 || $found($h2, 'Profit as a share of projected sales, VAT taken out'));

// No selling price yet: the archived item's and the old-category stock is listed; kitchen ingredients are not
$h3 = $render(['tab' => 'profit', 'filter' => 'noprice', 'size' => '50'], [$p . 'Lonely gin', $p . 'Tonic', $p . 'Lime', $p . 'Mop', $p . 'Jameson', 'menu-management.php']);
ok('page (No selling price yet): Lonely gin listed, with a link to the Menu page', $safe($h3) && $found($h3, $p . 'Lonely gin') && $found($h3, 'menu-management.php'), json_encode($h3['found'] ?? $h3));
ok('page (No selling price yet): a can only used in a cocktail is listed', $found($h3, $p . 'Tonic'));
ok('page (No selling price yet): a kitchen ingredient, a non-drink (mop) and a priced drink are not listed', !$found($h3, $p . 'Lime') && !$found($h3, $p . 'Mop') && !$found($h3, $p . 'Jameson'));

// Below cost filter lists only the flagged rows
$h4 = $render(['tab' => 'profit', 'filter' => 'below', 'vat' => '0'], [$p . 'Cheap shot', $p . 'Jameson shot']);
ok('page (Sells below cost filter): only the flagged rows', $safe($h4) && $found($h4, $p . 'Cheap shot') && !$found($h4, $p . 'Jameson shot'), json_encode($h4['found'] ?? $h4));

// CSV of the tab: plain columns, the whole list
$h5 = $render(['tab' => 'profit', 'vat' => '0', 'export' => 'csv'], ['Measures per unit', 'Projected profit', $p . 'Jameson bottle', '960000', '<html']);
ok('CSV: projection columns and rows, no page markup', $safe($h5) && $found($h5, 'Measures per unit') && $found($h5, $p . 'Jameson bottle') && $found($h5, '960000') && !$found($h5, '<html'), json_encode($h5['found'] ?? $h5));

$left = 0;
foreach (['stock_ingredients' => 'name', 'menu_items' => 'item_name', 'menu_categories' => 'name'] as $t => $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $c LIKE ?");
    $st->execute([$p . '%']);
    $left += (int)$st->fetchColumn();
}
ok('no 0ZZP rows were left behind (stock, till, categories)', $left === 0, (string)$left);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
