<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Import - "Differences from the uploaded file" on the opening count (owner 2026-11-23, build #11).
 * Usage (project root): php scripts/tests/import_diff_report.php [--preview=<dir>]
 *
 * The scenarios run in scripts/tests/import_diff_report_child.php through page_harness.php: ONE outer transaction that can never commit,
 * rolled back at the end (made-up 'ZZX <run> ...' items and prices; no real stock or price data). The child commits an import, checks the
 * frozen report row by row, renders the opening count as a manager who can approve it and checks the downloads.
 * A second harness run renders an OLD opening count (no import link): nothing extra, no crash.
 * --preview=<dir>: keep the rendered count page and the result reply there (for a headless screenshot); nothing is kept in the database.
 * The only lasting change is the additive column stock_counts.import_job_id (what the first real import adds anyway).
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
require_once $root . '/includes/import/diff-report.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$preview = '';
foreach ($argv as $a) { if (strpos($a, '--preview=') === 0) { $preview = rtrim(substr($a, 10), '\\/'); } }

// DDL outside the harness (it skips DDL): the same additive tables / columns a first real use creates
ensureImportSchema($pdo);
rh_ssi_ensure_table($pdo);
ok('setup: stock_counts.import_job_id is there (added the way is_opening was)', import_diff_link_ready($pdo, true));

$binBefore = [];
foreach ((array)@scandir($root . '/storage/imports') as $f) { if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f)) { $binBefore[$f] = true; } }
$run = strtoupper(bin2hex(random_bytes(3)));
$runHarness = function (string $page, array $cfg) use ($root) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' ' . $page . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $h = ['error' => 'no result'];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
    return [$h, (string)$out];
};
[$h, $out] = $runHarness('scripts/tests/import_diff_report_child.php', ['post' => ['run' => $run, 'preview' => $preview]]);
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
}
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr($out, -800));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr($out, -1500));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}

// ---- an OLD opening count (filed before this change: no import link): the page shows nothing extra and does not break ----
$ref = 'ZZX' . $run;
[$h2, $out2] = $runHarness('admin/stock-count.php', [
    'method' => 'GET',
    'setup' => ["INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, notes, is_opening) VALUES ('" . $ref . "', CURDATE(), 'full', 'submitted', "
        . "(SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1), 'Stock sheet import: opening balances from \"old.xlsx\" (Import #1).', 1)"],
    'vars' => ['cid' => "SELECT id FROM stock_counts WHERE reference = '" . $ref . "'"],
    'get' => ['id' => '{cid}'],
    'grep' => ['Back to all counts', 'Opening stock', 'Differences from the uploaded file', 'Fatal error', 'Warning:'],
]);
$f2 = (array)($h2['found'] ?? []);
ok('old opening count (no import link): renders, no "Differences" fold, no PHP error', !empty($h2['rolled_back']) && empty($h2['leak']) && !empty($f2['Back to all counts']) && !empty($f2['Opening stock'])
    && empty($f2['Differences from the uploaded file']) && empty($f2['Fatal error']) && empty($f2['Warning:']), json_encode($f2) . substr($out2, -400));

$like = 'ZZX ' . $run . ' %';
$left = 0;
foreach (['stock_ingredients' => 'name', 'drink_menu' => 'item_name', 'food_menu' => 'item_name'] as $t => $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $c LIKE ?");
    $st->execute([$like]);
    $left += (int)$st->fetchColumn();
}
$st = $pdo->prepare('SELECT COUNT(*) FROM stock_counts WHERE reference = ?');
$st->execute([$ref]);
$left += (int)$st->fetchColumn();
ok('no ZZX rows were left behind (stock, menus, counts)', $left === 0, (string)$left);
$stray = 0;
$dir = $root . '/storage/imports';
$referenced = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
foreach ((array)@scandir($dir) as $f) {
    if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f) && !isset($binBefore[$f]) && !isset($referenced[$f])) { $stray++; @unlink($dir . '/' . $f); }
}
ok('no staged files from this run remain on disk', $stray === 0, (string)$stray);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
