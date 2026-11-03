<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Scenarios for scripts/tests/import_stock_test.php (run by page_harness.php: ONE outer transaction that can never commit and is
 * rolled back at the end, DDL skipped). Prints one JSON line: {"checks":[[name, ok, detail], ...]}.
 * Every row it makes is named 'ZZT <run> ...'. Staged files (outside the database) are deleted in the finally block.
 */
$run = (string)($_POST['run'] ?? 'X');
$checks = [];
$ck = function (string $n, $c, string $d = '') use (&$checks) { $checks[] = [$n, (bool)$c, $d]; };
$made = [];                  // staged file names to delete at the end
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_stock_' . getmypid();
@mkdir($tmp);
$tmpFiles = [];

try {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/includes/import/commit-stock.php';
    require_once $root . '/scripts/tests/fixtures/import/build_fixture.php';
    $n = function (string $s) use ($run) { return 'ZZT ' . $run . ' ' . $s; };
    $put = function (string $bytes, string $name) use ($tmp, &$tmpFiles) {
        $p = $tmp . DIRECTORY_SEPARATOR . count($tmpFiles) . '_' . $name;
        file_put_contents($p, $bytes);
        $tmpFiles[] = $p;
        return $p;
    };
    $setSwitch = function ($v) use ($pdo) {
        if ($v === null) {
            $pdo->exec("DELETE FROM site_settings WHERE setting_key = 'data_import_enabled'");
        } else {
            $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', ?, 'import') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
        }
    };
    $mkUser = function (string $role) use ($pdo, $n) {
        $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, ?, 1)")
            ->execute([substr($n($role), 0, 90), strtolower(preg_replace('/\W+/', '', $n($role))) . '@example.invalid', $n($role), $role]);
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
    $csv = function (array $rows) { // header + rows -> csv text
        $fh = fopen('php://memory', 'w+');
        fputcsv($fh, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
        foreach ($rows as $r) { fputcsv($fh, $r); }
        rewind($fh);
        return stream_get_contents($fh);
    };
    $answerAll = function (int $jobId, int $uid) use ($pdo) {  // answers every open blocking question the safe way
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
            if ($open['type'] === 'conflict') { $pick = $keys[0]; }
            if (in_array('skip', $keys, true) && ($open['bad'] || $open['type'] === 'bad-value')) { $pick = 'skip'; }
            $r = import_job_answer($pdo, $jobId, $open['id'], $pick, $uid);
            if (!$r['ok']) { return ['stuck' => $open['type'], 'err' => $r['error']]; }
        }
        return ['stuck' => 'loop'];
    };

    $admin = (int)($_SESSION['admin_user_id'] ?? 0);
    $mgr = $mkUser('manager');
    $rec = $mkUser('receptionist');

    // ---- permission matrix + kill switch -----------------------------------------------------------------
    $all = getAllPermissions();
    $ck('permission: data_import is a defined permission with a plain label', isset($all['data_import']) && $all['data_import']['label'] === 'Import' && stripos($all['data_import']['description'], 'Excel/CSV') !== false);
    $ck('permission: admin has data_import', hasPermission($admin, 'data_import'));
    $ck('permission: manager has data_import by default', hasPermission($mgr, 'data_import'));
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'bookings', 1)")->execute([$mgr]);
    $ck('permission: a manager who already has explicit saved permissions still has it (no backfill rows needed)', hasPermission($mgr, 'data_import'));
    $ck('permission: receptionist does NOT have data_import', !hasPermission($rec, 'data_import'));
    $others = 0;
    foreach (array_keys(getAllRoles()) as $role) {
        if (!in_array($role, ['admin', 'manager'], true) && in_array('data_import', getDefaultPermissionsForRole($role), true)) { $others++; }
    }
    $ck('permission: nobody except admin and manager gets it by default', $others === 0);
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'data_import', 0)")->execute([$mgr]);
    $ck('permission: it can be taken away from one manager', !hasPermission($mgr, 'data_import'));
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = 'data_import'")->execute([$mgr]);
    $ck('permission: the page maps to data_import', getPermissionForPage('data-import.php') === 'data_import');
    $setSwitch('0');
    $ck('kill switch: OFF -> helper false (even for admin)', rh_import_enabled($pdo) === false && !rh_import_can_use($admin, $pdo));
    $setSwitch(null);
    $ck('kill switch: setting missing -> false (fail-closed)', rh_import_enabled($pdo) === false);
    $setSwitch('yes');
    $ck('kill switch: odd value -> false', rh_import_enabled($pdo) === false);
    $setSwitch('1');
    $ck('kill switch: ON -> true; admin and manager may use Import, receptionist may not', rh_import_enabled($pdo) && rh_import_can_use($admin, $pdo) && rh_import_can_use($mgr, $pdo) && !rh_import_can_use($rec, $pdo));
    $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'stock_management', 0)")->execute([$mgr]);
    $ck('permission: data_import without stock_management gives no stock target', !rh_import_can_use($mgr, $pdo));
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = 'stock_management'")->execute([$mgr]);
    $ck('kill switch is seeded OFF by the schema', rh_import_setting_defaults()['data_import_enabled'] === '0');

    // ---- no staff target ----------------------------------------------------------------------------------
    $col = $pdo->query("SHOW COLUMNS FROM import_jobs LIKE 'target'")->fetch(PDO::FETCH_ASSOC);
    $staffPerm = array_filter(array_keys($all), function ($k) { return (bool)preg_match('/staff.*import|import.*staff/i', $k); });
    $staffAlias = (int)$pdo->query("SELECT COUNT(*) FROM import_aliases WHERE term LIKE '%staff list%' OR maps_to LIKE '%staff%'")->fetchColumn();
    $ck('no staff-list target: no such permission, no alias, target column is just the stock default', !$staffPerm && $staffAlias === 0 && strpos((string)$col['Default'], 'stock') !== false);

    // ---- aliases from the database behave like the seed ---------------------------------------------------
    $dbAl = import_load_aliases($pdo);
    $seed = import_default_aliases();
    $same = true;
    foreach (['units', 'section_words', 'total_words', 'summary_phrases', 'skip_sheet_words', 'noise_tokens', 'derived'] as $k) { $same = $same && ($dbAl[$k] === $seed[$k]); }
    foreach ($seed['fields'] as $f => $ph) { $same = $same && (($dbAl['fields'][$f] ?? null) === $ph); }
    $ck('aliases: the table was seeded from default-aliases.php and loads back identical', $same);
    $fix = $root . '/scripts/tests/fixtures/import/synthetic_stock.xlsx';
    $a1 = import_analyze_file($fix, 'synthetic_stock.xlsx', $seed, []);
    $a2 = import_analyze_file($fix, 'synthetic_stock.xlsx', $dbAl, []);
    $ck('aliases: the engine gives the same fixture totals with database aliases', $a1['totals'] === $a2['totals'] && $a1['totals']['items_read'] === 20, json_encode($a2['totals']));

    // ---- upload rules --------------------------------------------------------------------------------------
    $fixBytes = file_get_contents($fix);
    $r = $upload($put($fixBytes, 'a.xlsx'), 'big.xlsx', $admin, ['max_bytes' => 1000]);
    $ck('upload: over the size cap is refused', !$r['ok'] && stripos($r['error'], 'too large') !== false, $r['error']);
    $r = $upload($put('x', 'a.exe'), 'evil.exe', $admin);
    $ck('upload: a wrong extension is refused', !$r['ok'] && stripos($r['error'], 'cannot be imported') !== false, $r['error']);
    $r = $upload($put('x', 'a.docx'), 'list.docx', $admin);
    $ck('upload: Word is "not supported yet"', !$r['ok'] && stripos($r['error'], 'not supported yet') !== false, $r['error']);
    $r = $upload($put('%PDF-1.4', 'a.pdf'), 'list.pdf', $admin);
    $ck('upload: PDF is "not supported yet"', !$r['ok'] && stripos($r['error'], 'not supported yet') !== false, $r['error']);
    $r = $upload($put('x', 'a.xls'), 'old.xls', $admin);
    $ck('upload: .xls asks for a re-save as .xlsx', !$r['ok'] && stripos($r['error'], 'xlsx') !== false, $r['error']);
    $r = $upload($put("just text, not a zip", 'b.xlsx'), 'fake.xlsx', $admin);
    $ck('upload: a .xlsx that is not a zip (bad magic bytes) is refused', !$r['ok'] && stripos($r['error'], 'not an Excel') !== false, $r['error']);
    $r = $upload($put($fixBytes, 'c.csv'), 'sheet.csv', $admin);
    $ck('upload: a .csv that is really a zip is refused', !$r['ok'] && stripos($r['error'], 'not plain text') !== false, $r['error']);
    $r = $upload($put("a,b\n1,\0\n", 'd.csv'), 'bin.csv', $admin);
    $ck('upload: a .csv with binary bytes is refused', !$r['ok'], $r['error']);
    $r = $upload($put('', 'e.csv'), 'empty.csv', $admin);
    $ck('upload: an empty file is refused', !$r['ok']);
    $r = $upload($put($fixBytes, 'f.xlsx'), '../../etc/pass wd.xlsx', $admin);
    $j = $r['ok'] ? import_job_get($pdo, $r['job_id']) : null;
    $dirOk = $j && import_job_file_path($j) !== '' && strpos(import_job_file_path($j), realpath(import_storage_dir())) === 0;
    $ck('upload: a good file is staged inside storage/imports under a random name with no extension, original name cleaned', $r['ok'] && $dirOk && preg_match('/^[a-f0-9]{32}\.bin$/', $j['stored_path']) && strpos($j['original_name'], '/') === false && $j['sha256'] === hash('sha256', $fixBytes), json_encode($r));
    $ck('upload: the folder denies web access and has an index file', is_file(import_storage_dir() . '/.htaccess') && is_file(import_storage_dir() . '/index.html') && strpos(file_get_contents(import_storage_dir() . '/.htaccess'), 'denied') !== false);
    $fixJob = $r['job_id'];
    $bomb = fx_zip(['xl/workbook.xml' => ['data' => '<x/>', 'usize' => 200 * 1024 * 1024]]);
    $r = $upload($put($bomb, 'bomb.xlsx'), 'bomb.xlsx', $admin);
    if ($r['ok']) {
        $an = import_job_analyse($pdo, $r['job_id']);
        $jb = import_job_get($pdo, $r['job_id']);
        $ck('upload: a zip bomb is refused when it is read, the job fails and nothing is proposed', !$an['ok'] && stripos($an['error'], 'zip bomb') !== false && $jb['status'] === 'failed' && $jb['analysis_json'] === null, $an['error']);
    } else {
        $ck('upload: a zip bomb is refused', stripos($r['error'], 'zip bomb') !== false, $r['error']);
    }

    // ---- analysis of the synthetic workbook and the review gate -------------------------------------------
    $an = import_job_analyse($pdo, $fixJob);
    $sum = import_job_review_summary($pdo, $fixJob);
    $ck('analysis: the synthetic workbook reads (20 items, 3 sheets imported)', $an['ok'] && $sum['ok'] && $sum['totals']['items_read'] === 20 && $sum['totals']['sheets_imported'] === 3, json_encode($sum['totals'] ?? $an));
    $ck('review: confirm is blocked while questions are open', !$sum['can_confirm'] && !import_job_can_confirm($pdo, $fixJob) && ($sum['counts']['needs-answer'] + $sum['counts']['blocked']) > 0, json_encode($sum['counts']));
    $types = array_count_values(array_column($sum['questions'], 'type'));
    $ck('review: the engine questions and the unit questions are all listed', ($types['conflict'] ?? 0) === 1 && ($types['case-price'] ?? 0) === 1 && ($types['duplicate-batch-clash'] ?? 0) === 1 && ($types['bad-value'] ?? 0) === 1 && ($types['no-unit'] ?? 0) >= 1, json_encode($types));
    $byType = [];
    foreach ($sum['questions'] as $q) { $byType[$q['type']][] = $q; }
    $conf = $byType['conflict'][0];
    $r = import_job_answer($pdo, $fixJob, $conf['id'], 'use:999999', $admin);
    $ck('answers: a value that is not one of the question\'s choices is rejected', !$r['ok']);
    $r = import_job_answer($pdo, $fixJob, 'q-nonexistent', 'skip', $admin);
    $ck('answers: a question id that is not in this job is rejected', !$r['ok']);
    $cp = $byType['case-price'][0];
    $r1 = import_job_answer($pdo, $fixJob, $cp['id'], 'per-case', $admin);
    $r2 = import_job_answer($pdo, $fixJob, $cp['id'], ['key' => 'per-case', 'pack_size' => 1], $admin);
    $ck('answers: "per case" needs a real pack size (missing or 1 is rejected)', !$r1['ok'] && !$r2['ok']);
    $ck('answers: nothing was stored by the rejected answers', import_job_review_summary($pdo, $fixJob)['questions'] !== [] && !import_job_can_confirm($pdo, $fixJob));
    // tamper: a browser (or anyone with the database) marking every row ready and every question answered changes nothing
    $jt = import_job_get($pdo, $fixJob);
    $aj = json_decode($jt['analysis_json'], true);
    foreach ($aj['sheets'] as &$shT) { foreach ($shT['rows'] as &$rowT) { $rowT['status'] = 'ready'; } unset($rowT); }
    unset($shT);
    foreach ($aj['questions'] as &$qT) { $qT['answer'] = 'skip'; } unset($qT);
    $pdo->prepare('UPDATE import_jobs SET analysis_json = ? WHERE id = ?')->execute([json_encode($aj), $fixJob]);
    $ck('tamper: statuses and answers written into the stored analysis are ignored (still cannot confirm)', !import_job_can_confirm($pdo, $fixJob));
    $cm = import_commit_stock($pdo, $fixJob, $admin);
    $ck('tamper: commit refuses while questions are open', !$cm['ok'] && !$cm['already'], $cm['error']);
    $pdo->prepare('UPDATE import_jobs SET analysis_json = ? WHERE id = ?')->execute([$jt['analysis_json'], $fixJob]);
    $ck('csv: formula-looking cells are prefixed so a spreadsheet shows them as text', import_csv_safe('=cmd|x') === "'=cmd|x" && import_csv_safe('+1') === "'+1" && import_csv_safe('@a') === "'@a" && import_csv_safe('Plain') === 'Plain');
    $cx = import_job_cancel($pdo, $fixJob, $admin);
    $jc = import_job_get($pdo, $fixJob);
    $ck('cancel: the job is cancelled and the staged file deleted', $cx['ok'] && $jc['status'] === 'cancelled' && $jc['stored_path'] === null);

    // ---- a real commit (made-up names) --------------------------------------------------------------------
    $exId = (function () use ($pdo, $n) {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point, notes) VALUES (?, 'Pantry', 'bottle', 2, 700, 5, 2, 'keep this note')")->execute([$n('Existing')]);
        return (int)$pdo->lastInsertId();
    })();
    $rows1 = [
        [$n('Section Beers'), '', '', '', '', '', ''],
        [$n('Alpha'), '', '10', 'Bottles', '1,000', '20', ''],
        [$n('Beta'), '', '5', 'kg', '', '', ''],
        [$n('Gamma'), 'B1', '3', 'bottles', '500', '9', '2031-05-01'],
        [$n('Gamma'), 'B2', '4', 'bottles', '500', '9', '2031-06-01'],
        [$n('Delta'), '', '2', 'packets', '0', '', ''],
        [$n('Existing'), '', '50', 'Bottle', '900', '99', ''],
    ];
    $up = $upload($put($csv($rows1), 'main.csv'), 'zzt-main-' . $run . '.csv', $admin);
    $jid = (int)$up['job_id'];
    $an = import_job_analyse($pdo, $jid);
    $s = $answerAll($jid, $admin);
    $ck('commit: the made-up list analyses and every question can be answered', $an['ok'] && !empty($s['can_confirm']), json_encode($s['stuck'] ?? ($s['why_not'] ?? $s['counts'] ?? $an)));
    $items = [];
    foreach (($s['items'] ?? []) as $it) { $items[$it['typed_name']] = $it; }
    $ex = $items[$n('Existing')] ?? null;
    $ck('review: the existing ingredient is matched and shows old -> new, with nothing ticked', $ex && $ex['action'] === 'update' && $ex['ticked'] === [] && isset($ex['diff']['cost']) && abs($ex['diff']['cost'][0] - 700) < 0.001 && abs($ex['diff']['cost'][1] - 900) < 0.001, json_encode($ex));
    $ck('review: zero price is treated as no price; stock with no cost is not counted', isset($items[$n('Delta')]) && $items[$n('Delta')]['cost'] === null && isset($items[$n('Beta')]) && !$items[$n('Beta')]['in_count'] && !empty($items[$n('Beta')]['warnings']));
    $ck('review: category comes from the section row', ($items[$n('Alpha')]['category'] ?? '') === $n('Section Beers'), (string)($items[$n('Alpha')]['category'] ?? ''));
    $ck('review: Gamma B1 + B2 are one item with two expiry batches and balance 7', isset($items[$n('Gamma')]) && count($items[$n('Gamma')]['batches']) === 2 && abs($items[$n('Gamma')]['balance'] - 7) < 0.0001);
    $csvOut = import_job_csv($pdo, $jid);
    $ck('csv: "what will be imported" lists the items', strpos($csvOut, $n('Alpha')) !== false && strpos($csvOut, 'Create new ingredient') !== false);

    $before = [
        'ing' => (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn(),
        'counts' => (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%'")->fetchColumn(),
    ];
    $pre = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn();
    $cf = import_commit_stock($pdo, $jid, $admin, ['fail_after_apply' => true]);
    $ck('rollback: a forced failure after the writes leaves nothing behind', !$cf['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn() === $pre
        && (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%'")->fetchColumn() === $before['counts'] && import_job_get($pdo, $jid)['status'] !== 'committed', $cf['error']);
    $setSwitch('0');
    $cOff = import_commit_stock($pdo, $jid, $admin);
    $ck('commit: refused while the kill switch is OFF', !$cOff['ok']);
    $setSwitch('1');
    $cRec = import_commit_stock($pdo, $jid, $rec);
    $ck('commit: refused for a person without data_import', !$cRec['ok']);

    $c1 = import_commit_stock($pdo, $jid, $admin);
    $res = $c1['result'] ?? [];
    $ck('commit: ok, 4 ingredients created (Alpha, Beta, Gamma, Delta), none updated', $c1['ok'] && !$c1['already'] && ($res['created'] ?? -1) === 4 && ($res['updated'] ?? -1) === 0, json_encode($c1));
    $ing = [];
    foreach ($pdo->query("SELECT * FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchAll(PDO::FETCH_ASSOC) as $x) { $ing[$x['name']] = $x; }
    $ck('commit: exactly the expected ingredients exist (5 = 4 new + the existing one)', count($ing) === 5);
    $al = $ing[$n('Alpha')] ?? [];
    $ck('commit: Alpha has the sheet\'s category, unit, cost and par; its stock is 0 until the count is approved', ($al['category'] ?? '') === $n('Section Beers') && ($al['unit'] ?? '') === 'bottle' && abs(($al['cost_per_unit'] ?? 0) - 1000) < 0.001 && abs(($al['par_level'] ?? 0) - 20) < 0.001 && abs(($al['current_quantity'] ?? 1)) < 0.0001, json_encode($al));
    $d = $ing[$n('Delta')] ?? [];
    $ck('commit: a zero price becomes a blank cost (0), not a price', abs(($d['cost_per_unit'] ?? 1)) < 0.0001);
    $e0 = $ing[$n('Existing')] ?? [];
    $ck('commit: the existing ingredient was NOT overwritten (cost, par, stock, category, notes)', abs($e0['cost_per_unit'] - 700) < 0.001 && abs($e0['par_level'] - 5) < 0.001 && abs($e0['current_quantity'] - 2) < 0.001 && $e0['category'] === 'Pantry' && $e0['notes'] === 'keep this note', json_encode($e0));
    $cnt = $pdo->prepare("SELECT c.id, c.status, c.is_opening, c.notes, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id) AS nlines, (SELECT COUNT(*) FROM stock_opening_batches b WHERE b.count_id = c.id) AS nbatches FROM stock_counts c WHERE c.id = ?");
    $cnt->execute([(int)($res['opening_count_id'] ?? 0)]);
    $cr = $cnt->fetch(PDO::FETCH_ASSOC) ?: [];
    $ck('commit: ONE opening count, left SUBMITTED, 2 lines (Alpha, Gamma) and 2 staged expiry batches', ($cr['status'] ?? '') === 'submitted' && (int)$cr['is_opening'] === 1 && (int)$cr["nlines"] === 2 && (int)$cr["nbatches"] === 2 && strpos($cr['notes'], 'Stock sheet import') === 0, json_encode($cr));
    $ck('commit: no stock batches or quantities were written directly', (int)$pdo->query("SELECT COUNT(*) FROM stock_batches b INNER JOIN stock_ingredients i ON i.id = b.ingredient_id WHERE i.name LIKE 'ZZT $run %'")->fetchColumn() === 0);
    $ck('commit: stock-with-no-cost items are reported', in_array($n('Beta'), $res['stock_not_counted_no_cost'] ?? [], true));
    $jobAfter = import_job_get($pdo, $jid);
    $ck('commit: the job is committed with a result, and its staged file is gone', $jobAfter['status'] === 'committed' && $jobAfter['stored_path'] === null && (int)$jobAfter['committed_by'] === $admin && json_decode($jobAfter['result_json'], true)['created'] === 4);
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'stock_import' AND details LIKE 'Import #$jid %'")->fetchColumn();
    $ck('commit: an audit log row was written', $aud === 1);
    $c2 = import_commit_stock($pdo, $jid, $admin);
    $ck('commit: a second call is a no-op returning the stored result', $c2['ok'] && $c2['already'] && ($c2['result']['created'] ?? 0) === 4
        && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn() === 5
        && (int)$pdo->query("SELECT COUNT(*) FROM stock_counts WHERE notes LIKE 'Stock sheet import%'")->fetchColumn() === $before['counts'] + 1);
    $re = $upload($put($csv($rows1), 'again.csv'), 'zzt-main-' . $run . '.csv', $admin);
    $ck('re-upload: the same file again is blocked with "already imported on <date> by <name>"', !$re['ok'] && stripos($re['error'], 'already imported on') !== false && $re['duplicate'] !== null, $re['error']);
    $re2 = $upload($put($csv($rows1), 'again2.csv'), 'x.csv', $mgr, ['allow_reimport' => true]);
    $ck('re-upload: a manager ticking "import again" is still refused (admin only, checked on the server)', !$re2['ok']);
    $re3 = $upload($put($csv($rows1), 'again3.csv'), 'x.csv', $admin, ['allow_reimport' => true]);
    $ck('re-upload: an administrator can import it again on purpose', $re3['ok']);

    // ---- ticked fields change; unticked stay; learned correction ---------------------------------------
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 0, 700, 5, 2)")->execute([$n('Tickme')]);
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 0, 650, 5, 2)")->execute([$n('Heineken')]);
    $rows2 = [[$n('Tickme'), '', '30', 'bottle', '900', '77', ''], [$n('Heinken'), '', '4', 'bottle', '660', '', '']];
    $u2 = $upload($put($csv($rows2), 'tick.csv'), 'zzt-tick-' . $run . '.csv', $admin);
    $j2 = (int)$u2['job_id'];
    import_job_analyse($pdo, $j2);
    $s2 = import_job_review_summary($pdo, $j2);
    $typo = null;
    foreach ($s2['questions'] as $q) { if ($q['type'] === 'possible-typo' && ($q['suggestion']['name'] ?? '') === $n('Heineken')) { $typo = $q; } }
    $ck('matcher: a typo against an existing ingredient is only a suggestion (default: keep as new)', $typo !== null && !$typo['blocking'] && $typo['answer'] === null && in_array('use:' . $n('Heineken'), array_column($typo['options'], 'key'), true));
    $ra = import_job_answer($pdo, $j2, $typo['id'], 'use:' . $n('Heineken'), $admin);
    $bad = import_job_set_update_fields($pdo, $j2, rh_ssi_key($n('Tickme')), ['unit'], $admin);
    $ok = import_job_set_update_fields($pdo, $j2, rh_ssi_key($n('Tickme')), ['cost'], $admin);
    $ck('ticks: only the allowed fields can be ticked', $ra['ok'] && !$bad['ok'] && $ok['ok']);
    $cacheKey = defined('RH_STOCK_DASH_CACHE_KEY') ? RH_STOCK_DASH_CACHE_KEY : 'stock_dashboard_metrics_v5';
    $cacheWorks = function_exists('setCache') && function_exists('getCache') && setCache($cacheKey, ['zzt' => 1], 600) && getCache($cacheKey, null) !== null;
    $c3 = import_commit_stock($pdo, $j2, $admin);
    $ck('commit: the stock dashboard cache is dropped after the commit (checked when the cache works in this process)', !$cacheWorks || getCache($cacheKey, null) === null);
    $costLogs = $pdo->prepare("SELECT details FROM admin_activity_log WHERE action = 'stock_import_cost' AND details LIKE ?");
    $costLogs->execute(['Import #' . $j2 . ': cost of "' . $n('Tickme') . '"%']);
    $cl = $costLogs->fetchAll(PDO::FETCH_COLUMN);
    $costLogs->execute(['Import #' . $j2 . ': cost of "' . $n('Heineken') . '"%']);
    $ck('commit: each changed cost is logged old -> new (700 to 900 for Tickme); an unchanged one is not', $c3['ok'] && count($cl) === 1 && strpos($cl[0], 'from 700.0000 to 900.0000') !== false && !$costLogs->fetchAll(PDO::FETCH_COLUMN), json_encode($cl));
    $tk = $pdo->prepare('SELECT cost_per_unit, par_level, current_quantity FROM stock_ingredients WHERE name = ?');
    $tk->execute([$n('Tickme')]);
    $t0 = $tk->fetch(PDO::FETCH_ASSOC);
    $tk->execute([$n('Heineken')]);
    $h0 = $tk->fetch(PDO::FETCH_ASSOC);
    $ck('ticks: the ticked cost changed, the unticked par and quantity did not; no count line without the quantity tick', $c3['ok'] && abs($t0['cost_per_unit'] - 900) < 0.001 && abs($t0['par_level'] - 5) < 0.001 && abs($t0['current_quantity']) < 0.0001 && ($c3['result']['count_lines'] ?? -1) === 0, json_encode($c3['result'] ?? $c3['error']));
    $ck('matcher: "use existing Heineken" matched the existing item and created no "Heinken"', abs($h0['cost_per_unit'] - 650) < 0.001 && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name = " . $pdo->quote($n('Heinken')))->fetchColumn() === 0);
    $learned = import_load_name_corrections($pdo);
    $ck('training: the correction was saved as a hotel alias (only after the commit)', ($learned[import_norm_text($n('Heinken'))] ?? '') === $n('Heineken'));
    $u3 = $upload($put($csv([[$n('Heinken'), '', '6', 'bottle', '660', '', '']]), 'learn.csv'), 'zzt-learn-' . $run . '.csv', $admin);
    import_job_analyse($pdo, (int)$u3['job_id']);
    $s3 = import_job_review_summary($pdo, (int)$u3['job_id']);
    $auto = false;
    foreach ($s3['questions'] as $q) { if ($q['type'] === 'possible-typo' && $q['auto_filled'] && $q['answer'] === 'use:' . $n('Heineken')) { $auto = true; } }
    $ck('training: next time the learned correction is pre-filled and visible (not silent)', $auto);

    // ---- cost change warning: ticked cost on an item that already has stock -------------------------------
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 12, 500, 5, 2)")->execute([$n('Stocky')]);
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'bottle', 0, 500, 5, 2)")->execute([$n('Emptyy')]);
    $u4 = $upload($put($csv([[$n('Stocky'), '', '5', 'bottle', '800.5', '', ''], [$n('Emptyy'), '', '5', 'bottle', '600', '', '']]), 'costwarn.csv'), 'zzt-costwarn-' . $run . '.csv', $admin);
    $j4 = (int)$u4['job_id'];
    import_job_analyse($pdo, $j4);
    $w0 = import_job_review_summary($pdo, $j4);
    $find = function (array $sum, string $name) { foreach ($sum['items'] as $x) { if ($x['match'] && $x['match']['name'] === $name) { return $x; } } return null; };
    $ck('cost warning: nothing ticked => no warning, but the on-hand figures are there', array_key_exists('cost_stock_warning', $find($w0, $n('Stocky')) ?? []) && $find($w0, $n('Stocky'))['cost_stock_warning'] === null && abs(($find($w0, $n('Stocky'))['on_hand']['qty'] ?? 0) - 12) < 0.0001);
    import_job_set_update_fields($pdo, $j4, rh_ssi_key($n('Stocky')), ['cost'], $admin);
    import_job_set_update_fields($pdo, $j4, rh_ssi_key($n('Emptyy')), ['cost'], $admin);
    $w1 = import_job_review_summary($pdo, $j4);
    $ws = $find($w1, $n('Stocky'))['cost_stock_warning'] ?? null;
    $ck('cost warning: ticked cost on an item with stock names the item, quantity, unit, old and new cost', is_string($ws) && strpos($ws, $n('Stocky') . ' already has 12 bottle in stock') !== false && strpos($ws, 'from 500 to 800.5') !== false && strpos($ws, 'before the opening count is approved') !== false, (string)$ws);
    $ck('cost warning: an item with no stock gets none', array_key_exists('cost_stock_warning', $find($w1, $n('Emptyy')) ?? []) && $find($w1, $n('Emptyy'))['cost_stock_warning'] === null && ($find($w1, $n('Emptyy'))['on_hand']['has_stock'] ?? true) === false);
    $ck('cost warning: it is a warning only, the import can still be confirmed', !empty($w1['can_confirm']) && import_job_can_confirm($pdo, $j4));
    $c4 = import_commit_stock($pdo, $j4, $admin);
    $costLogs->execute(['Import #' . $j4 . ': cost of "' . $n('Stocky') . '"%']);
    $sk = $pdo->prepare('SELECT cost_per_unit, current_quantity FROM stock_ingredients WHERE name = ?');
    $sk->execute([$n('Stocky')]);
    $s0 = $sk->fetch(PDO::FETCH_ASSOC);
    $ck('cost warning: commit behaviour is unchanged (cost changed, stock untouched, cost change logged)', $c4['ok'] && abs($s0['cost_per_unit'] - 800.5) < 0.001 && abs($s0['current_quantity'] - 12) < 0.0001 && count($costLogs->fetchAll(PDO::FETCH_COLUMN)) === 1, json_encode($c4['error'] ?? $c4['result'] ?? ''));

    // ---- cleanup of staged files, purge ---------------------------------------------------------------
    $pg = import_purge_staged($pdo, true);
    $ck('purge: runs and reports counts', is_array($pg) && isset($pg['files_deleted']));
} catch (Throwable $e) {
    $ck('child ran without an exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made as $m) { if ($m) { import_delete_stored((string)$m); } }
    foreach ($tmpFiles as $f) { @unlink($f); }
    @rmdir($tmp);
}
echo json_encode(['checks' => $checks]);
