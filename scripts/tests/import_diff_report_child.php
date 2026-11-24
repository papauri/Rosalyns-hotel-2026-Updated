<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/import_diff_report.php (run by page_harness.php: ONE outer transaction that can never commit and is rolled back
 * at the end). Prints one JSON line: {"checks":[[name, ok, detail], ...]}. Every row is named 'ZZX <run> ...' (made-up items and prices).
 */
if (defined('DB_NAME') && stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo json_encode(['checks' => [["refused: Rosalyn's is live, run this from the Liwonde repo", false, '']]]), "\n";
    exit;
}
$run = (string)($_POST['run'] ?? 'X');
$preview = (string)($_POST['preview'] ?? '');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$made = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_diff_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];

/** The count page as one signed-in person sees it (stock-count.php run in its own scope; returns the HTML). */
function zzx_render_count(string $root, int $countId, array $who): string
{
    session_start();
    $_SESSION['admin_user_id'] = (int)$who['id'];
    $_SESSION['admin_username'] = (string)$who['username'];
    $_SESSION['admin_role'] = (string)$who['role'];
    $_SESSION['admin_full_name'] = (string)$who['full_name'];
    $_SESSION['admin_user'] = ['id' => (int)$who['id'], 'username' => (string)$who['username'], 'full_name' => (string)$who['full_name'], 'role' => (string)$who['role']];
    $_SESSION['admin_last_activity'] = time();
    session_write_close();
    $pdo = $GLOBALS['pdo'];
    $_GET = ['id' => (string)$countId];
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/admin/stock-count.php';
    // the page runs in this function's scope and sets its own variables ($lvl, $user ...): this function's own are named so they cannot clash
    $__zzxCwd = getcwd();
    $__zzxPage = $root . '/admin/stock-count.php';
    chdir($root . '/admin');
    $__zzxLvl = ob_get_level();
    $__zzxAcc = '';
    ob_start(function ($buf) use (&$__zzxAcc) { $__zzxAcc .= $buf; return ''; });     // whatever the page does with buffers, its output lands here
    try {
        include $__zzxPage;
    } catch (Throwable $__zzxE) {
        echo "\nZZX-RENDER-ERROR " . $__zzxE->getMessage() . ' @' . basename($__zzxE->getFile()) . ':' . $__zzxE->getLine();
    }
    while (ob_get_level() > $__zzxLvl) { ob_end_flush(); }      // inner buffers (the menu link filter of admin-header.php) pass their output down to ours
    chdir($__zzxCwd);
    return $__zzxAcc;
}

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/includes/import/commit-stock.php';
    require_once $root . '/scripts/tests/import_count_time_helper.php';   // "When was this counted?" for the commits (2026-11-25)
    $n = function (string $s) use ($run) { return 'ZZX ' . $run . ' ' . $s; };
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $measureOn = function_exists('rh_stock_measure_ready') && rh_stock_measure_ready($pdo);
    $ck('setup: count measures are available here (migration 088)', $measureOn);
    // a manager who did not do the import: the person who approves the opening count
    $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'manager', 1)")
        ->execute([substr(strtolower(str_replace(' ', '', $n('appr'))), 0, 50), strtolower(str_replace(' ', '', $n('appr'))) . '@example.invalid', $n('Approver')]);
    $approver = ['id' => (int)$pdo->lastInsertId(), 'username' => substr(strtolower(str_replace(' ', '', $n('appr'))), 0, 50), 'role' => 'manager', 'full_name' => $n('Approver')];
    // an item already in stock: no count measure, cost 12,000 a bottle
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Bar', 'bottle', 0, 12000, 0, 0)")->execute([$n('Rum')]);
    $rumId = (int)$pdo->lastInsertId();

    // ---- the file (a bar sheet): every kind of difference once, and one row imported exactly as written ----
    $fileName = 'zzx-bar-' . $run . '.csv';
    $p = $tmp . DIRECTORY_SEPARATOR . $fileName;
    $fh = fopen($p, 'w');
    foreach ([
        ['Item Name', 'Total Balance', 'Unit Price (MWK)', 'Selling price', 'Stock Value', 'Unit Bought As', 'Total Per Unit', 'Unit sold/Served by'],
        [$n('Lager'), '40', '48000', '5000', '80000', 'Crate', '24', 'Btls'],            // crate price / 24 = 2,000 a bottle (cost worked out); "Btls" read as bottle; menu price typed 5,500
        [$n('Gin'), '50', '25000', '2000', '1250000', 'Bottle', '1', 'Shot'],             // 50 shots = 2 bottles (25 a bottle, answered); the value column still says 50 bottles
        [$n('Rum'), '40', '12000', '1800', '', 'Bottle', '1', 'Shot'],                    // existing, quantity NOT ticked: left out, its measure not saved
        [$n('Tonic'), '6', 'MWK 2,500', '', '20000', 'Can', '1', 'Can'],                  // the price cell is fixed for you; the value column is wrong (15,000)
        [$n('Water'), '10', '500', '', '5000', 'Bottle', '1', 'Bottle'],                 // clean: imported exactly as the file says
        [$n('Broken'), '-3', '700', '', '', 'Bottle', '1', 'Bottle'],                    // a negative count: blocked, skipped by the person
    ] as $r) { fputcsv($fh, $r); }
    fclose($fh);
    $tmpFiles[] = $p;
    $cr = import_job_create($pdo, $p, $fileName, $admin, ['from_upload' => false]);
    if (empty($cr['ok'])) { throw new RuntimeException('upload: ' . json_encode($cr)); }
    $jid = (int)$cr['job_id'];
    $made[] = import_job_get($pdo, $jid)['stored_path'];
    $an = import_job_analyse($pdo, $jid);
    if (empty($an['ok'])) { throw new RuntimeException('analyse: ' . json_encode($an)); }

    // answers: the count of the drinks sold by the shot is in shots; 25 shots in a Gin bottle, 20 in a Rum bottle
    $answered = [];
    for ($i = 0; $i < 12; $i++) {
        $s = import_job_review_summary($pdo, $jid);
        $open = null;
        foreach ($s['questions'] as $q) { if ($q['blocking'] && $q['answer'] === null && $q['options']) { $open = $q; break; } }
        if (!$open) { break; }
        $keys = array_column($open['options'], 'key');
        if ($open['type'] === 'count-in') {
            $a = import_job_answer($pdo, $jid, $open['id'], 'measure', $admin);
        } elseif ($open['type'] === 'measure-n') {
            $a = import_job_answer($pdo, $jid, $open['id'], ['key' => 'n', 'pack_size' => strpos((string)$open['text'], $n('Gin')) !== false ? '25' : '20'], $admin);
        } elseif (in_array('skip', $keys, true) && strpos(json_encode($open), $n('Broken')) !== false) {
            $a = import_job_answer($pdo, $jid, $open['id'], 'skip', $admin);
        } else {
            $a = import_job_answer($pdo, $jid, $open['id'], in_array('per-unit', $keys, true) ? 'per-unit' : $keys[0], $admin);
        }
        $answered[] = $open['type'] . ':' . (string)($a['ok'] ?? '') . ':' . mb_substr((string)$open['text'], 0, 60);
    }
    // the broken row: skipped by the person ("Continue without them")
    $dry = import_job_skip($pdo, $jid, ['mode' => 'blocked', 'dry' => true], $admin);
    if (!empty($dry['ok']) && (int)($dry['n'] ?? $dry['rows'] ?? 0) > 0) {
        import_job_skip($pdo, $jid, ['mode' => 'blocked', 'dry' => false, 'fingerprint' => (string)($dry['fingerprint'] ?? '')], $admin);
    }
    // the menu: Lager put on the drinks menu at a price typed in step 3 (5,500; the file says 5,000)
    $dv = import_job_drinks_view($pdo, $jid, $admin, ['size' => 100]);
    $dRows = [];
    foreach ((array)($dv['rows'] ?? []) as $r) { $dRows[$r['name']] = $r; }
    $lag = $dRows[$n('Lager')] ?? null;
    $menuSteps = [];
    if ($lag) {
        if (($lag['menu'] ?? '') === '') { $menuSteps[] = import_job_drinks_set($pdo, $jid, $admin, ['mode' => 'menu', 'key' => $lag['key'], 'value' => 'drink'])['ok'] ?? null; }
        $menuSteps[] = import_job_drinks_set($pdo, $jid, $admin, ['mode' => 'category', 'key' => $lag['key'], 'value' => $n('Beers')])['ok'] ?? null;
        $menuSteps[] = import_job_drinks_set($pdo, $jid, $admin, ['mode' => 'price', 'key' => $lag['key'], 'value' => '5500'])['ok'] ?? null;
        $menuSteps[] = import_job_drinks_set($pdo, $jid, $admin, ['mode' => 'tick', 'key' => $lag['key'], 'on' => true])['ok'] ?? null;
    }
    $sum = import_job_review_summary($pdo, $jid);
    $ck('review: everything answered, Broken skipped, the import can be confirmed', !empty($sum['can_confirm']), json_encode([$answered, $sum['why_not'] ?? null, $menuSteps, $dry['error'] ?? null]));

    // ---- the commit ----
    $c = import_test_commit($pdo, $jid, $admin);
    $ck('commit: ok, opening count filed', !empty($c['ok']) && !empty($c['result']['opening_count_id']), json_encode([$c['error'] ?? null, $c['violations'] ?? null]));
    $cid = (int)($c['result']['opening_count_id'] ?? 0);
    $job = import_job_get($pdo, $jid);
    $res = json_decode((string)$job['result_json'], true) ?: [];
    $rep = $res['diff_report'] ?? null;
    $ck('stored: the report is kept with the job (result_json.diff_report)', is_array($rep) && isset($rep['kinds'], $rep['rows']), json_encode(array_keys($res)));
    $rep = is_array($rep) ? $rep : ['kinds' => [], 'rows' => []];
    $lnk = $pdo->prepare('SELECT import_job_id, notes, is_opening, status FROM stock_counts WHERE id = ?');
    $lnk->execute([$cid]);
    $cRow = $lnk->fetch(PDO::FETCH_ASSOC) ?: [];
    $ck('link: the opening count points at the job (stock_counts.import_job_id), notes still say "Import #id"', (int)($cRow['import_job_id'] ?? 0) === $jid && strpos((string)$cRow['notes'], 'Import #' . $jid) !== false
        && (int)($rep['count_id'] ?? 0) === $cid, json_encode($cRow));

    $by = [];
    foreach ($rep['rows'] as $r) { $by[$r['k']][] = $r; }
    $names = function (string $k) use ($by) { $o = array_map(function ($r) { return $r['item']; }, $by[$k] ?? []); sort($o); return $o; };
    $one = function (string $k, string $item) use ($by) { foreach ($by[$k] ?? [] as $r) { if ($r['item'] === $item) { return $r; } } return null; };
    $dump = json_encode(['kinds' => $rep['kinds'], 'rows' => $rep['rows']], JSON_UNESCAPED_UNICODE);

    $g = $one('count', $n('Gin'));
    $ck('count converted: exactly Gin, 50 shots -> 2 bottles, 25 shots in one bottle (your answer), with its cell', $names('count') === [$n('Gin')] && $g
        && stripos($g['file'], '50 shot') === 0 && strpos($g['used'], '2 bottles') === 0 && strpos($g['why'], '25 shots in one bottle') === 0 && preg_match('/!B3$/', $g['cell']) === 1, $dump);
    $l = $one('cost', $n('Lager'));
    $ck('cost worked out: exactly Lager, 48,000 for a crate of 24 = 2,000 per bottle, the price cell named', $names('cost') === [$n('Lager')] && $l && strpos($l['file'], '48,000') === 0
        && strpos($l['used'], '2,000') === 0 && strpos($l['why'], '/ 24 = 2,000') !== false && preg_match('/!C2$/', $l['cell']) === 1, $dump);
    $t = $one('fixed', $n('Tonic'));
    $lf = $one('fixed', $n('Lager'));
    $ck('fixed for you: the price cell "MWK 2,500" read as 2500 (Tonic) and the unit "Btls" read as bottle (Lager); a change of capitals only is not listed', $names('fixed') === [$n('Lager'), $n('Tonic')] && $lf && $lf['file'] === 'Btls' && $lf['used'] === 'bottle' && $t && $t['file'] === 'MWK 2,500' && $t['used'] === '2500' && preg_match('/!C5$/', $t['cell']) === 1, $dump);
    $rl = $one('left', $n('Rum'));
    $bl = $one('left', $n('Broken'));
    $ck('left out: the skipped Broken row and Rum (quantity not ticked), each with the reason', $names('left') === [$n('Broken'), $n('Rum')] && $rl && stripos($rl['why'], 'quantity was not ticked') !== false
        && $bl && stripos($bl['why'], 'Skipped') === 0 && $bl['used'] === 'Not imported', $dump);
    $vg = $one('value', $n('Gin'));
    $vt = $one('value', $n('Tonic'));
    $ck('value check: Gin (file 1,250,000 vs 2 x 25,000) and Tonic (20,000 vs 6 x 2,500), nothing for Lager or Water', $names('value') === [$n('Gin'), $n('Tonic')]
        && $vg && $vg['file'] === '1,250,000.00' && strpos($vg['used'], '= 50,000.00') !== false && stripos($vg['why'], 'count imported is 2') !== false
        && $vt && $vt['file'] === '20,000.00' && strpos($vt['used'], '= 15,000.00') !== false && preg_match('/!E5$/', $vt['cell']) === 1, $dump);
    $vv = $rep['value'] ?? [];
    $ck('value check totals: file 1,355,000 vs imported 150,000 over the 4 rows with a value (Lager, Gin, Tonic, Water), 2 differ',
        (int)($vv['compared'] ?? 0) === 4 && abs((float)($vv['file_total'] ?? 0) - 1355000) < 0.005 && abs((float)($vv['imported_total'] ?? 0) - 150000) < 0.005 && (int)($vv['differ'] ?? 0) === 2, json_encode($vv));
    $ansTypes = array_map(function ($r) { return $r['file']; }, $by['answer'] ?? []);
    $ck('answers: the count-in question (in shots), the two "how many shots" answers (typed 25, 20) and the skip of Broken, each with the choice', (int)($rep['kinds']['answer'] ?? 0) === 4 && count(array_filter($by['answer'] ?? [], function ($r) { return $r['used'] === 'Skip this row'; })) === 1
        && count(array_filter($by['answer'] ?? [], function ($r) { return stripos($r['used'], 'shot') !== false; })) >= 1
        && count(array_filter($by['answer'] ?? [], function ($r) { return $r['used'] === 'Typed: 25'; })) === 1
        && count(array_filter($by['answer'] ?? [], function ($r) { return $r['used'] === 'Typed: 20'; })) === 1, $dump);
    $m = $one('menu', $n('Lager'));
    $ck('menu: Lager put on the drinks menu at the typed 5,500 (the file said 5,000)', $names('menu') === [$n('Lager')] && $m && $m['file'] === '5,000.00' && strpos($m['used'], '5,500.00') === 0
        && stripos($m['why'], 'typed in step 3 instead of the file\'s 5,000.00') !== false && stripos($m['why'], 'new item') !== false, $dump);
    $mg = $one('measure', $n('Gin'));
    $mr = $one('measure', $n('Rum'));
    $ck('count measures: Gin saved (25 shots in one bottle), Rum not saved (its quantity is not ticked)', $names('measure') === [$n('Gin'), $n('Rum')] && $mg && stripos($mg['used'], '25 shots in one bottle') !== false
        && $mr && $mr['used'] === 'Not saved' && stripos($mr['why'], 'quantity is not ticked') !== false, $dump);
    $water = array_filter($rep['rows'], function ($r) use ($n) { return $r['item'] === $n('Water'); });
    $ck('a clean row (Water, imported exactly as written) is not listed anywhere', !$water, $dump);
    $ck('summary counts per kind match the rows, total = sum', $rep['kinds'] === ['count' => 1, 'cost' => 1, 'fixed' => 2, 'left' => 2, 'value' => 2, 'answer' => 4, 'menu' => 1, 'measure' => 2]
        && (int)$rep['total'] === 15 && (int)$rep['stored'] === 15 && count($rep['rows']) === 15, json_encode($rep['kinds']));
    $ck('count value: 4 lines (Lager 40 x 2,000, Gin 2 x 25,000, Tonic 6 x 2,500, Water 10 x 500) = 150,000', (int)($rep['count_lines'] ?? 0) === 4 && abs((float)($rep['count_value'] ?? 0) - 150000) < 0.005, json_encode([$rep['count_lines'] ?? null, $rep['count_value'] ?? null]));

    // ---- frozen: changing the items afterwards does not change the stored report ----
    $before = md5(json_encode($rep));
    $pdo->prepare('UPDATE stock_ingredients SET cost_per_unit = 99999, name = ? WHERE name = ?')->execute([$n('Gin renamed'), $n('Gin')]);
    $again = import_diff_for_count($pdo, ['id' => $cid, 'is_opening' => 1, 'import_job_id' => $jid]);
    $ck('frozen: renaming Gin and changing its cost later leaves the stored report exactly as it was', $again && md5(json_encode($again['report'])) === $before);
    $pdo->prepare('UPDATE stock_ingredients SET cost_per_unit = 25000, name = ? WHERE name = ?')->execute([$n('Gin'), $n('Gin renamed')]);
    $ck('no link, no report: an old count (no import_job_id) and a count whose job is not committed give nothing', import_diff_for_count($pdo, ['id' => $cid, 'is_opening' => 1, 'import_job_id' => null]) === null
        && import_diff_for_count($pdo, ['id' => $cid, 'is_opening' => 1, 'import_job_id' => $jid + 999999]) === null);

    // ---- the result screen gets the summary only ----
    $sumOnly = import_diff_summary($rep);
    $ck('result screen summary: counts, totals and labels, no rows', is_array($sumOnly) && !isset($sumOnly['rows']) && (int)$sumOnly['total'] === 15 && isset($sumOnly['labels']['value'][0]));

    // ---- downloads ----
    $csv = import_diff_export($rep, 'csv');
    $xl = import_diff_export($rep, 'xlsx');
    $ck('download CSV: header + one line per difference + summary; file named after the upload', !empty($csv['ok']) && strpos($csv['body'], 'What,Item,"Cell in your file","In your file",Imported,Why') !== false
        && substr_count($csv['body'], $n('Gin')) >= 3 && strpos($csv['name'], 'differences import ' . $jid) !== false, substr((string)($csv['body'] ?? ''), 0, 300));
    $ck('download Excel: a real .xlsx (zip) with the differences', !empty($xl['ok']) && substr((string)$xl['body'], 0, 2) === 'PK' && strlen((string)$xl['body']) > 1500, (string)($xl['error'] ?? ''));

    // ---- the count page, as the manager who approves it ----
    $html = zzx_render_count($root, $cid, $approver);
    $ok = function (string $needle) use ($html) { return strpos($html, $needle) !== false; };
    $ck('count page (approver): the fold "Differences from the uploaded file (<file>)" is shown, open (submitted)', $ok('Differences from the uploaded file (' . $fileName . ')') && preg_match('/<details class="sc-diff" id="scDiff"[^>]* open>/', $html) === 1,
        substr(strip_tags((string)preg_replace('#<script.*?</script>#s', '', $html)), 0, 600) . (strpos($html, 'ZZX-RENDER-ERROR') !== false ? substr($html, strpos($html, 'ZZX-RENDER-ERROR'), 300) : ''));
    $ck('count page: one chip per kind (All + 8), each filtering to its rows; 15 rows in the table', preg_match_all('/class="sc-diff__chip( is-on)?"/', $html) === 9 && substr_count($html, '<tr data-k="') === 15 && $ok('data-k="value"'));
    $ck('count page: a "?" helper on every column, downloads offered (Excel / CSV)', preg_match_all('/<th>[^<]+<i class="help"/', $html) >= 6 && $ok('diff_export=xlsx') && $ok('diff_export=csv'));
    $ck('count page: the value totals and the count value are stated', $ok('adds up to') && $ok('1,355,000.00') && $ok('150,000.00'));
    $ck('count page: no PHP error in the page', !$ok('ZZX-RENDER-ERROR') && !$ok('Fatal error') && !$ok('Warning:</b>'));
    if ($preview !== '' && is_dir($preview)) {
        @file_put_contents($preview . DIRECTORY_SEPARATOR . 'count.html', $html);
        @file_put_contents($preview . DIRECTORY_SEPARATOR . 'result.json', json_encode(['ok' => true, 'state' => 'committed', 'file' => $fileName, 'job' => $jid, 'count' => $cid,
            'result' => (function ($r) { $r['diff'] = import_diff_summary($r['diff_report'] ?? null); unset($r['diff_report']); return $r; })($res)]));
    }
} catch (Throwable $e) {
    $ck('scenarios ran without an exception', false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
    foreach ($made as $sp) { if ($sp) { @unlink($root . '/storage/imports/' . basename((string)$sp)); } }
}
while (ob_get_level() > 1) { ob_end_clean(); }
echo json_encode(['checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
