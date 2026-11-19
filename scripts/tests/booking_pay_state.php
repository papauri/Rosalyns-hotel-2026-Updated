<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Step 8i (owner 2026-11-15): a booking with a balance still owed never reads "Completed" / "Paid".
 *
 * Usage (project root):
 *   php scripts/tests/booking_pay_state.php
 *       PURE: loads includes/booking-pay-state.php only. Never opens a database. Safe in either tree.
 *   php scripts/tests/booking_pay_state.php --db
 *       adds the database part against THIS tree's .env, inside one transaction that is rolled back (nothing kept):
 *       a fully paid booking gets one more night at its booked rate (the extend path's writes:
 *       rh_reprice_at_booked_rate + rh_write_booking_stay_totals + recalculateBookingFinancials), its payment status
 *       must turn Part paid with the new balance, and Booking History must get the old -> new row.
 *       Refuses before connecting when this tree's .env points at Rosalyn's (LIVE): run it from the Liwonde folder.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (!defined('BALANCE_TOLERANCE')) { define('BALANCE_TOLERANCE', 0.01); }
require_once $root . '/includes/booking-pay-state.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ': ' . substr($detail, 0, 800) : '') . "\n"; }
}
function ps(array $row): array { return rh_booking_pay_state($row, ['currency' => 'MWK']); }

echo "== pure ==\n";
$s = ps(['amount_paid' => 0, 'amount_due' => 500000, 'status' => 'confirmed', 'payment_status' => 'unpaid']);
ok('paid 0, owed: Unpaid', $s['key'] === 'unpaid' && $s['label'] === 'Unpaid' && $s['class'] === 'alert', json_encode($s));

// The owner's screenshot: total 1,066,500, paid 937,500, balance 129,000, last payment record 'completed'.
$s = ps(['amount_paid' => 937500, 'amount_due' => 129000, 'status' => 'checked-in', 'payment_status' => 'completed']);
ok('partial: Part paid (MWK 129,000.00 left), never Paid / Completed', $s['key'] === 'partial'
    && $s['label'] === 'Part paid (MWK 129,000.00 left)' && $s['short'] === 'Part paid' && $s['class'] === 'warn'
    && abs($s['left'] - 129000) < 0.005, json_encode($s));
$s = ps(['amount_paid' => 937500, 'amount_due' => 129000, 'status' => 'checked-in', 'payment_status' => 'paid']);
ok('a stale stored "paid" with a balance owed still reads Part paid', $s['key'] === 'partial', json_encode($s));

$s = ps(['amount_paid' => 1066500, 'amount_due' => 0.004, 'status' => 'checked-in', 'payment_status' => 'paid']);
ok('full within tolerance (0.004 left): Paid in full', $s['key'] === 'paid' && $s['label'] === 'Paid in full' && $s['left'] === 0.0, json_encode($s));
$s = ps(['amount_paid' => 1066500, 'amount_due' => 0.02, 'status' => 'checked-in', 'payment_status' => 'paid']);
ok('0.02 left is above tolerance: Part paid', $s['key'] === 'partial', json_encode($s));

$s = ps(['amount_paid' => 1100000, 'amount_due' => 0, 'credit_balance' => 33500, 'status' => 'checked-out', 'payment_status' => 'paid']);
ok('overpaid: Paid in full, helper points at the credit', $s['key'] === 'paid' && stripos($s['help'], 'credit') !== false, json_encode($s));

$s = ps(['amount_paid' => 0, 'amount_due' => 0, 'status' => 'cancelled', 'payment_status' => 'refunded']);
ok('cancelled, money all given back: Refunded', $s['key'] === 'refunded' && $s['label'] === 'Refunded', json_encode($s));
$s = ps(['amount_paid' => 0, 'amount_due' => 0, 'status' => 'cancelled', 'payment_status' => 'unpaid']);
ok('cancelled, nothing billed, nothing paid: Unpaid (not Paid in full)', $s['key'] === 'unpaid', json_encode($s));
$s = ps(['amount_paid' => 0, 'amount_due' => 0, 'status' => 'cancelled', 'payment_status' => 'paid']);
ok('cancelled with nothing paid never reads Paid in full', $s['key'] === 'unpaid', json_encode($s));

$s = ps(['amount_paid' => 0, 'amount_due' => 355500, 'status' => 'cancelled', 'payment_status' => 'unpaid']);
ok('cancelled with fee owed, nothing paid: Unpaid, MWK 355,500 owed', $s['key'] === 'unpaid' && abs($s['left'] - 355500) < 0.005, json_encode($s));
$s = ps(['amount_paid' => 100000, 'amount_due' => 255500, 'status' => 'cancelled', 'payment_status' => 'partial']);
ok('cancelled with fee part paid: Part paid (MWK 255,500.00 left)', $s['label'] === 'Part paid (MWK 255,500.00 left)', json_encode($s));
$s = ps(['amount_paid' => 355500, 'amount_due' => 0, 'status' => 'cancelled', 'payment_status' => 'paid']);
ok('cancelled, kept fee fully paid: Paid in full', $s['key'] === 'paid', json_encode($s));

// The stored rule (recalculateBookingFinancials calls rh_pay_state_key): same answers as before step 8i.
ok('key: nothing owed = paid', rh_pay_state_key(500, 0, false, 500, false) === 'paid');
ok('key: owed + something paid = partial', rh_pay_state_key(500, 10, false, 510, false) === 'partial');
ok('key: owed, nothing paid = unpaid', rh_pay_state_key(0, 10, false, 10, false) === 'unpaid');
ok('key: live booking, total 0 = paid (as before)', rh_pay_state_key(0, 0, false, 0, false) === 'paid');
ok('key: cancelled, nothing billed or paid = unpaid', rh_pay_state_key(0, 0, true, 0, false) === 'unpaid');
ok('key: cancelled, all refunded = refunded', rh_pay_state_key(0, 0, true, 0, true) === 'refunded');
ok('key: cancelled, refund but fee still owed = unpaid', rh_pay_state_key(0, 50, true, 50, true) === 'unpaid');

// Step 13 (owner 2026-11-19): the label comes from the money, never the stored word, and every screen prints these
// four labels only (Bookings tabs / filter / badge, booking lookup, individual rooms, calendar, export).
$s = ps(['amount_paid' => 60000, 'amount_due' => 15000, 'status' => 'confirmed', 'payment_status' => 'completed']);
ok('stale stored "completed" with money owed: Part paid (MWK 15,000.00 left)', $s['key'] === 'partial' && $s['label'] === 'Part paid (MWK 15,000.00 left)' && $s['short'] === 'Part paid', json_encode($s));
$s = ps(['amount_paid' => 0, 'amount_due' => 5000, 'status' => 'confirmed', 'payment_status' => null]);
ok('NULL stored status: Unpaid from the money', $s['key'] === 'unpaid' && $s['label'] === 'Unpaid', json_encode($s));
$s = ps(['amount_paid' => 10000, 'amount_due' => 40000, 'status' => 'no-show', 'payment_status' => 'partial']);
ok('no-show still owing: Part paid (MWK 40,000.00 left)', $s['key'] === 'partial' && $s['left'] === 40000.0, json_encode($s));
$labels = [];
foreach ([[0, 10, 'confirmed', 'unpaid'], [5, 10, 'confirmed', 'partial'], [10, 0, 'confirmed', 'paid'], [0, 0, 'cancelled', 'refunded']] as [$p, $d, $st, $ps]) {
    $x = ps(['amount_paid' => $p, 'amount_due' => $d, 'status' => $st, 'payment_status' => $ps]);
    $labels[$x['key']] = [$x['short'], $x['class']];
}
ok('four keys, their short labels and pill classes', $labels === ['unpaid' => ['Unpaid', 'alert'], 'partial' => ['Part paid', 'warn'], 'paid' => ['Paid in full', 'ok'], 'refunded' => ['Refunded', 'alert']], json_encode($labels));

/* ── Database part (TEST hotel only) ───────────────────────────────────────────────────────────────────────────── */
if (in_array('--db', $argv, true)) {
    echo "== database (rolled back) ==\n";
    $envName = '';
    if (is_readable($root . '/.env')) {
        foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*DB_NAME\s*=\s*["\']?([^"\'\s]*)/', $line, $m)) { $envName = $m[1]; }
        }
    }
    if ($envName === '' || stripos($envName, 'rosalyn') !== false || stripos($root, 'Rosalyn') !== false) {
        ok('refused: the database part never runs against Rosalyn\'s (LIVE); run it from the Liwonde folder', false, $envName);
    } else {
        require_once $root . '/config/database.php';
        global $pdo;
        echo "   database: " . DB_NAME . "\n";
        $b = $pdo->query("SELECT * FROM bookings WHERE payment_status = 'paid' AND amount_paid > 1 AND amount_due <= 0.01
                AND status IN ('confirmed','checked-in') AND deleted_at IS NULL AND number_of_nights >= 1 AND total_with_vat > 1
            ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            ok('a fully paid live booking to extend exists at the test hotel', false);
        } else {
            $id = (int)$b['id'];
            $auditBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM booking_audit_log")->fetchColumn();
            $pdo->beginTransaction();
            try {
                $nights = (int)$b['number_of_nights'] + 1;
                $rp = rh_reprice_at_booked_rate($b, $nights);
                $newOut = (new DateTime(substr((string)$b['check_out_date'], 0, 10)))->modify('+1 day')->format('Y-m-d');
                rh_write_booking_stay_totals($pdo, $id, $rp['totals'], $nights, substr((string)$b['check_in_date'], 0, 10), $newOut, $rp['new_child_supplement']);
                ok('extend: recalculateBookingFinancials ran', recalculateBookingFinancials($id) === true);
                $after = $pdo->query("SELECT * FROM bookings WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
                $extra = round((float)$after['total_with_vat'] - (float)$b['total_with_vat'], 2);
                ok('extend raised the total (one more night at the booked rate)', $extra > BALANCE_TOLERANCE, (string)$extra);
                ok('stored payment_status recalculated: paid -> partial', $after['payment_status'] === 'partial', (string)$after['payment_status']);
                ok('amount_due = the extra night (paid unchanged)', abs((float)$after['amount_due'] - $extra) <= BALANCE_TOLERANCE
                    && abs((float)$after['amount_paid'] - (float)$b['amount_paid']) <= BALANCE_TOLERANCE,
                    $after['amount_due'] . ' vs ' . $extra);
                $s = rh_booking_pay_state($after, ['currency' => 'MWK']);
                ok('screens read Part paid (MWK X left)', $s['key'] === 'partial' && strpos($s['label'], 'Part paid (MWK ') === 0, $s['label']);
                $log = $pdo->prepare("SELECT * FROM booking_audit_log WHERE booking_id = ? AND id > ? AND action = 'payment_status_changed' ORDER BY id DESC LIMIT 1");
                $log->execute([$id, $auditBefore]);
                $row = $log->fetch(PDO::FETCH_ASSOC);
                ok('Booking History row written (old -> new)', $row
                    && (json_decode((string)$row['old_values'], true)['payment_status'] ?? '') === 'paid'
                    && (json_decode((string)$row['new_values'], true)['payment_status'] ?? '') === 'partial'
                    && (string)$row['performed_by_name'] !== '', json_encode($row));
                if ($row) {
                    require_once $root . '/includes/booking-history-format.php';
                    $ev = rh_hist_from_audit([$row], ['booking' => $after, 'staff' => []]);
                    ok('History shows "Payment status: Paid -> Partial"', isset($ev[0]['detail']) && strpos($ev[0]['detail'], 'Payment status: Paid → Partial') !== false,
                        json_encode($ev));
                }
                // A second recalculation with nothing changed writes no further row.
                recalculateBookingFinancials($id);
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM booking_audit_log WHERE booking_id = ? AND id > ? AND action = 'payment_status_changed'");
                $cnt->execute([$id, $auditBefore]);
                ok('no History row when the status does not change', (int)$cnt->fetchColumn() === 1);
            } finally {
                $pdo->rollBack();
            }
            $chk = $pdo->query("SELECT payment_status, total_with_vat FROM bookings WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
            ok('rolled back: booking unchanged', $chk['payment_status'] === $b['payment_status'] && abs((float)$chk['total_with_vat'] - (float)$b['total_with_vat']) <= BALANCE_TOLERANCE);
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
