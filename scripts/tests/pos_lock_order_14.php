<?php
/**
 * Step 14 (2): lock order of a folio charge void against the station 86.
 *
 *   php scripts/tests/pos_lock_order_14.php --confirm-db
 *
 * The station 86 (api/kds-action.php void_item) locks stock_orders -> lines -> booking_charges -> bookings.
 * voidBookingCharge() used to lock the charge FIRST and the order later, so the two could wait on each other
 * in a circle (deadlock, rolled back). Now it locks the order first. This test proves it with real locks:
 *   connection A holds the order row (as an 86 in progress would), a child process runs voidBookingCharge on a
 *   charge of that order, and A then asks for the CHARGE row with NOWAIT. Old order: the child already holds
 *   the charge, so NOWAIT fails. New order: the child is queued on the order and has not touched the charge.
 *   The child gives up after a 3 s lock wait and rolls back (nothing voided).
 *
 * LIWONDE TEST HOTEL ONLY: refuses unless both the configured and the connected database are Liwonde.
 * Fixtures (a 'ZZ14' booking, a closed room-service order, one folio charge) are COMMITTED so a second
 * connection can lock them, then deleted by marker; AUTO_INCREMENTs go back to their start values.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const ZZ14_DB_SHA1 = '3b8976ab1ff31dab31141c7344d737ecc8725d41'; // sha1 of the Liwonde test database name
$_SERVER['HTTP_HOST'] = 'localhost'; // the web scheduler skips a local copy
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$isChild = ($argv[1] ?? '') === '--void-child';
if (!$isChild && !in_array('--confirm-db', $argv, true)) {
    fwrite(STDERR, "Refusing: this test commits (then removes) fixture rows at Liwonde. Re-run with --confirm-db.\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/config/database.php';
if (sha1((string)(defined('DB_NAME') ? DB_NAME : '')) !== ZZ14_DB_SHA1 || sha1((string)$pdo->query('SELECT DATABASE()')->fetchColumn()) !== ZZ14_DB_SHA1) {
    fwrite(STDERR, "Refused: not the Liwonde test database. Nothing was written.\n");
    exit(9);
}

/* ---------- child: void the charge with a short lock wait ---------- */
if ($isChild) {
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $t0 = microtime(true);
    $r = voidBookingCharge((int)($argv[2] ?? 0), 'ZZ14 lock test', null);
    echo json_encode(['result' => $r, 'seconds' => round(microtime(true) - $t0, 2)]), "\n";
    exit(0);
}

/* ---------- parent ---------- */
$pass = 0;
$fail = 0;
function zz14_check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $label\n"; } else { $fail++; echo "[FAIL] $label" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function zz14_conn(): PDO
{
    return new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
/** Delete every ZZ14 row (by marker) and put the AUTO_INCREMENTs back. */
function zz14_cleanup(PDO $db, array $ai): array
{
    $log = [];
    $bk = array_map('intval', $db->query("SELECT id FROM bookings WHERE guest_name = 'ZZ14 Lock Guest'")->fetchAll(PDO::FETCH_COLUMN));
    $ord = array_map('intval', $db->query("SELECT id FROM stock_orders WHERE reference LIKE 'ZZ14-%'")->fetchAll(PDO::FETCH_COLUMN));
    if ($bk) {
        $in = implode(',', $bk);
        foreach (['booking_charges', 'booking_audit_log', 'booking_timeline_logs', 'booking_financial_audit'] as $t) {
            try { $n = $db->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); if ($n) { $log[] = "$t -$n"; } } catch (Throwable $e) { /* table absent */ }
        }
    }
    if ($ord) {
        $in = implode(',', $ord);
        foreach (['stock_order_audit', 'stock_order_items'] as $t) {
            try { $n = $db->exec("DELETE FROM `$t` WHERE order_id IN ($in)"); if ($n) { $log[] = "$t -$n"; } } catch (Throwable $e) { /* table absent */ }
        }
        $n = $db->exec("DELETE FROM stock_orders WHERE id IN ($in)"); if ($n) { $log[] = "stock_orders -$n"; }
    }
    if ($bk) { $n = $db->exec('DELETE FROM bookings WHERE id IN (' . implode(',', $bk) . ')'); if ($n) { $log[] = "bookings -$n"; } }
    foreach ($ai as $t => $v) {
        if ($v > 0) { $db->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . (int)$v); } // InnoDB keeps it above MAX(id) by itself
    }
    return $log;
}

$aiTables = ['bookings', 'stock_orders', 'booking_charges'];
$aiStart = [];
$countStart = [];
$db = zz14_conn();
try { $db->exec('SET SESSION information_schema_stats_expiry = 0'); } catch (Throwable $e) { /* MariaDB */ }
foreach ($aiTables as $t) {
    $aiStart[$t] = (int)$db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($t))->fetchColumn();
    $countStart[$t] = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
if ((int)$db->query("SELECT COUNT(*) FROM bookings WHERE guest_name = 'ZZ14 Lock Guest'")->fetchColumn() > 0
    || (int)$db->query("SELECT COUNT(*) FROM stock_orders WHERE reference LIKE 'ZZ14-%'")->fetchColumn() > 0) {
    fwrite(STDERR, "Refusing: ZZ14 rows from an earlier run are still here - remove them first. Nothing changed.\n");
    exit(2);
}
register_shutdown_function(static function () use ($aiStart, $countStart, $aiTables) {
    global $pass, $fail;
    echo "\n--- Cleanup ---\n";
    try {
        $c = zz14_conn();
        foreach (zz14_cleanup($c, $aiStart) as $l) { echo "  $l\n"; }
        try { $c->exec('SET SESSION information_schema_stats_expiry = 0'); } catch (Throwable $e) { /* MariaDB */ }
        $diff = [];
        foreach ($aiTables as $t) {
            $n = (int)$c->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            if ($n !== $countStart[$t]) { $diff[] = "$t rows {$countStart[$t]}->$n"; }
            $ai = (int)$c->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $c->quote($t))->fetchColumn();
            if ($ai !== $aiStart[$t]) { $diff[] = "$t next id {$aiStart[$t]}->$ai"; }
        }
        zz14_check(!$diff, 'Liwonde clean after the run (rows and next ids as at the start)', implode('; ', $diff));
    } catch (Throwable $e) {
        zz14_check(false, 'cleanup ran', $e->getMessage());
    }
    echo "\n=== Result: $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
});

/* Fixtures (committed): booking, a CLOSED room-service order of that booking, one food charge linked to it. */
$adminId = (int)$db->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$room = (int)$db->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
$db->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)
              VALUES (?, ?, 'ZZ14 Lock Guest', 'zz14@example.invalid', '000', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 1, 1, 1, 0, 100, 100, 0, 100, 'checked-in')")
    ->execute(['ZZ14-' . random_int(100000, 999999), $room]);
$bookingId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO stock_orders (reference, order_type, booking_id, customer_name, status, total_amount, subtotal, kitchen_status, created_by, created_at)
              VALUES (?, 'room_service', ?, 'ZZ14 Lock Guest', 'completed', 100, 100, 'served', ?, NOW())")
    ->execute(['ZZ14-' . random_int(100000, 999999), $bookingId, $adminId ?: null]);
$orderId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO booking_charges (booking_id, stock_order_id, charge_type, source_item_id, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, added_by, voided, stock_tracked)
              VALUES (?, ?, 'food', NULL, 'ZZ14 lock test', 1, 100, 100, 0, 0, 100, ?, 0, 0)")
    ->execute([$bookingId, $orderId, $adminId ?: null]);
$chargeId = (int)$db->lastInsertId();
zz14_check($bookingId > 0 && $orderId > 0 && $chargeId > 0, "Fixtures committed: booking #$bookingId, closed order #$orderId, charge #$chargeId");

/* A holds the order row, as a station 86 on that order would. */
$a = zz14_conn();
$a->beginTransaction();
$a->prepare('SELECT id FROM stock_orders WHERE id = ? FOR UPDATE')->execute([$orderId]);

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --void-child ' . (int)$chargeId;
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
if (!is_resource($proc)) { zz14_check(false, 'child process started'); $a->rollBack(); exit; }
usleep(1200000); // the child is now inside voidBookingCharge, waiting on whatever it locks first

$chargeFree = false;
$probeErr = '';
try {
    $p = $a->prepare('SELECT id FROM booking_charges WHERE id = ? FOR UPDATE NOWAIT');
    $p->execute([$chargeId]);
    $chargeFree = (int)$p->fetchColumn() === $chargeId;
} catch (Throwable $e) {
    $probeErr = $e->getMessage();
}
zz14_check($chargeFree, 'While the order row is held, the folio void has NOT locked the charge (it queues on the order first, like the 86)', $probeErr);

$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
$a->rollBack();
$child = json_decode(trim((string)$out), true);
zz14_check(is_array($child) && empty($child['result']['success']), 'The waiting void gave up cleanly (lock wait, rolled back, reported as not done)', trim((string)$out . ' ' . $err));
$st = $db->prepare('SELECT voided FROM booking_charges WHERE id = ?');
$st->execute([$chargeId]);
zz14_check((int)$st->fetchColumn() === 0, 'Charge still not voided (nothing half-written)');
$st = $db->prepare('SELECT total_amount FROM stock_orders WHERE id = ?');
$st->execute([$orderId]);
zz14_check(abs((float)$st->fetchColumn() - 100.0) < 0.005, 'Order total unchanged');

/* Same void with nobody holding the order: it goes through (order lowered by the charge, charge voided). */
$r = voidBookingCharge($chargeId, 'ZZ14 lock test (free)', null);
zz14_check(!empty($r['success']), 'With the order free the void succeeds', json_encode($r));
$st = $db->prepare('SELECT voided FROM booking_charges WHERE id = ?');
$st->execute([$chargeId]);
zz14_check((int)$st->fetchColumn() === 1, 'Charge voided');
$st = $db->prepare('SELECT total_amount FROM stock_orders WHERE id = ?');
$st->execute([$orderId]);
zz14_check(abs((float)$st->fetchColumn()) < 0.005, 'Closed room-service order total lowered by the voided charge (step 9 B3 unchanged)');
