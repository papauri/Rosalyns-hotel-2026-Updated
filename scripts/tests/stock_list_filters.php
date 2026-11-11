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

// ---- batch list cap + true total (LATER items 2026-11-11)
$cap = rh_stock_batch_list_cap();
eq($cap, 2000, 'list cap is 2000');
eq(rh_stock_batch_list_total(15, null), 15, 'under the cap: rows loaded is the total');
eq(rh_stock_batch_list_total(15, 99), 15, 'under the cap: a count is never needed');
eq(rh_stock_batch_list_total($cap, $cap), $cap, 'at the cap, count == cap');
eq(rh_stock_batch_list_total($cap, 2345), 2345, 'at the cap, count gives the true total');
eq(rh_stock_batch_list_total($cap, null), $cap, 'failed count falls back to rows loaded');
eq(rh_stock_batch_list_total($cap, 10), $cap, 'count below rows loaded never believed');
eq(rh_stock_batch_capped_text(15, 15), '', 'no cap notice when all listed');
eq(rh_stock_batch_capped_text($cap, $cap), '', 'no cap notice when count == cap');
$capTxt = rh_stock_batch_capped_text($cap, 2345);
check(strpos($capTxt, 'Showing 2,000 of 2,345 batches') === 0, 'cap notice: "Showing 2,000 of 2,345 batches"', $capTxt);

$listStart = strpos($bp, 'SELECT b.*, COALESCE(i.name');
$listSql = $listStart !== false ? substr($bp, $listStart, 600) : '';
check($listSql !== '', 'batches: list query found');
check(strpos($listSql, 'LEFT JOIN stock_ingredients i ON i.id = b.ingredient_id') !== false && strpos($listSql, 'INNER JOIN') === false, 'batches: list LEFT JOINs the ingredient (count == list)');
check(strpos($listSql, "COALESCE(i.unit, '') AS unit") !== false, 'batches: unit never null for a missing item');
check(strpos($bp, "INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE b.id = ? FOR UPDATE") !== false, 'batches: write-off lock select unchanged');
check(strpos($bp, 'LIMIT " . rh_stock_batch_list_cap()') !== false, 'batches: list uses the shared cap');
check(strpos($bp, '"SELECT COUNT(*) FROM stock_batches b WHERE 1=1" . $where') !== false, 'batches: COUNT reuses the list WHERE');
check(preg_match('/\$where \.= " AND [^"]*\bi\./', $bp) === 0, 'batches: no WHERE clause reads the ingredient (COUNT has no join)');
check(strpos($bp, '$bfCount = $batchesMatched;') !== false, 'batches: filter notice uses the full match');
check(strpos($bp, 'number_format($batchesMatched); ?> batch(es)') !== false, 'batches: toolbar count uses the full match');
check(strpos($bp, 'rh_stock_batch_capped_text(count($batches), $batchesMatched)') !== false, 'batches: cap notice shown');
check(strpos($bp, "empty(\$b['ing_id'])") !== false, 'batches: no write-off buttons for a missing item');
$recPos  = strpos($bp, "} elseif (\$action === 'recall') {");
$lockPos = $recPos !== false ? strpos($bp, '$ingLockSt->execute(', $recPos) : false;
$guardPos = $recPos !== false ? strpos($bp, "if (\$ingLockSt->fetchColumn() === false) throw new RuntimeException('Batch not found.');", $recPos) : false;
$batchLockPos = $recPos !== false ? strpos($bp, 'SELECT * FROM stock_batches WHERE id = ? FOR UPDATE', $recPos) : false;
check($lockPos !== false && $guardPos !== false && $batchLockPos !== false && $lockPos < $guardPos && $guardPos < $batchLockPos, 'batches: recall refuses a batch with no item record (ingredient lock, then guard, then batch lock)');
check(strpos($bp, "if (\$expiredCount > 0 && function_exists('rh_clear_stock_dashboard_cache')) rh_clear_stock_dashboard_cache();") !== false, 'batches: expiry sweep busts the stock overview cache');

// ---- ingredients export label matches the filtered download
check(strpos($ip, "\$ingFilter === 'low' ? 'Export low-stock items' : 'Export to Excel'") !== false, 'ingredients: export button says low-stock when filtered');
check(strpos($ip, "'Download low-stock ' . strtolower(\$stockNounPl) . ' only (this filtered list)'") !== false, 'ingredients: export title says low-stock when filtered');
$fltPos = strpos($ip, "if (\$ingFilter === 'low') {");
$expPos = strpos($ip, "if (isset(\$_GET['export']) && \$_GET['export'] === 'csv')");
check($fltPos !== false && $expPos !== false && $fltPos < $expPos, 'ingredients: CSV is built after the low filter (exports what the label says)');

// ---- low-stock count is live everywhere it links from (no cache to go stale)
check(stripos($dash, 'getCache') === false && stripos($dash, 'setCache') === false, 'dashboard.php: no cache (low count is live)');
$sdCachePos = strpos($sdash, 'setCache($cacheKey');
$sdLivePos  = strpos($sdash, '$alertSummary = rh_stock_alert_summary($pdo');
check($sdCachePos !== false && $sdLivePos !== false && $sdLivePos > $sdCachePos, 'stock-dashboard.php: low/out read live after the cached KPIs');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
