<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/stay_nights.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/audit-functions.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/stay-nights.php';

/** An active room type with a free room from today to +4 days (the test DB is a live mirror: some types are full). */
function sn_room(PDO $pdo): int {
    static $id = null;
    if ($id !== null) { return $id; }
    $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+4 day'));
    foreach ($pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY total_rooms DESC, id")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $av = checkRoomAvailability((int)$rid, $from, $to);
        if (!empty($av['available'])) { return $id = (int)$rid; }
    }
    return $id = 0;
}

/**
 * A checked-in room booking: in yesterday, out today + $outDays, billed 1,165 a night all-in (1,000 net + 165 VAT, no
 * levy, no package), no guest email (nothing is ever emailed). Room type = the one with the most rooms.
 */
function sn_booking(PDO $pdo, int $outDays, string $status = 'checked-in'): array {
    $ref = 'SNTS' . random_int(10000, 99999);
    $in = date('Y-m-d', strtotime('-1 day'));
    $out = date('Y-m-d', strtotime('+' . $outDays . ' day'));
    $n = $outDays + 1;
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,'STAY NIGHTS TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'stay nights test',?,'unpaid',0,'single',?,NOW())")
        ->execute([$ref, sn_room($pdo), $in, $out, $n, 1000 * $n, 165 * $n, 1165 * $n, $status, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return [$id, $ref];
}
function sn_pay(PDO $pdo, int $bid, string $ref, float $gross): int {
    $sp = rh_account_vat_split($pdo, 'room', $bid, $gross, 16.5);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, invoice_generated, created_at)
        VALUES (?,'room',?,?,CURDATE(),?,?,?,?,'cash','full_payment','completed','completed',?,0,NOW())")
        ->execute(['SNTP' . random_int(100000, 999999), $bid, $ref, $sp['net'], $sp['rate'], $sp['vat'], $gross, 'RSNT' . random_int(100000, 999999)]);
    $pid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bid);
    return $pid;
}
function sn_row(PDO $pdo, string $sql, array $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function sn_bk(PDO $pdo, int $id): array { return sn_row($pdo, "SELECT * FROM bookings WHERE id = ?", [$id]); }
function sn_try(callable $fn): string { try { $fn(); return 'NOT REFUSED'; } catch (Throwable $e) { return $e->getMessage(); } }

$admin = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name']];
$GLOBALS['user'] = $user;
$out = ['scenario' => $sc];
$q = static function (array $b, int $d) use ($pdo) { $r = rh_stay_nights_quote($pdo, $b, $d, null, false, 'K'); unset($r['drop'], $r['reprice']); return $r; };

switch ($sc) {
    case 'pick_room':
        $out['room_id'] = sn_room($pdo);
        break;

    case 'add_unpaid':
        // 2 nights (yesterday -> tomorrow), nothing paid: +1 then -1.
        [$bid, $bref] = sn_booking($pdo, 1);
        $b = sn_bk($pdo, $bid);
        $out['q_add'] = $q($b, 1);
        $out['no_reason'] = sn_try(function () use ($pdo, $bid, $out, $user) { rh_stay_nights_apply($pdo, $bid, ['delta' => 1, 'expected_new_total' => $out['q_add']['new_total'], 'expected_total_delta' => $out['q_add']['total_delta'], 'reason' => ''], $user); });
        $out['stale'] = sn_try(function () use ($pdo, $bid, $out, $user) { rh_stay_nights_apply($pdo, $bid, ['delta' => 1, 'expected_new_total' => $out['q_add']['new_total'] - 5, 'expected_total_delta' => $out['q_add']['total_delta'], 'reason' => 'one more night'], $user); });
        $out['no_figures'] = sn_try(function () use ($pdo, $bid, $user) { rh_stay_nights_apply($pdo, $bid, ['delta' => 1, 'reason' => 'one more night'], $user); });
        $out['unchanged'] = array_intersect_key(sn_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1]);
        $res = rh_stay_nights_apply($pdo, $bid, ['delta' => 1, 'expected_new_total' => $out['q_add']['new_total'], 'expected_total_delta' => $out['q_add']['total_delta'], 'reason' => 'company LPO, one more night'], $user);
        $out['after_add'] = array_intersect_key(sn_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1, 'amount_due' => 1, 'amount_paid' => 1, 'credit_balance' => 1, 'vat_amount' => 1, 'total_amount' => 1]);
        $out['audit'] = sn_row($pdo, "SELECT action, old_values, new_values, note FROM booking_audit_log WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $out['timeline'] = sn_row($pdo, "SELECT action, old_value, new_value, description FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $b = sn_bk($pdo, $bid);
        $out['q_minus'] = $q($b, -1);
        $out['q_minus_to_today'] = $q($b, -2);
        $out['q_zero'] = $q($b, 0);
        rh_stay_nights_apply($pdo, $bid, ['delta' => -1, 'expected_new_total' => $out['q_minus']['new_total'], 'expected_total_delta' => $out['q_minus']['total_delta'], 'reason' => 'leaving a day earlier'], $user);
        $out['after_minus'] = array_intersect_key(sn_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1, 'amount_due' => 1, 'credit_balance' => 1]);
        $out['refund_rows'] = (int)sn_row($pdo, "SELECT COUNT(*) c FROM payments WHERE booking_id = ? AND booking_type = 'room' AND payment_type = 'refund'", [$bid])['c'];
        break;

    case 'paid_refund':
    case 'paid_credit':
        // 3 nights (yesterday -> +2 days) paid in full 3,495; take 1 off -> 1,165 goes back.
        [$bid, $bref] = sn_booking($pdo, 2);
        sn_pay($pdo, $bid, $bref, 3495.00);
        $b = sn_bk($pdo, $bid);
        $qq = $q($b, -1);
        $out['q'] = $qq;
        $base = ['delta' => -1, 'expected_new_total' => $qq['new_total'], 'expected_total_delta' => $qq['total_delta'], 'reason' => 'leaving a day early'];
        $out['no_mode'] = sn_try(function () use ($pdo, $bid, $base, $user) { rh_stay_nights_apply($pdo, $bid, $base, $user); });
        $out['refund_no_perm'] = sn_try(function () use ($pdo, $bid, $base, $user) { rh_stay_nights_apply($pdo, $bid, $base + ['money_back_mode' => 'refund', 'money_back_typed' => '1165', 'can_refund' => false], $user); });
        $out['typed_wrong'] = sn_try(function () use ($pdo, $bid, $base, $user) { rh_stay_nights_apply($pdo, $bid, $base + ['money_back_mode' => 'credit_note', 'money_back_typed' => '1100', 'can_refund' => true], $user); });
        $out['unchanged'] = array_intersect_key(sn_bk($pdo, $bid), ['check_out_date' => 1, 'total_with_vat' => 1, 'amount_paid' => 1]);
        $mode = $sc === 'paid_refund' ? 'refund' : 'credit_note';
        $res = rh_stay_nights_apply($pdo, $bid, $base + ['money_back_mode' => $mode, 'money_back_typed' => '1,165.00', 'can_refund' => true], $user);
        $out['res'] = ['refund_total' => $res['refund_total'], 'legs' => count($res['refund_legs']), 'credit_note' => $res['credit_note']];
        $out['after'] = array_intersect_key(sn_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1, 'amount_due' => 1, 'amount_paid' => 1, 'credit_balance' => 1, 'payment_status' => 1]);
        $out['refunds'] = sn_row($pdo, "SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) s, MIN(refund_status) st, MIN(refund_reason) rr FROM payments WHERE booking_id = ? AND booking_type = 'room' AND payment_type = 'refund'", [$bid]);
        if ($res['credit_note']) {
            $out['cn'] = sn_row($pdo, "SELECT original_amount AS amount, reason, status FROM credit_notes WHERE id = ?", [(int)$res['credit_note']['id']]);
        }
        $out['audit_action'] = (string)(sn_row($pdo, "SELECT action FROM booking_audit_log WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid])['action'] ?? '');
        break;

    case 'plan_dropped':
        // 3 nights booked under a 3-night minimum stay discount of 1,000 a night (1,165 all-in after it). Taking 1 night
        // off drops the discount: 2 x 2,165 = 4,330, so the bill goes UP by 835 although a night is removed.
        $pdo->exec("INSERT INTO rate_plans (name, rule_type, min_nights, adjustment_type, adjustment_value, applies_to, priority, is_stacking, is_active)
            VALUES ('ZZ SN Long stay', 'los_discount', 3, 'fixed', -1000, 'all', 0, 0, 0)");
        $planId = (int)$pdo->lastInsertId();
        [$bid] = sn_booking($pdo, 1);
        $pdo->prepare("UPDATE bookings SET check_out_date = ?, number_of_nights = 3, total_amount = 3000, vat_amount = 495, total_with_vat = 3495,
                rate_plan_id = ?, rate_plan_label = 'ZZ SN Long stay', rate_plan_discount = 1000 WHERE id = ?")
            ->execute([date('Y-m-d', strtotime('+2 day')), $planId, $bid]);
        recalculateBookingFinancials($bid);
        $out['q'] = $q(sn_bk($pdo, $bid), -1);
        break;

    case 'not_in_house':
        [$bid] = sn_booking($pdo, 2, 'confirmed');
        $out['q'] = $q(sn_bk($pdo, $bid), 1);
        break;

    case 'final_invoice_pay_later':
        // L2: a pay-later booking checked out: final invoice generated, NOT emailed, logged.
        require_once $root . '/config/invoice.php';
        [$bid, $bref] = sn_booking($pdo, 0);
        $pdo->prepare("UPDATE bookings SET checkin_without_payment = 1, checkin_arrangement_note = 'TEST CO (LPO)', guest_email = 'stay.nights@example.invalid', status = 'checked-out' WHERE id = ?")->execute([$bid]);
        $inv = generateAndSendFinalInvoice($bid, $user['id']);
        $out['inv'] = array_intersect_key($inv, ['success' => 1, 'email_sent' => 1, 'email_skipped' => 1, 'idempotent' => 1]);
        $out['row'] = array_intersect_key(sn_bk($pdo, $bid), ['final_invoice_generated' => 1, 'final_invoice_sent_at' => 1]);
        $out['not_sent'] = sn_row($pdo, "SELECT status, email_kind, error FROM email_send_log WHERE account_type = 'room' AND account_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $out['sent_rows'] = (int)sn_row($pdo, "SELECT COUNT(*) c FROM email_send_log WHERE account_type = 'room' AND account_id = ? AND status <> 'not_sent'", [$bid])['c'];
        $out['timeline'] = (string)(sn_row($pdo, "SELECT action FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid])['action'] ?? '');
        break;
}
echo json_encode($out);
