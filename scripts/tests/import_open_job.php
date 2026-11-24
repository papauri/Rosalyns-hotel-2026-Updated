<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * One open import per file (CONCURRENCY_PLAN task 3, 2026-11-25).
 * Usage (project root): php scripts/tests/import_open_job.php
 *
 * Part 1 runs scripts/tests/import_open_job_child.php through page_harness.php (ONE outer transaction, rolled back): a second upload of
 * a file another open job holds is refused with who/when + the job id; expired/cancelled jobs do not count; only an administrator's
 * "import again" tick overrides; of two open jobs for one file the first commit wins and the second writes no stock; an import from
 * before the job existed keeps the old rule.
 * Part 2 is the real race: two PHP processes upload the same file at the same moment on COMMITTING connections (3 rounds). Exactly one
 * job may exist per round. Those rows are named 'zzt-openrace-<run>-<round>.csv' and deleted by id (leftovers of a crashed run first).
 * Liwonde (the test hotel) only.
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
$reconnect = function () use (&$pdo) {
    try { $pdo->query('SELECT 1'); } catch (Throwable $e) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
    }
};
$storeDir = $root . '/storage/imports';
$binBefore = [];
foreach ((array)@scandir($storeDir) as $f) { if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f)) { $binBefore[$f] = true; } }

// ---------- part 1: rolled-back scenarios ----------
$run = strtoupper(bin2hex(random_bytes(3)));
$cfg = base64_encode(json_encode(['post' => ['run' => $run]]));
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_open_job_child.php ' . escapeshellarg($cfg) . ' 2>&1');
$reconnect();
$h = ['error' => 'no result'];
if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr($out, -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr($out, -800));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}
ok('part 1: no ZZT rows were left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-oj%-$run.csv'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE full_name LIKE 'ZZT $run %'")->fetchColumn() === 0);

// ---------- part 2: the race (committing connections, cleaned up by id) ----------
$raceIds = [];
$cleanup = function () use (&$raceIds, &$pdo, $reconnect) {
    if (!$raceIds) { return; }
    $reconnect();
    $sel = $pdo->prepare('SELECT stored_path FROM import_jobs WHERE id = ?');
    $del = $pdo->prepare("DELETE FROM import_jobs WHERE id = ? AND original_name LIKE 'zzt-openrace-%'");
    foreach (array_keys($raceIds) as $id) {
        $sel->execute([$id]);
        $sp = $sel->fetchColumn();
        if ($sp) { import_delete_stored((string)$sp); }
        $del->execute([$id]);
    }
    $raceIds = [];
};
register_shutdown_function($cleanup);
// leftovers of an earlier, crashed run (only rows this test names)
foreach ($pdo->query("SELECT id FROM import_jobs WHERE original_name LIKE 'zzt-openrace-%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { $raceIds[(int)$id] = true; }
$left = count($raceIds);
$cleanup();
if ($left) { echo "  (removed $left leftover race row(s) from an earlier run)\n"; }

$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
ok('race: an active administrator exists to upload as', $admin > 0);
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_openrace_' . getmypid();
@mkdir($tmpDir);
$child = $root . '/scripts/tests/import_open_job_child.php';
for ($round = 1; $round <= 3 && $admin > 0; $round++) {
    $name = 'zzt-openrace-' . $run . '-' . $round . '.csv';
    $bytes = "Item Name,Balance,Unit,Unit Price\nZZT $run race $round " . bin2hex(random_bytes(4)) . ",3,kg,100\n";
    $paths = [];
    foreach (['A', 'B'] as $t) { $p = $tmpDir . DIRECTORY_SEPARATOR . $round . $t . '.csv'; file_put_contents($p, $bytes); $paths[$t] = $p; }
    $startAt = microtime(true) + 2.5;
    $procs = [];
    foreach (['A', 'B'] as $t) {
        $c = base64_encode(json_encode(['tag' => $t, 'path' => $paths[$t], 'name' => $name, 'uid' => $admin, 'start_at' => $startAt]));
        $pipes = [];
        $pr = proc_open([PHP_BINARY, $child, $c], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $procs[$t] = [$pr, $pipes];
    }
    $res = [];
    foreach ($procs as $t => [$pr, $pipes]) {
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($pr);
        $r = preg_match('/@@RESULT@@(.*)$/m', $o, $mm) ? json_decode($mm[1], true) : null;
        $res[$t] = is_array($r) ? $r : ['ok' => false, 'job_id' => 0, 'error' => 'no result: ' . substr($o, -300), 'duplicate' => null];
        if (!empty($res[$t]['job_id'])) { $raceIds[(int)$res[$t]['job_id']] = true; }
    }
    $reconnect();
    $st = $pdo->prepare('SELECT id FROM import_jobs WHERE original_name = ?');
    $st->execute([$name]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) { $raceIds[$id] = true; }
    $okN = (int)!empty($res['A']['ok']) + (int)!empty($res['B']['ok']);
    $loser = !empty($res['A']['ok']) ? ($res['B'] ?? []) : ($res['A'] ?? []);
    $winner = !empty($res['A']['ok']) ? ($res['A'] ?? []) : ($res['B'] ?? []);
    ok("race round $round: two simultaneous uploads of one file make exactly one job",
        $okN === 1 && count($ids) === 1 && $ids[0] === (int)($winner['job_id'] ?? -1), json_encode([$res, $ids]));
    ok("race round $round: the other upload is refused as \"already being imported\" and points at that job",
        !empty($loser['duplicate']['open']) && (int)($loser['duplicate']['job_id'] ?? 0) === (int)($winner['job_id'] ?? -1)
        && strpos((string)($loser['error'] ?? ''), 'This file is already being imported') === 0, json_encode($loser));
    foreach ($paths as $p) { @unlink($p); }
    $cleanup();
}
@rmdir($tmpDir);
$reconnect();
ok('race: every race row was deleted by id', (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-openrace-$run-%'")->fetchColumn() === 0);

// staged files live outside the database: sweep (and report) any new one no job row points at
$stray = 0;
$referenced = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
foreach ((array)@scandir($storeDir) as $f) {
    if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f) && !isset($binBefore[$f]) && !isset($referenced[$f])) { $stray++; @unlink($storeDir . '/' . $f); }
}
ok('no staged files from this run remain on disk (any leak was deleted and is reported here)', $stray === 0, (string)$stray);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
