<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Import lock gaps (CONCURRENCY_PLAN task 4, 2026-11-25): the main answer, "Use this" on a column suggestion, one drink's count unit and the
 * "which fields may change" ticks save through import_cont_save against the answers they were checked on.
 * Usage (project root): php scripts/tests/import_lock_gaps.php
 * The scenarios run in scripts/tests/import_lock_gaps_child.php through page_harness.php (ONE outer transaction, rolled back). Liwonde only.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/includes/import/review.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
// source check: the four writes go through import_cont_save, and the column learning comes after the save
$src = (string)file_get_contents($root . '/includes/import/review.php');
foreach (['import_job_answer', 'import_job_column_use', 'import_job_count_row', 'import_job_set_update_fields'] as $fn) {
    $p = strpos($src, 'function ' . $fn . '(');
    $e = $p === false ? false : strpos($src, "\n    function ", $p + 10);
    $body = $p === false ? '' : substr($src, $p, ($e === false ? strlen($src) : $e) - $p);
    $cs = strpos($body, 'import_cont_save(');
    ok("source: $fn saves through import_cont_save with the answers hash and never writes answers_json itself",
        $cs !== false && strpos($body, '$answersHash') !== false && stripos($body, 'UPDATE import_jobs') === false);
    if ($fn === 'import_job_answer' || $fn === 'import_job_column_use') {
        $lc = strpos($body, 'import_job_learn_column(');
        ok("source: $fn learns the heading only after the save", $lc !== false && $cs !== false && $lc > $cs);
    }
    // the per-answer stale check runs before the save AND again inside it (under the row lock)
    $inner = $cs === false ? '' : substr($body, $cs);
    ok("source: $fn checks the answer's key hash before the save and again inside it",
        substr_count($body, 'import_key_hash_ok($keyHash') === 2 && strpos($inner, 'import_key_hash_ok($keyHash') !== false);
}
// the screen sends the key hash on all four clicks, and the endpoint passes it on
$page = (string)file_get_contents($root . '/admin/data-import.php');
ok('source: the four endpoints pass the posted key hash (di_key_hash) on', substr_count($page, ', $uid, null, di_key_hash())') === 4);
$js = (string)file_get_contents($root . '/admin/js/data-import.js');
ok('source: the screen sends key_hash with answer, col_use, count_row and fields, and keeps the hash from the reply',
    substr_count($js, 'key_hash') >= 8 && substr_count($js, 'khash = r.key_hash') + substr_count($js, 'khash = a.key_hash') === 4);

$storeDir = $root . '/storage/imports';
$binBefore = [];
foreach ((array)@scandir($storeDir) as $f) { if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f)) { $binBefore[$f] = true; } }
$run = strtoupper(bin2hex(random_bytes(3)));
$cfg = base64_encode(json_encode(['post' => ['run' => $run]]));
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_lock_gaps_child.php ' . escapeshellarg($cfg) . ' 2>&1');
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
}
$h = ['error' => 'no result'];
if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr($out, -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr($out, -800));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}
ok('no ZZT rows were left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-lg%-$run.csv'")->fetchColumn() === 0);
$stray = 0;
$referenced = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
foreach ((array)@scandir($storeDir) as $f) {
    if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f) && !isset($binBefore[$f]) && !isset($referenced[$f])) { $stray++; @unlink($storeDir . '/' . $f); }
}
ok('no staged files from this run remain on disk (any leak was deleted and is reported here)', $stray === 0, (string)$stray);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
