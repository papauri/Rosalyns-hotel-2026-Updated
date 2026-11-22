<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock Items: set one count measure on many ticked items (owner 2026-11-23, "Build #2"; admin/stock-ingredients.php +
 * admin/includes/stock-measure-bulk-lib.php + admin/js/stock-ingredients-measure-bulk.js).
 * Usage (project root): php scripts/tests/stock_measure_bulk.php
 *
 * The library scenarios and the page posts run in scripts/tests/stock_measure_bulk_child.php through page_harness.php (ONE outer
 * transaction that can never commit, rolled back at the end). Covers: set on 3 new-measure items, same, different (kept unless
 * "Change these too"), unit mismatch skipped, invalid N refused, ml helper exact / not exact, Tick all shown with a category filter
 * (every page), quantities / costs / batches unchanged, CSRF and permission refused, rh_stock_qty_words "2 bottles + 7 shots (57 shots)".
 * Every row is named 'ZZB <run> ...'; none may be left behind.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function harness(string $page, array $cfg): array {
    global $root;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' ' . $page . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $h = ['error' => 'no result', 'raw' => substr((string)$out, -800)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x + ['raw' => substr((string)$out, 0, 2000)]; } }
    return $h;
}
$clean = function (array $h): bool { return !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']); };
$run = strtoupper(bin2hex(random_bytes(3)));
$child = 'scripts/tests/stock_measure_bulk_child.php';
$itemsSql = "SELECT name, current_quantity, cost_per_unit, unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE name LIKE 'ZZB $run %' ORDER BY name";

// ---- library scenarios (preview, apply, guards)
$h = harness($child, ['post' => ['run' => $run, 'scenario' => 'lib']]);
ok('harness: the library scenarios ran and were rolled back, nothing leaked', $clean($h), substr((string)($h['raw'] ?? ''), -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)($h['raw'] ?? ''), -1500));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}

// ---- the page: preview by ticked ids
$p = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_preview']]);
$pj = $p['json'] ?? [];
$pRow = null;
foreach ((array)($pj['rows'] ?? []) as $r) { if ($r['name'] === "ZZB $run Brandy A") { $pRow = $r; } }
ok('page preview (6 ticked): 3 set, 1 same, 1 different, 1 skipped; Brandy A "2.28 bottle" -> "2 bottles + 7 shots (57 shots)"; nothing saved', $clean($p) && !empty($pj['ok'])
    && ($pj['counts'] ?? null) === ['set' => 3, 'same' => 1, 'different' => 1, 'change' => 0, 'skip' => 1] && $pRow && $pRow['qty_now'] === '2.28 bottle'
    && $pRow['qty_after'] === '2 bottles + 7 shots (57 shots)' && strlen((string)($pj['fingerprint'] ?? '')) === 64 && !isset($pRow['_new']), json_encode($pj ?: $p));

// ---- the page: apply (fingerprint from the same preview), quantities and costs unchanged
$a = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_apply'], 'verify' => [$itemsSql]]);
$aj = $a['json'] ?? [];
$rows = [];
foreach ((array)($a['verify'][0] ?? []) as $r) { $rows[str_replace("ZZB $run ", '', $r['name'])] = $r; }
ok('page apply: set 3, unchanged 2, skipped 1 (Sugar Kg, with its reason), rolled back', $clean($a) && !empty($aj['ok']) && count($aj['set'] ?? []) === 3 && count($aj['unchanged'] ?? []) === 2
    && count($aj['skipped'] ?? []) === 1 && strpos((string)($aj['skipped'][0][1] ?? ''), '"kg"') !== false, json_encode($aj ?: $a));
ok('page apply: Brandy A, Gin B, Rum C now count in shots (25 a bottle, kept in bottles); Whisky Diff keeps 20 tots; Sugar Kg none', isset($rows['Brandy A'], $rows['Whisky Diff'], $rows['Sugar Kg'])
    && $rows['Brandy A']['measure_small_name'] === 'shot' && (float)$rows['Brandy A']['measure_per_big'] === 25.0 && $rows['Brandy A']['measure_stock_side'] === 'big'
    && $rows['Gin B']['measure_small_name'] === 'shot' && $rows['Rum C']['measure_small_name'] === 'shot' && $rows['Whisky Diff']['measure_small_name'] === 'tot'
    && $rows['Sugar Kg']['measure_small_name'] === null, json_encode($rows));
ok('page apply: quantities and costs exactly as seeded (2.28 / 4000, 5 / 6000, 0.5 / 3000, 3 / 1500)', isset($rows['Brandy A'], $rows['Gin B'], $rows['Rum C'], $rows['Sugar Kg'])
    && (float)$rows['Brandy A']['current_quantity'] === 2.28 && (float)$rows['Brandy A']['cost_per_unit'] === 4000.0 && (float)$rows['Gin B']['current_quantity'] === 5.0
    && (float)$rows['Gin B']['cost_per_unit'] === 6000.0 && (float)$rows['Rum C']['current_quantity'] === 0.5 && (float)$rows['Rum C']['cost_per_unit'] === 3000.0
    && (float)$rows['Sugar Kg']['current_quantity'] === 3.0 && (float)$rows['Sugar Kg']['cost_per_unit'] === 1500.0, json_encode($rows));
ok('page apply: the result rows say Set for the saved items', in_array('done_set', array_column((array)($aj['rows'] ?? []), 'status'), true), json_encode(array_column((array)($aj['rows'] ?? []), 'status')));

// ---- the page: "Tick all shown" with a category filter (12 items = 2 pages of 10), preview and apply; other categories untouched
$t = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_all_preview']]);
$tj = $t['json'] ?? [];
ok('page Tick all shown + category (12 items, every page): preview lists all 12 as "set"', $clean($t) && !empty($tj['ok']) && count($tj['rows'] ?? []) === 12 && ($tj['counts']['set'] ?? 0) === 12, json_encode($tj['counts'] ?? $tj));
$ta = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_all_apply'], 'verify' => [
    "SELECT category, COUNT(*) AS n, SUM(measure_small_name = 'shot') AS shots FROM stock_ingredients WHERE name LIKE 'ZZB $run %' AND category LIKE 'ZZB $run %' GROUP BY category ORDER BY category"]]);
$byCat = [];
foreach ((array)($ta['verify'][0] ?? []) as $r) { $byCat[str_replace("ZZB $run ", '', $r['category'])] = [(int)$r['n'], (int)$r['shots']]; }
ok('page Tick all shown + category apply: the 12 active Many items set (archived one not), Other and Bar untouched', $clean($ta) && !empty($ta['json']['ok']) && count($ta['json']['set'] ?? []) === 12
    && ($byCat['Many'] ?? null) === [13, 12] && ($byCat['Other'] ?? null) === [2, 0] && ($byCat['Bar'][1] ?? -1) === 1, json_encode([$ta['json']['errors'] ?? null, $byCat]));

// ---- refused: wrong security token, invalid N
$c = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_csrf'], 'verify' => [$itemsSql]]);
$cRows = array_column((array)($c['verify'][0] ?? []), 'measure_small_name', 'name');
ok('page apply without the right security token: refused, nothing saved', $clean($c) && empty($c['json']['ok']) && stripos(implode(' ', (array)($c['json']['errors'] ?? [])), 'Security token') !== false
    && array_key_exists("ZZB $run Brandy A", $cRows) && $cRows["ZZB $run Brandy A"] === null, json_encode([$c['json'] ?? $c, $cRows["ZZB $run Brandy A"] ?? 'missing']));
$b = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_bad_n']]);
ok('page preview with N = 0: refused with the 1..1000 reason, no rows', $clean($b) && empty($b['json']['ok']) && strpos(implode(' ', (array)($b['json']['errors'] ?? [])), 'between 1 and 1000') !== false
    && !isset($b['json']['rows']), json_encode($b['json'] ?? $b));

// ---- permission: a user without the Stock permission never reaches the handler; an admin does (and is refused only by the stale preview)
$seed = ["INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZB $run Perm Gin', 'ZZB $run Perm', 'bottle', 2.28, 4000)"];
$vars = ['a' => "SELECT id FROM stock_ingredients WHERE name = 'ZZB $run Perm Gin' ORDER BY id DESC LIMIT 1"];
$applyPost = ['action' => 'measure_bulk_apply', 'mode' => 'ids', 'ids' => '{a}', 'small_name' => 'shot', 'per_big' => '25', 'side' => 'big', 'fingerprint' => str_repeat('0', 64)];
$staff = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY id LIMIT 1")->fetchColumn();
if ($staff === '') {
    ok('a non-admin user exists for the permission check', false, 'none');
} else {
    $grant = "INSERT INTO user_permissions (user_id, permission_key, is_granted) SELECT id, '%s', %d FROM admin_users WHERE username = " . $pdo->quote($staff) . " ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)";
    $np = harness('admin/stock-ingredients.php', ['as_username' => $staff, 'setup' => array_merge($seed, [sprintf($grant, 'stock_management', 0), sprintf($grant, 'view_only', 0)]), 'vars' => $vars,
        'post' => $applyPost, 'verify' => ["SELECT measure_small_name FROM stock_ingredients WHERE name = 'ZZB $run Perm Gin'"]]);
    ok('permission: a user without the Stock permission is sent away before the handler (no JSON), nothing saved', $clean($np) && !isset($np['json'])
        && array_key_exists('measure_small_name', (array)($np['verify'][0][0] ?? [])) && $np['verify'][0][0]['measure_small_name'] === null, json_encode([$np['json'] ?? null, $np['verify'] ?? null, $np['bytes'] ?? null]));
}
$ad = harness('admin/stock-ingredients.php', ['setup' => $seed, 'vars' => $vars, 'post' => $applyPost, 'verify' => ["SELECT measure_small_name FROM stock_ingredients WHERE name = 'ZZB $run Perm Gin'"]]);
ok('control: an admin reaches the handler; a made-up preview fingerprint is refused, nothing saved', $clean($ad) && empty($ad['json']['ok'])
    && strpos(implode(' ', (array)($ad['json']['errors'] ?? [])), 'Something changed since the preview') !== false && array_key_exists('measure_small_name', (array)($ad['verify'][0][0] ?? [])) && $ad['verify'][0][0]['measure_small_name'] === null, json_encode([$ad['json'] ?? $ad, $ad['verify'] ?? null]));

// ---- the list screen: tick column, bar, dialog, ? helpers
$g = harness('admin/stock-ingredients.php', ['method' => 'GET', 'setup' => $seed, 'grep' => ['id="smb-bar"', 'Tick all shown', 'Clear ticks', 'Set count measure', 'id="smbModal"',
    'class="stm-tick"', 'Count measure: how you count part-used bottles, e.g. 25 shots in one bottle', 'Stock is kept in', 'Change these too', 'stock-ingredients-measure-bulk.js', "ZZB $run Perm Gin"]]);
$gf = (array)($g['found'] ?? []);
ok('list: tick boxes, Tick all shown / Clear ticks / Set count measure bar with its ?, the dialog (bigger unit, smaller measure, N, kept in, ml, Change these too)', $clean($g) && $gf && !in_array(false, $gf, true), json_encode($gf ?: $g));

// ---- nothing left behind
$left = 0;
foreach (["SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZB $run %'", "SELECT COUNT(*) FROM stock_counts WHERE reference = '20991231-ZZB$run'",
          "SELECT COUNT(*) FROM stock_batches WHERE batch_number LIKE 'ZZB-$run-%'"] as $sql) {
    $left += (int)$pdo->query($sql)->fetchColumn();
}
ok('no ZZB rows were left behind (stock items, counts, batches)', $left === 0, (string)$left);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
