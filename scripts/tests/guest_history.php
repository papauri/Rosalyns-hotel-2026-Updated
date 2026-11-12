<?php
/**
 * Guest history: one rule for the booking page panel and the Create Booking returning-guest lookup
 * (includes/guest-history.php, owner 2026-11-12 "Make sure Guest history is also accurate").
 *
 * Usage (project root):
 *   php scripts/tests/guest_history.php        pure checks only, no database connection (safe anywhere)
 *   php scripts/tests/guest_history.php --db   + database checks, LIWONDE ONLY (refuses elsewhere); one transaction,
 *                                               rolled back, nothing is left behind
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): bool { global $pass, $fail; if ($c) { $pass++; echo "[PASS] $l\n"; } else { $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; } return $c; }

// ---------------------------------------------------------------- pure part (no database)
require_once $root . '/includes/guest-history.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'pure part loads without a database');

check(rh_guest_history_email_sql('b') === 'LOWER(TRIM(b.guest_email)) = LOWER(TRIM(?))', 'guest = email trimmed, case-insensitive');
$case = rh_guest_history_paid_case_sql('p');
check(strpos($case, "p.payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(p.payment_type,'') <> 'refund' THEN p.total_amount") !== false,
    'paid leg = canonical net-paid rule (gross total_amount, completed/paid/refunded/partially_refunded, not a refund row)', $case);
check(strpos($case, "WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN -p.total_amount") !== false,
    'refund leg = the one refund rule (completed + processing, full total_amount)', $case);
check(strpos($case, 'payment_amount') === false, 'never the net payment_amount column');

check(rh_guest_history_stay_label(0) === 'No completed stays', 'label: 0 stays');
check(rh_guest_history_stay_label(1) === '1 completed stay', 'label: 1 stay');
check(rh_guest_history_stay_label(4) === '4 completed stays', 'label: 4 stays');

$blank = rh_guest_history_blank(false);
check($blank['ok'] === false && $blank['money'] === null && $blank['is_returning'] === false, 'failed history: ok=false, no money, not returning');

$contact = ['guest_email' => 'a@example.invalid', 'guest_name' => 'A', 'guest_phone' => '1', 'guest_country' => '', 'guest_address' => ''];
$h = rh_guest_history_blank(true);
$h['completed_stays'] = 2; $h['is_returning'] = true;
$h['last_stay'] = ['id' => 1, 'booking_reference' => 'R1', 'check_in_date' => '2026-01-02', 'check_out_date' => '2026-01-04'];
$row = rh_guest_history_lookup_row($contact, $h, 'MWK');
check(!array_key_exists('lifetime_spend', $row), 'lookup: no money key when money is hidden (never a made-up 0)');
check($row['is_returning'] === true && $row['stay_label'] === '2 completed stays' && $row['last_booking_ref'] === 'R1' && $row['last_stay_label'] === 'Jan 2, 2026', 'lookup: stays, last finished stay');
$h['money'] = ['received' => 1500.0, 'refunded' => 200.0];
$row = rh_guest_history_lookup_row($contact, $h, 'MWK');
check(($row['lifetime_spend'] ?? null) === 1500.0 && ($row['refunded'] ?? null) === 200.0, 'lookup: money keys when allowed');
$row = rh_guest_history_lookup_row($contact, rh_guest_history_blank(false), 'MWK');
check($row['history_ok'] === false && $row['is_returning'] === false && $row['stay_label'] === 'History could not be loaded' && !array_key_exists('lifetime_spend', $row), 'lookup: failed history says so, no 0, not "first stay"');

$bd = (string)file_get_contents($root . '/admin/booking-details.php');
$gl = (string)file_get_contents($root . '/admin/api/guest-lookup.php');
check(strpos($bd, 'rh_guest_history(') !== false && strpos($gl, 'rh_guest_history(') !== false, 'both screens use the shared helper');
check(strpos($bd, "THEN \" . rh_booking_gross_sql('bookings')") === false, 'booking page no longer counts booked totals as spend');
check(stripos($gl, 'Fallback') === false && strpos($gl, 'total_with_vat') === false && strpos($gl, 'payment_amount') === false, 'lookup has no divergent fallback rule');
check(strpos($bd, "hasPermission((int)(\$user['id'] ?? 0), 'bookings')") !== false && strpos($gl, "hasPermission((int)(\$user['id'] ?? 0), 'bookings')") !== false, 'both gate money on the same permission');

// ---------------------------------------------------------------- database part (Liwonde only)
if (!in_array('--db', $argv, true)) {
    echo "\n(database part skipped: run with --db at Liwonde)\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
if (stripos((string)realpath($root), 'Liwonde') === false) {
    echo "[FAIL] --db refused: this is not the Liwonde test hotel (Rosalyn's is live)\n";
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/finance-account-sync.php';
$tol = (float)BALANCE_TOLERANCE;

$run = strtoupper(bin2hex(random_bytes(3)));
$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$roomId = (int)$pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminId || !$roomId) { check(false, 'fixture: an admin and a room exist'); echo "\n$pass passed, $fail failed\n"; exit(1); }

$pdo->beginTransaction();
try {
    $seq = 0;
    $email = 'gh.test.' . strtolower($run) . '@example.invalid';
    $mk = function (string $mail, string $status, int $inDays, int $nights = 2) use ($pdo, $run, $roomId, &$seq): array {
        $seq++;
        $ref = 'GH' . $run . $seq;
        $in = date('Y-m-d', strtotime(($inDays >= 0 ? '+' : '') . $inDays . ' days'));
        $out = date('Y-m-d', strtotime($in . ' +' . $nights . ' days'));
        $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status)
                       VALUES (?, ?, 'GH TEST', ?, '+265000000000', ?, ?, ?, 1, 1, 0, 100000, 100000, 0, 100000, ?)")
            ->execute([$ref, $roomId, $mail, $in, $out, $nights, $status]);
        return [(int)$pdo->lastInsertId(), $ref];
    };
    $pay = function (array $b, float $gross, string $status = 'completed', string $type = 'full_payment', ?string $refundStatus = null, string $method = 'cash') use ($pdo, $run, $adminId, &$seq): int {
        $seq++;
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, status, refund_reason, refund_status, refund_amount, recorded_by, created_at)
            VALUES (?, 'room', ?, ?, CURDATE(), ?, 17.5, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, NOW())")
            ->execute(['PGH' . $run . $seq, $b[0], $b[1], round($gross * 0.85, 2), round($gross * 0.15, 2), $gross, $method, $type, $status,
                $type === 'refund' ? 'other' : null, $refundStatus, $type === 'refund' ? $gross : null, $adminId]);
        return (int)$pdo->lastInsertId();
    };

    // Guest 1, stored with mixed case / spaces on some bookings
    $b1 = $mk($email, 'checked-out', -40);                                 // finished stay
    $pay($b1, 100000);                                                     // gross 100,000 (net 85,000)
    $pay($b1, 30000, 'refunded', 'refund', 'completed');                   // refund 30,000 paid out
    $delPay = $pay($b1, 12345);                                            // a deleted payment row
    $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE id = ?")->execute([$delPay]);
    $b6 = $mk(strtoupper($email), 'checked-out', -40);                     // second room of the same group
    $pdo->prepare("UPDATE bookings SET primary_booking_id = ? WHERE id = ?")->execute([$b1[0], $b6[0]]);
    $b2 = $mk('  ' . strtoupper($email) . ' ', 'confirmed', 400);          // future booking, case/space different
    $pay($b2, 20000, 'pending');                                           // not received yet
    $b3 = $mk($email, 'cancelled', -20);                                   // cancelled, money kept
    $pay($b3, 50000);
    $pay($b3, 10000, 'refunded', 'refund', 'completed', 'credit_note');    // 10,000 of it moved to a credit note
    $b4 = $mk($email, 'checked-out', -80);                                 // deleted booking
    $pay($b4, 99999);
    $pdo->prepare("UPDATE bookings SET deleted_at = NOW() WHERE id = ?")->execute([$b4[0]]);
    $b7 = $mk($email, 'checked-out', -10);                                 // another finished stay, nothing paid

    $h = rh_guest_history($pdo, $email, $b2[0], true, 6);
    check($h['ok'] === true, 'db: history loads');
    check($h['total_bookings'] === 5, 'db: deleted booking not counted (5 bookings)', (string)$h['total_bookings']);
    check($h['completed_stays'] === 2, 'db: stays = checked-out only, group counts once, future/cancelled/deleted not stays (2)', (string)$h['completed_stays']);
    check($h['other_bookings'] === 4 && $h['is_returning'] === true, 'db: from the future booking: 4 other bookings, returning guest');
    $ids = array_map('intval', array_column($h['recent_other'], 'id'));
    check(!in_array($b4[0], $ids, true) && !in_array($b2[0], $ids, true) && count($ids) === 4, 'db: list leaves out the deleted and the current booking', json_encode($ids));
    check((int)($h['last_stay']['id'] ?? 0) === $b7[0], 'db: last stay = latest checked-out booking');
    $expect = 100000 - 30000 + 50000 - 10000;
    check(is_array($h['money']) && abs($h['money']['received'] - $expect) <= $tol, 'db: received = paid in (gross) less refunds, cancelled kept money counted, deleted booking/payment and pending ignored (110,000)', json_encode($h['money']));
    check(is_array($h['money']) && abs($h['money']['refunded'] - 40000) <= $tol && abs($h['money']['to_credit'] - 10000) <= $tol, 'db: refunds shown in full (40,000), credit-note part apart (10,000)', json_encode($h['money']));
    $ledger = 0.0;
    foreach ([$b1, $b2, $b3, $b6, $b7] as $b) { $ledger += rh_sum_account_paid($pdo, 'room', $b[0]); }
    check(abs($h['money']['received'] - $ledger) <= $tol, 'db: received equals the folio net-paid rule summed per booking', $ledger . ' vs ' . $h['money']['received']);

    $hx = rh_guest_history($pdo, '  ' . strtoupper($email) . '  ', 0, true, 0);
    check($hx['total_bookings'] === 5 && $hx['completed_stays'] === 2 && abs($hx['money']['received'] - $expect) <= $tol, 'db: case/space-different email gives the same answer');

    $hb1 = rh_guest_history($pdo, $email, $b1[0], true, 6);
    check($hb1['other_completed_stays'] === 1 && $hb1['is_returning'] === true, 'db: from a finished stay, its own group is not "another stay" (1 other)');
    $hb7only = rh_guest_history($pdo, $email, $b7[0], true, 6);
    check($hb7only['other_completed_stays'] === 1, 'db: from the last stay, the earlier group counts once');

    $hn = rh_guest_history($pdo, $email, $b2[0], false, 6);
    check($hn['money'] === null && $hn['completed_stays'] === 2, 'db: without the money permission no money is read');
    $row = rh_guest_history_lookup_row(['guest_email' => $email], $hn, 'MWK');
    check(!array_key_exists('lifetime_spend', $row), 'db: lookup hides money without permission');

    // Guest 2: first-timer with two future bookings is not a returning guest
    $email2 = 'gh.new.' . strtolower($run) . '@example.invalid';
    $n1 = $mk($email2, 'confirmed', 300);
    $mk($email2, 'pending', 320);
    $h2 = rh_guest_history($pdo, $email2, $n1[0], true, 6);
    check($h2['completed_stays'] === 0 && $h2['is_returning'] === false && $h2['other_bookings'] === 1, 'db: two future bookings = first stay, 0 completed stays');
    $hi = rh_guest_history($pdo, $email2, 0, true, 0);
    check($hi['money'] !== null && abs($hi['money']['received']) <= $tol, 'db: nothing paid = checked total 0 (not missing)');

    $he = rh_guest_history($pdo, '   ', 0, true, 6);
    check($he['ok'] === true && $he['total_bookings'] === 0 && $he['money'] === null, 'db: blank email has no history');
} catch (Throwable $e) {
    check(false, 'db: no error', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GH" . $run . "%'")->fetchColumn();
check($left === 0, 'db: rolled back, nothing left behind');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
