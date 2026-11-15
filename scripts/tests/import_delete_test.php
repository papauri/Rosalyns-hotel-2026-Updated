<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Delete from Import history (includes/import/delete.php, admin/data-import.php action 'delete'), owner 2026-11-15.
 *
 * Usage (from the repo root, LIWONDE ONLY - the test hotel): php scripts/tests/import_delete_test.php
 *
 * SAFETY: nothing is ever committed. Everything happens inside ONE transaction that is rolled back at the end: its own jobs
 * (original_name zz-deltest-<run>...), two temporary accounts, and every delete runs with 'external_tx' => true (the library then
 * never begins/commits/rolls back). Backup files go to a temporary root folder that the test deletes; the one staged upload it
 * creates in storage/imports is deleted at the end. No DDL after the transaction opens; inTransaction() is checked after every call.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    exit("Refusing to run on Rosalyn's (live). Run this from the Liwonde repo.\n");
}
require_once $root . '/config/security.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/import-schema.php';
require_once $root . '/includes/import/delete.php';

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/zz_impdeltest_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0775, true);
$stagedFiles = [];
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
    global $tmpRoot, $stagedFiles, $pass, $fail;
    rm_tree($tmpRoot);
    foreach ($stagedFiles as $f) { if (is_file($f)) { @unlink($f); } }
    echo "\n$pass passed, $fail failed\n";
    exit($code);
}
function guard_tx(PDO $pdo, string $where): void
{
    if (!$pdo->inTransaction()) {
        echo "FATAL: the test transaction is gone after $where. Stopping. (Check import_jobs for zz-deltest rows.)\n";
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
        else { $row[$n] = 'ZZ_IMPDELTEST_'; }
    }
    $k = array_keys($row);
    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function jobRow(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM import_jobs WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function backupFiles(string $tmpRoot): array
{
    $d = $tmpRoot . '/' . RH_IMPORT_DELETE_DIR;
    return is_dir($d) ? array_values(array_filter(scandir($d), static fn($f) => !in_array($f, ['.', '..', '.htaccess', 'index.html'], true))) : [];
}

// ---- 0) static facts (no database) ------------------------------------------------------------------------------------
$src = php_strip_whitespace($root . '/includes/import/delete.php');
check(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $src) && stripos($src, 'TRUNCATE') === false, 'delete.php runs no DDL and no TRUNCATE');
check(substr_count($src, 'DELETE FROM') === 1 && strpos($src, 'DELETE FROM import_jobs WHERE id = ?') !== false, 'the only delete is one import_jobs row by id');
foreach (['committed' => true, 'failed' => true, 'cancelled' => true, 'uploaded' => false, 'analysed' => false, 'reviewing' => false, 'confirmed' => false] as $s => $can) {
    check((import_job_delete_block_reason(['status' => $s]) === '') === $can, "status '$s' " . ($can ? 'can' : 'cannot') . ' be deleted');
}
check(stripos(import_job_delete_block_reason(['status' => 'analysed', 'error_text' => 'Reading... since ' . date('Y-m-d H:i:s')]), 'being read') !== false, 'a file being read gets its own plain reason');
$page = (string)file_get_contents($root . '/admin/data-import.php');
check(strpos($page, "\$action === 'delete'") !== false && strpos($page, 'import_job_delete_checked(') !== false && strpos($page, "'can_delete'") !== false
    && strpos($page, "includes/stock-clear-lib.php") !== false, 'the page has the delete action (behind CSRF), uses the checked path and tells the list who can delete');
$posCsrf = strpos($page, 'validateCsrfToken((string)($_POST[\'csrf_token\']');
$posDel = strpos($page, "\$action === 'delete'");
check($posCsrf !== false && $posDel !== false && $posCsrf < $posDel, 'the CSRF check runs before the delete action');
$posModal = strpos($page, "renderModal('diDelModal'");
check($posModal !== false && $posModal < strpos($page, "require_once 'includes/admin-footer.php'") && $posModal > strpos($page, 'id="di-root"'), 'the confirm dialog is printed inside the page container (not after the footer)');

// ---- tables (DDL only here, before the transaction) ---------------------------------------------------------------------
ensureImportSchema($pdo);
foreach (['import_jobs', 'admin_users', 'admin_activity_log'] as $t) {
    if (!rh_table_exists($pdo, $t)) { echo "Table $t is missing on this database: the live checks cannot run.\n"; cleanup_and_exit(1); }
}

// ---- 1) everything below runs inside ONE transaction that is rolled back -----------------------------------------------
$run = strtolower(bin2hex(random_bytes(3)));
$pdo->beginTransaction();
try {
    $pw = 'ZZ_Impdel_pw_7!';
    $super = rh_super_admin_column_ready() ? ['is_super_admin' => 1] : [];
    $adminId = put($pdo, 'admin_users', ['username' => 'zz_impdel_admin_' . $run, 'email' => 'zz_impdel_admin_' . $run . '@example.invalid', 'full_name' => 'ZZ Impdel Admin', 'role' => 'admin', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)] + $super);
    $mgrId = put($pdo, 'admin_users', ['username' => 'zz_impdel_mgr_' . $run, 'email' => 'zz_impdel_mgr_' . $run . '@example.invalid', 'full_name' => 'ZZ Impdel Manager', 'role' => 'manager', 'is_active' => 1, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    guard_tx($pdo, 'temporary accounts');
    $admin = ['id' => $adminId, 'username' => 'zz_impdel_admin_' . $run, 'full_name' => 'ZZ Impdel Admin'];
    $mgr = ['id' => $mgrId, 'username' => 'zz_impdel_mgr_' . $run, 'full_name' => 'ZZ Impdel Manager'];
    $ext = ['external_tx' => true];

    // a committed job that still has its stored file (edge case: normally the commit deletes it)
    $stored = bin2hex(random_bytes(16)) . '.bin';
    $stagedPath = import_storage_dir() . '/' . $stored;
    $body = "Item,Qty\nZZ Impdel $run,3\n";
    file_put_contents($stagedPath, $body);
    $stagedFiles[] = $stagedPath;
    $sha = hash('sha256', $body . $run);
    $ins = $pdo->prepare("INSERT INTO import_jobs (target, original_name, stored_path, sha256, size_bytes, status, result_json, error_text, created_by, committed_by, committed_at, analysis_json)
                          VALUES ('stock', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute(['zz-deltest-' . $run . '-done.csv', $stored, $sha, strlen($body), 'committed', json_encode(['created' => 3, 'updated' => 1]), null, $adminId, $adminId, date('Y-m-d H:i:s'), json_encode(['ok' => true, 'note' => "caf\u{e9} \u{1F37A}"])]);
    $jDone = (int)$pdo->lastInsertId();
    $ins->execute(['zz-deltest-' . $run . '-review.csv', null, hash('sha256', 'r' . $run), 10, 'reviewing', null, null, $adminId, null, null, null]);
    $jReview = (int)$pdo->lastInsertId();
    $ins->execute(['zz-deltest-' . $run . '-reading.csv', null, hash('sha256', 'g' . $run), 10, 'uploaded', null, 'Reading... since ' . date('Y-m-d H:i:s'), $adminId, null, null, null]);
    $jReading = (int)$pdo->lastInsertId();
    $ins->execute(['zz-deltest-' . $run . '-failed.csv', null, hash('sha256', 'f' . $run), 10, 'failed', null, 'Could not be read.', $mgrId, null, null, null]);
    $jFailed = (int)$pdo->lastInsertId();
    guard_tx($pdo, 'test jobs');
    $dupSql = "SELECT COUNT(*) FROM import_jobs WHERE sha256 = ? AND status = 'committed'";   // the "already imported" check in store.php
    $dup = $pdo->prepare($dupSql);
    $dup->execute([$sha]);
    check((int)$dup->fetchColumn() === 1, 'before: the committed file counts as already imported');

    // --- refusals: nothing changes, no backup written
    $r = import_job_delete_checked($pdo, $tmpRoot, $jDone, $mgr, 'DELETE', $pw, $ext);
    guard_tx($pdo, 'manager refused');
    check(!$r['ok'] && stripos($r['error'], 'cannot delete import history') !== false && strpos($r['error'], 'Nothing was changed') !== false, 'a manager (no high-level permission) is refused with a plain reason', $r['error']);
    $r = import_job_delete_checked($pdo, $tmpRoot, $jDone, $admin, 'delete', $pw, $ext);
    check(!$r['ok'] && strpos($r['error'], 'DELETE') !== false, 'the word must be typed exactly (lower case refused)', $r['error']);
    $r = import_job_delete_checked($pdo, $tmpRoot, $jDone, $admin, 'DELETE', 'wrong-password', $ext);
    check(!$r['ok'] && stripos($r['error'], 'password') !== false, 'a wrong password is refused', $r['error']);
    $r = import_job_delete_checked($pdo, $tmpRoot, $jDone, $admin, 'DELETE', '', $ext);
    check(!$r['ok'], 'an empty password is refused');
    guard_tx($pdo, 'confirm refusals');
    check(jobRow($pdo, $jDone) !== null && backupFiles($tmpRoot) === [], 'after the refusals the entry is still there and no backup was written');

    $r = import_job_delete($pdo, $tmpRoot, $jReview, $admin, $ext);
    check(!$r['ok'] && stripos($r['error'], 'in progress') !== false && jobRow($pdo, $jReview) !== null, 'an import still in review is refused and kept', $r['error']);
    $r = import_job_delete($pdo, $tmpRoot, $jReading, $admin, $ext);
    check(!$r['ok'] && stripos($r['error'], 'being read') !== false && jobRow($pdo, $jReading) !== null, 'an import being read right now is refused and kept', $r['error']);
    $missing = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 100000 FROM import_jobs')->fetchColumn();
    $r = import_job_delete($pdo, $tmpRoot, $missing, $admin, $ext);
    check(!$r['ok'] && stripos($r['error'], 'not found') !== false && strpos($r['error'], 'Nothing was changed') !== false, 'a missing import gives a plain error', $r['error']);
    $r = import_job_delete($pdo, $tmpRoot, $jDone, $admin, []);
    check(!$r['ok'] && stripos($r['error'], 'in progress on this connection') !== false && jobRow($pdo, $jDone) !== null, 'it refuses to open its own transaction inside someone else\'s', $r['error']);
    guard_tx($pdo, 'status refusals');
    check(backupFiles($tmpRoot) === [], 'refused deletes leave no backup files');

    // --- the real thing (external transaction): committed job with its stored file
    $before = jobRow($pdo, $jDone);
    $r = import_job_delete_checked($pdo, $tmpRoot, $jDone, $admin, 'DELETE', $pw, $ext);
    guard_tx($pdo, 'delete committed');
    check($r['ok'] && $r['error'] === '', 'the holder with DELETE + own password deletes the committed entry', $r['error']);
    check(jobRow($pdo, $jDone) === null, 'the import_jobs row is gone');
    $snapPath = $tmpRoot . '/' . RH_IMPORT_DELETE_DIR . '/' . $r['snapshot'];
    check($r['snapshot'] !== '' && basename($r['snapshot']) === $r['snapshot'] && preg_match('/^import-' . $jDone . '-\d{14}(-\d+)?\.json\.gz$/', $r['snapshot']) && is_file($snapPath),
        'the backup import-<id>-<YmdHis>.json.gz is written (file name only is returned)', $r['snapshot']);
    $snap = is_file($snapPath) ? json_decode((string)gzdecode((string)file_get_contents($snapPath)), true) : null;
    $same = is_array($snap) && isset($snap['row']) && count($snap['row']) === count($before);
    $why = is_array($snap) ? '' : 'backup not readable';
    if ($same) { foreach ($before as $k => $v) { if (!array_key_exists($k, $snap['row']) || $snap['row'][$k] !== $v) { $same = false; $why = "column $k: " . var_export($v, true) . ' vs ' . var_export($snap['row'][$k] ?? null, true); break; } } }
    check($same && ($snap['format'] ?? '') === 'rh-import-delete-1' && (int)($snap['deleted_by']['id'] ?? 0) === $adminId, 'the backup holds the whole row exactly (every column, unicode intact) and who deleted it', $why);
    check(!empty($r['job']['file_backup']) && is_file($tmpRoot . '/' . RH_IMPORT_DELETE_DIR . '/' . $r['job']['file_backup'])
        && hash_file('sha256', $tmpRoot . '/' . RH_IMPORT_DELETE_DIR . '/' . $r['job']['file_backup']) === hash('sha256', $body)
        && ($snap['file_backup'] ?? '') === $r['job']['file_backup'], 'the stored upload is copied into the backup folder byte for byte');
    check(is_file($stagedPath), 'with an outside transaction the original stored file is left for the purge (the caller may still roll back)');
    check(is_file($tmpRoot . '/' . RH_IMPORT_DELETE_DIR . '/.htaccess'), 'the backup folder denies web access');
    check($r['job']['id'] === $jDone && $r['job']['status'] === 'committed' && $r['job']['created'] === 3 && $r['job']['updated'] === 1 && $r['job']['ran_by'] === 'ZZ Impdel Admin',
        'the returned summary names the job, its status, counts and who ran it');
    $dup->execute([$sha]);
    check((int)$dup->fetchColumn() === 0, 'after: the "already imported" check no longer finds the file');
    $lg = $pdo->prepare("SELECT details FROM admin_activity_log WHERE user_id = ? AND action = 'import_history_delete' ORDER BY id DESC LIMIT 1");
    $lg->execute([$adminId]);
    $d = (string)$lg->fetchColumn();
    check(strpos($d, 'import #' . $jDone) !== false && strpos($d, 'zz-deltest-' . $run . '-done.csv') !== false && strpos($d, '3 created, 1 updated') !== false
        && strpos($d, 'Started by ZZ Impdel Admin') !== false && strpos($d, 'Nothing it imported was changed.') !== false, 'the activity log names the job, file, counts, who ran it and that nothing imported changed', $d);
    check(strpos($d, 'copied to the backup') !== false && strpos($d, 'moved') === false, 'with an outside transaction the log says the file was copied (not moved)', $d);

    // --- a failed job without a file; a caller's own closing sentence (log_note, used by the stock count wipe)
    $r = import_job_delete($pdo, $tmpRoot, $jFailed, $admin, $ext + ['log_note' => 'ZZ count wipe note.']);
    $lg->execute([$adminId]);
    $d = (string)$lg->fetchColumn();
    check(strpos($d, 'import #' . $jFailed) !== false && strpos($d, 'ZZ count wipe note.') !== false && strpos($d, 'Nothing it imported was changed.') === false, 'log_note replaces the closing sentence of the log line', $d);
    guard_tx($pdo, 'delete failed job');
    check($r['ok'] && jobRow($pdo, $jFailed) === null && $r['job']['file_backup'] === '' && $r['job']['created'] === null && $r['job']['ran_by'] === 'ZZ Impdel Manager',
        'a failed entry (no stored file) is deleted too', $r['error']);
    $r = import_job_delete($pdo, $tmpRoot, $jFailed, $admin, $ext);
    check(!$r['ok'] && stripos($r['error'], 'not found') !== false, 'deleting the same entry twice gives a plain "not found"');
    guard_tx($pdo, 'end');
} catch (Throwable $e) {
    bad('unexpected error', $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM import_jobs WHERE original_name LIKE 'zz-deltest-$run-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'zz_impdel_%_$run'")->fetchColumn();
check($left === 0, 'rolled back: no test jobs or accounts are left');
cleanup_and_exit($fail > 0 ? 1 : 0);
