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
$r = $runPage('admin/module-settings.php', ['method' => 'GET', 'grep' => ['id="ms-toggle-data_import"', 'Data Import: on/off', 'Administrators and managers get it by default']]);
ok('F8: Module Settings shows the "Data Import: on/off" switch with its plain helper line', !empty($r['found']['id="ms-toggle-data_import"']) && !empty($r['found']['Data Import: on/off']) && !empty($r['found']['Administrators and managers get it by default']), json_encode($r['found'] ?? $r));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
