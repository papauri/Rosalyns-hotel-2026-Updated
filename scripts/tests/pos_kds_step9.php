<?php
/**
 * Step 9 (POS / KDS core review), builder A: station comms + B4, through the REAL endpoints.
 *
 *   php scripts/tests/pos_kds_step9.php --db=<path to the TEST hotel root (Liwonde)>            every case
 *   php scripts/tests/pos_kds_step9.php --db=<Liwonde root> A1 A3                                only these
 *
 * Runs THIS tree's code against the TEST hotel's database: the DB_* constants are defined from that tree's
 * .env BEFORE config/database.php loads (its own define()s then only warn), the database name must match
 * the Liwonde sha1 BEFORE anything connects, and the file cache points at a scratch folder. Refuses this
 * tree, any path containing "Rosalyn", and any other database. Rosalyn's is never connected to.
 * Each case runs in its own child PHP process on a PDO subclass holding ONE outer transaction that is
 * always ROLLED BACK (the code under test sees "no transaction"; its begin / commit / rollBack become
 * SAVEPOINT / RELEASE / ROLLBACK TO; implicit-commit statements are skipped); a marker row proves the outer
 * transaction never ended and a second connection proves nothing was left behind.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const ZZ9_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde database name (name kept out of the public repo)
$zz9Root = dirname(__DIR__, 2);

/** Validate --db and return [path, env]; exits on refusal. Never connects. */
function zz9_target(array $argv, string $root): array
{
    $dbArg = null;
    foreach ($argv as $a) { if (strpos((string)$a, '--db=') === 0) { $dbArg = substr((string)$a, 5); } }
    if ($dbArg === null) { fwrite(STDERR, "Refused: pass --db=<path to the Liwonde test hotel root>.\n"); exit(2); }
    $target = realpath($dbArg);
    if ($target === false || !is_readable($target . DIRECTORY_SEPARATOR . '.env')) { fwrite(STDERR, "Refused: --db path has no readable .env.\n"); exit(2); }
    if (strcasecmp((string)realpath($root), $target) === 0 || stripos($target, 'Rosalyn') !== false) { fwrite(STDERR, "Refused: never against Rosalyn's (live).\n"); exit(9); }
    $env = [];
    foreach (file($target . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $k = trim((string)preg_replace("/^\xEF\xBB\xBF/", '', $k));
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) { $v = substr($v, 1, -1); }
        if ($k !== '') { $env[$k] = $v; }
    }
    if (sha1((string)($env['DB_NAME'] ?? '')) !== ZZ9_DB_SHA1) { fwrite(STDERR, "Refused: that .env is not the Liwonde test database.\n"); exit(9); }
    return [$target, $env];
}

/* =====================================================================================================
 * CHILD
 * ===================================================================================================== */
if (($argv[1] ?? '') === '--child') {
    [, $zz9Env] = zz9_target($argv, $zz9Root);
    $GLOBALS['zz_case'] = (string)($argv[2] ?? '');
    $GLOBALS['zz_checks'] = [];
    $GLOBALS['zz_ddl'] = [];
    $GLOBALS['zz_after'] = null;
    $GLOBALS['zz_page'] = null;
    $_SERVER['HTTP_HOST'] = 'localhost'; // the web scheduler skips a local copy
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Liwonde's credentials, defined before database.php can read this tree's .env.
    define('DB_HOST', (string)($zz9Env['DB_HOST'] ?? ''));
    define('DB_PORT', (string)($zz9Env['DB_PORT'] ?? '3306') ?: '3306');
    define('DB_NAME', (string)($zz9Env['DB_NAME'] ?? ''));
    define('DB_USER', (string)($zz9Env['DB_USER'] ?? ''));
    define('DB_PASS', (string)($zz9Env['DB_PASS'] ?? ''));
    define('DB_CHARSET', 'utf8mb4');
    if (sha1(DB_NAME) !== ZZ9_DB_SHA1) { fwrite(STDERR, "child refused: not the Liwonde database\n"); exit(9); }
    $zz9Cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz9_cache_' . getmypid();
    if (!is_dir($zz9Cache)) { mkdir($zz9Cache, 0777, true); }
    define('CACHE_DIR', $zz9Cache); // never this tree's settings cache
    set_error_handler(static function (int $no, string $str): bool {
        return ($no === E_WARNING && strpos($str, 'already defined') !== false); // our defines win, silently
    });
    require $zz9Root . '/config/database.php';
    restore_error_handler();
    foreach ($zz9Env as $k => $v) { putenv($k . '=' . $v); $_ENV[$k] = $v; } // this tree's .env values never stay in the env
    if (sha1(DB_NAME) !== ZZ9_DB_SHA1 || sha1((string)$pdo->query('SELECT DATABASE()')->fetchColumn()) !== ZZ9_DB_SHA1) {
        fwrite(STDERR, "child refused: connection is not the Liwonde database\n"); exit(9);
    }

    class ZZ9Pdo extends PDO
    {
        private $sp = 0;
        public $sealed = false;
        public static function commitsImplicitly(string $sql): bool
        {
            $s = ltrim((string)preg_replace('#^(\s+|/\*(?!!).*?\*/|(--|\#)[^\n]*\n)+#s', '', $sql));
            $bare = (string)preg_replace('#\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`#s', "''", $s);
            if (preg_match('#;\s*\S#', rtrim($bare, "; \t\r\n")) || strpos($bare, '/*!') !== false) { return true; }
            if (preg_match('#^(CREATE|DROP)\s+TEMPORARY\s#i', $s)) { return false; }
            return (bool)preg_match('#^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|COMMIT|START\s+TRANSACTION|BEGIN|ANALYZE|OPTIMIZE|REPAIR|CHECK\s+TABLE|FLUSH|RESET|CACHE\s+INDEX|LOAD\s+INDEX|GRANT|REVOKE|SET\s+PASSWORD|INSTALL|UNINSTALL)\b#i', $s)
                || (bool)preg_match('#^SET\s+(GLOBAL\s+|SESSION\s+|LOCAL\s+)?(@@(global\.|session\.|local\.)?)?autocommit\b#i', $s);
        }
        private function blocked(string $sql): bool
        {
            if ($this->sealed) { throw new RuntimeException('ZZ9 test connection is sealed (after rollback)'); }
            if (self::commitsImplicitly($sql)) { $GLOBALS['zz_ddl'][] = substr((string)preg_replace('/\s+/', ' ', trim($sql)), 0, 100); return true; }
            return false;
        }
        public function zzBegin(): void { parent::beginTransaction(); }
        public function zzOuterOpen(): bool { return parent::inTransaction(); }
        public function zzRollback(): void { if (parent::inTransaction()) { parent::rollBack(); } $this->sealed = true; }
        public function inTransaction(): bool { return $this->sp > 0; }
        public function beginTransaction(): bool
        {
            if ($this->sealed || !parent::inTransaction()) { throw new RuntimeException('ZZ9 outer test transaction is not open'); }
            if ($this->sp > 0) { throw new PDOException('There is already an active transaction'); }
            $this->sp++;
            parent::exec('SAVEPOINT zz9_sp' . $this->sp);
            return true;
        }
        public function commit(): bool
        {
            if ($this->sp <= 0) { throw new PDOException('There is no active transaction'); }
            parent::exec('RELEASE SAVEPOINT zz9_sp' . $this->sp);
            $this->sp--;
            return true;
        }
        public function rollBack(): bool
        {
            if ($this->sp <= 0) { throw new PDOException('There is no active transaction'); }
            parent::exec('ROLLBACK TO SAVEPOINT zz9_sp' . $this->sp);
            parent::exec('RELEASE SAVEPOINT zz9_sp' . $this->sp);
            $this->sp--;
            return true;
        }
        #[\ReturnTypeWillChange]
        public function exec($statement) { if ($this->blocked((string)$statement)) { return 0; } return parent::exec($statement); }
        #[\ReturnTypeWillChange]
        public function query($statement, $mode = null, ...$args)
        {
            if ($this->blocked((string)$statement)) { return parent::query('SELECT 0'); }
            return $mode === null ? parent::query($statement) : parent::query($statement, $mode, ...$args);
        }
        #[\ReturnTypeWillChange]
        public function prepare($statement, $options = [])
        {
            if ($this->blocked((string)$statement)) { return parent::prepare('SELECT 0'); }
            return parent::prepare($statement, $options);
        }
    }

    $zzOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true];
    $pdo = new ZZ9Pdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $zzOpts);
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    $zzMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    if (stripos($zzMode, 'STRICT_TRANS_TABLES') === false) { $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(trim($zzMode . ',STRICT_TRANS_TABLES', ','))); }
    $GLOBALS['pdo'] = $pdo;
    $pdo->zzBegin();
    $pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('cache_global_enabled','0'),('automated_email_master','0') ON DUPLICATE KEY UPDATE setting_value='0'");
    $GLOBALS['zz_marker'] = '__zz9_marker_' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, '1')")->execute([$GLOBALS['zz_marker']]);
    $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS pos_deleted_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, original_order_id INT UNSIGNED NOT NULL, reference VARCHAR(50) NOT NULL DEFAULT '',
        client_uuid VARCHAR(64) NULL, order_type VARCHAR(30) NOT NULL DEFAULT '', order_status VARCHAR(30) NOT NULL DEFAULT '',
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0, paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0, refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tip_amount DECIMAL(12,2) NOT NULL DEFAULT 0, payment_method VARCHAR(30) NULL, booking_id INT UNSIGNED NULL, booking_reference VARCHAR(50) NULL,
        order_created_at DATETIME NULL, order_created_by INT UNSIGNED NULL, deleted_by INT UNSIGNED NULL, deleted_by_name VARCHAR(150) NOT NULL DEFAULT '',
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reason VARCHAR(500) NOT NULL DEFAULT '', notes VARCHAR(1500) NULL, ip_address VARCHAR(45) NULL,
        stock_summary VARCHAR(500) NULL, payments_removed INT UNSIGNED NOT NULL DEFAULT 0, folio_charges_voided INT UNSIGNED NOT NULL DEFAULT 0, snapshot_json LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $zzAdmin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch();
    if (!$zzAdmin) { fwrite(STDERR, "no admin\n"); exit(3); }
    $GLOBALS['zz_admin'] = (int)$zzAdmin['id'];
    $zzSessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz9_sessions';
    if (!is_dir($zzSessDir)) { mkdir($zzSessDir, 0777, true); }
    session_save_path($zzSessDir);
    define('RH_SESSION_KEEP_SAVE_PATH', true);
    ini_set('session.use_cookies', '0');
    session_id('zz9' . bin2hex(random_bytes(6)));
    session_start();
    $_SESSION['admin_user_id'] = (int)$zzAdmin['id'];
    $_SESSION['admin_username'] = $zzAdmin['username'];
    $_SESSION['admin_role'] = $zzAdmin['role'];
    $_SESSION['admin_full_name'] = $zzAdmin['full_name'];
    $_SESSION['admin_user'] = ['id' => (int)$zzAdmin['id'], 'username' => $zzAdmin['username'], 'full_name' => $zzAdmin['full_name'], 'role' => $zzAdmin['role']];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    session_write_close();
    $GLOBALS['zz_sess_file'] = $zzSessDir . DIRECTORY_SEPARATOR . 'sess_' . session_id();

    function zz_check(string $name, bool $ok, $detail = ''): void
    {
        $GLOBALS['zz_checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => is_string($detail) ? $detail : json_encode($detail)];
    }
    function zz_q(string $sql, array $p = []): PDOStatement
    {
        $s = $GLOBALS['pdo']->prepare($sql);
        $s->execute($p);
        return $s;
    }
    function zz_v(string $sql, array $p = [])
    {
        return zz_q($sql, $p)->fetchColumn();
    }
    /** A menu item (no recipe: no stock effect) routed to $station, price 100. */
    function zz_item(string $tag, string $station): int
    {
        $cat = (int)$GLOBALS['pdo']->query('SELECT id FROM menu_categories WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        zz_q("INSERT INTO menu_items (category_id, item_name, price, station, is_available, show_pos) VALUES (?, ?, 100, ?, 1, 1)", [$cat, 'ZZ9 ' . $tag, $station]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz_order(string $tag, string $kitchen = 'new', float $total = 100, string $status = 'placed'): int
    {
        zz_q("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, opened_as_tab, fired_at, kitchen_printed_at, created_at)
              VALUES (?, 'walk_in', 'ZZ9', ?, ?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 20 MINUTE))",
            ['ZZ9-' . $tag . '-' . random_int(100000, 999999), $status, $total, $total, $kitchen, $GLOBALS['zz_admin']]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz_line(int $orderId, int $item, string $station, string $kds, string $name = 'ZZ9 line', float $price = 100): int
    {
        zz_q("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, started_at, ready_at, served_at, stock_deducted, created_at)
              VALUES (?, ?, 'zz9', ?, 1, ?, ?, ?, ?, " . ($kds === 'pending' ? 'NULL' : 'NOW()') . ', ' . (in_array($kds, ['ready', 'collection', 'served'], true) ? 'NOW()' : 'NULL') . ', ' . ($kds === 'served' ? 'NOW()' : 'NULL') . ", 0, DATE_SUB(NOW(), INTERVAL 20 MINUTE))",
            [$orderId, $item, $name, $price, $price, $station, $kds]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz_ready_row(int $orderId, string $station, string $createdSql = 'NOW()'): int
    {
        zz_q("INSERT INTO pos_ready_notifications (order_id, `reference`, table_label, station, placed_by, message, for_role, seen_by, created_at)
              VALUES (?, 'ZZ9', 'WALK IN', ?, ?, 'ZZ9 ready', 'all', '[]', $createdSql)", [$orderId, $station, $GLOBALS['zz_admin']]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    /** Shape every station's hours IN MEMORY (never written) so they opened $openAgo and closed $closedAgo minutes ago. */
    function zz_hours_closed(int $openAgo, int $closedAgo): void
    {
        $tz = function_exists('rh_site_timezone') ? rh_site_timezone() : new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $tz);
        $open = $now->modify("-{$openAgo} minutes")->format('H:i');
        $close = $now->modify("-{$closedAgo} minutes")->format('H:i');
        foreach (['kitchen', 'bar', 'coffee_bar'] as $s) {
            $GLOBALS['_SITE_SETTINGS']['station_' . $s . '_opens_at'] = $open;
            $GLOBALS['_SITE_SETTINGS']['station_' . $s . '_closes_at'] = $close;
        }
    }
    /** Run this page as the last step of the child (prints JSON and exits; zz_finish checks and rolls back). */
    function zz_page(string $page, array $post, array $get = [], bool $ajax = true): void
    {
        $GLOBALS['zz_page'] = ['page' => $page, 'post' => $post, 'get' => $get, 'ajax' => $ajax];
    }

    function zz_finish(): void
    {
        $pdo = $GLOBALS['pdo'];
        $buf = '';
        while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
        $resp = null;
        $t = trim($buf);
        if ($t !== '' && $t[0] === '{') { $resp = json_decode($t, true); }
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            zz_check('child ran without a fatal error', false, $err['message'] . ' @' . basename((string)$err['file']) . ':' . $err['line']);
        }
        if (!empty($GLOBALS['zz_fatal'])) {
            zz_check('case setup / action ran', false, $GLOBALS['zz_fatal']);
        } elseif (is_callable($GLOBALS['zz_after'])) {
            try {
                ($GLOBALS['zz_after'])($resp, $buf);
            } catch (Throwable $e) {
                zz_check('after-checks ran', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        $res = ['case' => $GLOBALS['zz_case'], 'out_tail' => $resp === null ? substr((string)preg_replace('/\s+/', ' ', strip_tags($buf)), -400) : '', 'ddl_skipped' => $GLOBALS['zz_ddl']];
        try {
            $own = $pdo->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
            $own->execute([$GLOBALS['zz_marker']]);
            $res['marker_lost'] = (int)$own->fetchColumn() === 0 || !$pdo->zzOuterOpen();
        } catch (Throwable $e) {
            $res['marker_lost'] = true;
        }
        $pdo->zzRollback();
        $res['rolled_back'] = !$pdo->zzOuterOpen();
        $res['leak'] = null;
        try {
            $probe = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $chk = $probe->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = ?');
            $chk->execute([$GLOBALS['zz_marker']]);
            $left = (int)$chk->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM menu_items WHERE item_name LIKE 'ZZ9 %'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM stock_orders WHERE reference LIKE 'ZZ9-%' OR client_uuid LIKE 'zz9-%'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM stock_order_items WHERE item_name LIKE 'ZZ9%'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM idempotency_keys WHERE client_uuid LIKE 'zz9-%'")->fetchColumn();
            $res['leak'] = $left > 0;
            $res['left_behind'] = $left;
        } catch (Throwable $e) {
            $res['leak_check_error'] = $e->getMessage();
        }
        if (!empty($GLOBALS['zz_sess_file']) && is_file($GLOBALS['zz_sess_file'])) { @unlink($GLOBALS['zz_sess_file']); }
        $res['checks'] = $GLOBALS['zz_checks'];
        fwrite(STDOUT, "\n@@RESULT@@" . json_encode($res) . "\n");
    }
    register_shutdown_function('zz_finish');
    ob_start();

    require_once $zz9Root . '/admin/includes/permissions.php';
    require_once $zz9Root . '/includes/station-hours.php';
    require_once $zz9Root . '/admin/includes/restaurant-payment-sync.php';
    require_once $zz9Root . '/admin/includes/pos-order-close.php';
    require_once $zz9Root . '/admin/includes/pos-order-delete.php';
    require_once $zz9Root . '/admin/includes/eod-revenue.php';
    require_once $zz9Root . '/includes/booking-history-format.php';
    /** Step 12 fixtures: a checked-in test booking, a folio charge (gross 100) linked to an order, a restaurant refund row. */
    function zz12_booking(string $tag): int
    {
        $room = (int)$GLOBALS['pdo']->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
        zz_q("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)
              VALUES (?, ?, 'ZZ9 Guest', 'zz9@example.invalid', '000', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2, 1, 1, 0, 1000, 1000, 0, 1000, 'checked-in')",
            ['ZZ9-' . $tag . '-' . random_int(1000, 9999), $room]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz12_charge(int $bookingId, ?int $orderId, string $desc): int
    {
        $c = addBookingCharge($bookingId, 'food', $desc, 1, 100, null, $GLOBALS['zz_admin']);
        if (empty($c['success']) || empty($c['charge_id'])) { throw new RuntimeException('fixture: charge failed ' . json_encode($c)); }
        if ($orderId !== null) { zz_q('UPDATE booking_charges SET stock_order_id = ? WHERE id = ?', [$orderId, (int)$c['charge_id']]); }
        return (int)$c['charge_id'];
    }
    function zz12_refund(int $orderId, float $amt, string $status, string $processedSql): void
    {
        zz_q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
              payment_method, payment_type, payment_status, refund_reason, refund_status, refund_amount, refund_date_processed, recorded_by, created_at)
              VALUES (?, 'restaurant', ?, 'ZZ9', CURDATE(), ?, 0, 0, ?, 'cash', 'refund', ?, 'other', ?, ?, $processedSql, ?, NOW())",
            ['ZZ9-RF-' . random_int(100000, 999999), $orderId, $amt, $amt, $status === 'completed' ? 'completed' : 'pending', $status, $amt, $GLOBALS['zz_admin']]);
    }
    $A = $GLOBALS['zz_admin'];

    try {
        switch ($GLOBALS['zz_case']) {

        /* ---------- A1: Fire (park) - first send, then the retry of the same send ---------- */
        case 'A1_park_first':
            $k = zz_item('A1 dish', 'kitchen');
            $u = 'zz9-' . bin2hex(random_bytes(8));
            zz_page('admin/pos.php', ['action' => 'park', 'order_type' => 'walk_in', 'client_uuid' => $u, 'item_id' => [(string)$k], 'item_type' => ['food'], 'item_qty' => ['1'], 'item_note' => ['']]);
            $GLOBALS['zz_after'] = static function ($resp) use ($u): void {
                $o = zz_q('SELECT id, kitchen_status FROM stock_orders WHERE client_uuid = ?', [$u])->fetchAll();
                $oid = (int)($o[0]['id'] ?? 0);
                zz_check('A1 park: first send ok, not a replay', !empty($resp['ok']) && empty($resp['replayed']), $resp);
                zz_check('A1 park: one order carries the client_uuid', count($o) === 1, $o);
                zz_check('A1 park: one line, one fired ticket', (int)zz_v('SELECT COUNT(*) FROM stock_order_items WHERE order_id = ?', [$oid]) === 1
                    && (int)zz_v("SELECT COUNT(*) FROM stock_kds_events WHERE order_id = ? AND event = 'fired'", [$oid]) === 1);
            };
            break;

        case 'A1_park_retry': // the first send committed but its response was lost; the till re-sends the SAME uuid
            $k = zz_item('A1r dish', 'kitchen');
            $u = 'zz9-' . bin2hex(random_bytes(8));
            $o = zz_order('A1r');
            zz_q('UPDATE stock_orders SET client_uuid = ? WHERE id = ?', [$u, $o]);
            zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A1r dish');
            zz_q("INSERT INTO stock_kds_events (order_id, event, to_status, user_id, user_name) VALUES (?, 'fired', 'new', ?, 'zz9')", [$o, $A]);
            zz_page('admin/pos.php', ['action' => 'park', 'order_type' => 'walk_in', 'client_uuid' => $u, 'item_id' => [(string)$k], 'item_type' => ['food'], 'item_qty' => ['1'], 'item_note' => ['']]);
            $GLOBALS['zz_after'] = static function ($resp) use ($u, $o): void {
                zz_check('A1 park retry: answered as a replay of the same order', !empty($resp['ok']) && !empty($resp['replayed']) && (int)($resp['order_id'] ?? 0) === $o, $resp);
                zz_check('A1 park retry: still one order, one line, one ticket', (int)zz_v('SELECT COUNT(*) FROM stock_orders WHERE client_uuid = ?', [$u]) === 1
                    && (int)zz_v('SELECT COUNT(*) FROM stock_order_items WHERE order_id = ?', [$o]) === 1
                    && (int)zz_v("SELECT COUNT(*) FROM stock_kds_events WHERE order_id = ? AND event = 'fired'", [$o]) === 1);
            };
            break;

        /* ---------- A1 + A2 + A6: Add to tab - first send (round 2 while round 1 cooks) ---------- */
        case 'A1_tab_first':
            $k = zz_item('A1t dish', 'kitchen');
            $b = zz_item('A1t drink', 'bar');
            $o = zz_order('A1t', 'in_progress', 200);
            zz_line($o, $k, 'kitchen', 'preparing', 'ZZ9 A1t dish');
            zz_line($o, $b, 'bar', 'ready', 'ZZ9 A1t drink r1');
            $barRow = zz_ready_row($o, 'bar');         // round 1 drinks were announced
            $kitRow = zz_ready_row($o, 'kitchen');     // a station that gets nothing new keeps its row
            $firedBefore = (string)zz_v('SELECT fired_at FROM stock_orders WHERE id = ?', [$o]);
            $u = 'zz9-' . bin2hex(random_bytes(8));
            zz_page('admin/pos.php', ['action' => 'add_to_tab', 'tab_order_id' => (string)$o, 'client_uuid' => $u, 'item_id' => [(string)$b], 'item_type' => ['drink'], 'item_qty' => ['1'], 'item_note' => ['']]);
            $GLOBALS['zz_after'] = static function ($resp) use ($o, $u, $barRow, $kitRow, $firedBefore): void {
                $ord = zz_q('SELECT total_amount, subtotal, kitchen_status, fired_at, kitchen_printed_at FROM stock_orders WHERE id = ?', [$o])->fetch();
                zz_check('A1 tab: round added once', !empty($resp['ok']) && empty($resp['replayed']) && (int)zz_v('SELECT COUNT(*) FROM stock_order_items WHERE order_id = ?', [$o]) === 3
                    && abs((float)$ord['total_amount'] - 300) < BALANCE_TOLERANCE, [$resp, $ord]);
                zz_check('A1 tab: the replay record is written (idempotency_keys, endpoint pos.php:add_to_tab:<tab>)',
                    (int)zz_v('SELECT COUNT(*) FROM idempotency_keys WHERE client_uuid = ? AND endpoint = ?', [$u, 'pos.php:add_to_tab:' . $o]) === 1);
                zz_check('A2: the bar ready row of round 1 is cleared for round 2', (int)zz_v('SELECT COUNT(*) FROM pos_ready_notifications WHERE id = ?', [$barRow]) === 0);
                zz_check('A2: the kitchen row (no new kitchen lines) is kept', (int)zz_v('SELECT COUNT(*) FROM pos_ready_notifications WHERE id = ?', [$kitRow]) === 1);
                zz_check('A6: round 1 still cooking -> in_progress, not reset to new', (string)$ord['kitchen_status'] === 'in_progress', $ord);
                zz_check('A6: fired_at keeps the first fire; kitchen_printed_at marks the new round', (string)$ord['fired_at'] === $firedBefore && (string)$ord['kitchen_printed_at'] > $firedBefore, [$ord, $firedBefore]);
            };
            break;

        case 'A1_tab_retry': // round 2 committed, response lost: same uuid again
            $b = zz_item('A1tr drink', 'bar');
            $o = zz_order('A1tr', 'new', 100);
            zz_line($o, $b, 'bar', 'pending', 'ZZ9 A1tr drink');
            $u = 'zz9-' . bin2hex(random_bytes(8));
            zz_q("INSERT INTO idempotency_keys (client_uuid, endpoint, response_status, response_body, entity_type, entity_id, entity_reference, expires_at)
                  VALUES (?, ?, 200, ?, 'stock_order', ?, 'ZZ9', DATE_ADD(NOW(), INTERVAL 7 DAY))",
                [$u, 'pos.php:add_to_tab:' . $o, json_encode(['ok' => true, 'order_id' => $o, 'reference' => 'ZZ9', 'new_total' => 100, 'message' => 'zz9 stored']), $o]);
            $eventsBefore = (int)zz_v("SELECT COUNT(*) FROM stock_kds_events WHERE order_id = ?", [$o]);
            zz_page('admin/pos.php', ['action' => 'add_to_tab', 'tab_order_id' => (string)$o, 'client_uuid' => $u, 'item_id' => [(string)$b], 'item_type' => ['drink'], 'item_qty' => ['1'], 'item_note' => ['']]);
            $GLOBALS['zz_after'] = static function ($resp) use ($o, $eventsBefore): void {
                zz_check('A1 tab retry: answered from the stored result (replayed)', !empty($resp['ok']) && !empty($resp['replayed']) && ($resp['message'] ?? '') === 'zz9 stored', $resp);
                zz_check('A1 tab retry: nothing added, total and tickets unchanged', (int)zz_v('SELECT COUNT(*) FROM stock_order_items WHERE order_id = ?', [$o]) === 1
                    && abs((float)zz_v('SELECT total_amount FROM stock_orders WHERE id = ?', [$o]) - 100) < BALANCE_TOLERANCE
                    && (int)zz_v('SELECT COUNT(*) FROM stock_kds_events WHERE order_id = ?', [$o]) === $eventsBefore);
            };
            break;

        case 'A6_tab_all_pending': // round 1 not started: the order stays 'new'
            $k = zz_item('A6 dish', 'kitchen');
            $o = zz_order('A6', 'new', 100);
            zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A6 dish');
            zz_page('admin/pos.php', ['action' => 'add_to_tab', 'tab_order_id' => (string)$o, 'client_uuid' => 'zz9-' . bin2hex(random_bytes(8)), 'item_id' => [(string)$k], 'item_type' => ['food'], 'item_qty' => ['1'], 'item_note' => ['']]);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                zz_check('A6: only pending lines -> new', !empty($resp['ok']) && (string)zz_v('SELECT kitchen_status FROM stock_orders WHERE id = ?', [$o]) === 'new', $resp);
            };
            break;

        /* ---------- A2: the 2nd round's last line goes ready -> a new ready row for the till ---------- */
        case 'A2_round2_ready':
            $b = zz_item('A2 drink', 'bar');
            $o = zz_order('A2', 'in_progress', 200);
            zz_line($o, $b, 'bar', 'served', 'ZZ9 A2 drink r1');       // round 1 out (its row was cleared by add_to_tab)
            $l2 = zz_line($o, $b, 'bar', 'preparing', 'ZZ9 A2 drink r2');
            zz_page('api/kds-action.php', ['action' => 'ready_item', 'item_id' => (string)$l2, 'station' => 'bar']);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                zz_check('A2: round 2 ready -> one ready row for the bar, placed_by the cashier', !empty($resp['ok'])
                    && (int)zz_v("SELECT COUNT(*) FROM pos_ready_notifications WHERE order_id = ? AND station = 'bar' AND placed_by = ?", [$o, $GLOBALS['zz_admin']]) === 1, $resp);
            };
            break;

        /* ---------- A3: after the stations close, the till still gets ready alerts and My orders ---------- */
        case 'A3_poll_after_close':
            zz_hours_closed(180, 30);
            $k = zz_item('A3 dish', 'kitchen');
            $o = zz_order('A3', 'ready', 100);
            zz_line($o, $k, 'kitchen', 'ready', 'ZZ9 A3 dish');
            $row = zz_ready_row($o, 'kitchen', 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
            $created = (string)zz_v('SELECT created_at FROM pos_ready_notifications WHERE id = ?', [$row]);
            $oldEnd = (string)rh_station_union_business_window()['end_sql'];
            zz_page('api/pos-notifications.php', ['action' => 'poll', 'since_kds' => '0', 'since_audit' => '0'], ['action' => 'poll']);
            $GLOBALS['zz_after'] = static function ($resp) use ($row, $created, $oldEnd): void {
                zz_check('A3 setup: the alert is after closing time (the old window ended before it)', $created >= $oldEnd, [$created, $oldEnd]);
                $ids = array_map(static fn($n) => (int)$n['id'], (array)($resp['notifications'] ?? []));
                zz_check('A3: poll still returns the ready alert after the stations closed', !empty($resp['ok']) && in_array($row, $ids, true), $resp);
            };
            break;

        case 'A3_my_orders_after_close':
            zz_hours_closed(180, 30);
            $k = zz_item('A3m dish', 'kitchen');
            $o = zz_order('A3m', 'new', 100);
            zz_q('UPDATE stock_orders SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE), fired_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?', [$o]);
            zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A3m dish');
            zz_page('api/kds-action.php', ['action' => 'get_my_orders']);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                $ids = array_map(static fn($r) => (int)($r['id'] ?? 0), (array)($resp['orders'] ?? []));
                zz_check('A3: My orders still lists an order fired after closing time', !empty($resp['ok']) && in_array($o, $ids, true), is_array($resp) ? array_keys($resp) : $resp);
            };
            break;

        /* ---------- A7: note / rush go only to the stations on the order ---------- */
        case 'A7_note':
            $k = zz_item('A7 dish', 'kitchen');
            $o = zz_order('A7');
            zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A7 dish');
            zz_page('api/kds-action.php', ['action' => 'update_order_note', 'order_id' => (string)$o, 'note' => 'ZZ9 no onions']);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                $st = zz_q('SELECT station FROM station_messages WHERE order_id = ? ORDER BY station', [$o])->fetchAll(PDO::FETCH_COLUMN);
                zz_check('A7: note edit -> kitchen only (not bar / coffee bar)', !empty($resp['ok']) && $st === ['kitchen'], [$resp, $st]);
            };
            break;

        case 'A7_rush':
            $b = zz_item('A7r drink', 'bar');
            $o = zz_order('A7r');
            zz_line($o, $b, 'bar', 'pending', 'ZZ9 A7r drink');
            zz_page('api/kds-action.php', ['action' => 'toggle_priority_pos', 'order_id' => (string)$o]);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                $st = zz_q('SELECT station FROM station_messages WHERE order_id = ? ORDER BY station', [$o])->fetchAll(PDO::FETCH_COLUMN);
                zz_check('A7: rush -> bar only', !empty($resp['ok']) && $st === ['bar'], [$resp, $st]);
            };
            break;

        /* ---------- A8: 86 of the last unfinished line sends the ready alert ---------- */
        case 'A8_86_last':
            $k = zz_item('A8 dish', 'kitchen');
            $o = zz_order('A8', 'in_progress', 200);
            zz_line($o, $k, 'kitchen', 'ready', 'ZZ9 A8 dish ready');
            $l2 = zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A8 dish 86');
            zz_page('api/kds-action.php', ['action' => 'void_item', 'item_id' => (string)$l2, 'station' => 'kitchen']);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                zz_check('A8: 86 of the last open line -> kitchen ready row', !empty($resp['ok'])
                    && (int)zz_v("SELECT COUNT(*) FROM pos_ready_notifications WHERE order_id = ? AND station = 'kitchen'", [$o]) === 1, $resp);
            };
            break;

        case 'A8_86_not_last': // another line still cooking: no ready row yet
            $k = zz_item('A8n dish', 'kitchen');
            $o = zz_order('A8n', 'in_progress', 200);
            zz_line($o, $k, 'kitchen', 'preparing', 'ZZ9 A8n dish cooking');
            $l2 = zz_line($o, $k, 'kitchen', 'pending', 'ZZ9 A8n dish 86');
            zz_page('api/kds-action.php', ['action' => 'void_item', 'item_id' => (string)$l2, 'station' => 'kitchen']);
            $GLOBALS['zz_after'] = static function ($resp) use ($o): void {
                zz_check('A8: 86 while another line cooks -> no ready row', !empty($resp['ok'])
                    && (int)zz_v('SELECT COUNT(*) FROM pos_ready_notifications WHERE order_id = ?', [$o]) === 0, $resp);
            };
            break;

        /* ---------- A9: deleting a live order tells the cooking station to STOP ---------- */
        case 'A9_delete_stop':
            $k = zz_item('A9 dish', 'kitchen');
            $b = zz_item('A9 drink', 'bar');
            $o = zz_order('A9', 'in_progress', 200);
            zz_line($o, $k, 'kitchen', 'preparing', 'ZZ9 A9 dish');
            zz_line($o, $b, 'bar', 'pending', 'ZZ9 A9 drink');
            $ref = (string)zz_v('SELECT reference FROM stock_orders WHERE id = ?', [$o]);
            $maxMsg = (int)zz_v('SELECT COALESCE(MAX(id), 0) FROM station_messages');
            $r = rh_delete_pos_order($pdo, $o, $A, 'ZZ9 test delete of a live order');
            $msgs = zz_q("SELECT station, message FROM station_messages WHERE id > ? AND order_ref = ? ORDER BY station", [$maxMsg, $ref])->fetchAll();
            $GLOBALS['zz_after'] = static function () use ($r, $msgs, $o): void {
                zz_check('A9: order deleted', !empty($r['ok']) && (int)zz_v('SELECT COUNT(*) FROM stock_orders WHERE id = ?', [$o]) === 0, $r);
                zz_check('A9: one STOP note to the kitchen (cooking), none to the bar (not started)', count($msgs) === 1 && $msgs[0]['station'] === 'kitchen' && stripos((string)$msgs[0]['message'], 'STOP') === 0, $msgs);
            };
            break;

        /* ---------- B4: a discount bigger than the bill stores what actually came off ---------- */
        case 'B4_pay_existing':
            $k = zz_item('B4 dish', 'kitchen');
            $o = zz_order('B4', 'served', 100);
            zz_line($o, $k, 'kitchen', 'served', 'ZZ9 B4 dish');
            zz_page('admin/pos.php', ['action' => 'pay_existing', 'order_id' => (string)$o, 'payment_method' => 'cash', 'tendered_amount' => '1', 'split_count' => '1', 'split_number' => '1',
                'discount_amount' => '150', 'discount_reason' => 'ZZ9 over-discount'], [], false);
            $GLOBALS['zz_after'] = static function () use ($o): void {
                $ord = zz_q('SELECT status, subtotal, total_amount, discount_amount FROM stock_orders WHERE id = ?', [$o])->fetch();
                zz_check('B4 tab: total clamped to 0.01, discount stored as subtotal - total (99.99, not 150)', abs((float)$ord['total_amount'] - 0.01) < 0.005
                    && abs((float)$ord['discount_amount'] - 99.99) < 0.005 && abs((float)$ord['subtotal'] - (float)$ord['total_amount'] - (float)$ord['discount_amount']) < BALANCE_TOLERANCE, $ord);
            };
            break;

        case 'B4_pay_new':
            $k = zz_item('B4n dish', 'kitchen');
            $u = 'zz9-' . bin2hex(random_bytes(8));
            zz_page('admin/pos.php', ['action' => 'pay', 'order_type' => 'walk_in', 'client_uuid' => $u, 'item_id' => [(string)$k], 'item_type' => ['food'], 'item_qty' => ['1'], 'item_note' => [''],
                'payment_method' => 'cash', 'tendered_amount' => '1', 'discount_amount' => '130', 'discount_reason' => 'ZZ9 over-discount'], [], false);
            $GLOBALS['zz_after'] = static function () use ($u): void {
                $ord = zz_q('SELECT subtotal, total_amount, discount_amount FROM stock_orders WHERE client_uuid = ?', [$u])->fetch();
                zz_check('B4 new sale: total 0.01, discount stored 99.99 (subtotal - total, not 130)', is_array($ord) && abs((float)$ord['total_amount'] - 0.01) < 0.005
                    && abs((float)$ord['discount_amount'] - 99.99) < 0.005, $ord);
            };
            break;

        case 'B4_normal_discount': // an ordinary discount is unchanged
            $k = zz_item('B4o dish', 'kitchen');
            $o = zz_order('B4o', 'served', 100);
            zz_line($o, $k, 'kitchen', 'served', 'ZZ9 B4o dish');
            zz_page('admin/pos.php', ['action' => 'pay_existing', 'order_id' => (string)$o, 'payment_method' => 'cash', 'tendered_amount' => '100', 'split_count' => '1', 'split_number' => '1',
                'discount_amount' => '10', 'discount_reason' => 'ZZ9 normal'], [], false);
            $GLOBALS['zz_after'] = static function () use ($o): void {
                $ord = zz_q('SELECT total_amount, discount_amount FROM stock_orders WHERE id = ?', [$o])->fetch();
                zz_check('B4: a 10 discount on 100 stores 10, total 90', abs((float)$ord['total_amount'] - 90) < 0.005 && abs((float)$ord['discount_amount'] - 10) < 0.005, $ord);
            };
            break;

        /* ---------- Step 12 (1): End of Day revenue dates a whole-order refund by its payout, pending excluded ---------- */
        case 'S12_1_refund_pending':
            $biz = (string)rh_station_union_trading_window()['business_date'];
            $before = rh_eod_earned_revenue($pdo, $biz, ['bookings' => false]); // a second memo key, so the after-read is fresh
            $o = zz_order('S12p', 'served', 250, 'refunded');
            zz_q('UPDATE stock_orders SET paid_at = DATE_SUB(NOW(), INTERVAL 3 DAY), refunded_at = NOW() WHERE id = ?', [$o]);
            zz12_refund($o, 250, 'pending', 'NULL');
            $after = rh_eod_earned_revenue($pdo, $biz);
            $GLOBALS['zz_after'] = static function () use ($before, $after): void {
                zz_check('S12 (1): a refund still pending is not in End of Day revenue (reversed unchanged)', abs($after['pos_reversed'] - $before['pos_reversed']) < 0.005, [$before['pos_reversed'], $after['pos_reversed']]);
            };
            break;

        case 'S12_1_refund_paid_today':
            $biz = (string)rh_station_union_trading_window()['business_date'];
            $before = rh_eod_earned_revenue($pdo, $biz, ['bookings' => false]);
            $o = zz_order('S12d', 'served', 250, 'refunded');
            // raised 2 days ago, paid out now: counts today (payout day), like the Z and the EOD summary
            zz_q('UPDATE stock_orders SET paid_at = DATE_SUB(NOW(), INTERVAL 3 DAY), refunded_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?', [$o]);
            zz12_refund($o, 250, 'completed', 'NOW()');
            $after = rh_eod_earned_revenue($pdo, $biz);
            $GLOBALS['zz_after'] = static function () use ($before, $after): void {
                zz_check('S12 (1): a refund paid out today counts today at the order total (250)', abs(($after['pos_reversed'] - $before['pos_reversed']) - 250) < 0.005, [$before['pos_reversed'], $after['pos_reversed']]);
            };
            break;

        /* ---------- Step 12 (2): a voided folio charge lowers the order's cost of goods by the same ratio ---------- */
        case 'S12_2_total_cost':
            $bk = zz12_booking('S12c');
            $o = zz_order('S12c', 'served', 200, 'completed');
            zz_q("UPDATE stock_orders SET order_type = 'room_service', booking_id = ? WHERE id = ?", [$bk, $o]);
            $c1 = zz12_charge($bk, $o, 'ZZ9 S12 dish one');
            $c2 = zz12_charge($bk, $o, 'ZZ9 S12 dish two');
            $g1 = (float)zz_v('SELECT line_total FROM booking_charges WHERE id = ?', [$c1]);
            $g2 = (float)zz_v('SELECT line_total FROM booking_charges WHERE id = ?', [$c2]);
            zz_q('UPDATE stock_orders SET subtotal = ?, total_amount = ?, total_cost = 80 WHERE id = ?', [round($g1 + $g2, 2), round($g1 + $g2, 2), $o]);
            $vr = voidBookingCharge($c1, 'ZZ9 S12 cost', $A);
            $ord = zz_q('SELECT total_amount, total_cost FROM stock_orders WHERE id = ?', [$o])->fetch();
            $det = (string)zz_v("SELECT details FROM stock_order_audit WHERE order_id = ? AND event = 'folio_charge_voided' ORDER BY id DESC LIMIT 1", [$o]);
            $GLOBALS['zz_after'] = static function () use ($vr, $ord, $det, $g1, $g2): void {
                $exp = round(80 * ($g2 / ($g1 + $g2)), 4);
                zz_check('S12 (2): charge voided', !empty($vr['success']), $vr);
                zz_check('S12 (2): total_cost lowered by the subtotal ratio (80 -> ' . $exp . ')', abs((float)$ord['total_cost'] - $exp) < 0.005, [$ord, $exp]);
                zz_check('S12 (2): order audit names the cost change', strpos($det, 'cost 80.00 -> ' . number_format($exp, 2)) !== false, $det);
            };
            break;

        /* ---------- Step 12 (3): a paid order that still has a live folio charge counts once ---------- */
        case 'S12_3_paid_with_folio':
            $biz = (string)rh_station_union_trading_window()['business_date'];
            $before = rh_eod_earned_revenue($pdo, $biz, ['bookings' => false]);
            $bk = zz12_booking('S12f');
            $o = zz_order('S12f', 'served', 100, 'paid');
            zz_q("UPDATE stock_orders SET order_type = 'room_service', booking_id = ?, paid_at = NOW(), payment_method = 'cash' WHERE id = ?", [$bk, $o]);
            $c = zz12_charge($bk, $o, 'ZZ9 S12 paid and on the folio');
            $tot = (float)zz_v('SELECT line_total FROM booking_charges WHERE id = ?', [$c]);
            zz_q('UPDATE stock_orders SET subtotal = ?, total_amount = ? WHERE id = ?', [$tot, $tot, $o]);
            $after = rh_eod_earned_revenue($pdo, $biz);
            $live = rh_order_has_live_folio_charges($pdo, $o);
            $GLOBALS['zz_after'] = static function () use ($before, $after, $tot, $live): void {
                zz_check('S12 (3): the order is seen as billed to a room', $live === true);
                zz_check('S12 (3): till side takes it off again (folio gross + ' . $tot . ', POS net unchanged)',
                    abs(($after['pos_room_folio_gross'] - $before['pos_room_folio_gross']) - $tot) < 0.005 && abs($after['pos_net'] - $before['pos_net']) < 0.005,
                    [$before['pos_room_folio_gross'], $after['pos_room_folio_gross'], $before['pos_net'], $after['pos_net']]);
                zz_check('S12 (3): the folio side keeps it (folio F&B includes the live charge)', $after['folio_fnb_gross'] + 0.005 >= $tot, $after['folio_fnb_gross']);
            };
            break;

        case 'S12_3_pay_refused': // an open tab with a live folio charge can no longer be paid at the till
            $k = zz_item('S12g dish', 'kitchen');
            $bk = zz12_booking('S12g');
            $o = zz_order('S12g', 'served', 100);
            zz_line($o, $k, 'kitchen', 'served', 'ZZ9 S12g dish');
            zz12_charge($bk, $o, 'ZZ9 S12 tab on a folio');
            zz_page('admin/pos.php', ['action' => 'pay_existing', 'order_id' => (string)$o, 'payment_method' => 'cash', 'tendered_amount' => '100', 'split_count' => '1', 'split_number' => '1'], [], false);
            $GLOBALS['zz_after'] = static function () use ($o): void {
                zz_check('S12 (3): till payment refused, order still open, no payment row', (string)zz_v('SELECT status FROM stock_orders WHERE id = ?', [$o]) === 'placed'
                    && (int)zz_v("SELECT COUNT(*) FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND deleted_at IS NULL", [$o]) === 0);
            };
            break;

        case 'S12_3_pay_after_void': // twin: the same tab once its folio charge is voided pays normally (the guard caused the refusal)
            $k = zz_item('S12v dish', 'kitchen');
            $bk = zz12_booking('S12v');
            $o = zz_order('S12v', 'served', 100);
            zz_line($o, $k, 'kitchen', 'served', 'ZZ9 S12v dish');
            $cv = zz12_charge($bk, $o, 'ZZ9 S12 tab charge voided');
            zz_q("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = 'ZZ9' WHERE id = ?", [$cv]);
            zz_page('admin/pos.php', ['action' => 'pay_existing', 'order_id' => (string)$o, 'payment_method' => 'cash', 'tendered_amount' => '100', 'split_count' => '1', 'split_number' => '1'], [], false);
            $GLOBALS['zz_after'] = static function () use ($o): void {
                zz_check('S12 (3): no live folio charge -> the same tab is paid at the till', (string)zz_v('SELECT status FROM stock_orders WHERE id = ?', [$o]) === 'paid');
            };
            break;

        /* ---------- Step 12 (4): voiding a folio charge writes a Booking History line ---------- */
        case 'S12_4_history':
            $bk = zz12_booking('S12h');
            $c = zz12_charge($bk, null, 'ZZ9 S12 minibar');
            $amt = (float)zz_v('SELECT line_total FROM booking_charges WHERE id = ?', [$c]);
            unset($GLOBALS['user']); // no page user (CLI / API): the line must still name the voider
            $vr = voidBookingCharge($c, 'ZZ9 S12 posted to the wrong room', $A);
            $userLeftUnset = !array_key_exists('user', $GLOBALS);
            $rows = zz_q("SELECT * FROM booking_audit_log WHERE booking_id = ? AND action = 'charge_voided'", [$bk])->fetchAll();
            $adm = zz_q('SELECT full_name, username, role FROM admin_users WHERE id = ?', [$A])->fetch();
            $name = (string)($adm['full_name'] ?: $adm['username']);
            $ctx = ['booking' => ['booking_reference' => 'ZZ9'], 'staff' => [$A => ['name' => $name, 'username' => $adm['username'], 'role' => $adm['role']]], 'cur' => 'MWK', 'room_numbers' => []];
            $ev = rh_hist_from_audit($rows, $ctx);
            // A second charge voided straight after: the History list (merge of every audit row of the booking) keeps both lines.
            $c2 = zz12_charge($bk, null, 'ZZ9 S12 laundry');
            voidBookingCharge($c2, 'ZZ9 S12 second void', $A);
            $all = zz_q('SELECT * FROM booking_audit_log WHERE booking_id = ? ORDER BY id', [$bk])->fetchAll();
            $merged = rh_hist_merge(rh_hist_from_audit($all, $ctx));
            $voidLines = count(array_filter($merged, static fn($m) => strpos((string)$m['what'], 'Charge voided: ') === 0));
            $GLOBALS['zz_after'] = static function () use ($vr, $rows, $ev, $A, $amt, $name, $userLeftUnset, $voidLines, $merged): void {
                zz_check('S12 (4): two charges voided back to back stay two History lines', $voidLines === 2, $merged);
                zz_check('S12 (4): charge voided', !empty($vr['success']), $vr);
                zz_check('S12 (4): one History row, written as the voider', count($rows) === 1 && (int)$rows[0]['performed_by'] === $A, $rows);
                zz_check('S12 (4): the page $user is left as it was (unset)', $userLeftUnset);
                $e = $ev[0] ?? [];
                zz_check('S12 (4): plain words: what, amount, who, reason', count($ev) === 1
                    && strpos((string)$e['what'], 'Charge voided: ZZ9 S12 minibar') === 0 && strpos((string)$e['what'], rh_hist_money($amt, 'MWK')) !== false
                    && strpos((string)$e['who'], $name) === 0 && strpos((string)$e['detail'], 'Reason: ZZ9 S12 posted to the wrong room') !== false, $ev);
            };
            break;

        default:
            throw new RuntimeException('unknown case ' . $GLOBALS['zz_case']);
        }
    } catch (Throwable $e) {
        $GLOBALS['zz_fatal'] = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        exit(0);
    }

    if (!empty($GLOBALS['zz_page'])) {
        $zp = $GLOBALS['zz_page'];
        $_POST = $zp['post'] + ['csrf_token' => $_SESSION['csrf_token']];
        $_GET = $zp['get'];
        $_REQUEST = $_POST + $_GET;
        if ($zp['ajax']) { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; }
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/' . $zp['page'];
        chdir($zz9Root . '/' . dirname($zp['page']));
        require $zz9Root . '/' . $zp['page']; // prints and exits; zz_finish() checks and rolls back
    }
    exit(0);
}

/* =====================================================================================================
 * PARENT (never connects: each child validates the target again before it does)
 * ===================================================================================================== */
[$zz9Target] = zz9_target($argv, $zz9Root);
$cases = ['A1_park_first', 'A1_park_retry', 'A1_tab_first', 'A1_tab_retry', 'A6_tab_all_pending', 'A2_round2_ready',
          'A3_poll_after_close', 'A3_my_orders_after_close', 'A7_note', 'A7_rush', 'A8_86_last', 'A8_86_not_last', 'A9_delete_stop',
          'B4_pay_existing', 'B4_pay_new', 'B4_normal_discount',
          'S12_1_refund_pending', 'S12_1_refund_paid_today', 'S12_2_total_cost', 'S12_3_paid_with_folio', 'S12_3_pay_refused', 'S12_3_pay_after_void', 'S12_4_history'];
$only = array_values(array_filter(array_slice($argv, 1), static fn($a) => strpos((string)$a, '--') !== 0));
if ($only) {
    $cases = array_values(array_filter($cases, static function (string $c) use ($only): bool {
        foreach ($only as $o) { if (strpos($c, $o) === 0) { return true; } }
        return false;
    }));
}
$pass = 0; $fail = 0; $failedCases = [];
foreach ($cases as $case) {
    echo "== $case ==\n";
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg('--db=' . $zz9Target) . ' 2>&1');
    $res = null;
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $res = json_decode($m[1], true); }
    if (!is_array($res)) {
        $fail++; $failedCases[$case] = true;
        echo "[FAIL] $case: no result - " . substr(trim($out), -600) . "\n";
        continue;
    }
    $checks = $res['checks'] ?? [];
    $checks[] = ['name' => "$case: rolled back, marker kept, nothing left behind", 'ok' => !empty($res['rolled_back']) && empty($res['marker_lost']) && $res['leak'] === false,
                 'detail' => json_encode(['rolled_back' => $res['rolled_back'] ?? null, 'marker_lost' => $res['marker_lost'] ?? null, 'leak' => $res['leak'] ?? null, 'left' => $res['left_behind'] ?? null, 'err' => $res['leak_check_error'] ?? null])];
    if (count($checks) < 2) { $checks[] = ['name' => "$case: produced checks", 'ok' => false, 'detail' => $res['out_tail'] ?? '']; }
    foreach ($checks as $c) {
        if ($c['ok']) { $pass++; echo "[PASS] {$c['name']}\n"; }
        else { $fail++; $failedCases[$case] = true; echo "[FAIL] {$c['name']}" . (($c['detail'] ?? '') !== '' ? ': ' . substr((string)$c['detail'], 0, 900) : '') . (($res['out_tail'] ?? '') !== '' ? ' | out: ' . $res['out_tail'] : '') . "\n"; }
    }
    if (!empty($res['ddl_skipped'])) { echo "   (DDL skipped inside the test transaction: " . implode(' | ', array_unique($res['ddl_skipped'])) . ")\n"; }
}
echo "\n$pass passed, $fail failed" . ($failedCases ? ' (cases with a failure: ' . implode(', ', array_keys($failedCases)) . ')' : '') . "\n";
exit($fail === 0 ? 0 : 1);
