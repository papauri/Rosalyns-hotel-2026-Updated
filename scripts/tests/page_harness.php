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

/**
 * Tests run against the LIVE database (owner's choice), so nothing a page does may survive:
 * commit() is a no-op, and every statement that would make MySQL commit implicitly
 * (CREATE/ALTER/DROP/TRUNCATE/RENAME, LOCK/UNLOCK TABLES, autocommit, COMMIT/BEGIN) is
 * skipped and reported as `ddl_skipped`. Pages' "ensure schema" DDL used to commit the
 * harness transaction half-way and leak test rows into the live data. Temporary tables are
 * allowed (no implicit commit). A page's own beginTransaction()/commit()/rollBack() become
 * SAVEPOINT / RELEASE / ROLLBACK TO inside the harness transaction, so a page rolling back
 * its own work never ends the outer transaction (which would put the connection in
 * autocommit and let later writes reach the live DB). __h_finish() checks a marker row from
 * a second connection (`leak`) and on the harness connection (`marker_lost`).
 * Never point the harness at anything that spawns a subprocess or opens its own PDO
 * (backup/restore in admin/backup-management.php, scripts/restore_database.php).
 */
class TxSafePdo extends PDO
{
    private $savepoints = 0;
    public static function commitsImplicitly(string $sql): bool
    {
        $s = ltrim((string)preg_replace('#^(\s+|/\*(?!!).*?\*/|(--|\#)[^\n]*\n)+#s', '', $sql));
        // A second statement in the same string, or a MySQL executable comment, is not inspected: refuse it.
        $bare = (string)preg_replace('#\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`#s', "''", $s); // ignore ; inside literals
        if (preg_match('#;\s*\S#', rtrim($bare, "; \t\r\n")) || strpos($bare, '/*!') !== false) {
            return true;
        }
        if (preg_match('#^(CREATE|DROP)\s+TEMPORARY\s#i', $s)) {
            return false;
        }
        return (bool)preg_match('#^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|COMMIT|START\s+TRANSACTION|BEGIN|ANALYZE|OPTIMIZE|REPAIR|CHECK\s+TABLE|FLUSH|RESET|CACHE\s+INDEX|LOAD\s+INDEX|GRANT|REVOKE|SET\s+PASSWORD|INSTALL|UNINSTALL)\b#i', $s)
            || (bool)preg_match('#^SET\s+(GLOBAL\s+|SESSION\s+|LOCAL\s+)?(@@(global\.|session\.|local\.)?)?autocommit\b#i', $s);
    }
    private function skip(string $sql): void { $GLOBALS['__h_ddl'][] = substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 120); }
    public function beginTransaction(): bool
    {
        if (!$this->inTransaction()) {
            return parent::beginTransaction();
        }
        $this->savepoints++;
        parent::exec('SAVEPOINT h_sp' . $this->savepoints);
        return true;
    }
    public function commit(): bool
    {
        if ($this->savepoints > 0) {
            parent::exec('RELEASE SAVEPOINT h_sp' . $this->savepoints);
            $this->savepoints--;
        }
        return true;
    }
    #[\ReturnTypeWillChange]
    public function rollBack()
    {
        if ($this->savepoints > 0) {
            parent::exec('ROLLBACK TO SAVEPOINT h_sp' . $this->savepoints);
            parent::exec('RELEASE SAVEPOINT h_sp' . $this->savepoints);
            $this->savepoints--;
        } else {
            $GLOBALS['__h_ddl'][] = 'rollBack() with no open page transaction - ignored';
        }
        return true;
    }
    public function harnessRollback(): void { if ($this->inTransaction()) { parent::rollBack(); } }
    #[\ReturnTypeWillChange]
    public function exec($statement) { if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return 0; } return parent::exec($statement); }
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = null, ...$args)
    {
        if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return parent::query('SELECT 0'); }
        return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
    }
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = [])
    {
        if (self::commitsImplicitly((string)$statement)) { $this->skip((string)$statement); return parent::prepare('SELECT 0'); }
        return parent::prepare($statement, $options);
    }
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
$GLOBALS['__h_marker'] = '__harness_marker_' . bin2hex(random_bytes(6)); // leak probe, see __h_finish()
$pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, '1')")->execute([$GLOBALS['__h_marker']]);

function __h_finish(): void
{
    global $pdo;
    $res = ['output_flash' => [], 'verify' => []];
    $buf = '';
    while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
    $res['bytes'] = strlen($buf);
    // Optional cfg.dump_html = <file path>: keep the rendered page so it can be opened in a browser (screenshots of a user state that cannot be signed in for real).
    if (!empty($GLOBALS['__h_cfg']['dump_html'])) { @file_put_contents((string)$GLOBALS['__h_cfg']['dump_html'], $buf); }
    foreach (($GLOBALS['__h_cfg']['grep'] ?? []) as $needle) { $res['found'][$needle] = (stripos($buf, $needle) !== false); }
    if (preg_match_all('#<div class="alert[^"]*"[^>]*>.*?</div>#s', $buf, $m)) {
        foreach ($m[0] as $a) { $res['output_flash'][] = trim(preg_replace('/\s+/', ' ', strip_tags($a))); }
    }
    if (preg_match('#^\s*[\[{].*[\]}]\s*$#s', $buf)) { $res['json'] = json_decode(trim($buf), true); }
    foreach (($GLOBALS['__h_cfg']['verify'] ?? []) as $sql) {
        try { $res['verify'][] = $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { $res['verify'][] = 'ERR ' . $e->getMessage(); }
    }
    $res['ddl_skipped'] = $GLOBALS['__h_ddl'] ?? [];
    // The marker must still be visible on the harness connection; if not, the outer transaction
    // ended mid-run and anything written afterwards was autocommitted.
    try {
        $own = $pdo->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
        $own->execute([$GLOBALS['__h_marker']]);
        $res['marker_lost'] = (int)$own->fetchColumn() === 0;
        if ($res['marker_lost']) {
            fwrite(STDERR, "HARNESS LEAK: the harness transaction ended during the page run - later writes may have reached the live DB\n");
        }
    } catch (Throwable $e) {
        $res['marker_lost'] = null;
    }
    // Leak check from a SECOND connection: the marker row written inside the harness transaction
    // must be invisible to it. If it is visible, something committed the transaction mid-run.
    $res['leak'] = null;
    try {
        $probe = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $chk = $probe->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
        $chk->execute([$GLOBALS['__h_marker']]);
        $res['leak'] = (int)$chk->fetchColumn() > 0;
        if ($res['leak']) {
            $probe->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$GLOBALS['__h_marker']]);
            fwrite(STDERR, "HARNESS LEAK: the transaction was committed during the page run - rows may have reached the live DB
");
        }
    } catch (Throwable $e) {
        $res['leak_check_error'] = $e->getMessage();
    }
    $pdo->harnessRollback();
    $res['rolled_back'] = !$pdo->inTransaction();
    fwrite(STDOUT, "\n@@RESULT@@" . json_encode($res) . "\n");
}
register_shutdown_function('__h_finish');
ob_start();

// Sign in as an existing active admin user via a throw-away session file in the scratch dir.
if (!empty($cfg['as_username'])) {
    // Optional: run as that existing active user instead (to test what a non-admin may do). Needs no new user.
    $asSt = $pdo->prepare("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND username = ? LIMIT 1");
    $asSt->execute([(string)$cfg['as_username']]);
    $admin = $asSt->fetch();
} else {
    $admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch();
}
if (!$admin) { fwrite(STDERR, "no admin user\n"); exit(2); }
$sessDir = __DIR__ . '/sessions';
if (!is_dir($sessDir)) { mkdir($sessDir, 0777, true); }
session_save_path($sessDir);
define('RH_SESSION_KEEP_SAVE_PATH', true); // pages' rh_session_start() must read this harness session
ini_set('session.use_cookies', '0');
session_id('harness' . bin2hex(random_bytes(6)));
session_start();
$_SESSION['admin_user_id'] = (int)$admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['admin_full_name'] = $admin['full_name'];
$_SESSION['admin_user'] = ['id' => (int)$admin['id'], 'username' => $admin['username'], 'full_name' => $admin['full_name'], 'role' => $admin['role']]; // what api/*.php endpoints read
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$_SESSION['admin_last_activity'] = time();
session_write_close();

// Optional seed rows (cfg.setup = [sql,...]): run inside the same never-committing transaction, before the page runs.
foreach (($cfg['setup'] ?? []) as $setupSql) { $pdo->exec($setupSql); }
// Optional cfg.vars = {name: sql}: after setup, each sql's first value replaces {name} inside the POST values (ids of the seed rows).
$__h_vars = [];
foreach (($cfg['vars'] ?? []) as $vName => $vSql) { $__h_vars['{' . $vName . '}'] = (string)$pdo->query($vSql)->fetchColumn(); }

// Optional cfg.fake_smtp = <port>: point the (rolled-back) SMTP settings at scripts/tests/fake-smtp.php on
// 127.0.0.1 so every email the page sends is captured instead of delivered. Refuses to run the page if the
// override did not take (a page must never reach the real mail server from a test).
if (!empty($cfg['fake_smtp'])) {
    $fs = $pdo->prepare("INSERT INTO email_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0");
    foreach (['smtp_host' => '127.0.0.1', 'smtp_port' => (string)(int)$cfg['fake_smtp'], 'smtp_secure' => '', 'smtp_username' => 'fake@example.invalid', 'smtp_password' => 'x', 'smtp_timeout' => '5',
              'email_development_mode' => '0', 'email_preview_enabled' => '0', 'email_bcc_admin' => '0', 'email_log_enabled' => '0'] as $k => $v) {
        $fs->execute([$k, $v]);
    }
    $chk = $pdo->query("SELECT setting_value FROM email_settings WHERE setting_key = 'smtp_host'")->fetchColumn();
    if ($chk !== '127.0.0.1') { fwrite(STDERR, "fake smtp override failed\n"); exit(3); }
}

$_SERVER['REQUEST_METHOD'] = (($cfg['method'] ?? 'POST') === 'GET') ? 'GET' : 'POST'; // 'method':'GET' renders a page (read-only)
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!empty($cfg['ajax'])) { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; }
$_POST = ($cfg['post'] ?? []) + ['csrf_token' => $_SESSION['csrf_token']];
if ($__h_vars) { foreach ($_POST as $pk => $pv) { if (is_string($pv)) { $_POST[$pk] = strtr($pv, $__h_vars); } } }
$_GET = $cfg['get'] ?? [];
$_REQUEST = $_POST + $_GET;
chdir($root . '/' . dirname($page));
require $root . '/' . $page;
