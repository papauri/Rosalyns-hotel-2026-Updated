<?php
/**
 * Tourism levy figure follows the money (accounting audit 2026-10-24, G2).
 * Usage: php scripts/tests/levy_after_refunds.php
 *
 * rh_tourism_levy_for_period(): levy on bookings created in the period, minus the levy share of completed /
 * processing refunds; cancelled / no-show bookings contribute the levy share of the money they kept.
 * Booking: net 1,050,000 + levy 50,000 + VAT 165,000 = gross 1,215,000. Each case sits on its own far-past
 * created_at day so live data never mixes in. Everything runs in ONE transaction that is rolled back.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/credit-notes.php';
require_once __DIR__ . '/../../includes/booking-functions.php';
require_once __DIR__ . '/../../includes/booking-timeline.php';
require_once __DIR__ . '/../../includes/finance-sequences.php';
require_once __DIR__ . '/../../admin/includes/finance-account-sync.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function one(PDO $pdo, string $sql, array $p = []): ?array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function money_eq($a, float $b): bool { return abs((float)$a - $b) <= BALANCE_TOLERANCE; }

$run = strtoupper(bin2hex(random_bytes(3)));
$admin = one($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1");
$adminId = (int)($admin['id'] ?? 0);
$type = one($pdo, "SELECT r.id FROM rooms r WHERE EXISTS (SELECT 1 FROM individual_rooms ir WHERE ir.room_type_id = r.id) ORDER BY r.id LIMIT 1");
if (!$adminId || !$type) { bad('Fixture: an admin and a room type exist'); echo "\n0 passed, 1 failed\n"; exit(1); }

$GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = 'void_refund_all';

$seq = 0;
function mk_booking(PDO $pdo, string $run, int $typeId, string $in, string $out, string $day): array {
    global $seq;
    $seq++;
    $ref = 'LV' . $run . $seq;
    $nights = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,?,1050000,0,50000,5,16.5,165000,1215000,?,'confirmed','unpaid',0,'double',?,?)")
        ->execute([$ref, $typeId, 'LEVY TEST', 'levy-test@example.invalid', '+265000000008', 'Malawi', $in, $out, $nights, 'levy test', bin2hex(random_bytes(16)), $day . ' 10:00:00']);
    return [(int)$pdo->lastInsertId(), $ref];
}
function mk_pay(PDO $pdo, string $run, int $bookingId, string $bref, float $total, float $vat = 165000.00): int {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, CURDATE(), ?, 16.5, ?, ?, 'cash', 'full_payment', 'completed', 'completed', ?, NOW())")
        ->execute(['PLV' . $run . $seq, $bookingId, $bref, round($total - $vat, 2), $vat, $total, $adminId]);
    recalculateBookingFinancials($bookingId);
    return (int)$pdo->lastInsertId();
}
function mk_refund(PDO $pdo, string $run, int $bookingId, string $bref, float $total, string $refundStatus): void {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, refund_reason, refund_status, refund_amount, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, CURDATE(), ?, 16.5, 0, ?, 'cash', 'refund', 'completed', 'completed', 'cancellation', ?, ?, ?, NOW())")
        ->execute(['RLV' . $run . $seq, $bookingId, $bref, $total, $total, $refundStatus, $total, $adminId]);
    recalculateBookingFinancials($bookingId);
}
function levy(PDO $pdo, string $day): float { return rh_tourism_levy_for_period($pdo, $day, $day); }

$leakRef = '';
try {
    $pdo->beginTransaction();
    $t = (int)$type['id'];
    $future = date('Y-m-d', strtotime('+430 day')); $future2 = date('Y-m-d', strtotime('+431 day')); $future3 = date('Y-m-d', strtotime('+432 day'));

    echo "\n--- Untouched booking keeps its full levy ---\n";
    $d = '2003-02-01';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    $leakRef = $r;
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    check(money_eq(levy($pdo, $d), 50000.00), 'Paid booking, no refund: levy 50,000', (string)levy($pdo, $d));

    echo "\n--- Goodwill refund of 243,000 on 1,215,000 ---\n";
    $d = '2003-02-02';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    mk_refund($pdo, $run, $b, $r, 243000.00, 'completed');
    check(money_eq(levy($pdo, $d), 40000.00), 'Completed goodwill refund 243,000: levy 40,000', (string)levy($pdo, $d));
    mk_refund($pdo, $run, $b, $r, 243000.00, 'processing');
    check(money_eq(levy($pdo, $d), 30000.00), 'A processing refund counts too (same rule as net paid): levy 30,000', (string)levy($pdo, $d));
    mk_refund($pdo, $run, $b, $r, 243000.00, 'pending');
    mk_refund($pdo, $run, $b, $r, 243000.00, 'failed');
    check(money_eq(levy($pdo, $d), 30000.00), 'Pending and failed refunds are not counted', (string)levy($pdo, $d));

    echo "\n--- Full refund on a live booking ---\n";
    $d = '2003-02-03';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    mk_refund($pdo, $run, $b, $r, 1215000.00, 'completed');
    check(money_eq(levy($pdo, $d), 0.00), 'Fully refunded booking: levy 0', (string)levy($pdo, $d));
    mk_refund($pdo, $run, $b, $r, 100000.00, 'completed');
    check(levy($pdo, $d) >= 0.0 && money_eq(levy($pdo, $d), 0.00), 'Refunds beyond the gross never make the levy negative', (string)levy($pdo, $d));

    echo "\n--- Cancel with refund of everything: levy 0 ---\n";
    $d = '2003-02-04';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    $res = cancelRoomBookingSettled($pdo, $b, $adminId, 'levy test');
    check(!empty($res['success']), 'Cancel succeeds', (string)($res['error'] ?? ''));
    check(money_eq(levy($pdo, $d), 0.00), 'Cancelled and fully refunded: levy 0', (string)levy($pdo, $d));

    echo "\n--- Cancel, keep first night of two ---\n";
    $GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = 'keep_first_night';
    $d = '2003-02-05';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future3, $d);
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    $res = cancelRoomBookingSettled($pdo, $b, $adminId, 'levy test');
    check(!empty($res['success']), 'Cancel (keep first night) succeeds', (string)($res['error'] ?? ''));
    $kept = (float)col($pdo, "SELECT amount_paid FROM bookings WHERE id = ?", [$b]);
    $expect = round(50000.00 * $kept / 1215000.00, 2);
    check($kept > BALANCE_TOLERANCE && $kept < 1215000.00 - BALANCE_TOLERANCE, 'First night kept, the rest refunded', 'kept ' . $kept);
    check(money_eq(levy($pdo, $d), $expect) && $expect > 0, 'Kept night keeps its levy share (' . $expect . '), not 0 and not the full 50,000', (string)levy($pdo, $d));
    check(money_eq(levy($pdo, $d), 25000.00), 'Two equal nights: 25,000 levy kept', (string)levy($pdo, $d));
    $GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = 'void_refund_all';

    echo "\n--- Cancelled with nothing paid, expired, levy off, empty period ---\n";
    $d = '2003-02-06';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$b]);
    check(money_eq(levy($pdo, $d), 0.00), 'Cancelled, never paid: levy 0', (string)levy($pdo, $d));
    $d = '2003-02-07';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    mk_pay($pdo, $run, $b, $r, 1215000.00);
    $pdo->prepare("UPDATE bookings SET status = 'expired' WHERE id = ?")->execute([$b]);
    check(money_eq(levy($pdo, $d), 0.00), 'Expired booking: levy 0', (string)levy($pdo, $d));
    $d = '2003-02-08';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    $pdo->prepare("UPDATE bookings SET tourism_levy_amount = 0, tourism_levy_percent = 0, total_amount = 1000000, total_with_vat = 1165000 WHERE id = ?")->execute([$b]);
    mk_pay($pdo, $run, $b, $r, 1165000.00);
    mk_refund($pdo, $run, $b, $r, 100000.00, 'completed');
    check(money_eq(levy($pdo, $d), 0.00), 'Levy off (no levy on the booking): 0, refund or not', (string)levy($pdo, $d));
    $d = '2003-02-09';
    [$b, $r] = mk_booking($pdo, $run, $t, $future, $future2, $d);
    $pdo->prepare("UPDATE bookings SET total_with_vat = 0 WHERE id = ?")->execute([$b]);
    check(money_eq(levy($pdo, $d), 0.00), 'Booking with no gross: 0, no divide by zero', (string)levy($pdo, $d));
    check(money_eq(levy($pdo, '1999-01-01'), 0.00), 'Empty period: 0', (string)levy($pdo, '1999-01-01'));
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }

if ($leakRef !== '' && (int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE booking_reference = ?", [$leakRef]) > 0) {
    bad('HARNESS LEAK - test booking survived the rollback', $leakRef);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
