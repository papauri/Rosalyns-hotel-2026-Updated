<?php
/**
 * Child process for scripts/tests/stock_count_race.php. Runs the REAL admin/stock-count.php "approve_count" POST as an
 * admin user with a normal (committing) database connection, but only starts when the shared start time is reached, so
 * two of these approve at the same moment. Refuses to touch any count whose reference does not start with 20991231-RC.
 * Usage: php scripts/tests/stock_count_race_child.php <base64 json {count_id:int, start_at:float, tag:string}>
 * Prints one line: @@RESULT@@{json: flash (success message), alerts (error messages shown on the page)}.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__, 2);
chdir($root);
$cfg = json_decode(base64_decode((string)($argv[1] ?? '')), true);
if (!is_array($cfg) || empty($cfg['count_id'])) { fwrite(STDERR, "bad arguments\n"); exit(2); }
require $root . '/config/database.php';
$refChk = $pdo->prepare('SELECT reference FROM stock_counts WHERE id = ?');
$refChk->execute([(int)$cfg['count_id']]);
if (strpos((string)$refChk->fetchColumn(), '20991231-RC') !== 0) { fwrite(STDERR, "refusing to run: not a test count\n"); exit(2); }

register_shutdown_function(function () use ($cfg) {
    $buf = '';
    while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
    $alerts = [];
    if (preg_match_all('#<div class="alert[^"]*"[^>]*>.*?</div>#s', $buf, $m)) {
        foreach ($m[0] as $a) { $alerts[] = trim(preg_replace('/\s+/', ' ', strip_tags($a))); }
    }
    $flash = (string)($_SESSION['stock_msg'] ?? '');
    $err = (string)($_SESSION['stock_err'] ?? '');
    fwrite(STDOUT, "\n@@RESULT@@" . json_encode(['tag' => $cfg['tag'] ?? '', 'flash' => $flash, 'err' => $err, 'alerts' => $alerts, 'stale' => stripos($buf, 'out of date') !== false, 'bytes' => strlen($buf), 'ended' => microtime(true)]) . "\n");
});

$admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch();
if (!$admin) { fwrite(STDERR, "no admin user\n"); exit(2); }
$sessDir = __DIR__ . '/sessions';
if (!is_dir($sessDir)) { mkdir($sessDir, 0777, true); }
session_save_path($sessDir);
define('RH_SESSION_KEEP_SAVE_PATH', true);
ini_set('session.use_cookies', '0');
session_id('race' . bin2hex(random_bytes(6)));
session_start();
$_SESSION['admin_user_id'] = (int)$admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['admin_full_name'] = $admin['full_name'];
$_SESSION['admin_user'] = ['id' => (int)$admin['id'], 'username' => $admin['username'], 'full_name' => $admin['full_name'], 'role' => $admin['role']];
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$_SESSION['admin_last_activity'] = time();
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/admin/stock-count.php';
$_SERVER['REQUEST_URI'] = '/admin/stock-count.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = ['action' => 'approve_count', 'count_id' => (string)(int)$cfg['count_id'], 'csrf_token' => $_SESSION['csrf_token']];
$_GET = [];
$_REQUEST = $_POST;
ob_start();
chdir($root . '/admin');
require_once $root . '/admin/admin-init.php'; // sign-in and CSRF setup happen BEFORE the start line

$startAt = (float)($cfg['start_at'] ?? 0);
while (microtime(true) < $startAt) { usleep(100); }
require $root . '/admin/stock-count.php';
