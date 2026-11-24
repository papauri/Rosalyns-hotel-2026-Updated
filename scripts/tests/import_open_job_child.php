<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Child of scripts/tests/import_open_job.php ("one open import per file", 2026-11-25). Two modes:
 *  - run by page_harness.php (ONE outer transaction, rolled back at the end): the single-process scenarios. Prints {"checks":[...]}.
 *  - run directly (php import_open_job_child.php <base64 cfg>): ONE upload through import_store_upload() on a normal COMMITTING
 *    connection, started at cfg.start_at, for the simultaneous-upload race. Prints "@@RESULT@@{...}". The parent deletes the job
 *    row (by id) and its staged file.
 * Every row it makes is named 'ZZT <run> ...' / 'zzt-oj...-<run>.csv'.
 */
$inHarness = isset($GLOBALS['__h_cfg']);

if (!$inHarness) {
    // ---- race mode ----
    $root = dirname(__DIR__, 2);
    chdir($root);
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    require_once $root . '/config/database.php';
    if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
        exit("Refusing to run on Rosalyn's (live).\n");
    }
    require_once $root . '/includes/import/review.php';
    $cfg = json_decode((string)base64_decode((string)($argv[1] ?? '')), true);
    if (!is_array($cfg)) { exit("bad cfg\n"); }
    $wait = (float)$cfg['start_at'] - microtime(true);
    if ($wait > 0) { usleep((int)($wait * 1000000)); }
    try {
        $r = import_store_upload($pdo, (string)$cfg['path'], (string)$cfg['name'], (int)$cfg['uid'], ['from_upload' => false]);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => 'EXCEPTION ' . $e->getMessage(), 'job_id' => 0, 'duplicate' => null];
    }
    echo "\n@@RESULT@@" . json_encode(['tag' => (string)$cfg['tag'], 'ok' => !empty($r['ok']), 'job_id' => (int)($r['job_id'] ?? 0), 'error' => (string)($r['error'] ?? ''), 'duplicate' => $r['duplicate'] ?? null]) . "\n";
    exit;
}

// ---- harness mode ----
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) {
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$made = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_open_job_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/includes/import/commit-stock.php';
    require_once $root . '/scripts/tests/import_count_time_helper.php';
    $n = function (string $s) use ($run) { return 'ZZT ' . $run . ' ' . $s; };
    $put = function (string $bytes, string $name) use ($tmp, &$tmpFiles) {
        $p = $tmp . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . $name;
        file_put_contents($p, $bytes);
        $tmpFiles[] = $p;
        return $p;
    };
    $mkUser = function (string $role, string $tag) use ($pdo, $n) {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, ?, 1)")
            ->execute([substr($n($tag), 0, 90), strtolower(preg_replace('/\W+/', '', $n($tag))) . '@example.invalid', $n($tag), $role]);
        return (int)$pdo->lastInsertId();
    };
    $upload = function (string $path, string $name, int $uid, array $o = []) use ($pdo, &$made) {
        $r = import_job_create($pdo, $path, $name, $uid, $o + ['from_upload' => false]);
        if (!empty($r['ok'])) {
            $j = import_job_get($pdo, $r['job_id']);
            $made[] = $j['stored_path'];
        }
        return $r;
    };
    $csv = function (array $rows) {
        $fh = fopen('php://memory', 'w+');
        fputcsv($fh, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
        foreach ($rows as $r) { fputcsv($fh, $r); }
        rewind($fh);
        return stream_get_contents($fh);
    };
    $answerAll = function (int $jobId, int $uid) use ($pdo) {
        for ($i = 0; $i < 40; $i++) {
            $s = import_job_review_summary($pdo, $jobId);
            $open = null;
            foreach ($s['questions'] as $q) {
                if ($q['blocking'] && $q['answer'] === null && $q['options']) { $open = $q; break; }
            }
            if (!$open) { return $s; }
            $keys = array_column($open['options'], 'key');
            $pick = $keys[0];
            foreach (['per-unit', 'two-batches', 'keep'] as $pref) { if (in_array($pref, $keys, true)) { $pick = $pref; } }
            if ($open['type'] === 'no-unit') { $pick = in_array('unit:each', $keys, true) ? 'unit:each' : $keys[0]; }
            if (in_array('skip', $keys, true) && ($open['bad'] || $open['type'] === 'bad-value')) { $pick = 'skip'; }
            $r = import_job_answer($pdo, $jobId, $open['id'], $pick, $uid);
            if (!$r['ok']) { return ['stuck' => $open['type'], 'err' => $r['error']]; }
        }
        return ['stuck' => 'loop'];
    };
    $jobRow = function (int $id) use ($pdo) {
        $st = $pdo->prepare('SELECT id, status, created_at, committed_at, sha256 FROM import_jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $stockSnap = function () use ($pdo, $run) {
        return [
            'ing' => (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn(),
            'counts' => (int)$pdo->query('SELECT COUNT(*) FROM stock_counts')->fetchColumn(),
            'lines' => (int)$pdo->query('SELECT COUNT(*) FROM stock_count_lines')->fetchColumn(),
            'qty' => (string)$pdo->query("SELECT COALESCE(SUM(current_quantity), 0) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn(),
        ];
    };

    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $adminName = (string)$pdo->query('SELECT COALESCE(NULLIF(full_name, \'\'), username) FROM admin_users WHERE id = ' . $admin)->fetchColumn();
    $mgrA = $mkUser('manager', 'manager A');
    $mgrB = $mkUser('manager', 'manager B');
    $ck('setup: an administrator and two made-up managers', $admin > 0 && $mgrA > 0 && $mgrB > 0);

    // ---- 1. an open import of the same file refuses a second upload, naming who started it and when, with the job id ----
    $bytesA = $csv([[$n('OJ Alpha'), '', '3', 'kg', '100', '', '']]);
    $r1 = $upload($put($bytesA, 'a1.csv'), 'zzt-oj1-' . $run . '.csv', $mgrA);
    $j1 = $jobRow((int)($r1['job_id'] ?? 0));
    $r2 = $upload($put($bytesA, 'a2.csv'), 'zzt-oj1b-' . $run . '.csv', $mgrB);
    $d2 = $r2['duplicate'] ?? [];
    $ck('open duplicate: the first upload is accepted', !empty($r1['ok']) && ($j1['status'] ?? '') === 'uploaded', json_encode($r1));
    $ck('open duplicate: a second upload of the same file is refused with "already being imported", the starter\'s name and the start time',
        empty($r2['ok']) && strpos((string)$r2['error'], 'This file is already being imported: ' . $n('manager A') . ' started it on ' . substr((string)($j1['created_at'] ?? 'x'), 0, 16) . '. Open it to carry on.') === 0,
        (string)($r2['error'] ?? ''));
    $ck('open duplicate: the reply carries the open job (job_id, open, by, started_at, created_by)',
        is_array($d2) && !empty($d2['open']) && (int)($d2['job_id'] ?? 0) === (int)$r1['job_id'] && ($d2['by'] ?? '') === $n('manager A')
        && ($d2['started_at'] ?? '') === ($j1['created_at'] ?? '-') && (int)($d2['created_by'] ?? 0) === $mgrA, json_encode($d2));
    $cntA = $pdo->prepare('SELECT COUNT(*) FROM import_jobs WHERE sha256 = ?');
    $cntA->execute([hash('sha256', $bytesA)]);
    $ck('open duplicate: no second job was made', (int)$cntA->fetchColumn() === 1);

    // ---- 2. an expired open job (past expires_at) does not count; nor does a cancelled one ----
    $pdo->prepare('UPDATE import_jobs SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?')->execute([(int)$r1['job_id']]);
    $r3 = $upload($put($bytesA, 'a3.csv'), 'zzt-oj1c-' . $run . '.csv', $mgrB);
    $ck('expired: an open job past its expiry date is not counted, the upload is accepted', !empty($r3['ok']), (string)($r3['error'] ?? ''));
    $cx3 = !empty($r3['ok']) ? import_job_cancel($pdo, (int)$r3['job_id'], $mgrB) : ['ok' => false];
    $r3b = $upload($put($bytesA, 'a3b.csv'), 'zzt-oj1d-' . $run . '.csv', $mgrB);
    $ck('cancelled: a cancelled job is not counted either', !empty($cx3['ok']) && !empty($r3b['ok']), json_encode([$cx3, $r3b['error'] ?? '']));

    // ---- 3. the administrator's "import again" tick overrides; a manager's tick does not ----
    $bytesB = $csv([[$n('OJ Bravo'), '', '4', 'kg', '200', '', '']]);
    $r4 = $upload($put($bytesB, 'b1.csv'), 'zzt-oj2-' . $run . '.csv', $mgrA);
    $r5 = $upload($put($bytesB, 'b2.csv'), 'zzt-oj2b-' . $run . '.csv', $mgrB, ['allow_reimport' => true]);
    $r6 = $upload($put($bytesB, 'b3.csv'), 'zzt-oj2c-' . $run . '.csv', $admin, ['allow_reimport' => true]);
    $ck('override: a manager ticking "import again" is still refused', !empty($r4['ok']) && empty($r5['ok']) && !empty($r5['duplicate']['open']), (string)($r5['error'] ?? ''));
    $ck('override: an administrator ticking "import again" starts a second job for the same file', !empty($r6['ok']) && (int)$r6['job_id'] !== (int)$r4['job_id'], (string)($r6['error'] ?? ''));

    // ---- 4. two open jobs for one file: the first to commit wins, the second is refused at commit and writes no stock ----
    $j4 = (int)($r4['job_id'] ?? 0);
    $j6 = (int)($r6['job_id'] ?? 0);
    import_job_analyse($pdo, $j4);
    import_job_analyse($pdo, $j6);
    $s4 = $answerAll($j4, $admin);
    $s6 = $answerAll($j6, $admin);
    $ck('two jobs: both can be reviewed and confirmed', !empty($s4['can_confirm']) && !empty($s6['can_confirm']), json_encode([$s4['stuck'] ?? null, $s6['stuck'] ?? null]));
    $c4 = import_test_commit($pdo, $j4, $admin);
    $ck('two jobs: the first commit imports', !empty($c4['ok']) && empty($c4['already']), (string)($c4['error'] ?? ''));
    $snap = $stockSnap();
    $c6 = import_test_commit($pdo, $j6, $admin);
    $jc4 = $jobRow($j4);
    $ck('two jobs: the second commit is refused, naming who imported it and when, "while this import was open"',
        empty($c6['ok']) && strpos((string)$c6['error'], 'This file was already imported by ' . $adminName . ' on ' . substr((string)($jc4['committed_at'] ?? 'x'), 0, 16) . ' (Import #' . $j4 . '), while this import was open.') === 0,
        (string)($c6['error'] ?? ''));
    $ck('two jobs: the refused commit wrote no stock (no items, counts, count lines or quantities changed) and the job is not committed',
        $stockSnap() === $snap && ($jobRow($j6)['status'] ?? '') !== 'committed', json_encode([$snap, $stockSnap(), $jobRow($j6)['status'] ?? null]));

    // ---- 5. a commit from BEFORE this job existed: the upload rule and the admin re-import are unchanged ----
    $bytesC = $csv([[$n('OJ Charlie'), '', '5', 'kg', '300', '', '']]);
    $r7 = $upload($put($bytesC, 'c1.csv'), 'zzt-oj3-' . $run . '.csv', $admin);
    $j7 = (int)($r7['job_id'] ?? 0);
    import_job_analyse($pdo, $j7);
    $answerAll($j7, $admin);
    $c7 = import_test_commit($pdo, $j7, $admin);
    // the earlier import happened an hour ago (a job made later cannot have been open while it committed)
    $pdo->prepare('UPDATE import_jobs SET created_at = NOW() - INTERVAL 2 HOUR, committed_at = NOW() - INTERVAL 1 HOUR WHERE id = ?')->execute([$j7]);
    $r8 = $upload($put($bytesC, 'c2.csv'), 'zzt-oj3b-' . $run . '.csv', $admin);
    $ck('committed before: the same file again is still refused with "already imported on <date> by <name>" (no open job)',
        !empty($c7['ok']) && empty($r8['ok']) && stripos((string)$r8['error'], 'already imported on') !== false && empty($r8['duplicate']['open']), json_encode([$c7['error'] ?? null, $r8['error'] ?? null]));
    $r9 = $upload($put($bytesC, 'c3.csv'), 'zzt-oj3c-' . $run . '.csv', $admin, ['allow_reimport' => true]);
    $j9 = (int)($r9['job_id'] ?? 0);
    if ($j9) { import_job_analyse($pdo, $j9); $answerAll($j9, $admin); }
    $c9 = $j9 ? import_test_commit($pdo, $j9, $admin) : ['ok' => false, 'error' => 'no job'];
    $ck('committed before: the administrator\'s re-import is accepted at upload and is NOT refused at commit by the one-open-job rule',
        !empty($r9['ok']) && strpos((string)($c9['error'] ?? ''), 'while this import was open') === false, json_encode([$r9['error'] ?? null, $c9['error'] ?? null]));
    $ck('committed before: that re-import commits', !empty($c9['ok']), (string)($c9['error'] ?? ''));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
