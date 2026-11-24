<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * "When was this counted?" + regular counts after an approved opening count (critical patch 2026-11-25), for
 * scripts/tests/import_stock_test.php. Run by page_harness.php: ONE outer transaction that can never commit, rolled back at the end.
 * POST: run, case (a | b | c | e), out (file for the checks made before the page). One case per run, so an approved opening count
 * made by one case never changes the kind of count another case files.
 *
 *   a  opening count: current 7, 3 sold after T; the file says 10 (A) and 9 (B). Compared with the stock AT T (10): A is the same
 *      (no line), B is 1 short (system 10, actual 9). Then the REAL Stock Count page approves it (another manager imported it):
 *      the parent checks A stays 7 and B becomes 6.
 *   b  with an approved opening count: a regular count, the shortage gets no reason, the count is a draft, no opening batches;
 *      a commit that expected an opening count is refused. Then the REAL page saves a reason on the line: counted_at stays T.
 *   c  refusals: T before a later approved count of the item; T in the future (setter and commit gate); no T at all.
 *   e  with an approved opening count, no shortage: the regular count is submitted. Then the REAL page: the person who imported it
 *      cannot approve it (two-person rule on; import_job_id), the parent checks it stays submitted and stock is unchanged.
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { exit("refused: Rosalyn's is live\n"); }
$run = (string)($_POST['run'] ?? 'X');
$case = (string)($_POST['case'] ?? '');
$outFile = (string)($_POST['out'] ?? '');
$csrfTok = (string)($_POST['csrf_token'] ?? '');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks, $case) { $checks[] = ['count time (' . $case . '): ' . $n, (bool)$c, $d]; };
$T = '';
$save = function () use (&$checks, $outFile, &$T) { if ($outFile !== '') { file_put_contents($outFile, json_encode(['checks' => $checks, 'T' => $T])); } };
$tmpFiles = [];
$made = [];

require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/import/commit-stock.php';
require_once $root . '/scripts/tests/import_count_time_helper.php';

$me = (int)($_SESSION['admin_user_id'] ?? 0);
$n = function (string $s) use ($run) { return 'ZZT ' . $run . ' ' . $s; };
$pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
updateSetting('stock_count_second_approver', '1');
updateSetting('stock_variance_block_pct', '50');
updateSetting('stock_variance_min_cost', '1000000');
$pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'manager', 1)")
    ->execute([substr($n('ctmgr'), 0, 90), strtolower(preg_replace('/\W+/', '', $n('ctmgr'))) . '@example.invalid', $n('ctmgr')]);
$mgr = (int)$pdo->lastInsertId();
$now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$T = (string)$pdo->query('SELECT NOW() - INTERVAL 10 MINUTE')->fetchColumn();
$soldAt = (string)$pdo->query('SELECT NOW() - INTERVAL 5 MINUTE')->fetchColumn();

// an item with 7 in stock now, 3 of them sold after T (5 minutes ago): its stock at T was 10
$mkItem = function (string $name, float $qty, float $sold) use ($pdo, $soldAt, $me) {
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', ?, 100, 5, 2)")->execute([$name, $qty]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_alert_days, status, notes) VALUES (?, ?, ?, ?, 100, CURDATE(), 7, 'active', 'ZZT')")
        ->execute([$id, 'ZZT-' . $id, $qty + $sold, $qty]);
    if ($sold > 0) {
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, ?, 'ZZT sale after the count', 'pos_order', NULL, 100, ?, ?)")
            ->execute([$id, -$sold, $me, $soldAt]);
    }
    return $id;
};
$csvJob = function (array $rows, int $uid) use ($pdo, $run, &$tmpFiles, &$made) {
    $fh = fopen('php://memory', 'w+');
    fputcsv($fh, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
    foreach ($rows as $r) { fputcsv($fh, $r); }
    rewind($fh);
    $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzt_ct_' . $run . '_' . count($tmpFiles) . '.csv';
    file_put_contents($p, stream_get_contents($fh));
    $tmpFiles[] = $p;
    $r = import_job_create($pdo, $p, 'zzt-ct' . count($tmpFiles) . '-' . $run . '.csv', $uid, ['from_upload' => false]);
    if (empty($r['ok'])) { return 0; }
    $j = import_job_get($pdo, (int)$r['job_id']);
    $made[] = $j['stored_path'];
    import_job_analyse($pdo, (int)$r['job_id']);
    for ($i = 0; $i < 20; $i++) {                // answer any open blocking question the safe way
        $s = import_job_review_summary($pdo, (int)$r['job_id']);
        $open = null;
        foreach ($s['questions'] as $q) { if ($q['blocking'] && $q['answer'] === null && $q['options']) { $open = $q; break; } }
        if (!$open) { break; }
        $keys = array_column($open['options'], 'key');
        $pick = $keys[0];
        foreach (['per-unit', 'two-batches', 'keep'] as $pref) { if (in_array($pref, $keys, true)) { $pick = $pref; } }
        if ($open['type'] === 'no-unit') { $pick = in_array('unit:each', $keys, true) ? 'unit:each' : $keys[0]; }
        import_job_answer($pdo, (int)$r['job_id'], $open['id'], $pick, $uid);
    }
    return (int)$r['job_id'];
};
$item = function (int $jobId, string $name) use ($pdo) {
    foreach (import_job_review_summary($pdo, $jobId)['items'] as $x) { if (!empty($x['match']) && $x['match']['name'] === $name) { return $x; } }
    return null;
};
$countOf = function (int $jobId) use ($pdo) {
    $q = $pdo->prepare('SELECT * FROM stock_counts WHERE import_job_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$jobId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
};
$lineOf = function (int $countId, int $ingId) use ($pdo) {
    $q = $pdo->prepare('SELECT * FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
    $q->execute([$countId, $ingId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
};
$approvedOpening = function () use ($pdo, $run, $me) {      // an opening count approved two hours ago (no lines on the test items)
    $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, approved_by, approved_at, is_opening, created_at) VALUES (?, CURDATE(), 'full', 'approved', ?, ?, NOW() - INTERVAL 2 HOUR, 1, NOW() - INTERVAL 3 HOUR)")
        ->execute(['20991231-CTO' . $run, $me, $me]);
};
$pagePost = null;          // the REAL Stock Count page runs at the top level, after the case (it needs the global scope)

try {
    if ($case === 'a') {
        // no approved opening count in this (rolled-back) run: the import files the OPENING count
        $pdo->exec("UPDATE stock_counts SET is_opening = 0 WHERE is_opening = 1 AND status = 'approved'");
        $idA = $mkItem($n('Mango Juice'), 7, 3);
        $idB = $mkItem($n('Tonic Water'), 7, 3);
        $j = $csvJob([[$n('Mango Juice'), '', '10', 'bottle', '100', '', ''], [$n('Tonic Water'), '', '9', 'bottle', '100', '', '']], $mgr);
        $ck('the job was read', $j > 0);
        $setR = import_count_time_set($pdo, $j, str_replace(' ', 'T', substr($T, 0, 16)), $mgr);
        $Tm = substr($T, 0, 16) . ':00';       // the browser sends minutes
        $ck('the count time is saved from the browser format (minutes)', !empty($setR['ok']) && $setR['at'] === $Tm, json_encode($setR));
        $xA = $item($j, $n('Mango Juice'));
        $xB = $item($j, $n('Tonic Water'));
        $ck('A: 10 in the file = its stock at the count time (7 now + 3 sold since): no quantity difference', $xA && !isset($xA['diff']['quantity']), json_encode($xA['diff'] ?? null));
        $ck('B: 9 in the file against 10 at the count time: the difference shown is 10 -> 9', $xB && isset($xB['diff']['quantity']) && abs($xB['diff']['quantity'][0] - 10) < 0.0001 && abs($xB['diff']['quantity'][1] - 9) < 0.0001, json_encode($xB['diff'] ?? null));
        import_job_set_update_fields($pdo, $j, rh_ssi_key($n('Tonic Water')), ['quantity'], $mgr);
        $v = import_job_review_view($pdo, $j, null, []);
        $ck('the review says this files an opening count, at the count time, and Import is on', ($v['count_time']['kind'] ?? '') === 'opening' && ($v['count_time']['at'] ?? '') === $Tm && ($v['count_time']['problem'] ?? 'x') === '' && !empty($v['can_confirm']), json_encode([$v['count_time'] ?? null, $v['why_not'] ?? null]));
        $c = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => 'opening']);
        $ck('commit ok', !empty($c['ok']), json_encode($c['error'] ?? ''));
        $cnt = $countOf($j);
        $lB = $cnt ? $lineOf((int)$cnt['id'], $idB) : null;
        $ck('the count: opening, submitted, snapshot_at = T, count_date = the day of T', $cnt && (int)$cnt['is_opening'] === 1 && $cnt['status'] === 'submitted' && (string)$cnt['snapshot_at'] === $Tm && (string)$cnt['count_date'] === substr($Tm, 0, 10), json_encode($cnt));
        $ck('A has no line (nothing to change)', $cnt && $lineOf((int)$cnt['id'], $idA) === null);
        $ck('B: system 10 (stock at T), actual 9, variance -1, counted_at = T, reason correction (opening)', $lB && abs($lB['system_quantity'] - 10) < 0.0001 && abs($lB['actual_quantity'] - 9) < 0.0001 && abs($lB['variance'] + 1) < 0.0001 && (string)$lB['counted_at'] === $Tm && $lB['reason_code'] === 'correction', json_encode($lB));
        $ck('result: kind, term, status and count time are reported', ($c['result']['count_kind'] ?? '') === 'opening' && ($c['result']['count_term'] ?? '') === 'opening count' && ($c['result']['count_status'] ?? '') === 'submitted' && ($c['result']['counted_at'] ?? '') === $Tm, json_encode(array_intersect_key((array)($c['result'] ?? []), array_flip(['count_kind', 'count_term', 'count_status', 'counted_at']))));
        $ck('nothing in stock changed before approval (A 7, B 7)', abs((float)$pdo->query('SELECT current_quantity FROM stock_ingredients WHERE id = ' . $idA)->fetchColumn() - 7) < 0.0001 && abs((float)$pdo->query('SELECT current_quantity FROM stock_ingredients WHERE id = ' . $idB)->fetchColumn() - 7) < 0.0001);
        $pagePost = (['action' => 'approve_count', 'count_id' => (string)($cnt['id'] ?? 0)]);
    } elseif ($case === 'b') {
        $approvedOpening();
        $idB = $mkItem($n('Ginger Ale'), 7, 3);
        $idE = $mkItem($n('Soda Lime'), 7, 3);
        $ck('an approved opening count exists: imports now file a regular stock count', import_count_kind($pdo) === 'regular' && import_count_term('regular') === 'stock count');
        $j = $csvJob([[$n('Ginger Ale'), '', '9', 'bottle', '100', '', '2030-01-31']], $mgr);
        import_job_set_update_fields($pdo, $j, rh_ssi_key($n('Ginger Ale')), ['quantity'], $mgr);
        import_test_set_counted_at($pdo, $j, $T, $mgr);
        $v = import_job_review_view($pdo, $j, null, []);
        $ck('the review says "stock count"', ($v['count_time']['kind'] ?? '') === 'regular' && ($v['count_time']['term'] ?? '') === 'stock count', json_encode($v['count_time'] ?? null));
        $cOld = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => 'opening']);
        $ck('a commit from a screen that showed an opening count is refused ("review again"), nothing written', empty($cOld['ok']) && stripos((string)$cOld['error'], 'review again') !== false && $countOf($j) === null
            && (string)$pdo->query('SELECT status FROM import_jobs WHERE id = ' . $j)->fetchColumn() !== 'committed', (string)($cOld['error'] ?? ''));
        $c = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => 'regular']);
        $ck('commit ok', !empty($c['ok']), json_encode($c['error'] ?? ''));
        $cnt = $countOf($j);
        $lB = $cnt ? $lineOf((int)$cnt['id'], $idB) : null;
        $ck('the count: regular (is_opening 0), draft because of the shortage, snapshot_at = T', $cnt && (int)$cnt['is_opening'] === 0 && $cnt['status'] === 'draft' && (string)$cnt['snapshot_at'] === $T, json_encode($cnt));
        $ck('the shortage line: system 10, actual 9, counted_at = T, NO reason (the counter picks one)', $lB && abs($lB['system_quantity'] - 10) < 0.0001 && abs($lB['actual_quantity'] - 9) < 0.0001 && (string)$lB['counted_at'] === $T && (string)$lB['reason_code'] === '', json_encode($lB));
        $ob = 0;
        try { $ob = (int)$pdo->query('SELECT COUNT(*) FROM stock_opening_batches WHERE count_id = ' . (int)($cnt['id'] ?? 0))->fetchColumn(); } catch (Throwable $e) { $ob = 0; }
        $ck('no opening batches for a regular count (the expiry date in the file is not staged)', $ob === 0);
        $ck('result: draft, with the next step to give reasons', ($c['result']['count_status'] ?? '') === 'draft' && stripos((string)($c['result']['next_step'] ?? ''), 'reason for each shortage') !== false, (string)($c['result']['next_step'] ?? ''));
        $pagePost = (['action' => 'save_lines', 'count_id' => (string)($cnt['id'] ?? 0),
            'lines_json' => [json_encode([['id' => (int)($lB['id'] ?? 0), 'actual' => '9', 'reason' => 'spillage', 'notes' => '', 'touched' => '1']])]]);
    } elseif ($case === 'c') {
        $idC = $mkItem($n('Lemon Cordial'), 7, 0);
        // a count of the item approved 5 minutes ago (after T), with a posted line
        $pdo->prepare("INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at) VALUES (?, 0, 'ZZT count', 'variance', NULL, 100, ?, NOW() - INTERVAL 5 MINUTE)")->execute([$idC, $me]);
        $adj = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_counts (reference, count_date, scope, status, counted_by, approved_by, approved_at, is_opening, created_at) VALUES (?, CURDATE(), 'full', 'approved', ?, ?, NOW() - INTERVAL 5 MINUTE, 0, NOW() - INTERVAL 6 MINUTE)")
            ->execute(['20991231-CTC' . $run, $me, $me]);
        $scId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_count_lines (count_id, ingredient_id, system_quantity, actual_quantity, variance, cost_per_unit, variance_cost, adjustment_id) VALUES (?, ?, 7, 7, 0, 100, 0, ?)")->execute([$scId, $idC, $adj]);
        $j = $csvJob([[$n('Lemon Cordial'), '', '9', 'bottle', '100', '', '']], $mgr);
        import_job_set_update_fields($pdo, $j, rh_ssi_key($n('Lemon Cordial')), ['quantity'], $mgr);
        $kind = import_count_kind($pdo);
        $c0 = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => $kind]);
        $ck('no count time at all: the commit is refused', empty($c0['ok']) && stripos((string)$c0['error'], 'Enter when the stock in this file was counted') === 0, (string)($c0['error'] ?? ''));
        $fut = (string)$pdo->query('SELECT NOW() + INTERVAL 1 HOUR')->fetchColumn();
        $sF = import_count_time_set($pdo, $j, str_replace(' ', 'T', substr($fut, 0, 16)), $mgr);
        $ck('a future count time is refused when it is typed', empty($sF['ok']) && stripos((string)$sF['error'], 'in the future') !== false, (string)($sF['error'] ?? ''));
        import_test_set_counted_at($pdo, $j, $fut, $mgr);         // as if it was stored earlier: the commit checks again
        $cF = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => $kind]);
        $ck('a future count time is refused at commit too, nothing written', empty($cF['ok']) && stripos((string)$cF['error'], 'in the future') !== false && $countOf($j) === null, (string)($cF['error'] ?? ''));
        $sBad = import_count_time_set($pdo, $j, '2026-02-30T10:00', $mgr);
        $ck('an impossible date is refused', empty($sBad['ok']));
        import_test_set_counted_at($pdo, $j, $T, $mgr);
        $v = import_job_review_view($pdo, $j, null, []);
        $ck('T before a later approved count of the item: the review says why and Import is off', empty($v['can_confirm']) && stripos((string)($v['count_time']['problem'] ?? ''), 'was approved on') !== false && stripos((string)($v['count_time']['problem'] ?? ''), $n('Lemon Cordial')) !== false, json_encode([$v['count_time'] ?? null]));
        $cS = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => $kind]);
        $ck('T before a later approved count of the item: the commit is refused, nothing written', empty($cS['ok']) && stripos((string)$cS['error'], 'was approved on') !== false && $countOf($j) === null
            && abs((float)$pdo->query('SELECT current_quantity FROM stock_ingredients WHERE id = ' . $idC)->fetchColumn() - 7) < 0.0001, (string)($cS['error'] ?? ''));
        $T2 = (string)$pdo->query('SELECT NOW() - INTERVAL 1 MINUTE')->fetchColumn();
        import_test_set_counted_at($pdo, $j, $T2, $mgr);
        $cOk = import_commit_stock($pdo, $j, $mgr, ['expect_kind' => $kind]);
        $ck('a count time after that approval is accepted', !empty($cOk['ok']) && $countOf($j) !== null, json_encode($cOk['error'] ?? ''));
        $save();
    } elseif ($case === 'e') {
        $approvedOpening();
        $idE = $mkItem($n('Cola Zero'), 7, 3);
        $j = $csvJob([[$n('Cola Zero'), '', '12', 'bottle', '100', '', '']], $me);
        import_job_set_update_fields($pdo, $j, rh_ssi_key($n('Cola Zero')), ['quantity'], $me);
        import_test_set_counted_at($pdo, $j, $T, $me);
        $c = import_commit_stock($pdo, $j, $me, ['expect_kind' => 'regular']);
        $ck('commit ok', !empty($c['ok']), json_encode($c['error'] ?? ''));
        $cnt = $countOf($j);
        $lE = $cnt ? $lineOf((int)$cnt['id'], $idE) : null;
        $ck('no shortage: the regular count is submitted (is_opening 0)', $cnt && (int)$cnt['is_opening'] === 0 && $cnt['status'] === 'submitted', json_encode($cnt));
        $ck('the line: system 10 at T, actual 12, counted_at = T', $lE && abs($lE['system_quantity'] - 10) < 0.0001 && abs($lE['actual_quantity'] - 12) < 0.0001 && (string)$lE['counted_at'] === $T, json_encode($lE));
        $pagePost = (['action' => 'approve_count', 'count_id' => (string)($cnt['id'] ?? 0)]);
    } else {
        $ck('known case', false, $case);
        $save();
    }
} catch (Throwable $e) {
    $ck('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $save();
} finally {
    foreach ($tmpFiles as $p) { @unlink($p); }
    foreach ($made as $sp) { if ($sp) { @unlink(import_storage_dir() . DIRECTORY_SEPARATOR . $sp); } }
}
$save();
if ($pagePost !== null) {
    // this file's own names never reach the page (it reads some of its variables before it sets them)
    unset($c, $c0, $cF, $cS, $cOk, $cOld, $cnt, $v, $j, $lB, $lE, $xA, $xB, $setR, $sF, $sBad, $idA, $idB, $idC, $idE, $ob, $kind, $fut, $T2, $Tm,
        $item, $countOf, $lineOf, $csvJob, $mkItem, $approvedOpening, $n, $ck, $made, $tmpFiles, $adj, $scId, $mgr, $soldAt);
    $_POST = $pagePost + ['csrf_token' => $csrfTok];
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    chdir($root . '/admin');
    require $root . '/admin/stock-count.php';
}
