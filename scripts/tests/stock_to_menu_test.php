<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Put stock items on the drinks menu (admin/stock-to-menu.php + admin/includes/stock-to-menu-lib.php, owner 2026-11-22).
 * Usage (project root): php scripts/tests/stock_to_menu_test.php
 *
 * The library scenarios run in scripts/tests/stock_to_menu_child.php through page_harness.php (ONE outer transaction that can never
 * commit, rolled back at the end); the page itself is run through the harness too (screen opened from Stock Items by POST with the ids
 * in one field, 1200 of them, over the cap, a Menu-only user without costs, preview JSON, a POST without the security token). Every row is named 'ZZM <run> ...' (made-up drinks and prices); none may be left behind.
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

// ---- library scenarios
$h = harness('scripts/tests/stock_to_menu_child.php', ['post' => ['run' => $run]]);
ok('harness: the scenarios ran and were rolled back, nothing leaked', $clean($h), substr((string)($h['raw'] ?? ''), -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)($h['raw'] ?? ''), -1200));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}

// ---- the page: a seeded item (rolled back) shown from Stock Items, then the preview JSON
$seed = ["INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, volume_ml) VALUES ('ZZM $run Page Gin', 'ZZM', 'bottle', 0, 15000, 750)"];
$vars = ['gin' => "SELECT id FROM stock_ingredients WHERE name = 'ZZM $run Page Gin' ORDER BY id DESC LIMIT 1"];
$g = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'post' => ['action' => 'open', 'ids' => '{gin}'],
    'grep' => ['ZZM ' . $run . ' Page Gin', 'Put on the drinks menu', 'id="stm-table"', 'Sold as', 'Cost of one', 'class="help"', 'checked', '"show_cost":true']]);
ok('page: the screen opened from Stock Items (POST, ids in one field) shows the ticked item with its columns and ? helpers (rolled back)', $clean($g) && !in_array(false, (array)($g['found'] ?? [false]), true), json_encode($g['found'] ?? $g));
$gx = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'post' => ['action' => 'open', 'ids' => '{gin}', 'csrf_token' => 'wrong'], 'grep' => ['Security token invalid', 'Page Gin']]);
ok('page: opening from Stock Items without the right security token shows the message and no rows', $clean($gx) && !empty($gx['found']['Security token invalid']) && empty($gx['found']['Page Gin']), json_encode($gx['found'] ?? $gx));

// ---- 1200 ticked items in one field all reach the screen (rolled back); more than the cap is said, never cut
// 1..9999 made in SQL (the harness config travels on the command line, so it must stay short)
$digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$seq = "(SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + 1 AS n FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits e)";
$dump = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stm_bulk_' . $run . '.html';
$bk = harness('admin/stock-to-menu.php', ['setup' => ['SET SESSION group_concat_max_len = 1000000',
    "INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) SELECT CONCAT('ZZM $run Bulk ', LPAD(t.n, 4, '0')), 'ZZM', 'can', 0, 100 FROM $seq t WHERE t.n <= 1200"],
    'vars' => ['ids' => "SELECT GROUP_CONCAT(id ORDER BY id) FROM stock_ingredients WHERE name LIKE 'ZZM $run Bulk %'"],
    'post' => ['action' => 'open', 'ids' => '{ids}'], 'dump_html' => $dump]);
$html = (string)@file_get_contents($dump);
@unlink($dump);
$rowsShown = preg_match_all('/<tr class="stm-row"/', $html);
$ticked = preg_match_all('/class="stm-tick-box"[^>]*checked/', $html);
ok('page: 1200 stock items sent in one POST field all reach the screen, all ticked (rolled back)', $clean($bk) && $rowsShown === 1200 && $ticked === 1200, $rowsShown . ' rows, ' . $ticked . ' ticked');
$ov = harness('admin/stock-to-menu.php', ['setup' => ['SET SESSION group_concat_max_len = 1000000'], 'vars' => ['ids' => "SELECT GROUP_CONCAT(t.n ORDER BY t.n) FROM $seq t WHERE t.n <= 5001"],
    'post' => ['action' => 'open', 'ids' => '{ids}'], 'grep' => ['You ticked 5,001 stock items. At most 5,000', 'id="stm-table"']]);
ok('page: more than 5000 ticked items is refused with a plain message, not cut', $clean($ov) && !empty($ov['found']['You ticked 5,001 stock items. At most 5,000']) && empty($ov['found']['id="stm-table"']), json_encode($ov['found'] ?? $ov));

// ---- a Menu-only user (no Stock permission): no cost, profit or cost warning in the page or the preview JSON; Menu + Stock (admin) sees them
$staff = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY id LIMIT 1")->fetchColumn();
if ($staff === '') {
    ok('page: a non-admin user exists for the Menu-only check', false, 'none');
} else {
    $grant = "INSERT INTO user_permissions (user_id, permission_key, is_granted) SELECT id, '%s', %d FROM admin_users WHERE username = " . $pdo->quote($staff) . " ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)";
    $menuOnlySetup = array_merge($seed, [sprintf($grant, 'menu', 1), sprintf($grant, 'stock_management', 0), sprintf($grant, 'view_only', 0)]);
    $mo = harness('admin/stock-to-menu.php', ['as_username' => $staff, 'setup' => $menuOnlySetup, 'vars' => $vars, 'post' => ['action' => 'open', 'ids' => '{gin}'],
        'grep' => ['ZZM ' . $run . ' Page Gin', 'Costs show for people with the Stock permission', '"show_cost":false', '"cost":null', '"profit":null', '15000', '"cost_note":"From', 'profit_ex_vat', 'stm-exvat']]);
    $f = (array)($mo['found'] ?? []);
    ok('page: a Menu-only user sees the item with "—" and "Costs show for people with the Stock permission"; no cost in the page data, no Show profit without VAT box or figure', $clean($mo) && !empty($f['ZZM ' . $run . ' Page Gin'])
        && !empty($f['Costs show for people with the Stock permission']) && !empty($f['"show_cost":false']) && !empty($f['"cost":null']) && !empty($f['"profit":null'])
        && empty($f['15000']) && empty($f['"cost_note":"From']) && empty($f['profit_ex_vat']) && empty($f['stm-exvat']), json_encode($f ?: $mo));
    $mp = harness('admin/stock-to-menu.php', ['as_username' => $staff, 'setup' => $menuOnlySetup, 'vars' => $vars, 'ajax' => true,
        'post' => ['action' => 'preview', 'rows' => '[{"ing_id":{gin},"sold":"m:shot","price":"100","tick":1}]']]);
    $mr = $mp['json']['rows'][0] ?? null;
    ok('page: the Menu-only preview JSON has no cost, profit or cost warning (shot costs 500 > price 100) and the row is ready', $clean($mp) && !empty($mp['json']['ok']) && $mr
        && $mr['cost'] === null && $mr['profit'] === null && !array_key_exists('profit_ex_vat', $mr) && $mr['below'] === false && $mr['warn'] === '' && $mp['json']['show_cost'] === false && $mr['ready'] === true, json_encode($mp['json'] ?? $mp));
    $ms = harness('admin/stock-to-menu.php', ['as_username' => $staff, 'setup' => $menuOnlySetup, 'vars' => $vars, 'ajax' => true,
        'post' => ['action' => 'save', 'rows' => '[{"ing_id":{gin},"name":"ZZM ' . $run . ' Menu Only Gin","sold":"unit","price":"12,500.50","category":"Bar","tick":1}]'],
        'verify' => ["SELECT price FROM drink_menu WHERE item_name = 'ZZM $run Menu Only Gin'"]]);
    ok('page: a Menu-only user can still save (12,500.50 -> 12500.50, rolled back)', $clean($ms) && !empty($ms['json']['ok']) && (string)($ms['verify'][0][0]['price'] ?? '') === '12500.50', json_encode([$ms['json'] ?? $ms, $ms['verify'] ?? null]));
}
$p = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'ajax' => true,
    'post' => ['action' => 'preview', 'rows' => '[{"ing_id":{gin},"sold":"m:shot","price":"1500","tick":1}]']]);
$pc = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'ajax' => true,
    'post' => ['action' => 'preview', 'rows' => '[{"ing_id":{gin},"sold":"unit","price":"1,50","tick":1}]']]);
$pcr = $pc['json']['rows'][0] ?? null;
ok('page: preview of "1,50" is refused with the comma message, never 150', $clean($pc) && $pcr && $pcr['price'] === null && $pcr['ready'] === false
    && $pcr['why_not'] === 'Use a dot for decimals, e.g. 1.50, and commas only between thousands, e.g. 1,500.', json_encode($pcr));
$pr = $p['json']['rows'][0] ?? null;
ok('page: preview JSON for Menu + Stock (admin) works the row out (shot of a 750 ml bottle costs 500, profit 1000, New on the menu; with VAT on, its profit without VAT too)', $clean($p) && !empty($p['json']['ok']) && $pr && abs((float)$pr['cost'] - 500) < 0.005
    && abs((float)$pr['profit'] - 1000) < 0.001 && $pr['status'] === 'new' && $pr['ready'] === true
    && (vat_mode() === 'off' ? !array_key_exists('profit_ex_vat', $pr) : abs((float)($pr['profit_ex_vat'] ?? -1) - (vat_mode() === 'inclusive' ? round(round(1500 / (1 + (float)getSetting('vat_rate', 0) / 100), 2) - 500, 2) : 1000.0)) < 0.001), json_encode($p['json'] ?? $p));
$x = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'ajax' => true,
    'post' => ['action' => 'save', 'csrf_token' => 'wrong', 'rows' => '[{"ing_id":{gin},"price":"1500","tick":1}]'],
    'verify' => ["SELECT COUNT(*) AS n FROM drink_menu WHERE item_name LIKE 'ZZM $run %'"]]);
ok('page: a save without the right security token is refused and writes nothing', $clean($x) && empty($x['json']['ok']) && stripos((string)($x['json']['error'] ?? ''), 'Security token') !== false
    && (int)($x['verify'][0][0]['n'] ?? -1) === 0, json_encode([$x['json'] ?? null, $x['verify'] ?? null]));

// ---- nothing left behind
$like = 'ZZM ' . $run . ' %';
$left = 0;
foreach (['stock_ingredients' => 'name', 'drink_menu' => 'item_name', 'menu_items' => 'item_name', 'admin_users' => 'full_name'] as $t => $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $c LIKE ?");
    $st->execute([$like]);
    $left += (int)$st->fetchColumn();
}
ok('no ZZM rows were left behind (stock, drinks menu, till, users)', $left === 0, (string)$left);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
