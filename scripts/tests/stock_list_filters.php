<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Stock list filters that dashboards link into (admin/includes/stock-list-filters.php). NO DATABASE.
 * - ?filter= whitelists (batches: expiring|expired, ingredients: low)
 * - the batch clauses are the dashboards' count clauses word for word (read from the dashboard sources)
 * - the ingredient 'low' clause is rh_stock_alert_summary()'s out + low halves word for word
 * - the expired snapshot keeps the dashboard's batches across pages but never goes stale on arrival
 * - the pages wire the filters in (source checks)
 * Usage (repo root): php scripts/tests/stock_list_filters.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }
/** Collapse whitespace and spaces around '=' so "status='active'" and "status = 'active'" compare equal. */
function norm(string $sql): string { return trim(preg_replace('/\s*=\s*/', '=', preg_replace('/\s+/', ' ', $sql))); }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/admin/includes/stock-list-filters.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');

// ---- whitelists
eq(array_keys(rh_stock_batch_list_filters()), ['expiring', 'expired'], 'batch filters: expiring + expired');
eq(rh_stock_batch_filter_key('expiring'), 'expiring', 'expiring kept');
eq(rh_stock_batch_filter_key(' Expired '), 'expired', 'case/space tolerant');
eq(rh_stock_batch_filter_key("expired' OR 1=1"), '', 'injection dropped');
eq(rh_stock_batch_filter_key('recalled'), '', 'unknown filter dropped (recalled has the status box)');
eq(rh_stock_batch_filter_key(['expired']), '', 'array dropped');
eq(rh_stock_batch_filter_key(null), '', 'missing -> no filter');
eq(rh_stock_ingredient_filter_key('low'), 'low', 'ingredients: low kept');
eq(rh_stock_ingredient_filter_key('LOW '), 'low', 'ingredients: case/space tolerant');
eq(rh_stock_ingredient_filter_key('critical'), '', 'ingredients: unknown dropped');
eq(rh_stock_ingredient_filter_key(['low']), '', 'ingredients: array dropped');

// ---- batch clauses = the dashboards' count clauses
$dash  = (string)file_get_contents($root . '/admin/dashboard.php');
$sdash = (string)file_get_contents($root . '/admin/stock-dashboard.php');
$expiringBare = norm(rh_stock_batch_expiring_sql(''));
$expiredBare  = norm(rh_stock_batch_expired_sql(''));
check(strpos(norm($dash), "COUNT(*) FROM stock_batches WHERE " . $expiringBare . '")') !== false, 'expiring == dashboard.php Expiring 7d / Expiring batches count clause');
check(substr_count(norm($dash), "FROM stock_batches WHERE " . $expiredBare . '")') >= 2, 'expired == both dashboard.php Expired counts');
check(strpos(norm($sdash), "FROM stock_batches WHERE " . $expiredBare . '")') !== false, 'expired == stock-dashboard.php expired_batches count');
$aliased = norm(rh_stock_batch_expired_sql('b'));
check(strpos(norm($sdash), $aliased) !== false, 'expired == stock-dashboard.php "Expired Batches Still Active" list (b. alias)');
check(strpos($expiringBare, 'CURDATE()') !== false && strpos($expiredBare, 'CURDATE()') !== false, 'hotel date via CURDATE() (session time_zone)');
eq(rh_stock_batch_expiring_sql('b; DROP'), str_replace('b.', 'bDROP.', rh_stock_batch_expiring_sql('b')), 'alias sanitised');

// ---- ingredient low clause = rh_stock_alert_summary attention (out + low)
$db = (string)file_get_contents($root . '/config/database.php');
$start = strpos($db, 'function rh_stock_alert_summary(');
$summary = $start !== false ? substr($db, $start, 4000) : '';
check($summary !== '', 'found rh_stock_alert_summary in config/database.php');
$parts = rh_stock_attention_parts('{$thr}', '{$hist}', 'i');
check(strpos($summary, 'WHEN ' . $parts['out'] . ' THEN 1 ELSE 0 END) AS out_n') !== false, 'out half == summary out_n', $parts['out']);
check(strpos($summary, 'WHEN ' . $parts['low'] . ' THEN 1 ELSE 0 END) AS low_n') !== false, 'low half == summary low_n', $parts['low']);
check(strpos($summary, "\$res['attention']     = \$res['out'] + \$res['low'];") !== false, 'attention is still out + low');
check(strpos($summary, 'FROM stock_ingredients i WHERE i.is_archived = 0') !== false, 'summary counts unarchived only');
$att = rh_stock_attention_sql('T', 'H', 'i');
eq($att, '(i.is_archived = 0 AND ((i.current_quantity <= 0.0001 AND H) OR (i.current_quantity > 0.0001 AND T > 0 AND i.current_quantity <= T)))', 'attention clause shape');
check(strpos($att, 'NOT H') === false, 'never-stocked (no history) items are not in the list (they add 0 to the badge)');

// In-PHP evaluation over fixtures: the clause logic picks the same rows as the summary rule.
$fixtures = [ // [qty, threshold, has_history, archived, expected]
    [0.0, 5.0, true, false, true],     // out with history
    [0.0, 0.0, true, false, true],     // out, no threshold set: still counted (out_n has no threshold)
    [-2.0, 5.0, true, false, true],    // negative
    [0.0, 5.0, false, false, false],   // never stocked
    [3.0, 5.0, true, false, true],     // low
    [5.0, 5.0, true, false, true],     // at the line
    [5.0001, 5.0, true, false, false], // above
    [3.0, 0.0, true, false, false],    // no threshold, stock on hand
    [0.00005, 5.0, true, false, true], // float dust counts as out (<= 0.0001), not low
    [3.0, 5.0, true, true, false],     // archived
];
foreach ($fixtures as $n => [$q, $t, $h, $arch, $want]) {
    $got = !$arch && (($q <= 0.0001 && $h) || ($q > 0.0001 && $t > 0 && $q <= $t));
    eq($got, $want, "fixture #$n");
}

// ---- expired snapshot
$today = '2026-11-11';
eq(rh_stock_expired_snapshot(null, $today, false, [5, '3', 5]), [3, 5], 'arrival: fresh ids, deduped, sorted');
eq(rh_stock_expired_snapshot(['date' => $today, 'ids' => [1, 2]], $today, false, []), [], 'arrival never reuses an old list (dashboard now says 0)');
eq(rh_stock_expired_snapshot(['date' => $today, 'ids' => [1, 2]], $today, true, []), [1, 2], 'page 2 / ingredient pick keeps today\'s list after the sweep');
eq(rh_stock_expired_snapshot(['date' => $today, 'ids' => [1, 2]], $today, true, [7]), [1, 2, 7], 'kept list plus newly expired');
eq(rh_stock_expired_snapshot(['date' => '2026-11-10', 'ids' => [1, 2]], $today, true, [9]), [9], 'yesterday\'s list dropped');
eq(rh_stock_expired_snapshot(['date' => $today, 'ids' => ['1 OR 1', -4, 0, 'x', 8]], $today, true, []), [8], 'junk ids dropped');
eq(rh_stock_expired_snapshot('garbage', $today, true, []), [], 'bad session value ignored');
eq(rh_stock_id_in_sql('b.id', [1, 2, 3]), 'b.id IN (?,?,?)', 'ids bound as placeholders');
eq(rh_stock_id_in_sql('b.id', []), '0 = 1', 'empty list matches nothing (never everything)');
eq(rh_stock_id_in_sql('b.id;--', [1]), 'b.id IN (?)', 'column sanitised');

// ---- pages wire the filters in
$bp = (string)file_get_contents($root . '/admin/stock-batches.php');
$ip = (string)file_get_contents($root . '/admin/stock-ingredients.php');
check(strpos($bp, "rh_stock_batch_filter_key(\$_GET['filter'] ?? '')") !== false, 'batches: filter whitelisted');
check(strpos($bp, "rh_stock_batch_expiring_sql('b')") !== false, 'batches: expiring uses the shared clause');
check(strpos($bp, "rh_stock_batch_expired_sql('b')") !== false, 'batches: expired snapshot uses the shared clause');
$snapPos = strpos($bp, 'rh_stock_expired_snapshot(');
$sweepPos = strpos($bp, 'runStockExpiryCheck();');
check($snapPos !== false && $sweepPos !== false && $snapPos < $sweepPos, 'batches: snapshot is read before the expiry sweep');
check(strpos($bp, "'filter' => \$batchFilter") !== false, 'batches: pagination keeps the filter');
check(strpos($bp, "'filter'        => \$batchFilter") !== false, 'batches: POST redirect keeps the filter');
check(strpos($bp, 'name="filter"') !== false, 'batches: toolbar form carries the filter');
check(strpos($bp, '>Clear</a>') !== false, 'batches: notice has Clear');
check(strpos($ip, "rh_stock_ingredient_filter_key(\$_GET['filter'] ?? '')") !== false, 'ingredients: filter whitelisted');
check(strpos($ip, "rh_stock_attention_sql(rh_stock_threshold_sql('i'), \$histSql, 'i')") !== false, 'ingredients: low uses the alert rule');
check(strpos($ip, "header('Location: stock-ingredients.php' . (\$ingFilter !== ''") !== false, 'ingredients: POST redirect keeps the filter');
check(strpos($ip, '>Clear</a>') !== false, 'ingredients: notice has Clear');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
