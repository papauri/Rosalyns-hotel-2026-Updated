<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Smarter search matcher (includes/smart-search.php). NO DATABASE.
 * Words, phone numbers, literal wildcards, placeholder/param counts, and that the bookings list, its export, the
 * payments filter and the universal search all use it.
 * Usage (repo root): php scripts/tests/smart_search.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { bad('PHP notice/warning', "$msg at $file:$line"); return true; });
require_once $root . '/includes/smart-search.php';

// ---- words
eq(rh_search_terms('  john   banda '), ['john', 'banda'], 'words split on any spacing');
eq(rh_search_terms('John john JOHN'), ['John'], 'repeated words count once (any case)');
eq(rh_search_terms(''), [], 'empty search has no words');
eq(count(rh_search_terms('a b c d e f g h i')), 6, 'at most 6 words');
eq(rh_search_terms('banda, john'), ['banda', 'john'], 'commas are not part of a word');

// ---- phone numbers
eq(rh_search_phone_core('0999 123 456'), '999123456', 'local number: leading 0 dropped');
eq(rh_search_phone_core('+265 999 123 456'), '999123456', '+265 dropped');
eq(rh_search_phone_core('00265999123456'), '999123456', '00265 dropped');
eq(rh_search_phone_core('999-123-456'), '999123456', 'dashes ignored');
eq(rh_search_phone_core('(0)999.123.456'), '', 'starts with a bracket: not read as a phone number');
eq(rh_search_phone_core('12345'), '', 'under 6 digits is not a phone number');
eq(rh_search_phone_core('BK-2026-0001'), '', 'a reference is not a phone number');
eq(rh_search_phone_core('265123'), '265123', 'short number starting 265 keeps its digits');
eq(rh_search_terms('0999 123 456'), ['0999 123 456'], 'a spaced phone number stays one term');

// ---- clause shape
$one = rh_search_where('Phiri', ['a.x', 'a.y']);
eq($one['params'], ['%Phiri%', '%Phiri%'], 'one word: one %word% per column');
check(substr_count($one['sql'], ' LIKE ?') === 2 && substr_count($one['sql'], " LIKE ? ESCAPE '!'") === 2, 'one word: every LIKE carries the escape');
$two = rh_search_where('john banda', ['a.x', 'a.y']);
eq(substr_count($two['sql'], '?'), count($two['params']), 'placeholders equal params (two words)');
eq($two['params'], ['%john%', '%john%', '%banda%', '%banda%'], 'two words: each word against every column');
check(strpos($two['sql'], ') AND (') !== false, 'two words must both match (AND of ORs)');
eq(rh_search_where('   ', ['a.x']), ['sql' => '', 'params' => []], 'blank search adds nothing');
$ph = rh_search_where('0999 123 456', ['b.name', 'b.phone'], ['b.phone']);
eq(substr_count($ph['sql'], '?'), count($ph['params']), 'placeholders equal params (phone)');
check(in_array('%999123456%', $ph['params'], true) && strpos($ph['sql'], "REPLACE(") !== false, 'phone number also compared digits-only');
$noPh = rh_search_where('0999 123 456', ['b.name']);
check(strpos($noPh['sql'], 'REPLACE(') === false, 'no phone columns: no digits comparison');
$pr = rh_search_where('cash ref9', ['b.x'], [], ['EXISTS (SELECT 1 FROM p WHERE p.a LIKE ? OR p.b LIKE ?)']);
eq(substr_count($pr['sql'], '?'), count($pr['params']), 'placeholders equal params (predicates)');
eq(count($pr['params']), 6, 'predicate ? marks each take the word');
$wild = rh_search_where('10%_!', ['a.x']);
eq($wild['params'][0], '%10!%!_!!%', '% _ ! typed by staff are literal');
eq(rh_search_prefix('Te_a'), 'Te!_a%', 'prefix pattern escapes too');
$evil = rh_search_where("x' OR 1=1 --", ['a.x']);
check(strpos($evil['sql'], "1=1") === false, 'user text never reaches the SQL');

// ---- callers use the shared matcher
require_once $root . '/admin/includes/bookings-list-filters.php';
$bk = rh_bk_search_where('deluxe phiri');
eq(substr_count($bk['sql'], '?'), count($bk['params']), 'bookings search: placeholders equal params');
eq(count($bk['params']), 26, 'bookings search: 13 per word');
$bk1 = rh_bk_search_where('BK1');
eq(count($bk1['params']), 13, 'bookings search: one word binds 13 like before');
$bkSrc = (string)file_get_contents($root . '/admin/bookings.php');
eq(substr_count($bkSrc, 'rh_bk_search_where($search_query)'), 2, 'bookings list and export share rh_bk_search_where');
check(strpos($bkSrc, "array_fill(0, 13, \$search_param)") === false, 'bookings: the old single-phrase clause is gone');
require_once $root . '/admin/includes/finance-filters.php';
$pay = rh_fin_payments_where(['search' => 'phiri 50000'], 'transaction_reference', []);
eq(substr_count($pay['sql'], '?'), count($pay['params']), 'payments search: placeholders equal params');
eq(count($pay['params']), 54, 'payments search: 27 per word');
$gs = (string)file_get_contents($root . '/admin/api/global-search.php');
check(strpos($gs, 'rh_search_where(') !== false && strpos($gs, 'addcslashes') === false, 'universal search uses the shared matcher');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
