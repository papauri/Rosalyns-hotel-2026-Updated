<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock Reports > Projected vs actual (drinks profit projection P3, owner 2026-11-20).
 * Usage (project root): php scripts/tests/stock_projected_vs_actual.php
 *
 * Part 1 is pure arithmetic on made-up rows (rh_spva_build: no database at all).
 * Part 2 seeds counts, sales and stock movements dated March 2001 inside ONE transaction that is always rolled back, and
 *        reads them with rh_spva_load (the report itself only reads).
 * Part 3 renders the page through page_harness.php (its own outer transaction, rolled back) on the same seed.
 * Every seeded row is named '0ZZV <run> ...'.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/admin/includes/stock-projected-vs-actual.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function money_eq(?float $a, float $b): bool { return $a !== null && abs($a - $b) <= BALANCE_TOLERANCE; }
function qty_eq(?float $a, float $b): bool { return $a !== null && abs($a - $b) < 0.000001; }
function row_of(array $built, string $name): ?array {
    foreach ($built['rows'] as $r) { if ($r['stock_name'] === $name) { return $r; } }
    return null;
}

// ---------------- Part 1: the arithmetic ----------------
$list = [['name' => 'shot', 'ml' => 25.0]];
$bottle = static fn(int $id, string $name, $cost): array => [
    'id' => $id, 'name' => $name, 'category' => 'WHISKYS', 'unit' => 'bottle', 'current_quantity' => 5, 'cost_per_unit' => $cost,
    'volume_ml' => 750.0, 'measure_big_name' => null, 'measure_small_name' => null, 'measure_per_big' => null, 'measure_stock_side' => null,
];
$shotLink = static fn(int $mid, string $drink, int $ingId, float $price): array => [
    'menu_item_id' => $mid, 'item_name' => $drink, 'menu_category' => 'Bar', 'price' => $price, 'is_available' => 1,
    'ingredient_id' => $ingId, 'quantity_per_portion' => round(25 / 750, 6), 'yield_percent' => 100, 'entry_measure' => 'shot', 'entry_qty' => 1, 'entry_ml' => 25,
];
$unitLink = static fn(int $mid, string $drink, int $ingId, float $price): array => [
    'menu_item_id' => $mid, 'item_name' => $drink, 'menu_category' => 'Bar', 'price' => $price, 'is_available' => 1,
    'ingredient_id' => $ingId, 'quantity_per_portion' => 1, 'yield_percent' => 100, 'entry_measure' => null, 'entry_qty' => null, 'entry_ml' => null,
];
$count = static fn(string $ref, string $at, float $actual, float $var, bool $opening = false): array =>
    ['count_id' => crc32($ref), 'reference' => $ref, 'is_opening' => $opening, 'at' => $at, 'actual' => $actual, 'variance' => $var];
$moves = static fn(float $received, float $sold, float $wasted, float $manual = 0.0): array =>
    ['sold' => $sold, 'wasted' => $wasted, 'received' => $received, 'manual_out' => $manual, 'other_out' => 0.0, 'net' => $received - $sold - $wasted - $manual];
$src = static fn(array $items, array $links, array $counts, array $mv, array $till = []): array =>
    ['spp' => ['items' => $items, 'links' => $links, 'kitchen' => [], 'list' => $list], 'counts' => $counts, 'moves' => $mv, 'till' => $till, 'from' => '2001-03-01', 'to' => '2001-03-31'];

// (a)+(i) Jameson: opening count 10 (its own +10 variance is not in the gap), 6 received, 1.5 sold, 1 wasted, closing count 11.5.
$J = [1 => $bottle(1, 'Jameson', 45000)];
$jCounts = [1 => [$count('A', '2001-03-02 10:00:00', 10, 10, true), $count('B', '2001-03-20 10:00:00', 11.5, -2)]];
$jMoves = [1 => $moves(6, 1.5, 1)];
$jTill = [1 => [['kind' => 'pos_order', 'line_id' => 11, 'taken' => 1.5, 'restored' => 0.0,
    'line' => ['menu_item_id' => 101, 'quantity' => 45, 'line_total' => 157500, 'discount_share' => 0.05, 'kept' => true]]]];
$b = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], $jCounts, $jMoves, $jTill), 17.5, false);
$r = $b['rows'][0];
ok('(a) known gap: 2 bottles unaccounted = 60 shots', qty_eq($r['gap_units'], 2.0) && qty_eq($r['gap'], 60.0), $r['gap_units'] . ' / ' . $r['gap']);
ok('(a) gap at menu price 60 x 3,500 = 210,000', money_eq($r['gap_menu'], 210000.00), (string)$r['gap_menu']);
ok('(a) gap at cost 2 x 45,000 = 90,000', money_eq($r['gap_cost'], 90000.00), (string)$r['gap_cost']);
ok('(a) plan formula (opening + received - closing - sold - wasted) = the count route (minus later variances)', qty_eq($r['gap_units'], $r['check_units']), $r['gap_units'] . ' vs ' . $r['check_units']);
ok('(i) the opening count\'s own +10 variance is not in the gap (check = 2, not -8)', qty_eq($r['check_units'], 2.0), (string)$r['check_units']);
ok('(a) actual used = opening + received - closing = 4.5 bottles = 135 shots', qty_eq($r['used_units'], 4.5) && qty_eq($r['used'], 135.0), (string)$r['used']);
ok('(a) actual used = sold + wasted + by hand + unaccounted', qty_eq($r['used'], $r['sold'] + $r['wasted'] + $r['manual_out'] + $r['gap']));
ok('(a) till: took 157,500 less the 5% order discount = 149,625 vs 157,500 at menu price, -7,875', money_eq($r['till_taken'], 149625.00)
    && money_eq($r['till_menu'], 157500.00) && money_eq($r['till_drift'], -7875.00), json_encode([$r['till_taken'], $r['till_menu'], $r['till_drift']]));
ok('(a) totals: 60 measures, 210,000, 90,000, one with a gap', qty_eq($b['totals']['gap_measures'], 60.0) && money_eq($b['totals']['gap_menu'], 210000.00)
    && money_eq($b['totals']['gap_cost'], 90000.00) && $b['totals']['with_gap'] === 1 && $b['totals']['qualified'] === 1, json_encode($b['totals']));

// (b) sold only between counts: gap 0
$b2 = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], [1 => [$count('A', '2001-03-02 10:00:00', 10, 0), $count('B', '2001-03-20 10:00:00', 8, 0)]], [1 => $moves(0, 2, 0)]), 17.5, false);
ok('(b) sold only: gap 0, value 0, no gap card', qty_eq($b2['rows'][0]['gap_units'], 0.0) && money_eq($b2['rows'][0]['gap_menu'], 0.0) && $b2['totals']['with_gap'] === 0);

// (c) stock received between counts is not in the gap; (d) recorded wastage is not in the gap
$b3 = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], [1 => [$count('A', '2001-03-02 10:00:00', 10, 0), $count('B', '2001-03-20 10:00:00', 22, 0)]], [1 => $moves(12, 0, 0)]), 17.5, false);
ok('(c) 12 received, counted 10 then 22: gap 0', qty_eq($b3['rows'][0]['gap_units'], 0.0), (string)$b3['rows'][0]['gap_units']);
$b4 = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], [1 => [$count('A', '2001-03-02 10:00:00', 10, 0), $count('B', '2001-03-20 10:00:00', 7, 0)]], [1 => $moves(0, 1, 2)]), 17.5, false);
ok('(d) 2 wasted + 1 sold, counted 10 then 7: gap 0, used 90 shots, wasted 60', qty_eq($b4['rows'][0]['gap_units'], 0.0) && qty_eq($b4['rows'][0]['used'], 90.0) && qty_eq($b4['rows'][0]['wasted'], 60.0));

// (f) only one count: a message, no figure
$b5 = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], [1 => [$count('A', '2001-03-02 10:00:00', 10, 0)]], []), 17.5, false);
$r5 = $b5['rows'][0];
ok('(f) one count only: not worked out, no gap figure, counted in "needs two counts"', $r5['qualified'] === false && !array_key_exists('gap', $r5)
    && $b5['totals']['needs'] === 1 && $b5['totals']['qualified'] === 0 && ($r5['last_count']['reference'] ?? '') === 'A');

// (g) VAT switch: net = gross / (1 + rate), the till's rule, on the gap and on both sides of the till line; cost has no VAT
$bv = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], $jCounts, $jMoves, $jTill), 17.5, true);
$rv = $bv['rows'][0];
ok('(g) VAT out: gap at menu price 210,000 / 1.175 = 178,723.40', money_eq($rv['gap_menu'], 178723.40), (string)$rv['gap_menu']);
ok('(g) VAT out: cost value unchanged (90,000)', money_eq($rv['gap_cost'], 90000.00));
ok('(g) VAT out: till line both sides without VAT', money_eq($rv['till_taken'], rh_spp_net(149625.00, 17.5)) && money_eq($rv['till_menu'], rh_spp_net(157500.00, 17.5)), json_encode([$rv['till_taken'], $rv['till_menu']]));
ok('(g) VAT out flagged in totals; VAT off (rate 0) leaves prices as they are', $bv['totals']['vat_out'] === true
    && money_eq(rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], $jCounts, $jMoves), 0.0, true)['rows'][0]['gap_menu'], 210000.00));

// Valued once at the drink that brings in the least per bottle: bottle at 80,000 < 30 shots x 3,500 = 105,000
$bb = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500), $unitLink(102, 'Jameson bottle', 1, 80000)], $jCounts, $jMoves), 17.5, false);
ok('one row per stock item, valued at the bottle price (2 x 80,000 = 160,000), gap in bottles', count($bb['rows']) === 1 && money_eq($bb['rows'][0]['gap_menu'], 160000.00)
    && qty_eq($bb['rows'][0]['gap'], 2.0) && $bb['rows'][0]['drink'] === 'Jameson bottle', json_encode($bb['rows'][0]['gap_menu']));

// Cost not known: null, left out of the cost total; more found than expected: a negative gap
$bc = rh_spva_build($src([1 => $bottle(1, 'Jameson', 0)], [$shotLink(101, 'Jameson shot', 1, 3500)], [1 => [$count('A', '2001-03-02 10:00:00', 10, 0), $count('B', '2001-03-20 10:00:00', 11, 1)]], [1 => $moves(0, 0, 0)]), 17.5, false);
ok('cost not known stays null and is counted, never 0; extra found = negative gap', $bc['rows'][0]['gap_cost'] === null && $bc['totals']['cost_unknown'] === 1
    && qty_eq($bc['rows'][0]['gap_units'], -1.0) && $bc['totals']['with_gap'] === 0);

// Till line: a mixed drink (not this item's own) is not priced and flagged; a line voided in full adds nothing
$mixTill = [1 => [
    ['kind' => 'pos_order', 'line_id' => 21, 'taken' => 0.1, 'restored' => 0.0, 'line' => ['menu_item_id' => 999, 'quantity' => 1, 'line_total' => 9000, 'discount_share' => 0.0, 'kept' => true]],
    ['kind' => 'pos_order', 'line_id' => 22, 'taken' => 0.5, 'restored' => 0.5, 'line' => ['menu_item_id' => 101, 'quantity' => 15, 'line_total' => 52500, 'discount_share' => 0.0, 'kept' => false]],
    ['kind' => 'room_service', 'line_id' => 23, 'taken' => 1.0, 'restored' => 0.0, 'line' => ['menu_item_id' => 101, 'quantity' => 30, 'line_total' => 105000, 'discount_share' => 0.0, 'kept' => true]],
]];
$bm = rh_spva_build($src($J, [$shotLink(101, 'Jameson shot', 1, 3500)], $jCounts, $jMoves, $mixTill), 17.5, false);
$rm = $bm['rows'][0];
ok('till: cocktail use flagged as not priced (3 shots), fully voided line ignored, room service line priced', $rm['till_partly'] === true && qty_eq($rm['till_untied'], 3.0)
    && $rm['till_lines'] === 1 && money_eq($rm['till_drift'], 0.0) && money_eq($rm['till_taken'], 105000.00), json_encode([$rm['till_untied'], $rm['till_lines'], $rm['till_taken']]));

// ---------------- Part 2: the database read, on rows rolled back at the end ----------------
$run = strtoupper(bin2hex(random_bytes(3)));
$p = '0ZZV ' . $run . ' ';
$q = static fn(string $s): string => $pdo->quote($s);
$slug = 'zzv-' . strtolower($run);
$ref = 'ZZV' . $run . '-';
$shot = round(25 / 750, 6);
$at = static fn(string $d): string => "'2001-03-$d'";
// Kept short: page_harness.php takes its config as one command-line argument (8 KB at most).
$setup = [
    "INSERT INTO menu_categories (name, slug, business_context, default_station, sort_order, shows_on_pos, shows_on_room_service, display_order, is_active) VALUES (CONCAT(@p,'Bar'), @s, 'food_service', 'bar', 999, 1, 1, 999, 1)",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES (CONCAT(@p,'Jameson'),'ZZV','bottle',11.5,45000,750), (CONCAT(@p,'Gin'),'ZZV','bottle',4,20000,750), (CONCAT(@p,'Rum'),'ZZV','bottle',3,15000,750)",
    "INSERT INTO menu_items (category_id, item_name, price, category, is_available, display_order, station, show_pos, show_room_service) SELECT c.id, CONCAT(@p,t.n), t.p, c.name, 1, 0, 'bar', 1, 1 FROM ("
        . "SELECT 'Jameson shot' n, 3500 p UNION ALL SELECT 'Gin shot', 2000 UNION ALL SELECT 'Rum shot', 2500) t JOIN menu_categories c ON c.slug = @s",
    "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes) SELECT id, @s, 1, '' FROM menu_items WHERE item_name LIKE CONCAT(@p,'%')",
    "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent, entry_measure, entry_qty, entry_ml) SELECT r.id, i.id, $shot, 100, 'shot', 1, 25"
        . " FROM stock_recipes r JOIN menu_items m ON m.id = r.menu_item_id AND r.menu_type = @s JOIN stock_ingredients i ON i.name = REPLACE(m.item_name, ' shot', '')",
    // counts: A opening (Jameson), K1 spot (Gin), M spot (Jameson + Rum), B full (Jameson), K2 spot (Gin); approved 5 minutes after the count
    "INSERT INTO stock_counts (reference, count_date, scope, status, is_opening, snapshot_at, approved_at) SELECT CONCAT(@r,t.k), DATE(t.a), t.sc, 'approved', t.o, t.a, t.a + INTERVAL 5 MINUTE FROM ("
        . "SELECT 'A' k, TIMESTAMP({$at('02 10:00')}) a, 'full' sc, 1 o UNION ALL SELECT 'K1', {$at('03 10:00')}, 'spot', 0 UNION ALL SELECT 'M', {$at('15 10:00')}, 'spot', 0"
        . " UNION ALL SELECT 'B', {$at('20 10:00')}, 'full', 0 UNION ALL SELECT 'K2', {$at('21 10:00')}, 'spot', 0) t",
    "INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, counted_at) SELECT c.id, i.id, t.sy, t.ac, t.v, c.snapshot_at FROM ("
        . "SELECT 'A' k, 'Jameson' g, 0 sy, 10 ac, 10 v UNION ALL SELECT 'K1','Gin',5,5,0 UNION ALL SELECT 'M','Jameson',13.5,13,-0.5 UNION ALL SELECT 'M','Rum',3,3,0"
        . " UNION ALL SELECT 'B','Jameson',13,11.5,-1.5 UNION ALL SELECT 'K2','Gin',4,4,0) t JOIN stock_counts c ON c.reference = CONCAT(@r,t.k) JOIN stock_ingredients i ON i.name = CONCAT(@p,t.g)",
    "INSERT INTO stock_orders (reference, status, subtotal, discount_amount, total_amount) VALUES (CONCAT(@r,'O1'),'paid',157500,7875,149625), (CONCAT(@r,'O2'),'voided',105000,0,0), (CONCAT(@r,'O3'),'paid',60000,0,60000)",
    // till lines: L1 45 Jameson shots paid, L2 15 voided and put back, L3 15 voided to wastage, L4 30 gin shots
    "INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, unit_price, quantity, line_total, kds_status) SELECT o.id, m.id, @s, CONCAT(@p,t.l), t.u, t.q, t.u * t.q, t.k FROM ("
        . "SELECT 'L1' l, 'O1' o, 'Jameson shot' d, 3500 u, 45 q, 'served' k UNION ALL SELECT 'L2','O2','Jameson shot',3500,15,'void' UNION ALL SELECT 'L3','O2','Jameson shot',3500,15,'void'"
        . " UNION ALL SELECT 'L4','O3','Gin shot',2000,30,'served') t JOIN stock_orders o ON o.reference = CONCAT(@r,t.o) JOIN menu_items m ON m.item_name = CONCAT(@p,t.d)",
    // movements (source line or count by name); the M measure fix (-0.25) and every variance row are left out of the gap
    "INSERT INTO stock_adjustments (ingredient_id, quantity_change, source_type, source_id, reason, created_at, restore_of_type) SELECT i.id, t.q, t.ty, COALESCE(s.id, c.id), t.rs, t.a, t.ro FROM ("
        . "SELECT 'Jameson' g, 10 q, 'variance' ty, '' ln, 'A' cr, 'count' rs, TIMESTAMP({$at('02 10:05')}) a, NULL ro"
        . " UNION ALL SELECT 'Jameson',6,'stock_in','','','in',{$at('05 09:00')},NULL UNION ALL SELECT 'Jameson',-1.5,'pos_order','L1','','sale',{$at('10 20:00')},NULL"
        . " UNION ALL SELECT 'Jameson',-0.5,'pos_order','L2','','sale',{$at('10 20:00')},NULL UNION ALL SELECT 'Jameson',0.5,'void_restore','L2','','void',{$at('11 09:00')},'pos_order'"
        . " UNION ALL SELECT 'Jameson',-0.5,'wastage','','','spilt',{$at('12 09:00')},NULL UNION ALL SELECT 'Jameson',-0.5,'pos_order','L3','','Voided to wastage: t',{$at('12 10:00')},NULL"
        . " UNION ALL SELECT 'Jameson',-0.25,'variance','','M','count',{$at('15 10:05')},NULL UNION ALL SELECT 'Jameson',-0.25,'manual','','M','Stock count X correction: t',{$at('15 10:10')},NULL"
        . " UNION ALL SELECT 'Jameson',-1.5,'variance','','B','count',{$at('20 10:05')},NULL UNION ALL SELECT 'Gin',-1,'pos_order','L4','','sale',{$at('10 21:00')},NULL"
        . ") t JOIN stock_ingredients i ON i.name = CONCAT(@p,t.g) LEFT JOIN stock_order_items s ON t.ln <> '' AND s.item_name = CONCAT(@p,t.ln) LEFT JOIN stock_counts c ON t.cr <> '' AND c.reference = CONCAT(@r,t.cr)",
];
$setup = array_map(static fn(string $s): string => str_replace(['@p', '@s', '@r'], [$q($p), $q($slug), $q($ref)], $s), $setup); // literals: user variables clash with the table collation

$d = null; $d2 = null;
$pdo->beginTransaction();
try {
    foreach ($setup as $sql) { $pdo->exec($sql); }
    $d = rh_spva_build(rh_spva_load($pdo, '2001-03-01', '2001-03-31'), 17.5, false);
    $d2 = rh_spva_build(rh_spva_load($pdo, '2001-03-04', '2001-03-31'), 17.5, false);
} catch (Throwable $e) {
    ok('seed and read', false, $e->getMessage());
} finally {
    $pdo->rollBack();
}
if (is_array($d)) {
    $j = row_of($d, $p . 'Jameson');
    $g = row_of($d, $p . 'Gin');
    $rm = row_of($d, $p . 'Rum');
    ok('db: Jameson worked out from its opening count A to its closing count B (3 counts in range)', $j && $j['qualified'] && $j['counts_in_range'] === 3
        && $j['opening']['reference'] === $ref . 'A' && $j['closing']['reference'] === $ref . 'B', json_encode($j['opening'] ?? null));
    ok('db (c): 6 received', $j && qty_eq($j['received'], 6.0), (string)($j['received'] ?? ''));
    ok('db (e): void_restore takes the put-back off sold (2.5 taken, 0.5 put back, 0.5 voided to wastage = 1.5 sold)', $j && qty_eq($j['sold_units'], 1.5), (string)($j['sold_units'] ?? ''));
    ok('db (d): wastage + voided to wastage = 1 wasted, not in the gap', $j && qty_eq($j['wasted_units'], 1.0), (string)($j['wasted_units'] ?? ''));
    ok('db: the count measure fix is not "taken off by hand"', $j && qty_eq($j['manual_out_units'], 0.0), (string)($j['manual_out_units'] ?? ''));
    ok('db (a): gap 2 bottles = 60 shots, 210,000 at menu price, 90,000 at cost', $j && qty_eq($j['gap_units'], 2.0) && qty_eq($j['gap'], 60.0)
        && money_eq($j['gap_menu'], 210000.00) && money_eq($j['gap_cost'], 90000.00), json_encode([$j['gap_units'] ?? null, $j['gap_menu'] ?? null, $j['gap_cost'] ?? null]));
    ok('db (a)+(i): the plan formula equals the count route (later variances -0.5 and -1.5; A\'s +10 left out)', $j && qty_eq($j['check_units'], $j['gap_units']), (string)($j['check_units'] ?? ''));
    ok('db: till line = L1 only (149,625 after its 5% discount vs 157,500; L2 put back, L3 voided to wastage)', $j && $j['till_lines'] === 1
        && money_eq($j['till_taken'], 149625.00) && money_eq($j['till_menu'], 157500.00) && money_eq($j['till_drift'], -7875.00), json_encode([$j['till_lines'] ?? null, $j['till_taken'] ?? null, $j['till_menu'] ?? null]));
    ok('db (b)+(h): Gin uses its own spot counts K1 -> K2, sold only, gap 0, till equal', $g && $g['qualified'] && $g['opening']['reference'] === $ref . 'K1'
        && $g['closing']['reference'] === $ref . 'K2' && qty_eq($g['gap_units'], 0.0) && qty_eq($g['sold'], 30.0) && money_eq($g['till_drift'], 0.0), json_encode($g));
    ok('db (f)+(h): Rum counted once (M): message row, no figure', $rm && $rm['qualified'] === false && ($rm['last_count']['reference'] ?? '') === $ref . 'M');
    $j2 = row_of($d2, $p . 'Jameson');
    $g2 = row_of($d2, $p . 'Gin');
    ok('db: dates from 4 March snap Jameson to M -> B: 13 + 0 - 11.5 = 1.5 unaccounted = the route', $j2 && $j2['qualified'] && $j2['opening']['reference'] === $ref . 'M'
        && qty_eq($j2['gap_units'], 1.5) && qty_eq($j2['check_units'], 1.5) && qty_eq($j2['sold_units'], 0.0), json_encode([$j2['gap_units'] ?? null, $j2['check_units'] ?? null]));
    ok('db: dates from 4 March leave Gin with one count (K2): message', $g2 && $g2['qualified'] === false);
}
$left = $pdo->prepare("SELECT (SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?) + (SELECT COUNT(*) FROM stock_counts WHERE reference LIKE ?) + (SELECT COUNT(*) FROM stock_orders WHERE reference LIKE ?)");
$left->execute([$p . '%', $ref . '%', $ref . '%']);
ok('db: nothing left behind after the rollback', (int)$left->fetchColumn() === 0);

// ---------------- Part 3: the page, rendered on the same seed (rolled back) ----------------
$render = static function (array $get, array $grep, array $seed) use ($root): array {
    $cfg = ['method' => 'GET', 'get' => $get, 'setup' => $seed, 'grep' => $grep];
    $arg = base64_encode(json_encode($cfg));
    if (strlen($arg) > 8000) { return ['error' => 'config too long: ' . strlen($arg)]; }
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' admin/stock-reports.php ' . escapeshellarg($arg) . ' 2>&1');
    $h = ['error' => 'no result', 'raw' => substr((string)$out, -800)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
    return $h;
};
$safe = static fn(array $h): bool => !empty($h['bytes']) && !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']);
$found = static fn(array $h, string $k): bool => !empty($h['found'][$k]);
$brief = static fn(array $h): string => json_encode(array_intersect_key($h, array_flip(['error', 'raw', 'rolled_back', 'leak', 'marker_lost', 'bytes', 'found'])));

$range = ['tab' => 'pva', 'from' => '2001-03-01', 'to' => '2001-03-31', 'size' => '50'];
$h1 = $render($range + ['vat' => '0'], [$p . 'Jameson', $p . 'Gin', $p . 'Rum', '210,000.00', '90,000.00', '7,875.00', '149,625.00', 'Needs two approved counts between these dates',
    $ref . 'A', $ref . 'B', $ref . 'K1', 'Unaccounted measures', 'Projected vs actual', '209,625.00'], $setup);
ok('page (VAT left in): rendered and rolled back, nothing leaked', $safe($h1), $brief($h1));
ok('page: Jameson 210,000 at menu price, 90,000 at cost, till -7,875', $found($h1, '210,000.00') && $found($h1, '90,000.00') && $found($h1, '7,875.00') && $found($h1, '149,625.00'), $brief($h1));
ok('page: each item shows its own opening and closing count', $found($h1, $ref . 'A') && $found($h1, $ref . 'B') && $found($h1, $ref . 'K1'));
ok('page: Rum (one count) shows the message instead of a figure', $found($h1, $p . 'Rum') && $found($h1, 'Needs two approved counts between these dates'));
ok('page: till card adds both counted drinks (took 209,625 = 149,625 + 60,000)', $found($h1, '209,625.00'), $brief($h1));
$vr = rh_spp_vat_rate();
if ($vr > 0) {
    $net = number_format(rh_spp_net(210000.00, $vr), 2);
    $h2 = $render($range, [$net, 'Take VAT out', 'VAT taken out'], $setup);
    ok('page (default, VAT on): unaccounted at menu price shown without VAT (' . $net . ')', $safe($h2) && $found($h2, $net) && $found($h2, 'Take VAT out'), $brief($h2));
} else {
    ok('page: VAT is off at this hotel, no switch to test', true);
}
$h3 = $render($range + ['vat' => '0', 'filter' => 'gap'], [$p . 'Jameson', $p . 'Gin'], $setup);
ok('page (Unaccounted stock filter): Jameson only', $safe($h3) && $found($h3, $p . 'Jameson') && !$found($h3, $p . 'Gin'), $brief($h3));
$h4 = $render($range + ['vat' => '0', 'export' => 'csv'], ['Unaccounted (measures)', $p . 'Jameson', '210000', '<html'], $setup);
ok('CSV: plain columns, no page markup', $safe($h4) && $found($h4, 'Unaccounted (measures)') && $found($h4, '210000') && !$found($h4, '<html'), $brief($h4));
// The hotel's own data now (no seed): Liwonde has one approved count, so no drink can be worked out yet
$h5 = $render(['tab' => 'pva'], ['No drink has two approved counts between these dates', 'No drink on the menu is linked to a stock item on its own yet', 'Unaccounted at menu price'], []);
ok('page (real data, last 30 days): says plainly when no drink has two counts (or no drink is linked)', $safe($h5) && $found($h5, 'Unaccounted at menu price')
    && ($found($h5, 'No drink has two approved counts between these dates') || $found($h5, 'No drink on the menu is linked to a stock item on its own yet')), $brief($h5));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
