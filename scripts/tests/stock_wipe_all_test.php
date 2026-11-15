<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Wipe all stock and every record (owner 2026-11-16): permission stock_wipe_all + the top section of admin/stock-clear.php.
 *
 * Usage (from the repo root, TEST HOTEL ONLY): php scripts/tests/stock_wipe_all_test.php
 *
 * SAFETY: refuses to run on a database whose name contains "rosalyn" (Rosalyn's is LIVE). Everything happens inside ONE
 * transaction that is rolled back: temporary accounts and marker rows (names start ZZ_WIPEALLTEST_), and the wipe runs with
 * 'external_tx' => true and 'full_backup' => false. Snapshot and Import-delete backup files go to a temporary folder that the
 * test deletes. Two page POSTs go through scripts/tests/page_harness.php (rolled back; both are refused before anything runs).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function skip(string $l): void { global $skip; $skip++; echo "SKIP $l\n"; }

require_once $root . '/config/database.php';
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/stock-clear-lib.php';

if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    echo "Refused: this test runs on the test hotel only (database " . DB_NAME . ").\n";
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/zz_wipealltest_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0775, true);
function rm_tree(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $d . '/' . $f;
        is_dir($p) ? rm_tree($p) : @unlink($p);
    }
    @rmdir($d);
}
function cleanup_and_exit(int $code): void
{
    global $tmpRoot, $pass, $fail, $skip;
    rm_tree($tmpRoot);
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit($code);
}
function guard_tx(PDO $pdo, string $where): void
{
    if (!$pdo->inTransaction()) {
        echo "FATAL: the test transaction is gone after $where. Stopping. (Check the database.)\n";
        cleanup_and_exit(2);
    }
}
/** Insert one row, filling every required column with a harmless value. Returns the new id. */
function put(PDO $pdo, string $table, array $set): int
{
    $cols = $pdo->prepare("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $cols->execute([$table]);
    $row = $set;
    foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $n = $c['COLUMN_NAME'];
        if (array_key_exists($n, $row) || stripos((string)$c['EXTRA'], 'auto_increment') !== false || preg_match('/(VIRTUAL|STORED) GENERATED/i', (string)$c['EXTRA'])) { continue; }
        if ($c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null) { continue; }
        $t = strtolower($c['DATA_TYPE']);
        if (in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true)) { $row[$n] = 0; }
        elseif ($t === 'enum') { preg_match("/^enum\('([^']*)'/i", $c['COLUMN_TYPE'], $m); $row[$n] = $m[1] ?? ''; }
        elseif ($t === 'date') { $row[$n] = '2000-01-01'; }
        elseif (in_array($t, ['datetime', 'timestamp'], true)) { $row[$n] = '2000-01-01 00:00:00'; }
        elseif ($t === 'json') { $row[$n] = '{}'; }
        else { $row[$n] = 'ZZ_WIPEALLTEST_'; }
    }
    $k = array_keys($row);
    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function cnt(PDO $pdo, string $t): int { return (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
function has(PDO $pdo, string $t): bool { return rh_sc_table_exists($pdo, $t); }

// ---- 0) the permission and the page, statically -----------------------------------------------------------------------
$all = getAllPermissions();
$P = $all['stock_wipe_all'] ?? null;
check(is_array($P) && $P['label'] === 'Wipe all stock (full reset)' && $P['category'] === 'Settings' && $P['group'] === 'settings_advanced' && $P['page'] === 'stock-clear.php',
    'stock_wipe_all is defined: label, Settings, settings_advanced, stock-clear.php');
check(is_array($P) && stripos($P['description'], 'Import history') !== false && stripos($P['description'], 'not touched') !== false, 'the description says what it removes and what it leaves');
check(in_array('stock_wipe_all', rh_super_only_permissions(), true), 'stock_wipe_all is Tier S (super admins only)');
check(in_array('stock_wipe_all', rh_admin_only_permissions(), true), 'stock_wipe_all is administrators-only (Tier A includes Tier S)');
$inDefault = [];
foreach (getAllRoles() as $rk => $rd) {
    if ($rk === 'admin') { continue; } // admin = null = all keys, held back by the tier rule
    if (in_array('stock_wipe_all', (array)getDefaultPermissionsForRole($rk), true)) { $inDefault[] = $rk; }
}
check($inDefault === [], 'stock_wipe_all is in no role default list', implode(', ', $inDefault));
check(getPermissionForPage('stock-clear.php') === 'data_reset' && getAlternatePermissionsForPage('stock-clear.php') === ['stock_wipe_all'],
    'Clear Stock Data keeps data_reset as its page key and also opens for stock_wipe_all');
$pageSrc = (string)file_get_contents($root . '/admin/stock-clear.php');
check(strpos($pageSrc, "in_array(\$action, ['preview', 'clear', 'cw_preview', 'cw_confirm', 'cw_wipe'], true) && !\$canReset") !== false,
    'the page: clear, count wipe and their previews still need data_reset on every POST');
check(strpos($pageSrc, "in_array(\$action, ['wa_preview', 'wa_confirm', 'wa_wipe'], true) && !\$canWipeAll") !== false
    && strpos($pageSrc, 'rh_sc_wipe_all_request($pdo, $ROOT, $user, $_POST)') !== false, 'the page: the wipe-all steps need stock_wipe_all on every POST');
check(strpos($pageSrc, "\$action === 'undo' && !rh_sc_undo_allowed(\$canReset, \$canWipeAll, rh_sc_manifest(") !== false
    && strpos($pageSrc, '$pastClears = rh_sc_visible_clears(rh_sc_list_clears($ROOT, $area), $canReset);') !== false, 'the page: Undo and Past clears use the shared gate helpers');
check(strpos($pageSrc, "if (\$wr['stage'] === 'confirm') {\n                sc_note_failure();") !== false, 'the page: a wrong word or password counts toward the lockout');
check(strpos((string)file_get_contents($root . '/admin/admin-init.php'), 'rhHasPagePermission((int)$user[\'id\'], $current_page)') !== false, 'admin-init opens a page for its alternate permissions');

// ---- 0b) the pure rule ----------------------------------------------------------------------------------------------------
$r = 'rh_permission_resolve';
$mgr = getDefaultPermissionsForRole('manager');
check($r('admin', true, 'stock_wipe_all', null, [], [], [], 1) === true, 'rule: a super admin has it');
check($r('admin', false, 'stock_wipe_all', null, [], [], [], 1) === false, 'rule: a plain administrator does not, while a super admin exists');
check($r('admin', false, 'stock_wipe_all', null, [], [], ['stock_wipe_all' => true], 1) === true, 'rule: a plain administrator has it once a super admin switched it on');
check($r('manager', false, 'stock_wipe_all', true, $mgr, [], ['stock_wipe_all' => true], 0) === false, 'rule: a manager never has it, even with a granting row');

// ---- tables -------------------------------------------------------------------------------------------------------------
if (function_exists('ensureStockTablesExist')) { ensureStockTablesExist(); } // DDL: before the transaction, never inside it
foreach (['stock_ingredients', 'stock_batches', 'stock_adjustments', 'stock_recipes', 'stock_recipe_ingredients', 'stock_suppliers', 'admin_users', 'user_permissions'] as $t) {
    if (!has($pdo, $t)) { echo "Table $t is missing on this database: the live checks cannot run.\n"; cleanup_and_exit(1); }
}
$hasImport = has($pdo, 'import_jobs');
$hasSuperCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'is_super_admin'")->fetchColumn() > 0;
if (!$hasSuperCol) { echo "admin_users.is_super_admin is missing: the tier checks cannot run.\n"; cleanup_and_exit(1); }
$area = rh_sc_area('stock');

// ---- 1) inside ONE transaction that is rolled back ---------------------------------------------------------------------
$pdo->beginTransaction();
try {
    $pw = 'ZZ_Wipealltest_pw_9!';
    $mk = static function (string $u, string $role, int $super) use ($pdo, $pw): int {
        return put($pdo, 'admin_users', ['username' => 'ZZ_WIPEALLTEST_' . $u, 'email' => 'zz_wipealltest_' . $u . '@example.invalid', 'full_name' => 'ZZ_WIPEALLTEST ' . $u,
            'role' => $role, 'is_active' => 1, 'is_super_admin' => $super, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    };
    $superId = $mk('super', 'admin', 1);
    $plainId = $mk('plain', 'admin', 0);
    $mgrId = $mk('mgr', 'manager', 0);
    guard_tx($pdo, 'temporary accounts');
    rh_active_super_admin_count(true); // at least one active super admin now exists: no Tier S fallback for plain administrators
    $actor = ['id' => $superId, 'username' => 'ZZ_WIPEALLTEST_super', 'full_name' => 'ZZ_WIPEALLTEST super'];
    $plainActor = ['id' => $plainId, 'username' => 'ZZ_WIPEALLTEST_plain', 'full_name' => 'ZZ_WIPEALLTEST plain'];
    $mgrActor = ['id' => $mgrId, 'username' => 'ZZ_WIPEALLTEST_mgr', 'full_name' => 'ZZ_WIPEALLTEST mgr'];

    // permission, through the database
    check(hasPermission($superId, 'stock_wipe_all') === true, 'a super admin holds stock_wipe_all');
    check(hasPermission($plainId, 'stock_wipe_all') === false, 'a plain administrator does not, until a super admin switches it on');
    check(rhHasPagePermission($plainId, 'stock-clear.php') === false, 'so the page does not open for that plain administrator');
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $plainActor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], ['external_tx' => true, 'full_backup' => false]);
    check($rq['ok'] === false && $rq['stage'] === 'permission', 'the wipe POST refuses a plain administrator without the permission');
    put($pdo, 'user_permissions', ['user_id' => $plainId, 'permission_key' => 'stock_wipe_all', 'is_granted' => 1, 'granted_by' => $superId]);
    rh_admin_permission_overrides($plainId, true);
    check(hasPermission($plainId, 'stock_wipe_all') === true, 'a plain administrator holds it once a super admin switched it on');
    check(hasPermission($plainId, 'data_reset') === false && rhHasPagePermission($plainId, 'stock-clear.php') === true, 'the page opens for stock_wipe_all alone (without data_reset)');
    put($pdo, 'user_permissions', ['user_id' => $mgrId, 'permission_key' => 'stock_wipe_all', 'is_granted' => 1, 'granted_by' => $superId]);
    check(hasPermission($mgrId, 'stock_wipe_all') === false, 'a manager is refused even with a row granting it');
    [$okM] = rh_sc_authorize_wipe_all($pdo, $mgrId);
    check($okM === false, 'rh_sc_authorize_wipe_all refuses the manager');
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $mgrActor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], ['external_tx' => true, 'full_backup' => false]);
    check($rq['ok'] === false && $rq['stage'] === 'permission', 'the wipe POST refuses the manager');
    guard_tx($pdo, 'permission checks');

    // marker rows
    $supId = put($pdo, 'stock_suppliers', ['name' => 'ZZ_WIPEALLTEST_supplier']);
    $ingId = put($pdo, 'stock_ingredients', ['name' => 'ZZ_WIPEALLTEST_item']);
    put($pdo, 'stock_batches', ['ingredient_id' => $ingId, 'supplier_id' => $supId]);
    put($pdo, 'stock_adjustments', ['ingredient_id' => $ingId]);
    $recId = put($pdo, 'stock_recipes', []);
    put($pdo, 'stock_recipe_ingredients', ['recipe_id' => $recId, 'ingredient_id' => $ingId]);
    if (has($pdo, 'stock_counts') && has($pdo, 'stock_count_lines')) {
        $cId = put($pdo, 'stock_counts', ['reference' => 'ZZ_WIPEALLTEST_count']);
        put($pdo, 'stock_count_lines', ['count_id' => $cId, 'ingredient_id' => $ingId]);
    }
    $doneJob = $openJob = 0;
    if ($hasImport) {
        $doneJob = put($pdo, 'import_jobs', ['original_name' => 'ZZ_WIPEALLTEST_done.xlsx', 'sha256' => str_repeat('a', 64), 'status' => 'committed', 'stored_path' => null]);
        $openJob = put($pdo, 'import_jobs', ['original_name' => 'ZZ_WIPEALLTEST_open.xlsx', 'sha256' => str_repeat('b', 64), 'status' => 'reviewing', 'stored_path' => null]);
    }
    guard_tx($pdo, 'marker rows');
    $before = [];
    foreach ($area['order'] as $t) { if (has($pdo, $t)) { $before[$t] = cnt($pdo, $t); } }
    $jobsBefore = $hasImport ? cnt($pdo, 'import_jobs') : 0;

    // preview (read-only)
    $pv = rh_sc_wipe_all_preview($pdo);
    check((int)$pv['counts']['stock_ingredients'] === $before['stock_ingredients'] && count($pv['groups']) === count($area['groups']), 'the preview counts every stock table over every group');
    if ($hasImport) {
        check((int)$pv['import']['total'] === $jobsBefore && (int)$pv['import']['removable'] >= 1, 'the preview counts the Import history entries');
        check(in_array($openJob, array_column($pv['import']['kept'], 'job_id'), true), 'the preview lists the in-progress import as kept');
    }

    // refusals: no tick, wrong words, wrong password (super admin, who has the permission)
    $opts = ['external_tx' => true, 'full_backup' => false];
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], $opts);
    check($rq['ok'] === false && $rq['stage'] === 'understand', 'the wipe POST refuses without "I understand"');
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'WIPE', 'confirm_password' => $pw], $opts);
    check($rq['ok'] === false && $rq['stage'] === 'confirm' && strpos($rq['error'], 'WIPE ALL') !== false, 'the wipe POST refuses the wrong typed words (WIPE)');
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'wipe all', 'confirm_password' => $pw], $opts);
    check($rq['ok'] === false && $rq['stage'] === 'confirm', 'the wipe POST refuses lower-case words');
    $rq = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => 'wrong-password'], $opts);
    check($rq['ok'] === false && $rq['stage'] === 'confirm' && stripos($rq['error'], 'password') !== false, 'the wipe POST refuses the wrong password');
    guard_tx($pdo, 'refusals');
    check(cnt($pdo, 'stock_ingredients') === $before['stock_ingredients'] && (!$hasImport || cnt($pdo, 'import_jobs') === $jobsBefore), 'the refused POSTs changed nothing');

    // ---- the wipe ------------------------------------------------------------------------------------------------------
    $w = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], $opts);
    guard_tx($pdo, 'the wipe');
    check($w['ok'] === true && $w['stage'] === 'done', 'the wipe succeeds', (string)$w['error']);
    if ($w['ok']) {
        $res = $w['result'];
        foreach ($area['order'] as $t) {
            if (has($pdo, $t)) { check(cnt($pdo, $t) === 0, "$t is empty after the wipe"); }
        }
        $man = rh_sc_manifest($tmpRoot, $res['id']);
        check(is_array($man) && rh_sc_manifest_is_full_wipe($man), 'the snapshot manifest is a full wipe (every group)');
        if ($hasImport) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM import_jobs WHERE id = ?');
            $st->execute([$doneJob]);
            check((int)$st->fetchColumn() === 0, 'the finished import job was removed');
            $st->execute([$openJob]);
            check((int)$st->fetchColumn() === 1, 'the in-progress import job was kept');
            $byId = array_column($res['import'], null, 'job_id');
            check(isset($byId[$doneJob]) && $byId[$doneJob]['ok'] === true && $byId[$doneJob]['snapshot'] !== '', 'the result reports the finished job as removed, with its backup copy');
            check(isset($byId[$openJob]) && $byId[$openJob]['ok'] === false && strpos($byId[$openJob]['error'], 'Kept') === 0, 'the result reports the in-progress job as kept, with the reason');
            check(cnt($pdo, 'import_jobs') === $res['import_kept'], 'every import job left is one the result reports as kept');
            check((int)$res['import_removed'] >= 1 && (int)$res['import_removed'] + (int)$res['import_kept'] === $jobsBefore, 'removed + kept = the entries there were');
            check(count((array)glob($tmpRoot . '/backups/import-delete/import-' . $doneJob . '-*.json.gz')) === 1, 'the removed job has its backup copy in the (temporary) backups folder');
        } else {
            skip('import_jobs table missing: import checks');
        }
        $lg = $pdo->prepare("SELECT details FROM admin_activity_log WHERE user_id = ? AND action = 'stock_wipe_all'");
        $lg->execute([$superId]);
        $lines = $lg->fetchAll(PDO::FETCH_COLUMN);
        check(count($lines) === 1 && strpos((string)$lines[0], 'Import history:') !== false, 'one stock_wipe_all line with the totals is in the activity log');
        $lg = $pdo->prepare("SELECT COUNT(*) FROM admin_activity_log WHERE user_id = ? AND action = 'stock_clear'");
        $lg->execute([$superId]);
        check((int)$lg->fetchColumn() === 1, 'the stock part logged its own stock_clear line');

        // the stock part can be undone while the tables are still empty
        $u = rh_sc_run_undo($pdo, $tmpRoot, $actor, $res['id'], ['external_tx' => true]);
        guard_tx($pdo, 'the undo');
        check($u['ok'] === true, 'Undo puts the wiped stock back', (string)$u['error']);
        foreach ($before as $t => $n) { check(cnt($pdo, $t) === $n, "$t is back to $n row(s) after Undo"); }
        check(rh_sc_manifest_is_full_wipe((array)rh_sc_manifest($tmpRoot, $res['id'])) && (rh_sc_manifest($tmpRoot, $res['id'])['kind'] ?? '') === 'wipe_all', 'the wipe-all manifest carries the wipe_all marker (kept after Undo)');

        // A Clear with every group ticked (data_reset) is NOT a wipe-all: a stock_wipe_all-only administrator cannot undo or see it.
        $dr = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', array_keys($area['groups']), $opts);
        guard_tx($pdo, 'the tick-everything clear');
        check($dr['ok'] === true, 'a tick-everything data_reset clear succeeds', (string)$dr['error']);
        if ($dr['ok']) {
            $drMan = rh_sc_manifest($tmpRoot, $dr['id']);
            check(is_array($drMan) && !isset($drMan['kind']) && !rh_sc_manifest_is_full_wipe($drMan), 'its manifest has no wipe_all marker, so it is not a wipe-all (like every older manifest)');
            check(rh_sc_undo_allowed(false, true, $drMan) === false, 'a stock_wipe_all-only administrator is refused Undo of a tick-everything data_reset clear');
            check(rh_sc_undo_allowed(true, false, $drMan) === true, 'a data_reset holder may still undo it');
            check(!in_array($dr['id'], array_column(rh_sc_visible_clears(rh_sc_list_clears($tmpRoot), false), 'id'), true), 'it is not in Past clears for a stock_wipe_all-only administrator');
            check(in_array($dr['id'], array_column(rh_sc_visible_clears(rh_sc_list_clears($tmpRoot), true), 'id'), true), 'it is in Past clears for a data_reset holder');
            $ud = rh_sc_run_undo($pdo, $tmpRoot, $actor, $dr['id'], ['external_tx' => true]);
            guard_tx($pdo, 'undo of the tick-everything clear');
            check($ud['ok'] === true, 'the data_reset holder undoes it', (string)$ud['error']);
        }
        // The stock_wipe_all-only administrator's own wipe-all can be seen and undone by them.
        check(hasPermission($plainId, 'data_reset') === false, 'the plain administrator still has stock_wipe_all only');
        $pwip = rh_sc_wipe_all_request($pdo, $tmpRoot, $plainActor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], $opts);
        guard_tx($pdo, 'the plain administrator wipe-all');
        check($pwip['ok'] === true, 'the stock_wipe_all-only administrator can run the wipe-all', (string)$pwip['error']);
        if ($pwip['ok']) {
            $pMan = rh_sc_manifest($tmpRoot, $pwip['result']['id']);
            check(rh_sc_undo_allowed(false, true, $pMan) === true, 'their own wipe-all passes the Undo gate');
            check(in_array($pwip['result']['id'], array_column(rh_sc_visible_clears(rh_sc_list_clears($tmpRoot), false), 'id'), true), 'their own wipe-all is in their Past clears');
            $up = rh_sc_run_undo($pdo, $tmpRoot, $plainActor, $pwip['result']['id'], ['external_tx' => true]);
            guard_tx($pdo, 'the plain administrator undo');
            check($up['ok'] === true && cnt($pdo, 'stock_ingredients') === $before['stock_ingredients'], 'their own wipe-all is undone and the stock is back', (string)$up['error']);
        }

        // nothing left to wipe: refused before any backup
        if ($hasImport) { $pdo->prepare('DELETE FROM import_jobs WHERE id <> ? AND status IN (\'committed\',\'failed\',\'cancelled\')')->execute([$openJob]); }
        $c2 = rh_sc_run_clear($pdo, $tmpRoot, $actor, 'stock', array_keys($area['groups']), $opts);
        $e2 = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], $opts);
        check($c2['ok'] === true && $e2['ok'] === false && $e2['stage'] === 'empty', 'with nothing to remove the wipe is refused (no needless backup)', (string)$e2['error']);

        // stock already empty but one finished import left (the state both hotels are in now): the wipe still removes it
        if ($hasImport) {
            $lateJob = put($pdo, 'import_jobs', ['original_name' => 'ZZ_WIPEALLTEST_late.xlsx', 'sha256' => str_repeat('c', 64), 'status' => 'committed', 'stored_path' => null]);
            $e3 = rh_sc_wipe_all_request($pdo, $tmpRoot, $actor, ['wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => $pw], $opts);
            guard_tx($pdo, 'the empty-stock wipe');
            $st = $pdo->prepare('SELECT COUNT(*) FROM import_jobs WHERE id = ?');
            $st->execute([$lateJob]);
            check($e3['ok'] === true && $e3['stage'] === 'done' && (int)$st->fetchColumn() === 0 && is_array(rh_sc_manifest($tmpRoot, (string)$e3['result']['id'])),
                'with the stock already empty, the wipe still succeeds and removes the finished import', (string)$e3['error']);
            if ($e3['ok']) {
                $u3 = rh_sc_run_undo($pdo, $tmpRoot, $actor, (string)$e3['result']['id'], ['external_tx' => true]);
                guard_tx($pdo, 'undo of the empty-stock wipe');
                check(is_array($u3) && isset($u3['ok']), 'Undo of an empty wipe answers without an error', (string)($u3['error'] ?? ''));
            }
        }
    }
} catch (Throwable $e) {
    bad('unexpected error inside the test transaction', $e->getMessage() . ' @' . $e->getLine());
}

if ($pdo->inTransaction()) {
    $pdo->rollBack();
    ok('the test transaction was rolled back');
} else {
    bad('the test transaction was already closed before the rollback');
}
$left = 0;
foreach (['stock_suppliers' => 'name', 'stock_ingredients' => 'name', 'admin_users' => 'username'] as $t => $col) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$col` LIKE ?"); $st->execute(['ZZ_WIPEALLTEST_%']);
    $left += (int)$st->fetchColumn();
}
if ($hasImport) { $st = $pdo->prepare("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE ?"); $st->execute(['ZZ_WIPEALLTEST_%']); $left += (int)$st->fetchColumn(); }
check($left === 0, 'no test rows or accounts remain after the rollback');

// ---- 2) the page itself, through the rolled-back harness (both POSTs are refused before anything runs) ---------------------
$h = $root . '/scripts/tests/page_harness.php';
$runPage = static function (array $cfg) use ($h): array {
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/stock-clear.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr($out, -300)];
};
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
$verify = ['SELECT COUNT(*) AS n FROM stock_ingredients', $hasImport ? 'SELECT COUNT(*) AS n FROM import_jobs' : 'SELECT 0 AS n'];
$nowIng = cnt($pdo, 'stock_ingredients');
$nowJobs = $hasImport ? cnt($pdo, 'import_jobs') : 0;
$sameCounts = static fn(array $r): bool => isset($r['verify'][0][0]['n'], $r['verify'][1][0]['n']) && (int)$r['verify'][0][0]['n'] === $nowIng && (int)$r['verify'][1][0]['n'] === $nowJobs;
$post = ['action' => 'wa_wipe', 'wa_understand' => '1', 'confirm_word' => 'WIPE ALL', 'confirm_password' => 'not-the-password'];

// A manager if there is one, else any other non-administrator account (the test hotel keeps a permanent test staff account).
$mgrName = (string)$pdo->query("SELECT username FROM admin_users WHERE role <> 'admin' AND is_active = 1 ORDER BY role = 'manager' DESC, id LIMIT 1")->fetchColumn();
if ($mgrName !== '') {
    $r1 = $runPage(['as_username' => $mgrName, 'post' => $post, 'verify' => $verify, 'grep' => ['Wiped', 'All stock and its records wiped']]);
    check($clean($r1) && $sameCounts($r1) && empty($r1['found']['All stock and its records wiped']), 'page: a manager (or other non-administrator) POSTing the wipe is refused and nothing changes', json_encode(array_intersect_key($r1, ['error' => 1, 'raw' => 1, 'verify' => 1])));
} else {
    skip('no active non-administrator account here: manager page POST');
}
// The first active administrator (whatever its permissions): wrong password -> refused either at the door or at the confirm.
$r2 = $runPage(['post' => $post, 'session' => [], 'verify' => $verify, 'grep' => ['That password is not correct', 'All stock and its records wiped']]);
check($clean($r2) && $sameCounts($r2) && empty($r2['found']['All stock and its records wiped']), 'page: the wipe POST with the wrong password is refused and nothing changes', json_encode(array_intersect_key($r2, ['error' => 1, 'raw' => 1, 'verify' => 1])));

cleanup_and_exit($fail > 0 ? 1 : 0);
