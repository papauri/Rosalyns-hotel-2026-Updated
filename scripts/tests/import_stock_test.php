<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Data Import, stock target (Phase 1b-backend): permissions, kill switch, upload rules, analysis, answers, commit, rollback.
 * Usage (project root): php scripts/tests/import_stock_test.php
 *
 * Runs against the configured live database exactly like stock_sheet_import.php: the scenarios run in
 * scripts/tests/import_stock_child.php through page_harness.php (ONE outer transaction that can never commit, rolled back at the
 * end, DDL skipped). Every row is named 'ZZT <run> ...'; no real stock or price data is used. Staged files (outside the
 * database) are deleted by the child. The only things written for real are the additive Import tables + settings + seeded aliases
 * (ensureImportSchema) and the empty stock_opening_batches table, the same as a first real use would create.
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

// DDL happens here, outside the harness (it skips DDL)
ok('schema: ensureImportSchema() runs and is repeatable', ensureImportSchema($pdo) && ensureImportSchema($pdo));
rh_ssi_ensure_table($pdo);
foreach (['import_jobs', 'import_aliases'] as $t) {
    ok("schema: table $t exists", rh_table_exists($pdo, $t));
}
ok('schema: no import_rows table (answers live on the job)', !rh_table_exists($pdo, 'import_rows'));
$n1 = (int)$pdo->query('SELECT COUNT(*) FROM import_aliases WHERE is_hotel_learned = 0')->fetchColumn();
import_seed_aliases($pdo);
ok('schema: the alias seed is added once only', (int)$pdo->query('SELECT COUNT(*) FROM import_aliases WHERE is_hotel_learned = 0')->fetchColumn() === $n1 && $n1 > 100);
// v2 phrases reach a database seeded before them (top-up once per seed version), each exactly once
$seedVer = (string)(int)import_default_aliases()['version'];
$st = $pdo->prepare("SELECT COUNT(*) FROM import_aliases WHERE kind = 'seed_version' AND term = ?"); $st->execute([$seedVer]);
ok('schema: the seed version marker v' . $seedVer . ' is written exactly once', (int)$st->fetchColumn() === 1);
$missing = []; $doubled = [];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM import_aliases WHERE kind = ? AND term = ? AND maps_to = ?");
foreach (import_alias_seed_rows() as [$k, $tm, $mp]) { $cnt->execute([$k, $tm, $mp]); $c = (int)$cnt->fetchColumn(); if ($c === 0) { $missing[] = "$k:$tm"; } elseif ($c > 1) { $doubled[] = "$k:$tm"; } }
ok('schema: every seed phrase (old and new) is in this database exactly once', !$missing && !$doubled, 'missing ' . implode(', ', array_slice($missing, 0, 8)) . ' doubled ' . implode(', ', array_slice($doubled, 0, 8)));
require_once $root . '/includes/import/analyze.php';
$dbAl = import_load_aliases($pdo);
ok('schema: the database vocabulary carries the Min Qty fallback and the new derived fields', ($dbAl['field_fallbacks']['min_qty'] ?? '') === 'min_level' && in_array('lead_time', $dbAl['derived'], true) && in_array('min_qty', $dbAl['derived'], true));
$gb = []; foreach (['Total Buying Cost', 'Avg Cost Value', 'Total Avg Cost', 'Value in Stock', 'Days in Stock', 'Current Level Value'] as $gh) { $gm = import_match_header($gh, $dbAl); if ($gm['conf'] >= import_map_auto_threshold() && !import_field_is_derived((string)$gm['field'], $dbAl)) { $gb[] = $gh . '->' . $gm['field']; } }
ok('schema: with the database vocabulary a total / value / days heading is never auto-read as a unit price or balance', in_array('days', $dbAl['guard_words'], true) && !$gb, implode('; ', $gb));
$hdrSets = ['our export' => ['Name', 'Category', 'Unit', 'Current Qty', 'Min Qty', 'Reorder Point', 'Par Level', 'Lead Time (days)', 'Cost/Unit', 'Stock Value', 'Next Expiry', 'Status'],
    'renamed copy' => ['Name', 'Category', 'Unit', 'Current Quantity level', 'Minimum Quantity level', 'Reorder Point', 'Par Level', 'Lead Time (days)', 'Unit Cost', 'Stock Value', 'Next Expiry', 'Status']];
foreach ($hdrSets as $lbl => $hs) {
    $tmpCsv = tempnam(sys_get_temp_dir(), 'zzt');
    $fh = fopen($tmpCsv, 'w'); fputcsv($fh, $hs);
    foreach ([['ZZT A', 'ZZT Cat', 'kg', 5, 1, 2, 9, 3, '10.00', '50.00', '', 'Active'], ['ZZT B', 'ZZT Cat', 'L', 6, 1, 2, 9, 3, '20.00', '120.00', '2027-03-27', 'Active'], ['ZZT C', 'ZZT Cat', 'kg', 7, 1, 2, 9, 3, '30.00', '210.00', '', 'Active']] as $rw) { fputcsv($fh, $rw); }
    fclose($fh);
    $anDb = import_analyze_file($tmpCsv, 'zzt.csv', $dbAl);
    @unlink($tmpCsv);
    $hq = array_filter($anDb['questions'], function ($q) { return in_array($q['type'], ['mapping-unsure', 'no-item-column', 'sheet-unknown'], true); });
    $flds = array_map(function ($c) { return ($c['field'] ?? '-') . (!empty($c['derived']) ? '(grey)' : ''); }, $anDb['sheets'][0]['columns'] ?? []);
    ok("schema: with this database's vocabulary the $lbl headings need zero header questions", !$hq && $flds === ['item_name', 'category', 'unit', 'qty_single', 'min_qty(grey)', 'min_level', 'max_level', 'lead_time(grey)', 'unit_price', 'total_cost(grey)', 'expiry', 'status(grey)'], implode(',', $flds));
}
ok('schema: the kill switch row exists and is not switched on by the schema', in_array(rh_import_setting('data_import_enabled', $pdo), ['0', '1'], true));
ok('permissions.php: data_import is in the manager default list and not in receptionist', (function () {
    require_once dirname(__DIR__, 2) . '/admin/includes/permissions.php';
    return in_array('data_import', getDefaultPermissionsForRole('manager'), true) && !in_array('data_import', getDefaultPermissionsForRole('receptionist'), true);
})());

$binBefore = [];
foreach ((array)@scandir(dirname(__DIR__, 2) . '/storage/imports') as $f) { if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f)) { $binBefore[$f] = true; } }
$run = strtoupper(bin2hex(random_bytes(3)));
$cfg = base64_encode(json_encode(['post' => ['run' => $run]]));
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_stock_child.php ' . escapeshellarg($cfg) . ' 2>&1');
// the child can run longer than the server's wait_timeout: this (idle) link may have been dropped meanwhile - reconnect like config/database.php
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
    echo "  (reconnected to the database after the child run)
";
}
$h = ['error' => 'no result'];
if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
ok('harness: the scenarios ran and were rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr((string)$out, -600));
$j = $h['json'] ?? null;
if (!is_array($j) || !isset($j['checks'])) {
    ok('child printed its checks', false, substr((string)$out, -800));
} else {
    foreach ($j['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
}
ok('no ZZT rows were left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT $run %'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-%-$run.csv'")->fetchColumn() === 0);
// Staged files live outside the database, so the rolled-back jobs cannot clean them. The child deletes the ones it made; this sweeps (and
// counts) any new file that no job row points at, so a leak is reported AND removed instead of piling up in storage/imports.
$stray = 0;
$dir = dirname(__DIR__, 2) . '/storage/imports';
$referenced = array_flip($pdo->query('SELECT stored_path FROM import_jobs WHERE stored_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
foreach ((array)@scandir($dir) as $f) {
    if (preg_match('/^[a-f0-9]{32}\.bin$/', (string)$f) && !isset($binBefore[$f]) && !isset($referenced[$f])) { $stray++; @unlink($dir . '/' . $f); }
}
ok('no staged files from this run remain on disk (any leak was deleted and is reported here)', $stray === 0, (string)$stray);

// "When was this counted?" + regular counts after an approved opening count (critical patch 2026-11-25). One rolled-back harness run per
// case (scripts/tests/import_count_time_child.php): the child's own checks come back in a file, then the REAL Stock Count page acts and
// the verify SQL reads the result before the rollback.
$ctCase = function (string $case, array $verify, array $grep = []) use ($root, $run) {
    $r = $run . 'CT' . strtoupper($case);
    $outF = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzt_ct_out_' . $r . '.json';
    @unlink($outF);
    $sub = function ($sql) use ($r) { return str_replace('{r}', $r, $sql); };
    $cfg = ['post' => ['run' => $r, 'case' => $case, 'out' => $outF], 'verify' => array_map($sub, $verify), 'session_keys' => ['stock_msg'], 'grep' => $grep];
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' scripts/tests/import_count_time_child.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $h = ['error' => 'no result'];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { $h = $x; } }
    $c = json_decode((string)@file_get_contents($outF), true);
    @unlink($outF);
    ok('count time (' . $case . '): the run was rolled back, nothing leaked', !empty($h['rolled_back']) && empty($h['leak']) && empty($h['marker_lost']), substr($out, -600));
    if (!is_array($c) || !isset($c['checks'])) {
        ok('count time (' . $case . '): the child wrote its checks', false, substr($out, -800));
        $c = ['checks' => [], 'T' => ''];
    }
    foreach ($c['checks'] as [$name, $good, $detail]) { ok($name, (bool)$good, (string)$detail); }
    $h['T'] = (string)($c['T'] ?? '');
    $h['diag'] = json_encode(['flash' => $h['output_flash'] ?? null, 'msg' => $h['session']['stock_msg'] ?? null]);
    return $h;
};
$jobsOf = "SELECT id FROM import_jobs WHERE original_name LIKE 'zzt-ct%-{r}.csv'";
// (a) opening count: 7 now, 3 sold after T; the file says 10 (A) and 9 (B). Approved on the real page by a different person.
$h = $ctCase('a', ["SELECT name, current_quantity FROM stock_ingredients WHERE name LIKE 'ZZT {r} %' ORDER BY name",
    "SELECT status, is_opening FROM stock_counts WHERE import_job_id IN ($jobsOf)"]);
$q = []; foreach ((array)($h['verify'][0] ?? []) as $row) { $q[substr((string)$row['name'], -11)] = (float)$row['current_quantity']; }
ok('count time (a): after approval A stays 7 (10 in the file = its stock at the count time) and B is 6 (1 short of 10, 3 sold since)',
    isset($q['Mango Juice'], $q['Tonic Water']) && abs($q['Mango Juice'] - 7) < 0.0001 && abs($q['Tonic Water'] - 6) < 0.0001, json_encode($h['verify'] ?? null) . ' ' . $h['diag']);
ok('count time (a): the opening count is approved', ($h['verify'][1][0]['status'] ?? '') === 'approved' && (int)($h['verify'][1][0]['is_opening'] ?? 0) === 1, json_encode($h['verify'][1] ?? null) . ' ' . $h['diag']);
// (b) regular count, shortage, no reason, draft; then the real page saves a reason: the count time stays T.
$h = $ctCase('b', ["SELECT l.counted_at, l.reason_code, l.system_quantity, l.actual_quantity, sc.snapshot_at, sc.status, sc.is_opening FROM stock_count_lines l
    INNER JOIN stock_counts sc ON sc.id = l.count_id INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name = 'ZZT {r} Ginger Ale' AND sc.import_job_id IN ($jobsOf)"]);
$l = $h['verify'][0][0] ?? [];
ok('count time (b): saving a reason on the real page keeps counted_at = T, snapshot_at = T and system 10 (the reason is saved, still a draft)',
    $h['T'] !== '' && ($l['reason_code'] ?? '') === 'spillage' && (string)($l['counted_at'] ?? '') === $h['T'] && (string)($l['snapshot_at'] ?? '') === $h['T']
    && abs((float)($l['system_quantity'] ?? 0) - 10) < 0.0001 && abs((float)($l['actual_quantity'] ?? 0) - 9) < 0.0001 && ($l['status'] ?? '') === 'draft' && (int)($l['is_opening'] ?? 1) === 0,
    json_encode([$h['T'], $l]) . ' ' . $h['diag']);
// (c) refusals: no count time, a future one, one before a later approved count of the item.
$ctCase('c', []);
// (e) regular count with no shortage: submitted; the person who imported it cannot approve it (two-person rule on).
$h = $ctCase('e', ["SELECT sc.status, sc.approved_by, i.current_quantity FROM stock_counts sc INNER JOIN stock_count_lines l ON l.count_id = sc.id
    INNER JOIN stock_ingredients i ON i.id = l.ingredient_id WHERE i.name = 'ZZT {r} Cola Zero' AND sc.import_job_id IN ($jobsOf)"], ['because you imported it']);
$e = $h['verify'][0][0] ?? [];
ok('count time (e): the importer\'s own approval is refused: the count stays submitted and the stock stays 7',
    !empty($h['found']['because you imported it']) && ($e['status'] ?? '') === 'submitted' && empty($e['approved_by']) && abs((float)($e['current_quantity'] ?? 0) - 7) < 0.0001, json_encode($e) . ' ' . $h['diag']);
ok('count time: no ZZT rows of these runs were left behind', (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZT {$run}CT%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zzt-ct%-{$run}CT%.csv'")->fetchColumn() === 0);

// commit-stock.php: the dashboard cache is cleared AFTER the commit (source order check; the cache layer is off inside the harness)
$cs = (string)file_get_contents($root . '/includes/import/commit-stock.php');
$pc = strpos($cs, '$pdo->commit();');
ok('commit-stock.php clears the stock dashboard cache after $pdo->commit()', $pc !== false && strpos($cs, 'rh_clear_stock_dashboard_cache();', $pc) !== false);

// F8: the on/off control for the kill switch on Module Settings (admin only, CSRF, ships OFF). Each call rolls back.
$runPage = function (string $page, array $cfg) use ($root) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { return $x; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -300)];
};
$clean = function (array $r) { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); };
$q = "SELECT setting_value FROM site_settings WHERE setting_key = 'data_import_enabled'";
$r = $runPage('admin/api/toggle-module.php', ['post' => ['module_key' => 'data_import', 'is_enabled' => '1'], 'verify' => [$q]]);
ok('F8: an administrator can switch Data Import ON (site setting data_import_enabled = 1)', !empty($r['json']['success']) && ($r['verify'][0][0]['setting_value'] ?? '') === '1' && $clean($r), json_encode($r['json'] ?? $r));
$r = $runPage('admin/api/toggle-module.php', ['post' => ['module_key' => 'data_import', 'is_enabled' => '0'], 'setup' => ["INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'"], 'verify' => [$q]]);
ok('F8: and OFF again (value 0, which the fail-closed reader treats as off)', !empty($r['json']['success']) && ($r['verify'][0][0]['setting_value'] ?? '') === '0' && $clean($r), json_encode($r['json'] ?? $r));
$nonAdmin = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($nonAdmin !== '') {
    $r = $runPage('admin/api/toggle-module.php', ['post' => ['module_key' => 'data_import', 'is_enabled' => '1'], 'as_username' => $nonAdmin,
        'setup' => ["INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '0', 'import') ON DUPLICATE KEY UPDATE setting_value = '0'"], 'verify' => [$q]]);
    ok('F8: a non-administrator cannot switch it on (setting stays 0)', empty($r['json']['success']) && ($r['verify'][0][0]['setting_value'] ?? '0') === '0', json_encode($r['json'] ?? $r));
} else {
    echo "SKIP F8 non-administrator check (no non-admin user in this database)\n";
}
$r = $runPage('admin/api/toggle-module.php', ['post' => ['module_key' => 'data_import', 'is_enabled' => '1', 'csrf_token' => 'wrong'], 'verify' => [$q]]);
ok('F8: a wrong CSRF token is refused', empty($r['json']['success']));
$r = $runPage('admin/module-settings.php', ['method' => 'GET', 'grep' => ['id="ms-toggle-data_import"', '>Data Import</div>', 'Administrators and managers get it by default']]);
ok('F8: Module Settings shows the "Data Import" switch with its plain helper line', !empty($r['found']['id="ms-toggle-data_import"']) && !empty($r['found']['>Data Import</div>']) && !empty($r['found']['Administrators and managers get it by default']), json_encode($r['found'] ?? $r));


// Step 3 paging / bulk endpoints: the same gates as every other action (job ownership, switch, CSRF). Each call rolls back.
$mgrName = (string)$pdo->query("SELECT username FROM admin_users WHERE is_active = 1 AND role = 'manager' ORDER BY id LIMIT 1")->fetchColumn();
$jobSetup = ["INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES ('data_import_enabled', '1', 'import') ON DUPLICATE KEY UPDATE setting_value = '1'",
    "INSERT INTO import_jobs (target, original_name, stored_path, sha256, size_bytes, status, created_by) SELECT 'stock', 'zzt-perm-$run.csv', NULL, REPEAT('0', 64), 1, 'analysed', id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"];
// di_json() clears every output buffer before it prints, so the harness cannot capture the reply: read the JSON line the page wrote to stdout
$runRaw = function (array $cfg) use ($root, $clean) {
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tests/page_harness.php') . ' admin/data-import.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = preg_match('/@@RESULT@@(.*)$/m', $out, $m) ? (json_decode($m[1], true) ?: []) : [];
    $json = preg_match('/^\{"ok".*\}\s*$/m', preg_replace('/@@RESULT@@.*$/m', '', $out), $j) ? json_decode(trim($j[0]), true) : null;
    return ['json' => $json, 'clean' => $clean($res), 'raw' => substr($out, 0, 300)];
};
if ($mgrName !== '') {
    foreach (['review_page' => ['facets' => '1'], 'bulk' => ['btype' => 'case-price', 'sig' => 'per-unit', 'role' => 'per-unit', 'dry' => '1'], 'answer' => ['question' => 'x', 'answer' => 'skip'], 'skip' => ['mode' => 'blocked', 'dry' => '1'], 'unskip' => ['uids' => ['x']], 'unit_items' => ['sheet' => 'x'], 'unit_choice' => ['sheet' => 'x', 'mode' => 'suggested', 'dry' => '1'], 'heal_undo' => ['heal' => 'x'], 'fixed_list' => ['kind' => 'skipped'], 'tick_all' => ['field' => 'quantity', 'mode' => 'set', 'dry' => '1']] as $act => $extra) {
        $r = $runRaw(['post' => ['action' => $act, 'job' => '{jid}'] + $extra, 'as_username' => $mgrName, 'setup' => $jobSetup, 'vars' => ['jid' => 'SELECT MAX(id) FROM import_jobs']]);
        ok("ownership: a manager cannot use '$act' on a job an administrator uploaded (not found, nothing read)", isset($r['json']['ok']) && $r['json']['ok'] === false && stripos((string)($r['json']['error'] ?? ''), 'not found') !== false && $r['clean'], json_encode($r['json'] ?? $r['raw']));
    }
    $r = $runRaw(['post' => ['action' => 'review_page', 'job' => '{jid}'], 'setup' => $jobSetup, 'vars' => ['jid' => 'SELECT MAX(id) FROM import_jobs']]);
    ok('ownership: the administrator gets past the gate on that job (it only says the import cannot be reviewed: no analysis stored)', isset($r['json']['ok']) && $r['json']['ok'] === false && stripos((string)($r['json']['error'] ?? ''), 'not found') === false, json_encode($r['json'] ?? $r['raw']));
    $r = $runRaw(['post' => ['action' => 'review_page', 'job' => '{jid}', 'csrf_token' => 'wrong'], 'setup' => $jobSetup, 'vars' => ['jid' => 'SELECT MAX(id) FROM import_jobs']]);
    ok('security: review_page with a wrong CSRF token is refused', isset($r['json']['ok']) && $r['json']['ok'] === false && stripos((string)($r['json']['error'] ?? ''), 'expired') !== false, json_encode($r['json'] ?? $r['raw']));
} else {
    echo "SKIP Step 3 endpoint ownership checks (no active manager user in this database)\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
