<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/payment_edit.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
require_once $root . '/config/email.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/payment-edit.php';
require_once $root . '/includes/booking-history.php';

/** A confirmed room booking billed 1,000 net + 165 VAT = 1,165, no levy, no guest email (nothing is ever emailed). */
function pe_booking(PDO $pdo, ?int $primaryId = null): array {
    $ref = 'PEDT' . random_int(10000, 99999);
    $in = date('Y-m-d', strtotime('+400 day')); $outd = date('Y-m-d', strtotime('+401 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'PAYMENT EDIT TEST','','+265000000009','Malawi',2,2,0,50,?,?,1,1000,0,0,0,16.5,165,1165,'payment edit test','confirmed','unpaid',0,'double',?,?,NOW())")
        ->execute([$ref, $in, $outd, bin2hex(random_bytes(16)), $primaryId]);
    return [(int)$pdo->lastInsertId(), $ref];
}
/** A collected full payment of $gross on the booking, VAT split like a new payment, with a receipt number. */
function pe_pay(PDO $pdo, int $bid, string $ref, float $gross): array {
    $sp = rh_account_vat_split($pdo, 'room', $bid, $gross, 16.5);
    $pref = 'PEDP' . random_int(100000, 999999);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, invoice_generated, created_at)
        VALUES (?,'room',?,?,CURDATE(),?,?,?,?,'cash','full_payment','completed','completed',?,0,NOW())")
        ->execute([$pref, $bid, $ref, $sp['net'], $sp['rate'], $sp['vat'], $gross, 'RPED' . random_int(100000, 999999)]);
    $pid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bid);
    return [$pid, $pref];
}
function pe_row(PDO $pdo, string $sql, array $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function pe_try(callable $fn): string { try { $fn(); return 'NOT REFUSED'; } catch (Throwable $e) { return $e->getMessage(); } }

$admin = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name']];
$today = date('Y-m-d');
$in = static function (string $amount, string $reason = 'guest paid part only', string $method = 'cash', ?string $date = null) use ($today): array {
    return ['amount' => $amount, 'payment_method' => $method, 'payment_date' => $date ?? $today, 'transaction_reference' => '', 'notes' => '', 'reason' => $reason];
};
$out = ['scenario' => $sc];

switch ($sc) {
    case 'main':
        [$bid, $bref] = pe_booking($pdo);
        [$pid, $pref] = pe_pay($pdo, $bid, $bref, 1165.00);
        $before = pe_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$bid]);
        $out['before'] = $before;

        // refusals first (nothing saved)
        $out['no_reason'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('500', ''), $user); });
        $out['zero'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('0'), $user); });
        $out['above'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('1200'), $user); });
        $out['future'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('500', 'x reason', 'cash', date('Y-m-d', strtotime('+2 day'))), $user); });
        $out['nothing'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('1165'), $user); });
        $out['unchanged_after_refusals'] = pe_row($pdo, "SELECT total_amount FROM payments WHERE id = ?", [$pid]);

        // full -> partial
        $res = rh_payment_edit_apply($pdo, $pid, $in('500', 'guest paid part only', 'mobile_money'), $user);
        $out['res'] = ['due_before' => $res['due_before'], 'due_after' => $res['due_after'], 'owed_without' => $res['owed_without'],
            'status_after' => $res['booking_status_after'], 'changes' => array_keys($res['changes'])];
        $out['pay_after'] = pe_row($pdo, "SELECT total_amount, payment_amount, vat_amount, vat_rate, payment_type, payment_method, payment_status FROM payments WHERE id = ?", [$pid]);
        $out['bk_after'] = pe_row($pdo, "SELECT amount_paid, amount_due, payment_status, credit_balance FROM bookings WHERE id = ?", [$bid]);
        $out['expected_vat'] = rh_account_vat_split($pdo, 'room', $bid, 500.0, 16.5);
        $st = $pdo->prepare("SELECT field_name, old_value, new_value FROM payment_audit_log WHERE payment_id = ? AND action = 'edited' ORDER BY id");
        $st->execute([$pid]);
        $out['audit'] = $st->fetchAll(PDO::FETCH_ASSOC);

        // after the commit: unticked email = no send, a not_sent row; receipt marked corrected
        $notice = rh_payment_edit_after($pdo, $res, false, $user);
        $out['notice'] = $notice;
        $out['not_sent'] = pe_row($pdo, "SELECT status, email_kind, account_type, account_id, error FROM email_send_log WHERE account_type = 'room' AND account_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $out['sent_rows'] = (int)pe_row($pdo, "SELECT COUNT(*) c FROM email_send_log WHERE account_type = 'room' AND account_id = ? AND status <> 'not_sent'", [$bid])['c'];
        $out['corrected_event'] = pe_row($pdo, "SELECT event_type, receipt_number, event_note FROM receipt_events WHERE payment_id = ? AND event_type = 'corrected' ORDER BY id DESC LIMIT 1", [$pid]);
        $out['receipt_emailed'] = pe_row($pdo, "SELECT receipt_emailed_at FROM payments WHERE id = ?", [$pid]);
        $ctx = receipt_hydrate_context($pdo, receipt_get_payment($pdo, $pid));
        $out['ctx_corrected'] = (string)($ctx['corrected_at'] ?? '') !== '';
        $out['pdf_has_corrected'] = stripos(receipt_render_payment_pdf_html(receipt_get_payment($pdo, $pid), $ctx), 'Corrected receipt') !== false;

        // History: one step "Payment <ref> changed" with old -> new and the reason
        $hist = json_encode(rh_booking_history($pdo, $bid), JSON_UNESCAPED_UNICODE);
        $out['hist_changed'] = strpos($hist, 'Payment ' . $pref . ' changed') !== false;
        $out['hist_reason'] = strpos($hist, 'Reason: guest paid part only') !== false;
        $out['hist_type'] = strpos($hist, 'Partial payment') !== false;

        // below what was refunded from it
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, original_payment_id, refund_status, refund_amount, refund_reason, status, created_at)
            VALUES (?,'room',?,?,CURDATE(),300,0,0,300,'cash','refund','completed',?,'completed',300,'other','completed',NOW())")
            ->execute(['PEDR' . random_int(100000, 999999), $bid, $bref, $pid]);
        recalculateBookingFinancials($bid);
        $out['below_refund'] = pe_try(function () use ($pdo, $pid, $in, $user) { rh_payment_edit_apply($pdo, $pid, $in('250'), $user); });
        $res2 = rh_payment_edit_apply($pdo, $pid, $in('300', 'refund covered all of it'), $user);
        $out['at_refund_status'] = pe_row($pdo, "SELECT payment_status FROM payments WHERE id = ?", [$pid]);
        $out['at_refund_due'] = $res2['due_after'];

        // till payments and refund rows are never edited
        $out['refusal_restaurant'] = rh_payment_edit_refusal(['booking_type' => 'restaurant', 'payment_status' => 'completed', 'payment_type' => 'full_payment']);
        $out['refusal_refund'] = rh_payment_edit_refusal(['booking_type' => 'room', 'payment_status' => 'completed', 'payment_type' => 'refund']);
        $out['refusal_credit_note'] = rh_payment_edit_refusal(['booking_type' => 'room', 'payment_status' => 'completed', 'payment_type' => 'full_payment', 'payment_method' => 'credit_note', 'credit_note_id' => 5]);
        $out['refusal_pending'] = rh_payment_edit_refusal(['booking_type' => 'room', 'payment_status' => 'pending', 'payment_type' => 'full_payment']);

        // no payment_edit permission: refused server-side (role made accountant inside this rolled-back run)
        [$bid3, $bref3] = pe_booking($pdo);
        [$pid3] = pe_pay($pdo, $bid3, $bref3, 1165.00);
        $pdo->prepare("UPDATE admin_users SET role = 'accountant' WHERE id = ?")->execute([$user['id']]);
        $out['perm_accountant'] = hasPermission($user['id'], 'payment_edit');
        $out['perm_denied'] = pe_try(function () use ($pdo, $pid3, $in, $user) { rh_payment_edit_apply($pdo, $pid3, $in('500'), $user); });
        $out['perm_unchanged'] = pe_row($pdo, "SELECT total_amount FROM payments WHERE id = ?", [$pid3]);
        // ... and WITH the grant (Staff & Access > Permissions row) the same accountant can edit
        $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'payment_edit', 1) ON DUPLICATE KEY UPDATE is_granted = 1")->execute([$user['id']]);
        $out['perm_granted'] = hasPermission($user['id'], 'payment_edit');
        $out['perm_granted_err'] = pe_try(function () use ($pdo, $pid3, $in, $user) { rh_payment_edit_apply($pdo, $pid3, $in('600', 'granted accountant corrects it'), $user); });
        $out['perm_granted_total'] = pe_row($pdo, "SELECT total_amount FROM payments WHERE id = ?", [$pid3]);
        $pdo->prepare("UPDATE admin_users SET role = 'admin' WHERE id = ?")->execute([$user['id']]);
        $out['perm_admin'] = hasPermission($user['id'], 'payment_edit');
        break;

    case 'group':
        [$lead, $leadRef] = pe_booking($pdo);
        [$member, $memRef] = pe_booking($pdo, $lead);
        pe_pay($pdo, $lead, $leadRef, 1165.00);
        [$mpid] = pe_pay($pdo, $member, $memRef, 1165.00);
        $out['lead_before'] = pe_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$lead]);
        $res = rh_payment_edit_apply($pdo, $mpid, $in('400', 'member paid part'), $user);
        $out['lead_after'] = pe_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$lead]);
        $out['member_after'] = pe_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$member]);
        $out['group_paid'] = (float)pe_row($pdo, "SELECT COALESCE(SUM(amount_paid),0) s FROM bookings WHERE id = ? OR primary_booking_id = ?", [$lead, $lead])['s'];
        break;
}
echo json_encode($out);
