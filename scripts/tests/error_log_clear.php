<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Error logs panel (admin/cache-management.php + admin/includes/error-log-manager.php).
 * Usage (from the repo root): php scripts/tests/error_log_clear.php
 *
 * Part 1 works on a throw-away copy of the folder layout under the system temp dir (the helper takes
 * an overridable site root), never on the real error_log files. Part 2 drives the real page in
 * page_harness.php (rolled-back transaction) but ONLY with requests that must be refused, and checks
 * the real log files were not emptied. Nothing is written to the database that survives.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function run_page(string $page, array $post, array $setup, array $verify, string $asUser = '', array $grep = []): array {
    global $h;
    $cfg = ['post' => $post, 'verify' => $verify, 'setup' => $setup, 'grep' => $grep];
    if ($asUser !== '') { $cfg['as_username'] = $asUser; }
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = shell_exec($cmd);
    if (trim((string)$out) === '') { usleep(500000); $out = shell_exec($cmd); }
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400), 'rolled_back' => false];
}
// The page shows its messages as toasts (a script in the page), so each check greps the rendered page for the text.
function said(array $r, string $needle): bool { return !empty($r['found'][$needle]); }
function rrmdir(string $d): void {
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $d . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p) || @rmdir($p); } // a link is removed, never followed
    }
    @rmdir($d);
}

require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/error-log-manager.php';

// ---- Part 1: helper on a scratch site root ----
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_errlog_test_' . bin2hex(random_bytes(4));
$site = $tmp . DIRECTORY_SEPARATOR . 'site';
$outside = $tmp . DIRECTORY_SEPARATOR . 'outside';
foreach ([$site, $site . '/admin/api', $site . '/api', $site . '/docs/guides/assets', $site . '/logs', $outside] as $d) { mkdir($d, 0777, true); }
$files = ['error_log' => 'root', 'admin/error_log' => 'admin', 'api/error_log' => 'api', 'admin/api/error_log' => 'admin_api', 'docs/guides/assets/error_log' => 'docs_assets'];
foreach ($files as $rel => $k) { file_put_contents($site . '/' . $rel, str_repeat('x', 100 + strlen($k))); }
file_put_contents($outside . '/error_log', 'precious outside file');
file_put_contents($site . '/keep.php', '<?php // not a log');

try {
    $list = rh_errorlog_list($site, '');
    check(count($list) === 5, 'lists the five whitelisted logs that exist', json_encode($list));
    $bySize = array_column($list, 'size', 'key');
    check(($bySize['admin'] ?? 0) === 105 && !empty($list[0]['modified']), 'size and modified time are reported');

    // whitelisted file is truncated, kept, and nothing else is touched
    $r = rh_errorlog_clear('admin', $site, '');
    check($r['ok'] === true && $r['size_before'] === 105 && $r['rel'] === 'admin/error_log', 'whitelisted key clears', json_encode($r));
    check(is_file($site . '/admin/error_log') && filesize($site . '/admin/error_log') === 0, 'cleared file still exists at 0 bytes');
    check(filesize($site . '/api/error_log') === 103 && filesize($site . '/error_log') === 104, 'other logs untouched');
    check(file_get_contents($site . '/keep.php') === '<?php // not a log', 'non-log files untouched');

    // refused keys
    $before = filesize($site . '/error_log');
    foreach (['bogus', '', 'keep.php', '../outside/error_log', '..\\outside\\error_log', '../../outside/error_log', 'admin/error_log', 'ROOT', 'php_ini', "root\0", '/etc/passwd', 'C:\\Windows\\win.ini'] as $badKey) {
        $r = rh_errorlog_clear($badKey, $site, '');
        check($r['ok'] === false, 'refuses key ' . json_encode($badKey));
    }
    check(rh_errorlog_clear(['root'], $site, '')['ok'] === false && rh_errorlog_clear(null, $site, '')['ok'] === false, 'refuses array / null key');
    check(filesize($site . '/error_log') === $before && file_get_contents($outside . '/error_log') === 'precious outside file', 'refused keys changed nothing');

    // PHP ini error_log: only when absolute and inside the root
    $iniIn = $site . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-errors.log';
    file_put_contents($iniIn, 'abcdef');
    $l = array_column(rh_errorlog_list($site, $iniIn), null, 'key');
    check(isset($l['php_ini']) && $l['php_ini']['rel'] === 'logs/php-errors.log', 'ini error_log inside the root is listed', json_encode(array_keys($l)));
    $r = rh_errorlog_clear('php_ini', $site, $iniIn);
    check($r['ok'] && filesize($iniIn) === 0, 'ini log clears');
    $iniOut = $outside . DIRECTORY_SEPARATOR . 'error_log';
    check(!isset(array_column(rh_errorlog_list($site, $iniOut), null, 'key')['php_ini']), 'ini error_log outside the root is not listed');
    check(rh_errorlog_clear('php_ini', $site, $iniOut)['ok'] === false && file_get_contents($outside . '/error_log') === 'precious outside file', 'ini error_log outside the root cannot be cleared');
    check(!isset(array_column(rh_errorlog_list($site, $site . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'error_log'), null, 'key')['php_ini']), 'ini log equal to a whitelisted file is not listed twice');
    check(!isset(array_column(rh_errorlog_list($site, 'syslog'), null, 'key')['php_ini']) && !isset(array_column(rh_errorlog_list($site, 'relative/path.log'), null, 'key')['php_ini']), 'syslog / relative ini values ignored');

    // a symlink must never be followed (skipped where the OS will not let us create one)
    @unlink($site . '/api/error_log');
    if (@symlink($outside . '/error_log', $site . '/api/error_log')) {
        $r = rh_errorlog_clear('api', $site, '');
        check($r['ok'] === false && file_get_contents($outside . '/error_log') === 'precious outside file', 'symlinked log pointing outside is refused');
        check(!in_array('api', array_column(rh_errorlog_list($site, ''), 'key'), true), 'symlinked log is not listed');
        @unlink($site . '/api/error_log');
        rrmdir($site . '/docs/guides/assets');
        if (@symlink($outside, $site . '/docs/guides/assets')) {
            check(rh_errorlog_clear('docs_assets', $site, '')['ok'] === false, 'symlinked folder pointing outside is refused');
        } else {
            ok('symlinked folder case skipped (cannot create symlinks here)');
        }
    } else {
        ok('symlink cases skipped (cannot create symlinks here)');
    }
    // missing file
    check(rh_errorlog_clear('api', $site, '')['ok'] === false, 'a missing log is refused, not created');
    check(!file_exists($site . '/api/error_log'), 'clear never creates a file');

    // clear all
    $cleared = 0;
    foreach (rh_errorlog_list($site, '') as $row) { if (rh_errorlog_clear($row['key'], $site, '')['ok']) { $cleared++; } }
    check($cleared >= 1 && filesize($site . '/error_log') === 0, 'clearing every listed log works');
    check(file_get_contents($outside . '/error_log') === 'precious outside file', 'outside file still intact at the end');
} finally {
    @unlink($site . '/api/error_log');
    if (is_link($site . '/docs/guides/assets')) { @unlink($site . '/docs/guides/assets') || @rmdir($site . '/docs/guides/assets'); } // a symlink, if one was made (removed as a link, never followed)
    rrmdir($tmp);
}

// ---- Admin check + logging (rolled back) ----
$adm = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$non = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adm || !$non) { bad('Fixture: an active admin and an active non-admin user exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$_SESSION = ['admin_user_id' => (int)$adm['id'], 'admin_username' => $adm['username']];
check(rh_errorlog_user_is_admin() === true, 'admin user passes the admin check');
$_SESSION = ['admin_user_id' => (int)$non['id'], 'admin_username' => $non['username']];
check(rh_errorlog_user_is_admin() === false, 'non-admin user fails the admin check');
$_SESSION = [];
check(rh_errorlog_user_is_admin() === false, 'signed-out request fails the admin check');
$_SESSION = ['admin_user_id' => (int)$adm['id'], 'admin_username' => $adm['username']];
$pdo->beginTransaction();
$tag = 'admin/zz_test_error_log_' . bin2hex(random_bytes(3));
rh_errorlog_record('admin', ['ok' => true, 'rel' => $tag, 'size_before' => 4321, 'error' => '']);
$st = $pdo->prepare("SELECT details FROM admin_activity_log WHERE user_id = ? AND action = 'error_log_cleared' AND details LIKE ?");
$st->execute([(int)$adm['id'], '%' . $tag . '%']);
$d = (string)$st->fetchColumn();
check($d !== '' && strpos($d, '4321') !== false && strpos($d, (string)$adm['username']) !== false, 'activity log records who, which file and the size before', $d);
$pdo->rollBack();
$st->execute([(int)$adm['id'], '%' . $tag . '%']);
check($st->fetchColumn() === false, 'test activity row rolled back');

// ---- Part 2: the real page, refusals only ----
$real = [];
foreach (rh_errorlog_list() as $row) { $real[$row['key']] = $row['size']; }
$unchanged = function () use ($real): bool {
    foreach (rh_errorlog_list() as $row) {
        if (($real[$row['key']] ?? 0) > 0 && $row['size'] === 0) { return false; }
    }
    return true;
};
$tok = ['csrf_token' => 'not-a-valid-token'];

echo "== Real page: refusals ==\n";
$r = run_page('admin/cache-management.php', ['action' => 'clear_all_error_logs'] + $tok, [], [], '', ['security token']);
check(said($r, 'security token'), 'no valid CSRF token: clear all refused', json_encode($r['raw'] ?? ''));
check(!empty($r['rolled_back']) && empty($r['leak']), 'CSRF case rolled back');
$r = run_page('admin/cache-management.php', ['action' => 'clear_error_log', 'log_key' => 'root'] + $tok, [], [], '', ['security token']);
check(said($r, 'security token'), 'no valid CSRF token: single clear refused');
check($unchanged(), 'real logs not emptied by the CSRF refusals');

foreach (['bogus', '../index.php', '..\\..\\config\\database.php', 'admin/error_log'] as $badKey) {
    $r = run_page('admin/cache-management.php', ['action' => 'clear_error_log', 'log_key' => $badKey], [], [], '', ['not available to clear']);
    check(said($r, 'not available to clear'), 'admin with key ' . json_encode($badKey) . ' is refused');
}
check($unchanged() && is_file($root . '/config/database.php') && is_file($root . '/index.php'), 'real logs and files unchanged after bad keys');

$uid = (int)$non['id'];
$grant = ["DELETE FROM user_permissions WHERE user_id = $uid AND permission_key = 'cache'", "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES ($uid, 'cache', 1)"];
$ver = ["SELECT COUNT(*) AS c FROM system_event_log WHERE source = 'cache_management' AND message LIKE 'Error log clear refused%' AND user_id = $uid"];
$r = run_page('admin/cache-management.php', ['action' => 'clear_all_error_logs'], $grant, $ver, $non['username'], ['Only an administrator can clear', 'error-logs-panel']);
check(said($r, 'Only an administrator can clear'), 'non-admin with the Cache permission: clear all refused', json_encode($r['raw'] ?? ''));
$r2 = run_page('admin/cache-management.php', ['action' => 'clear_error_log', 'log_key' => 'root'], $grant, $ver, $non['username'], ['Only an administrator can clear', 'error-logs-panel']);
check(said($r2, 'Only an administrator can clear'), 'non-admin with the Cache permission: single clear refused');
check(!said($r2, 'error-logs-panel'), 'non-admin does not even see the Error logs panel');
check($unchanged(), 'real logs not emptied by the non-admin attempts');

$r = run_page('admin/cache-management.php', ['action' => 'noop'], [], [], '', ['error-logs-panel', 'Clear all error logs']);
check(said($r, 'error-logs-panel'), 'admin sees the Error logs panel');
check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), 'admin page run rolled back, no leak');
check($unchanged(), 'real logs still intact at the end');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
