<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Put stock items on the drinks menu (admin/stock-to-menu.php + admin/includes/stock-to-menu-lib.php, owner 2026-11-22).
 * Usage (project root): php scripts/tests/stock_to_menu_test.php
 *
 * The library scenarios run in scripts/tests/stock_to_menu_child.php through page_harness.php (ONE outer transaction that can never
 * commit, rolled back at the end); the page itself is run through the harness too (screen with ?ids=, preview JSON, a POST without
 * the security token). Every row is named 'ZZM <run> ...' (made-up drinks and prices); none may be left behind.
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
$g = harness('admin/stock-to-menu.php', ['method' => 'GET', 'setup' => $seed, 'vars' => $vars, 'get' => ['ids' => '{gin}'],
    'grep' => ['ZZM ' . $run . ' Page Gin', 'Put on the drinks menu', 'id="stm-table"', 'Sold as', 'Cost of one', 'class="help"', 'checked']]);
ok('page: the screen opened from Stock Items shows the ticked item with its columns and ? helpers (rolled back)', $clean($g) && !in_array(false, (array)($g['found'] ?? [false]), true), json_encode($g['found'] ?? $g));
$p = harness('admin/stock-to-menu.php', ['setup' => $seed, 'vars' => $vars, 'ajax' => true,
    'post' => ['action' => 'preview', 'rows' => '[{"ing_id":{gin},"sold":"m:shot","price":"1500","tick":1}]']]);
$pr = $p['json']['rows'][0] ?? null;
ok('page: preview JSON works the row out (shot of a 750 ml bottle costs 500, profit 1000, New on the menu)', $clean($p) && !empty($p['json']['ok']) && $pr && abs((float)$pr['cost'] - 500) < 0.005
    && abs((float)$pr['profit'] - 1000) < 0.001 && $pr['status'] === 'new' && $pr['ready'] === true, json_encode($p['json'] ?? $p));
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
