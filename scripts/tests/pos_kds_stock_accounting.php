<?php
/**
 * POS till -> KDS stations -> stock -> payments -> shift close, end to end, through the REAL endpoints.
 *
 *   php scripts/tests/pos_kds_stock_accounting.php --confirm-db            run everything, then clean up
 *   php scripts/tests/pos_kds_stock_accounting.php --confirm-db --cleanup-only   (after an interrupted run)
 *
 * LIWONDE ONLY (refuses any other database). The owner allowed committed test rows at Liwonde as long as
 * every one is removed afterwards: fixtures ('ZZ POSTEST ...' ingredients with two FIFO batches each,
 * recipes, a kitchen and a bar menu item, a ZZPT room + checked-in booking) are COMMITTED, then each
 * step runs admin/pos.php, api/kds-action.php or admin/room-service-dashboard.php in a child PHP
 * process (this file with --child) signed in as the first active admin, committing like the real till.
 * A shutdown handler deletes every row the run created (by marker / linkage, never by blind range),
 * receipt PDFs whose names carry the run's receipt numbers, finance_sequences rows (only when no
 * non-test payment / booking / credit note exists), resets every AUTO_INCREMENT to its start value and
 * prints "Liwonde clean: yes/no" against the start snapshot.
 *
 * Settings: two scenarios use an IN-MEMORY setting override inside one child process only: shop mode
 * (restaurant_system_enabled=0) for one refund, and - only when the run spans two trading days - all-day
 * station hours for set_float / close_shift (reported as a NOTE). S7b / S7c shape the station hours
 * around "now" IN MEMORY too (this process + each child); settings are NEVER written (live traffic reads
 * this database). The clean check also proves the station-hours settings rows are unchanged.
 * Emails: no customer email is ever set, the scheduler skips (HTTP_HOST localhost), and the child points
 * SMTP at 127.0.0.1:9 so nothing can be delivered.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const ZZ_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde database name (name kept out of the public repo)
const ZZ = 'ZZ POSTEST';
$__zzRoot = dirname(__DIR__, 2);

/* ======================================================================================
 * CHILD: run one page as the admin, committing (top level so the page's globals are global).
 * ====================================================================================== */
if (($argv[1] ?? '') === '--child') {
    $zzCfg = json_decode((string)@file_get_contents((string)($argv[2] ?? '')), true);
    if (!is_array($zzCfg) || empty($zzCfg['zz_child']) || empty($zzCfg['page'])) { fwrite(STDERR, "bad child cfg\n"); exit(2); }
    $_SERVER['HTTP_HOST'] = 'localhost'; // the web scheduler skips a local copy: no live emails / jobs
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/' . $zzCfg['page'];
    require $__zzRoot . '/config/database.php';
    if (sha1(DB_NAME) !== ZZ_DB_SHA1) { fwrite(STDERR, "child refused: not the Liwonde database\n"); exit(9); }
    foreach (($zzCfg['settings'] ?? []) as $zzK => $zzV) { $GLOBALS['_SITE_SETTINGS'][(string)$zzK] = (string)$zzV; } // in memory only
    require_once $__zzRoot . '/config/email.php';
    $GLOBALS['smtp_host'] = '127.0.0.1'; $GLOBALS['smtp_port'] = 9; $GLOBALS['smtp_secure'] = ''; $GLOBALS['smtp_timeout'] = 2;
    $GLOBALS['email_bcc_admin'] = false; $GLOBALS['rh_email_fast_fail'] = true;
    $zzAdmin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$zzAdmin) { fwrite(STDERR, "no admin\n"); exit(3); }
    $zzSessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_postest_sessions';
    if (!is_dir($zzSessDir)) { mkdir($zzSessDir, 0777, true); }
    session_save_path($zzSessDir);
    define('RH_SESSION_KEEP_SAVE_PATH', true);
    ini_set('session.use_cookies', '0');
    session_id('zzpostest' . bin2hex(random_bytes(6)));
    session_start();
    $_SESSION['admin_user_id'] = (int)$zzAdmin['id'];
    $_SESSION['admin_username'] = $zzAdmin['username'];
    $_SESSION['admin_role'] = $zzAdmin['role'];
    $_SESSION['admin_full_name'] = $zzAdmin['full_name'];
    $_SESSION['admin_user'] = ['id' => (int)$zzAdmin['id'], 'username' => $zzAdmin['username'], 'full_name' => $zzAdmin['full_name'], 'role' => $zzAdmin['role']];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    session_write_close();
    $GLOBALS['__zz_sess_file'] = $zzSessDir . DIRECTORY_SEPARATOR . 'sess_' . session_id();
    register_shutdown_function(static function (): void {
        $buf = '';
        while (ob_get_level() > 0) { $buf = ob_get_clean() . $buf; }
        $res = ['bytes' => strlen($buf)];
        $t = trim($buf);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) { $res['json'] = json_decode($t, true); }
        $err = $GLOBALS['error'] ?? null;
        $res['error'] = (is_string($err) && $err !== '') ? $err : null;
        $res['flash'] = $_SESSION['pos_flash'] ?? null;
        $res['rs_flash'] = $GLOBALS['flash'] ?? null;
        foreach ((array)($GLOBALS['zzCfg']['capture'] ?? []) as $zzCap) { $res['captured'][(string)$zzCap] = $GLOBALS[(string)$zzCap] ?? null; }
        if (preg_match_all('#<div class="alert[^"]*"[^>]*>.*?</div>#s', $buf, $m)) {
            $res['alerts'] = array_map(static fn($a) => trim((string)preg_replace('/\s+/', ' ', strip_tags($a))), array_slice($m[0], 0, 5));
        }
        $res['open_tx'] = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && $GLOBALS['pdo']->inTransaction();
        if (!isset($res['json']) && $res['bytes'] < 3000) { $res['raw'] = substr($buf, 0, 1500); }
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { $res['fatal'] = $last['message'] . ' @ ' . $last['file'] . ':' . $last['line']; }
        fwrite(STDOUT, "\n@@ZZRESULT@@" . json_encode($res) . "\n");
        @unlink((string)($GLOBALS['__zz_sess_file'] ?? ''));
    });
    $_POST = ($zzCfg['post'] ?? []) + ['csrf_token' => $_SESSION['csrf_token']];
    $_GET = $zzCfg['get'] ?? [];
    $_REQUEST = $_POST + $_GET;
    if (!empty($zzCfg['ajax'])) { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; }
    ob_start();
    chdir($__zzRoot . '/' . dirname((string)$zzCfg['page']));
    require $__zzRoot . '/' . $zzCfg['page'];
    exit(0);
}

/* ======================================================================================
 * ORCHESTRATOR
 * ====================================================================================== */
if (!in_array('--confirm-db', $argv, true)) {
    fwrite(STDERR, "Refusing: this test COMMITS rows (then removes them) at Liwonde. Re-run with --confirm-db.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $__zzRoot . '/config/database.php';
if (sha1(DB_NAME) !== ZZ_DB_SHA1) {
    fwrite(STDERR, 'Refusing: database is ' . DB_NAME . ", this test runs at Liwonde only.\n");
    exit(2);
}
require_once $__zzRoot . '/includes/station-hours.php';
require_once $__zzRoot . '/admin/includes/restaurant-payment-sync.php';
require_once $__zzRoot . '/admin/includes/pos-order-close.php';
try { $pdo->exec("SET SESSION information_schema_stats_expiry = 0"); } catch (Throwable $e) { /* MariaDB: live stats already */ }

$STATE_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_postest_state_' . substr(ZZ_DB_SHA1, 0, 12) . '.json';
$R = [];          // scenario => ['p' => n, 'f' => n]
$SC = 'SETUP';
$FAULTS = [];
$NOTES = [];
$SKIPS = [];

function zz_ok(string $label): void { global $R, $SC; $R[$SC]['p'] = ($R[$SC]['p'] ?? 0) + 1; echo "  [PASS] $label\n"; }
function zz_bad(string $label, string $detail = ''): void { global $R, $SC; $R[$SC]['f'] = ($R[$SC]['f'] ?? 0) + 1; $R[$SC]['p'] = $R[$SC]['p'] ?? 0; echo "  [FAIL] $label" . ($detail !== '' ? " :: $detail" : '') . "\n"; }
function zz_check(bool $c, string $label, $detail = ''): bool { if ($c) { zz_ok($label); } else { zz_bad($label, is_string($detail) ? $detail : (string)json_encode($detail)); } return $c; }
function zz_near($a, $b, float $tol = 0.0001): bool { return abs((float)$a - (float)$b) <= $tol; }
function zz_money($a, $b): bool { return abs((float)$a - (float)$b) <= (defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01); }
function zz_section(string $sc, string $title): void { global $SC, $R; $SC = $sc; $R[$sc] = $R[$sc] ?? ['p' => 0, 'f' => 0]; echo "\n--- $sc. $title ---\n"; }
function zz_in(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }
/** The remote DB sometimes drops the link ("server has gone away"): reconnect like config/database.php (same session settings). */
function zz_reconnect(): void
{
    global $pdo;
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    $mode = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    if (stripos($mode, 'STRICT_TRANS_TABLES') === false) { $pdo->exec("SET SESSION sql_mode = " . $pdo->quote(trim($mode . ',STRICT_TRANS_TABLES', ','))); }
    try { $pdo->exec("SET SESSION information_schema_stats_expiry = 0"); } catch (Throwable $e) { /* MariaDB */ }
    echo "  (reconnected to the database)\n";
}
/** Run a read (or an idempotent statement) and retry once on a dropped link; never inside a transaction. */
function zz_db(callable $fn)
{
    global $pdo;
    try { return $fn($pdo); } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'gone away') === false && stripos($e->getMessage(), 'Lost connection') === false) { throw $e; }
        if ($pdo->inTransaction()) { throw $e; }
        zz_reconnect();
        return $fn($pdo);
    }
}
function zz_q(string $sql, array $p = []): array { return zz_db(static function (PDO $db) use ($sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }); }
function zz_one(string $sql, array $p = []) { return zz_db(static function (PDO $db) use ($sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); }); }
function zz_has_table(string $t): bool { return (int)zz_one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]) > 0; }
function zz_has_col(string $t, string $c): bool { return (int)zz_one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$t, $c]) > 0; }

/** Run one page in a committing child process as the admin. */
function zz_call(string $page, array $post, bool $ajax = true, array $settings = [], array $capture = []): array
{
    global $__zzRoot;
    $cfgFile = tempnam(sys_get_temp_dir(), 'zzpt');
    file_put_contents($cfgFile, json_encode(['zz_child' => true, 'page' => $page, 'post' => $post, 'ajax' => $ajax, 'settings' => $settings, 'capture' => $capture]));
    $out = (string)shell_exec('cd ' . escapeshellarg($__zzRoot) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($cfgFile) . ' 2>&1');
    @unlink($cfgFile);
    if (preg_match('/@@ZZRESULT@@(.*)$/m', $out, $m)) {
        $r = json_decode($m[1], true);
        if (is_array($r)) { return $r; }
    }
    return ['error' => 'no child result', 'raw' => substr($out, -800)];
}
function zz_kds(string $action, array $p = []): array { return zz_call('api/kds-action.php', ['action' => $action] + $p); }
function zz_pos(array $p, array $settings = []): array { return zz_call('admin/pos.php', $p, true, $settings); }
function zz_jok(array $r): bool { return ($r['json']['ok'] ?? false) === true && empty($r['open_tx']) && empty($r['fatal']); }
function zz_err(array $r): string { return (string)($r['json']['error'] ?? $r['error'] ?? $r['fatal'] ?? ($r['alerts'][0] ?? '') ?: substr((string)($r['raw'] ?? json_encode($r)), 0, 400)); }

/* ---------------- stock model (expected FIFO state, compared against the DB after each step) ---------------- */
$M = ['batches' => [], 'qty' => []]; // batches: id => [ing, rem, exp, cost]; qty: ingKey => float
$ING = [];                           // key (A/B/C) => ingredient id
function zz_take(string $k, float $q): array
{
    global $M;
    $ids = array_keys(array_filter($M['batches'], static fn($b) => $b['ing'] === $k && $b['rem'] > 0.0001));
    usort($ids, static function ($x, $y) use ($M) {
        $ex = $M['batches'][$x]['exp']; $ey = $M['batches'][$y]['exp'];
        if (($ex === null) !== ($ey === null)) { return $ex === null ? 1 : -1; }
        return [$ex, $x] <=> [$ey, $y];
    });
    $left = round($q, 6);
    $taken = [];
    foreach ($ids as $id) {
        if ($left <= 0.0001) { break; }
        $t = min($M['batches'][$id]['rem'], $left);
        $M['batches'][$id]['rem'] = round($M['batches'][$id]['rem'] - $t, 6);
        $taken[] = [$id, $t];
        $left = round($left - $t, 6);
    }
    $M['qty'][$k] = round($M['qty'][$k] - $q, 6);
    return $taken;
}
function zz_give(string $k, array $taken): void
{
    global $M;
    foreach ($taken as [$id, $t]) { $M['batches'][$id]['rem'] = round($M['batches'][$id]['rem'] + $t, 6); $M['qty'][$k] = round($M['qty'][$k] + $t, 6); }
}
const REC_F = ['A' => 0.4, 'B' => 0.25];
const REC_D = ['C' => 0.3];
/** take a whole recipe x qty; returns [key => taken list] */
function zz_take_recipe(array $rec, float $qty): array { $o = []; foreach ($rec as $k => $per) { $o[$k] = zz_take($k, $per * $qty); } return $o; }
function zz_give_recipe(array $taken): void { foreach ($taken as $k => $t) { zz_give($k, $t); } }
function zz_stock_matches(string $label): bool
{
    global $M, $ING;
    $diff = [];
    foreach ($ING as $k => $iid) {
        $q = (float)zz_one("SELECT current_quantity FROM stock_ingredients WHERE id = ?", [$iid]);
        if (!zz_near($q, $M['qty'][$k], 0.00001)) { $diff[] = "$k qty db=$q model={$M['qty'][$k]}"; }
    }
    foreach ($M['batches'] as $bid => $b) {
        $row = zz_q("SELECT quantity_remaining, status FROM stock_batches WHERE id = ?", [$bid])[0] ?? null;
        $st = $b['rem'] > 0.0001 ? 'active' : 'depleted';
        if (!$row || !zz_near($row['quantity_remaining'], $b['rem'], 0.00001) || $row['status'] !== $st) {
            $diff[] = "batch#$bid db=" . json_encode($row) . " model={$b['rem']}/$st";
        }
    }
    $extra = (int)zz_one("SELECT COUNT(*) FROM stock_batches WHERE ingredient_id IN (" . zz_in(array_values($ING)) . ") AND id NOT IN (" . zz_in(array_keys($M['batches'])) . ")", array_merge(array_values($ING), array_keys($M['batches'])));
    if ($extra > 0) { $diff[] = "$extra unexpected new batch(es)"; }
    return zz_check($diff === [], $label, implode('; ', $diff));
}
/** usage moves of a line: ingredient key => [n, qty, cost] */
function zz_moves(string $src, int $sid): array
{
    global $ING;
    $flip = array_flip($ING);
    $o = [];
    foreach (zz_q("SELECT ingredient_id, COUNT(*) n, SUM(quantity_change) q, MAX(cost_at_time) c FROM stock_adjustments WHERE source_type = ? AND source_id = ? AND quantity_change < 0 GROUP BY ingredient_id", [$src, $sid]) as $r) {
        $o[$flip[(int)$r['ingredient_id']] ?? ('#' . $r['ingredient_id'])] = ['n' => (int)$r['n'], 'q' => round((float)$r['q'], 6), 'c' => (float)$r['c']];
    }
    ksort($o);
    return $o;
}
function zz_moves_ok(array $moves, array $rec, float $qty): bool
{
    if (count($moves) !== count($rec)) { return false; }
    foreach ($rec as $k => $per) {
        if (!isset($moves[$k]) || $moves[$k]['n'] !== 1 || !zz_near($moves[$k]['q'], -$per * $qty, 0.00001)) { return false; }
    }
    return true;
}
function zz_restores(string $ofType, int $sid): int { return (int)zz_one("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'void_restore' AND restore_of_type = ? AND source_id = ?", [$ofType, $sid]); }
function zz_line(int $id): array { return zz_q("SELECT id, order_id, item_name, kds_status, stock_deducted, station, quantity FROM stock_order_items WHERE id = ?", [$id])[0] ?? []; }
function zz_order(int $id): array { return zz_q("SELECT * FROM stock_orders WHERE id = ?", [$id])[0] ?? []; }
function zz_lines(int $orderId): array { return array_map('intval', array_column(zz_q("SELECT id FROM stock_order_items WHERE order_id = ? ORDER BY id", [$orderId]), 'id')); }
function zz_vat_ok(array $p, float $rate): bool
{
    $g = (float)$p['total_amount'];
    $net = $rate > 0 ? round($g / (1 + $rate / 100), 2) : $g;
    return zz_money((float)$p['payment_amount'] + (float)$p['vat_amount'], $g) && zz_money($p['payment_amount'], $net) && zz_near($p['vat_rate'], $rate, 0.001);
}

/* ---------------- snapshot / cleanup / clean check ---------------- */
/** MySQL 8 caches information_schema AUTO_INCREMENT for a day: read live values (re-set before every read pass). */
function zz_fresh_stats(): void { global $pdo; try { $pdo->exec("SET SESSION information_schema_stats_expiry = 0"); } catch (Throwable $e) { /* MariaDB: live already */ } }
/** The id the table will hand out next: AUTO_INCREMENT as read (NULL / stale = 1 or MAX) never below MAX(id)+1. */
function zz_next_id($ai, int $max): int { return max((int)($ai ?? 1), $max + 1); }
function zz_max_id(string $t, string $c): int { return (int)zz_one("SELECT COALESCE(MAX(`" . str_replace('`', '', $c) . "`), 0) FROM `" . str_replace('`', '', $t) . "`"); }
function zz_snapshot(): array
{
    global $__zzRoot;
    zz_fresh_stats();
    $snap = ['ai' => [], 'count' => [], 'files' => [], 'aicol' => []];
    foreach (zz_q("SELECT TABLE_NAME AS t, AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $r) {
        $t = (string)$r['t'];
        $snap['count'][$t] = (int)zz_one("SELECT COUNT(*) FROM `" . str_replace('`', '', $t) . "`");
        if ($r['ai'] !== null) { $snap['ai'][$t] = (int)$r['ai']; }
    }
    foreach (zz_q("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%auto_increment%'") as $r) { $snap['aicol'][(string)$r['t']] = (string)$r['c']; }
    $snap['files'] = zz_files();
    $snap['station_hours'] = zz_station_hours_rows();
    return $snap;
}
function zz_files(): array
{
    global $__zzRoot;
    $out = [];
    foreach (['invoices', 'quotations'] as $d) {
        $base = $__zzRoot . '/' . $d;
        if (!is_dir($base)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) { $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($__zzRoot) + 1)); } }
    }
    sort($out);
    return $out;
}

/** The station opening/closing settings rows (value + updated_at): must be identical after the run. */
function zz_station_hours_rows(): array
{
    $keys = [];
    foreach (array_keys(rh_station_definitions()) as $st) { $keys[] = rh_station_setting_key($st, 'opens_at'); $keys[] = rh_station_setting_key($st, 'closes_at'); }
    return zz_q("SELECT setting_key, setting_value, updated_at FROM site_settings WHERE setting_key IN (" . zz_in($keys) . ") ORDER BY setting_key", $keys);
}
/** Delete every row this run created (by marker / linkage) + its PDFs + sequences; reset AUTO_INCREMENTs. */
function zz_cleanup(array $state): array
{
    global $pdo, $__zzRoot;
    $log = [];
    try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e) { /* dropped link */ }
    try { $pdo->query("SELECT 1"); } catch (Throwable $e) { zz_reconnect(); }
    $runStart = (string)$state['run_start'];
    $adminId = (int)$state['admin_id'];
    $snap = $state['snap'];
    $col = static fn(string $sql, array $p = []): array => array_map('intval', array_column(zz_q($sql, $p), 'id'));
    $del = static function (string $table, string $where, array $p) use (&$log, $pdo): void {
        if (!zz_has_table($table)) { return; }
        try {
            $s = $pdo->prepare("DELETE FROM `$table` WHERE $where");
            $s->execute($p);
            if ($s->rowCount() > 0) { $log[] = "$table -" . $s->rowCount(); }
        } catch (Throwable $e) { $log[] = "$table ERR " . $e->getMessage(); }
    };
    $ing = $col("SELECT id FROM stock_ingredients WHERE name LIKE ?", [ZZ . '%']);
    $mi = $col("SELECT id FROM menu_items WHERE item_name LIKE ?", [ZZ . '%']);
    $bk = $col("SELECT id FROM bookings WHERE guest_name LIKE ?", [ZZ . '%']);
    $rooms = $col("SELECT id FROM individual_rooms WHERE room_number LIKE 'ZZPT-%'");
    $op = [ZZ . '%', $runStart];
    $orderSql = "SELECT id FROM stock_orders WHERE customer_name LIKE ? OR (customer_name = 'Smoke Test' AND created_at >= ?)";
    if ($bk) { $orderSql .= " OR booking_id IN (" . zz_in($bk) . ")"; $op = array_merge($op, $bk); }
    $orders = $col($orderSql, $op);
    $pays = $orders ? $col("SELECT id FROM payments WHERE booking_type = 'restaurant' AND booking_id IN (" . zz_in($orders) . ")", $orders) : [];
    $receiptNos = $pays ? array_values(array_filter(array_column(zz_q("SELECT receipt_number FROM payments WHERE id IN (" . zz_in($pays) . ")", $pays), 'receipt_number'))) : [];
    $refs = $orders ? array_column(zz_q("SELECT reference FROM stock_orders WHERE id IN (" . zz_in($orders) . ")", $orders), 'reference') : [];

    if ($pays) {
        $del('receipt_events', "payment_id IN (" . zz_in($pays) . ")", $pays);
        if (zz_has_col('payment_audit_log', 'payment_id')) { $del('payment_audit_log', "payment_id IN (" . zz_in($pays) . ")", $pays); }
        $del('payments', "original_payment_id IN (" . zz_in($pays) . ")", $pays);
        $del('payments', "id IN (" . zz_in($pays) . ")", $pays);
    }
    if ($ing) {
        $adj = $col("SELECT id FROM stock_adjustments WHERE ingredient_id IN (" . zz_in($ing) . ")", $ing);
        if ($adj) { $del('stock_batch_deductions', "adjustment_id IN (" . zz_in($adj) . ")", $adj); }
        $del('stock_batch_deductions', "batch_id IN (SELECT id FROM stock_batches WHERE ingredient_id IN (" . zz_in($ing) . "))", $ing);
        $del('stock_adjustments', "ingredient_id IN (" . zz_in($ing) . ")", $ing);
        $del('stock_wastage', "ingredient_id IN (" . zz_in($ing) . ")", $ing);
        $del('stock_batches', "ingredient_id IN (" . zz_in($ing) . ")", $ing);
        $del('stock_recipe_ingredients', "ingredient_id IN (" . zz_in($ing) . ")", $ing);
    }
    if ($mi) {
        $del('stock_recipe_ingredients', "recipe_id IN (SELECT id FROM stock_recipes WHERE menu_item_id IN (" . zz_in($mi) . "))", $mi);
        $del('stock_recipes', "menu_item_id IN (" . zz_in($mi) . ")", $mi);
    }
    if ($orders) {
        foreach (['stock_order_splits', 'stock_kds_events', 'stock_order_audit', 'station_messages', 'pos_ready_notifications', 'print_jobs', 'stock_order_deliveries'] as $t) {
            if (zz_has_col($t, 'order_id')) { $del($t, "order_id IN (" . zz_in($orders) . ")", $orders); }
        }
        if (zz_has_col('idempotency_keys', 'entity_id')) { $del('idempotency_keys', "entity_type = 'stock_order' AND entity_id IN (" . zz_in($orders) . ")", $orders); }
        $del('booking_charges', "stock_order_id IN (" . zz_in($orders) . ")", $orders);
        $del('stock_order_items', "order_id IN (" . zz_in($orders) . ")", $orders);
        $del('stock_orders', "id IN (" . zz_in($orders) . ")", $orders);
    }
    if ($bk) {
        foreach (['booking_charges', 'booking_timeline_logs', 'booking_audit_log', 'booking_financial_audit', 'booking_status_log', 'booking_notes', 'booking_rooms', 'booking_date_adjustments', 'guest_communication_log', 'cancellation_log'] as $t) {
            if (zz_has_col($t, 'booking_id')) { $del($t, "booking_id IN (" . zz_in($bk) . ")", $bk); }
        }
        $del('bookings', "id IN (" . zz_in($bk) . ")", $bk);
    }
    if ($rooms) { $del('individual_rooms', "id IN (" . zz_in($rooms) . ")", $rooms); }
    if ($mi) { $del('menu_items', "id IN (" . zz_in($mi) . ")", $mi); }
    if ($ing) { $del('stock_ingredients', "id IN (" . zz_in($ing) . ")", $ing); }
    // Shift rows and order-less audit rows written by THIS run's admin after the run started (new ids only).
    $ai = $snap['ai'];
    $del('stock_shift_closes', "id >= ? AND user_id = ? AND created_at >= ? AND notes LIKE ?", [(int)($ai['stock_shift_closes'] ?? 1), $adminId, $runStart, '%' . ZZ . '%']);
    $del('stock_shift_opens', "id >= ? AND user_id = ? AND notes LIKE ?", [(int)($ai['stock_shift_opens'] ?? 1), $adminId, '%' . ZZ . '%']);
    // Till audit rows with no order (float_set / shift_closed, order_id NULL since migration 079) by the test admin during the run.
    if (zz_has_table('stock_order_audit')) {
        $del('stock_order_audit', "id >= ? AND order_id IS NULL AND actor_id = ? AND created_at >= ? AND event IN ('float_set','shift_closed','accountant_shift_closed')", [(int)($ai['stock_order_audit'] ?? 1), $adminId, $runStart]);
    }
    // The accountant-close event (no order / marker in it): this admin, after run start, new ids only.
    $del('system_event_log', "id >= ? AND created_at >= ? AND user_id = ? AND message = 'POS shifts manually closed from accounting'", [(int)($ai['system_event_log'] ?? 1), $runStart, $adminId]);
    // Activity / event log rows of the run: new ids, after run start, by the test admin, and naming the run's orders or till actions.
    $likeRefs = $refs ? implode(' OR ', array_fill(0, count($refs), 'details LIKE ?')) : '0';
    $del('admin_activity_log', "id >= ? AND created_at >= ? AND user_id = ? AND (details LIKE ? OR $likeRefs)",
        array_merge([(int)($ai['admin_activity_log'] ?? 1), $runStart, $adminId, '%' . ZZ . '%'], array_map(static fn($r) => '%' . $r . '%', $refs)));
    // Event-log rows that name one of the run's orders / ingredients / references.
    $evNeedles = array_merge(['%' . ZZ . '%'], array_map(static fn($r) => '%' . $r . '%', $refs));
    foreach ($orders as $oid) { $evNeedles[] = '%"order_id":' . $oid . ',%'; $evNeedles[] = '%"order_id":' . $oid . '}%'; $evNeedles[] = '%order #' . $oid . ')%'; $evNeedles[] = '%order #' . $oid . ' %'; }
    foreach ($ing as $iid) { $evNeedles[] = '%"ingredient_id":' . $iid . ',%'; $evNeedles[] = '%"ingredient_id":' . $iid . '}%'; }
    foreach ($evNeedles as $nd) {
        $del('system_event_log', "id >= ? AND created_at >= ? AND source IN ('stock','kds','room_service','pos') AND (message LIKE ? OR context_json LIKE ?)", [(int)($ai['system_event_log'] ?? 1), $runStart, $nd, $nd]);
    }
    // "Receipt generated" events of the run's own receipts (context names the receipt number).
    $runRc = array_values(array_unique(array_filter(array_merge($receiptNos, $state['receipt_numbers'] ?? []))));
    foreach ($runRc as $rc) {
        $del('system_event_log', "id >= ? AND created_at >= ? AND source = 'receipts' AND context_json LIKE ?", [(int)($ai['system_event_log'] ?? 1), $runStart, '%"' . $rc . '"%']);
    }
    // finance_sequences: only when nothing real could have drawn a number (no non-test payments / bookings / credit notes).
    $realP = (int)zz_one("SELECT COUNT(*) FROM payments");
    $realB = (int)zz_one("SELECT COUNT(*) FROM bookings");
    $realC = zz_has_table('credit_notes') ? (int)zz_one("SELECT COUNT(*) FROM credit_notes") : 0;
    if ($realP === 0 && $realB === 0 && $realC === 0) {
        $del('finance_sequences', "id >= ?", [(int)($ai['finance_sequences'] ?? 1)]);
    } else {
        $log[] = "finance_sequences KEPT: non-test rows exist (payments=$realP bookings=$realB credit_notes=$realC) - owner decision";
    }
    // PDFs: only new files whose names carry one of the run's receipt numbers / order references.
    $before = array_flip($snap['files'] ?? []);
    $needles = array_merge($receiptNos, $refs);
    $state['receipt_numbers'] = array_values(array_unique(array_merge($state['receipt_numbers'] ?? [], $receiptNos)));
    $needles = array_merge($needles, $state['receipt_numbers']);
    foreach (zz_files() as $f) {
        if (isset($before[$f])) { continue; }
        $hit = false;
        foreach ($needles as $n) { if ($n !== '' && stripos(basename($f), (string)$n) !== false) { $hit = true; break; } }
        if ($hit) { @unlink($__zzRoot . '/' . $f); $log[] = "deleted $f"; } else { $log[] = "NEW FILE NOT MINE (kept): $f"; }
    }
    // AUTO_INCREMENT back to the start value (or MAX+1 if real rows arrived meanwhile). Compared as "next id"
    // (zz_next_id), so a NULL / not-yet-opened reading (MAX instead of MAX+1) is neither skipped nor a false change.
    zz_fresh_stats();
    foreach (zz_q("SELECT TABLE_NAME AS t, AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL") as $r) {
        $t = (string)$r['t'];
        if (!isset($snap['aicol'][$t])) { continue; }
        $max = zz_max_id($t, $snap['aicol'][$t]);
        $target = zz_next_id($ai[$t] ?? null, $max);
        if (zz_next_id($r['ai'], $max) === $target) { continue; }
        try { $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . $target); $log[] = "$t AI {$r['ai']}->$target"; } catch (Throwable $e) { $log[] = "$t AI ERR " . $e->getMessage(); }
    }
    $sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_postest_sessions';
    foreach (glob($sessDir . DIRECTORY_SEPARATOR . 'sess_zzpostest*') ?: [] as $sf) { @unlink($sf); }
    if (is_dir($sessDir) && !(glob($sessDir . DIRECTORY_SEPARATOR . '*') ?: [])) { @rmdir($sessDir); }
    return $log;
}
/** Compare the DB + files with the start snapshot. */
function zz_clean_check(array $snap): array
{
    $diff = [];
    $now = [];
    zz_fresh_stats();
    foreach (zz_q("SELECT TABLE_NAME AS t, AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $r) {
        $t = (string)$r['t'];
        $cnt = (int)zz_one("SELECT COUNT(*) FROM `$t`");
        if (($snap['count'][$t] ?? null) !== $cnt) { $diff[] = "$t rows {$snap['count'][$t]}->$cnt"; }
        if (isset($snap['aicol'][$t])) {
            $max = zz_max_id($t, $snap['aicol'][$t]);
            $was = zz_next_id($snap['ai'][$t] ?? null, $max);
            $is = zz_next_id($r['ai'], $max);
            if ($was !== $is) { $diff[] = "$t next id $was->$is"; }
        }
        $now[$t] = $cnt;
    }
    if (isset($snap['station_hours']) && zz_station_hours_rows() !== $snap['station_hours']) { $diff[] = 'station hours settings changed: ' . json_encode(zz_station_hours_rows()); }
    $newFiles = array_values(array_diff(zz_files(), $snap['files'] ?? []));
    foreach ($newFiles as $f) { $diff[] = "new file $f"; }
    return ['diff' => $diff, 'counts' => $now, 'new_files' => $newFiles];
}

/* ---------------- cleanup-only mode ---------------- */
if (in_array('--cleanup-only', $argv, true)) {
    $state = is_file($STATE_FILE) ? json_decode((string)file_get_contents($STATE_FILE), true) : null;
    if (!is_array($state)) { fwrite(STDERR, "No state file ($STATE_FILE): nothing recorded to clean.\n"); exit(1); }
    foreach (zz_cleanup($state) as $l) { echo "  cleanup: $l\n"; }
    $cc = zz_clean_check($state['snap']);
    echo "\nLiwonde clean: " . ($cc['diff'] ? 'no' : 'yes') . ($cc['diff'] ? "\n  " . implode("\n  ", $cc['diff']) : '') . "\n";
    if (!$cc['diff']) { @unlink($STATE_FILE); }
    exit($cc['diff'] ? 1 : 0);
}

/* ---------------- start guards ---------------- */
echo "\n=== POS -> KDS -> stock -> accounting, real endpoints (" . date('Y-m-d H:i:s') . ", " . DB_NAME . ") ===\n";
if (is_file($STATE_FILE)) { fwrite(STDERR, "A previous run left $STATE_FILE - run with --cleanup-only first.\n"); exit(2); }
$startCounts = [];
foreach (['stock_orders', 'payments', 'bookings', 'stock_ingredients', 'stock_recipes', 'stock_batches', 'finance_sequences', 'credit_notes', 'booking_charges', 'stock_shift_closes'] as $t) {
    $startCounts[$t] = zz_has_table($t) ? (int)zz_one("SELECT COUNT(*) FROM `$t`") : 0;
}
$busy = array_filter($startCounts, static fn($n) => $n > 0);
if ($busy) {
    fwrite(STDERR, 'Refusing: Liwonde is not in the agreed empty state (' . json_encode($busy) . ") - real data may exist. Nothing changed.\n");
    exit(2);
}
$admin = zz_q("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")[0] ?? null;
if (!$admin) { fwrite(STDERR, "No active admin.\n"); exit(2); }
$ADMIN = (int)$admin['id'];
$runStart = (string)zz_one("SELECT NOW()");
$snap = zz_snapshot();
$state = ['run_start' => $runStart, 'admin_id' => $ADMIN, 'snap' => $snap, 'receipt_numbers' => []];
file_put_contents($STATE_FILE, json_encode($state));

register_shutdown_function(static function () use (&$state, $STATE_FILE) {
    global $R, $FAULTS, $NOTES, $SKIPS, $SC;
    $SC = 'CLEANUP';
    echo "\n--- Cleanup ---\n";
    try {
        foreach (zz_cleanup($state) as $l) { echo "  $l\n"; }
        $cc = zz_clean_check($state['snap']);
    } catch (Throwable $e) {
        $cc = ['diff' => ['cleanup error: ' . $e->getMessage()], 'counts' => [], 'new_files' => []];
    }
    $c = $cc['counts'];
    echo "\n=== Per scenario ===\n";
    $tp = 0; $tf = 0;
    foreach ($R as $k => $v) { $tp += $v['p']; $tf += $v['f']; printf("  %-8s %3d passed, %d failed\n", $k, $v['p'], $v['f']); }
    foreach ($SKIPS as $s) { echo "  SKIP: $s\n"; }
    foreach ($NOTES as $s) { echo "  NOTE: $s\n"; }
    foreach ($FAULTS as $s) { echo "  FAULT: $s\n"; }
    $clean = !$cc['diff'];
    echo "\nLiwonde clean: " . ($clean ? 'yes' : 'no') . ' (bookings=' . ($c['bookings'] ?? '?') . ' payments=' . ($c['payments'] ?? '?') . ' stock_orders=' . ($c['stock_orders'] ?? '?')
        . ' stock_ingredients=' . ($c['stock_ingredients'] ?? '?') . ' stock_recipes=' . ($c['stock_recipes'] ?? '?') . ' stock_batches=' . ($c['stock_batches'] ?? '?')
        . ' finance_sequences=' . ($c['finance_sequences'] ?? '?') . ' new_pdfs=' . count($cc['new_files']) . ")\n";
    if (!$clean) { echo "  differences vs start: " . implode('; ', $cc['diff']) . "\n"; } else { @unlink($STATE_FILE); }
    echo "\n=== Result: $tp passed, $tf failed ===\n";
    exit(($tf > 0 || !$clean) ? 1 : 0);
});

/* ======================================================================================
 * FIXTURES (committed, marker 'ZZ POSTEST')
 * ====================================================================================== */
zz_section('FIX', 'Fixtures');
$catF = zz_q("SELECT id, slug FROM menu_categories WHERE default_station = 'kitchen' ORDER BY (slug = 'meat-corner') DESC, id LIMIT 1")[0] ?? null;
$catD = zz_q("SELECT id, slug FROM menu_categories WHERE default_station = 'bar' ORDER BY (slug = 'non-alcoholic') DESC, id LIMIT 1")[0] ?? null;
if (!$catF || !$catD) { zz_bad('menu categories for kitchen and bar exist'); exit; }
$VAT = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
$FP = 11750.0; $DP = 2350.0;
$d10 = date('Y-m-d', strtotime('+10 days')); $d30 = date('Y-m-d', strtotime('+30 days')); $d60 = date('Y-m-d', strtotime('+60 days'));
$mkIng = static function (string $name, string $unit, float $avgCost, array $batches) use ($pdo, $ADMIN): int {
    global $M;
    $total = array_sum(array_column($batches, 0));
    $pdo->prepare("INSERT INTO stock_ingredients (name, category, unit, current_quantity, cost_per_unit, yield_percent) VALUES (?, 'ZZPOSTEST', ?, ?, ?, 100)")->execute([$name, $unit, $total, $avgCost]);
    $id = (int)$pdo->lastInsertId();
    foreach ($batches as $i => [$q, $c, $exp]) {
        $pdo->prepare("INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit, received_date, expiry_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'active', 'ZZ POSTEST fixture', ?)")
            ->execute([$id, 'ZZPT-' . $id . '-' . ($i + 1), $q, $q, $c, $exp, $ADMIN]);
        $M['batches'][(int)$pdo->lastInsertId()] = ['ing' => null, 'rem' => $q, 'exp' => $exp, 'cost' => $c];
    }
    return $id;
};
$ING['A'] = $mkIng(ZZ . ' Beef', 'kg', 1172.7273, [[1.0, 900.0, $d10], [10.0, 1200.0, $d30]]);
$ING['B'] = $mkIng(ZZ . ' Rice', 'kg', 500.0, [[20.0, 500.0, $d60]]);
$ING['C'] = $mkIng(ZZ . ' Juice', 'litre', 383.3333, [[2.0, 300.0, $d10], [10.0, 400.0, $d30]]);
$byIng = [];
foreach (zz_q("SELECT id, ingredient_id FROM stock_batches WHERE ingredient_id IN (?, ?, ?)", array_values($ING)) as $b) { $byIng[(int)$b['id']] = array_search((int)$b['ingredient_id'], $ING, true); }
foreach ($M['batches'] as $bid => &$b) { $b['ing'] = $byIng[$bid]; } unset($b);
$M['qty'] = ['A' => 11.0, 'B' => 20.0, 'C' => 12.0];
$batchIds = ['A' => [], 'C' => []];
foreach ($M['batches'] as $bid => $b) { if (isset($batchIds[$b['ing']])) { $batchIds[$b['ing']][] = $bid; } }
$mkItem = static function (array $cat, string $name, float $price, array $rec) use ($pdo, $ADMIN, &$ING): array {
    $pdo->prepare("INSERT INTO menu_items (category_id, item_name, description, price, category, is_available, show_pos, show_room_service, display_order) VALUES (?, ?, 'ZZ POSTEST fixture', ?, ?, 1, 1, 1, 9999)")
        ->execute([(int)$cat['id'], $name, $price, (string)$cat['slug']]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes, created_by) VALUES (?, ?, 1, 'ZZ POSTEST', ?)")->execute([$id, (string)$cat['slug'], $ADMIN]);
    $rid = (int)$pdo->lastInsertId();
    foreach ($rec as $k => $per) {
        $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, ?, 100)")->execute([$rid, $ING[$k], $per]);
    }
    return ['id' => $id, 'type' => (string)$cat['slug']];
};
$F = $mkItem($catF, ZZ . ' Beef Plate', $FP, REC_F);
$D = $mkItem($catD, ZZ . ' Juice', $DP, REC_D);
$roomType = (int)zz_one("SELECT id FROM rooms ORDER BY id LIMIT 1");
$pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active, notes) VALUES (?, 'ZZPT-01', 'ZZ POSTEST Room', 'occupied', 1, 'ZZ POSTEST fixture')")->execute([$roomType]);
$ROOM = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, number_of_guests, check_in_date, check_out_date, number_of_nights, total_amount, status, payment_status, special_requests)
               VALUES (?, ?, ?, ?, '', '', 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 1, 0, 'checked-in', 'unpaid', 'ZZ POSTEST fixture')")
    ->execute(['ZZPT' . strtoupper(bin2hex(random_bytes(3))), $roomType, $ROOM, ZZ . ' Guest']);
$BOOKING = (int)$pdo->lastInsertId();
zz_check($F['id'] > 0 && $D['id'] > 0 && $BOOKING > 0, "Fixtures committed: food #{$F['id']} ({$F['type']}), drink #{$D['id']} ({$D['type']}), room ZZPT-01, booking #$BOOKING, VAT $VAT%");
zz_stock_matches('Fixture stock = model (A 1.0@900 + 10@1200, B 20@500, C 2@300 + 10@400)');

/* ---------------- smoke test once, while a recipe-backed item exists ---------------- */
zz_section('SMOKE', 'scripts/smoke_test_pos_kds.php (sections 1-8 need a recipe item)');
$smokeOut = (string)shell_exec('cd ' . escapeshellarg($__zzRoot) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/smoke_test_pos_kds.php 2>&1');
$smokeRes = preg_match('/=== Result: (\d+) passed, (\d+) failed/', $smokeOut, $sm) ? [(int)$sm[1], (int)$sm[2]] : null;
$smokeSkipped1to8 = strpos($smokeOut, 'Sections 1-8 (stock lifecycle)') !== false;
$smokeFails = [];
foreach (explode("\n", $smokeOut) as $ln) { if (strpos($ln, '[FAIL]') !== false || strpos($ln, '[SKIP]') !== false || stripos($ln, 'warning') !== false) { $smokeFails[] = trim($ln); } }
$NOTES[] = 'smoke_test_pos_kds.php: ' . ($smokeRes ? "{$smokeRes[0]} passed, {$smokeRes[1]} failed" : 'no result line') . ($smokeSkipped1to8 ? ' (sections 1-8 SKIPPED)' : ' (sections 1-8 ran)') . ($smokeFails ? ' | ' . implode(' | ', array_slice($smokeFails, 0, 6)) : '');
zz_check($smokeRes !== null && $smokeRes[1] === 0 && !$smokeSkipped1to8, 'Smoke test passed with sections 1-8 running', implode(' | ', $smokeFails) ?: substr($smokeOut, -400));
if (!zz_stock_matches('Fixture stock back to its fixture levels after the smoke test')) { echo "Stock not restored by the smoke test - stopping before the scenarios.\n"; exit; }
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_adjustments WHERE ingredient_id IN (?, ?, ?)", array_values($ING)) === 0, 'Smoke test left no stock movements behind');

$cart = static fn(array $items): array => ['item_id' => array_column($items, 0), 'item_type' => array_column($items, 1), 'item_qty' => array_column($items, 2)];
$fl = static fn(float $q) => [$F['id'], $F['type'], (string)$q];
$dl = static fn(float $q) => [$D['id'], $D['type'], (string)$q];

/* ======================================================================================
 * S7e. The cashier skips two trading days (X-1 and X); the accountant catches up in date order,
 *      both closes recorded now: each sale counted once, in its own day's close (owner 2026-11-10).
 *      Runs first among the close scenarios (no close exists yet). Hours IN MEMORY only.
 * ====================================================================================== */
zz_section('S7e', 'Cashier skips X-1 and X; accountant closes X-1 then X: each sale once');
$zzHoursOn = static function (array $h): void { foreach ($h as $k => $v) { $GLOBALS['_SITE_SETTINGS'][$k] = (string)$v; } };
$zzHoursOff = static function (array $h): void { foreach (array_keys($h) as $k) { unset($GLOBALS['_SITE_SETTINGS'][$k]); } };
$zzHoursFor = static function (string $open, string $close): array {
    $h = [];
    foreach (array_keys(rh_station_definitions()) as $st) { $h[rh_station_setting_key($st, 'opens_at')] = $open; $h[rh_station_setting_key($st, 'closes_at')] = $close; }
    return $h;
};
$zzWaitFor = static function (DateTimeImmutable $at, DateTimeZone $tz): int {
    $waited = 0;
    while (($waited < 200) && ((string)zz_one("SELECT NOW()") < $at->modify('+2 seconds')->format('Y-m-d H:i:s') || new DateTimeImmutable('now', $tz) < $at->modify('+2 seconds'))) { sleep(5); $waited += 5; }
    return $waited;
};
require_once $__zzRoot . '/admin/includes/pos-shift-totals.php';
$eTz = rh_site_timezone();
$eNow = new DateTimeImmutable((string)zz_one("SELECT NOW()"), $eTz);
$eOpenAt = $eNow->setTime((int)$eNow->format('H'), (int)$eNow->format('i'))->modify('+2 minutes');
$eHours = $zzHoursFor($eOpenAt->format('H:i'), $eOpenAt->modify('-60 minutes')->format('H:i'));
$zzHoursOn($eHours);
$eX1 = rh_station_trading_business_date();
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_shift_closes WHERE user_id = ?", [$ADMIN]) === 0, "No close yet; day X-1 = $eX1 (next opening " . $eOpenAt->format('H:i') . ')');
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' E1 day X-1', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]), $eHours);
$E1 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' E1 day X-1']);
zz_check($E1 > 0 && (zz_order($E1)['status'] ?? '') === 'paid', 'Sale E1 on day X-1, no cashier close', zz_err($r));
if ($E1 > 0) { zz_take_recipe(REC_D, 1); }
$waited = $zzWaitFor($eOpenAt, $eTz);
$eX = rh_station_trading_business_date();
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' E2 day X', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]), $eHours);
$E2 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' E2 day X']);
zz_check($eX !== $eX1 && $E2 > 0 && (zz_order($E2)['status'] ?? '') === 'paid', "Opening passed (waited {$waited}s): sale E2 on day X = $eX, still no cashier close", zz_err($r));
if ($E2 > 0) { zz_take_recipe(REC_D, 1); }
foreach ([$E1, $E2] as $eo) {
    $state['receipt_numbers'] = array_values(array_unique(array_merge($state['receipt_numbers'], array_filter(array_column(zz_q("SELECT receipt_number FROM payments WHERE booking_type = 'restaurant' AND booking_id = ?", [$eo]), 'receipt_number')))));
}
$eAcct = static function (string $date) use ($ADMIN, $DP, $eHours): array {
    return zz_call('admin/pos-accounting.php', ['business_date' => $date, 'action' => 'close_selected', 'user_ids' => [(string)$ADMIN], 'bulk_note' => 'ZZ POSTEST S7e',
        'declared_cash' => [(string)$ADMIN => (string)$DP], 'declared_mobile' => [(string)$ADMIN => '0'], 'declared_card' => [(string)$ADMIN => '0']], false, $eHours, ['message', 'error']);
};
$r = $eAcct($eX1);
$c1 = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND shift_date = ? AND notes LIKE ?", [$ADMIN, $eX1, '%S7e%']);
zz_check(count($c1) === 1 && (int)$c1[0]['orders_count'] === 1 && zz_money($c1[0]['expected_cash'], $DP) && zz_money($c1[0]['variance_cash'], 0), "Accountant close of X-1 ($eX1): E1 only, balances", $c1 ?: ($r['captured'] ?? zz_err($r)));
$r = $eAcct($eX);
$c2 = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND shift_date = ? AND notes LIKE ?", [$ADMIN, $eX, '%S7e%']);
zz_check(count($c2) === 1 && (int)$c2[0]['orders_count'] === 1 && zz_money($c2[0]['expected_cash'], $DP) && zz_money($c2[0]['variance_cash'], 0), "Accountant close of X ($eX), recorded after the X-1 close: E2 only (X-1 not counted again)", $c2 ?: ($r['captured'] ?? zz_err($r)));
if (count($c1) === 1 && count($c2) === 1) {
    $paidE = ['E1' => (string)zz_one("SELECT paid_at FROM stock_orders WHERE id = ?", [$E1]), 'E2' => (string)zz_one("SELECT paid_at FROM stock_orders WHERE id = ?", [$E2])];
    $inClose = [];
    foreach (['X-1' => $c1[0], 'X' => $c2[0]] as $lbl => $cc) {
        $w = rh_pos_close_window($pdo, $cc);
        foreach ($paidE as $sl => $pt) { if ($pt >= $w['from'] && $pt < $w['end']) { $inClose[$sl][] = $lbl; } }
    }
    zz_check(($inClose['E1'] ?? []) === ['X-1'] && ($inClose['E2'] ?? []) === ['X'], 'Shift-close report windows (rebuilt): E1 only in X-1, E2 only in X', $inClose);
    zz_check(zz_money(rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $c1[0]), $DP) && zz_money(rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $c2[0]), $DP), 'Revenue rebuilt on read: each close = one sale');
    $r = zz_call('admin/pos-drift-report.php', [], false, $eHours, ['shiftDrift']);
    $driftIds = array_map('intval', array_column((array)($r['captured']['shiftDrift'] ?? []), 'id'));
    zz_check(empty($r['fatal']) && is_array($r['captured']['shiftDrift'] ?? null) && !in_array((int)$c1[0]['id'], $driftIds, true) && !in_array((int)$c2[0]['id'], $driftIds, true), 'Drift report: no drift on either catch-up close', ['drift_ids' => $driftIds]);
}
$zzHoursOff($eHours);
zz_stock_matches('Stock = model after S7e');

/* ======================================================================================
 * S7d. A night that is never closed, then a close after the next opening, then the accountant's
 *      close of that night: every sale counted once (owner 2026-11-10). Runs right after S7e (the
 *      night sale comes after S7e's closes). Hours IN MEMORY only (never written).
 * ====================================================================================== */
zz_section('S7d', 'Night never closed, close after opening, accountant close of the night: counted once, no drift');
require_once $__zzRoot . '/admin/includes/pos-shift-totals.php';
$dTz = rh_site_timezone();
$dNow = new DateTimeImmutable((string)zz_one("SELECT NOW()"), $dTz);
$dOpenAt = $dNow->setTime((int)$dNow->format('H'), (int)$dNow->format('i'))->modify('+2 minutes');
$dOpenSql = $dOpenAt->format('Y-m-d H:i:s');
$dHours = $zzHoursFor($dOpenAt->format('H:i'), $dOpenAt->modify('-60 minutes')->format('H:i'));
$zzHoursOn($dHours);
$dPrev = rh_station_trading_business_date();
$dLastClose = (string)zz_one("SELECT MAX(closed_at) FROM stock_shift_closes WHERE user_id = ?", [$ADMIN]);
zz_check(true, "Night = trading day $dPrev, opening " . $dOpenAt->format('H:i') . " (last close so far: S7e's, $dLastClose)");
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O0 night', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]), $dHours);
$O0 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O0 night']);
$paid0 = $O0 > 0 ? (string)zz_one("SELECT paid_at FROM stock_orders WHERE id = ?", [$O0]) : '';
zz_check($O0 > 0 && (zz_order($O0)['status'] ?? '') === 'paid' && $paid0 !== '' && $paid0 < $dOpenSql, "Night sale paid before the opening ($paid0), no close follows it", zz_err($r));
if ($O0 > 0) {
    zz_take_recipe(REC_D, 1);
    $p0 = zz_q("SELECT receipt_number FROM payments WHERE booking_type = 'restaurant' AND booking_id = ?", [$O0]);
    $state['receipt_numbers'] = array_values(array_unique(array_merge($state['receipt_numbers'], array_filter(array_column($p0, 'receipt_number')))));
}
$waited = 0;
while (($waited < 200) && ((string)zz_one("SELECT NOW()") < $dOpenAt->modify('+2 seconds')->format('Y-m-d H:i:s') || new DateTimeImmutable('now', $dTz) < $dOpenAt->modify('+2 seconds'))) { sleep(5); $waited += 5; }
$dDay = rh_station_trading_business_date();
zz_check($dDay !== $dPrev, "Opening passed (waited {$waited}s): trading day $dDay");
$r = zz_call('admin/pos.php', ['action' => 'close_shift', 'declared_cash' => (string)$DP, 'declared_mobile' => '0', 'declared_card' => '0', 'shift_note' => 'ZZ POSTEST S7d close after opening'], true, $dHours);
$cD = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND created_at >= ? AND notes LIKE ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart, '%S7d close after opening%'])[0] ?? null;
zz_check($cD !== null && $r['error'] === null && $cD['shift_date'] === $dDay && (int)$cD['orders_count'] === 1 && zz_money($cD['expected_cash'], $DP) && zz_money($cD['variance_cash'], 0),
    "Close after the opening (dated $dDay) counts the unclosed night sale, balances", $cD ? [$cD['shift_date'], $cD['orders_count'], $cD['expected_cash']] : zz_err($r));
// Accountant close of the night: this cashier must be treated as closed (nothing offered, nothing expected again).
$r = zz_call('admin/pos-accounting.php', ['business_date' => $dPrev, 'action' => 'close_selected', 'user_ids' => [(string)$ADMIN],
    'declared_cash' => [(string)$ADMIN => '0'], 'declared_mobile' => [(string)$ADMIN => '0'], 'declared_card' => [(string)$ADMIN => '0']], false, $dHours, ['message', 'error', 'existingCloses']);
$acctMsg = (string)($r['captured']['message'] ?? '');
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_shift_closes WHERE user_id = ? AND id > ?", [$ADMIN, (int)($cD["id"] ?? 0)]) === 0 && stripos($acctMsg, 'already closed: 1') !== false,
    "Accountant close of $dPrev skips the cashier as already closed (no second close)", $acctMsg !== '' ? $acctMsg : ($r['captured']['error'] ?? zz_err($r)));
zz_check((int)(($r['captured']['existingCloses'] ?? [])[(string)$ADMIN] ?? 0) > 0, "Accountant screen for $dPrev shows the cashier Closed (nothing still expected)", $r['captured']['existingCloses'] ?? null);
if ($cD) {
    $hits = 0;
    foreach (zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ?", [$ADMIN]) as $anyClose) {
        $w = rh_pos_close_window($pdo, $anyClose);
        if ($paid0 >= $w['from'] && $paid0 < $w['end']) { $hits++; }
    }
    zz_check($hits === 1 && zz_money(rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $cD), $DP), 'Shift-close report: the night sale sits in exactly one close (rebuilt window)', $hits);
    $r = zz_call('admin/pos-drift-report.php', [], false, $dHours, ['shiftDrift']);
    $driftIds = array_map('intval', array_column((array)($r['captured']['shiftDrift'] ?? []), 'id'));
    zz_check(empty($r['fatal']) && is_array($r['captured']['shiftDrift'] ?? null) && !in_array((int)$cD['id'], $driftIds, true), 'Drift report: no drift for the look-back close (compared against its own window)', ['drift_ids' => $driftIds, 'fatal' => $r['fatal'] ?? null]);
}
$zzHoursOff($dHours);
zz_stock_matches('Stock = model after S7d');

/* ======================================================================================
 * S1. Place -> no stock; start -> ready deducts once (FIFO, cost); ready again / collect / serve / bump: no 2nd deduction
 * ====================================================================================== */
zz_section('S1', 'KDS lifecycle: stock leaves once, at Ready, FIFO');
$r = zz_pos(['action' => 'park', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O1'] + $cart([$fl(3)]));
zz_check(zz_jok($r), 'park (fire to kitchen) via admin/pos.php', zz_err($r));
$O1 = (int)($r['json']['order_id'] ?? 0);
[$L1] = zz_lines($O1) + [0];
$o = zz_order($O1);
zz_check(($o['status'] ?? '') === 'placed' && ($o['kitchen_status'] ?? '') === 'new' && zz_money($o['total_amount'] ?? 0, 3 * $FP), 'Order placed, fired (kitchen new), total 3 x price', $o);
$l = zz_line($L1);
zz_check(($l['kds_status'] ?? '') === 'pending' && (int)($l['stock_deducted'] ?? 1) === 0 && ($l['station'] ?? '') === 'kitchen', 'Line pending on the kitchen, not deducted', $l);
zz_check(zz_moves('pos_order', $L1) === [], 'Placement moved no stock');
zz_stock_matches('Stock unchanged after placement');
$r = zz_kds('start_item', ['item_id' => $L1]);
zz_check(zz_jok($r) && zz_line($L1)['kds_status'] === 'preparing', 'start_item -> preparing', zz_err($r));
zz_check(zz_moves('pos_order', $L1) === [], 'Start moved no stock');
$r = zz_kds('ready_item', ['item_id' => $L1]);
zz_check(zz_jok($r), 'ready_item', zz_err($r));
$t1 = zz_take_recipe(REC_F, 3);
$mv = zz_moves('pos_order', $L1);
zz_check(zz_moves_ok($mv, REC_F, 3), 'Ready deducted exactly recipe x 3 (beef 1.2, rice 0.75), one row per ingredient', $mv);
zz_check(zz_near($mv['A']['c'] ?? 0, 950.0, 0.0001), 'FIFO cost: beef cost_at_time 950 = (1.0 x 900 + 0.2 x 1200) / 1.2', $mv['A'] ?? null);
$bd = zz_q("SELECT sbd.batch_id, sbd.quantity_deducted FROM stock_batch_deductions sbd JOIN stock_adjustments sa ON sa.id = sbd.adjustment_id WHERE sa.source_type = 'pos_order' AND sa.source_id = ? AND sa.ingredient_id = ? ORDER BY sbd.batch_id", [$L1, $ING['A']]);
zz_check(count($bd) === 2 && (int)$bd[0]['batch_id'] === $batchIds['A'][0] && zz_near($bd[0]['quantity_deducted'], 1.0) && (int)$bd[1]['batch_id'] === $batchIds['A'][1] && zz_near($bd[1]['quantity_deducted'], 0.2), 'Batch trail: oldest batch emptied first (1.0), then 0.2 from the next', $bd);
zz_check((int)zz_line($L1)['stock_deducted'] === 1, 'stock_deducted = 1');
zz_stock_matches('Ingredient totals and batch levels = FIFO model (oldest batch depleted)');
$r = zz_kds('ready_item', ['item_id' => $L1]);
zz_check(zz_jok($r) && zz_moves_ok(zz_moves('pos_order', $L1), REC_F, 3), 'ready_item again: accepted, no second deduction', zz_err($r));
$r = zz_kds('collect_item', ['item_id' => $L1]);
zz_check(zz_jok($r) && zz_line($L1)['kds_status'] === 'collection', 'collect_item -> collection', zz_err($r));
$r = zz_kds('serve_item', ['item_id' => $L1]);
zz_check(zz_jok($r) && zz_line($L1)['kds_status'] === 'served', 'serve_item -> served', zz_err($r));
$r = zz_kds('bump_ticket', ['order_id' => $O1]);
zz_check(zz_jok($r), 'bump_ticket on the served ticket', zz_err($r));
zz_check(zz_moves_ok(zz_moves('pos_order', $L1), REC_F, 3) && (int)zz_line($L1)['stock_deducted'] === 1, 'Collect / serve / bump took nothing more');
zz_stock_matches('Stock unchanged by collect / serve / bump');
zz_check((zz_order($O1)['kitchen_status'] ?? '') === 'served' && (int)zz_one("SELECT COUNT(*) FROM payments WHERE booking_type='restaurant' AND booking_id = ?", [$O1]) === 0, 'Ticket served, still unpaid (no payment rows)');

/* ======================================================================================
 * S2. add_to_tab; serve straight from pending; bump an unstarted ticket: each line deducts once
 * ====================================================================================== */
zz_section('S2', 'Skip Ready: serve_item from pending, bump_ticket on an unstarted line');
$r = zz_pos(['action' => 'park', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O2'] + $cart([$fl(1)]));
zz_check(zz_jok($r), 'park O2', zz_err($r));
$O2 = (int)($r['json']['order_id'] ?? 0);
$r = zz_pos(['action' => 'add_to_tab', 'tab_order_id' => (string)$O2] + $cart([$dl(1)]));
zz_check(zz_jok($r) && zz_money($r['json']['new_total'] ?? 0, $FP + $DP), 'add_to_tab adds a bar round, total = food + drink', zz_err($r));
[$L2F, $L2D] = zz_lines($O2) + [0, 0];
zz_check((zz_line($L2D)['station'] ?? '') === 'bar' && zz_line($L2D)['kds_status'] === 'pending' && zz_moves('pos_order', $L2D) === [], 'Added drink line pending on the bar, no stock moved');
$r = zz_kds('serve_item', ['item_id' => $L2F]);
zz_check(zz_jok($r), 'serve_item straight from pending', zz_err($r));
$t2f = zz_take_recipe(REC_F, 1);
zz_check(zz_moves_ok(zz_moves('pos_order', $L2F), REC_F, 1) && (int)zz_line($L2F)['stock_deducted'] === 1, 'Served-from-pending line deducted exactly once');
$r = zz_kds('bump_ticket', ['order_id' => $O2]);
zz_check(zz_jok($r), 'bump_ticket with the drink never started', zz_err($r));
$t2d = zz_take_recipe(REC_D, 1);
zz_check(zz_moves_ok(zz_moves('pos_order', $L2D), REC_D, 1) && (int)zz_line($L2D)['stock_deducted'] === 1 && zz_line($L2D)['kds_status'] === 'served', 'Bump deducted the unstarted drink exactly once and served it');
zz_check(zz_moves_ok(zz_moves('pos_order', $L2F), REC_F, 1), 'Bump did not deduct the already-served food again');
$r = zz_kds('bump_ticket', ['order_id' => $O2]);
zz_check(zz_jok($r) && zz_moves_ok(zz_moves('pos_order', $L2D), REC_D, 1), 'Second bump: nothing more taken', zz_err($r));
zz_stock_matches('Stock = model after S2');

/* ======================================================================================
 * S3. 86 before start (nothing to restore), 86 after Ready (wastage, no double count), recall restores, 86 after serve refused
 * ====================================================================================== */
zz_section('S3', 'Station 86 / recall');
$r = zz_pos(['action' => 'park', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O3'] + $cart([$fl(1), $fl(1), $dl(1)]));
zz_check(zz_jok($r), 'park O3 (food, food, drink)', zz_err($r));
$O3 = (int)($r['json']['order_id'] ?? 0);
[$La, $Lb, $Lc] = zz_lines($O3) + [0, 0, 0];
$r = zz_kds('void_item', ['item_id' => $La]);
zz_check(zz_jok($r) && ($r['json']['stock'] ?? '') === 'none', '86 of a never-started line: stock outcome none', zz_err($r) ?: ($r['json'] ?? null));
zz_check(zz_moves('pos_order', $La) === [] && zz_restores('pos_order', $La) === 0 && zz_line($La)['kds_status'] === 'void', 'Nothing deducted, nothing restored, line void');
zz_check(zz_money(zz_order($O3)['total_amount'] ?? 0, $FP + $DP), 'Order total dropped by the line');
zz_check((int)zz_one("SELECT COUNT(*) FROM station_messages WHERE order_id = ? AND notice_type = 'item_86' AND to_user_id = ?", [$O3, $ADMIN]) === 1, 'Cashier gets the item_86 notice');
$r = zz_kds('ready_item', ['item_id' => $Lb]);
zz_check(zz_jok($r), 'ready_item on the second food line', zz_err($r));
$t3b = zz_take_recipe(REC_F, 1);
$r = zz_kds('void_item', ['item_id' => $Lb]);
zz_check(zz_jok($r) && ($r['json']['stock'] ?? '') === 'wastage', '86 after Ready: outcome wastage (food was made)', zz_err($r) ?: ($r['json'] ?? null));
zz_check(zz_moves_ok(zz_moves('pos_order', $Lb), REC_F, 1) && zz_restores('pos_order', $Lb) === 0, 'No second deduction and no restore for the made line');
$wasted = zz_q("SELECT ingredient_id, quantity FROM stock_wastage WHERE reason LIKE ? ORDER BY ingredient_id", ['%' . zz_order($O3)['reference'] . '%']);
zz_check(count($wasted) === 2 && zz_near($wasted[0]['quantity'], 0.4) && zz_near($wasted[1]['quantity'], 0.25), 'Wastage rows = recipe quantities (beef 0.4, rice 0.25)', $wasted);
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_adjustments WHERE source_type = 'pos_order' AND source_id = ? AND reason LIKE 'Voided to wastage%'", [$Lb]) === 2, 'Usage rows reclassified "Voided to wastage" (cost counted once)');
zz_stock_matches('Stock unchanged by the wastage 86 (already off the shelf)');
$r = zz_kds('serve_item', ['item_id' => $Lc]);
zz_check(zz_jok($r) && (zz_order($O3)['kitchen_status'] ?? '') === 'served', 'serve_item on the drink: ticket served', zz_err($r));
$t3c = zz_take_recipe(REC_D, 1);
zz_stock_matches('Drink deducted on serve');
$r = zz_kds('recall_ticket', ['order_id' => $O3]);
zz_check(zz_jok($r) && (int)($r['json']['stock_restored'] ?? 0) === 1, 'recall_ticket restores the served drink', zz_err($r) ?: ($r['json'] ?? null));
zz_give_recipe($t3c);
zz_check(zz_restores('pos_order', $Lc) === 1 && (int)zz_line($Lc)['stock_deducted'] === 0 && zz_line($Lc)['kds_status'] === 'preparing', 'void_restore row written, line back to preparing, flag cleared');
zz_stock_matches('Recall put the juice back into the same batch');
$r = zz_kds('serve_item', ['item_id' => $Lc]);
zz_check(zz_jok($r), 'Re-serve after recall', zz_err($r));
$t3c = zz_take_recipe(REC_D, 1);
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_adjustments WHERE source_type='pos_order' AND source_id = ? AND quantity_change < 0", [$Lc]) === 2 && (int)zz_line($Lc)['stock_deducted'] === 1, 'Re-serve deducts again (owed after the restore)');
$r = zz_kds('void_item', ['item_id' => $Lc]);
zz_check(($r['json']['ok'] ?? null) === false && stripos(zz_err($r), 'served') !== false, '86 after serve is refused', $r['json'] ?? $r);
zz_check(zz_line($Lc)['kds_status'] === 'served', 'Served line untouched by the refused 86');
zz_stock_matches('Stock = model after S3');

/* ======================================================================================
 * S4. Payments through admin/pos.php: single cash, split cash+card+mobile, tip, place-and-pay
 * ====================================================================================== */
zz_section('S4', 'Till payments -> payments ledger');
$payRow = static fn(int $oid): array => zz_q("SELECT * FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type,'') <> 'refund' ORDER BY id", [$oid]);
$r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$O1, 'payment_method' => 'cash', 'tendered_amount' => '40000']);
zz_check(zz_jok($r) && zz_money($r['json']['change'] ?? -1, 40000 - 3 * $FP), 'pay_existing cash: ok, change right', zz_err($r));
$p = $payRow($O1);
zz_check(count($p) === 1 && $p[0]['payment_method'] === 'cash' && zz_money($p[0]['total_amount'], 3 * $FP) && zz_vat_ok($p[0], $VAT) && $p[0]['payment_type'] === 'full_payment' && $p[0]['payment_status'] === 'completed' && (int)$p[0]['recorded_by'] === $ADMIN && !empty($p[0]['receipt_number']) && $p[0]['payment_reference'] === 'POS-' . zz_order($O1)['reference'],
    'O1: one completed cash row, net + VAT = gross, receipt number, recorded_by cashier', $p);
$o = zz_order($O1);
zz_check($o['status'] === 'paid' && (int)$o['paid_by'] === $ADMIN && zz_money($o['tip_amount'], 0), 'O1 paid, paid_by = cashier');
$legs = [
    1 => ['payment_method' => 'cash', 'tendered_amount' => '5000'],
    2 => ['payment_method' => 'card_manual', 'card_last4' => '4242', 'card_auth_code' => 'ZZ1234'],
    3 => ['payment_method' => 'mobile_money', 'mobile_wallet_provider' => 'Airtel Money', 'mobile_wallet_reference' => 'ZZPT-MM-1'],
];
foreach ($legs as $n => $leg) {
    $r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$O2, 'split_count' => '3', 'split_number' => (string)$n] + $leg);
    zz_check(zz_jok($r) && ($n < 3 ? !empty($r['json']['split_intermediate']) : empty($r['json']['split_intermediate'])), "Split leg $n/3 ({$leg['payment_method']})", zz_err($r));
}
$p = $payRow($O2);
$share = round(($FP + $DP) / 3, 2);
$methods = array_column($p, 'payment_method');
zz_check(count($p) === 3 && $methods === ['cash', 'credit_card', 'mobile_money'], 'O2: one ledger row per leg in its own tender', $methods);
$vatAll = true; foreach ($p as $row) { $vatAll = $vatAll && zz_vat_ok($row, $VAT) && zz_money($row['total_amount'], $share); }
zz_check($vatAll && zz_money(array_sum(array_column($p, 'total_amount')), $FP + $DP), 'Each leg = 1/3 of the bill, net + VAT = gross, legs sum to the bill', $p);
zz_check(count(array_unique(array_column($p, 'receipt_number'))) === 3 && array_column($p, 'payment_type') === ['partial_payment', 'partial_payment', 'full_payment'], 'Each leg its own receipt number (by design: one receipt per leg), last leg full_payment');
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_order_splits WHERE order_id = ?", [$O2]) === 3 && zz_order($O2)['status'] === 'paid', 'O2: 3 split rows, order paid after the last leg');
$r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$O3, 'payment_method' => 'mobile_money', 'mobile_wallet_provider' => 'TNM Mpamba', 'mobile_wallet_reference' => 'ZZPT-MM-2', 'tip_amount' => '500']);
zz_check(zz_jok($r), 'pay_existing mobile money with a 500 tip', zz_err($r));
$p = $payRow($O3);
zz_check(count($p) === 1 && zz_money($p[0]['total_amount'], $DP) && zz_vat_ok($p[0], $VAT) && $p[0]['payment_method'] === 'mobile_money', 'O3: ledger books the sale only (tip not revenue, not VAT-rated)', $p);
zz_check(zz_money(zz_order($O3)['tip_amount'], 500) && zz_order($O3)['status'] === 'paid', 'O3: tip 500 kept on the order');
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O4', 'payment_method' => 'cash', 'tendered_amount' => (string)($FP + 2 * $DP)] + $cart([$fl(1), $dl(2)]));
$O4 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O4']);
zz_check($O4 > 0 && $r['error'] === null && empty($r['open_tx']), 'Place-and-pay (default action) in one go', zz_err($r));
[$L4F, $L4D] = zz_lines($O4) + [0, 0];
$t4d = zz_take_recipe(REC_D, 2);
$p = $payRow($O4);
zz_check(count($p) === 1 && zz_money($p[0]['total_amount'], $FP + 2 * $DP) && zz_vat_ok($p[0], $VAT) && zz_order($O4)['status'] === 'paid', 'O4: one cash row for food + 2 drinks, order paid', $p);
zz_check(zz_line($L4D)['kds_status'] === 'served' && zz_moves_ok(zz_moves('pos_order', $L4D), REC_D, 2), 'Bar line auto-served at payment, deducted once (2 x 0.3)');
zz_check(zz_line($L4F)['kds_status'] === 'pending' && zz_moves('pos_order', $L4F) === [], 'Food line fired to the kitchen, not deducted');
$allRc = array_column(zz_q("SELECT receipt_number FROM payments WHERE booking_type='restaurant' AND booking_id IN (?, ?, ?, ?)", [$O1, $O2, $O3, $O4]), 'receipt_number');
zz_check(count($allRc) === 6 && count(array_unique($allRc)) === 6, 'Six sale rows, six distinct receipt numbers', $allRc);
$state['receipt_numbers'] = $allRc;
zz_stock_matches('Stock = model after S4');

/* ======================================================================================
 * S5. Refunds: full (refund_order) and partial (86 on a paid order -> REF86 -> settle_refund); shop-mode rule
 * ====================================================================================== */
zz_section('S5', 'Till refunds');
$r = zz_kds('start_item', ['item_id' => $L4F]);
zz_check(zz_jok($r), 'Kitchen starts the O4 food line', zz_err($r));
$r = zz_pos(['action' => 'refund_order', 'order_id' => (string)$O4, 'refund_reason' => 'ZZ POSTEST full refund']);
zz_check(zz_jok($r), 'refund_order (full) via admin/pos.php', zz_err($r));
$sale = $payRow($O4)[0] ?? [];
$ref = zz_q("SELECT * FROM payments WHERE booking_type='restaurant' AND booking_id = ? AND payment_type = 'refund'", [$O4]);
zz_check(count($ref) === 1 && zz_money($ref[0]['total_amount'], $sale['total_amount'] ?? -1) && zz_money($ref[0]['payment_amount'], $sale['payment_amount'] ?? -1) && zz_money($ref[0]['vat_amount'], $sale['vat_amount'] ?? -1)
    && $ref[0]['payment_method'] === 'cash' && (int)$ref[0]['original_payment_id'] === (int)($sale['id'] ?? 0) && $ref[0]['refund_status'] === 'completed', 'Contra row reverses the sale exactly (gross, net, VAT as charged), same tender', $ref);
zz_check(($sale['payment_status'] ?? '') === 'completed', 'Original sale row left untouched (immutable)');
zz_check(zz_order($O4)['status'] === 'refunded' && zz_order($O4)['kitchen_status'] === 'served', 'Order refunded, ticket closed');
zz_check(zz_line($L4F)['kds_status'] === 'void' && zz_line($L4D)['kds_status'] === 'served', 'Started food line closed (void), served drinks untouched');
$t5w = zz_take_recipe(REC_F, 1); // started, never deducted -> wastage deducts it now
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_wastage WHERE reason LIKE ?", ['%' . zz_order($O4)['reference'] . '%']) === 2 && zz_moves('pos_order', $L4F) === [], 'Started food line -> wastage (deducted as wastage, not usage)');
zz_check(zz_moves_ok(zz_moves('pos_order', $L4D), REC_D, 2) && zz_restores('pos_order', $L4D) === 0, 'Served drinks: stock not restored (restaurant mode)');
zz_check((int)zz_one("SELECT COUNT(*) FROM station_messages WHERE order_id = ? AND message LIKE 'STOP%'", [$O4]) === 1, 'Kitchen told to STOP');
zz_stock_matches('Stock = model after the full refund');
// Partial refund: 86 one of two food lines on a paid order -> pending REF86 -> settle_refund.
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O5', 'payment_method' => 'cash', 'tendered_amount' => (string)(2 * $FP)] + $cart([$fl(1), $fl(1)]));
$O5 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O5']);
zz_check($O5 > 0 && zz_order($O5)['status'] === 'paid', 'O5 placed and paid (2 food lines)', zz_err($r));
[$L5a] = zz_lines($O5) + [0];
$r = zz_kds('void_item', ['item_id' => $L5a]);
zz_check(zz_jok($r) && zz_money($r['json']['pending_refund'] ?? 0, $FP) && ($r['json']['stock'] ?? '') === 'none', '86 on the paid order raises a pending refund of one line, no stock move', zz_err($r) ?: ($r['json'] ?? null));
$r86 = zz_q("SELECT * FROM payments WHERE booking_type='restaurant' AND booking_id = ? AND payment_type = 'refund'", [$O5]);
zz_check(count($r86) === 1 && strpos($r86[0]['payment_reference'], 'REF86-') === 0 && $r86[0]['refund_status'] === 'pending' && zz_money($r86[0]['refund_amount'], $FP) && zz_vat_ok($r86[0], $VAT), 'REF86 row pending, amount = line, net + VAT = gross', $r86);
zz_check(zz_order($O5)['status'] === 'paid' && zz_money(zz_order($O5)['total_amount'], $FP), 'O5 stays paid, total lowered to the remaining line');
$r = zz_pos(['action' => 'settle_refund', 'order_id' => (string)$O5, 'settle_note' => 'ZZ POSTEST handed back']);
zz_check(zz_jok($r) && zz_money($r['json']['amount'] ?? 0, $FP), 'settle_refund pays it out', zz_err($r));
$r86 = zz_q("SELECT refund_status, recorded_by FROM payments WHERE booking_type='restaurant' AND booking_id = ? AND payment_type = 'refund'", [$O5]);
zz_check(($r86[0]['refund_status'] ?? '') === 'completed' && (int)($r86[0]['recorded_by'] ?? 0) === $ADMIN, 'Refund completed on the settling cashier', $r86);
// Shop mode (in-memory setting, this one call only): a served, deducted line goes back on the shelf.
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O6', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]));
$O6 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O6']);
[$L6] = zz_lines($O6) + [0];
$t6 = zz_take_recipe(REC_D, 1);
zz_check($O6 > 0 && zz_line($L6)['kds_status'] === 'served' && zz_moves_ok(zz_moves('pos_order', $L6), REC_D, 1), 'O6 drink sold, auto-served, deducted', zz_err($r));
$r = zz_pos(['action' => 'refund_order', 'order_id' => (string)$O6, 'refund_reason' => 'ZZ POSTEST shop refund'], ['restaurant_system_enabled' => '0']);
zz_check(zz_jok($r) && zz_order($O6)['status'] === 'refunded', 'refund_order in shop mode', zz_err($r));
zz_give_recipe($t6);
zz_check(zz_restores('pos_order', $L6) === 1 && (int)zz_line($L6)['stock_deducted'] === 0 && zz_line($L6)['kds_status'] === 'served', 'Shop mode: served goods restored to the shelf, sale stays served');
zz_stock_matches('Stock = model after the refunds');

/* ======================================================================================
 * S6. Room service: folio charge, stock at folio posting, delivery takes nothing more, 86 restores, booking close
 * ====================================================================================== */
zz_section('S6', 'Room service: folio, delivery, booking close');
$chargesOf = static fn(int $oid): array => zz_q("SELECT id, charge_type, source_item_id, quantity, line_total, voided, stock_tracked FROM booking_charges WHERE stock_order_id = ? ORDER BY id", [$oid]);
$r = zz_pos(['action' => 'park', 'order_type' => 'room_service', 'table_number' => 'ZZPT-01'] + $cart([$fl(1)]));
zz_check(zz_jok($r), 'Room-service order to ZZPT-01 (checked-in booking)', zz_err($r));
$RB = (int)($r['json']['order_id'] ?? 0);
[$LRB] = zz_lines($RB) + [0];
$ch = $chargesOf($RB);
$trb = zz_take_recipe(REC_F, 1);
zz_check(count($ch) === 1 && $ch[0]['charge_type'] === 'food' && (int)$ch[0]['stock_tracked'] === 1 && (int)zz_order($RB)['booking_id'] === $BOOKING, 'Charge posted to the guest folio at order time, stock-tracked', $ch);
zz_check(zz_moves_ok(zz_moves('room_service', (int)($ch[0]['id'] ?? 0)), REC_F, 1) && (int)zz_line($LRB)['stock_deducted'] === 1, 'Room service: stock leaves at folio posting (keyed on the charge), line flagged');
zz_check((int)zz_one("SELECT COUNT(*) FROM payments WHERE booking_type='restaurant' AND booking_id = ?", [$RB]) === 0, 'No till payment for room service (folio)');
$r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$RB, 'payment_method' => 'cash', 'tendered_amount' => '20000']);
zz_check(($r['json']['ok'] ?? null) === false && stripos(zz_err($r), 'folio') !== false, 'Till refuses to take cash for a room-service order', $r['json'] ?? $r);
$r = zz_call('admin/room-service-dashboard.php', ['action' => 'mark_delivered', 'order_id' => (string)$RB], false);
zz_check(empty($r['rs_flash']['err']) && zz_order($RB)['status'] === 'completed' && zz_line($LRB)['kds_status'] === 'served', 'room-service-dashboard mark_delivered closes it', $r['rs_flash'] ?? zz_err($r));
zz_check(zz_moves('pos_order', $LRB) === [] && zz_moves_ok(zz_moves('room_service', (int)($ch[0]['id'] ?? 0)), REC_F, 1), 'Delivery took nothing more (no second deduction)');
$SKIPS[] = 'S6 delivery-time deduct (room-service-dashboard.php ~381): unreachable from the till - POS room-service lines are always deducted at folio posting (stock_deducted=1), so mark_delivered only finds undeducted lines from other entry points';
zz_stock_matches('Stock = model after delivery');
$r = zz_pos(['action' => 'park', 'order_type' => 'room_service', 'table_number' => 'ZZPT-01'] + $cart([$fl(1), $fl(1), $dl(1)]));
zz_check(zz_jok($r), 'Second room-service order (food, food, drink)', zz_err($r));
$RA = (int)($r['json']['order_id'] ?? 0);
[$RA1, $RA2, $RA3] = zz_lines($RA) + [0, 0, 0];
$tra1 = zz_take_recipe(REC_F, 1); $tra2 = zz_take_recipe(REC_F, 1); $tra3 = zz_take_recipe(REC_D, 1);
$ch = $chargesOf($RA);
zz_check(count($ch) === 3 && array_sum(array_column($ch, 'stock_tracked')) === 3, '3 folio charges, all stock-tracked', $ch);
zz_stock_matches('Stock = model after the 3 room-service lines');
$r = zz_kds('void_item', ['item_id' => $RA3]);
zz_check(zz_jok($r) && ($r['json']['stock'] ?? '') === 'restored', '86 of the deducted, unstarted drink: restored', zz_err($r) ?: ($r['json'] ?? null));
zz_give_recipe($tra3);
zz_check(zz_restores('room_service', (int)($ch[2]['id'] ?? 0)) === 1 && (int)zz_one("SELECT voided FROM booking_charges WHERE id = ?", [(int)($ch[2]['id'] ?? 0)]) === 1, 'Restore keyed on the charge; folio charge voided');
zz_stock_matches('86 restored exactly, into the same batches');
$r = zz_kds('start_item', ['item_id' => $RA1]);
zz_check(zz_jok($r), 'Kitchen starts the first room-service food line', zz_err($r));
try {
    $pdo->beginTransaction();
    $close = rh_close_orders_for_booking($pdo, $BOOKING, $ADMIN, 'ZZ POSTEST booking closed');
    $pdo->commit();
    zz_check(true, 'rh_close_orders_for_booking (pos-order-close.php) ran');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $close = [];
    zz_bad('rh_close_orders_for_booking', $e->getMessage());
}
zz_give_recipe($tra2);
zz_check(zz_line($RA1)['kds_status'] === 'preparing' && zz_line($RA2)['kds_status'] === 'void', 'Started line kept, unstarted line closed', [$close['kept'] ?? null, zz_line($RA1), zz_line($RA2)]);
zz_check((int)zz_one("SELECT voided FROM booking_charges WHERE id = ?", [(int)($ch[0]['id'] ?? 0)]) === 0 && (int)zz_one("SELECT voided FROM booking_charges WHERE id = ?", [(int)($ch[1]['id'] ?? 0)]) === 1, 'Started line stays on the folio, unstarted charge voided');
zz_check(zz_restores('room_service', (int)($ch[1]['id'] ?? 0)) === count(REC_F) && zz_restores('room_service', (int)($ch[0]['id'] ?? 0)) === 0, 'Unstarted line restored once (one void_restore row per ingredient); started line not restored', [zz_restores('room_service', (int)($ch[1]['id'] ?? 0)), zz_restores('room_service', (int)($ch[0]['id'] ?? 0))]);
zz_stock_matches('Booking close restored exactly the unstarted line');

/* ======================================================================================
 * S8. Till without the KDS: no setting routes it; manager force-serve at settlement deducts (pos.php ~399)
 * ====================================================================================== */
zz_section('S8', 'Till-side deduction (force-serve / bar auto-serve)');
$SKIPS[] = 'S8 setting: no setting routes till sales around the KDS (restaurant_system_enabled only switches refund stock rules = shop mode, covered in S5); till-side deducts tested: bar auto-serve at payment (S4 O4, S5 O6) and manager force-serve here';
$r = zz_pos(['action' => 'park', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O7'] + $cart([$fl(1)]));
$O7 = (int)($r['json']['order_id'] ?? 0);
[$L7] = zz_lines($O7) + [0];
zz_check(zz_jok($r), 'park O7 (food)', zz_err($r));
$r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$O7, 'payment_method' => 'cash', 'tendered_amount' => (string)$FP]);
zz_check(($r['json']['ok'] ?? null) === false && stripos(zz_err($r), 'not yet served') !== false && zz_order($O7)['status'] === 'placed', 'Settling with unserved food is refused', $r['json'] ?? $r);
$r = zz_pos(['action' => 'pay_existing', 'order_id' => (string)$O7, 'payment_method' => 'cash', 'tendered_amount' => (string)$FP, 'force_serve_kitchen' => '1']);
zz_check(zz_jok($r), 'Force-serve + pay (admin holds pos_force_serve)', zz_err($r));
$t7 = zz_take_recipe(REC_F, 1);
zz_check(zz_line($L7)['kds_status'] === 'served' && (int)zz_line($L7)['stock_deducted'] === 1 && zz_moves_ok(zz_moves('pos_order', $L7), REC_F, 1), 'Force-served line deducted exactly once at the till');
zz_check((int)zz_one("SELECT COUNT(*) FROM stock_order_audit WHERE order_id = ? AND event = 'kitchen_items_force_served'", [$O7]) === 1 && zz_order($O7)['status'] === 'paid', 'Audited and paid');
zz_stock_matches('Stock = model after S8');
$state['receipt_numbers'] = array_column(zz_q("SELECT receipt_number FROM payments WHERE booking_type='restaurant' AND receipt_number IS NOT NULL"), 'receipt_number');

/* ======================================================================================
 * S7. Opening float + close_shift: expected figures match the scenarios exactly
 * ====================================================================================== */
zz_section('S7', 'Shift close (Z) = scenario money');
$win = rh_station_union_trading_window();
$nowSql = (string)zz_one("SELECT NOW()");
$winOverride = [];
if (!($win['start_sql'] <= $runStart && $nowSql < $win['trading_end_sql'])) {
    foreach (['kitchen', 'bar', 'coffee_bar'] as $st) { $winOverride[rh_station_setting_key($st, 'opens_at')] = '00:00'; $winOverride[rh_station_setting_key($st, 'closes_at')] = '23:59'; }
    require_once $__zzRoot . '/admin/includes/pos-shift-totals.php';
    $real = rh_pos_shift_totals($pdo, $ADMIN, $win['start_sql'], $win['trading_end_sql'], $win['start_sql']);
    $NOTES[] = "S7: the run spans two trading days (run start $runStart, trading day {$win['start_sql']} - {$win['trading_end_sql']}); in that real window the till sees orders=" . $real['orders_count'] . ' cash=' . $real['cash']
        . '. set_float/close_shift were run with all-day station hours IN MEMORY to prove the maths.';
}
$expCash = 3 * $FP + $share + ($FP + 2 * $DP) + 2 * $FP + $DP + $FP - ($FP + 2 * $DP) - $FP - $DP; // sales - refund O4 - REF86 O5 - refund O6
$expCard = $share;
$expMobile = $share + $DP + 500;
$float = 10000.0;
$r = zz_call('admin/pos.php', ['action' => 'set_float', 'float_amount' => (string)$float, 'float_note' => 'ZZ POSTEST float'], true, $winOverride);
zz_check(zz_jok($r) && zz_money($r['json']['float_amount'] ?? 0, $float), 'set_float 10000', zz_err($r));
$r = zz_call('admin/pos.php', ['action' => 'close_shift', 'declared_cash' => (string)($float + $expCash), 'declared_mobile' => (string)$expMobile, 'declared_card' => (string)$expCard, 'shift_note' => 'ZZ POSTEST close'], true, $winOverride);
$sc = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart])[0] ?? null;
zz_check($sc !== null && $r['error'] === null, 'close_shift recorded a Z row', zz_err($r));
if ($sc) {
    zz_check(zz_money($sc['expected_cash'], $float + $expCash), "Expected drawer cash = float + cash sales - cash payouts = " . ($float + $expCash), $sc['expected_cash']);
    zz_check(zz_money($sc['expected_card'], $expCard), "Expected card = $expCard", $sc['expected_card']);
    zz_check(zz_money($sc['expected_mobile'], $expMobile), "Expected mobile = $expMobile (incl. 500 tip)", $sc['expected_mobile']);
    zz_check(zz_money($sc['variance_cash'], 0) && zz_money($sc['variance_card'], 0) && zz_money($sc['variance_mobile'], 0), 'Variance 0 on every tender');
    zz_check((int)$sc['orders_count'] === 7 && (int)$sc['voids_count'] === 0, 'Orders 7 (O1-O7 paid by this cashier), voids 0', ['orders' => $sc['orders_count'], 'voids' => $sc['voids_count']]);
    zz_check(zz_money($sc['total_revenue'] ?? 0, $expCash + $expCard + $expMobile), 'total_revenue = net takings across tenders', $sc['total_revenue'] ?? null);
    $audRaw = zz_one("SELECT details FROM stock_order_audit WHERE event = 'shift_closed' AND actor_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart]);
    $floatAud = (int)zz_one("SELECT COUNT(*) FROM stock_order_audit WHERE event = 'float_set' AND actor_id = ? AND created_at >= ?", [$ADMIN, $runStart]);
    zz_check($floatAud === 1, 'Float audit row written (stock_order_audit float_set, pos.php ~1102)', 'none: pos_logAudit(order_id 0) is rejected by FK fk_soa_order and swallowed');
    zz_check($audRaw !== false, 'Shift-close audit row written (stock_order_audit shift_closed, pos.php ~1068)', 'none: pos_logAudit(order_id 0) is rejected by FK fk_soa_order and swallowed');
    if ($audRaw === false || $floatAud !== 1) { $FAULTS[] = 'admin/pos.php:1068 (shift_closed) + :1102 (float_set): pos_logAudit($pdo, 0, ...) inserts stock_order_audit.order_id = 0, rejected by FK fk_soa_order (NOT NULL, references stock_orders) and swallowed in pos_logAudit - no Z / float audit row; pos-accounting.php and order-lifecycle.php never show them'; }
    $aud = json_decode((string)$audRaw, true) ?: [];
    if ($audRaw !== false) zz_check(zz_money($aud['tips_total'] ?? -1, 500) && zz_money($aud['refund_cash'] ?? -1, ($FP + 2 * $DP) + $FP + $DP) && zz_money($aud['opening_float'] ?? -1, $float), 'Audit: tips 500, cash refunds = O4 + REF86 O5 + O6, float 10000', $aud);
}

/* ======================================================================================
 * S7b. A sale after closing time and before the next opening counts with the day that ended
 *      (owner 2026-11-10). Station hours are shaped around "now" IN MEMORY only (this process and
 *      each child) - the real settings are never written. Runs after S7 (S7's figures untouched);
 *      this close starts at S7's close.
 * ====================================================================================== */
zz_section('S7b', 'Gap sale (after closing, before the next opening) lands in the Z of the day that ended');
$gapTz = rh_site_timezone();
$gapNow = new DateTimeImmutable('now', $gapTz);
$gapOpen = $gapNow->modify('+90 minutes')->format('H:i');
$gapClose = $gapNow->modify('-60 minutes')->format('H:i');
$gapHours = $zzHoursFor($gapOpen, $gapClose);
$zzHoursOn($gapHours);
zz_check(rh_station_hours('bar')['opens_at'] === $gapOpen && rh_station_hours('kitchen')['closes_at'] === $gapClose, "Station hours $gapOpen - $gapClose in memory (settings never written): now is after closing, before opening");
$gapMin = (int)$gapNow->format('H') * 60 + (int)$gapNow->format('i');
$expD = $gapMin < rh_station_minutes($gapOpen) ? $gapNow->modify('-1 day')->format('Y-m-d') : $gapNow->format('Y-m-d');
$tw = rh_station_union_trading_window();
$bw = rh_station_union_business_window();
$gapNowSql = (string)zz_one("SELECT NOW()");
zz_check($tw['is_open_now'] === false && $bw['is_open_now'] === false, 'Stations show closed (open-hours meaning unchanged)');
zz_check($tw['business_date'] === $expD && $tw['start_sql'] <= $gapNowSql && $gapNowSql < $tw['trading_end_sql'], "Trading day = $expD and holds now ($gapNowSql)", [$tw['business_date'], $tw['start_sql'], $tw['trading_end_sql']]);
zz_check(!($bw['start_sql'] <= $gapNowSql && $gapNowSql < $bw['end_sql']) && !($tw['start_sql'] <= $gapNowSql && $gapNowSql < $tw['end_sql']), 'Old open-hours window does NOT hold now (the fault being fixed)');
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O8 gap', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]), $gapHours);
$O8 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O8 gap']);
zz_check($O8 > 0 && (zz_order($O8)['status'] ?? '') === 'paid' && $r['error'] === null && empty($r['open_tx']), 'Till accepts the gap sale (place-and-pay a drink, cash)', zz_err($r));
require_once $__zzRoot . '/admin/includes/pos-shift-totals.php';
if ($O8 > 0) {
    zz_take_recipe(REC_D, 1);
    zz_stock_matches('Gap sale deducted the drink once');
    $p8 = zz_q("SELECT payment_date, receipt_number, total_amount FROM payments WHERE booking_type = 'restaurant' AND booking_id = ? AND COALESCE(payment_type,'') <> 'refund'", [$O8]);
    zz_check(count($p8) === 1 && substr((string)$p8[0]['payment_date'], 0, 10) === $expD && zz_money($p8[0]['total_amount'], $DP), "Ledger row dated the trading day $expD", $p8);
    $state['receipt_numbers'] = array_values(array_unique(array_merge($state['receipt_numbers'], array_filter(array_column($p8, 'receipt_number')))));
    $paid8 = (string)zz_one("SELECT paid_at FROM stock_orders WHERE id = ?", [$O8]);
    $eod = rh_pos_eod_summary($pdo, $expD);
    zz_check($eod['window_start'] <= $paid8 && $paid8 < $eod['window_end'] && $eod['window_end'] === $tw['trading_end_sql'], 'End-of-day POS window of the trading day holds the gap sale', [$paid8, $eod['window_start'], $eod['window_end']]);
    $r = zz_call('admin/pos.php', ['action' => 'close_shift', 'declared_cash' => (string)$DP, 'declared_mobile' => '0', 'declared_card' => '0', 'shift_note' => 'ZZ POSTEST gap close'], true, $gapHours);
    $sc8 = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND created_at >= ? AND notes LIKE ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart, '%gap close%'])[0] ?? null;
    zz_check($sc8 !== null && $r['error'] === null, 'close_shift (no override) recorded a Z row', zz_err($r));
    if ($sc8) {
        zz_check($sc8['shift_date'] === $expD, "Z dated the day that ended ($expD)", $sc8['shift_date']);
        zz_check(zz_money($sc8['expected_cash'], $DP) && zz_money($sc8['variance_cash'], 0) && zz_money($sc8['variance_mobile'], 0) && zz_money($sc8['variance_card'], 0), "Expected cash = the gap sale ($DP), drawer balances", [$sc8['expected_cash'], $sc8['variance_cash']]);
        zz_check((int)$sc8['orders_count'] === 1 && zz_money($sc8['total_revenue'] ?? 0, $DP), 'Z counts exactly the gap sale (starts at the S7 close)', ['orders' => $sc8['orders_count'], 'rev' => $sc8['total_revenue'] ?? null]);
        zz_check(zz_money(rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $sc8), $DP), 'Z rebuilt on read (shift-close report path) finds the same sale');
    }
}
$zzHoursOff($gapHours);

/* ======================================================================================
 * S7c. Every till sale lands in exactly one cashier close: close in the night, sale before the next
 *      opening, next close AFTER the opening -> the sale is in that next close, once. Hours (in
 *      memory) open at the next-but-one minute and close an hour before it; the test waits for the
 *      opening to pass between the sale and the second close.
 * ====================================================================================== */
zz_section('S7c', 'Close at night, sale before opening, close after opening: the sale is counted once, in the later close');
$cNow = new DateTimeImmutable((string)zz_one("SELECT NOW()"), $gapTz);
$cOpenAt = $cNow->setTime((int)$cNow->format('H'), (int)$cNow->format('i'))->modify('+2 minutes');
$cHours = $zzHoursFor($cOpenAt->format('H:i'), $cOpenAt->modify('-60 minutes')->format('H:i'));
$zzHoursOn($cHours);
$dA = rh_station_trading_business_date();
zz_check(rh_station_union_trading_window()['is_open_now'] === false, 'Night: stations closed (opening ' . $cOpenAt->format('H:i') . ', closing ' . $cOpenAt->modify('-60 minutes')->format('H:i') . ')');
$r = zz_call('admin/pos.php', ['action' => 'close_shift', 'declared_cash' => '0', 'declared_mobile' => '0', 'declared_card' => '0', 'shift_note' => 'ZZ POSTEST S7c close A'], true, $cHours);
$cA = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND created_at >= ? AND notes LIKE ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart, '%S7c close A%'])[0] ?? null;
zz_check($cA !== null && $r['error'] === null && $cA['shift_date'] === $dA && (int)$cA['orders_count'] === 0 && zz_money($cA['expected_cash'], 0), "Close A (night, $dA): nothing new since the S7b close, balances at 0", $cA ? [$cA['shift_date'], $cA['orders_count'], $cA['expected_cash']] : zz_err($r));
$r = zz_pos(['action' => 'pay', 'order_type' => 'walk_in', 'customer_name' => ZZ . ' O9 pre-open', 'payment_method' => 'cash', 'tendered_amount' => (string)$DP] + $cart([$dl(1)]), $cHours);
$O9 = (int)zz_one("SELECT id FROM stock_orders WHERE customer_name = ?", [ZZ . ' O9 pre-open']);
$paid9 = $O9 > 0 ? (string)zz_one("SELECT paid_at FROM stock_orders WHERE id = ?", [$O9]) : '';
$openSql = $cOpenAt->format('Y-m-d H:i:s');
zz_check($O9 > 0 && (zz_order($O9)['status'] ?? '') === 'paid' && $paid9 !== '' && $paid9 < $openSql && $paid9 >= (string)($cA['closed_at'] ?? ''), "Sale paid after close A and before the opening ($paid9 < $openSql)", zz_err($r));
if ($O9 > 0) {
    zz_take_recipe(REC_D, 1);
    $p9 = zz_q("SELECT receipt_number FROM payments WHERE booking_type = 'restaurant' AND booking_id = ?", [$O9]);
    $state['receipt_numbers'] = array_values(array_unique(array_merge($state['receipt_numbers'], array_filter(array_column($p9, 'receipt_number')))));
}
// Wait for the opening to pass on both clocks (DB NOW() and PHP), then close again.
$waited = 0;
while (($waited < 200) && ((string)zz_one("SELECT NOW()") < $cOpenAt->modify('+2 seconds')->format('Y-m-d H:i:s') || new DateTimeImmutable('now', $gapTz) < $cOpenAt->modify('+2 seconds'))) { sleep(5); $waited += 5; }
$dB = rh_station_trading_business_date();
$twB = rh_station_union_trading_window();
zz_check($dB !== $dA && $twB['start_sql'] === $openSql && $paid9 < $twB['start_sql'], "Opening passed (waited {$waited}s): new trading day $dB; the sale belongs to the previous one", [$dA, $dB, $twB['start_sql']]);
$r = zz_call('admin/pos.php', ['action' => 'close_shift', 'declared_cash' => (string)$DP, 'declared_mobile' => '0', 'declared_card' => '0', 'shift_note' => 'ZZ POSTEST S7c close B'], true, $cHours);
$cB = zz_q("SELECT * FROM stock_shift_closes WHERE user_id = ? AND created_at >= ? AND notes LIKE ? ORDER BY id DESC LIMIT 1", [$ADMIN, $runStart, '%S7c close B%'])[0] ?? null;
zz_check($cB !== null && $r['error'] === null && $cB['shift_date'] === $dB, "Close B recorded after the opening, dated $dB", $cB ? $cB['shift_date'] : zz_err($r));
if ($cB) {
    zz_check((int)$cB['orders_count'] === 1 && zz_money($cB['expected_cash'], $DP) && zz_money($cB['variance_cash'], 0) && zz_money($cB['total_revenue'] ?? 0, $DP), "Close B counts the pre-opening sale ($DP), drawer balances", [$cB['orders_count'], $cB['expected_cash'], $cB['variance_cash']]);
    $inA = rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $cA);
    $inB = rh_pos_close_revenue($pdo, ['total_revenue' => 0] + $cB);
    zz_check(zz_money($inA, 0) && zz_money($inB, $DP), 'Rebuilt on read: sale in close B only, not in close A (counted exactly once)', [$inA, $inB]);
}
$zzHoursOff($cHours);
zz_stock_matches('Stock = model after S7b / S7c');

echo "\nScenarios finished.\n";
exit;
