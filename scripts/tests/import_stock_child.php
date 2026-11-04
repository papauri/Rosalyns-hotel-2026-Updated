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
    $answerAll($j2, $admin);    // the price check judges against this hotel's real data, so answer whatever it asks ("the file's price is right" changes nothing by itself)
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
    $answerAll($j4, $admin);
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

    // ---- price plausibility from this hotel's own data + the physical storage limit ------------------------
    $mkIng = function (string $name, string $cat, float $cost, string $unit = 'bottle', array $hist = []) use ($pdo) {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, ?, ?, 0, ?, 5, 2)")->execute([$name, $cat, $unit, $cost]);
        $id = (int)$pdo->lastInsertId();
        $insB = $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, ?, 1, 0, ?, ?, 'depleted')");
        foreach (array_values($hist) as $i => $c) { $insB->execute([$id, 'ZZT' . $run . '-' . $id . '-' . $i, $c, sprintf('2026-%02d-01', $i + 1)]); }
        return $id;
    };
    $pcQ = function (array $sum, string $type = 'price-check') { foreach ($sum['questions'] as $q) { if ($q['type'] === $type) { return $q; } } return null; };
    $catHi = $n('CatHi'); $catLo = $n('CatLo');
    $idTight = $mkIng($n('Tight'), $n('CatOne'), 1000, 'bottle', [1000, 1020, 980, 1010]);
    $idWide = $mkIng($n('Wide'), $n('CatTwo'), 1000, 'bottle', [500, 1500, 800, 2000]);
    $idPlain = $mkIng($n('Plain'), $n('CatThree'), 1000);
    foreach ([[1000, 1800, 3200], [500, 900, 1600], [200, 360, 650]] as $i => $h) { $mkIng($n('Hi' . $i), $catHi, $h[2], 'bottle', $h); }
    foreach ([[1000, 1010, 1020], [500, 505, 510], [200, 201, 202]] as $i => $h) { $mkIng($n('Lo' . $i), $catLo, $h[2], 'bottle', $h); }
    $idHiT = $mkIng($n('HiTarget'), $catHi, 1000);
    $idLoT = $mkIng($n('LoTarget'), $catLo, 1000);
    $exAll = $pdo->query("SELECT id, category FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchAll(PDO::FETCH_ASSOC);
    $evd = import_job_price_evidence($pdo, $exAll);
    $ck('price evidence: an ingredient\'s own history is read from its batch unit costs, oldest first', ($evd['history'][$idTight] ?? null) === [1000.0, 1020.0, 980.0, 1010.0], json_encode($evd['history'][$idTight] ?? null));
    $ck('price evidence: how far prices usually move is worked out per category from this hotel\'s history', ($evd['category_move'][mb_strtolower($catHi)]['v'] ?? 0) > ($evd['category_move'][mb_strtolower($catLo)]['v'] ?? 9) && ($evd['category_move'][mb_strtolower($catHi)]['n'] ?? 0) === 6);
    $uP = $upload($put($csv([[$n('Tight'), '', '5', 'bottle', '1,500', '', ''], [$n('Wide'), '', '5', 'bottle', '1,500', '', ''], [$n('Plain'), '', '5', 'bottle', '1,600', '', ''],
        [$n('HiTarget'), '', '5', 'bottle', '1,600', '', ''], [$n('LoTarget'), '', '5', 'bottle', '1,600', '', '']]), 'pc.csv'), 'zzt-pc-' . $run . '.csv', $admin);
    $jP = (int)$uP['job_id'];
    import_job_analyse($pdo, $jP);
    $s0P = import_job_review_summary($pdo, $jP);
    $ck('price check (database): with no cost ticked, nothing will be written, so no price question is asked', $pcQ($s0P) === null);
    foreach (['Tight', 'Wide', 'Plain', 'HiTarget', 'LoTarget'] as $nmT) { import_job_set_update_fields($pdo, $jP, rh_ssi_key($n($nmT)), ['cost'], $admin); }
    $sP = import_job_review_summary($pdo, $jP);
    $byName = [];
    foreach ($sP['questions'] as $q) { if ($q['type'] === 'price-check') { $byName[$q['evidence']['basis'] . '|' . round($q['evidence']['new'])][] = $q; } }
    $flaggedNames = [];
    foreach ($sP['questions'] as $q) { if ($q['type'] === 'price-check') { foreach ($sP['items'] as $it) { if (array_intersect($it['uids'], $q['row_uids'])) { $flaggedNames[$it['typed_name']] = $q['evidence']['basis']; } } } }
    $ck('price check (database): 4 tight history points flag a 1.5x jump on their own spread', ($flaggedNames[$n('Tight')] ?? '') === 'own-history', json_encode($flaggedNames));
    $ck('price check (database): a wide own history does not flag the same jump', !isset($flaggedNames[$n('Wide')]), json_encode($flaggedNames));
    $ck('price check (database): a category whose prices usually move a lot tolerates 1.6x; a stable category is asked', !isset($flaggedNames[$n('HiTarget')]) && ($flaggedNames[$n('LoTarget')] ?? '') === 'category-moves', json_encode($flaggedNames));
    $ck('price check (database): a question carries its evidence numbers and blocks Confirm', !$sP['can_confirm'] && ($pcQ($sP)['evidence']['tolerance_factor'] ?? 0) > 1 && ($pcQ($sP)['blocking'] ?? false) === true);
    $ck('price check (database): the evidence is part of the review summary', array_key_exists('evidence', $sP['questions'][0]));
    // keep the current price: cost untouched, the opening count line still imports
    $idZ = $mkIng($n('Zero'), $n('CatZ'), 2875);
    $uZ = $upload($put($csv([[$n('Zero'), '', '5', 'bottle', '28,750', '', '']]), 'zero.csv'), 'zzt-zero-' . $run . '.csv', $admin);
    $jZ = (int)$uZ['job_id'];
    import_job_analyse($pdo, $jZ);
    $sZ0 = import_job_review_summary($pdo, $jZ);
    $tk = import_job_set_update_fields($pdo, $jZ, rh_ssi_key($n('Zero')), ['cost', 'quantity'], $admin);
    $sZ = import_job_review_summary($pdo, $jZ);
    $qZ = $pcQ($sZ);
    $ck('price check (database): an extra zero is asked in plain words, with the real stock cost', $qZ !== null && $qZ['evidence']['reason'] === 'extra-zero' && strpos($qZ['text'], 'costs 2,875 now; the file says 28,750') !== false && !$sZ['can_confirm'], (string)($qZ['text'] ?? ''));
    $ck('price check (database): the question appears only once the cost is ticked', $pcQ($sZ0) === null && $qZ !== null);
    $ra = import_job_answer($pdo, $jZ, $qZ['id'], 'current', $admin);
    $sZ2 = import_job_review_summary($pdo, $jZ);
    $itZ = null; foreach ($sZ2['items'] as $x) { if ($x['typed_name'] === $n('Zero')) { $itZ = $x; } }
    $ck('price check (database): "keep the current price" unticks the cost and keeps the quantity line', $tk['ok'] && $ra['ok'] && $sZ2['can_confirm'] && $itZ && $itZ['ticked'] === ['quantity'] && $itZ['in_count'] === true, json_encode($itZ['ticked'] ?? null));
    $cZ = import_commit_stock($pdo, $jZ, $admin);
    $zc = $pdo->prepare('SELECT cost_per_unit FROM stock_ingredients WHERE id = ?');
    $zc->execute([$idZ]);
    $lineZ = $pdo->prepare('SELECT cost_per_unit, actual_quantity FROM stock_count_lines WHERE count_id = ? AND ingredient_id = ?');
    $lineZ->execute([(int)($cZ['result']['opening_count_id'] ?? 0), $idZ]);
    $lz = $lineZ->fetch(PDO::FETCH_ASSOC) ?: [];
    $ck('price check (database): commit leaves the cost at 2,875 and files the opening count line at that cost', $cZ['ok'] && abs((float)$zc->fetchColumn() - 2875) < 0.0001 && abs(($lz['cost_per_unit'] ?? 0) - 2875) < 0.0001 && abs(($lz['actual_quantity'] ?? 0) - 5) < 0.0001, json_encode([$cZ['error'] ?? '', $lz]));
    // the physical limit, read from the real column definitions
    $colMax = [];
    foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'stock_ingredients' AND COLUMN_NAME = 'cost_per_unit') OR (TABLE_NAME = 'stock_count_lines' AND COLUMN_NAME = 'cost_per_unit'))")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $colMax[] = pow(10, (int)$c['NUMERIC_PRECISION'] - (int)$c['NUMERIC_SCALE']) - pow(10, -(int)$c['NUMERIC_SCALE']);
    }
    $lm = import_cost_limits($pdo);
    $ck('storage limit: the maximum cost is read from the real column precision (not a number in the code)', $colMax && !empty($lm['from_database']) && abs($lm['cost'] - min($colMax)) < 0.0001 && !empty($lm['line_value']), json_encode($lm));
    $uH = $upload($put($csv([[$n('Hugeone'), '', '5', 'bottle', '123456789012', '', ''], [$n('Fineone'), '', '5', 'bottle', '500', '', '']]), 'huge.csv'), 'zzt-huge-' . $run . '.csv', $admin);
    $jH = (int)$uH['job_id'];
    import_job_analyse($pdo, $jH);
    $sH = import_job_review_summary($pdo, $jH);
    $qH = $pcQ($sH, 'cost-too-large');
    $ck('storage limit (database): a cost that cannot be stored is a blocking question with only "skip"', $qH !== null && $qH['bad'] && array_column($qH['options'], 'key') === ['skip'] && !$sH['can_confirm'] && strpos($qH['text'], 'too large for a stock cost') !== false, (string)($qH['text'] ?? ''));
    $cH0 = import_commit_stock($pdo, $jH, $admin);
    $ck('storage limit (database): commit is refused while it is open, nothing is written', !$cH0['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run Hugeone%' OR name LIKE 'ZZT $run Fineone%'")->fetchColumn() === 0);
    import_job_answer($pdo, $jH, $qH['id'], 'skip', $admin);
    $answerAll($jH, $admin);
    $cH = import_commit_stock($pdo, $jH, $admin);
    $ck('storage limit (database): skipped, the rest imports and the oversized row never reaches the table', $cH['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name = " . $pdo->quote($n('Fineone')))->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name = " . $pdo->quote($n('Hugeone')))->fetchColumn() === 0, (string)($cH['error'] ?? ''));
    $ck('storage limit: commit re-checks it on the server (import_cost_storage_problems runs in import_job_revalidate; the helper flags the oversized item)',
        strpos((string)file_get_contents($root . '/includes/import/review.php'), 'import_cost_storage_problems($it, import_cost_limits_from_ctx($ctx))') !== false
        && import_cost_storage_problems(['cost' => 123456789012.0, 'in_count' => false], import_cost_limits_from_ctx(['cost_limits' => $lm])) !== []
        && import_cost_storage_problems(['cost' => 500.0, 'in_count' => false], import_cost_limits_from_ctx(['cost_limits' => $lm])) === []);

    // ---- Step 3 at scale: server-side paging, filters, facets, category counts, bulk answers (review-view.php) --------------------
    require_once $root . '/includes/import/review-view.php';
    $N = 2000;
    $words = ['Lager', 'Stout', 'Cider', 'Vodka', 'Rum', 'Flour', 'Sugar', 'Rice', 'Beans', 'Salt', 'Oil', 'Soap', 'Bleach', 'Mop', 'Paper', 'Pen'];
    $fh = fopen('php://memory', 'w+');
    fputcsv($fh, ['Item Name', 'Batch', 'Balance', 'Unit', 'Unit Price', 'Max Level', 'Expiry Date']);
    $cats = 40; $per = (int)ceil($N / $cats); $ii = 0; mt_srand(11);
    for ($c = 1; $c <= $cats; $c++) {
        fputcsv($fh, [$n('CATEGORY ' . sprintf('%03d', $c)), '', '', '', '', '', '']);
        for ($k = 0; $k < $per && $ii < $N; $k++, $ii++) {
            $nm = $n($words[$ii % 16] . ' ' . sprintf('%05d', $ii));
            if ($ii % 40 == 39) { $nm = $n($words[($ii - 1) % 16] . ' ' . sprintf('%05d', $ii - 1) . 'x'); }           // looks like a typo of the previous item
            $price = 1000 + ($ii % 50) * 10; if ($ii % 23 == 0) { $price *= 40; }                                       // a case-sized price now and then
            if ($ii % 211 == 0 && $ii > 0) { $nm = $n($words[($ii - 1) % 16] . ' ' . sprintf('%05d', $ii - 1)); }       // the same name twice
            fputcsv($fh, [$nm, '', mt_rand(0, 50), ['bottle', 'kg', 'each', 'L'][$ii % 4], $price, mt_rand(10, 100), '']);
        }
    }
    rewind($fh);
    $uBig = $upload($put(stream_get_contents($fh), 'big.csv'), 'zzt-big-' . $run . '.csv', $admin, ['max_bytes' => 50 * 1048576]);
    $jBig = (int)$uBig['job_id'];
    $tA = microtime(true);
    $aBig = import_job_analyse($pdo, $jBig);
    $analyseSec = microtime(true) - $tA;
    $ck('scale: a generated file of ' . $N . ' items is analysed', !empty($aBig['ok']), (string)($aBig['error'] ?? '') . ' ' . round($analyseSec, 1) . 's');
    $full = import_job_review_summary($pdo, $jBig);                       // independent oracle: the old full summary, filtered here with plain PHP
    $inItem = [];
    foreach ($full['items'] as $it) { foreach ($it['uids'] as $u) { $inItem[$u] = true; } }
    $extraRows = array_values(array_filter($full['rows'], function ($r) use ($inItem) { return !isset($inItem[$r['uid']]); }));
    $oracleAll = count($full['items']) + count($extraRows);
    $oSt = ['needs-answer' => 0, 'blocked' => 0, 'ready' => 0, 'skipped' => 0];
    foreach ($full['items'] as $it) { $oSt[$it['status']]++; }
    foreach ($extraRows as $r) { $oSt[$r['status']]++; }
    $V = function (array $p, array $o = []) use ($pdo, $jBig) { return import_job_review_view($pdo, $jBig, $p, $o + ['overview' => false, 'facets' => true]); };
    $tP = microtime(true);
    $pAll = $V(['status' => 'all', 'size' => 200]);
    $pageSec = microtime(true) - $tP;
    $ck('scale: ' . $N . ' generated items give a few thousand entries, and the page total equals the independent count', $pAll['ok'] && $oracleAll >= 1900 && $pAll['page']['total'] === $oracleAll && count($pAll['page']['entries']) === 200, json_encode([$oracleAll, $pAll['page']['total'] ?? null]));
    $ck('paging: the status counts match the independent count (needs answer / blocked / ready / skipped)', $pAll['page']['facets']['status'] === $oSt, json_encode([$pAll['page']['facets']['status'], $oSt]));
    $seen = [];
    $pg = 1; $pages = $pAll['page']['pages'];
    for ($pg = 1; $pg <= $pages; $pg++) {
        $r = $V(['status' => 'all', 'size' => 200, 'page' => $pg], ['facets' => false]);
        foreach ($r['page']['entries'] as $e) { $seen[$e['i']] = true; }
    }
    $ck('paging: walking every page shows each item once and only once', count($seen) === $oracleAll && $pages === (int)ceil($oracleAll / 200), count($seen) . ' of ' . $oracleAll);
    $ck('paging: a page past the end is clamped to the last page, size 25 gives 25 entries', $V(['status' => 'all', 'size' => 25, 'page' => 99999], ['facets' => false])['page']['page'] === (int)ceil($oracleAll / 25) && count($V(['status' => 'all', 'size' => 25])['page']['entries']) === 25);
    $ck('filters: junk values are dropped (bad sort, bad size, bad status, long text) and the page still loads', (function () use ($V) {
        $r = $V(['status' => 'bogus,ready', 'sort' => 'DROP TABLE', 'size' => '7', 'kind' => 'x', 'type' => '<b>', 'q' => str_repeat('a', 500), 'page' => '-4']);
        $f = $r['page']['filters'] ?? [];
        return $r['ok'] && $f['status'] === ['ready'] && $f['sort'] === 'order' && $f['size'] === 50 && $f['kind'] === '' && $f['type'] === '' && mb_strlen($f['q']) === 100 && $f['page'] === 1;
    })());
    // default status: needs answer (+ blocked) while any remain
    $pDef = $V([]);
    $ck('filters: with no status given the default is "needs answer" (+ blocked) because questions remain', $pDef['page']['filters']['status_default'] === true && $pDef['page']['total'] === $oSt['needs-answer'] + $oSt['blocked'] && $oSt['needs-answer'] > 0);
    // status filter + facet independence
    $pReady = $V(['status' => 'ready', 'size' => 25]);
    $ck('filters: status=ready narrows to the ready items and its count matches', $pReady['page']['total'] === $oSt['ready'] && $pReady['page']['facets']['status']['needs-answer'] === $oSt['needs-answer']);
    // category
    $catCount = [];
    foreach ($full['items'] as $it) { $catCount[$it['category']] = ($catCount[$it['category']] ?? 0) + 1; }
    $someCat = array_key_first(array_filter($catCount, function ($c) { return $c > 10; }));
    $pCat = $V(['status' => 'all', 'cat' => $someCat, 'size' => 200]);
    $ck('filters: category narrows to exactly that heading\'s items', $pCat['page']['total'] === $catCount[$someCat], $someCat . ' ' . ($pCat['page']['total'] ?? '?') . ' vs ' . $catCount[$someCat]);
    $catRow = null;
    foreach ($pAll['page']['facets']['cat'] as $c) { if ($c['name'] === $someCat) { $catRow = $c; } }
    $oCatNeeds = 0;
    foreach ($full['items'] as $it) { if ($it['category'] === $someCat && $it['status'] === 'needs-answer') { $oCatNeeds++; } }
    $ck('category counts: items / needs answer per category match the independent count', $catRow && $catRow['items'] === $catCount[$someCat] && $catRow['needs'] === $oCatNeeds, json_encode($catRow));
    $ck('category view: the category list is in file order and covers every heading', count($pAll['page']['facets']['cat']) === count($catCount) && array_keys($catCount) === array_column($pAll['page']['facets']['cat'], 'name'), count($pAll['page']['facets']['cat']) . ' vs ' . count($catCount));
    // sheet
    $sheetName = $full['items'][0]['sheet'];
    $ck('filters: sheet = the sheet gives everything, an unknown sheet gives nothing', $V(['status' => 'all', 'sheet' => $sheetName])['page']['total'] === $oracleAll && $V(['status' => 'all', 'sheet' => 'No such sheet'])['page']['total'] === 0);
    // search: name word, category, source cell
    $pick = $full['items'][37];
    $pNm = $V(['status' => 'all', 'q' => $pick['name']]);
    $ck('search: the full item name finds that item', $pNm['page']['total'] >= 1 && in_array($pick['name'], array_map(function ($e) { return $e['it']['name']; }, array_filter($pNm['page']['entries'], function ($e) { return $e['kind'] === 'item'; })), true));
    $pCell = $V(['status' => 'all', 'q' => $pick['name_source']]);
    $ck('search: a source cell like "' . $pick['name_source'] . '" finds exactly the item read from it', $pCell['page']['total'] === 1 && $pCell['page']['entries'][0]['it']['name'] === $pick['name'], (string)$pCell['page']['total']);
    $cellOnly = substr($pick['name_source'], strrpos($pick['name_source'], '!') + 1);
    $pCell2 = $V(['status' => 'all', 'q' => strtolower($cellOnly)]);
    $ck('search: a bare cell (lower case) finds it too, and the sheet part is matched loosely (first 3 letters of the sheet name)', $pCell2['page']['total'] >= 1 && $V(['status' => 'all', 'q' => substr($pick['sheet'], 0, 3) . '!' . $cellOnly])['page']['total'] === 1);
    $ck('search: a word that is nowhere finds nothing, a category word finds that category', $V(['status' => 'all', 'q' => 'qqqnotthere'])['page']['total'] === 0 && $V(['status' => 'all', 'q' => 'CATEGORY ' . sprintf('%03d', 7)])['page']['total'] >= $catCount[$n('CATEGORY 007')]);
    // flags and kinds
    $oWarn = count(array_filter($full['items'], function ($it) { return !empty($it['warnings']); }));
    $ck('filters: "has a warning" counts and narrows correctly', $V(['status' => 'all', 'warn' => '1'])['page']['total'] === $oWarn, (string)$oWarn);
    $ck('filters: new vs existing (nothing here matches stock) and the cost / stock / answered flags are empty', $V(['status' => 'all', 'kind' => 'new'])['page']['total'] === count($full['items']) && $V(['status' => 'all', 'kind' => 'matched'])['page']['total'] === 0
        && $V(['status' => 'all', 'cost' => '1'])['page']['total'] === 0 && $V(['status' => 'all', 'stock' => '1'])['page']['total'] === 0 && $V(['status' => 'all', 'answered' => '1'])['page']['total'] === 0);
    // question type
    $uidItem = [];
    foreach ($full['items'] as $k => $it) { foreach ($it['uids'] as $u) { $uidItem[$u] = $k; } }
    $oType = function (string $type) use ($full, $uidItem) {
        $hit = [];
        foreach ($full['questions'] as $q) {
            if ($q['type'] === $type && ($q['answer'] === null || $q['answer'] === '')) { foreach ($q['row_uids'] as $u) { if (isset($uidItem[$u])) { $hit[$uidItem[$u]] = true; } } }
        }
        return count($hit);
    };
    $ck('filters: question type "did you mean" counts items with an open question of that kind', $V(['status' => 'all', 'type' => 'did-you-mean'])['page']['total'] === $oType('possible-typo') && $oType('possible-typo') > 0, (string)$oType('possible-typo'));
    $ck('filters: question type "case price or per unit" matches', $V(['status' => 'all', 'type' => 'case-or-unit'])['page']['total'] === $oType('case-price') && $oType('case-price') > 5);
    $ck('facets: each count ignores its own filter but keeps the others (sheet count under a category filter)', $V(['status' => 'all', 'cat' => $someCat])['page']['facets']['sheet_list'][0]['n'] === $catCount[$someCat]
        && $V(['status' => 'all', 'cat' => $someCat])['page']['facets']['status']['ready'] + $V(['status' => 'all', 'cat' => $someCat])['page']['facets']['status']['needs-answer'] === $catCount[$someCat]);
    // sorting
    $byName = $V(['status' => 'all', 'sort' => 'name', 'size' => 200])['page']['entries'];
    $names = array_map(function ($e) { return mb_strtolower($e['it']['name']); }, $byName);
    $sortedNames = $names; usort($sortedNames, 'strnatcasecmp');
    $byPrice = array_map(function ($e) { return $e['it']['cost']; }, $V(['status' => 'all', 'sort' => 'price', 'size' => 200])['page']['entries']);
    $nn = array_values(array_filter($byPrice, function ($x) { return $x !== null; }));
    $okPrice = $nn === (function ($x) { sort($x); return $x; })($nn) && $byPrice === array_merge($nn, array_fill(0, count($byPrice) - count($nn), null));
    $stRank = ['blocked' => 0, 'needs-answer' => 1, 'ready' => 2, 'skipped' => 3];
    $stOrder = array_map(function ($e) use ($stRank) { return $stRank[$e['status']]; }, $V(['status' => 'all', 'sort' => 'status', 'size' => 200])['page']['entries']);
    $ck('sorting: name A-Z, price (low to high) and needs-answer-first are in order', $names === $sortedNames && $okPrice && $stOrder === (function ($x) { sort($x); return $x; })($stOrder));
    // reachability: every open blocking non-structural question can be answered from some entry (or the sheet box)
    $reach = [];
    for ($pg = 1; $pg <= $pages; $pg++) {
        $r = $V(['status' => 'all', 'size' => 200, 'page' => $pg], ['facets' => false]);
        foreach ($r['page']['entries'] as $e) { foreach ($e['qs'] as $q) { $reach[$q['id']] = true; } }
        foreach ($r['page']['sheet_questions'] as $q) { $reach[$q['id']] = true; }
    }
    $unreached = [];
    foreach ($full['questions'] as $q) {
        $struct = empty($q['row_uids']) && ($q['row'] === null || $q['row'] === '');
        if ($q['blocking'] && !$struct && ($q['answer'] === null || $q['answer'] === '') && empty($reach[$q['id']])) { $unreached[] = $q['type']; }
    }
    $ck('questions: every open question that stops a confirm can be reached from the list (nothing is stuck out of sight)', !$unreached, json_encode(array_slice($unreached, 0, 5)));
    $ck('questions: a page only carries the questions of the items on it (far fewer than the whole job)', (function () use ($V, $full) {
        $r = $V(['status' => 'all', 'size' => 25], ['facets' => false]);
        $n = 0; foreach ($r['page']['entries'] as $e) { $n += count($e['qs']); }
        return $n < count($full['questions']) / 10 + 3;
    })());

    // ---- bulk answers --------------------------------------------------------------------------------------------------------
    $oOpenCase = array_values(array_filter($full['questions'], function ($q) { return $q['type'] === 'case-price' && ($q['answer'] === null || $q['answer'] === ''); }));
    $bd = import_job_bulk($pdo, $jBig, ['status' => 'all', 'type' => 'case-or-unit'], 'case-price', 'per-unit', 'per-unit', true, '', $admin);
    $ck('bulk: the preview counts exactly the open questions of that kind in the filtered list, with 5 sample names and no change made', $bd['ok'] && $bd['n'] === count($oOpenCase) && $bd['would_apply'] === $bd['n'] && count($bd['samples']) === 5 && $bd['fingerprint'] !== ''
        && count(array_filter(import_job_review_summary($pdo, $jBig)['questions'], function ($q) { return $q['type'] === 'case-price' && $q['answer'] !== null; })) === 0, json_encode([$bd['n'] ?? $bd, count($oOpenCase)]));
    $bGroups = $V(['status' => 'all'])['page']['bulk'];
    $caseGroup = array_values(array_filter($bGroups, function ($g) { return $g['type'] === 'case-price'; }))[0] ?? null;
    $ck('bulk: the offered answers are only roles every question accepts - "price for one unit" yes, "per case" (needs a number) never', $caseGroup && array_column($caseGroup['answers'], 'role') === ['per-unit'] && $caseGroup['n'] === count($oOpenCase));
    $ck('bulk: "per case" is refused outright (it needs a pack size typed per question)', !import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'per-case', false, 'x', $admin)['ok']);
    $ck('bulk: structural / sheet-level / "same existing match" questions cannot be bulk answered', !import_job_bulk($pdo, $jBig, ['status' => 'all'], 'no-unit', 'x', 'skip', true, '', $admin)['ok'] && !import_job_bulk($pdo, $jBig, ['status' => 'all'], 'sheet-unknown', 'skip', 'skip', true, '', $admin)['ok']
        && !import_job_bulk($pdo, $jBig, ['status' => 'all'], 'same-existing-match', 'skip', 'skip', true, '', $admin)['ok']);
    $bad = import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'skip', true, '', $admin);
    $ck('bulk: an answer that is not one of the choices is refused question by question (would apply 0, would refuse all)', $bad['ok'] && $bad['would_apply'] === 0 && $bad['would_refuse'] === $bad['n'] && $bad['reasons'] !== []);
    $badApply = import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'skip', false, $bad['fingerprint'], $admin);
    $ck('bulk: applying an invalid answer writes nothing (applied 0, refused all)', $badApply['ok'] && $badApply['applied'] === 0 && $badApply['refused'] === $bd['n']
        && count(array_filter(import_job_review_summary($pdo, $jBig)['questions'], function ($q) { return $q['type'] === 'case-price' && $q['answer'] !== null; })) === 0);
    $ck('bulk: a stale or wrong fingerprint is refused ("the list changed")', !import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'per-unit', false, 'deadbeef', $admin)['ok']);
    // exactly the filtered set: only the questions of ONE category
    $inCat = function (?string $category) use ($full, $uidItem) {
        $ids = [];
        foreach ($full['questions'] as $q) {
            if ($q['type'] !== 'case-price' || ($q['answer'] !== null && $q['answer'] !== '')) { continue; }
            foreach ($q['row_uids'] as $u) { if (isset($uidItem[$u]) && $full['items'][$uidItem[$u]]['category'] === $category) { $ids[$q['id']] = true; } }
        }
        return array_keys($ids);
    };
    $catWith = null;
    foreach (array_keys($catCount) as $cname) { if (count($inCat($cname)) >= 2) { $catWith = $cname; break; } }
    $params = ['status' => 'all', 'cat' => $catWith];
    $d2 = import_job_bulk($pdo, $jBig, $params, 'case-price', 'per-unit', 'per-unit', true, '', $admin);
    $ck('bulk: the set is recomputed from the filter (a category) - here only that category\'s questions', $catWith !== null && $d2['ok'] && $d2['n'] === count($inCat($catWith)) && $d2['n'] < $bd['n'], json_encode([$catWith, $d2['n'] ?? $d2]));
    $a2 = import_job_bulk($pdo, $jBig, $params, 'case-price', 'per-unit', 'per-unit', false, $d2['fingerprint'], $admin);
    $after = import_job_review_summary($pdo, $jBig);
    $answeredCase = array_values(array_filter($after['questions'], function ($q) { return $q['type'] === 'case-price' && $q['answer'] === 'per-unit'; }));
    $okIds = $inCat($catWith);
    $ck('bulk: it applied exactly that set (applied N, refused 0) and no other case-price question changed', $a2['ok'] && $a2['applied'] === $d2['n'] && $a2['refused'] === 0 && count($answeredCase) === $d2['n']
        && !array_diff(array_column($answeredCase, 'id'), $okIds), json_encode([$a2['applied'] ?? $a2, count($answeredCase)]));
    $ck('bulk: other kinds of question were not touched (the open count of the others is unchanged)', count(array_filter($after['questions'], function ($q) { return $q['type'] !== 'case-price' && $q['answer'] !== null; })) === count(array_filter($full['questions'], function ($q) { return $q['type'] !== 'case-price' && $q['answer'] !== null; })));
    $rest = import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'per-unit', true, '', $admin);
    $ck('bulk: after that, the full list offers only the questions still open', $rest['ok'] && $rest['n'] === count($oOpenCase) - $d2['n']);
    $ck('bulk: the stored answer has the same shape as a single answer (key, by, at) and the progress counter moved', (function () use ($pdo, $jBig, $admin) {
        $a = import_job_answers(import_job_get($pdo, $jBig));
        foreach ($a as $k => $v) { if (is_array($v) && ($v['key'] ?? '') === 'per-unit') { return array_keys($v) === ['key', 'by', 'at'] && (int)$v['by'] === $admin; } }
        return false;
    })() && $V(['status' => 'all'])['progress']['answered'] === 0 + count($answeredCase) + count(array_filter($full['questions'], function ($q) { return $q['blocking'] && $q['answer'] !== null && $q['type'] !== 'case-price'; })));
    $dupG = array_values(array_filter($V(['status' => 'all'])['page']['bulk'], function ($g) { return $g['type'] === 'possible-typo'; }))[0] ?? null;
    $ck('bulk: "use the suggested match" is offered for did-you-mean questions and maps to each question\'s own suggestion', $dupG && in_array('use-suggestion', array_column($dupG['answers'], 'role'), true));
    if ($dupG) {
        $pt = import_job_bulk($pdo, $jBig, ['status' => 'all', 'type' => 'did-you-mean'], 'possible-typo', $dupG['sig'], 'use-suggestion', true, '', $admin);
        $ptA = import_job_bulk($pdo, $jBig, ['status' => 'all', 'type' => 'did-you-mean'], 'possible-typo', $dupG['sig'], 'use-suggestion', false, $pt['fingerprint'], $admin);
        $sugOk = true;
        foreach (import_job_review_summary($pdo, $jBig)['questions'] as $q) {
            if ($q['type'] === 'possible-typo' && $q['answer'] !== null && $q['answer'] !== 'keep') { $sugOk = $sugOk && $q['answer'] === 'use:' . ($q['suggestion']['name'] ?? '?'); }
        }
        $ck('bulk: the suggested match is applied per question and every question took its own suggestion', $ptA['ok'] && $ptA['applied'] === $pt['n'] && $ptA['refused'] === 0 && $sugOk, json_encode($ptA));
    }
    // a concurrent single answer between the preview and the apply is never overwritten (the target set is decided under the row lock)
    $openCase = array_values(array_filter(import_job_review_summary($pdo, $jBig)['questions'], function ($q) { return $q['type'] === 'case-price' && ($q['answer'] === null || $q['answer'] === ''); }));
    $dRace = import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'per-unit', true, '', $admin);
    $raceQ = $openCase[0] ?? null;
    $aRace = $raceQ ? import_job_bulk($pdo, $jBig, ['status' => 'all'], 'case-price', 'per-unit', 'per-unit', false, (string)$dRace['fingerprint'], $admin, function () use ($pdo, $jBig, $raceQ, $mgr) {
        import_job_answer($pdo, $jBig, $raceQ['id'], ['key' => 'per-case', 'pack_size' => 12], $mgr);       // another person answers it first
    }) : ['ok' => false];
    $kept = null;
    foreach (import_job_review_summary($pdo, $jBig)['questions'] as $q) { if ($raceQ && $q['id'] === $raceQ['id']) { $kept = $q; } }
    $ck('bulk race: a question another person answered meanwhile keeps THEIR answer (per case, 12) and is reported as refused', $raceQ && $aRace['ok'] && $kept && $kept['answer'] === 'per-case' && (int)$kept['pack_size'] === 12 && $aRace['refused'] === 1 && $aRace['applied'] === $dRace['n'] - 1
        && count(array_filter(array_keys($aRace['reasons']), function ($k) { return stripos($k, 'someone else') !== false; })) === 1, json_encode($aRace));
    // finish the whole job: bulk for every group that is offered, one by one for the few left, then it must be confirmable
    $bulkRounds = 0; $bulkApplied = 0;
    for ($round = 0; $round < 12; $round++) {
        $groups = $V(['status' => 'all'], ['facets' => false])['page']['bulk'];
        if (!$groups) { break; }
        $g = $groups[0];
        $roles = array_column($g['answers'], 'role');
        $role = in_array('per-unit', $roles, true) ? 'per-unit' : (in_array('two-batches', $roles, true) ? 'two-batches' : (in_array('skip', $roles, true) ? 'skip' : $roles[0]));
        $d = import_job_bulk($pdo, $jBig, ['status' => 'all'], $g['type'], $g['sig'], $role, true, '', $admin);
        $x = import_job_bulk($pdo, $jBig, ['status' => 'all'], $g['type'], $g['sig'], $role, false, (string)$d['fingerprint'], $admin);
        if (empty($x['ok']) || $x['applied'] === 0) { break; }
        $bulkRounds++; $bulkApplied += $x['applied'];
    }
    $left = array_values(array_filter(import_job_review_summary($pdo, $jBig)['questions'], function ($q) { return $q['blocking'] && ($q['answer'] === null || $q['answer'] === '') && $q['options']; }));
    $ck('bulk: group after group the job is brought down to a handful of single questions (a question whose rows were all skipped by another answer is finished, so one round can settle several groups)', $bulkRounds >= 1 && $bulkApplied > 100 && count($left) < 40, $bulkRounds . ' rounds, ' . $bulkApplied . ' answers, ' . count($left) . ' left');
    foreach ($left as $q) {
        $keys = array_column($q['options'], 'key');
        $pick = in_array('skip', $keys, true) ? 'skip' : (in_array('two-batches', $keys, true) ? 'two-batches' : (in_array('per-unit', $keys, true) ? 'per-unit' : $keys[0]));
        if ($q['type'] === 'no-unit') { $pick = in_array('unit:each', $keys, true) ? 'unit:each' : $keys[0]; }
        import_job_answer($pdo, $jBig, $q['id'], $pick, $admin);
    }
    $fin = $V([]);
    $ck('progress: after everything is answered the default list is empty, nothing is open and the job is confirmable', $fin['page']['filters']['status_default'] && $fin['page']['filters']['status'] === [] && $fin['progress']['open'] === 0 && $fin['can_confirm'] === true, json_encode([$fin['progress'], $fin['can_confirm'], $fin['why_not'] ?? '']));
    // ---- a file that cannot fit, a read that died: plain message, no half-made job (memory.php) -----------------------------------------
    $storedFile = function (int $id) use ($pdo) { $j = import_job_get($pdo, $id); return $j && $j['stored_path'] ? dirname(__DIR__, 2) . '/storage/imports/' . $j['stored_path'] : ''; };
    $uM = $upload($put($csv([[$n('Memitem'), '', '5', 'bottle', '500', '', '']]), 'mem.csv'), 'zzt-mem-' . $run . '.csv', $admin);
    $jM = (int)$uM['job_id'];
    $fileM = $storedFile($jM);
    $rM = import_job_analyse($pdo, $jM, ['mem' => ['limit' => 12 * 1048576, 'count' => ['rows' => 20000, 'cells' => 140000]]]);
    $jobM = import_job_get($pdo, $jM);
    $ck('too big: the job ends as FAILED with the plain "split it into files of about N rows" text and the staged file is deleted', !$rM['ok'] && $jobM['status'] === 'failed' && strpos((string)$jobM['error_text'], 'too many to read in one go on this server') !== false
        && $jobM['error_text'] === $rM['error'] && $jobM['stored_path'] === null && $fileM !== '' && !is_file($fileM) && $jobM['analysis_json'] === null, json_encode([$rM['error'] ?? '', $jobM['status'], is_file($fileM)]));
    $ck('too big: nothing is left to open (summary says the import cannot be reviewed, no second attempt runs)', import_job_state($pdo, $jM) === null && !import_job_analyse($pdo, $jM)['ok']);
    // a read that died (killed / timed out) is recognised later instead of leaving a stuck job
    $uS = $upload($put($csv([[$n('Staleitem'), '', '5', 'bottle', '500', '', '']]), 'stale.csv'), 'zzt-stale-' . $run . '.csv', $admin);
    $jS = (int)$uS['job_id'];
    $fileS = $storedFile($jS);
    $pdo->prepare('UPDATE import_jobs SET error_text = ? WHERE id = ?')->execute(['Reading... since ' . date('Y-m-d H:i:s', time() - 60), $jS]);
    $rS1 = import_job_analyse($pdo, $jS);
    $ck('stale read: a read that started a minute ago is "still being read" (no second run, job untouched)', !$rS1['ok'] && stripos($rS1['error'], 'still being read') !== false && import_job_get($pdo, $jS)['status'] === 'uploaded' && is_file($fileS));
    $pdo->prepare('UPDATE import_jobs SET error_text = ? WHERE id = ?')->execute(['Reading... since ' . date('Y-m-d H:i:s', time() - 1800), $jS]);
    $rS2 = import_job_analyse($pdo, $jS);
    $jobS = import_job_get($pdo, $jS);
    $ck('stale read: one that started half an hour ago is closed as FAILED with a plain text and its file deleted', !$rS2['ok'] && $jobS['status'] === 'failed' && strpos((string)$jobS['error_text'], 'did not finish') !== false && !is_file($fileS) && $jobS['stored_path'] === null, (string)($jobS['error_text'] ?? ''));
    $uO = $upload($put($csv([[$n('Okitem'), '', '5', 'bottle', '500', '', '']]), 'okmem.csv'), 'zzt-okmem-' . $run . '.csv', $admin);
    $rO = import_job_analyse($pdo, (int)$uO['job_id']);
    $jobO = import_job_get($pdo, (int)$uO['job_id']);
    $ck('normal file: analyses as before, the "Reading..." marker is cleared', $rO['ok'] && $jobO['status'] === 'analysed' && $jobO['error_text'] === null && $jobO['analysis_json'] !== null);
    $light = import_job_get_light($pdo, (int)$uO['job_id']);
    $ck('memory: the job row used by the page carries no analysis text, only its size', $light['analysis_json'] === 'stored' && (int)$light['analysis_bytes'] === strlen($jobO['analysis_json']) && $light['answers_json'] === $jobO['answers_json']);
    // ---- ownership and speed ---------------------------------------------------------------------------------------------------
    $ck('ownership: another user cannot open the job, the uploader and an administrator can', import_job_for_user($pdo, $jBig, $mgr, false) === null && import_job_for_user($pdo, $jBig, $admin, false) !== null && import_job_for_user($pdo, $jBig, $mgr, true) !== null && import_job_for_user($pdo, 0, $admin, true) === null);
    $ck('speed: one page of a ' . $oracleAll . '-item job with every facet is well under 20 s (' . round($pageSec, 2) . ' s; analysis ' . round($analyseSec, 1) . ' s)', $pageSec < 20, (string)round($pageSec, 2));
    $tM = microtime(true);
    import_job_state_memo(true);
    $vv = import_job_review_view($pdo, $jBig, ['size' => 50], ['facets' => true]);
    $vv2 = import_job_review_view($pdo, $jBig, ['size' => 50, 'page' => 2], ['overview' => false]);
    import_job_state_memo(false);
    $ck('speed: the lean overview carries no item list (a fraction of the old full summary) and a second call reuses the resolved job', $vv['ok'] && !isset($vv['items']) && strlen(json_encode($vv)) < strlen(json_encode($full)) / 5 && $vv2['ok'], round(microtime(true) - $tM, 2) . ' s');
    $ck('confirm numbers: the lean overview gives the Step 4 counts (creates + updates + kept = ready items)', $vv['confirm']['creates'] + $vv['confirm']['updates'] + $vv['confirm']['kept'] === (int)$vv['entry_counts']['ready']);


    // ---- smarter: leaving rows out, units item by item, repairs, learning ---------------------------------------------------------
    $answersOf = function (int $j) use ($pdo) { return (string)import_job_get($pdo, $j)['answers_json']; };
    $uidOf = function (array $sum, string $name) { foreach ($sum['rows'] as $r0) { if ($r0['name'] === $name) { return $r0['uid']; } } return null; };
    $rowsSk = [
        [$n('Sk Good'), '', '5', 'kg', '100', '', ''],
        [$n('Sk Bad'), '', 'lots', 'kg', '100', '', ''],
        [$n('Sk Neg'), '', '-3', 'kg', '100', '', ''],
        [$n('Sk Twin'), '', '2', 'kg', '50', '', ''],
        [$n('Sk Twin'), '', '3', 'kg', '60', '', ''],
    ];
    $upS = $upload($put($csv($rowsSk), 'skip.csv'), 'zzt-skip-' . $run . '.csv', $admin);
    $jS = (int)$upS['job_id'];
    import_job_analyse($pdo, $jS);
    $sS = import_job_review_summary($pdo, $jS);
    $ck('skip: two blocked rows (not a number, negative) and an unanswered duplicate stop the confirm', $sS['counts']['blocked'] === 2 && $sS['counts']['needs-answer'] === 2 && !$sS['can_confirm'] && strpos($sS['why_not'], 'skip them') !== false, json_encode($sS['counts']));
    $a0 = $answersOf($jS);
    $dS = import_job_skip($pdo, $jS, ['mode' => 'blocked', 'dry' => true], $admin);
    $ck('skip (dry run): says how many rows, names them with the plain reason and the cell, and changes nothing', $dS['ok'] && $dS['entries'] === 2 && $dS['rows'] === 2 && $dS['groups'][0]['code'] === 'bad-value' && strpos($dS['samples'][0]['why'], 'not a number') !== false && $dS['samples'][0]['ref'] !== '' && $answersOf($jS) === $a0, json_encode($dS));
    $wrongFp = import_job_skip($pdo, $jS, ['mode' => 'blocked', 'fingerprint' => 'nope'], $admin);
    $ck('skip: a fingerprint that does not match what was previewed is refused, nothing skipped', !$wrongFp['ok'] && $answersOf($jS) === $a0);
    $aS1 = import_job_skip($pdo, $jS, ['mode' => 'blocked', 'fingerprint' => $dS['fingerprint']], $admin);
    $sS1 = import_job_review_summary($pdo, $jS);
    $ck('skip (bulk blocked): both blocked rows are skipped, the rest is untouched', $aS1['ok'] && $aS1['skipped_now'] === 2 && $sS1['counts']['blocked'] === 0 && $sS1['counts']['skipped'] === 2 && $sS1['counts']['needs-answer'] === 2, json_encode($sS1['counts']));
    $ck('skip: the skipped rows are recorded with who, when, the original reason and its code', (function () use ($pdo, $jS, $admin) { $a = json_decode((string)import_job_get($pdo, $jS)['answers_json'], true)['_skip_rows'] ?? []; $x = reset($a); return count($a) === 2 && (int)$x['by'] === $admin && $x['code'] === 'bad-value' && strpos($x['why'], 'not a number') !== false && $x['at'] !== ''; })());
    $uidBad = $uidOf($sS1, $n('Sk Bad'));
    $un = import_job_unskip($pdo, $jS, [$uidBad], $admin);
    $ck('skip (undo): bringing a row back asks about it again', $un['ok'] && $un['restored'] === 1 && import_job_review_summary($pdo, $jS)['counts']['blocked'] === 1);
    $qBad = null; foreach (import_job_review_summary($pdo, $jS)['questions'] as $q0) { if ($q0['type'] === 'bad-value' && in_array($uidBad, $q0['row_uids'], true)) { $qBad = $q0; } }
    $ck('skip: a blocked question card offers skipping ("can_skip") and the exact cell to fix', $qBad && $qBad['can_skip'] === true && count($qBad['cells']) === 1 && strpos($qBad['text'], 'not a number') !== false);
    $rSk = import_job_answer($pdo, $jS, $qBad['id'], '_skip', $admin);
    $sS2 = import_job_review_summary($pdo, $jS);
    $qBad2 = null; foreach ($sS2['questions'] as $q0) { if ($q0['id'] === $qBad['id']) { $qBad2 = $q0; } }
    $ck('skip (answer): "Skip this row" is accepted as the answer to a blocked question; its rows are skipped and the question counts as done', $rSk['ok'] && $sS2['counts']['blocked'] === 0 && $qBad2['answer'] === '_skipped');
    $ck('skip: a made-up answer is still refused', !import_job_answer($pdo, $jS, $qBad['id'], 'nonsense', $admin)['ok']);
    $dO = import_job_skip($pdo, $jS, ['mode' => 'open', 'dry' => true], $admin);
    $ck('continue (dry run): the unanswered duplicate is the one thing left, with its reason', $dO['ok'] && $dO['entries'] === 1 && $dO['rows'] === 2 && strpos($dO['groups'][0]['code'], 'duplicate') === 0 && $dO['structural_open'] === 0, json_encode($dO));
    $aO = import_job_skip($pdo, $jS, ['mode' => 'open', 'fingerprint' => $dO['fingerprint']], $admin);
    $sS3 = import_job_review_summary($pdo, $jS);
    $ck('continue: after leaving the unanswered rows out the import can be confirmed', $aO['ok'] && $sS3['can_confirm'] && $sS3['counts']['skipped'] === 4 && $sS3['counts']['ready'] === 1, json_encode($sS3['counts']));
    $ov = import_job_review_view($pdo, $jS, null, []);
    $ck('continue: the overview carries the skipped count by reason and the repair counts', $ov['ok'] && $ov['skipped_by_you'] === 4 && ($ov['skipped_by_code']['bad-value'] ?? 0) >= 1 && isset($ov['heal_counts']));
    $lS = import_job_list_view($pdo, $jS, 'skipped', []);
    $lS2 = import_job_list_view($pdo, $jS, 'skipped', ['q' => 'twin']);
    $ck('Step 4 list: the skipped rows are grouped by reason, searchable, and say what was wrong', $lS['total'] === 4 && count($lS['groups']) >= 2 && $lS2['total'] === 2 && $lS2['rows'][0]['by_you'] === true && $lS2['rows'][0]['why'] !== '', json_encode([$lS['groups'], $lS2['total']]));
    $csvS = import_job_skipped_export($pdo, $jS, 'csv');
    $ck('skipped export (csv): lists the skipped rows with their row numbers, the reason and what the row said; none of the good row', $csvS['ok'] && strpos($csvS['body'], $n('Sk Bad')) !== false && strpos($csvS['body'], 'lots') !== false && strpos($csvS['body'], 'not a number') !== false && strpos($csvS['body'], $n('Sk Good')) === false && substr_count($csvS['body'], $n('Sk Twin')) >= 2);
    $xlsS = import_job_skipped_export($pdo, $jS, 'xlsx');
    $ck('skipped export (xlsx): a real workbook', $xlsS['ok'] && substr($xlsS['body'], 0, 2) === 'PK' && strlen($xlsS['body']) > 500 && substr($xlsS['name'], -5) === '.xlsx');
    $cS = import_commit_stock($pdo, $jS, $admin);
    $nameIn = function (string $nm) use ($pdo) { $st = $pdo->prepare('SELECT COUNT(*) FROM stock_ingredients WHERE name = ?'); $st->execute([$nm]); return (int)$st->fetchColumn(); };
    $ck('commit: only the good row reaches the database; the four skipped rows (bad, negative, the duplicate pair) are absent', $cS['ok'] && $nameIn($n('Sk Good')) === 1 && $nameIn($n('Sk Bad')) === 0 && $nameIn($n('Sk Neg')) === 0 && $nameIn($n('Sk Twin')) === 0 && ($cS['result']['created'] ?? -1) === 1, json_encode($cS['result'] ?? $cS['error']));
    $ck('commit: the result says how many rows the person left out', ($cS['result']['skipped_by_you'] ?? -1) === 4);

    // ---- skipping "different items" rows (blocked with no question) and unit-clash / archived -------------------------------------
    $upD = $upload($put($csv([[$n('Dup Item'), '', '2', 'kg', '10', '', ''], [$n('Dup Item'), '', '3', 'kg', '10', '', ''], [$n('Dup Fine'), '', '1', 'kg', '10', '', '']]), 'dup.csv'), 'zzt-dup-' . $run . '.csv', $admin);
    $jD = (int)$upD['job_id'];
    import_job_analyse($pdo, $jD);
    $qD = null; foreach (import_job_review_summary($pdo, $jD)['questions'] as $q0) { if (strpos($q0['type'], 'duplicate') === 0) { $qD = $q0; } }
    import_job_answer($pdo, $jD, $qD['id'], 'separate-items', $admin);
    $sD = import_job_review_summary($pdo, $jD);
    $ck('skip: "different items, same name" blocks both rows with no question card ...', $sD['counts']['blocked'] === 2 && !$sD['can_confirm']);
    $dD = import_job_skip($pdo, $jD, ['mode' => 'blocked', 'dry' => true], $admin);
    $aD = import_job_skip($pdo, $jD, ['mode' => 'blocked', 'fingerprint' => $dD['fingerprint']], $admin);
    $ck('skip: ... and they can still be skipped, which unblocks the import', $dD['groups'][0]['code'] === 'separate-items' && $aD['ok'] && import_job_review_summary($pdo, $jD)['can_confirm']);
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, 'Pantry', 'crate', 0, 10, 0, 0)")->execute([$n('Clash Item')]);
    $upC = $upload($put($csv([[$n('Clash Item'), '', '2', 'kg', '10', '', ''], [$n('Clash Fine'), '', '1', 'kg', '10', '', '']]), 'clash.csv'), 'zzt-clash-' . $run . '.csv', $admin);
    $jC = (int)$upC['job_id'];
    import_job_analyse($pdo, $jC);
    $sC = import_job_review_summary($pdo, $jC);
    $qC = null; foreach ($sC['questions'] as $q0) { if ($q0['type'] === 'unit-clash') { $qC = $q0; } }
    $ck('blocked: a unit that differs from stock is a blocked "unit clash" with a skip choice', $qC && $qC['bad'] && in_array('skip', array_column($qC['options'], 'key'), true) && $sC['counts']['blocked'] === 1);
    import_job_answer($pdo, $jC, $qC['id'], 'skip', $admin);
    $sC2 = import_job_review_summary($pdo, $jC);
    $itC = null; foreach ($sC2['items'] as $it0) { if ($it0['typed_name'] === $n('Clash Item')) { $itC = $it0; } }
    $ck('skip: answering skip to a unit clash really takes the item out of the import (it used to stay ready)', $sC2['counts']['blocked'] === 0 && ($itC === null || $itC['status'] === 'skipped') && $sC2['can_confirm']);

    // ---- units item by item, suggestions, learning --------------------------------------------------------------------------------
    $peerCat = $n('Peer Beers');
    for ($z = 0; $z < 8; $z++) {
        $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, par_level, reorder_point) VALUES (?, ?, 'bottle', 0, 100, 0, 0)")->execute([$n('PeerBeer ' . $z), $peerCat]);
    }
    $rowsU = [
        [$peerCat, '', '', '', '', '', ''],
        [$n('NewLager'), '', '4', '', '100', '', ''],
        [$n('NewStout'), '', '6', '', '100', '', ''],
        [$n('Odd Spices'), '', '', '', '', '', ''],
        [$n('OddThing'), '', '2', '', '100', '', ''],
    ];
    $upU = $upload($put($csv($rowsU), 'units.csv'), 'zzt-units-' . $run . '.csv', $admin);
    $jU = (int)$upU['job_id'];
    import_job_analyse($pdo, $jU);
    $sU = import_job_review_summary($pdo, $jU);
    $qU = null; foreach ($sU['questions'] as $q0) { if ($q0['type'] === 'no-unit') { $qU = $q0; } }
    $ck('units: the sheet question lists its items, with a count of what is chosen / suggested / needs a choice', $qU && $qU['unit_stats']['total'] === 3 && $qU['unit_stats']['suggested'] === 2 && $qU['unit_stats']['ask'] === 1 && count($qU['unit_items']) === 3, json_encode($qU['unit_stats'] ?? $sU['questions']));
    $byN = []; foreach ($qU['unit_items'] as $x) { $byN[$x['name']] = $x; }
    $ck('units: every item carries its name, row, source cell and category; a suggestion cites its evidence (8 of 8 peers) and is NOT applied', ($byN[$n('NewLager')]['suggestion']['value'] ?? '') === 'bottle' && $byN[$n('NewLager')]['suggestion']['confidence'] === 'high' && $byN[$n('NewLager')]['suggestion']['evidence'][0]['n'] === 8 && $byN[$n('NewLager')]['source'] !== '' && $byN[$n('NewLager')]['category'] === $peerCat && $byN[$n('NewLager')]['unit'] === null && $byN[$n('OddThing')]['state'] === 'ask' && !$sU['can_confirm'], json_encode($byN[$n('NewLager')] ?? []));
    $uiAll = import_job_unit_items($pdo, $jU, $qU['sheet'], ['q' => 'stout']);
    $ck('units: "show all" is searchable and filterable on the server', $uiAll['ok'] && $uiAll['total'] === 1 && $uiAll['items'][0]['name'] === $n('NewStout') && import_job_unit_items($pdo, $jU, $qU['sheet'], ['which' => 'ask'])['total'] === 1);
    $uc0 = $answersOf($jU);
    $dU = import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'suggested', 'dry' => true], $admin);
    $ck('units (dry run): "Use suggestions" says how many and who, changes nothing', $dU['ok'] && $dU['n'] === 2 && $answersOf($jU) === $uc0);
    $bad = import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'suggested', 'fingerprint' => 'nope'], $admin);
    $ck('units: a stale fingerprint is refused', !$bad['ok'] && $answersOf($jU) === $uc0);
    $aU = import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'suggested', 'fingerprint' => $dU['fingerprint']], $admin);
    $sU2 = import_job_review_summary($pdo, $jU);
    $stU = []; foreach ($sU2['items'] as $it0) { $stU[$it0['typed_name']] = $it0; }
    $ck('units (click): the two suggested items now have the unit and are ready; the item with no evidence still waits', $aU['ok'] && $aU['applied'] === 2 && $stU[$n('NewLager')]['unit'] === 'bottle' && $stU[$n('NewLager')]['status'] === 'ready' && $stU[$n('OddThing')]['status'] === 'needs-answer' && !$sU2['can_confirm']);
    $ck('units: a unit that is not in the hotel list is refused; an item that is not waiting is refused', !import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'pick', 'choices' => [import_norm_text($n('OddThing')) => 'furlong']], $admin)['ok'] && !import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'pick', 'choices' => ['not an item' => 'kg']], $admin)['ok']);
    $nkOdd = import_norm_text($n('OddThing'));
    $pU = import_job_unit_choice($pdo, $jU, ['sheet' => $qU['sheet'], 'mode' => 'pick', 'choices' => [$nkOdd => 'kg']], $admin);
    $sU3 = import_job_review_summary($pdo, $jU);
    $qU3 = null; foreach ($sU3['questions'] as $q0) { if ($q0['type'] === 'no-unit') { $qU3 = $q0; } }
    $ck('units (one item): choosing the unit for the one remaining item finishes the sheet question and the import can be confirmed', $pU['ok'] && $qU3['answer'] === 'per-item' && $sU3['can_confirm'], json_encode($sU3['counts']));
    $learnN = function () use ($pdo, $run) { return (int)$pdo->query("SELECT COUNT(*) FROM import_aliases WHERE kind LIKE 'learned\\_%' AND term LIKE 'zzt " . strtolower($run) . " %'")->fetchColumn(); };
    $ck('learning: nothing is remembered before the import is committed', $learnN() === 0);
    $cfU = import_commit_stock($pdo, $jU, $admin, ['fail_after_apply' => true]);
    $ck('learning: a rolled-back commit teaches nothing', !$cfU['ok'] && $learnN() === 0);
    $cU = import_commit_stock($pdo, $jU, $admin);
    $ingU = []; foreach ($pdo->query("SELECT name, unit, category FROM stock_ingredients WHERE name LIKE 'ZZT $run New%' OR name LIKE 'ZZT $run Odd%'")->fetchAll(PDO::FETCH_ASSOC) as $x) { $ingU[$x['name']] = $x; }
    $ck('commit: the chosen units are what was created (bottle, bottle, kg)', $cU['ok'] && ($ingU[$n('NewLager')]['unit'] ?? '') === 'bottle' && ($ingU[$n('NewStout')]['unit'] ?? '') === 'bottle' && ($ingU[$n('OddThing')]['unit'] ?? '') === 'kg', json_encode($ingU));
    $lrn = import_load_learned($pdo);
    $ck('learning: after the commit each confirmed unit is remembered as a hotel-learned row (who and when kept)', $learnN() >= 3 && ($lrn['unit_item'][import_norm_text($n('OddThing'))]['v'] ?? '') === 'kg' && (int)($lrn['unit_item'][import_norm_text($n('OddThing'))]['by'] ?? 0) === $admin && !empty($lrn['unit_item'][import_norm_text($n('OddThing'))]['at']) && ($cU['result']['learned'] ?? 0) >= 3, json_encode($cU['result'] ?? []));
    $isLearned = (int)$pdo->query("SELECT COUNT(*) FROM import_aliases WHERE kind LIKE 'learned\\_%' AND is_hotel_learned = 1 AND term LIKE 'zzt " . strtolower($run) . " %'")->fetchColumn();
    $ck('learning: learned rows are marked as the hotel\'s own and never leak into the engine\'s header / unit vocabulary', $isLearned === $learnN() && !array_intersect($pdo->query("SELECT DISTINCT kind FROM import_aliases WHERE kind LIKE 'learned\_%'")->fetchAll(PDO::FETCH_COLUMN), ['unit', 'field']) && !in_array('zzt', array_map('strtolower', import_load_aliases($pdo)['units']), true));
    $sgNext = import_infer_unit(import_infer_index([], $lrn, [], import_load_aliases($pdo)['unit_synonyms']), ['name' => $n('OddThing'), 'name_source' => 'S!A1', 'category' => 'Whatever', 'sheet' => 'S', 'category_how' => 'section-row'], ['kg', 'bottle']);
    $ck('learning: the next import suggests it with high confidence and says "Remembered from your earlier import"', $sgNext['value'] === 'kg' && $sgNext['confidence'] === 'high' && strpos($sgNext['reason'], 'Remembered from your earlier import') !== false);
    $ck('seed: the unit-spelling table is seeded for hotels that were seeded before it existed (per kind)', (int)$pdo->query("SELECT COUNT(*) FROM import_aliases WHERE kind = 'unit_synonym' AND is_hotel_learned = 0")->fetchColumn() > 20 && import_load_aliases($pdo)['unit_synonyms']['btl'] === 'bottle');

    // ---- "skip these items" on the unit question leaves out only the items still waiting for a unit ------------------------------------
    $upK = $upload($put($csv([[$n('Keep A'), '', '2', '', '100', '', ''], [$n('Keep B'), '', '2', '', '100', '', ''], [$n('Keep C'), '', '2', '', '100', '', '']]), 'keep.csv'), 'zzt-keep-' . $run . '.csv', $admin);
    $jK = (int)$upK['job_id'];
    import_job_analyse($pdo, $jK);
    $qK = null; foreach (import_job_review_summary($pdo, $jK)['questions'] as $q0) { if ($q0['type'] === 'no-unit') { $qK = $q0; } }
    import_job_unit_choice($pdo, $jK, ['sheet' => $qK['sheet'], 'mode' => 'pick', 'choices' => [import_norm_text($n('Keep A')) => 'kg']], $admin);
    $qK = null; foreach (import_job_review_summary($pdo, $jK)['questions'] as $q0) { if ($q0['type'] === 'no-unit') { $qK = $q0; } }
    $rK = import_job_answer($pdo, $jK, $qK['id'], '_skip', $admin);
    $sK = import_job_review_summary($pdo, $jK);
    $stK = []; foreach ($sK['items'] as $it0) { $stK[$it0['typed_name']] = $it0['status']; }
    $ck('skip (unit question): the item that already has a unit survives; only the two still waiting are left out', $rK['ok'] && ($stK[$n('Keep A')] ?? '') === 'ready' && count($stK) === 1 && $sK['counts']['skipped'] === 2 && $sK['can_confirm'], json_encode([$stK, $sK['counts']]));

    // ---- self-healing through the database (repair listed, undone, put back) --------------------------------------------------------
    $upH = $upload($put($csv([[$n('Heal Lager'), '', '12 pcs', '', '100', '', ''], [$n('Heal Plain'), '', '5', 'pcs', '100', '', '']]), 'heal.csv'), 'zzt-heal-' . $run . '.csv', $admin);
    $jH2 = (int)$upH['job_id'];
    import_job_analyse($pdo, $jH2);
    $sH2 = import_job_review_summary($pdo, $jH2);
    $hl = import_job_list_view($pdo, $jH2, 'heals', []);
    $itH = null; foreach ($sH2['items'] as $it0) { if ($it0['typed_name'] === $n('Heal Lager')) { $itH = $it0; } }
    $ck('heal: "12 pcs" is read as 12 and pcs, ready, and listed as Fixed for you with before, after and the cell', $itH && $itH['status'] === 'ready' && abs($itH['balance'] - 12) < 0.0001 && $itH['unit'] === 'pcs' && $hl['total'] === 1 && $hl['rows'][0]['before'] === '12 pcs' && $hl['rows'][0]['source'] !== '' && $hl['rows'][0]['undoable'], json_encode($hl));
    $hu = import_job_heal_undo($pdo, $jH2, $hl['rows'][0]['id'], true, $admin);
    $sH3 = import_job_review_summary($pdo, $jH2);
    $hl3 = import_job_list_view($pdo, $jH2, 'heals', []);
    $ck('heal (undo): the original value is back, the row is blocked and asked again, the fix stays listed as taken back', $hu['ok'] && $hu['reanalysed'] && $sH3['counts']['blocked'] === 1 && $hl3['total'] === 1 && $hl3['rows'][0]['undone'] === true);
    $hr = import_job_heal_undo($pdo, $jH2, $hl['rows'][0]['id'], false, $admin);
    $ck('heal (redo): "Fix it again" puts the repair back', $hr['ok'] && import_job_review_summary($pdo, $jH2)['counts']['blocked'] === 0 && !import_job_heal_undo($pdo, $jH2, 'nonexistent00', true, $admin)['ok']);
    $cH2 = import_commit_stock($pdo, $jH2, $admin);
    $ck('heal: committing re-checks the repaired values against the file (the invariant) and imports the repaired quantity', $cH2['ok'] && $nameIn($n('Heal Lager')) === 1 && ($cH2['result']['fixed_for_you'] ?? 0) === 1, json_encode($cH2['error'] ?? $cH2['result'] ?? ''));

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
