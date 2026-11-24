<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Stock Items: fill count measures from bottle sizes + "Shots per bottle" column (owner 2026-11-24; admin/stock-ingredients.php +
 * admin/includes/stock-measure-auto-lib.php + admin/js/stock-ingredients-measure-auto.js; spec .claude/STOCK_MEASURE_AUTO_PLAN.md).
 * Usage (project root): php scripts/tests/stock_measure_auto.php
 *
 * The library scenarios and the page posts run in scripts/tests/stock_measure_auto_child.php through page_harness.php (ONE outer
 * transaction that can never commit, rolled back at the end). Covers: set, same, different (unticked), skip no size / ml unit / weight /
 * crate / mixed shot + glass of wine / inexact / not sold / not on the list, double + shot -> shot, open-count-lines guard, stale
 * fingerprint refused, hand-set never changed when not ticked, quantities / costs / recipes unchanged, the page preview / apply / CSRF,
 * the list column and button, CSV header unchanged. The Drink measures list setting is never written. Every row is 'ZZA <run> ...'.
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
$child = 'scripts/tests/stock_measure_auto_child.php';
$settingBefore = (string)$pdo->query("SELECT COALESCE(MAX(setting_value), '') FROM site_settings WHERE setting_key = 'stock_measures_list'")->fetchColumn();

// ---- library scenarios
$h = harness($child, ['post' => ['run' => $run, 'scenario' => 'lib']]);
ok('harness: the library scenarios ran and were rolled back, nothing leaked', $clean($h), substr((string)($h['raw'] ?? ''), -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)($h['raw'] ?? ''), -1500));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}

// ---- the page: preview (the whole active list), fixture sold in the live list's smallest measure
$p = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_preview']]);
$pj = $p['json'] ?? [];
$pRow = null;
foreach ((array)($pj['rows'] ?? []) as $r) { if ($r['name'] === "ZZA $run Page Gin") { $pRow = $r; } }
ok('page preview: Page Gin "will be set" to 30 of the smallest measure, ticked; fingerprint; internal arrays left out', $clean($p) && !empty($pj['ok']) && $pRow
    && $pRow['status'] === 'set' && $pRow['tick'] === true && strpos((string)$pRow['new'], '30 ') === 0 && strlen((string)($pj['fingerprint'] ?? '')) === 64
    && !isset($pRow['_new']) && isset($pj['counts']['set'], $pj['counts']['skip']), json_encode($pRow ?: ($pj ?: $p)));

// ---- the page: apply (fingerprint from a fresh plan), saved; quantity / cost unchanged
$itemSql = "SELECT current_quantity, cost_per_unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side FROM stock_ingredients WHERE name = 'ZZA $run Page Gin'";
$a = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_apply'], 'verify' => [$itemSql]]);
$aj = $a['json'] ?? [];
$aRow = (array)($a['verify'][0][0] ?? []);
ok('page apply: Page Gin set (bottle side, 30 a bottle), quantity 2.5 and cost 5000 unchanged, rolled back', $clean($a) && !empty($aj['ok']) && ($aj['set'] ?? []) === ["ZZA $run Page Gin"]
    && (float)($aRow['measure_per_big'] ?? 0) === 30.0 && ($aRow['measure_stock_side'] ?? '') === 'big' && (float)($aRow['current_quantity'] ?? 0) === 2.5
    && (float)($aRow['cost_per_unit'] ?? 0) === 5000.0, json_encode([$aj ?: $a, $aRow]));

// ---- refused: wrong security token
$c = harness($child, ['post' => ['run' => $run, 'scenario' => 'page_csrf'], 'verify' => [$itemSql]]);
$cRow = (array)($c['verify'][0][0] ?? []);
ok('page apply without the right security token: refused, nothing saved', $clean($c) && empty($c['json']['ok']) && array_key_exists('measure_small_name', $cRow)
    && $cRow['measure_small_name'] === null, json_encode([$c['json'] ?? $c, $cRow]));

// ---- a made-up fingerprint: the handler refuses it
$seed = ["INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, measure_big_name, measure_small_name, measure_per_big, measure_stock_side)
          VALUES ('ZZA $run Col Gin', 'ZZA $run Col', 'bottle', 2.5, 5000, 'bottle', 'shot', 30, 'big')",
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES ('ZZA $run Col None', 'ZZA $run Col', 'bottle', 1, 100)"];
$vars = ['a' => "SELECT id FROM stock_ingredients WHERE name = 'ZZA $run Col None' ORDER BY id DESC LIMIT 1"];
$ad = harness('admin/stock-ingredients.php', ['setup' => $seed, 'vars' => $vars, 'post' => ['action' => 'measure_auto_apply', 'ticked' => '{a}', 'fingerprint' => str_repeat('0', 64)],
    'verify' => ["SELECT measure_small_name FROM stock_ingredients WHERE name = 'ZZA $run Col None'"]]);
ok('page apply with a made-up preview fingerprint: refused ("Something changed since the preview"), nothing saved', $clean($ad) && empty($ad['json']['ok'])
    && strpos(implode(' ', (array)($ad['json']['errors'] ?? [])), 'Something changed since the preview') !== false && array_key_exists('measure_small_name', (array)($ad['verify'][0][0] ?? []))
    && $ad['verify'][0][0]['measure_small_name'] === null, json_encode([$ad['json'] ?? $ad, $ad['verify'] ?? null]));

// ---- permission: a user without the Stock permission never reaches the handler (same gate as Set count measure)
$staff = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY id LIMIT 1")->fetchColumn();
if ($staff === '') {
    ok('a non-admin user exists for the permission check', false, 'none');
} else {
    $grant = "INSERT INTO user_permissions (user_id, permission_key, is_granted) SELECT id, '%s', %d FROM admin_users WHERE username = " . $pdo->quote($staff) . " ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)";
    foreach (['preview_measure_auto', 'measure_auto_apply'] as $act) {
        $np = harness('admin/stock-ingredients.php', ['as_username' => $staff, 'setup' => array_merge($seed, [sprintf($grant, 'stock_management', 0), sprintf($grant, 'view_only', 0)]), 'vars' => $vars,
            'post' => ['action' => $act, 'ticked' => '{a}', 'fingerprint' => str_repeat('0', 64)], 'verify' => ["SELECT measure_small_name FROM stock_ingredients WHERE name = 'ZZA $run Col None'"]]);
        ok("permission: $act by a user without the Stock permission is sent away before the handler (no JSON), nothing saved", $clean($np) && !isset($np['json'])
            && array_key_exists('measure_small_name', (array)($np['verify'][0][0] ?? [])) && $np['verify'][0][0]['measure_small_name'] === null, json_encode([$np['json'] ?? null, $np['verify'] ?? null]));
    }
}

// ---- the list screen: button, dialog, column
$g = harness('admin/stock-ingredients.php', ['method' => 'GET', 'setup' => $seed, 'grep' => ['id="sma-open"', 'Fill from bottle sizes', 'Shots per bottle',
    'id="smaModal"', 'stock-ingredients-measure-auto.js', '30 shots / bottle', 'data-sort-value="30.0000"', "ZZA $run Col None"]]);
$gf = (array)($g['found'] ?? []);
ok('list: Fill from bottle sizes button, its dialog and script, the Shots per bottle column ("30 shots / bottle", sorts on 30)', $clean($g) && $gf && !in_array(false, $gf, true), json_encode($gf ?: $g));

// ---- CSV export: header exactly as before, no new column
$x = harness('admin/stock-ingredients.php', ['method' => 'GET', 'setup' => $seed, 'get' => ['export' => 'csv'], 'grep' => [
    'Name,Category,Unit,"Current Qty","Min Qty","Reorder Point","Par Level","Lead Time (days)",Cost/Unit,"Stock Value","Next Expiry",Status' . "\n",
    'Shots per bottle', '30 shots']]);
$xf = (array)($x['found'] ?? []);
ok('CSV export: header unchanged (12 columns), no "Shots per bottle" and no "30 shots" text', $clean($x) && count($xf) === 3 && array_values($xf) === [true, false, false], json_encode($xf ?: $x));

// ---- nothing left behind; the Drink measures list setting never written
$left = 0;
foreach (["SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZA $run %'", "SELECT COUNT(*) FROM stock_counts WHERE reference = '20991231-ZZA$run'",
          "SELECT COUNT(*) FROM stock_recipes WHERE menu_type = 'zza-" . strtolower($run) . "'"] as $sql) {
    $left += (int)$pdo->query($sql)->fetchColumn();
}
ok('no ZZA rows were left behind (stock items, counts, recipes)', $left === 0, (string)$left);
$settingAfter = (string)$pdo->query("SELECT COALESCE(MAX(setting_value), '') FROM site_settings WHERE setting_key = 'stock_measures_list'")->fetchColumn();
ok('the Drink measures list setting is unchanged', $settingAfter === $settingBefore, '');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
