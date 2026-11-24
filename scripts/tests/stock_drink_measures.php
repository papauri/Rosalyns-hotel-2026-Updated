<?php
/**
 * Drink measures (Step U2 a+b, owner 2026-11-19). Usage: php scripts/tests/stock_drink_measures.php  (test platform only)
 *
 * Covers:
 *   1. The maths (rh_drink_*), no database: bottle size from the stock unit / the item / the name, shots per bottle and cost
 *      per shot, "2 shots" -> 0.06 bottle (1 L, 30 ml) and the item's own count measure (N 30 -> 0.066667), litre and ml
 *      stock, stock kept in shots, weights refused, unknown measure / bad amount refused, list checks, bottle size input,
 *      a saved line shown in its measure only while it still gives the saved figure.
 *   2. Same answers from admin/js/stock-drink-measures.js (node) as from PHP, case by case.
 *   3. One rolled-back transaction: Menu Management save in a measure (stock figure 6 dp + entry kept), a till sale and a
 *      room service sale take the right quantity at the batch cost; a bottle size change moves no stock, no cost and no
 *      saved recipe figure.
 *   4. Through the REAL pages (scripts/tests/page_harness.php, rolled back): Drink measures list save (logged old -> new),
 *      refused lists, bottle size on the item form (logged; an old form never wipes it; a litre unit never keeps one),
 *      Stock > Recipes save in a measure (server figure, the page figure ignored), get_recipe returns the typed measure,
 *      and the markup of the three screens.
 * Leaves nothing behind.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once __DIR__ . '/../../config/database.php';
const ZZ_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // the test platform's database (name kept out of the public repo)
if (sha1(DB_NAME) !== ZZ_DB_SHA1) {
    fwrite(STDERR, "Refusing: this test runs on the test platform only.\n");
    exit(2);
}
require_once __DIR__ . '/../../includes/form-validation.php';
require_once __DIR__ . '/../../admin/includes/menu-recipe-lib.php';
require_once __DIR__ . '/../../admin/includes/permissions.php'; // logActivity()

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function near($a, float $b, float $eps = 0.0000011): bool { return $a !== null && abs((float)$a - $b) < $eps; }
function conv(float $q, string $m, array $ing, array $list): ?float
{
    try { return rh_drink_measure_to_stock($q, $m, $ing, $list)['qty']; } catch (RuntimeException $e) { return null; }
}
function refused(callable $f): string
{
    try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); }
    return '';
}

/* ---------- 1. The maths ---------- */
check(rh_stock_drink_ready($pdo), 'Migration 089 has run (volume_ml + three entry columns)');
$L30 = [['name' => 'shot', 'ml' => 30.0], ['name' => 'double', 'ml' => 50.0], ['name' => 'glass of wine', 'ml' => 175.0]];
$L25 = [['name' => 'shot', 'ml' => 25.0], ['name' => 'glass of wine', 'ml' => 175.0]];
$vodka = ['name' => 'ZZ Vodka', 'unit' => 'bottle', 'volume_ml' => '1000.00', 'cost_per_unit' => 30000];
$u1 = ['name' => 'ZZ Whisky', 'unit' => 'bottle', 'volume_ml' => null, 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30.0000', 'measure_stock_side' => 'big'];
$size = rh_drink_item_ml($vodka);
check($size !== null && near($size['per_unit'], 1000) && $size['source'] === 'item', '1 L bottle: 1,000 ml in one stock unit (from the bottle size)', json_encode($size));
check(near(1000 / 30, 33.333333, 0.000001) && near(30000 * 30 / 1000, 900, 0.001), '1 L at MWK 30,000 with a 30 ml shot: 33.3 shots, MWK 900 a shot');
$q = conv(2, 'shot', $vodka, $L30);
check($q === 0.06, '2 shots (30 ml) of a 1 L bottle = 0.06 bottle', var_export($q, true));
check(abs(round(0.06 * 30000, 2) - 1800.00) < (float)BALANCE_TOLERANCE, '0.06 bottle at MWK 30,000 costs MWK 1,800.00');
$q = conv(2, 'shot', $u1, $L30);
check($q === 0.066667, 'Item counted as bottle / shot x 30 (no size): 2 shots = 0.066667 bottle', var_export($q, true));
check(abs(round(0.066667 * 30000, 2) - 2000.01) < (float)BALANCE_TOLERANCE, '0.066667 bottle at MWK 30,000 costs MWK 2,000.01');
$o = rh_drink_measure_options($u1, $L30);
check(array_keys($o) === ['shot'] && $o['shot']['kind'] === 'item', 'With no size known only the item\'s own measure is offered', json_encode($o));
$u1v = $u1 + [];
$u1v['volume_ml'] = '1000';
check(conv(2, 'shot', $u1v, $L25) === 0.066667 && conv(2, 'Shot', $u1v, $L25) === 0.066667, 'The item\'s own measure wins over a list entry of the same name (any case)');
check(conv(50, 'ml', $u1v, $L25) === 0.05 && conv(1, 'glass of wine', $u1v, $L25) === 0.175, 'Same item with a size: ml and the list work too (50 ml = 0.05, 1 glass of wine = 0.175)');
$litre = ['name' => 'ZZ Juice', 'unit' => 'litre', 'volume_ml' => '750'];
check(conv(50, 'ml', $litre, $L30) === 0.05, 'Stock kept in litres: 50 ml = 0.05 L (a volume unit beats the bottle size)');
$mlItem = ['name' => 'ZZ Syrup', 'unit' => 'ml'];
check(!isset(rh_drink_measure_options($mlItem, $L25)['ml']) && conv(1, 'shot', $mlItem, $L25) === 25.0, 'Stock kept in ml: "ml" is not offered again; 1 shot = 25 ml');
$gin = ['name' => 'ZZ Gin 750ml', 'unit' => 'bottle'];
check(conv(2, 'shot', $gin, $L25) === 0.066667 && rh_drink_item_ml($gin)['source'] === 'name', 'Size read from the name (Gin 750ml): 2 x 25 ml = 0.066667 bottle');
$shots = ['name' => 'ZZ Rum', 'unit' => 'shot', 'volume_ml' => '750', 'measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30', 'measure_stock_side' => 'small'];
check(conv(50, 'ml', $shots, $L25) === 2.0 && !isset(rh_drink_measure_options($shots, $L25)['bottle']), 'Stock kept in shots (750 ml / 30): 50 ml = 2 shots');
check(conv(3, 'double', ['name' => 'ZZ Brandy', 'unit' => 'bottle', 'volume_ml' => 700], $L30) === round(150 / 700, 6), '3 doubles of a 700 ml bottle = 0.214286 (6 dp)');
check(conv(1, 'glass of wine', ['name' => 'ZZ Wine', 'unit' => 'cl'], $L30) === 17.5, 'Stock kept in cl: 1 glass of wine (175 ml) = 17.5 cl');
check(rh_drink_measure_options(['name' => 'ZZ Sugar 1L', 'unit' => 'kg', 'volume_ml' => 1000], $L30) === [] && conv(1, 'shot', ['name' => 'ZZ Sugar', 'unit' => 'kg'], $L30) === null, 'Weights never get drink measures');
// Crates, cases and packs: "Beer 330ml" in crates is 330 ml a BOTTLE, never 330 ml a crate. No ml measures; the item's own count measure still works.
$crate = ['name' => 'ZZ Beer 330ml', 'unit' => 'crate'];
$crateTyped = ['name' => 'ZZ Beer', 'unit' => 'Crates', 'volume_ml' => '330'];
$crateU1 = ['name' => 'ZZ Beer 330ml', 'unit' => 'crate', 'measure_big_name' => 'crate', 'measure_small_name' => 'bottle', 'measure_per_big' => '24', 'measure_stock_side' => 'big'];
check(rh_drink_item_ml($crate) === null && rh_drink_item_ml($crateTyped) === null && rh_drink_measure_options($crate, $L25) === []
    && conv(330, 'ml', $crate, $L25) === null && conv(1, 'shot', $crateTyped, $L25) === null, 'Crate of "Beer 330ml" (or a typed 330 ml): no size, no shot / ml measures, nothing worked out');
check(array_keys(rh_drink_measure_options($crateU1, $L25)) === ['bottle'] && conv(2, 'bottle', $crateU1, $L25) === round(2 / 24, 6), 'Crate counted as crate / bottle x 24: 2 bottles = 0.083333 crate; no ml or list measures');
check(rh_drink_item_ml(['name' => 'ZZ Water 500ml', 'unit' => 'pack']) === null && rh_drink_item_ml(['name' => 'ZZ Wine 750ml', 'unit' => 'case']) === null, 'Packs and cases are treated the same as crates');
// Names that would be read as HTML on a screen are refused.
foreach (['<b>shot</b>', 'a & b', 'big "one"', 'x>y'] as $badName) {
    [$l, $e] = rh_drink_measures_clean([['name' => $badName, 'ml' => 30]]);
    check($l === [] && is_string($e) && strpos($e, 'cannot use') !== false, 'Measure name with < > & or " refused: ' . $badName, (string)$e);
}
check(refused(fn() => rh_drink_measure_to_stock(1, 'pint', $vodka, $L30)) !== '' && refused(fn() => rh_drink_measure_to_stock(0, 'shot', $vodka, $L30)) !== ''
    && refused(fn() => rh_drink_measure_to_stock(-1, 'shot', $vodka, $L30)) !== '' && refused(fn() => rh_drink_measure_to_stock(1, 'shot', ['name' => 'ZZ Lime', 'unit' => 'each'], $L30)) !== ''
    && refused(fn() => rh_drink_measure_to_stock(0.0000001, 'ml', $vodka, $L30)) !== '', 'Refused with a plain message: unknown measure, 0, negative, no size known, too small to record');
check(rh_drink_plural('glass of wine', 2) === 'glasses of wine' && rh_drink_plural('shot', 2) === 'shots' && rh_drink_plural('ml', 2) === 'ml', 'Plurals: glasses of wine, shots, ml');
// A saved line is shown in its measure only while it still gives the saved figure.
$line = ['entry_measure' => 'shot', 'entry_qty' => '2.0000', 'quantity_per_portion' => '0.060000'];
check(rh_drink_line_entry($line, $vodka, $L30) === ['measure' => 'shot', 'qty' => 2.0], 'Saved "2 shots" (0.06) is shown again as 2 shots');
check(rh_drink_line_entry($line, $vodka, $L25) === null, 'After the shot size changed it is shown in bottles, unchanged (0.06)');
check(rh_drink_line_entry(['quantity_per_portion' => '0.06'], $vodka, $L30) === null, 'A line typed in the stock unit has no measure');
// List checks.
[$l, $e] = rh_drink_measures_clean([['name' => ' shot ', 'ml' => '30'], ['name' => '', 'ml' => ''], ['name' => 'glass  of wine', 'ml' => '1,75']]);
check($e === null && $l === [['name' => 'shot', 'ml' => 30.0], ['name' => 'glass of wine', 'ml' => 175.0]], 'List: names trimmed, blank rows skipped, 1,75 read as 175 (thousands comma)', json_encode([$l, $e]));
$listBad = [
    'same name twice' => [['name' => 'shot', 'ml' => 25], ['name' => 'Shot', 'ml' => 30]],
    'named ml' => [['name' => 'ml', 'ml' => 1]],
    'size 0' => [['name' => 'shot', 'ml' => 0]],
    'size over 5,000' => [['name' => 'jug', 'ml' => 5001]],
    'size not a number' => [['name' => 'shot', 'ml' => 'thirty']],
    'size with no name' => [['name' => '', 'ml' => 30]],
    'name over 30' => [['name' => str_repeat('s', 31), 'ml' => 30]],
    'more than 20' => array_map(fn($i) => ['name' => 'm' . $i, 'ml' => 10 + $i], range(1, 21)),
];
foreach ($listBad as $what => $rows) {
    [$l, $e] = rh_drink_measures_clean($rows);
    check($l === [] && is_string($e) && $e !== '', "List refused with a plain message: $what", (string)$e);
}
[$v, $e] = rh_drink_volume_from_input('1,000');
check($v === 1000.0 && $e === null, 'Bottle size 1,000 read as 1000 ml');
[$v, $e] = rh_drink_volume_from_input('');
[$v2, $e2] = rh_drink_volume_from_input('0');
check($v === null && $e === null && $v2 === null && $e2 === null, 'Blank or 0 bottle size = not set');
[$v, $e] = rh_drink_volume_from_input('-5');
[$v2, $e2] = rh_drink_volume_from_input('abc');
check($v === null && is_string($e) && is_string($e2), 'Negative or non-number bottle size refused');

/* ---------- 1b. The hotel's own measures (owner 2026-11-19: "be able to have custom measurements and make sure it works") ---------- */
$LC = [['name' => 'jigger', 'ml' => '44'], ['name' => 'double', 'ml' => '60'], ['name' => 'half tot', 'ml' => '12.5'], ['name' => 'carafe', 'ml' => '500'],
       ['name' => 'cup', 'ml' => '250'], ['name' => 'small glass of wine', 'ml' => '125'], ['name' => 'glass of wine', 'ml' => '175']];
[$LCc, $e] = rh_drink_measures_clean($LC);
check($e === null && array_column($LCc, 'name') === ['jigger', 'double', 'half tot', 'carafe', 'cup', 'small glass of wine', 'glass of wine'] && $LCc[2]['ml'] === 12.5,
    'Custom list accepted as typed (jigger 44, double 60, half tot 12.5, carafe 500, cup 250, small glass of wine 125): order kept, 12.5 ml kept', json_encode([$LCc, $e]));
foreach (['shot / shots' => [['name' => 'shot', 'ml' => 25], ['name' => 'shots', 'ml' => 30]], 'glasses / glass' => [['name' => 'glasses', 'ml' => 175], ['name' => 'glass', 'ml' => 175]],
          'Jigger / jiggers' => [['name' => 'Jigger', 'ml' => 44], ['name' => 'jiggers', 'ml' => 44]], 'half tot / Half Tots' => [['name' => 'half tot', 'ml' => 12.5], ['name' => 'Half Tots', 'ml' => 12.5]],
          'glass of wine / glasses of wine' => [['name' => 'glass of wine', 'ml' => 175], ['name' => 'glasses of wine', 'ml' => 175]]] as $what => $rows) {
    [$l, $e] = rh_drink_measures_clean($rows);
    check($l === [] && is_string($e) && strpos($e, 'plural') !== false, "A name and its plural are one measure, refused with a plain message: $what", (string)$e);
}
[$l, $e] = rh_drink_measures_clean([['name' => 'tot', 'ml' => 25], ['name' => 'half tot', 'ml' => 12.5], ['name' => 'tot of gin', 'ml' => 25]]);
check($e === null && count($l) === 3, 'Names that only share a word (tot, half tot, tot of gin) are different measures', (string)$e);
$v750 = ['name' => 'ZZ Vodka', 'unit' => 'bottle', 'volume_ml' => '750', 'cost_per_unit' => 30000];
$wine = ['name' => 'ZZ House Wine', 'unit' => 'bottle', 'volume_ml' => '750'];
check(array_keys(rh_drink_measure_options($v750, $LCc)) === ['jigger', 'double', 'half tot', 'carafe', 'cup', 'small glass of wine', 'glass of wine', 'ml'],
    'Every custom measure is offered for a bottle with a size, in the list\'s order, then ml', json_encode(array_keys(rh_drink_measure_options($v750, $LCc))));
check(conv(2, 'jigger', $v750, $LCc) === 0.117333 && conv(2, 'jiggers', $v750, $LCc) === 0.117333 && conv(2, 'Jiggers', $v750, $LCc) === 0.117333,
    '2 jiggers (44 ml) of a 750 ml bottle = 0.117333 bottle, typed as jigger, jiggers or Jiggers');
check(conv(1, 'half tot', $v750, $LCc) === 0.016667 && conv(3, 'half tots', $v750, $LCc) === 0.05 && conv(3, '  Half   Tots ', $v750, $LCc) === 0.05,
    'Two-word name: 1 half tot (12.5 ml) = 0.016667, 3 half tots = 0.05 (spaces and case tidied)');
check(conv(1, 'carafe', $wine, $LCc) === 0.666667 && conv(2, 'small glasses of wine', $wine, $LCc) === 0.333333 && conv(2, 'cups', ['name' => 'ZZ Milk', 'unit' => 'L'], $LCc) === 0.5,
    '1 carafe of a 750 ml bottle = 0.666667; 2 small glasses of wine = 0.333333; 2 cups of litre stock = 0.5 L');
check(conv(4, 'doubles', ['name' => 'ZZ Rum', 'unit' => 'ml'], $LCc) === 240.0, 'Stock kept in ml: 4 doubles (60 ml) = 240 ml');
$r = rh_drink_measure_to_stock(2, 'JIGGERS', $v750, $LCc);
check($r['name'] === 'jigger' && near($r['ml'], 44), 'The list\'s own spelling is what gets saved (JIGGERS -> jigger, 44 ml)', json_encode($r));
check(abs(round(0.117333 * 30000, 2) - 3519.99) < (float)BALANCE_TOLERANCE, '0.117333 bottle at MWK 30,000 costs MWK 3,519.99');
check(conv(1, 'pony', $v750, $LCc) === null && refused(fn() => rh_drink_measure_to_stock(1, 'ponies', $v750, $LCc)) !== '', 'A measure not on the list is still refused');
check(rh_drink_plural('half tot', 2) === 'half tots' && rh_drink_plural('small glass of wine', 2) === 'small glasses of wine' && rh_drink_plural('carafe', 1) === 'carafe'
    && rh_drink_plural('jigger', 3) === 'jiggers', 'Plurals: half tots, small glasses of wine, 1 carafe, 3 jiggers');
// Removed or renamed: the saved stock figure is never touched; the line simply shows in the stock unit.
$jl = ['entry_measure' => 'jigger', 'entry_qty' => '2.0000', 'entry_ml' => '44', 'quantity_per_portion' => '0.117333'];
$noJig = array_values(array_filter($LCc, fn($m) => $m['name'] !== 'jigger'));
$renamed = array_map(fn($m) => $m['name'] === 'jigger' ? ['name' => 'pony', 'ml' => 44.0] : $m, $LCc);
check(rh_drink_line_entry($jl, $v750, $LCc) === ['measure' => 'jigger', 'qty' => 2.0], 'Saved "2 jiggers" (0.117333) is shown again as 2 jiggers');
check(rh_drink_line_entry($jl, $v750, $noJig) === null && rh_drink_line_entry($jl, $v750, $renamed) === null && $jl['quantity_per_portion'] === '0.117333',
    'After jigger is removed or renamed the line shows in bottles (0.117333), nothing recalculated');
// The item's own count measure with a custom small name (bottle / jigger x 17).
$ownJ = ['name' => 'ZZ Gin', 'unit' => 'bottle', 'measure_big_name' => 'bottle', 'measure_small_name' => 'jigger', 'measure_per_big' => '17', 'measure_stock_side' => 'big'];
$ownJv = $ownJ + ['volume_ml' => '750'];
check(array_keys(rh_drink_measure_options($ownJ, $LCc)) === ['jigger'] && conv(2, 'jiggers', $ownJ, $LCc) === 0.117647, 'Item counted as bottle / jigger x 17 (no size): 2 jiggers = 0.117647 bottle');
check(conv(2, 'jigger', $ownJv, $LCc) === 0.117647 && rh_drink_measure_options($ownJv, $LCc)['jigger']['kind'] === 'item', 'With a size too, the item\'s own 17 jiggers wins over the list\'s 44 ml jigger');
// Import: a custom one-word name on a sheet is a measure word; a word nobody set up is still asked.
require_once __DIR__ . '/../../includes/import/default-aliases.php';
require_once __DIR__ . '/../../includes/import/analyze.php';
require_once __DIR__ . '/../../includes/import/resolve.php';
$al = import_default_aliases();
$mw0 = import_measure_words($al);
$mw = import_measure_words($al, $LCc);
check(isset($mw['jigger'], $mw['jiggers'], $mw['carafe'], $mw['carafes'], $mw['cup'], $mw['double'], $mw['doubles'], $mw['shots']) && !isset($mw['half tot'], $mw['half'], $mw['ml']),
    'Import measure words: the list\'s one-word names in one and many (jigger / jiggers, carafes, doubles) plus the usual ones; two-word names are not read from a sheet', json_encode(array_keys($mw)));
check(!isset($mw0['jigger']) && !isset($mw0['double']), 'Without the hotel list nothing new is a measure word (double still names no amount)');
check(import_measure_cell('34 jiggers', $mw) === ['value' => 34, 'word' => 'jiggers'] && import_measure_cell('34 jiggers', $mw0) === null && import_measure_cell('3 ponies', $mw) === null,
    'Import cell "34 jiggers" is a count in jiggers (only with the list); "3 ponies" is not');
$imp = function (array $rows, array $list): array {
    $p = tempnam(sys_get_temp_dir(), 'zzdmi') . '.csv';
    $fh = fopen($p, 'w');
    foreach ($rows as $row) { fputcsv($fh, $row); }
    fclose($fh);
    $an = import_analyze_file($p, 'zz.csv', import_default_aliases(), ['drink_measures' => $list]);
    $res = import_resolve($an, [], ['existing' => [], 'unit_options' => ['bottle', 'each'], 'corrections' => [], 'drink_measures' => $list]);
    @unlink($p);
    $items = [];
    foreach ($res['items'] as $it) { $items[$it['name']] = $it; }
    $open = array_values(array_filter(array_merge($res['questions'], array_values($res['extra_questions'])), fn($q) => $q['answer'] === null));
    return ['items' => $items, 'open' => $open];
};
$jig = [['name' => 'jigger', 'ml' => 37.5]];
$i1 = $imp([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['Vodka 750ml', '40 jiggers', 'bottle', '9000']], $jig);
check(abs((float)($i1['items']['Vodka 750ml']['balance'] ?? -1) - 2) < 0.0000005 && !$i1['open'], 'Import "40 jiggers" of Vodka 750ml with jigger 37.5 ml on the list = 2 bottles, nothing asked',
    json_encode([$i1['items']['Vodka 750ml']['balance'] ?? null, $i1['items']['Vodka 750ml']['qty_how'] ?? null, array_column($i1['open'], 'type')]));
$i2 = $imp([['Item Name', 'Balance (jiggers)', 'Unit', 'Unit Price'], ['Vodka 750ml', '60', 'bottle', '9000']], $jig);
check(abs((float)($i2['items']['Vodka 750ml']['balance'] ?? -1) - 3) < 0.0000005 && !$i2['open'], 'Import heading "Balance (jiggers)" 60 = 3 bottles', json_encode([$i2['items']['Vodka 750ml']['balance'] ?? null, array_column($i2['open'], 'type')]));
$i3 = $imp([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['Vodka 750ml', '40 ponies', 'bottle', '9000']], $jig);
check(count($i3['open']) > 0 && abs((float)($i3['items']['Vodka 750ml']['balance'] ?? 0) - 40) > 0.001, 'Import "40 ponies" (not set up anywhere) is asked, never guessed', json_encode(array_column($i3['open'], 'type')));
$i4 = $imp([['Item Name', 'Balance', 'Unit', 'Unit Price'], ['Vodka 750ml', '40 tots', 'bottle', '9000']], $jig);
check(count($i4['open']) > 0, 'Import "40 tots" when the list has no tot size: asked how many in one bottle', json_encode(array_column($i4['open'], 'type')));

/* ---------- 2. PHP and the screens' JS give the same answers ---------- */
$cases = [
    [$vodka, $L30, 'shot', 2], [$u1, $L30, 'shot', 2], [$u1v, $L25, 'shot', 2], [$u1v, $L25, 'ml', 50], [$litre, $L30, 'ml', 50],
    [$mlItem, $L25, 'shot', 1], [$gin, $L25, 'shot', 2], [$shots, $L25, 'ml', 50], [['name' => 'ZZ Brandy', 'unit' => 'bottle', 'volume_ml' => 700], $L30, 'double', 3],
    [['name' => 'ZZ Wine', 'unit' => 'cl'], $L30, 'glass of wine', 1], [['name' => 'ZZ Sugar', 'unit' => 'kg', 'volume_ml' => 1000], $L30, 'shot', 1],
    [$vodka, $L30, 'pint', 1], [['name' => 'ZZ Wine 75cl', 'unit' => 'bottle'], $L30, 'glass of wine', 1], [['name' => 'ZZ Beer 330ml', 'unit' => 'crate'], $L25, 'ml', 330],
    [$crateTyped, $L25, 'shot', 1], [$crateU1, $L25, 'bottle', 2], [$crateU1, $L25, 'ml', 330],
    // the hotel's own measures (1b): plural typing, two-word names, decimal ml, the item's own jigger
    [$v750, $LCc, 'jiggers', 2], [$v750, $LCc, 'Half  Tots', 3], [$wine, $LCc, 'carafe', 1], [$wine, $LCc, 'small glasses of wine', 2], [$ownJ, $LCc, 'jiggers', 2],
    [$ownJv, $LCc, 'jigger', 2], [['name' => 'ZZ Rum', 'unit' => 'ml'], $LCc, 'doubles', 4], [$v750, $LCc, 'ponies', 1],
];
$php = [];
foreach ($cases as [$ing, $list, $m, $qty]) {
    $php[] = ['opts' => array_values(array_map(fn($o) => $o['name'], rh_drink_measure_options($ing, $list))), 'qty' => conv((float)$qty, $m, $ing, $list)];
}
$jsFile = realpath(__DIR__ . '/../../admin/js/stock-drink-measures.js');
$runner = 'global.window={};require(' . json_encode($jsFile) . ');const c=JSON.parse(Buffer.from(process.argv[2],"base64").toString());'
    . 'const r=c.map(x=>({opts:window.RHDrink.options(x[0],x[1]).map(o=>o.name),qty:window.RHDrink.toStock(x[3],x[2],x[0],x[1])}));'
    . 'r.push({plurals:["half tot","small glass of wine","glass of wine","carafe","jigger","box","ml"].map(n=>window.RHDrink.plural(n,2))});'
    . 'window.RHDrink.setReady(false);r.push({notReady:c.map(x=>window.RHDrink.options(x[0],x[1]).length+(window.RHDrink.toStock(x[3],x[2],x[0],x[1])===null?0:1)).reduce((a,b)=>a+b,0)});'
    . 'console.log(JSON.stringify(r));';
$runFile = tempnam(sys_get_temp_dir(), 'zzdm') . '.js';
file_put_contents($runFile, $runner);
$jsOut = shell_exec('node ' . escapeshellarg($runFile) . ' ' . escapeshellarg(base64_encode(json_encode($cases))) . ' 2>&1');
@unlink($runFile);
$js = json_decode(trim((string)$jsOut), true);
if (!is_array($js)) {
    bad('Screens\' maths (node) ran', substr((string)$jsOut, 0, 300));
} else {
    foreach ($cases as $k => $c) {
        $same = ($js[$k]['opts'] ?? null) === $php[$k]['opts']
            && (($js[$k]['qty'] === null && $php[$k]['qty'] === null) || ($js[$k]['qty'] !== null && $php[$k]['qty'] !== null && near($js[$k]['qty'], $php[$k]['qty'], 0.0000001)));
        check($same, 'Screen and server agree: ' . $c[3] . ' ' . $c[2] . ' of ' . $c[0]['name'] . ' (' . $c[0]['unit'] . ')', json_encode(['js' => $js[$k] ?? null, 'php' => $php[$k]]));
    }
    $plPhp = array_map(fn($n) => rh_drink_plural($n, 2), ['half tot', 'small glass of wine', 'glass of wine', 'carafe', 'jigger', 'box', 'ml']);
    check(($js[count($cases)]['plurals'] ?? null) === $plPhp, 'Screen and server write the same plurals (' . implode(', ', $plPhp) . ')', json_encode($js[count($cases)] ?? null));
    check(($js[count($cases) + 1]['notReady'] ?? null) === 0, 'Before migration 089 (RHDrink.setReady(false)) the screens offer no measures and work nothing out', json_encode($js[count($cases) + 1] ?? null));
}

/* ---------- 3. Saved recipes and sales, one rolled-back transaction ---------- */
$adminId = (int)col($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$run = strtoupper(bin2hex(random_bytes(3)));
$hotelList = rh_drink_measures_list();
$hotelShot = null;
foreach ($hotelList as $dm) { if (mb_strtolower($dm['name']) === 'shot') { $hotelShot = (float)$dm['ml']; } }
check($hotelShot !== null && $hotelShot > 0, 'The hotel list has a shot size (seeded by 089)', json_encode($hotelList));
$catId = (int)col($pdo, "SELECT id FROM menu_categories ORDER BY id LIMIT 1");
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES (?, 'ZZDM', 'bottle', 10, 30000, 1000)")->execute(["ZZT DM $run Vodka"]);
    $ing = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, 10, 10, 30000, CURDATE(), 'active')")->execute([$ing, 'ZZDM-' . $ing]);
    $pdo->prepare("INSERT INTO menu_items (category_id, item_name, price, is_available) VALUES (?, ?, 5000, 1)")->execute([$catId, "ZZT DM $run Vodka tot"]);
    $mi = (int)$pdo->lastInsertId();
    $slug = rh_menu_item_slug($pdo, $mi);
    $want = round(2 * $hotelShot / 1000, 6);

    $res = rh_menu_recipe_save($pdo, $mi, $slug, null, null, [['ingredient_id' => $ing, 'quantity' => 2, 'unit' => 'measure', 'measure' => 'Shot']], $adminId);
    $row = $pdo->prepare("SELECT quantity_per_portion AS q, entry_measure AS m, entry_qty AS eq, entry_ml AS ml FROM stock_recipe_ingredients WHERE recipe_id = ?");
    $row->execute([$res['recipe_id']]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    check(near($r['q'] ?? null, $want) && ($r['m'] ?? '') === 'shot' && near($r['eq'] ?? null, 2) && near($r['ml'] ?? null, $hotelShot),
        "Menu Management \"2 shots\": saved as $want bottle, typed measure kept (shot, 2, {$hotelShot} ml)", json_encode($r));
    $lines = rh_menu_recipe_lines($pdo, $mi, $slug);
    check(($lines[0]['entry_match']['measure'] ?? '') === 'shot' && near($lines[0]['entry_match']['qty'] ?? null, 2), 'Reading it back shows 2 shots again', json_encode($lines[0]['entry_match'] ?? null));
    check(refused(fn() => rh_menu_recipe_save($pdo, $mi, $slug, null, null, [['ingredient_id' => $ing, 'quantity' => 1, 'unit' => 'measure', 'measure' => 'pint']], $adminId)) !== ''
        && near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res['recipe_id']]), $want), 'An unknown measure is refused and the saved line is untouched');

    foreach (['pos_order' => 2.0, 'room_service' => 1.0] as $src => $portions) {
        $sid = 987650000 + random_int(1, 9999);
        $before = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$ing]);
        $done = deductStockForMenuItem($mi, $slug, $portions, $src, $sid, $adminId);
        $after = (float)col($pdo, "SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$ing]);
        $adj = $pdo->prepare("SELECT quantity_change AS qc, cost_at_time AS c FROM stock_adjustments WHERE ingredient_id = ? AND source_type = ? AND source_id = ?");
        $adj->execute([$ing, $src, $sid]);
        $a = $adj->fetch(PDO::FETCH_ASSOC);
        $value = $a ? round(-(float)$a['qc'] * (float)$a['c'], 2) : null;
        check($done && near($before - $after, $want * $portions) && near($a['qc'] ?? null, -$want * $portions) && near($a['c'] ?? null, 30000, 0.0001)
            && $value !== null && abs($value - round($want * $portions * 30000, 2)) < (float)BALANCE_TOLERANCE,
            "A $src sale of $portions takes " . ($want * $portions) . " bottle at MWK 30,000 (value MWK " . number_format(round($want * $portions * 30000, 2), 2) . ')', json_encode([$before, $after, $a]));
    }

    // A bottle size change: stock, cost and the saved figure stay; the line is shown in bottles from then on.
    $stockBefore = $pdo->query("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = $ing")->fetch(PDO::FETCH_ASSOC);
    $batchBefore = $pdo->query("SELECT SUM(quantity_remaining), SUM(quantity_remaining * cost_per_unit) FROM stock_batches WHERE ingredient_id = $ing")->fetch(PDO::FETCH_NUM);
    check(rh_drink_volume_save($pdo, $ing, 750.0, $adminId) === true && rh_drink_volume_save($pdo, $ing, 750.0, $adminId) === false, 'Bottle size 1000 -> 750 saved once (same again = no change)');
    $stockAfter = $pdo->query("SELECT current_quantity, cost_per_unit FROM stock_ingredients WHERE id = $ing")->fetch(PDO::FETCH_ASSOC);
    $batchAfter = $pdo->query("SELECT SUM(quantity_remaining), SUM(quantity_remaining * cost_per_unit) FROM stock_batches WHERE ingredient_id = $ing")->fetch(PDO::FETCH_NUM);
    check($stockBefore == $stockAfter && $batchBefore == $batchAfter, 'A bottle size change moves no stock and no cost (quantity, cost per unit, batch value)', json_encode([$stockBefore, $stockAfter, $batchBefore, $batchAfter]));
    $lines = rh_menu_recipe_lines($pdo, $mi, $slug);
    check(near($lines[0]['quantity_per_portion'] ?? null, $want) && ($lines[0]['entry_match'] ?? null) === null, 'The saved recipe figure is unchanged and now shows in bottles', json_encode($lines[0] ?? null));
    $log = col($pdo, "SELECT details FROM admin_activity_log WHERE action = 'stock_volume_changed' AND details LIKE ? ORDER BY id DESC LIMIT 1", ["%ZZT DM $run Vodka%"]);
    check(is_string($log) && strpos($log, '1000 ml -> 750 ml') !== false, 'Bottle size change logged old -> new (stock_volume_changed)', (string)$log);
    check(rh_drink_volume_save($pdo, $ing, null, $adminId) === true && col($pdo, "SELECT volume_ml FROM stock_ingredients WHERE id = ?", [$ing]) === null, 'Clearing the bottle size stores NULL');

    // A hotel that has not run 089 yet (Rosalyn's until applied): no new columns read, measures refused, stock-unit lines still save.
    $GLOBALS['rh_drink_ready_force'] = false;
    try {
        check(!rh_stock_drink_ready($pdo) && strpos(rh_drink_ing_cols('i'), 'volume_ml') === false, 'Before 089: ready check is false and volume_ml is not read');
        $msg = refused(fn() => rh_menu_recipe_save($pdo, $mi, $slug, null, null, [['ingredient_id' => $ing, 'quantity' => 2, 'unit' => 'measure', 'measure' => 'shot']], $adminId));
        check(strpos($msg, 'not set up yet') !== false, 'Before 089: a measure line is refused with a plain message', $msg);
        $res2 = rh_menu_recipe_save($pdo, $mi, $slug, null, null, [['ingredient_id' => $ing, 'quantity' => 0.05, 'unit' => 'base']], $adminId);
        $lines = rh_menu_recipe_lines($pdo, $mi, $slug);
        check(near(col($pdo, "SELECT quantity_per_portion FROM stock_recipe_ingredients WHERE recipe_id = ?", [$res2['recipe_id']]), 0.05) && !isset($lines[0]['entry_match']),
            'Before 089: a stock-unit line saves (0.05 bottle) and is read back without measure fields', json_encode($lines[0] ?? null));
    } finally {
        unset($GLOBALS['rh_drink_ready_force']);
    }
} catch (Throwable $e) {
    bad('Saved recipes and sales ran', get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    while ($pdo->inTransaction()) { $pdo->rollBack(); }
}
check((int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZT DM $run %"]) === 0, 'Section 3 left nothing behind');

/* ---------- 4. Through the real pages (rolled back) ---------- */
$harness = __DIR__ . '/page_harness.php';
$page = function (string $file, array $cfg) use ($harness): array {
    $cfg['session_keys'] = ['stock_msg', 'stock_err'];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -900), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $mm)) { $x = json_decode($mm[1], true); if (is_array($x)) { $res = $x; } }
    return $res;
};
$safe = function (array $r, string $label): void {
    check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), "$label: ran and rolled back", json_encode($r['raw'] ?? $r));
};
// Both pages answer a POST with a redirect; the message is what they left in the session for the next page.
$flash = fn(array $r): string => trim((string)($r['session']['stock_msg'] ?? '') . ' ' . (string)($r['session']['stock_err'] ?? ''));
$mark = 'zzt dm ' . strtolower($run);
$listSql = "SELECT setting_value FROM site_settings WHERE setting_key = 'stock_measures_list'";
$logSql = "SELECT action, details FROM admin_activity_log WHERE details LIKE '%" . strtoupper($run) . "%' OR details LIKE '%$mark%' ORDER BY id";
$listBefore = (string)col($pdo, $listSql);

$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'measure_name' => ['shot', $mark, 'glass of wine', ''], 'measure_ml' => ['30', '50', '175', '']], 'verify' => [$listSql, $logSql]]);
$safe($r, 'Drink measures list save');
$saved = json_decode((string)($r['verify'][0][0]['setting_value'] ?? ''), true);
check(is_array($saved) && count($saved) === 3 && ($saved[1]['name'] ?? '') === $mark && near($saved[0]['ml'] ?? null, 30), 'Drink measures saved (blank row ignored)', json_encode($saved) . ' ' . $flash($r));
$acts = $r['verify'][1] ?? [];
check(is_array($acts) && ($acts[0]['action'] ?? '') === 'stock_measures_list_changed' && strpos((string)($acts[0]['details'] ?? ''), ' -> ') !== false && strpos((string)$acts[0]['details'], $mark . ' 50 ml') !== false,
    'Logged old -> new (stock_measures_list_changed)', json_encode($acts));
check(stripos($flash($r), 'unchanged') !== false, 'The message says stock, cost and saved recipes are unchanged', $flash($r));

$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'measure_name' => ['shot', 'Shot'], 'measure_ml' => ['25', '30']], 'verify' => [$listSql]]);
$safe($r, 'Duplicate measures');
check(stripos($flash($r), 'twice') !== false && (string)($r['verify'][0][0]['setting_value'] ?? '') === $listBefore, 'A name twice is refused, the list is unchanged', $flash($r));
$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'measure_name' => 'shot', 'measure_ml' => '25'], 'verify' => [$listSql]]);
check(stripos($flash($r), 'not sent correctly') !== false && (string)($r['verify'][0][0]['setting_value'] ?? '') === $listBefore, 'A list not sent as rows is refused');
$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'csrf_token' => 'nope', 'measure_name' => ['tot'], 'measure_ml' => ['30']], 'verify' => [$listSql]]);
check(stripos($flash($r), 'token') !== false && (string)($r['verify'][0][0]['setting_value'] ?? '') === $listBefore, 'Without the security token nothing is saved');

// The hotel's own measures through the page: two-word names, decimal ml, a plural twin refused.
$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'measure_name' => ['jigger', 'half tot', 'carafe', $mark], 'measure_ml' => ['44', '12.5', '500', '60']], 'verify' => [$listSql, $logSql]]);
$safe($r, 'Custom measures list save');
$saved = json_decode((string)($r['verify'][0][0]['setting_value'] ?? ''), true);
check(is_array($saved) && array_column($saved, 'name') === ['jigger', 'half tot', 'carafe', $mark] && near($saved[1]['ml'] ?? null, 12.5) && near($saved[2]['ml'] ?? null, 500),
    'Custom measures saved as typed (jigger 44, half tot 12.5, carafe 500), in that order', json_encode($saved) . ' ' . $flash($r));
check(strpos((string)($r['verify'][1][0]['details'] ?? ''), 'half tot 12.5 ml') !== false, 'The change log shows the decimal size (half tot 12.5 ml)', json_encode($r['verify'][1] ?? null));
$r = $page('admin/stock-ingredients.php', ['post' => ['action' => 'save_drink_measures', 'measure_name' => ['jigger', 'Jiggers'], 'measure_ml' => ['44', '44']], 'verify' => [$listSql]]);
check(stripos($flash($r), 'plural') !== false && (string)($r['verify'][0][0]['setting_value'] ?? '') === $listBefore, 'jigger + Jiggers is refused with a plain message, the list is unchanged', $flash($r));

// Bottle size on the item form.
$ingName = "ZZT DM $run Gin";
$setup = ["INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('$ingName', 'ZZDM', 'bottle', 4, 24000)",
          "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZT DM $run Juice', 'ZZDM', 'litre', 4, 2000)"];
// Stale-edit (task 9): the Save posts the hash of the item's form columns as the page would have rendered it (rh_stock_item_stale_cols in
// admin/stock-ingredients.php; same list here), computed in SQL inside the run after the setup rows.
$siHashSql = function (string $name) use ($pdo): string {
    $cols = ['name', 'category', 'unit', 'min_quantity', 'yield_percent', 'notes', 'reorder_point', 'par_level', 'lead_time_days', 'preferred_supplier_id', 'pack_name', 'pack_size', 'pieces_per_unit'];
    if (rh_stock_measure_ready($pdo)) { array_push($cols, 'measure_big_name', 'measure_small_name', 'measure_per_big', 'measure_stock_side'); }
    if (rh_stock_drink_ready($pdo)) { $cols[] = 'volume_ml'; }
    $parts = ["'a:" . count($cols) . ":{'"];
    foreach ($cols as $i => $c) {
        $v = "CONVERT(`$c` USING utf8mb4)";
        $parts[] = "'i:$i;'";
        $parts[] = "IF(`$c` IS NULL, 'N;', CONCAT('s:', LENGTH($v), ':\"', $v, '\";'))";
    }
    $parts[] = "'}'";
    return "SELECT MD5(CONCAT(" . implode(', ', $parts) . ")) FROM stock_ingredients WHERE name = '" . str_replace("'", "''", $name) . "'";
};
// One hash per run (the Windows command line caps the harness argument at 8 KB).
$vars = ['gin' => "SELECT id FROM stock_ingredients WHERE name = '$ingName'", 'hg' => $siHashSql($ingName)];
$varsJ = ['juice' => "SELECT id FROM stock_ingredients WHERE name = 'ZZT DM $run Juice'", 'hj' => $siHashSql("ZZT DM $run Juice")];
$ingSql = "SELECT name, unit, current_quantity AS q, cost_per_unit AS c, volume_ml AS v FROM stock_ingredients WHERE name LIKE 'ZZT DM $run %' ORDER BY id";
$base = ['action' => 'update', 'id' => '{gin}', 'name' => $ingName, 'category' => 'ZZDM', 'unit' => 'bottle', 'min_quantity' => '0', 'reorder_point' => '0', 'par_level' => '0', 'yield_percent' => '100', '_row_hash' => '{hg}'];
$r = $page('admin/stock-ingredients.php', ['setup' => $setup, 'vars' => $vars, 'post' => $base + ['volume_present' => '1', 'volume_ml' => '750'], 'verify' => [$ingSql, $logSql]]);
$safe($r, 'Bottle size on the item form');
$I = $r['verify'][0] ?? [];
check(near($I[0]['v'] ?? null, 750) && near($I[0]['q'] ?? null, 4) && near($I[0]['c'] ?? null, 24000, 0.0001), 'Bottle size 750 ml saved; quantity and cost unchanged', json_encode($I) . ' ' . $flash($r));
check(in_array('stock_volume_changed', array_column(is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [], 'action'), true), 'Logged (stock_volume_changed)', json_encode($r['verify'][1] ?? null));
$setup2 = array_merge($setup, ["UPDATE stock_ingredients SET volume_ml = 700 WHERE name = '$ingName'"]);
$r = $page('admin/stock-ingredients.php', ['setup' => $setup2, 'vars' => $vars, 'post' => $base, 'verify' => [$ingSql]]);
check(near(($r['verify'][0][0]['v'] ?? null), 700), 'A form without the bottle size field (old page) never wipes it', json_encode($r['verify'][0] ?? null) . ' ' . $flash($r));
$r = $page('admin/stock-ingredients.php', ['setup' => $setup2, 'vars' => $vars, 'post' => $base + ['volume_present' => '1', 'volume_ml' => 'abc'], 'verify' => [$ingSql]]);
check(near(($r['verify'][0][0]['v'] ?? null), 700) && stripos($flash($r), 'Bottle size') !== false, 'A bad bottle size is refused with a plain message', $flash($r));
$r = $page('admin/stock-ingredients.php', ['setup' => $setup, 'vars' => $varsJ, 'post' => ['action' => 'update', 'id' => '{juice}', 'name' => "ZZT DM $run Juice", 'category' => 'ZZDM', 'unit' => 'litre', 'volume_present' => '1', 'volume_ml' => '750', '_row_hash' => '{hj}'], 'verify' => [$ingSql]]);
check(is_array($r['verify'][0][1] ?? null) && array_key_exists('v', $r['verify'][0][1]) && $r['verify'][0][1]['v'] === null, 'Stock kept in litres never keeps a bottle size', json_encode($r['verify'][0] ?? null) . ' ' . $flash($r));

// Stock > Recipes: typed in shots, the page's own figure is ignored.
$listSet = "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('stock_measures_list', '[{\"name\":\"shot\",\"ml\":30},{\"name\":\"glass of wine\",\"ml\":175}]', 'stock') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
$setupR = [
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES ('ZZT DM $run Vodka', 'ZZDM', 'bottle', 5, 30000, 1000)",
    "INSERT INTO menu_items (category_id, item_name, price, is_available) VALUES ($catId, 'ZZT DM $run Vodka tot', 5000, 1)",
    $listSet,
];
$varsR = ['ing' => "SELECT id FROM stock_ingredients WHERE name = 'ZZT DM $run Vodka'", 'mi' => "SELECT id FROM menu_items WHERE item_name = 'ZZT DM $run Vodka tot'"];
$sriSql = "SELECT sri.quantity_per_portion AS q, sri.entry_measure AS m, sri.entry_qty AS eq, sri.entry_ml AS ml FROM stock_recipe_ingredients sri INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id WHERE i.name LIKE 'ZZT DM $run %'";
$postR = ['action' => 'save_recipe', 'menu_item_id' => '{mi}', 'portions_per_recipe' => '1', 'ingredient_id' => ['{ing}'], 'quantity_per_portion' => ['2'], 'yield_percent' => ['100'], 'entry_measure' => ['shot']];
$r = $page('admin/stock-recipes.php', ['setup' => $setupR, 'vars' => $varsR, 'post' => $postR, 'verify' => [$sriSql]]);
$S = $r['verify'][0] ?? [];
$safe($r, 'Stock > Recipes save in shots');
check(near($S[0]['q'] ?? null, 0.06) && ($S[0]['m'] ?? '') === 'shot' && near($S[0]['eq'] ?? null, 2) && near($S[0]['ml'] ?? null, 30), 'Recipes "2 shots" of a 1 L bottle saved as 0.06 with the typed measure', json_encode($S) . ' ' . $flash($r));
$r = $page('admin/stock-recipes.php', ['setup' => $setupR, 'vars' => $varsR, 'post' => array_merge($postR, ['entry_measure' => ['pint']]), 'verify' => [$sriSql]]);
check(($r['verify'][0] ?? null) === [] && stripos($flash($r), 'pint') !== false, 'Recipes: an unknown measure is refused, nothing saved', json_encode($r['verify'][0] ?? null) . ' ' . $flash($r));
$r = $page('admin/stock-recipes.php', ['setup' => $setupR, 'vars' => $varsR, 'post' => array_merge($postR, ['entry_measure' => ['']]), 'verify' => [$sriSql]]);
check(near($r['verify'][0][0]['q'] ?? null, 2) && is_array($r['verify'][0][0] ?? null) && $r['verify'][0][0]['m'] === null, 'Recipes: typed in the stock unit stays as typed (2 bottles), no measure kept', json_encode($r['verify'][0] ?? null));
$setupG = array_merge($setupR, [
    "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) SELECT mi.id, mc.slug, 1 FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.item_name = 'ZZT DM $run Vodka tot'",
    "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent, entry_measure, entry_qty, entry_ml) SELECT sr.id, i.id, 0.06, 100, 'shot', 2, 30 FROM stock_recipes sr JOIN menu_items mi ON mi.id = sr.menu_item_id JOIN stock_ingredients i ON i.name = 'ZZT DM $run Vodka' WHERE mi.item_name = 'ZZT DM $run Vodka tot'",
]);
$r = $page('admin/stock-recipes.php', ['setup' => $setupG, 'vars' => $varsR, 'method' => 'GET', 'get' => ['ajax' => 'get_recipe', 'menu_item_id' => '{mi}', 'menu_type' => 'x']]);
$J = $r['json'] ?? null;
$jl = is_array($J) ? ($J['ingredients'][0] ?? $J['recipe']['ingredients'][0] ?? null) : null;
check(is_array($jl) && ($jl['entry_match']['measure'] ?? '') === 'shot' && near($jl['entry_match']['qty'] ?? null, 2), 'Recipes get_recipe returns the line as 2 shots', substr(json_encode($J), 0, 400));

// A recipe typed in the hotel's own measure, then that measure removed or renamed: the saved line never changes.
$listJig = "INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('stock_measures_list', '[{\"name\":\"jigger\",\"ml\":44},{\"name\":\"half tot\",\"ml\":12.5}]', 'stock') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
$setupJ = [
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES ('ZZT DM $run Vodka', 'ZZDM', 'bottle', 5, 30000, 750)",
    "INSERT INTO menu_items (category_id, item_name, price, is_available) VALUES ($catId, 'ZZT DM $run Vodka tot', 5000, 1)",
    $listJig,
];
$r = $page('admin/stock-recipes.php', ['setup' => $setupJ, 'vars' => $varsR, 'post' => array_merge($postR, ['entry_measure' => ['jiggers']]), 'verify' => [$sriSql]]);
$S = $r['verify'][0] ?? [];
$safe($r, 'Stock > Recipes save in jiggers');
check(near($S[0]['q'] ?? null, 0.117333) && ($S[0]['m'] ?? '') === 'jigger' && near($S[0]['eq'] ?? null, 2) && near($S[0]['ml'] ?? null, 44),
    'Recipes "2 jiggers" of a 750 ml bottle saved as 0.117333 with the measure jigger (44 ml)', json_encode($S) . ' ' . $flash($r));
$r = $page('admin/stock-recipes.php', ['setup' => $setupJ, 'vars' => $varsR, 'post' => array_merge($postR, ['quantity_per_portion' => ['3'], 'entry_measure' => ['half tots']]), 'verify' => [$sriSql]]);
check(near($r['verify'][0][0]['q'] ?? null, 0.05) && ($r['verify'][0][0]['m'] ?? '') === 'half tot', 'Recipes "3 half tots" (12.5 ml) saved as 0.05 bottle', json_encode($r['verify'][0] ?? null) . ' ' . $flash($r));
$jigLine = "INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent, entry_measure, entry_qty, entry_ml) SELECT sr.id, i.id, 0.117333, 100, 'jigger', 2, 44 FROM stock_recipes sr JOIN menu_items mi ON mi.id = sr.menu_item_id JOIN stock_ingredients i ON i.name = 'ZZT DM $run Vodka' WHERE mi.item_name = 'ZZT DM $run Vodka tot'";
$setupJL = array_merge($setupJ, [
    "INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe) SELECT mi.id, mc.slug, 1 FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.item_name = 'ZZT DM $run Vodka tot'",
    $jigLine,
]);
foreach (['removed' => [['half tot'], ['12.5']], 'renamed to pony' => [['pony', 'half tot'], ['44', '12.5']]] as $how => [$names, $mls]) {
    $r = $page('admin/stock-ingredients.php', ['setup' => $setupJL, 'post' => ['action' => 'save_drink_measures', 'measure_name' => $names, 'measure_ml' => $mls], 'verify' => [$sriSql, $listSql]]);
    $S = $r['verify'][0] ?? [];
    check(near($S[0]['q'] ?? null, 0.117333) && ($S[0]['m'] ?? '') === 'jigger' && near($S[0]['eq'] ?? null, 2) && near($S[0]['ml'] ?? null, 44) && strpos((string)($r['verify'][1][0]['setting_value'] ?? ''), 'jigger') === false,
        "jigger $how from the list: the saved recipe line is unchanged (0.117333 bottle, typed as 2 jigger)", json_encode($r['verify'] ?? null) . ' ' . $flash($r));
}
$setupJG = array_merge($setupJL, ["UPDATE site_settings SET setting_value = '[{\"name\":\"pony\",\"ml\":44}]' WHERE setting_key = 'stock_measures_list'"]);
$r = $page('admin/stock-recipes.php', ['setup' => $setupJG, 'vars' => $varsR, 'method' => 'GET', 'get' => ['ajax' => 'get_recipe', 'menu_item_id' => '{mi}', 'menu_type' => 'x']]);
$J = $r['json'] ?? null;
$jl2 = is_array($J) ? ($J['ingredients'][0] ?? $J['recipe']['ingredients'][0] ?? null) : null;
check(is_array($jl2) && ($jl2['entry_match'] ?? null) === null && near($jl2['quantity_per_portion'] ?? null, 0.117333), 'After the rename, Recipes opens the line in bottles (0.117333), no stale measure', substr(json_encode($J), 0, 400));

// The three screens render the new parts.
$r = $page('admin/stock-ingredients.php', ['method' => 'GET', 'grep' => ['id="drinkMeasuresModal"', 'id="ing_drink"', 'name="volume_present"', 'stock-drink-measures.js', 'name="measure_name[]"', 'Drink measures']]);
check(!in_array(false, $r['found'] ?? [false], true), 'Stock Items shows the Drink measures button, list and the bottle size field', json_encode($r['found'] ?? $r));
$r = $page('admin/stock-recipes.php', ['method' => 'GET', 'grep' => ['stock-drink-measures.js', 'drinkMeasures', 'measure-pick']]);
check(!in_array(false, $r['found'] ?? [false], true), 'Recipes loads the measures and the picker', json_encode($r['found'] ?? $r));
$r = $page('admin/menu-management.php', ['method' => 'GET', 'grep' => ['stock-drink-measures.js', 'mmMeasureOpts']]);
check(!in_array(false, $r['found'] ?? [false], true), 'Menu Management loads the measures and the picker', json_encode($r['found'] ?? $r));

// Static: every reader of the new columns is guarded by rh_stock_drink_ready (old databases keep working).
foreach (['admin/stock-recipes.php', 'admin/includes/menu-recipe-lib.php', 'admin/api/menu-recipe.php', 'admin/stock-ingredients.php'] as $f) {
    $src = (string)file_get_contents($f);
    check(strpos($src, 'rh_stock_drink_ready') !== false || strpos($src, 'rh_drink_ing_cols') !== false, "$f checks that 089 has run before using the new columns");
}
check((string)col($pdo, $listSql) === $listBefore && (int)col($pdo, "SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ?", ["ZZT DM $run %"]) === 0
    && (int)col($pdo, "SELECT COUNT(*) FROM admin_activity_log WHERE details LIKE ?", ["%ZZT DM $run%"]) === 0, 'Nothing left behind (list, items, activity)');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
