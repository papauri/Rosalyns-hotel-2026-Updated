<?php
/**
 * Step 8c Build C - stock Import faults I1-I5 + the owner's stock count file in several shapes.
 * Usage (Liwonde project root ONLY): php scripts/tests/import_stock_8c.php
 *
 * Runs ONLY against the Liwonde test database (sha1 guard below); anywhere else it prints SKIP and writes nothing.
 * Unlike import_stock_test.php (one rolled-back harness transaction) these cases need a second connection / second process
 * (races), so they COMMIT for real and then delete everything they made: ingredients named 'ZZT8C <run> ...', their import jobs,
 * staged files, opening counts (+ lines + staged batches), activity-log lines and learned aliases. The last checks prove nothing
 * is left behind.
 *
 * Owner file (owner 2026-11-15: "use this stock count just the import test count"): scripts/tests/fixtures/import/real/
 * stock-management-20261110-101650.csv - real hotel data, gitignored, never printed (counts only). Missing -> those cases SKIP.
 * Variants (xlsx, renamed + reordered headers, title row + blank lines) are written into the same ignored folder.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const ZZ_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde database name (name kept out of the public repo)
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/import-schema.php';
require_once $root . '/admin/includes/stock-sheet-import-lib.php';
require_once $root . '/includes/import/commit-stock.php';
require_once $root . '/scripts/tests/import_count_time_helper.php';   // "When was this counted?" for the commits (2026-11-25)
require_once $root . '/includes/import/export-xlsx.php';

if (sha1(DB_NAME) !== ZZ_DB_SHA1) {
    echo "SKIP: not the Liwonde test database - nothing run, nothing written.\n";
    exit(0);
}

/* ---- CHILD: commit one job in its own process (I1 race) ------------------------------------------------------------ */
if (($argv[1] ?? '') === '--child-commit') {
    $r = import_test_commit($pdo, (int)$argv[2], (int)$argv[3]);
    file_put_contents((string)$argv[4], json_encode($r));
    exit(0);
}

$pass = 0; $fail = 0; $red = [];
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail, $red;
    if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; $red[] = $name; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function zz_conn(): PDO {
    return new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

ensureImportSchema($pdo);
rh_ssi_ensure_table($pdo);
if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = 'is_opening'")->fetchColumn()) {
    $pdo->exec('ALTER TABLE stock_counts ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0');
}
$run = strtoupper(bin2hex(random_bytes(3)));
$P = 'ZZT8C ' . $run . ' ';
$n = function (string $s) use ($P) { return $P . $s; };
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$switchWas = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'data_import_enabled'")->fetchColumn();
if ($switchWas !== '1') {
    $pdo->exec("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'");
}
$aliasMax = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM import_aliases')->fetchColumn();
$ingMax = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_ingredients')->fetchColumn();
$fpSql = "SELECT COUNT(*) c, COALESCE(SUM(CRC32(CONCAT_WS('|', id, name, category, unit, cost_per_unit, par_level, reorder_point, IFNULL(pack_size, ''), IFNULL(notes, ''), is_archived))), 0) s FROM stock_ingredients WHERE id <= " . $ingMax;
$fpBefore = $pdo->query($fpSql)->fetch();
$jobIds = [];
$ownerCreatedFrom = null;          // ingredients the owner-file commit created (id > this AND a new name from the file) are removed at the end
$ownerNewKeys = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzt8c_' . getmypid();
@mkdir($tmp);

$cleanup = function () use (&$jobIds, &$ownerCreatedFrom, &$ownerNewKeys, $pdo, $P, $aliasMax, $switchWas, $tmp) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e) {}
    rh_ssi_release_opening_lock($pdo);
    $countIds = [];
    foreach ($jobIds as $jid) {
        $j = import_job_get($pdo, $jid);
        if ($j && $j['stored_path']) { import_delete_stored((string)$j['stored_path']); }
        $q = $pdo->prepare('SELECT id FROM stock_counts WHERE notes LIKE ?');
        $q->execute(['%(Import #' . $jid . ')%']);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) { $countIds[(int)$c] = true; }
        $pdo->prepare("DELETE FROM admin_activity_log WHERE action IN ('stock_import', 'stock_import_cost') AND details LIKE ?")->execute(['Import #' . $jid . ' %']);
        $pdo->prepare("DELETE FROM admin_activity_log WHERE action IN ('stock_import', 'stock_import_cost') AND details LIKE ?")->execute(['Import #' . $jid . ':%']);
    }
    $q = $pdo->prepare('SELECT DISTINCT l.count_id FROM stock_count_lines l INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name LIKE ?');
    $q->execute([$P . '%']);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) { $countIds[(int)$c] = true; }
    foreach (array_keys($countIds) as $c) {
        $pdo->prepare('DELETE FROM stock_opening_batches WHERE count_id = ?')->execute([$c]);
        $pdo->prepare('DELETE FROM stock_count_lines WHERE count_id = ?')->execute([$c]);
        $pdo->prepare('DELETE FROM stock_counts WHERE id = ?')->execute([$c]);
    }
    $pdo->prepare('DELETE FROM import_aliases WHERE id > ? AND is_hotel_learned = 1')->execute([$aliasMax]);
    $pdo->prepare('DELETE FROM stock_ingredients WHERE name LIKE ?')->execute([$P . '%']);
    if ($ownerCreatedFrom !== null) {
        $del = $pdo->prepare('DELETE FROM stock_ingredients WHERE id > ? AND LOWER(TRIM(name)) = ?');
        foreach ($ownerNewKeys as $k) { $del->execute([$ownerCreatedFrom, $k]); }
    }
    if ($jobIds) {
        $pdo->exec('DELETE FROM import_jobs WHERE id IN (' . implode(',', array_map('intval', $jobIds)) . ')');
    }
    if ($switchWas !== '1') {
        if ($switchWas === false) { $pdo->exec("DELETE FROM site_settings WHERE setting_key = 'data_import_enabled'"); }
        else { $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'data_import_enabled'")->execute([$switchWas]); }
    }
    foreach ((array)@glob($tmp . DIRECTORY_SEPARATOR . '*') as $f) { @unlink($f); }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

$csv = function (array $rows, array $head = ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']) {
    $fh = fopen('php://memory', 'w+');
    fputcsv($fh, $head);
    foreach ($rows as $r) { fputcsv($fh, $r); }
    rewind($fh);
    return stream_get_contents($fh);
};
$answerAll = function (int $jobId) use ($pdo, $admin) {   // answers every open blocking question the safe way (as import_stock_child.php)
    for ($i = 0; $i < 400; $i++) {
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
        if ($open['type'] === 'mapping-unsure' && in_array('ignore', $keys, true)) { $pick = 'ignore'; }   // an unsure column guess is never taken
        if (in_array('skip', $keys, true) && ($open['bad'] || $open['type'] === 'bad-value')) { $pick = 'skip'; }
        $r = import_job_answer($pdo, $jobId, $open['id'], $pick, $admin);
        if (!$r['ok']) { return ['stuck' => $open['type'], 'err' => $r['error']]; }
    }
    return ['stuck' => 'loop'];
};
$stage = function (string $path, string $origName) use ($pdo, $admin, &$jobIds, $answerAll) {
    $r = import_job_create($pdo, $path, $origName, $admin, ['from_upload' => false]);
    if (empty($r['ok'])) { return ['ok' => false, 'error' => (string)($r['error'] ?? 'upload refused')]; }
    $jid = (int)$r['job_id'];
    $jobIds[] = $jid;
    $a = import_job_analyse($pdo, $jid);
    if (empty($a['ok'])) { return ['ok' => false, 'error' => (string)($a['error'] ?? 'analyse failed'), 'job' => $jid]; }
    $s = $answerAll($jid);
    return ['ok' => true, 'job' => $jid, 'summary' => $s];
};
$upload = function (string $bytes, string $case) use ($tmp, $run, $stage) {
    $name = 'zzt8c-' . $case . '-' . $run . '.csv';
    $p = $tmp . DIRECTORY_SEPARATOR . $name;
    file_put_contents($p, $bytes);
    return $stage($p, $name);
};
$items = function (int $jobId) use ($pdo) {
    $st = import_job_state($pdo, $jobId);
    $out = [];
    foreach (($st['res']['items'] ?? []) as $it) { $out[$it['name']] = $it; }
    return $out;
};
$addIng = function (string $name, float $qty, float $cost, float $par = 5, float $reorder = 2) use ($pdo) {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'each', ?, ?, ?, ?)")
        ->execute([$name, $qty, $cost, $par, $reorder]);
    return (int)$pdo->lastInsertId();
};
$countByName = function (string $name) use ($pdo) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM stock_ingredients WHERE name = ?');
    $q->execute([$name]);
    return (int)$q->fetchColumn();
};

try {
    /* ==== I1: two commits that share a NEW item, at the same time -> the item is created once ======================== */
    $jA = $upload($csv([[$n('Shared'), '', '5', 'each', '100', '', ''], [$n('Only A'), '', '1', 'each', '10', '', '']]), 'i1a');
    $jB = $upload($csv([[$n('Shared'), '', '5', 'each', '100', '', ''], [$n('Only B'), '', '2', 'each', '20', '', '']]), 'i1b');
    $readyA = $jA['ok'] && !empty($jA['summary']['can_confirm']);
    $readyB = $jB['ok'] && !empty($jB['summary']['can_confirm']);
    ok('I1 setup: both jobs analysed and ready (Shared is new in both)', $readyA && $readyB, json_encode([$jA['error'] ?? '', $jB['error'] ?? '']));
    if ($readyA && $readyB) {
        // hold the opening-count lock so both children stop inside their commit; then let go and see what each one wrote
        $hold = zz_conn();
        $hold->query('SELECT GET_LOCK(' . $hold->quote(rh_ssi_opening_lock_name()) . ', 5)')->fetchColumn();
        $procs = [];
        foreach (['a' => $jA['job'], 'b' => $jB['job']] as $k => $jid) {
            $out = $tmp . DIRECTORY_SEPARATOR . "child_$k.json";
            $procs[$k] = ['out' => $out, 'p' => proc_open([PHP_BINARY, __FILE__, '--child-commit', (string)$jid, (string)$admin, $out],
                [0 => ['pipe', 'r'], 1 => ['file', $tmp . DIRECTORY_SEPARATOR . "child_$k.log", 'w'], 2 => ['file', $tmp . DIRECTORY_SEPARATOR . "child_$k.err", 'w']], $pipes, $root, null, ['bypass_shell' => true])];
            fclose($pipes[0]);
        }
        $waiting = 0;
        for ($t = 0; $t < 40; $t++) {          // up to ~8 s: both children are waiting on a named lock
            usleep(200000);
            $waiting = (int)$hold->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE 'SELECT GET_LOCK(%'
                AND (INFO LIKE '%rh_stock_count_opening_%' OR INFO LIKE '%rh_import_commit_%')")->fetchColumn();
            if ($waiting >= 2) { break; }
        }
        $hold->query('SELECT RELEASE_LOCK(' . $hold->quote(rh_ssi_opening_lock_name()) . ')');
        $res = [];
        foreach ($procs as $k => $pp) {
            proc_close($pp['p']);
            $res[$k] = json_decode((string)@file_get_contents($pp['out']), true) ?: ['ok' => false, 'error' => 'child: ' . substr((string)@file_get_contents($tmp . DIRECTORY_SEPARATOR . "child_$k.err"), -300)];
        }
        $hold = null;
        echo "  (I1: $waiting children were waiting on a lock at release)\n";
        ok('I1: two imports committed at the same time create the shared new item ONCE', $countByName($n('Shared')) === 1, 'rows: ' . $countByName($n('Shared')) . ' ' . json_encode([$res['a']['error'] ?? '', $res['b']['error'] ?? '']));
        ok('I1: both imports finish (the second waits for the first, then sees the item as existing)', !empty($res['a']['ok']) && !empty($res['b']['ok']), json_encode([$res['a']['error'] ?? '', $res['b']['error'] ?? '']));
        ok('I1: each import made its own other item', $countByName($n('Only A')) === 1 && $countByName($n('Only B')) === 1);
    }

    /* ==== I2: a "create" row whose name exists by the time it is written -> refused, everything rolled back ============ */
    foreach (['same transaction' => 'own', 'another user, committed meanwhile' => 'other'] as $label => $how) {
        $jC = $upload($csv([[$n("Late $how"), '', '3', 'each', '20', '', ''], [$n("Other $how"), '', '2', 'each', '30', '', '']]), "i2$how");
        $job = $jC['ok'] ? import_job_get($pdo, $jC['job']) : null;
        if (!$job || empty($jC['summary']['can_confirm'])) { ok("I2 ($label) setup", false, json_encode($jC['error'] ?? '')); continue; }
        $err = '';
        $pdo->beginTransaction();
        try {
            $v = import_job_revalidate($pdo, $job);                  // the plan is made while "Late" does not exist yet
            if ($how === 'own') {
                $addIng($n("Late $how"), 4, 20);
            } else {
                $c2 = zz_conn();
                $c2->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit) VALUES (?, 'Pantry', 'each', 4, 20)")->execute([$n("Late $how")]);
                $c2 = null;
            }
            $plan = import_build_stock_plan($v['res'], $v['ctx']);
            rh_ssi_apply($pdo, $plan, $admin);
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        $pdo->rollBack();
        rh_ssi_release_opening_lock($pdo);
        ok("I2 ($label): the create row whose name now exists is refused with a plain 'review again' reason", stripos($err, 'matches existing') !== false && stripos($err, 'review') !== false, $err === '' ? 'apply went ahead' : $err);
        ok("I2 ($label): nothing from that import stays (the other new item is not created, no second copy of the name)",
            $countByName($n("Other $how")) === 0 && $countByName($n("Late $how")) === ($how === 'other' ? 1 : 0));
    }
    // the commit itself turns that refusal into "Nothing was imported" and rolls back (source order: the throw is inside its try)
    $src = (string)file_get_contents($root . '/admin/includes/stock-sheet-import-lib.php');
    ok('I2: rh_ssi_apply no longer treats a now-existing name as "change nothing" (the silent skip is gone)', strpos($src, "appeared since the plan was made: treat as existing, change nothing") === false);

    /* ==== I3: only ticked columns change; a cost change made after the analysis survives ============================== */
    $keepId = $addIng($n('Keep'), 2, 100, 5, 2);
    $jD = $upload($csv([[$n('Keep'), '', '10', 'each', '150', '9', '']]), 'i3');
    $r1 = $jD['ok'] ? import_job_set_update_fields($pdo, $jD['job'], rh_ssi_key($n('Keep')), ['par', 'quantity'], $admin) : ['ok' => false];
    $s1 = $jD['ok'] ? $answerAll($jD['job']) : [];
    ok('I3 setup: Keep is matched, par + quantity ticked (cost NOT ticked), ready', !empty($r1['ok']) && !empty($s1['can_confirm']), json_encode($r1['error'] ?? ''));
    if (!empty($r1['ok']) && !empty($s1['can_confirm'])) {
        $job = import_job_get($pdo, $jD['job']);
        $pdo->beginTransaction();
        $err = '';
        $line = null; $row = null;
        try {
            $v = import_job_revalidate($pdo, $job);                  // the commit has read Keep (cost 100, qty 2) ...
            $c2 = zz_conn();                                          // ... then another user changes its cost and a sale moves its stock
            $c2->prepare('UPDATE stock_ingredients SET cost_per_unit = 120, current_quantity = 4 WHERE id = ?')->execute([$keepId]);
            $c2 = null;
            $plan = import_build_stock_plan($v['res'], $v['ctx']);
            $ap = rh_ssi_apply($pdo, $plan, $admin);
            $q = $pdo->prepare('SELECT cost_per_unit, par_level, category FROM stock_ingredients WHERE id = ? FOR UPDATE');
            $q->execute([$keepId]);
            $row = $q->fetch();
            $q = $pdo->prepare('SELECT system_quantity, actual_quantity, cost_per_unit FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
            $q->execute([(int)$ap['count_id'], $keepId]);
            $line = $q->fetch();
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        $pdo->rollBack();
        rh_ssi_release_opening_lock($pdo);
        ok('I3 (race): the unticked cost changed by someone else after the analysis survives (120, not the stale 100)', $row && abs((float)$row['cost_per_unit'] - 120) < 0.0001, $err !== '' ? $err : json_encode($row));
        ok('I3 (race): the ticked par is written (9)', $row && abs((float)$row['par_level'] - 9) < 0.0001, json_encode($row));
        ok('I3 (race): the count line takes the system quantity and cost from the locked row (4 at 120)', $line && abs((float)$line['system_quantity'] - 4) < 0.0001 && abs((float)$line['cost_per_unit'] - 120) < 0.0001 && abs((float)$line['actual_quantity'] - 10) < 0.0001, json_encode($line));
        // the same through the real commit (cost 120 / qty 4 are committed now): regression
        $c = import_test_commit($pdo, $jD['job'], $admin);
        $q = $pdo->prepare('SELECT cost_per_unit, par_level FROM stock_ingredients WHERE id = ?');
        $q->execute([$keepId]);
        $row = $q->fetch();
        $q = $pdo->prepare('SELECT system_quantity FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
        $q->execute([(int)($c['result']['opening_count_id'] ?? 0), $keepId]);
        ok('I3 (commit): cost stays 120, par becomes 9, the count line starts from 4', $c['ok'] && abs((float)$row['cost_per_unit'] - 120) < 0.0001 && abs((float)$row['par_level'] - 9) < 0.0001 && abs((float)$q->fetchColumn() - 4) < 0.0001, json_encode($c['error'] ?? ''));
    }

    /* ==== I4: a ticked quantity of 0 is counted; a blank cell is not a value ========================================== */
    $zeroId = $addIng($n('Zero'), 7, 50);
    $blankId = $addIng($n('Blank'), 3, 40);
    $jE = $upload($csv([[$n('Zero'), '', '0', 'each', '50', '', ''], [$n('Blank'), '', '', 'each', '40', '', '']]), 'i4');
    $its = $jE['ok'] ? $items($jE['job']) : [];
    $zi = null; $bi = null;
    foreach ($its as $it) {
        if (($it['match']['id'] ?? 0) === $zeroId) { $zi = $it; }
        if (($it['match']['id'] ?? 0) === $blankId) { $bi = $it; }
    }
    ok('I4 setup: the 0 cell is a quantity change (7 -> 0); the blank cell is no value at all', $zi && isset($zi['diff']['quantity']) && abs((float)$zi['diff']['quantity'][1]) < 0.0001 && $bi && !isset($bi['diff']['quantity']) && $bi['balance'] === null, json_encode([$zi['diff'] ?? null, $bi['diff'] ?? null, $bi['balance'] ?? 'x']));
    $rb = $jE['ok'] ? import_job_set_update_fields($pdo, $jE['job'], rh_ssi_key($n('Blank')), ['quantity'], $admin) : ['ok' => true];
    ok('I4: a blank cell cannot be ticked as a quantity', empty($rb['ok']));
    $rz = $jE['ok'] ? import_job_set_update_fields($pdo, $jE['job'], rh_ssi_key($n('Zero')), ['quantity'], $admin) : ['ok' => false];
    if ($jE['ok']) { $answerAll($jE['job']); }
    $zi = null;
    foreach (($jE['ok'] ? $items($jE['job']) : []) as $it) { if (($it['match']['id'] ?? 0) === $zeroId) { $zi = $it; } }
    ok('I4: the ticked 0 goes into the opening count (review shows it counted)', !empty($rz['ok']) && $zi && !empty($zi['in_count']), json_encode($zi['warnings'] ?? $zi));
    $c = $jE['ok'] ? import_test_commit($pdo, $jE['job'], $admin) : ['ok' => false, 'error' => 'no job'];
    $cid = (int)($c['result']['opening_count_id'] ?? 0);
    $q = $pdo->prepare('SELECT system_quantity, actual_quantity, variance, reason_code FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
    $q->execute([$cid, $zeroId]);
    $zl = $q->fetch();
    $q->execute([$cid, $blankId]);
    $bl = $q->fetch();
    ok('I4: commit writes a shortage line 7 -> 0, reason correction', $c['ok'] && $zl && abs((float)$zl['system_quantity'] - 7) < 0.0001 && abs((float)$zl['actual_quantity']) < 0.0001 && abs((float)$zl['variance'] + 7) < 0.0001 && $zl['reason_code'] === 'correction', json_encode([$c['error'] ?? '', $zl]));
    ok('I4: the blank cell puts no line in the count', !$bl);
    $q = $pdo->prepare('SELECT COUNT(*) FROM stock_opening_batches WHERE count_id = ? AND quantity <= 0');
    $q->execute([$cid]);
    ok('I4: no zero-quantity batch is staged', (int)$q->fetchColumn() === 0);

    /* ==== I5: Cancel on a job that has been committed -> nothing changes, plain message ============================== */
    $jF = $upload($csv([[$n('Cancel me'), '', '1', 'each', '10', '', '']]), 'i5');
    if ($jF['ok']) {
        $rc = import_job_cancel($pdo, (int)$jE['job'], $admin);         // the plain case: E is committed
        ok('I5 (plain): Cancel on a committed import says so and changes nothing', !$rc['ok'] && import_job_get($pdo, (int)$jE['job'])['status'] === 'committed', json_encode($rc));
        // the race: Cancel read the job as "analysed" just before the import finished saving
        $c2 = zz_conn();
        $c2->beginTransaction();
        import_job_get($c2, $jF['job']);                                        // the Cancel request's picture: analysed
        $pdo->prepare("UPDATE import_jobs SET status = 'committed', result_json = '{}' WHERE id = ?")->execute([$jF['job']]);   // the commit finishes
        $rc = import_job_cancel($c2, $jF['job'], $admin);
        $c2->commit();
        $c2 = null;
        $jf = import_job_get($pdo, $jF['job']);
        ok('I5 (race): Cancel arriving after the import was saved changes nothing (still committed, file kept)', $jf['status'] === 'committed' && $jf['stored_path'] !== null && import_job_file_path($jf) !== '', json_encode([$jf['status'], $jf['stored_path'] !== null]));
        ok('I5 (race): and says so in a plain sentence', empty($rc['ok']) && preg_match('/already|nothing/i', (string)($rc['error'] ?? '')) === 1, json_encode($rc));
    }

    /* ==== Owner file: the stock count export in several shapes -> the same mapping; one commit = ONE opening count ==== */
    $realDir = $root . '/scripts/tests/fixtures/import/real';
    $owner = $realDir . '/stock-management-20261110-101650.csv';
    if (!is_file($owner)) {
        echo "SKIP owner-file cases (fixture not on this machine)\n";
    } else {
        // failure details from the owner's file never show its item names: anything in quotes is masked (real data stays off screen)
        $mask = function ($x) { $x = is_string($x) ? $x : json_encode($x); return (string)preg_replace('/"[^"]*"|\x{201C}[^\x{201D}]*\x{201D}/u', '"..."', substr((string)$x, 0, 300)); };
        $raw = (string)file_get_contents($owner);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $grid = [];
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh)) !== false) { if ($r !== [null]) { $grid[] = $r; } }
        fclose($fh);
        $head = array_shift($grid);
        $writeCsv = function (string $path, array $rows) {
            $fh = fopen($path, 'w');
            foreach ($rows as $r) { fputcsv($fh, $r); }
            fclose($fh);
        };
        // variant 1: the same table as .xlsx (the repo's own writer)
        $w = new ImportXlsxWriter(['title' => 'Stock']);
        $si = $w->addSheet('Stock');
        $cells = [];
        foreach ($head as $i => $h) { $cells[$i + 1] = ['str' => (string)$h]; }
        $w->row($si, 1, $cells);
        foreach ($grid as $ri => $r) {
            $cells = [];
            foreach ($r as $i => $v) { $cells[$i + 1] = ($v !== '' && is_numeric($v)) ? ['num' => (string)$v] : ['str' => (string)$v]; }
            $w->row($si, $ri + 2, $cells);
        }
        $fin = $w->finish();
        $xlsx = $realDir . '/zzt8c-variant-xlsx.xlsx';
        $xf = fopen($xlsx, 'w');
        rewind($fin['stream']);
        stream_copy_to_stream($fin['stream'], $xf);
        fclose($xf);
        fclose($fin['stream']);
        // variant 2: renamed + reordered headers
        $rename = ['Name' => 'Item Description', 'Category' => 'Department', 'Unit' => 'UOM', 'Current Qty' => 'Closing Stock', 'Min Qty' => 'Minimum',
            'Reorder Point' => 'Reorder Level', 'Par Level' => 'Max Level', 'Lead Time (days)' => 'Lead Days', 'Cost/Unit' => 'Unit Cost',
            'Stock Value' => 'Total Value', 'Next Expiry' => 'Expiry Date', 'Status' => 'Stock Status'];
        $order = ['Cost/Unit', 'Name', 'Current Qty', 'Unit', 'Category', 'Next Expiry', 'Par Level', 'Reorder Point', 'Min Qty', 'Lead Time (days)', 'Stock Value', 'Status'];
        $idx = array_flip($head);
        $ren = [array_map(function ($h) use ($rename) { return $rename[$h] ?? $h; }, $order)];
        foreach ($grid as $r) { $ren[] = array_map(function ($h) use ($r, $idx) { return $r[$idx[$h]] ?? ''; }, $order); }
        $renamed = $realDir . '/zzt8c-variant-renamed.csv';
        $writeCsv($renamed, $ren);
        // variant 3: a title row and blank lines on top
        $titled = $realDir . '/zzt8c-variant-titled.csv';
        $writeCsv($titled, array_merge([['Stock count'], [''], ['Printed from the stock list'], [''], ['']], [$head], $grid));

        $shape = function (int $jobId) use ($items) {
            $out = ['ready' => 0, 'matched' => [], 'qty' => [], 'byname' => []];
            foreach ($items($jobId) as $it) {
                if ($it['status'] !== 'ready') { continue; }
                $out['ready']++;
                $k = $it['match'] ? 'id:' . $it['match']['id'] : 'new:' . rh_ssi_key($it['name']);
                if ($it['match']) { $out['matched'][] = (int)$it['match']['id']; }
                $out['qty'][$k] = $it['balance'] === null ? null : round((float)$it['balance'], 4);
                $out['byname'][rh_ssi_key((string)($it['typed_name'] ?? $it['name']))] = $out['qty'][$k];
            }
            sort($out['matched']);
            ksort($out['qty']);
            ksort($out['byname']);
            return $out;
        };
        $base = $stage($owner, 'zzt8c-owner-csv-' . $run . '.csv');
        $baseShape = $base['ok'] ? $shape($base['job']) : null;
        ok('owner file (csv): analysed, every question answered, ready to confirm', $base['ok'] && !empty($base['summary']['can_confirm']), $mask($base['error'] ?? ($base['summary']['why_not'] ?? ($base['summary']['stuck'] ?? ''))));
        if ($baseShape) {
            echo '  (owner csv: ' . $baseShape['ready'] . ' ready items, ' . count($baseShape['matched']) . " matched to existing ingredients)\n";
        }
        foreach (['xlsx' => [$xlsx, 'xlsx'], 'renamed + reordered headers' => [$renamed, 'csv'], 'title row + blank lines' => [$titled, 'csv']] as $label => [$path, $ext]) {
            $v = $stage($path, 'zzt8c-owner-' . preg_replace('/\W+/', '', $label) . '-' . $run . '.' . $ext);
            $vs = $v['ok'] ? $shape($v['job']) : null;
            $same = $vs && $baseShape && $vs['ready'] === $baseShape['ready'] && $vs['matched'] === $baseShape['matched'] && $vs['qty'] === $baseShape['qty'];
            echo '  (owner ' . $label . ': ' . ($vs ? $vs['ready'] . ' ready, ' . count($vs['matched']) . ' matched, ' . count(array_diff_assoc($vs['qty'], $baseShape['qty'] ?? [])) . ' quantities differ from the csv' : 'not analysed') . ")\n";
            ok("owner file ($label): maps the same as the csv (same item count, same matched items, same quantities)", $same, $v['ok'] ? '' : $mask((string)$v['error']));
        }
        if ($base['ok'] && !empty($base['summary']['can_confirm'])) {
            // review: tick "quantity" on every matched item whose quantity differs (the first few through the real endpoint function,
            // the rest written in the same answers shape in one go - 300 single calls would each re-resolve the whole file)
            $st = import_job_state($pdo, $base['job']);
            $keys = [];
            foreach ($st['res']['items'] as $it) {
                if ($it['status'] === 'ready' && $it['match'] && isset($it['diff']['quantity'])) { $keys[] = rh_ssi_key($it['match']['name']); }
                if ($it['status'] === 'ready' && !$it['match']) { $ownerNewKeys[] = rh_ssi_key($it['name']); }
            }
            $viaApi = 0;
            foreach (array_slice($keys, 0, 3) as $k) { $viaApi += !empty(import_job_set_update_fields($pdo, $base['job'], $k, ['quantity'], $admin)['ok']) ? 1 : 0; }
            $ans = import_job_answers(import_job_get($pdo, $base['job']));
            foreach ($keys as $k) { $ans['_updates'][$k] = ['quantity']; }
            $pdo->prepare("UPDATE import_jobs SET answers_json = ?, status = 'reviewing' WHERE id = ?")->execute([import_json($ans), $base['job']]);
            $s = $answerAll($base['job']);
            $expectLines = 0;
            foreach ($items($base['job']) as $it) { if ($it['status'] === 'ready' && !empty($it['in_count'])) { $expectLines++; } }
            echo '  (owner csv: ' . count($keys) . ' matched items have a different quantity; ' . $expectLines . " go into the count)\n";
            ok('owner file: the quantity ticks are accepted by the review (' . $viaApi . ' through the endpoint function)', $viaApi === min(3, count($keys)) && !empty($s['can_confirm']), $mask($s['why_not'] ?? ($s['stuck'] ?? '')));
            $commitOne = function (int $jobId, int $expectLines, string $label) use ($pdo, $admin, &$ownerCreatedFrom, $mask) {
                if ($ownerCreatedFrom === null) { $ownerCreatedFrom = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_ingredients')->fetchColumn(); }
                $countFrom = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM stock_counts')->fetchColumn();
                $c = import_test_commit($pdo, $jobId, $admin);
                $q = $pdo->prepare('SELECT id, status FROM stock_counts WHERE id > ? AND is_opening = 1');
                $q->execute([$countFrom]);
                $new = $q->fetchAll();
                echo "  (owner $label: commit " . ($c['ok'] ? 'ok' : 'refused') . ', created ' . (int)($c['result']['created'] ?? 0) . ', updated ' . (int)($c['result']['updated'] ?? 0)
                    . ', count lines ' . (int)($c['result']['count_lines'] ?? 0) . ' of ' . $expectLines . ' expected, left out (already in an open count) ' . (int)($c['result']['left_out_overlap'] ?? 0) . ")
";
                if ($expectLines === 0) {
                    ok("owner file ($label): nothing to count -> the commit makes no opening count and changes nothing", $c['ok'] && !$new && empty($c['result']['opening_count_id'])
                        && (int)$c['result']['created'] === 0 && (int)$c['result']['updated'] === 0, $mask($c['error'] ?? ''));
                    return;
                }
                ok("owner file ($label): the commit succeeds and makes exactly ONE new opening count, left submitted", $c['ok'] && count($new) === 1
                    && (int)$new[0]['id'] === (int)($c['result']['opening_count_id'] ?? -1) && $new[0]['status'] === 'submitted', $mask(($c['error'] ?? '') . ' / new counts: ' . count($new)));
                ok("owner file ($label): one line per counted item", (int)($c['result']['count_lines'] ?? -1) + (int)($c['result']['left_out_overlap'] ?? 0) === $expectLines,
                    'lines ' . (int)($c['result']['count_lines'] ?? -1) . ' expected ' . $expectLines);
                $again = import_test_commit($pdo, $jobId, $admin);
                $q->execute([$countFrom]);
                ok("owner file ($label): pressing Import again changes nothing (still one count)", $again['ok'] && $again['already'] && count($q->fetchAll()) === 1);
            };
            $commitOne($base['job'], $expectLines, 'csv as it is');

            // the same count as a FIRST import: every name prefixed so each row is a new ingredient (Liwonde already holds these
            // items at these quantities, so the as-is file has nothing to count). Renamed headers, so the cost column is read too.
            $first = [array_shift($ren)];
            $nameAt = array_search('Item Description', $first[0], true);
            foreach ($ren as $r) { $r[$nameAt] = $P . $r[$nameAt]; $first[] = $r; }
            $firstPath = $realDir . '/zzt8c-variant-first.csv';
            $writeCsv($firstPath, $first);
            $fv = $stage($firstPath, 'zzt8c-owner-first-' . $run . '.csv');
            $fs = $fv['ok'] ? $shape($fv['job']) : null;
            $pk = rh_ssi_key($P) . ' ';
            $fqty = [];
            foreach (($fs['byname'] ?? []) as $k => $q) { $fqty[strpos($k, $pk) === 0 ? substr($k, strlen($pk)) : '?' . $k] = $q; }
            ksort($fqty);
            echo '  (owner first import: ' . ($fs ? $fs['ready'] . ' ready, ' . count($fs['matched']) . ' matched' : 'not analysed: ' . $mask($fv['error'] ?? ($fv['summary']['stuck'] ?? ''))) . ")
";
            ok('owner file (first import): every row is ready and new, same item count as the csv', $fs && !empty($fv['summary']['can_confirm']) && $fs['ready'] === $baseShape['ready'] && !$fs['matched'],
                $mask($fv['summary']['why_not'] ?? ($fv['summary']['stuck'] ?? $fv['error'] ?? '')));
            ok('owner file (first import): the same quantity per item as the csv', $fs && $fqty === $baseShape['byname'], count(array_diff_assoc($fqty, $baseShape['byname'] ?? [])) . ' differ');
            if ($fs && !empty($fv['summary']['can_confirm'])) {
                $fl = 0;
                foreach ($items($fv['job']) as $it) { if ($it['status'] === 'ready' && !empty($it['in_count'])) { $fl++; } }
                $commitOne($fv['job'], $fl, 'first import');
            }
            @unlink($firstPath);
        }
        foreach ([$xlsx, $renamed, $titled] as $f) { @unlink($f); }
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

/* ==== nothing left behind ======================================================================================== */
$jobsMade = $jobIds;
$cleanup();
$left = (int)$pdo->query('SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE ' . $pdo->quote($P . '%'))->fetchColumn();
$leftJobs = $jobsMade ? (int)$pdo->query('SELECT COUNT(*) FROM import_jobs WHERE id IN (' . implode(',', array_map('intval', $jobsMade)) . ')')->fetchColumn() : 0;
$leftCounts = 0;
foreach ($jobsMade as $jid) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM stock_counts WHERE notes LIKE ?');
    $q->execute(['%(Import #' . $jid . ')%']);
    $leftCounts += (int)$q->fetchColumn();
}
$leftLines = (int)$pdo->query('SELECT COUNT(*) FROM stock_count_lines l LEFT JOIN stock_counts c ON c.id = l.count_id WHERE c.id IS NULL')->fetchColumn();
$leftAliases = (int)$pdo->query('SELECT COUNT(*) FROM import_aliases WHERE is_hotel_learned = 1 AND id > ' . $aliasMax)->fetchColumn();
$leftNew = 0;
foreach ($ownerNewKeys as $k) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM stock_ingredients WHERE id > ? AND LOWER(TRIM(name)) = ?');
    $q->execute([(int)($ownerCreatedFrom ?? PHP_INT_MAX), $k]);
    $leftNew += (int)$q->fetchColumn();
}
$fpAfter = $pdo->query($fpSql)->fetch();
ok('nothing left behind: no test ingredients, jobs, counts, orphan lines or learned aliases', $left + $leftJobs + $leftCounts + $leftLines + $leftAliases + $leftNew === 0, json_encode(compact('left', 'leftJobs', 'leftCounts', 'leftLines', 'leftAliases', 'leftNew')));
ok('nothing left behind: every pre-existing ingredient is unchanged (name, category, unit, cost, par, reorder, pack, notes)', $fpAfter == $fpBefore, json_encode([$fpBefore, $fpAfter]));
ok('nothing left behind: the variant files are gone from the fixture folder', !glob($root . '/scripts/tests/fixtures/import/real/zzt8c-*'));

echo "\n$pass passed, $fail failed\n";
if ($red) { echo "FAILED: " . implode(' | ', $red) . "\n"; }
exit($fail ? 1 : 0);
