<?php
/**
 * Money linked-data test (.claude/plans/linked-data-cleanup.md, Accounting F2/F8 + no-show).
 * Usage: php scripts/tests/linked_money.php
 *
 * - cancel of a booking paid by credit note re-opens the note (never cash)
 * - mixed credit note + cash: note first, cash only for the cash share
 * - a voided note is not re-opened and nothing is paid out in cash for it
 * - cancel closes a still-pending payment
 * - no-show refund never pays credit-note money out in cash
 *
 * Everything runs inside ONE transaction that is rolled back at the end - nothing is left in the live
 * database. Settings are overridden in memory only ($_SITE_SETTINGS), never written.
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

// In-memory only: the refund rules under test, whatever the hotel has configured.
$GLOBALS['_SITE_SETTINGS']['cancellation_refund_mode'] = 'void_refund_all';
$GLOBALS['_SITE_SETTINGS']['no_show_refund_policy'] = 'full';

$seq = 0;
function mk_booking(PDO $pdo, string $run, int $typeId, string $in, string $out, string $tag): array {
    global $seq;
    $seq++;
    $ref = 'LM' . $run . $seq;
    $nights = max(1, (int)((strtotime($out) - strtotime($in)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,?,?,2,2,0,50,?,?,?,100,0,0,0,0,0,100,?,'confirmed','unpaid',0,'double',?,NOW())")
        ->execute([$ref, $typeId, 'MONEY TEST ' . $tag, 'linked-test@example.invalid', '+265000000009', 'Malawi', $in, $out, $nights, 'linked money test', bin2hex(random_bytes(16))]);
    return [(int)$pdo->lastInsertId(), $ref];
}
function mk_cn(PDO $pdo, string $run, float $original, float $used, string $status = ''): int {
    global $seq, $adminId;
    $seq++;
    $status = $status !== '' ? $status : ($used >= $original - BALANCE_TOLERANCE ? 'fully_applied' : 'partially_applied');
    $pdo->prepare("INSERT INTO credit_notes (credit_note_number, booking_type, guest_name, original_amount, amount_used, balance, reason, status, issued_by, issued_at, expires_at)
        VALUES (?, 'goodwill', 'MONEY TEST', ?, ?, ?, 'goodwill', ?, ?, NOW(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
        ->execute(['CNLM' . $run . $seq, $original, $used, round($original - $used, 2), $status, $adminId]);
    return (int)$pdo->lastInsertId();
}
function mk_pay(PDO $pdo, string $run, int $bookingId, string $bref, string $method, float $amount, string $status = 'completed', ?int $cnId = null): int {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, credit_note_id, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, CURDATE(), ?, 0, 0, ?, ?, 'full_payment', ?, ?, ?, ?, NOW())")
        ->execute(['PLM' . $run . $seq, $bookingId, $bref, $amount, $amount, $method, $status, $status === 'completed' ? 'completed' : 'pending', $cnId, $adminId]);
    $pid = (int)$pdo->lastInsertId();
    if ($cnId) {
        $pdo->prepare("INSERT INTO credit_note_applications (credit_note_id, payment_id, applied_to_booking_id, applied_to_booking_reference, applied_to_booking_type, amount_applied, applied_by, applied_at, notes)
            VALUES (?, ?, ?, ?, 'room', ?, ?, NOW(), 'linked money test')")->execute([$cnId, $pid, $bookingId, $bref, $amount, $adminId]);
    }
    recalculateBookingFinancials($bookingId);
    return $pid;
}
function refunds(PDO $pdo, int $bookingId): array {
    $s = $pdo->prepare("SELECT payment_method, total_amount, original_payment_id, refund_status FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_type = 'refund' AND deleted_at IS NULL");
    $s->execute([$bookingId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

$leakRef = '';
try {
    $pdo->beginTransaction();
    $future = date('Y-m-d', strtotime('+410 day')); $future2 = date('Y-m-d', strtotime('+411 day'));

    echo "\n--- F2: cancel of a booking paid by credit note re-opens the note ---\n";
    [$b1, $r1] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'cn');
    $leakRef = $r1;
    $cn1 = mk_cn($pdo, $run, 100, 100);
    $p1 = mk_pay($pdo, $run, $b1, $r1, 'credit_note', 100, 'completed', $cn1);
    $r = cancelRoomBookingSettled($pdo, $b1, $adminId, 'linked money test');
    check(!empty($r['success']), 'Cancel succeeds', (string)($r['error'] ?? ''));
    $cn = one($pdo, "SELECT amount_used, balance, status FROM credit_notes WHERE id = ?", [$cn1]);
    check(money_eq($cn['amount_used'], 0) && money_eq($cn['balance'], 100) && $cn['status'] === 'active', 'Note is back to 100 unused and active', json_encode($cn));
    $rf = refunds($pdo, $b1);
    check(count($rf) === 1 && $rf[0]['payment_method'] === 'credit_note' && money_eq($rf[0]['total_amount'], 100) && (int)$rf[0]['original_payment_id'] === $p1,
        'One refund row, method credit_note, against the note payment', json_encode($rf));
    check((int)col($pdo, "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND payment_type = 'refund' AND payment_method <> 'credit_note'", [$b1]) === 0, 'No cash refund');
    $neg = one($pdo, "SELECT amount_applied, payment_id FROM credit_note_applications WHERE credit_note_id = ? AND amount_applied < 0", [$cn1]);
    check($neg !== null && money_eq($neg['amount_applied'], -100) && (int)$neg['payment_id'] > 0, 'Negative reversal row linked to the refund', json_encode($neg));
    check(money_eq(col($pdo, "SELECT SUM(amount_applied) FROM credit_note_applications WHERE credit_note_id = ?", [$cn1]), 0), 'Applications net to zero');
    check((int)col($pdo, "SELECT COUNT(*) FROM payment_audit_log WHERE payment_id = ? AND action = 'refund_recorded' AND new_value LIKE '%credit note%'", [$p1]) === 1, 'Payment audit log records the refund');

    echo "\n--- F2: credit note + cash - note first, cash only for the cash share ---\n";
    [$b2, $r2] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'mixed');
    $cn2 = mk_cn($pdo, $run, 60, 60);
    mk_pay($pdo, $run, $b2, $r2, 'credit_note', 60, 'completed', $cn2);
    $cash2 = mk_pay($pdo, $run, $b2, $r2, 'cash', 40);
    $r = cancelRoomBookingSettled($pdo, $b2, $adminId, 'linked money test');
    check(!empty($r['success']), 'Cancel succeeds', (string)($r['error'] ?? ''));
    check(money_eq(col($pdo, "SELECT amount_used FROM credit_notes WHERE id = ?", [$cn2]), 0), 'Note share (60) returned to the note');
    $cashRf = array_values(array_filter(refunds($pdo, $b2), fn($x) => $x['payment_method'] !== 'credit_note'));
    check(count($cashRf) === 1 && $cashRf[0]['payment_method'] === 'cash' && money_eq($cashRf[0]['total_amount'], 40) && (int)$cashRf[0]['original_payment_id'] === $cash2,
        'Cash refund is exactly the 40 paid in cash', json_encode($cashRf));

    echo "\n--- F2: a voided note is not re-opened and is never paid out in cash ---\n";
    [$b3, $r3] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'voided');
    $cn3 = mk_cn($pdo, $run, 100, 100);
    mk_pay($pdo, $run, $b3, $r3, 'credit_note', 100, 'completed', $cn3);
    $pdo->prepare("UPDATE credit_notes SET status = 'voided', voided_at = NOW(), void_reason = 'linked money test' WHERE id = ?")->execute([$cn3]);
    $r = cancelRoomBookingSettled($pdo, $b3, $adminId, 'linked money test');
    check(!empty($r['success']), 'Cancel succeeds', (string)($r['error'] ?? ''));
    check(col($pdo, "SELECT status FROM credit_notes WHERE id = ?", [$cn3]) === 'voided' && money_eq(col($pdo, "SELECT amount_used FROM credit_notes WHERE id = ?", [$cn3]), 100), 'Voided note untouched');
    check(count(refunds($pdo, $b3)) === 0, 'No refund rows at all (stays as credit owed back)', json_encode(refunds($pdo, $b3)));

    echo "\n--- F8: cancel closes a payment that has not cleared ---\n";
    [$b4, $r4] = mk_booking($pdo, $run, (int)$type['id'], $future, $future2, 'pending');
    $p4 = mk_pay($pdo, $run, $b4, $r4, 'mobile_money', 100, 'pending');
    $r = cancelRoomBookingSettled($pdo, $b4, $adminId, 'linked money test');
    check(!empty($r['success']), 'Cancel succeeds', (string)($r['error'] ?? ''));
    $p = one($pdo, "SELECT payment_status, status, notes FROM payments WHERE id = ?", [$p4]);
    check($p['payment_status'] === 'cancelled' && $p['status'] === 'failed', 'Pending payment is cancelled', json_encode($p));
    check(stripos((string)$p['notes'], 'cancelled before this payment cleared') !== false, 'It says why');
    check(count(refunds($pdo, $b4)) === 0, 'Nothing refunded for money never received');

    echo "\n--- No-show refund never pays credit-note money out in cash ---\n";
    $past = date('Y-m-d', strtotime('-5 day')); $past2 = date('Y-m-d', strtotime('-4 day'));
    [$b5, $r5] = mk_booking($pdo, $run, (int)$type['id'], $past, $past2, 'noshow');
    $cn5 = mk_cn($pdo, $run, 60, 60);
    mk_pay($pdo, $run, $b5, $r5, 'credit_note', 60, 'completed', $cn5);
    $cash5 = mk_pay($pdo, $run, $b5, $r5, 'cash', 40);
    $alloc = allocateBookingRefunds($pdo, $b5, 100, false);
    check(count($alloc) === 1 && (int)$alloc[0]['payment']['id'] === $cash5 && money_eq($alloc[0]['amount'], 40), 'Cash refund allocation skips the credit-note payment', json_encode(array_map(fn($a) => [$a['payment']['id'], $a['amount']], $alloc)));
    $ns = rh_mark_booking_no_show($pdo, $b5, $adminId, 'Test', 'linked money test');
    check(!empty($ns['success']), 'No-show succeeds', (string)($ns['error'] ?? ''));
    $rf = refunds($pdo, $b5);
    $cnRf = array_filter($rf, fn($x) => $x['payment_method'] === 'credit_note');
    $total = array_sum(array_map(fn($x) => (float)$x['total_amount'], $rf));
    check(!$cnRf && money_eq($total, 40), 'Refund is the 40 cash only (policy full)', json_encode($rf));
    check(money_eq(col($pdo, "SELECT amount_used FROM credit_notes WHERE id = ?", [$cn5]), 60), 'Note share stays on the booking as credit owed back');
    check((int)col($pdo, "SELECT COUNT(*) FROM payment_audit_log WHERE payment_id = ? AND action = 'refund_recorded'", [$cash5]) === 1, 'Payment audit log records the no-show refund');

    echo "\n--- Payment audit log: only real changes are written ---\n";
    $n = rh_log_payment_audit($pdo, $cash5, 'PLMTEST', 'edited', [
        'total_amount' => ['40.00', 40.004],          // within tolerance: not a change
        'payment_method' => ['cash', 'cash'],
        'payment_status' => ['completed', 'paid'],
        'notes' => [null, 'fixed typo'],
    ], $adminId, 'Test');
    check($n === 2, 'Two changed fields -> two rows', (string)$n);
    $rows = $pdo->prepare("SELECT field_name, old_value, new_value, performed_by FROM payment_audit_log WHERE payment_id = ? AND action = 'edited' ORDER BY id");
    $rows->execute([$cash5]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 2 && $rows[0]['field_name'] === 'payment_status' && $rows[0]['old_value'] === 'completed' && $rows[0]['new_value'] === 'paid'
        && $rows[1]['field_name'] === 'notes' && $rows[1]['old_value'] === null && (int)$rows[0]['performed_by'] === $adminId, 'Rows hold field, before and after, and who', json_encode($rows));
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }

if ($leakRef !== '' && (int)col($pdo, "SELECT COUNT(*) FROM bookings WHERE booking_reference = ?", [$leakRef]) > 0) {
    bad('HARNESS LEAK - test booking survived the rollback', $leakRef);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
