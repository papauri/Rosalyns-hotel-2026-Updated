<?php
/**
 * Gym member cancel linked-data test (.claude/plans/linked-data-cleanup.md, owner decision 2026-10-20 item 5).
 * Usage: php scripts/tests/linked_gym.php
 *
 * - cancelling a member voids the UNPAID gym bill (nothing paid -> account cancelled, due 0)
 * - a part-paid bill is cancelled too; the paid money is credit owed back; the payment row is never touched
 * - a desk renewal bill tied by the [member:NUMBER] marker is voided too; a fully paid bill is left alone
 * - a still-pending payment on a voided bill is closed; a second run clears nothing
 * - suspend / expire do not clear the bill (source check on admin/gym-members.php)
 * - future class enrolments and slot reservations are released
 *
 * Runs the same helpers, in the same order, that the member_status action uses, inside ONE transaction
 * that is rolled back at the end - nothing is left in the live database.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../admin/includes/gym-billing-lib.php';
require_once __DIR__ . '/../../admin/includes/gym-classes-lib.php';
require_once __DIR__ . '/../../admin/includes/gym-schedule-lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function one(PDO $pdo, string $sql, array $p = []): ?array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function money_eq($a, float $b): bool { return abs((float)$a - $b) <= BALANCE_TOLERANCE; }

$run = strtoupper(bin2hex(random_bytes(3)));
$adminId = (int)(one($pdo, "SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
if (!$adminId) { bad('Fixture: an admin exists'); echo "\n0 passed, 1 failed\n"; exit(1); }

// The lazily created gym tables are made before the transaction (CREATE TABLE commits implicitly), as the page does.
gymClassesEnsureTables($pdo);
gymScheduleEnsureTables($pdo);

$seq = 0;
function mk_member(PDO $pdo, string $run, ?int $inquiryId): array {
    global $seq;
    $seq++;
    $num = 'LG' . $run . 'M' . $seq;
    $pdo->prepare("INSERT INTO gym_members (member_number, full_name, start_date, expiry_date, status, monthly_fee, gym_inquiry_id, membership_type)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active', 1175, ?, 'Test')")
        ->execute([$num, 'GYM LINK TEST ' . $seq, $inquiryId]);
    return [(int)$pdo->lastInsertId(), $num];
}
function mk_bill(PDO $pdo, string $run, float $net, float $vat, string $notes = '', string $status = 'converted'): int {
    global $seq;
    $seq++;
    $pdo->prepare("INSERT INTO gym_inquiries (reference_number, name, email, phone, status, total_amount, vat_rate, vat_amount, total_with_vat, amount_paid, amount_due, payment_status, consent, notes)
        VALUES (?, 'GYM LINK TEST', 'linked-test@example.invalid', '+265000000009', ?, ?, 17.5, ?, ?, 0, ?, 'pending', 0, ?)")
        ->execute(['LG' . $run . 'B' . $seq, $status, $net, $vat, $net + $vat, $net + $vat, $notes]);
    return (int)$pdo->lastInsertId();
}
function mk_pay(PDO $pdo, string $run, int $inquiryId, float $amount, string $status = 'completed'): int {
    global $seq, $adminId;
    $seq++;
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, recorded_by, created_at)
        VALUES (?, 'gym', ?, 'LGTEST', CURDATE(), ?, 0, 0, ?, 'cash', 'full_payment', ?, ?, ?, NOW())")
        ->execute(['PLG' . $run . $seq, $inquiryId, $amount, $amount, $status, $status === 'completed' ? 'completed' : 'pending', $adminId]);
    $pid = (int)$pdo->lastInsertId();
    syncGymInquiryPaymentSnapshot($pdo, $inquiryId);
    return $pid;
}
function bill(PDO $pdo, int $id): array { return one($pdo, "SELECT status, total_amount, vat_amount, total_with_vat, amount_paid, amount_due, payment_status, notes FROM gym_inquiries WHERE id = ?", [$id]) ?: []; }
/** The member_status 'cancelled' action body, in the same order as admin/gym-members.php. */
function cancel_member(PDO $pdo, int $memberId, int $adminId): array {
    $prev = one($pdo, "SELECT status, member_number, gym_inquiry_id FROM gym_members WHERE id = ? FOR UPDATE", [$memberId]);
    $pdo->prepare("UPDATE gym_members SET status = 'cancelled' WHERE id = ?")->execute([$memberId]);
    gym_member_release_future($pdo, $memberId);
    return rh_gym_void_unpaid_for_member($pdo, $memberId, (string)$prev['member_number'], (int)($prev['gym_inquiry_id'] ?? 0) ?: null, 'Test admin #' . $adminId);
}

$leakRef = '';
try {
    $pdo->beginTransaction();

    echo "\n--- Unpaid bill is cleared on cancel ---\n";
    $b1 = mk_bill($pdo, $run, 1000, 175);
    $leakRef = 'LG' . $run . 'B1';
    [$m1, $n1] = mk_member($pdo, $run, $b1);
    $pend = mk_pay($pdo, $run, $b1, 500, 'pending');
    // Future class place and slot booking that must be released.
    $pdo->prepare("INSERT INTO gym_classes (title, day_label, time_label) VALUES ('LG TEST CLASS', 'Mon', '06:00')")->execute();
    $classId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO gym_class_enrollments (class_id, member_id, status) VALUES (?, ?, 'enrolled')")->execute([$classId, $m1]);
    $pdo->prepare("INSERT INTO gym_slot_reservations (reference, slot_date, slot_time, member_id, member_number, full_name, status, source)
        VALUES (?, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '07:00:00', ?, ?, 'GYM LINK TEST', 'booked', 'admin')")->execute(['LGR' . $run, $m1, $n1]);
    $r = cancel_member($pdo, $m1, $adminId);
    $b = bill($pdo, $b1);
    check(money_eq($r['cleared'], 1175) && $r['accounts'] === 1, 'Cleared amount reported is the full 1175.00', json_encode($r));
    check($b['status'] === 'cancelled' && money_eq($b['amount_due'], 0) && money_eq($b['amount_paid'], 0), 'Unpaid account is cancelled, due 0, paid 0', json_encode($b));
    check(stripos((string)$b['notes'], 'unpaid bill of') !== false && strpos((string)$b['notes'], 'Test admin #' . $adminId) !== false, 'Account note says what was cleared and by whom', (string)$b['notes']);
    $p = one($pdo, "SELECT payment_status, status, total_amount FROM payments WHERE id = ?", [$pend]);
    check($p['payment_status'] === 'cancelled' && $p['status'] === 'failed' && money_eq($p['total_amount'], 500), 'Pending payment is closed (amount kept on the row)', json_encode($p));
    check((int)col($pdo, "SELECT COUNT(*) FROM gym_class_enrollments WHERE member_id = ?", [$m1]) === 0, 'Class enrolment released');
    check(col($pdo, "SELECT status FROM gym_slot_reservations WHERE member_id = ?", [$m1]) === 'cancelled', 'Future slot reservation cancelled');
    check(col($pdo, "SELECT status FROM gym_members WHERE id = ?", [$m1]) === 'cancelled', 'Member is cancelled');
    $r2 = rh_gym_void_unpaid_for_member($pdo, $m1, $n1, $b1, 'Test');
    check($r2['accounts'] === 0 && money_eq($r2['cleared'], 0), 'Second run clears nothing', json_encode($r2));

    echo "
--- Part-paid bill is cancelled; the paid money becomes credit owed back ---
";
    $b2 = mk_bill($pdo, $run, 1000, 175);
    [$m2, $n2] = mk_member($pdo, $run, $b2);
    $pay2 = mk_pay($pdo, $run, $b2, 400);
    $before = one($pdo, "SELECT payment_status, status, total_amount, deleted_at FROM payments WHERE id = ?", [$pay2]);
    $r = cancel_member($pdo, $m2, $adminId);
    $b = bill($pdo, $b2);
    check(money_eq($r['cleared'], 775), 'Cleared amount is the unpaid 775.00', json_encode($r));
    check($b['status'] === 'cancelled' && money_eq($b['amount_due'], 0) && money_eq($b['amount_paid'], 400), 'Account cancelled, due 0, the 400 paid is kept', json_encode($b));
    check(money_eq($b['total_with_vat'], 1175), 'Billed total is NOT shrunk (a later refund must not make it fall due again)', json_encode($b));
    check(money_eq(rh_account_credit_owed($pdo, 'gym', $b2), 400), 'The 400 paid is credit owed back (refund / credit note as normal)');
    check(one($pdo, "SELECT payment_status, status, total_amount, deleted_at FROM payments WHERE id = ?", [$pay2]) == $before, 'The 400 payment row is untouched');
    check(stripos((string)$b['notes'], 'credit owed back') !== false, 'Note records the paid money stays as credit owed back');

    echo "\n--- Renewal bill (marker) is voided; fully paid bill is left alone ---\n";
    $b3 = mk_bill($pdo, $run, 1000, 175);
    [$m3, $n3] = mk_member($pdo, $run, $b3);
    $renew = mk_bill($pdo, $run, 1000, 175, rh_gym_member_marker($n3) . ' Desk renewal');
    $paidFull = mk_bill($pdo, $run, 1000, 175, rh_gym_member_marker($n3) . ' Desk renewal earlier');
    mk_pay($pdo, $run, $paidFull, 1175);
    $other = mk_bill($pdo, $run, 1000, 175, '[member:NOT' . $run . '] someone else');
    $r = cancel_member($pdo, $m3, $adminId);
    check($r['accounts'] === 2 && money_eq($r['cleared'], 2350), 'Enrolment + renewal bills cleared (2 x 1175)', json_encode($r));
    check(bill($pdo, $renew)['status'] === 'cancelled', 'Renewal bill voided');
    $pf = bill($pdo, $paidFull);
    check(money_eq($pf['total_with_vat'], 1175) && $pf['status'] === 'converted', 'Fully paid bill untouched', json_encode($pf));
    check(bill($pdo, $other)['status'] === 'converted', 'Another member bill is untouched');

    echo "\n--- Suspend / expire never clear the bill ---\n";
    $src = (string)file_get_contents(__DIR__ . '/../../admin/gym-members.php');
    $callPos = strpos($src, 'rh_gym_void_unpaid_for_member');
    $guard = $callPos === false ? '' : substr($src, max(0, $callPos - 220), 220);
    check($callPos !== false && strpos($guard, "\$status === 'cancelled'") !== false, "member_status only voids the bill when the new status is 'cancelled'");
    check(substr_count($src, 'rh_gym_void_unpaid_for_member') === 1, 'No other path in the members page voids a bill');
    $b6 = mk_bill($pdo, $run, 1000, 175);
    [$m6] = mk_member($pdo, $run, $b6);
    gym_member_release_future($pdo, $m6); // what suspend / expire do
    $pdo->prepare("UPDATE gym_members SET status = 'suspended' WHERE id = ?")->execute([$m6]);
    $b = bill($pdo, $b6);
    check($b['status'] === 'converted' && money_eq($b['total_with_vat'], 1175) && money_eq($b['amount_due'], 1175), 'Suspended member keeps the full bill', json_encode($b));
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }

if ($leakRef !== '' && (int)col($pdo, "SELECT COUNT(*) FROM gym_inquiries WHERE reference_number = ?", [$leakRef]) > 0) {
    bad('HARNESS LEAK - test gym bill survived the rollback', $leakRef);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
