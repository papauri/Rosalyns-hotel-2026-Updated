<?php
/**
 * Stock quantity in words (owner 2026-11-21: "show bottles + shots"). Usage: php scripts/tests/stock_qty_words_test.php
 *
 * Pure function cases for rh_stock_qty_words() / rh_stock_qty_html() (config/database.php): an item with a count
 * measure shows whole bigs + the rest in smalls, the small part to 1 dp; stock kept in bigs or in smalls gives the same
 * words; 29.99 of 30 rolls into the next big; below zero reads "short by ..."; count differences read "over by" /
 * "short by"; no measure = the number at most 2 dp + the unit; the exact figure is on hover. Display only: no database
 * reads or writes (config/database.php is loaded for the functions).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once __DIR__ . '/../../config/database.php';

$pass = 0; $fail = 0;
function eq(string $got, string $want, string $label): void
{
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label: got \"$got\", want \"$want\"\n"; }
}

$big = ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30.0000', 'measure_stock_side' => 'big'];
$small = ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '30.0000', 'measure_stock_side' => 'small'];
$glass = ['measure_big_name' => 'bottle', 'measure_small_name' => 'glass', 'measure_per_big' => '4.2857', 'measure_stock_side' => 'big'];
$none = ['measure_big_name' => null, 'measure_small_name' => null, 'measure_per_big' => null, 'measure_stock_side' => null];

// Stock kept in bottles (side big).
eq(rh_stock_qty_words(2.233333, $big, 'bottle'), '2 bottles + 7 shots', 'side big 2.233333 n30 -> 2 bottles + 7 shots');
eq(rh_stock_qty_words(2.0, $big, 'bottle'), '2 bottles', 'exact bottles');
eq(rh_stock_qty_words(1.0, $big, 'bottle'), '1 bottle', 'one bottle is singular');
eq(rh_stock_qty_words(0.233333, $big, 'bottle'), '7 shots', 'under one bottle -> shots only');
eq(rh_stock_qty_words(0.033333, $big, 'bottle'), '1 shot', 'one shot is singular');
eq(rh_stock_qty_words(1.033333, $big, 'bottle'), '1 bottle + 1 shot', 'one bottle + one shot, both singular');
eq(rh_stock_qty_words(2.249999, $big, 'bottle'), '2 bottles + 7.5 shots', 'half shot kept to 1 dp');
eq(rh_stock_qty_words(6.9999 / 30, $big, 'bottle'), '7 shots', '6.9999 shots -> 7 shots');
eq(rh_stock_qty_words(29.99 / 30, $big, 'bottle'), '1 bottle', '29.99 of 30 shots rolls into the next bottle');
eq(rh_stock_qty_words(1 + 29.99 / 30, $big, 'bottle'), '2 bottles', '1 bottle + 29.99 shots -> 2 bottles');
eq(rh_stock_qty_words(0.0, $big, 'bottle'), '0 bottles', 'zero shows in the stock measure');
eq(rh_stock_qty_words(0.0001, $big, 'bottle'), '0 bottles', 'a dust figure shows as 0');

// Stock kept in shots (side small): same words.
eq(rh_stock_qty_words(67.0, $small, 'shot'), '2 bottles + 7 shots', 'side small 67 n30 -> 2 bottles + 7 shots');
eq(rh_stock_qty_words(60.0, $small, 'shot'), '2 bottles', 'side small exact bottles');
eq(rh_stock_qty_words(7.0, $small, 'shot'), '7 shots', 'side small under one bottle');
eq(rh_stock_qty_words(29.99, $small, 'shot'), '1 bottle', 'side small 29.99 rolls into a bottle');
eq(rh_stock_qty_words(0.0, $small, 'shot'), '0 shots', 'side small zero in shots');

// A measure with a fractional N (glasses of wine).
eq(rh_stock_qty_words(1.5, $glass, 'bottle'), '1 bottle + 2.1 glasses', 'fractional N: 1.5 bottles = 1 bottle + 2.1 glasses');
eq(rh_stock_qty_words(1.0, $glass, 'bottle'), '1 bottle', 'fractional N: exact bottle');

// Negative stock: the whole figure is wrapped.
eq(rh_stock_qty_words(-1.1, $big, 'bottle'), 'short by 1 bottle + 3 shots', 'negative -> short by 1 bottle + 3 shots');
eq(rh_stock_qty_words(-0.1, $big, 'bottle'), 'short by 3 shots', 'negative under one bottle');
eq(rh_stock_qty_words(-0.00001, $big, 'bottle'), '0 bottles', 'negative dust shows as 0');

// Count differences (signed).
eq(rh_stock_qty_words(0.1, $big, 'bottle', true), 'over by 3 shots', 'signed positive -> over by');
eq(rh_stock_qty_words(-1.1, $big, 'bottle', true), 'short by 1 bottle + 3 shots', 'signed negative -> short by');
eq(rh_stock_qty_words(0.0, $big, 'bottle', true), '0 bottles', 'signed zero');

// No count measure: number at most 2 dp + the unit as stored.
eq(rh_stock_qty_words(2.233333, $none, 'bottle'), '2.23 bottle', 'no measure 2.233333 -> 2.23 bottle');
eq(rh_stock_qty_words(2.233333, [], 'kg'), '2.23 kg', 'row without measure columns (before migration 088)');
eq(rh_stock_qty_words(12.0, $none, 'kg'), '12 kg', 'no measure whole number has no trailing zeros');
eq(rh_stock_qty_words(2.5, $none, 'kg'), '2.5 kg', 'no measure one decimal');
eq(rh_stock_qty_words(1234.5, $none, 'g'), '1,234.5 g', 'no measure thousands separator');
eq(rh_stock_qty_words(-1.5, $none, 'kg'), '-1.5 kg', 'no measure negative keeps its minus');
eq(rh_stock_qty_words(-0.001, $none, 'kg'), '0 kg', 'no measure negative dust -> 0');
eq(rh_stock_qty_words(1.5, $none, 'kg', true), '+1.5 kg', 'no measure signed positive');
eq(rh_stock_qty_words(2.0, $none, ''), '2', 'no measure, no unit');
eq(rh_stock_qty_words(2.233333, ['measure_big_name' => 'bottle', 'measure_small_name' => 'shot', 'measure_per_big' => '0', 'measure_stock_side' => 'big'], 'bottle'), '2.23 bottle', 'a broken measure (N 0) falls back to the number');

// Plurals of other names.
$box = ['measure_big_name' => 'box', 'measure_small_name' => 'glass', 'measure_per_big' => '10', 'measure_stock_side' => 'big'];
eq(rh_stock_qty_words(2.3, $box, 'box'), '2 boxes + 3 glasses', 'plural es for box / glass');

// HTML: escaped words, exact figure on hover.
eq(rh_stock_qty_html(-1.1, $big, 'bottle'), '<span class="rh-qty" title="Exact: -1.1 bottle">short&nbsp;by&nbsp;1&nbsp;bottle + 3&nbsp;shots</span>', 'html: negative keeps short by with its figure');
eq(rh_stock_qty_html(2.233333, $big, 'bottle'), '<span class="rh-qty" title="Exact: 2.233333 bottle">2&nbsp;bottles + 7&nbsp;shots</span>', 'html: words with the exact figure as title, wraps only at +');
eq(rh_stock_qty_html(1.5, $none, 'kg <b>'), '<span class="rh-qty" title="Exact: 1.5 kg &lt;b&gt;">1.5&nbsp;kg&nbsp;&lt;b&gt;</span>', 'html: unit is escaped');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
