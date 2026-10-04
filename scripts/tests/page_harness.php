<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.
"); } // never runnable over the web
/**
 * Rolled-back admin page harness. Run from a repo root:
 *   php page_harness.php <admin/page.php> <base64(json {post:{}, ajax:bool, verify:[sql,...]})>
 * Runs the page as an admin POST inside ONE outer transaction on a PDO subclass that can never
 * commit (commit() is a no-op), disables the settings file cache for this process (inside the
 * transaction), stubs the web scheduler, then runs verify SQL and ROLLS BACK. Prints one JSON line.
 */
$root = getcwd();
$page = $argv[1];
$cfg = json_decode(base64_decode($argv[2]), true);
$GLOBALS['__h_out'] = '';
$GLOBALS['__h_cfg'] = $cfg;


require $root . '/config/database.php';

class TxSafePdo extends PDO
{
    public function beginTransaction(): bool { if (!$this->inTransaction()) { return parent::beginTransaction(); } return true; }
    public function commit(): bool { return true; }
    public function harnessRollback(): void { if ($this->inTransaction()) { parent::rollBack(); } }
}
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
$pdo = new TxSafePdo("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, $opts);
$pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
$sqlMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
if (stripos($sqlMode, 'STRICT_TRANS_TABLES') === false) {
    $pdo->exec("SET SESSION sql_mode = " . $pdo->quote(trim($sqlMode . ',STRICT_TRANS_TABLES', ',')));
}
$pdo->beginTransaction();
// Cache off for this process only (rolled back with everything else): no uncommitted value can reach the file cache.
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('cache_global_enabled','0'),('automated_email_master','0') ON DUPLICATE KEY UPDATE setting_value='0'"); // master off: the web scheduler (which can send emails) never runs

function __h_finish(): void
{
    global $pdo;
    $res = ['output_flash' => [], 'verify' => []];
    $buf = '';
    while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
    $res['bytes'] = strlen($buf);
    foreach (($GLOBALS['__h_cfg']['grep'] ?? []) as $needle) { $res['found'][$needle] = (stripos($buf, $needle) !== false); }
    if (preg_match_all('#<div class="alert[^"]*"[^>]*>.*?</div>#s', $buf, $m)) {
        foreach ($m[0] as $a) { $res['output_flash'][] = trim(preg_replace('/\s+/', ' ', strip_tags($a))); }
    }
    if (preg_match('#^\s*[\[{].*[\]}]\s*$#s', $buf)) { $res['json'] = json_decode(trim($buf), true); }
    foreach (($GLOBALS['__h_cfg']['verify'] ?? []) as $sql) {
        try { $res['verify'][] = $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { $res['verify'][] = 'ERR ' . $e->getMessage(); }
    }
    $pdo->harnessRollback();
    $res['rolled_back'] = !$pdo->inTransaction();
    fwrite(STDOUT, "\n@@RESULT@@" . json_encode($res) . "\n");
}
register_shutdown_function('__h_finish');
ob_start();

// Sign in as an existing active admin user via a throw-away session file in the scratch dir.
$admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch();
if (!$admin) { fwrite(STDERR, "no admin user\n"); exit(2); }
$sessDir = __DIR__ . '/sessions';
if (!is_dir($sessDir)) { mkdir($sessDir, 0777, true); }
session_save_path($sessDir);
ini_set('session.use_cookies', '0');
session_id('harness' . bin2hex(random_bytes(6)));
session_start();
$_SESSION['admin_user_id'] = (int)$admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['admin_full_name'] = $admin['full_name'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$_SESSION['admin_last_activity'] = time();
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!empty($cfg['ajax'])) { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; }
$_POST = ($cfg['post'] ?? []) + ['csrf_token' => $_SESSION['csrf_token']];
$_REQUEST = $_POST;
chdir($root . '/' . dirname($page));
require $root . '/' . $page;
