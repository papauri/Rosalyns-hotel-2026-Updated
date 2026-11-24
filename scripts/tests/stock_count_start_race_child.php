<?php
/**
 * Child process for scripts/tests/stock_count_start_race.php. Runs the REAL admin/stock-count.php "start_count" POST as
 * the given user with a normal (committing) database connection, but only starts when the shared start time is reached,
 * so two of these start at the same moment. Refuses anything but a category count of a ZZSTART test category, run as
 * the admin or a zz_scstart_ throwaway user.
 * Usage: php scripts/tests/stock_count_start_race_child.php <base64 json {user_id, start_at, tag, category, shift, opening}>
 * Prints one line: @@RESULT@@{json: flash, err (dialog message), link (Open that count href), start_held / opening_held
 * (this connection still holds the lock when the page has finished: must be false on every path)}.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__, 2);
chdir($root);
$cfg = json_decode(base64_decode((string)($argv[1] ?? '')), true);
if (!is_array($cfg) || empty($cfg['user_id']) || strpos((string)($cfg['category'] ?? ''), 'ZZSTART ') !== 0) { fwrite(STDERR, "bad arguments\n"); exit(2); }
require $root . '/config/database.php';

$u = $pdo->prepare('SELECT id, username, full_name, role FROM admin_users WHERE id = ? AND is_active = 1');
$u->execute([(int)$cfg['user_id']]);
$signIn = $u->fetch(PDO::FETCH_ASSOC);
if (!$signIn || !($signIn['role'] === 'admin' || strpos((string)$signIn['username'], 'zz_scstart_') === 0)) { fwrite(STDERR, "refusing to run: not the admin or a test user\n"); exit(2); }

register_shutdown_function(function () use ($cfg) {
    global $pdo;
    $buf = '';
    while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
    $msg = '';
    if (preg_match('#<p class="sc-banner-msg" id="scBannerMsg">(.*?)</p>#s', $buf, $m)) { $msg = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8'); }
    $link = '';
    if (preg_match('#<a id="scBannerOpenLink"[^>]*\shref="([^"]*)"#', $buf, $m)) { $link = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'); }
    $held = function (string $prefix) use ($pdo): bool {
        $n = substr($prefix . (defined('DB_NAME') ? DB_NAME : 'db'), 0, 64);
        try { return (int)$pdo->query('SELECT IS_USED_LOCK(' . $pdo->quote($n) . ') <=> CONNECTION_ID()')->fetchColumn() === 1; } catch (Throwable $e) { return true; }
    };
    fwrite(STDOUT, "\n@@RESULT@@" . json_encode([
        'tag' => $cfg['tag'] ?? '', 'flash' => (string)($_SESSION['stock_msg'] ?? ''), 'err' => $msg, 'link' => $link,
        'start_held' => $held('rh_stock_count_start_'), 'opening_held' => $held('rh_stock_count_opening_'),
        'in_txn' => $pdo instanceof PDO && $pdo->inTransaction(), 'bytes' => strlen($buf), 'ended' => microtime(true),
    ]) . "\n");
});

$sessDir = __DIR__ . '/sessions';
if (!is_dir($sessDir)) { mkdir($sessDir, 0777, true); }
session_save_path($sessDir);
define('RH_SESSION_KEEP_SAVE_PATH', true);
ini_set('session.use_cookies', '0');
session_id('scstart' . bin2hex(random_bytes(6)));
session_start();
$_SESSION['admin_user_id'] = (int)$signIn['id'];
$_SESSION['admin_username'] = $signIn['username'];
$_SESSION['admin_role'] = $signIn['role'];
$_SESSION['admin_full_name'] = $signIn['full_name'];
$_SESSION['admin_user'] = ['id' => (int)$signIn['id'], 'username' => $signIn['username'], 'full_name' => $signIn['full_name'], 'role' => $signIn['role']];
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$_SESSION['admin_last_activity'] = time();
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/admin/stock-count.php';
$_SERVER['REQUEST_URI'] = '/admin/stock-count.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = ['action' => 'start_count', 'scope' => 'category', 'scope_value' => (string)$cfg['category'], 'shift' => (string)($cfg['shift'] ?? ''),
    'notes' => '', 'csrf_token' => $_SESSION['csrf_token']];
if (!empty($cfg['opening'])) { $_POST['opening'] = '1'; }
$_GET = [];
$_REQUEST = $_POST;
ob_start();
chdir($root . '/admin');
require_once $root . '/admin/admin-init.php'; // sign-in and CSRF setup happen BEFORE the start line

$startAt = (float)($cfg['start_at'] ?? 0);
while (microtime(true) < $startAt) { usleep(100); }
require $root . '/admin/stock-count.php';
