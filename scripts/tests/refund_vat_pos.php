<?php
/**
 * Refund VAT split + POS partial refunds in revenue (accounting audit 2026-10-24, FIX-NOW 1, 3, 4).
 * Usage: php scripts/tests/refund_vat_pos.php
 *
 * 1. rh_refund_vat_split(): a refund reverses the ORIGINAL payment's own VAT / gross share (booking payments
 *    keep the nominal vat_rate but a vat_amount diluted by the tourism levy), full / partial / zero /
 *    negative, levy on and off, VAT-inclusive and VAT-exclusive payments, legacy row with no vat_amount.
 * 2. Every refund path writes that split: cancel, no-show, move to credit note, return to credit note,
 *    and the allocators hand the VAT column to their callers.
 * 3. Completed partial refunds on still-paid POS orders reduce End-of-Day earned revenue, the POS summary
 *    and the dashboard helper at refund_amount on the refund's own date; REF86 rows, pending rows and
 *    fully refunded orders are not counted again.
 *
 * Everything runs inside ONE transaction that is rolled back at the end - nothing is left in the live database.
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
require_once __DIR__ . '/../../admin/includes/pos-shift-totals.php';
require_once __DIR__ . '/../../admin/includes/eod-revenue.php';
require_once __DIR__ . '/../../includes/station-hours.php';

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
$GLOBALS['_SITE_SETTINGS']['no_show_refund_policy'] = 'full';

echo "\n--- rh_refund_vat_split: unit cases ---\n";
// Levy on: net 1,000,000 + levy 5% 50,000 + VAT 16.5% 165,000 = 1,215,000 (the payment row keeps rate 16.5, VAT 165,000).
$levyPay = ['vat_rate' => 16.5, 'vat_amount' => 165000.00, 'total_amount' => 1215000.00];
$s = rh_refund_vat_split($levyPay, 1215000.00);
check(money_eq($s['vat'], 165000.00) && money_eq($s['net'], 1050000.00) && money_eq($s['rate'], 16.5), 'Levy on, full refund: exactly 165,000 VAT reversed', json_encode($s));
$old = round(1215000.00 * (16.5 / 116.5), 2);
check(!money_eq($old, 165000.00), 'Sanity: the old rate/(100+rate) formula would have reversed ' . $old);
$s = rh_refund_vat_split($levyPay, 607500.00);
check(money_eq($s['vat'], 82500.00) && money_eq($s['net'], 525000.00), 'Levy on, half refund: 82,500 VAT', json_encode($s));
// Levy off: gross 1,165,000, VAT 165,000.
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'vat_amount' => 165000.00, 'total_amount' => 1165000.00], 1165000.00);
check(money_eq($s['vat'], 165000.00) && money_eq($s['net'], 1000000.00), 'Levy off, full refund: 165,000 VAT', json_encode($s));
// VAT-inclusive room price: gross 1,000,000 already contains VAT 141,630.04.
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'vat_amount' => 141630.04, 'total_amount' => 1000000.00], 250000.00);
check(money_eq($s['vat'], 35407.51) && money_eq($s['net'], 214592.49), 'VAT-inclusive payment, quarter refund: 35,407.51 VAT', json_encode($s));
// Payment with no VAT at all.
$s = rh_refund_vat_split(['vat_rate' => 0, 'vat_amount' => 0, 'total_amount' => 500.00], 500.00);
check(money_eq($s['vat'], 0) && money_eq($s['net'], 500.00), 'No-VAT payment: nothing to reverse', json_encode($s));
// A zero-VAT row that still carries a nominal rate keeps its own (zero) VAT.
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'vat_amount' => 0, 'total_amount' => 500.00], 500.00);
check(money_eq($s['vat'], 0), 'Own vat_amount 0 is respected even with a rate on the row', json_encode($s));
// Zero / negative refund.
$s = rh_refund_vat_split($levyPay, 0.0);
check(money_eq($s['vat'], 0) && money_eq($s['net'], 0), 'Zero refund: 0 VAT, 0 net', json_encode($s));
$s = rh_refund_vat_split($levyPay, -50.0);
check(money_eq($s['vat'], 0) && money_eq($s['net'], -50.0), 'Negative refund: no VAT invented', json_encode($s));
// Zero-gross original (no divide by zero) and legacy row without vat_amount (rate fallback).
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'vat_amount' => 10, 'total_amount' => 0], 100.0);
check(money_eq($s['vat'], 0), 'Original with no gross: 0 VAT, no error', json_encode($s));
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'total_amount' => 116.5], 116.5);
check(money_eq($s['vat'], 16.5), 'Legacy row with no vat_amount falls back to the rate', json_encode($s));
// Never more VAT than the refund.
$s = rh_refund_vat_split(['vat_rate' => 16.5, 'vat_amount' => 2000, 'total_amount' => 1000], 100.0);
check($s['vat'] <= 100.0 && $s['net'] >= 0, 'VAT is capped at the refund', json_encode($s));

$seq = 0;
function mk_booking(PDO $pdo, string $run, int $typeId, string $in, string $out, string $tag): array {
    global $seq;
    $seq++;
    $ref = 'RV' . $run . $seq;
    $nights = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,?,1050000,0,50000,5,16.5,165000,1215000,?,'confirmed','unpaid',0,'double',?,NOW())")
        ->execute([$ref, $typeId, 'REFUND VAT TEST ' . $tag, 'refund-vat-test@example.invalid', '+265000000009', 'Malawi', $in, $out, $nights, 'refund vat test', bin2hex(random_bytes(16))]);
    return [(int)$pdo->lastInsertId(), $ref];
}
function mk_pay(PDO $pdo, string $run, int $bookingId, string $bref, string $method, float $total, float $vat, float $rate = 16.5, ?int $cnId = null): int {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, credit_note_id, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'full_payment', 'completed', 'completed', ?, ?, NOW())")
        ->execute(['PRV' . $run . $seq, $bookingId, $bref, round($total - $vat, 2), $rate, $vat, $total, $method, $cnId, $adminId]);
    $pid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bookingId);
    return $pid;
}
function mk_cn(PDO $pdo, string $run, float $original, float $used): int {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO credit_notes (credit_note_number, booking_type, guest_name, original_amount, amount_used, balance, reason, status, issued_by, issued_at, expires_at)
        VALUES (?, 'goodwill', 'REFUND VAT TEST', ?, ?, ?, 'goodwill', ?, ?, NOW(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
        ->execute(['CNRV' . $run . $seq, $original, $used, round($original - $used, 2), $used >= $original - BALANCE_TOLERANCE ? 'fully_applied' : 'partially_applied', $adminId]);
    return (int)$pdo->lastInsertId();
}
function refunds(PDO $pdo, int $bookingId): array {
    $s = $pdo->prepare("SELECT payment_method, total_amount, vat_amount, payment_amount, vat_rate, refund_amount FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_type = 'refund' AND deleted_at IS NULL ORDER BY id");
    $s->execute([$bookingId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

$leakRef = '';
try {
    $pdo->beginTransaction();
    $future = date('Y-m-d', strtotime('+420 day')); $future2 = date('Y-m-d', strtotime('+421 day'));

    echo "\n--- Cancel with tourism levy on: full refund reverses exactly 165,000 VAT ---\n";
    [$b1, $r1] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'cancel');
    $leakRef = $r1;
    $p1 = mk_pay($pdo, $run, $b1, $r1, 'cash', 1215000.00, 165000.00);
    $res = cancelRoomBookingSettled($pdo, $b1, $adminId, 'refund vat test');
    check(!empty($res['success']), 'Cancel succeeds', (string)($res['error'] ?? ''));
    $rf = refunds($pdo, $b1);
    check(count($rf) === 1 && money_eq($rf[0]['total_amount'], 1215000.00) && money_eq($rf[0]['refund_amount'], 1215000.00), 'Refund pays back the full 1,215,000 (VAT and levy included)', json_encode($rf));
    check(count($rf) === 1 && money_eq($rf[0]['vat_amount'], 165000.00) && money_eq($rf[0]['payment_amount'], 1050000.00) && money_eq($rf[0]['vat_rate'], 16.5), 'VAT line reversed is exactly 165,000, net 1,050,000, rate kept', json_encode($rf));

    echo "\n--- Cancel with levy off ---\n";
    [$b2, $r2] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'nolevy');
    mk_pay($pdo, $run, $b2, $r2, 'credit_card', 1165000.00, 165000.00);
    $res = cancelRoomBookingSettled($pdo, $b2, $adminId, 'refund vat test');
    $rf = refunds($pdo, $b2);
    check(!empty($res['success']) && count($rf) === 1 && money_eq($rf[0]['vat_amount'], 165000.00) && money_eq($rf[0]['total_amount'], 1165000.00), 'Levy off: 165,000 VAT on a 1,165,000 refund', json_encode($rf));

    echo "\n--- Two payments, one levy and one not: each leg reverses its own VAT ---\n";
    [$b3, $r3] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'two');
    $pa = mk_pay($pdo, $run, $b3, $r3, 'cash', 1215000.00, 165000.00);
    $pb = mk_pay($pdo, $run, $b3, $r3, 'credit_card', 500000.00, 0.00, 0.0);
    $res = cancelRoomBookingSettled($pdo, $b3, $adminId, 'refund vat test');
    $rf = refunds($pdo, $b3);
    $vatSum = array_sum(array_map(fn($x) => (float)$x['vat_amount'], $rf));
    $totSum = array_sum(array_map(fn($x) => (float)$x['total_amount'], $rf));
    check(count($rf) === 2 && money_eq($vatSum, 165000.00) && money_eq($totSum, 1715000.00), 'Total VAT reversed 165,000 on 1,715,000 refunded', json_encode($rf));

    echo "\n--- No-show refund with levy on ---\n";
    $past = date('Y-m-d', strtotime('-6 day')); $past2 = date('Y-m-d', strtotime('-5 day'));
    [$b4, $r4] = mk_booking($pdo, $run, (int)$type['id'], $past, $past2, 'noshow');
    mk_pay($pdo, $run, $b4, $r4, 'cash', 1215000.00, 165000.00);
    $ns = rh_mark_booking_no_show($pdo, $b4, $adminId, 'Test', 'refund vat test');
    check(!empty($ns['success']), 'No-show succeeds', (string)($ns['error'] ?? ''));
    $rf = refunds($pdo, $b4);
    check(count($rf) === 1 && money_eq($rf[0]['total_amount'], 1215000.00) && money_eq($rf[0]['vat_amount'], 165000.00), 'No-show refund reverses 165,000 VAT', json_encode($rf));

    echo "\n--- Allocators carry vat_amount; partial early-checkout style refund; move to credit note ---\n";
    [$b5, $r5] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'alloc');
    $p5 = mk_pay($pdo, $run, $b5, $r5, 'cash', 1215000.00, 165000.00);
    $alloc = allocateBookingRefunds($pdo, $b5, 607500.00, true);
    check(count($alloc) === 1 && array_key_exists('vat_amount', $alloc[0]['payment']) && money_eq($alloc[0]['payment']['vat_amount'], 165000.00), 'allocateBookingRefunds returns the payment VAT', json_encode($alloc[0]['payment'] ?? []));
    $sp = rh_refund_vat_split($alloc[0]['payment'], (float)$alloc[0]['amount']);
    check(money_eq($sp['vat'], 82500.00), 'Partial refund through the allocator: 82,500 VAT', json_encode($sp));
    $cnId = mk_cn($pdo, $run, 607500.00, 0);
    $mv = recordCreditNoteRefundLegs($pdo, $b5, $r5, 607500.00, $cnId, 'CNRV' . $run, $adminId, 'overpayment');
    $rf = refunds($pdo, $b5);
    check(money_eq($mv['total'], 607500.00) && count($rf) === 1 && money_eq($rf[0]['vat_amount'], 82500.00) && money_eq($rf[0]['total_amount'], 607500.00), 'Moving 607,500 into a credit note reverses 82,500 VAT', json_encode($rf));

    echo "\n--- Return to credit note (payment made by credit note) ---\n";
    [$b6, $r6] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'cnpay');
    $cn6 = mk_cn($pdo, $run, 1215000.00, 1215000.00);
    mk_pay($pdo, $run, $b6, $r6, 'credit_note', 1215000.00, 165000.00, 16.5, $cn6);
    $res = cancelRoomBookingSettled($pdo, $b6, $adminId, 'refund vat test');
    $rf = refunds($pdo, $b6);
    check(!empty($res['success']) && count($rf) === 1 && money_eq($rf[0]['vat_amount'], 165000.00) && money_eq($rf[0]['total_amount'], 1215000.00), 'Credit-note payment returned in full reverses 165,000 VAT', json_encode($rf));

    echo "\n--- Account-level (conference / gym / event) allocator carries vat_amount ---\n";
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, recorded_by, created_at)
        VALUES (?, 'conference', 987654321, 'RVCONF', CURDATE(), 1000, 16.5, 165, 1165, 'cash', 'full_payment', 'completed', 'completed', ?, NOW())")
        ->execute(['PRVC' . $run . $seq, $adminId]);
    $ca = rh_allocate_account_refunds($pdo, 'conference', 987654321, 582.50, true);
    $csp = $ca ? rh_refund_vat_split($ca[0]['payment'], (float)$ca[0]['amount']) : ['vat' => -1];
    check(count($ca) === 1 && money_eq($csp['vat'], 82.50), 'Account allocator hands over vat_amount: half of 1,165 reverses 82.50 VAT', json_encode($ca[0]['payment'] ?? []));

    echo "\n--- POS: partial refunds on still-paid orders in earned revenue / POS summary ---\n";
    $day = '2037-06-10';
    $w = rh_station_union_window_for_date($day);
    $at = date('Y-m-d H:i:s', strtotime($w['start_sql']) + 60);
    $outside = date('Y-m-d H:i:s', strtotime($w['end_sql']) + 3600);
    $base = rh_pos_eod_summary($pdo, $day);
    if (!check((int)$base['totals']['orders'] === 0 && money_eq($base['totals']['gross'], 0), 'Fixture: the test business date is empty', json_encode($base['totals']))) {
        throw new RuntimeException('test date not empty');
    }
    $mkOrder = function (string $tag, string $status, float $total, ?string $refundedAt = null) use ($pdo, $run, $adminId, $at): int {
        $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, kitchen_status, created_by, paid_by, paid_at, refunded_at, payment_method, created_at)
            VALUES (?, 'walk_in', 'REFUND VAT TEST', ?, ?, ?, 'served', ?, ?, ?, ?, 'cash', ?)")
            ->execute(["RVP$run$tag", $status, $total, $total, $adminId, $adminId, $at, $refundedAt, $at]);
        return (int)$pdo->lastInsertId();
    };
    $mkRefund = function (int $oid, string $ref, float $amt, string $status, string $when, ?string $processed) use ($pdo, $run, $adminId): void {
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, status, refund_reason, refund_status, refund_amount, refund_date_processed, recorded_by, created_at)
            VALUES (?, 'restaurant', ?, ?, ?, ?, 16.5, ?, ?, 'cash', 'refund', ?, ?, 'service_issue', ?, ?, ?, ?, ?)")
            ->execute([$ref, $oid, "RVP$run", substr($when, 0, 10), round($amt / 1.165, 2), round($amt - $amt / 1.165, 2), $amt,
                $status === 'completed' ? 'completed' : 'pending', $status === 'completed' ? 'completed' : 'pending', $status, $amt, $processed, $adminId, $when]);
    };
    $o1 = $mkOrder('A', 'paid', 100.0);                       // partial refund 40 completed -> counts
    $mkRefund($o1, "RVR$run-A", 40.0, 'completed', $at, $at);
    $o2 = $mkOrder('B', 'paid', 50.0);                        // 86 refund 10 completed -> never counted
    $mkRefund($o2, "REF86-RVB$run", 10.0, 'completed', $at, $at);
    $o3 = $mkOrder('C', 'refunded', 80.0, $at);               // refunded in full -> counted ONCE at 80
    $mkRefund($o3, "RVR$run-C", 80.0, 'completed', $at, $at);
    $o4 = $mkOrder('D', 'paid', 30.0);                        // pending partial refund -> not paid out yet
    $mkRefund($o4, "RVR$run-D", 10.0, 'pending', $at, null);
    $o5 = $mkOrder('E', 'paid', 20.0);                        // refund settled in a later window -> not on this day
    $mkRefund($o5, "RVR$run-E", 5.0, 'completed', $outside, $outside);

    $pr = rh_pos_partial_refunds($pdo, $w['start_sql'], $w['end_sql']);
    check(money_eq($pr['amount'], 40.0) && $pr['orders'] === 1 && money_eq($pr['by_type']['walk_in'] ?? 0, 40.0), 'Partial-refund helper: only the completed non-86 row on a paid order, 40', json_encode($pr));
    $none = rh_pos_partial_refunds($pdo, $w['end_sql'], date('Y-m-d H:i:s', strtotime($w['end_sql']) + 86400));
    check(money_eq($none['amount'], 5.0), 'Helper: the later refund shows in its own window (5), not on this day', json_encode($none));
    $empty = rh_pos_partial_refunds($pdo, '2038-01-01 00:00:00', '2038-01-02 00:00:00');
    check(money_eq($empty['amount'], 0) && $empty['orders'] === 0 && $empty['by_type'] === [], 'Helper: empty period gives zeros, no error', json_encode($empty));

    $earned = rh_eod_earned_revenue($pdo, $day);
    // Sales 100 + 50 + 80 + 30 + 20 = 280. Reversed: refunded order 80 + partial 40 = 120. Net 160.
    check(money_eq($earned['pos_sales_gross'], 280.0), 'Earned: POS sales gross 280', json_encode($earned['pos_sales_gross']));
    check(money_eq($earned['pos_reversed'], 120.0), 'Earned: reversed = fully refunded 80 + partial 40 (REF86, pending and later-dated rows left out, no double count)', json_encode($earned['pos_reversed']));
    check(money_eq($earned['pos_net'], 160.0) && money_eq($earned['fnb_gross'], 160.0), 'Earned: POS net and F&B revenue 160', json_encode([$earned['pos_net'], $earned['fnb_gross']]));

    $sum = rh_pos_eod_summary($pdo, $day);
    $standing = 100.0 + 50.0 + 30.0 + 20.0 - 40.0;            // paid orders less the partial refund
    $expectNet = rh_pos_margin_basis($standing, 0.0)['net_ex_vat'];
    check(money_eq($sum['totals']['net_ex_vat'], $expectNet), 'POS summary: standing sales less the partial refund (' . $standing . ' on the VAT basis)', json_encode($sum['totals']));
    check(money_eq($sum['totals']['refunded_value'], 120.0) && (int)$sum['totals']['refunded_count'] === 2, 'POS summary: refunds paid out 120 over 2 orders (80 refunded order + 40 partial)', json_encode([$sum['totals']['refunded_value'], $sum['totals']['refunded_count']]));

    echo "\n--- POS accounting: net takings uses one basis (tips in, both sides) ---\n";
    $tot = rh_pos_shift_totals($pdo, null, $w['start_sql'], $w['end_sql'], $w['start_sql']);
    $netTakings = round((float)$tot['cash'] + (float)$tot['mobile'] + (float)$tot['card'], 2);
    $grossTakings = round((float)$tot['gross_cash'] + (float)$tot['gross_mobile'] + (float)$tot['gross_card'], 2);
    check(money_eq($netTakings, $grossTakings - (float)$tot['refund_amount']), 'Net takings = takings taken - refunds and voids paid out, both with tips', json_encode([$netTakings, $grossTakings, $tot['refund_amount']]));

    echo "\n--- Shift payouts: part refund in an earlier shift, rest refunded in a later one (no double payout) ---\n";
    $d1 = '2037-06-11'; $d2 = '2037-06-12';
    $w1 = rh_station_union_window_for_date($d1); $w2 = rh_station_union_window_for_date($d2);
    $t1 = date('Y-m-d H:i:s', strtotime($w1['start_sql']) + 60); $t2 = date('Y-m-d H:i:s', strtotime($w2['start_sql']) + 60);
    $pdo->prepare("INSERT INTO stock_orders (reference, order_type, customer_name, status, total_amount, subtotal, tip_amount, kitchen_status, created_by, paid_by, paid_at, payment_method, created_at)
        VALUES (?, 'walk_in', 'REFUND VAT TEST', 'paid', 8000, 8000, 500, 'served', ?, ?, ?, 'cash', ?)")
        ->execute(["RVS$run", $adminId, $adminId, $t1, $t1]);
    $oS = (int)$pdo->lastInsertId();
    $mkRefund($oS, "RVR$run-S1", 3000.0, 'completed', $t1, $t1);                 // part refund paid out in shift 1
    $payoutFor = static function (array $rows, int $oid): float { $s = 0.0; foreach ($rows as $r) { if ((int)$r['id'] === $oid) { $s += $r['total']; } } return round($s, 2); };
    $early = $payoutFor(rh_pos_shift_reversals($pdo, $w1['start_sql'], $w1['end_sql']), $oS);
    check(money_eq($early, 3000.0), 'Earlier shift pays out the 3000 part refund', (string)$early);
    $mkRefund($oS, "RVR$run-S2", 5000.0, 'completed', $t2, $t2);                 // the rest, next shift
    $pdo->prepare("UPDATE stock_orders SET status = 'refunded', refunded_at = ?, refund_reason = 'test' WHERE id = ?")->execute([$t2, $oS]);
    $late = $payoutFor(rh_pos_shift_reversals($pdo, $w2['start_sql'], $w2['end_sql']), $oS);
    check(money_eq($late, 5500.0), 'Later shift pays out only the 5000 left + 500 tip, not the 3000 already paid', (string)$late);
    check(money_eq($early + $late, 8500.0), 'Both shifts together pay out 8000 + 500 tip once', (string)($early + $late));
    $tot2 = rh_pos_shift_totals($pdo, $adminId, $w2['start_sql'], $w2['end_sql'], $w2['start_sql']);
    check(money_eq($tot2['refund_cash'], 5500.0), 'Shift totals (Z) refund_cash for the later window = 5500', (string)$tot2['refund_cash']);
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }

if ($leakRef !== '' && (int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE booking_reference = ?", [$leakRef]) > 0) {
    bad('HARNESS LEAK - test booking survived the rollback', $leakRef);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
