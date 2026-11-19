<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Import - "Drinks for the menu" (drinks profit projection P1, owner 2026-11-19).
 * Usage (project root): php scripts/tests/import_drink_prices.php
 *
 * The scenarios run in scripts/tests/import_drink_prices_child.php through page_harness.php: ONE outer transaction that can never
 * commit, rolled back at the end. Every row is named 'ZZD <run> ...' (made-up drinks and prices, no real stock or price data).
 * Staged files (outside the database) are deleted by the child; any stray one is swept here.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/admin/includes/import-schema.php';
require_once $root . '/admin/includes/stock-sheet-import-lib.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// DDL outside the harness (it skips DDL): the same additive tables a first real use creates
ensureImportSchema($pdo);
rh_ssi_ensure_table($pdo);

$binBefore = [];
foreach ((array)@scandir($root . '/storage/imports') as $f) { if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f)) { $binBefore[$f] = true; } }
$run = strtoupper(bin2hex(random_bytes(3)));
$cfg = base64_encode(json_encode(['post' => ['run' => $run]]));
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_drink_prices_child.php ' . escapeshellarg($cfg) . ' 2>&1');
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
}
$h = ['error' => 'no result'];
if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr((string)$out, -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)$out, -1200));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}
$like = 'ZZD ' . $run . ' %';
$left = 0;
foreach (['stock_ingredients' => 'name', 'drink_menu' => 'item_name', 'menu_items' => 'item_name'] as $t => $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $c LIKE ?");
    $st->execute([$like]);
    $left += (int)$st->fetchColumn();
}
ok('no ZZD rows were left behind (stock, drinks menu, till)', $left === 0, (string)$left);
$stray = 0;
$dir = $root . '/storage/imports';
$referenced = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
foreach ((array)@scandir($dir) as $f) {
    if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f) && !isset($binBefore[$f]) && !isset($referenced[$f])) { $stray++; @unlink($dir . '/' . $f); }
}
ok('no staged files from this run remain on disk', $stray === 0, (string)$stray);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
