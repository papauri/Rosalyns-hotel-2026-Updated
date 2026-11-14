<?php
/**
 * Step 8c Build A - POS callers of the stock deduct / restore engine.
 *
 *   php scripts/tests/stock_pos_restore_8c.php            run every case
 *   php scripts/tests/stock_pos_restore_8c.php P2 P6      run only these cases
 *
 * LIWONDE ONLY (sha1 of the database name, refuses any other database, parent and child).
 * Each case runs in its own child PHP process on a PDO subclass that holds ONE outer transaction which
 * is always ROLLED BACK: the code under test sees "no transaction" on entry exactly as it does live
 * (its own begin / commit / rollBack become SAVEPOINT / RELEASE / ROLLBACK TO), statements that would
 * commit implicitly (DDL, LOCK TABLES, autocommit) are skipped, and after the rollback the connection
 * is sealed so nothing can write afterwards. A marker row proves the outer transaction never ended, a
 * second connection proves the marker (and every ZZ8C row) is invisible / gone afterwards.
 * Liwonde has no till data: each case builds its own ZZ8C ingredient (two FIFO batches 6 @ 4 and 6 @ 6),
 * recipe (1 per portion), menu items, booking, order, lines and room-service charges, and calls the REAL
 * functions; KDS actions run the real api/kds-action.php as the last step of the child.
 * Every case checks: ingredient quantity = SUM(active batch remaining), the ledger (12 + SUM of the
 * ingredient's adjustments) = quantity, and the net movement the rule expects.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const ZZ_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde database name (name kept out of the public repo)
const ZZ_START = 12.0; // two batches of 6
const ZZ_TYPE = 'zz8c'; // one menu_type slug for recipe, line and charge
$zzRoot = dirname(__DIR__, 2);

/* =====================================================================================================
 * CHILD
 * ===================================================================================================== */
if (($argv[1] ?? '') === '--child') {
    $GLOBALS['zz_case'] = (string)($argv[2] ?? '');
    $GLOBALS['zz_checks'] = [];
    $GLOBALS['zz_ddl'] = [];
    $GLOBALS['zz_after'] = null;
    $_SERVER['HTTP_HOST'] = 'localhost'; // the web scheduler skips a local copy
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/api/kds-action.php';
    require $zzRoot . '/config/database.php';
    if (sha1(DB_NAME) !== ZZ_DB_SHA1) { fwrite(STDERR, "child refused: not the Liwonde database\n"); exit(9); }

    class ZZ8cPdo extends PDO
    {
        private $sp = 0;
        public $sealed = false;
        public static function commitsImplicitly(string $sql): bool
        {
            $s = ltrim((string)preg_replace('#^(\s+|/\*(?!!).*?\*/|(--|\#)[^\n]*\n)+#s', '', $sql));
            $bare = (string)preg_replace('#\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`#s', "''", $s);
            if (preg_match('#;\s*\S#', rtrim($bare, "; \t\r\n")) || strpos($bare, '/*!') !== false) {
                return true;
            }
            if (preg_match('#^(CREATE|DROP)\s+TEMPORARY\s#i', $s)) {
                return false;
            }
            return (bool)preg_match('#^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|COMMIT|START\s+TRANSACTION|BEGIN|ANALYZE|OPTIMIZE|REPAIR|CHECK\s+TABLE|FLUSH|RESET|CACHE\s+INDEX|LOAD\s+INDEX|GRANT|REVOKE|SET\s+PASSWORD|INSTALL|UNINSTALL)\b#i', $s)
                || (bool)preg_match('#^SET\s+(GLOBAL\s+|SESSION\s+|LOCAL\s+)?(@@(global\.|session\.|local\.)?)?autocommit\b#i', $s);
        }
        private function blocked(string $sql): bool
        {
            if ($this->sealed) {
                throw new RuntimeException('ZZ8C test connection is sealed (after rollback)');
            }
            if (self::commitsImplicitly($sql)) {
                $GLOBALS['zz_ddl'][] = substr((string)preg_replace('/\s+/', ' ', trim($sql)), 0, 100);
                return true;
            }
            return false;
        }
        public function zzBegin(): void { parent::beginTransaction(); }
        public function zzOuterOpen(): bool { return parent::inTransaction(); }
        public function zzRollback(): void { if (parent::inTransaction()) { parent::rollBack(); } $this->sealed = true; }
        public function inTransaction(): bool { return $this->sp > 0; }
        public function beginTransaction(): bool
        {
            if ($this->sealed || !parent::inTransaction()) {
                throw new RuntimeException('ZZ8C outer test transaction is not open');
            }
            if ($this->sp > 0) {
                throw new PDOException('There is already an active transaction');
            }
            $this->sp++;
            parent::exec('SAVEPOINT zz8c_sp' . $this->sp);
            return true;
        }
        public function commit(): bool
        {
            if ($this->sp <= 0) {
                throw new PDOException('There is no active transaction');
            }
            parent::exec('RELEASE SAVEPOINT zz8c_sp' . $this->sp);
            $this->sp--;
            return true;
        }
        public function rollBack(): bool
        {
            if ($this->sp <= 0) {
                throw new PDOException('There is no active transaction');
            }
            parent::exec('ROLLBACK TO SAVEPOINT zz8c_sp' . $this->sp);
            parent::exec('RELEASE SAVEPOINT zz8c_sp' . $this->sp);
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
    $pdo = new ZZ8cPdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $zzOpts);
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    $zzMode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    if (stripos($zzMode, 'STRICT_TRANS_TABLES') === false) {
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(trim($zzMode . ',STRICT_TRANS_TABLES', ',')));
    }
    $GLOBALS['pdo'] = $pdo;
    $pdo->zzBegin();
    // Inside the rolled-back transaction: no settings file cache of uncommitted values, no scheduled emails.
    $pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('cache_global_enabled','0'),('automated_email_master','0') ON DUPLICATE KEY UPDATE setting_value='0'");
    $GLOBALS['zz_marker'] = '__zz8c_marker_' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, '1')")->execute([$GLOBALS['zz_marker']]);
    // pos_deleted_orders is created by the delete on first use; here a TEMPORARY copy (no implicit commit, no schema change).
    $pdo->exec("CREATE TEMPORARY TABLE pos_deleted_orders (
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
    $zzSessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz8c_sessions';
    if (!is_dir($zzSessDir)) { mkdir($zzSessDir, 0777, true); }
    session_save_path($zzSessDir);
    define('RH_SESSION_KEEP_SAVE_PATH', true);
    ini_set('session.use_cookies', '0');
    session_id('zz8c' . bin2hex(random_bytes(6)));
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
    function zz_ing(string $tag): int
    {
        zz_q("INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit) VALUES (?, 'ZZ8C', 'pcs', ?, 0, 5)", ['ZZ8C ' . $tag . ' Flour', ZZ_START]);
        $ing = (int)$GLOBALS['pdo']->lastInsertId();
        zz_q("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, 'ZZ8C-B1', 6, 6, 4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'active')", [$ing]);
        zz_q("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, status) VALUES (?, 'ZZ8C-B2', 6, 6, 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'active')", [$ing]);
        return $ing;
    }
    function zz_item(string $tag, ?int $ing): int
    {
        $cat = (int)$GLOBALS['pdo']->query('SELECT id FROM menu_categories ORDER BY id LIMIT 1')->fetchColumn();
        zz_q("INSERT INTO menu_items (category_id, item_name, price, station, is_available) VALUES (?, ?, 100, 'kitchen', 1)", [$cat, 'ZZ8C ' . $tag . ' Dish']);
        $item = (int)$GLOBALS['pdo']->lastInsertId();
        if ($ing) {
            zz_recipe($item, $ing);
        }
        return $item;
    }
    function zz_recipe(int $item, int $ing): void
    {
        zz_q('INSERT INTO stock_recipes (menu_item_id, menu_type) VALUES (?, ?)', [$item, ZZ_TYPE]);
        $rid = (int)$GLOBALS['pdo']->lastInsertId();
        zz_q('INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion) VALUES (?, ?, 1)', [$rid, $ing]);
    }
    function zz_booking(string $tag): int
    {
        $room = (int)$GLOBALS['pdo']->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
        zz_q("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)
              VALUES (?, ?, 'ZZ8C Guest', 'zz8c@example.invalid', '000', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2, 1, 1, 0, 1000, 1000, 0, 1000, 'checked-in')",
            ['ZZ8C-' . $tag . '-' . random_int(1000, 9999), $room]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz_order(string $tag, string $type, string $status, ?int $bookingId, string $kitchen = 'new', bool $servedNow = false): int
    {
        zz_q("INSERT INTO stock_orders (reference, order_type, booking_id, customer_name, status, total_amount, subtotal, kitchen_status, created_by, served_at, created_at)
              VALUES (?, ?, ?, 'ZZ8C', ?, 100, 100, ?, ?, " . ($servedNow ? 'NOW()' : 'NULL') . ', DATE_SUB(NOW(), INTERVAL 2 SECOND))',
            ['ZZ8C-' . $tag . '-' . random_int(100000, 999999), $type, $bookingId, $status, $kitchen, $GLOBALS['zz_admin']]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    function zz_line(int $orderId, int $item, float $qty, string $kds, int $flag = 0, string $name = 'ZZ8C line'): int
    {
        zz_q("INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, station, kds_status, started_at, ready_at, served_at, stock_deducted)
              VALUES (?, ?, ?, ?, ?, 100, ?, 'kitchen', ?, " . ($kds === 'pending' ? 'NULL' : 'NOW()') . ', ' . (in_array($kds, ['ready', 'collection', 'served'], true) ? 'NOW()' : 'NULL') . ', ' . ($kds === 'served' ? 'NOW()' : 'NULL') . ', ?)',
            [$orderId, $item, ZZ_TYPE, $name, $qty, 100 * $qty, $kds, $flag]);
        return (int)$GLOBALS['pdo']->lastInsertId();
    }
    /** What the KDS does at ready / bump: deduct per LINE (source pos_order) and set the flag. */
    function zz_kds_deduct(int $line, int $item, float $qty): void
    {
        $ok = deductStockForMenuItem($item, ZZ_TYPE, $qty, 'pos_order', $line, $GLOBALS['zz_admin']);
        zz_q('UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?', [$line]);
        if (!$ok) { throw new RuntimeException('fixture: KDS deduction failed'); }
    }
    /** What admin/pos.php:236-245 does for a room-service line: folio charge from the menu (deducts when a recipe exists), stock_order_id, line flag 1. */
    function zz_rs_charge(int $bookingId, int $orderId, int $item, float $qty, int $line): int
    {
        $c = addBookingChargeFromMenu($bookingId, ZZ_TYPE, $item, $qty, $GLOBALS['zz_admin']);
        if (empty($c['success']) || empty($c['charge_id'])) { throw new RuntimeException('fixture: charge failed ' . json_encode($c)); }
        zz_q('UPDATE booking_charges SET stock_order_id = ? WHERE id = ?', [$orderId, (int)$c['charge_id']]);
        zz_q('UPDATE stock_order_items SET stock_deducted = 1 WHERE id = ?', [$line]);
        return (int)$c['charge_id'];
    }
    function zz_qty(int $ing): float
    {
        return (float)zz_q('SELECT current_quantity FROM stock_ingredients WHERE id = ?', [$ing])->fetchColumn();
    }
    /** Invariants + expected net: quantity = SUM(active batches) = 12 + SUM(adjustments), and = $expected. */
    function zz_inv(string $label, int $ing, float $expected): void
    {
        $cur = zz_qty($ing);
        $bat = (float)zz_q("SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_batches WHERE ingredient_id = ? AND status = 'active'", [$ing])->fetchColumn();
        $led = ZZ_START + (float)zz_q('SELECT COALESCE(SUM(quantity_change), 0) FROM stock_adjustments WHERE ingredient_id = ?', [$ing])->fetchColumn();
        zz_check($label . ': quantity = SUM(active batch remaining)', abs($cur - $bat) < 0.0001, "qty $cur, batches $bat");
        zz_check($label . ': ledger 12 + SUM(adjustments) = quantity', abs($cur - $led) < 0.0001, "qty $cur, ledger $led");
        zz_check($label . ': net stock = ' . $expected . ' (start 12)', abs($cur - $expected) < 0.0001, "qty $cur");
    }
    function zz_line_row(int $line): array
    {
        return zz_q('SELECT kds_status, stock_deducted FROM stock_order_items WHERE id = ?', [$line])->fetch() ?: ['kds_status' => '(gone)', 'stock_deducted' => null];
    }
    function zz_counts(int $line): array
    {
        $d = (int)zz_q("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id = ? AND quantity_change < 0", [$line])->fetchColumn();
        $r = (int)zz_q("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'void_restore' AND restore_of_type = 'pos_order' AND source_id = ?", [$line])->fetchColumn();
        return ['deductions' => $d, 'restores' => $r];
    }
    function zz_set_shop(bool $shop): void
    {
        $v = $shop ? '0' : '1'; // rolled back with everything else; the in-memory copy is what getSetting() reads
        zz_q("INSERT INTO site_settings (setting_key, setting_value) VALUES ('restaurant_system_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$v]);
        $GLOBALS['_SITE_SETTINGS']['restaurant_system_enabled'] = $v;
    }
    function zz_endpoint(array $post): void
    {
        $GLOBALS['zz_endpoint'] = $post;
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
                ($GLOBALS['zz_after'])($resp);
            } catch (Throwable $e) {
                zz_check('after-checks ran', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        $res = ['case' => $GLOBALS['zz_case'], 'response' => $resp, 'out_tail' => $resp === null ? substr($buf, -300) : '', 'ddl_skipped' => $GLOBALS['zz_ddl']];
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
            $left += (int)$probe->query("SELECT COUNT(*) FROM stock_ingredients WHERE name LIKE 'ZZ8C %'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM menu_items WHERE item_name LIKE 'ZZ8C %'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM stock_orders WHERE reference LIKE 'ZZ8C-%'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'ZZ8C-%'")->fetchColumn();
            $left += (int)$probe->query("SELECT COUNT(*) FROM stock_order_items WHERE item_name LIKE 'ZZ8C%'")->fetchColumn();
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

    require_once $zzRoot . '/admin/includes/permissions.php';
    require_once $zzRoot . '/admin/includes/restaurant-payment-sync.php';
    require_once $zzRoot . '/admin/includes/pos-order-close.php';
    require_once $zzRoot . '/admin/includes/pos-order-delete.php';
    require_once $zzRoot . '/admin/includes/finance-account-sync.php';
    $A = $GLOBALS['zz_admin'];

    try {
        switch ($GLOBALS['zz_case']) {

        /* ---------- P1: delete of a closed order never deducts; live orders decide from the trail ---------- */
        case 'P1_voided': // restaurant: a served line, voided (wastage reclassified, flag cleared), then deleted
            zz_set_shop(false);
            $ing = zz_ing('P1v'); $item = zz_item('P1v', $ing);
            $o = zz_order('P1v', 'walk_in', 'placed', null, 'served');
            $l = zz_line($o, $item, 1, 'served');
            zz_kds_deduct($l, $item, 1);
            $pdo->beginTransaction(); rh_close_pos_order($pdo, $o, $A, 'ZZ8C void before delete', 'void'); $pdo->commit();
            $GLOBALS['zz_mid'] = zz_qty($ing);
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete of a voided order');
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_check('P1 voided: after the void the served portion stays out (11)', abs($GLOBALS['zz_mid'] - 11) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_inv('P1 voided: delete deducts nothing more', $ing, 11);
            };
            break;

        case 'P1_cancelled': // a cancelled order holding a served line (rh_close_pos_order 'cancel' accepts it), then deleted
            zz_set_shop(false);
            $ing = zz_ing('P1c'); $item = zz_item('P1c', $ing);
            $o = zz_order('P1c', 'walk_in', 'placed', null, 'in_progress');
            $l1 = zz_line($o, $item, 1, 'served');
            zz_kds_deduct($l1, $item, 1);
            zz_line($o, $item, 2, 'pending');
            $pdo->beginTransaction(); rh_close_pos_order($pdo, $o, $A, 'ZZ8C cancel before delete', 'cancel'); $pdo->commit();
            $GLOBALS['zz_mid'] = zz_qty($ing);
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete of a cancelled order');
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_check('P1 cancelled: after the cancel 11', abs($GLOBALS['zz_mid'] - 11) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_inv('P1 cancelled: delete deducts nothing more', $ing, 11);
            };
            break;

        case 'P1_refunded_shop': // shop mode: the refund puts the served goods back (flag cleared, line stays served), then deleted
        case 'P1_refunded_rest': // restaurant: the refund leaves the served line alone, then deleted
            $shop = $GLOBALS['zz_case'] === 'P1_refunded_shop';
            zz_set_shop($shop);
            $ing = zz_ing('P1r'); $item = zz_item('P1r', $ing);
            $o = zz_order('P1r', 'walk_in', 'paid', null, 'served', true);
            $l = zz_line($o, $item, 1, 'served');
            zz_kds_deduct($l, $item, 1);
            $ref = (string)zz_q('SELECT reference FROM stock_orders WHERE id = ?', [$o])->fetchColumn();
            zz_q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_type, payment_status, recorded_by)
                  VALUES (?, 'restaurant', ?, ?, CURDATE(), 100, 100, 'cash', 'full_payment', 'completed', ?)", ['ZZ8C-PAY-' . $o, $o, $ref, $A]);
            $orig = (int)$pdo->lastInsertId();
            zz_q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_type, payment_status, refund_status, refund_amount, refund_reason, original_payment_id, recorded_by)
                  VALUES (?, 'restaurant', ?, ?, CURDATE(), -100, -100, 'cash', 'refund', 'completed', 'completed', 100, 'service_issue', ?, ?)", ['ZZ8C-REF-' . $o, $o, $ref, $orig, $A]);
            $pdo->beginTransaction(); $st = rh_sync_restaurant_order_refund_state($pdo, $o, 'ZZ8C refund before delete'); $pdo->commit();
            $GLOBALS['zz_mid'] = zz_qty($ing);
            $GLOBALS['zz_st'] = $st;
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete of a refunded order');
            $exp = $shop ? 12.0 : 11.0;
            $GLOBALS['zz_after'] = static function () use ($ing, $exp, $shop): void {
                zz_check('P1 refunded (' . ($shop ? 'shop' : 'restaurant') . '): the order became refunded', $GLOBALS['zz_st'] === 'refunded', (string)$GLOBALS['zz_st']);
                zz_check('P1 refunded (' . ($shop ? 'shop' : 'restaurant') . '): after the refund ' . $exp, abs($GLOBALS['zz_mid'] - $exp) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_inv('P1 refunded (' . ($shop ? 'shop' : 'restaurant') . '): delete deducts nothing more', $ing, $exp);
            };
            break;

        case 'P1_live_rs_untracked': // live room-service order: charge posted before the recipe existed (untracked), line flag 1, started
            zz_set_shop(false);
            $ing = zz_ing('P1u'); $item = zz_item('P1u', null);
            $b = zz_booking('P1u');
            $o = zz_order('P1u', 'room_service', 'placed', $b, 'in_progress');
            $l = zz_line($o, $item, 1, 'preparing');
            zz_rs_charge($b, $o, $item, 1, $l);
            zz_recipe($item, $ing); // recipe added later: the charge never deducted
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete of a live room-service order');
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_inv('P1 live, started, never deducted (flag 1): still takes its wastage', $ing, 11);
                zz_check('P1 live: exactly one wastage row', (int)zz_q('SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?', [$ing])->fetchColumn() === 1);
            };
            break;

        case 'P1_live_plain': // live till order: a started line the KDS never deducted (flag 0)
            zz_set_shop(false);
            $ing = zz_ing('P1p'); $item = zz_item('P1p', $ing);
            $o = zz_order('P1p', 'walk_in', 'placed', null, 'in_progress');
            zz_line($o, $item, 1, 'preparing');
            $l2 = zz_line($o, $item, 2, 'served');
            zz_kds_deduct($l2, $item, 2);
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete of a live till order');
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_inv('P1 live: started never-deducted line wasted, served line stays out (12-1-2)', $ing, 9);
            };
            break;

        /* ---------- P2: recall moves no stock for a charge-tracked line; pos_order line restored once ---------- */
        case 'P2_recall':
            zz_set_shop(false);
            $ing = zz_ing('P2'); $item = zz_item('P2', $ing);
            $b = zz_booking('P2');
            $o = zz_order('P2', 'room_service', 'placed', $b, 'served', true);
            $la = zz_line($o, $item, 2, 'served', 0, 'ZZ8C line A');
            zz_kds_deduct($la, $item, 2);
            $lb = zz_line($o, $item, 1, 'served', 0, 'ZZ8C line B');
            $cb = zz_rs_charge($b, $o, $item, 1, $lb);
            $GLOBALS['zz_mid'] = zz_qty($ing);
            zz_endpoint(['action' => 'recall_ticket', 'order_id' => (string)$o]);
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $la, $lb, $cb): void {
                zz_check('P2: setup took 3 (9)', abs($GLOBALS['zz_mid'] - 9) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_check('P2: recall ok', ($resp['ok'] ?? false) === true, $resp);
                zz_inv('P2: only the pos_order line comes back (9 + 2)', $ing, 11);
                $ca = zz_counts($la); $cbn = zz_counts($lb);
                zz_check('P2: line A restored exactly once', $ca['restores'] === 1 && $ca['deductions'] === 1, $ca);
                zz_check('P2: charge line B: no pos_order restore', $cbn['restores'] === 0, $cbn);
                zz_check('P2: charge line B keeps stock_deducted = 1 (re-bump will not deduct again)', (int)zz_line_row($lb)['stock_deducted'] === 1, zz_line_row($lb));
                zz_check('P2: line A flag cleared (its restore was written)', (int)zz_line_row($la)['stock_deducted'] === 0, zz_line_row($la));
                zz_check('P2: both lines back to preparing', zz_line_row($la)['kds_status'] === 'preparing' && zz_line_row($lb)['kds_status'] === 'preparing');
                zz_check('P2: charge still live', (int)zz_q('SELECT voided FROM booking_charges WHERE id = ?', [$cb])->fetchColumn() === 0);
            };
            break;

        /* ---------- P3: voidBookingCharge closes an UNSERVED linked line; a served line is untouched ---------- */
        case 'P3_unserved_tracked':
        case 'P3_unserved_untracked':
            zz_set_shop(false);
            $tracked = $GLOBALS['zz_case'] === 'P3_unserved_tracked';
            $ing = zz_ing('P3u'); $item = zz_item('P3u', $tracked ? $ing : null);
            $item0 = zz_item('P3u0', null); // a served dish without a recipe (no stock)
            $b = zz_booking('P3u');
            $o = zz_order('P3u', 'room_service', 'paid', $b, 'in_progress');
            $l0 = zz_line($o, $item0, 1, 'served', 0, 'ZZ8C served side');
            zz_rs_charge($b, $o, $item0, 1, $l0);
            $l = zz_line($o, $item, 1, 'pending');
            $c = zz_rs_charge($b, $o, $item, 1, $l);
            if (!$tracked) { zz_recipe($item, $ing); }
            $GLOBALS['zz_setup'] = zz_qty($ing);
            $vr = voidBookingCharge($c, 'ZZ8C void the charge', $A);
            $GLOBALS['zz_vr'] = $vr;
            $GLOBALS['zz_mid'] = zz_qty($ing);
            $GLOBALS['zz_mid_line'] = zz_line_row($l);
            $GLOBALS['zz_mid_kitchen'] = (string)zz_q('SELECT kitchen_status FROM stock_orders WHERE id = ?', [$o])->fetchColumn();
            zz_endpoint(['action' => 'void_item', 'item_id' => (string)$l]); // a later 86 of the same line
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $l, $tracked): void {
                $tag = 'P3 unserved ' . ($tracked ? 'tracked' : 'untracked');
                zz_check($tag . ': setup ' . ($tracked ? '11' : '12'), abs($GLOBALS['zz_setup'] - ($tracked ? 11 : 12)) < 0.0001, (string)$GLOBALS['zz_setup']);
                zz_check($tag . ': charge voided', !empty($GLOBALS['zz_vr']['success']), $GLOBALS['zz_vr']);
                zz_check($tag . ': stock back to 12 after the charge void', abs($GLOBALS['zz_mid'] - 12) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_check($tag . ': line void + flag 0 after the charge void', $GLOBALS['zz_mid_line']['kds_status'] === 'void' && (int)$GLOBALS['zz_mid_line']['stock_deducted'] === 0, $GLOBALS['zz_mid_line']);
                zz_check($tag . ': kitchen_status recomputed (only the served side left)', $GLOBALS['zz_mid_kitchen'] === 'served', $GLOBALS['zz_mid_kitchen']);
                zz_inv($tag . ': a later 86 restores nothing extra', $ing, 12);
                $cn = zz_counts($l);
                zz_check($tag . ': no pos_order restore without a pos_order deduction', $cn['restores'] <= $cn['deductions'], $cn);
            };
            break;

        case 'P3_served':
            zz_set_shop(false);
            $ing = zz_ing('P3s'); $item = zz_item('P3s', $ing);
            $b = zz_booking('P3s');
            $o = zz_order('P3s', 'room_service', 'completed', $b, 'served', true);
            $l = zz_line($o, $item, 1, 'served');
            $c = zz_rs_charge($b, $o, $item, 1, $l);
            $vr = voidBookingCharge($c, 'ZZ8C void a served charge', $A);
            $GLOBALS['zz_vr'] = $vr;
            $GLOBALS['zz_mid'] = zz_qty($ing);
            $GLOBALS['zz_mid_line'] = zz_line_row($l);
            $pdo->beginTransaction(); rh_close_pos_order($pdo, $o, $A, 'ZZ8C later close', 'void'); $pdo->commit();
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_check('P3 served: charge voided', !empty($GLOBALS['zz_vr']['success']), $GLOBALS['zz_vr']);
                zz_check('P3 served: stock stays out after the charge void (11)', abs($GLOBALS['zz_mid'] - 11) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_check('P3 served: line untouched (served, flag 1)', $GLOBALS['zz_mid_line']['kds_status'] === 'served' && (int)$GLOBALS['zz_mid_line']['stock_deducted'] === 1, $GLOBALS['zz_mid_line']);
                zz_inv('P3 served: a later order void moves nothing more', $ing, 11);
            };
            break;

        case 'P3_served_delete': // served room-service line, its charge voided alone (wastage on the charge), then the ORDER deleted
            zz_set_shop(false);
            $ing = zz_ing('P3d'); $item = zz_item('P3d', $ing);
            $b = zz_booking('P3d');
            $o = zz_order('P3d', 'room_service', 'completed', $b, 'served', true);
            $l = zz_line($o, $item, 1, 'served');
            $c = zz_rs_charge($b, $o, $item, 1, $l);
            $vr = voidBookingCharge($c, 'ZZ8C void a served charge', $A);
            $GLOBALS['zz_vr'] = $vr;
            $GLOBALS['zz_mid'] = zz_qty($ing);
            rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete after a served charge void');
            $GLOBALS['zz_after'] = static function () use ($ing): void {
                zz_check('P3 served+delete: charge voided', !empty($GLOBALS['zz_vr']['success']), $GLOBALS['zz_vr']);
                zz_check('P3 served+delete: stock out after the charge void (11)', abs($GLOBALS['zz_mid'] - 11) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_inv('P3 served+delete: deleting the order takes no second deduction', $ing, 11);
                zz_check('P3 served+delete: exactly one wastage row', (int)zz_q('SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?', [$ing])->fetchColumn() === 1);
            };
            break;

        /* ---------- P4: untracked charge -> at most one deduction and one restore ---------- */
        case 'P4_86_untracked':
            zz_set_shop(false);
            $ing = zz_ing('P4a'); $item = zz_item('P4a', null);
            $b = zz_booking('P4a');
            $o = zz_order('P4a', 'room_service', 'placed', $b, 'new');
            $l = zz_line($o, $item, 1, 'pending');
            zz_rs_charge($b, $o, $item, 1, $l);
            zz_recipe($item, $ing);
            zz_endpoint(['action' => 'void_item', 'item_id' => (string)$l]);
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $l): void {
                zz_check('P4 86: ok', ($resp['ok'] ?? false) === true, $resp);
                zz_inv('P4 86 of an untracked room-service line: no phantom credit', $ing, 12);
                $cn = zz_counts($l);
                zz_check('P4 86: <= 1 deduction and restores <= deductions', $cn['deductions'] <= 1 && $cn['restores'] <= $cn['deductions'], $cn);
            };
            break;

        case 'P4_close_untracked': // booking close, line not started, untracked charge (pos-order-close.php:447)
        case 'P4_close_nocharge':  // booking close, line not started, no charge, flag 1 with no trail (pos-order-close.php:453)
            zz_set_shop(false);
            $withCharge = $GLOBALS['zz_case'] === 'P4_close_untracked';
            $ing = zz_ing('P4c'); $item = zz_item('P4c', null);
            $b = zz_booking('P4c');
            $o = zz_order('P4c', $withCharge ? 'room_service' : 'walk_in', 'placed', $b, 'new');
            $l = zz_line($o, $item, 1, 'pending');
            if ($withCharge) {
                zz_rs_charge($b, $o, $item, 1, $l);
            } else {
                zz_kds_deduct($l, $item, 1); // no recipe yet: nothing taken, flag 1
            }
            zz_recipe($item, $ing);
            $pdo->beginTransaction(); $kr = rh_close_order_keep_started($pdo, $o, $A, 'ZZ8C booking closed'); $pdo->commit();
            $GLOBALS['zz_after'] = static function () use ($ing, $l, $withCharge): void {
                $tag = 'P4 close ' . ($withCharge ? '(untracked charge)' : '(no charge)');
                zz_inv($tag . ': no phantom credit', $ing, 12);
                $cn = zz_counts($l);
                zz_check($tag . ': <= 1 deduction and restores <= deductions', $cn['deductions'] <= 1 && $cn['restores'] <= $cn['deductions'], $cn);
                zz_check($tag . ': line closed', zz_line_row($l)['kds_status'] === 'void', zz_line_row($l));
            };
            break;

        /* ---------- P5: a failure inside rh_close_refunded_order_lines leaves no half-restored stock ---------- */
        case 'P5_refund_savepoint':
            zz_set_shop(false);
            $ing = zz_ing('P5'); $item = zz_item('P5', $ing);
            $o = zz_order('P5', 'walk_in', 'paid', null, 'new');
            $l1 = zz_line($o, $item, 1, 'pending', 0, 'ZZ8C line 1');
            zz_kds_deduct($l1, $item, 1);
            $l2 = zz_line($o, $item, 1, 'pending', 0, 'ZZ8C line 2');
            zz_kds_deduct($l2, $item, 1);
            zz_q("UPDATE stock_order_items SET menu_type = '' WHERE id = ?", [$l2]); // forces line 2's restore to fail
            $ref = (string)zz_q('SELECT reference FROM stock_orders WHERE id = ?', [$o])->fetchColumn();
            zz_q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_type, payment_status, recorded_by)
                  VALUES (?, 'restaurant', ?, ?, CURDATE(), 200, 200, 'cash', 'full_payment', 'completed', ?)", ['ZZ8C-PAY-' . $o, $o, $ref, $A]);
            $orig = (int)$pdo->lastInsertId();
            zz_q("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_type, payment_status, refund_status, refund_amount, refund_reason, original_payment_id, recorded_by)
                  VALUES (?, 'restaurant', ?, ?, CURDATE(), -200, -200, 'cash', 'refund', 'completed', 'completed', 200, 'service_issue', ?, ?)", ['ZZ8C-REF-' . $o, $o, $ref, $orig, $A]);
            $logBefore = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM system_event_log')->fetchColumn();
            $pdo->beginTransaction(); $st = rh_sync_restaurant_order_refund_state($pdo, $o, 'ZZ8C refund'); $pdo->commit();
            $GLOBALS['zz_after'] = static function () use ($ing, $l1, $l2, $o, $st, $logBefore): void {
                zz_check('P5: order still refunded', $st === 'refunded' && (string)zz_q('SELECT status FROM stock_orders WHERE id = ?', [$o])->fetchColumn() === 'refunded', (string)$st);
                zz_inv('P5: the failed close left no half-restored stock (12 - 2)', $ing, 10);
                zz_check('P5: line 1 not closed half-way (still pending, flag 1)', zz_line_row($l1)['kds_status'] === 'pending' && (int)zz_line_row($l1)['stock_deducted'] === 1, zz_line_row($l1));
                zz_check('P5: line 2 untouched', zz_line_row($l2)['kds_status'] === 'pending', zz_line_row($l2));
                zz_check('P5: the failure is in the system log', (int)zz_q('SELECT COUNT(*) FROM system_event_log WHERE id > ? AND message LIKE ?', [$logBefore, '%#' . $o . '%'])->fetchColumn() >= 1);
            };
            break;

        /* ---------- P6: recall refused on a refunded order ---------- */
        case 'P6_recall_refunded':
            zz_set_shop(false);
            $ing = zz_ing('P6'); $item = zz_item('P6', $ing);
            $o = zz_order('P6', 'walk_in', 'refunded', null, 'served', true);
            $l = zz_line($o, $item, 1, 'served');
            zz_kds_deduct($l, $item, 1);
            zz_endpoint(['action' => 'recall_ticket', 'order_id' => (string)$o]);
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $l): void {
                zz_check('P6: recall refused', ($resp['ok'] ?? true) === false, $resp);
                zz_inv('P6: no stock moved', $ing, 11);
                zz_check('P6: line still served, flag 1', zz_line_row($l)['kds_status'] === 'served' && (int)zz_line_row($l)['stock_deducted'] === 1, zz_line_row($l));
            };
            break;

        /* ---------- P7: two identical room-service lines, one served one not: each charge follows its own line ---------- */
        case 'P7_close_twins':
        case 'P7_delete_twins':
        case 'P7_86_twin':
            zz_set_shop(false);
            $ing = zz_ing('P7'); $item = zz_item('P7', $ing);
            $b = zz_booking('P7');
            $o = zz_order('P7', 'room_service', 'placed', $b, 'in_progress');
            $l1 = zz_line($o, $item, 1, 'served', 0, 'ZZ8C twin 1');
            $c1 = zz_rs_charge($b, $o, $item, 1, $l1);
            $l2 = zz_line($o, $item, 1, 'pending', 0, 'ZZ8C twin 2');
            $c2 = zz_rs_charge($b, $o, $item, 1, $l2);
            $GLOBALS['zz_setup'] = zz_qty($ing);
            $case = $GLOBALS['zz_case'];
            if ($case === 'P7_close_twins') {
                $pdo->beginTransaction(); rh_close_pos_order($pdo, $o, $A, 'ZZ8C void twins', 'void'); $pdo->commit();
            } elseif ($case === 'P7_delete_twins') {
                rh_delete_pos_order($pdo, $o, $A, 'ZZ8C delete twins order');
            } else {
                zz_endpoint(['action' => 'void_item', 'item_id' => (string)$l2]);
            }
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $c1, $c2, $case): void {
                zz_check($case . ': setup took 2 (10)', abs($GLOBALS['zz_setup'] - 10) < 0.0001, (string)$GLOBALS['zz_setup']);
                zz_inv($case . ': served twin wasted, unserved twin restored', $ing, 11);
                if ($case === 'P7_86_twin') {
                    zz_check('P7 86: ok', ($resp['ok'] ?? false) === true, $resp);
                    $v1 = (int)zz_q('SELECT voided FROM booking_charges WHERE id = ?', [$c1])->fetchColumn();
                    $v2 = (int)zz_q('SELECT voided FROM booking_charges WHERE id = ?', [$c2])->fetchColumn();
                    zz_check("P7 86: the 86'd twin's OWN charge is voided, the served twin's stays", $v1 === 0 && $v2 === 1, "charge1 voided=$v1, charge2 voided=$v2");
                } else {
                    zz_check($case . ': one wastage row (the served twin)', (int)zz_q('SELECT COUNT(*) FROM stock_wastage WHERE ingredient_id = ?', [$ing])->fetchColumn() === 1);
                }
            };
            break;

        case 'P7_86_after_served_void': // twins; the served twin's charge voided on its own first, then the unserved twin 86'd
            zz_set_shop(false);
            $ing = zz_ing('P7s'); $item = zz_item('P7s', $ing);
            $b = zz_booking('P7s');
            $o = zz_order('P7s', 'room_service', 'paid', $b, 'in_progress');
            $l1 = zz_line($o, $item, 1, 'served', 0, 'ZZ8C twin 1');
            $c1 = zz_rs_charge($b, $o, $item, 1, $l1);
            $l2 = zz_line($o, $item, 1, 'pending', 0, 'ZZ8C twin 2');
            $c2 = zz_rs_charge($b, $o, $item, 1, $l2);
            $vr = voidBookingCharge($c1, 'ZZ8C void the served twin charge', $A);
            $GLOBALS['zz_vr'] = $vr;
            $GLOBALS['zz_mid'] = zz_qty($ing);
            $GLOBALS['zz_live_before'] = (float)zz_q('SELECT COALESCE(SUM(line_total), 0) FROM booking_charges WHERE booking_id = ? AND voided = 0', [$b])->fetchColumn();
            zz_endpoint(['action' => 'void_item', 'item_id' => (string)$l2]);
            $GLOBALS['zz_after'] = static function ($resp) use ($ing, $c2, $b, $l1): void {
                zz_check('P7 86 after a served void: charge 1 voided first', !empty($GLOBALS['zz_vr']['success']), $GLOBALS['zz_vr']);
                zz_check('P7 86 after a served void: served twin stays out (10)', abs($GLOBALS['zz_mid'] - 10) < 0.0001, (string)$GLOBALS['zz_mid']);
                zz_check('P7 86 after a served void: ok', ($resp['ok'] ?? false) === true, $resp);
                zz_check("P7 86 after a served void: the unserved twin's charge is voided", (int)zz_q('SELECT voided FROM booking_charges WHERE id = ?', [$c2])->fetchColumn() === 1);
                $live = (float)zz_q('SELECT COALESCE(SUM(line_total), 0) FROM booking_charges WHERE booking_id = ? AND voided = 0', [$b])->fetchColumn();
                zz_check('P7 86 after a served void: nothing left billed on the folio', $live < 0.005, "live before 86 {$GLOBALS['zz_live_before']}, after $live");
                zz_inv('P7 86 after a served void: unserved twin restored', $ing, 11);
                zz_check('P7 86 after a served void: served twin untouched', zz_line_row($l1)['kds_status'] === 'served', zz_line_row($l1));
            };
            break;

        default:
            throw new RuntimeException('unknown case ' . $GLOBALS['zz_case']);
        }
    } catch (Throwable $e) {
        $GLOBALS['zz_fatal'] = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        exit(0);
    }

    if (!empty($GLOBALS['zz_endpoint'])) {
        $_POST = $GLOBALS['zz_endpoint'] + ['csrf_token' => $_SESSION['csrf_token']];
        $_REQUEST = $_POST;
        $_GET = [];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        chdir($zzRoot . '/api');
        require $zzRoot . '/api/kds-action.php'; // prints JSON and exits; zz_finish() checks and rolls back
    }
    exit(0);
}

/* =====================================================================================================
 * PARENT
 * ===================================================================================================== */
require $zzRoot . '/config/database.php';
if (sha1(DB_NAME) !== ZZ_DB_SHA1) { fwrite(STDERR, "Refused: this suite runs on the Liwonde test database only.\n"); exit(9); }
$cases = ['P1_voided', 'P1_cancelled', 'P1_refunded_shop', 'P1_refunded_rest', 'P1_live_rs_untracked', 'P1_live_plain',
          'P2_recall', 'P3_unserved_tracked', 'P3_unserved_untracked', 'P3_served', 'P3_served_delete', 'P4_86_untracked', 'P4_close_untracked',
          'P4_close_nocharge', 'P5_refund_savepoint', 'P6_recall_refunded', 'P7_close_twins', 'P7_delete_twins', 'P7_86_twin', 'P7_86_after_served_void'];
$only = array_slice($argv, 1);
if ($only) {
    $cases = array_values(array_filter($cases, static function (string $c) use ($only): bool {
        foreach ($only as $o) { if (strpos($c, $o) === 0) { return true; } }
        return false;
    }));
}
$pass = 0; $fail = 0; $failedCases = [];
foreach ($cases as $case) {
    echo "== $case ==\n";
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($case) . ' 2>&1');
    $res = null;
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $res = json_decode($m[1], true); }
    if (!is_array($res)) {
        $fail++; $failedCases[$case] = true;
        echo "[FAIL] $case: no result - " . substr(trim($out), -500) . "\n";
        continue;
    }
    $checks = $res['checks'] ?? [];
    $checks[] = ['name' => "$case: rolled back, marker kept, nothing left behind", 'ok' => !empty($res['rolled_back']) && empty($res['marker_lost']) && $res['leak'] === false,
                 'detail' => json_encode(['rolled_back' => $res['rolled_back'] ?? null, 'marker_lost' => $res['marker_lost'] ?? null, 'leak' => $res['leak'] ?? null, 'left' => $res['left_behind'] ?? null, 'err' => $res['leak_check_error'] ?? null])];
    if (count($checks) < 2) {
        $checks[] = ['name' => "$case: produced checks", 'ok' => false, 'detail' => $res['out_tail'] ?? ''];
    }
    foreach ($checks as $c) {
        if ($c['ok']) { $pass++; echo "[PASS] {$c['name']}\n"; }
        else { $fail++; $failedCases[$case] = true; echo "[FAIL] {$c['name']}" . (($c['detail'] ?? '') !== '' ? ': ' . $c['detail'] : '') . "\n"; }
    }
    if (!empty($res['ddl_skipped'])) { echo "   (DDL skipped inside the test transaction: " . implode(' | ', array_unique($res['ddl_skipped'])) . ")\n"; }
}
echo "\n$pass passed, $fail failed" . ($failedCases ? ' (cases with a failure: ' . implode(', ', array_keys($failedCases)) . ')' : '') . "\n";
exit($fail === 0 ? 0 : 1);
