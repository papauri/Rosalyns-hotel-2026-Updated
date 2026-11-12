<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/billing_remittance.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/audit-functions.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/admin/includes/payment-edit.php';

/** A room booking billed 1,165 a night all-in (1,000 net + 165 VAT, no levy), no guest email (nothing is emailed). */
function br_booking(PDO $pdo, string $status, int $nights = 2, ?int $primaryId = null): array {
    $ref = 'BRTS' . random_int(10000, 99999);
    $in = date('Y-m-d', strtotime('-' . $nights . ' day'));
    $out = date('Y-m-d');
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'COMPANY BILL TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'company billing test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute([$ref, $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return [$id, $ref];
}
function br_row(PDO $pdo, string $sql, array $p = []): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function br_try(callable $fn): string { try { $fn(); return 'NOT REFUSED'; } catch (Throwable $e) { return $e->getMessage(); } }
function br_charge(PDO $pdo, int $bid, float $gross): void {
    $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
        VALUES (?, 'other', 'Bar (test)', 1, ?, ?, 0, 0, ?, NOW(), 0)")->execute([$bid, $gross, $gross, $gross]);
    recalculateBookingFinancials($bid);
}

$admin = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name']];
$GLOBALS['user'] = $user;
$out = ['scenario' => $sc, 'ready' => rh_billing_ready($pdo)];

$pdo->prepare("INSERT INTO billing_accounts (name, contact_name, billing_email, terms_days, active, created_at) VALUES (?, 'Test Contact', NULL, 30, 1, NOW())")
    ->execute(['ZZ TEST CO ' . random_int(1000, 9999)]);
$acc = (int)$pdo->lastInsertId();
$out['acc'] = $acc;

switch ($sc) {
    case 'flow':
        [$b1, $r1] = br_booking($pdo, 'checked-in', 2);   // 2,330 stay
        [$b2, $r2] = br_booking($pdo, 'checked-out', 1);  // 1,165 stay
        br_charge($pdo, $b1, 200.0);                      // guest's bar bill on b1
        // set on account
        $out['set_bad_date'] = br_try(function () use ($pdo, $b1, $acc, $user) { rh_billing_set_on_booking($pdo, $b1, ['account_id' => $acc, 'due_date' => '2026-13-40'], $user); });
        $s1 = rh_billing_set_on_booking($pdo, $b1, ['account_id' => $acc, 'reference' => 'LPO-77', 'covers' => 'stay'], $user);
        rh_billing_set_on_booking($pdo, $b2, ['account_id' => $acc, 'reference' => 'LPO-78', 'covers' => 'everything'], $user);
        $out['set'] = ['changed' => $s1['changed'], 'new' => $s1['new']];
        $out['b1_flags'] = br_row($pdo, "SELECT checkin_without_payment, checkin_arrangement_note, billing_account_id, billing_reference, billing_covers FROM bookings WHERE id = ?", [$b1]);
        $out['b1_history'] = br_row($pdo, "SELECT action, description FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$b1]);
        $out['b1_audit'] = br_row($pdo, "SELECT action FROM booking_audit_log WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$b1]);
        $out['split1'] = array_intersect_key(rh_booking_bill_split($pdo, $b1), array_flip(['on_account', 'company_due', 'guest_due', 'amount_due', 'name', 'reference', 'due_date', 'covers']));
        $out['split2'] = array_intersect_key(rh_booking_bill_split($pdo, $b2), array_flip(['company_due', 'guest_due', 'amount_due', 'covers']));
        $out['label1'] = rh_booking_pay_label(rh_booking_bill_split($pdo, $b1));
        // receivables: one row per booking, payer split
        $rows = array_values(array_filter(rh_receivable_rows($pdo, ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false]), static fn($r) => in_array($r['id'], [$b1, $b2], true)));
        $out['recv'] = array_map(static fn($r) => array_intersect_key($r, array_flip(['id', 'client', 'payer', 'company_due', 'guest_due', 'amount_due'])), $rows);
        // checkout gate (D2): only the guest's 200 bar bill is gated
        $gate = evaluateCheckoutBalance($pdo, $b1, null, false);
        $out['gate1'] = array_intersect_key($gate, array_flip(['allowed', 'balance', 'company_due', 'company_name']));
        $gate2 = evaluateCheckoutBalance($pdo, $b2, null, false);
        $out['gate2'] = array_intersect_key($gate2, array_flip(['allowed', 'balance', 'company_due']));
        // chasers leave on-account bookings out
        $out['exclude_sql'] = rh_checkin_arrangement_exclude_sql('b');
        // company payment refusals
        $base = ['amount' => '3495', 'payment_method' => 'bank_transfer', 'received_date' => date('Y-m-d'), 'bank_reference' => 'BT-1', 'notes' => ''];
        $out['r_mismatch'] = br_try(function () use ($pdo, $acc, $base, $user, $b1, $b2) { rh_billing_record_remittance($pdo, $acc, $base + ['lines' => [$b1 => 2330, $b2 => 1000]], $user); });
        $out['r_over'] = br_try(function () use ($pdo, $acc, $base, $user, $b1) { rh_billing_record_remittance($pdo, $acc, ['amount' => '2500'] + $base + ['lines' => [$b1 => 2500]], $user); });
        $out['r_future'] = br_try(function () use ($pdo, $acc, $base, $user, $b1) { rh_billing_record_remittance($pdo, $acc, ['received_date' => date('Y-m-d', strtotime('+1 day')), 'amount' => '100'] + $base + ['lines' => [$b1 => 100]], $user); });
        [$b3] = br_booking($pdo, 'checked-out', 1);
        $out['r_not_on_acc'] = br_try(function () use ($pdo, $acc, $base, $user, $b3) { rh_billing_record_remittance($pdo, $acc, ['amount' => '100'] + $base + ['lines' => [$b3 => 100]], $user); });
        $out['no_rem_rows'] = (int)br_row($pdo, "SELECT COUNT(*) c FROM billing_remittances WHERE billing_account_id = ?", [$acc])['c'];
        // exact split: company pays b1 stay (2,330) + b2 everything (1,165)
        $rem = rh_billing_record_remittance($pdo, $acc, ['amount' => '3,495.00'] + $base + ['lines' => [$b1 => '2330', $b2 => '1165']], $user);
        $out['rem'] = ['reference' => $rem['reference'], 'legs' => count($rem['legs']), 'amount' => $rem['amount']];
        $out['legs'] = [];
        foreach ($rem['legs'] as $l) {
            $out['legs'][] = br_row($pdo, "SELECT total_amount, payment_amount, vat_amount, payment_type, payment_status, payment_method, receipt_number, notes FROM payments WHERE id = ?", [$l['payment_id']]);
        }
        $out['after1'] = br_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$b1]);
        $out['after2'] = br_row($pdo, "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?", [$b2]);
        $out['split1_after'] = array_intersect_key(rh_booking_bill_split($pdo, $b1), array_flip(['company_due', 'guest_due', 'company_paid']));
        $out['rem_history'] = br_row($pdo, "SELECT action FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Company payment recorded' ORDER BY id DESC LIMIT 1", [$b1]);
        // Edit payment refuses a company leg
        $out['edit_leg'] = br_try(function () use ($pdo, $rem, $user) {
            rh_payment_edit_apply($pdo, (int)$rem['legs'][0]['payment_id'], ['amount' => '100', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'transaction_reference' => '', 'notes' => '', 'reason' => 'test edit'], $user);
        });
        // a refund of a company leg puts the share back on the company (read from the payment rows, not the line)
        $leg = br_row($pdo, "SELECT * FROM payments WHERE id = ?", [(int)$rem['legs'][0]['payment_id']]);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, created_at)
            VALUES (?, 'room', ?, ?, CURDATE(), 500, 0, 0, 500, 'bank_transfer', 'refund', 'completed', ?, 'other', 'completed', 500, NOW())")
            ->execute(['BRTR' . random_int(100000, 999999), $b1, $leg['booking_reference'], (int)$leg['id']]);
        recalculateBookingFinancials($b1);
        $out['split1_refund'] = array_intersect_key(rh_booking_bill_split($pdo, $b1), array_flip(['company_due', 'guest_due', 'company_paid', 'amount_due']));
        // take the company off b1: the automatic 083 note switches off too
        rh_billing_set_on_booking($pdo, $b1, ['account_id' => 0], $user);
        $out['b1_off'] = br_row($pdo, "SELECT checkin_without_payment, checkin_arrangement_note, billing_account_id FROM bookings WHERE id = ?", [$b1]);
        break;

    case 'group':
        [$p] = br_booking($pdo, 'checked-in', 1);
        [$m] = br_booking($pdo, 'checked-in', 1, $p);
        rh_billing_set_on_booking($pdo, $p, ['account_id' => $acc, 'reference' => 'LPO-G'], $user);
        $sm = rh_booking_bill_split($pdo, $m);
        $out['member'] = ['on_account' => $sm['on_account'], 'company_due' => $sm['company_due'], 'reference' => $sm['reference']];
        $out['open'] = count(rh_billing_open_bookings($pdo, $acc));
        // Chasers: a confirmed, unpaid, future member of an on-account primary is never a reminder / auto-release candidate.
        [$cp] = br_booking($pdo, 'confirmed', 1);
        [$cm] = br_booking($pdo, 'confirmed', 1, $cp);
        [$cx] = br_booking($pdo, 'confirmed', 1); // control: same, no company
        $fut = date('Y-m-d', strtotime('+30 day'));
        $pdo->prepare("UPDATE bookings SET check_in_date = ?, check_out_date = DATE_ADD(?, INTERVAL 1 DAY), created_at = NOW() - INTERVAL 10 DAY WHERE id IN (?, ?, ?)")->execute([$fut, $fut, $cp, $cm, $cx]);
        $pdo->prepare("UPDATE bookings SET billing_account_id = ? WHERE id = ?")->execute([$acc, $cp]); // primary on account; member has no link, no 083 switch
        require_once $root . '/includes/auto-email-jobs.php';
        $cands = rh_auto_unpaid_candidates($pdo, 1);
        $out['chase'] = ['member' => in_array($cm, $cands, true), 'primary' => in_array($cp, $cands, true), 'control' => in_array($cx, $cands, true)];
        $noPay = rh_booking_has_payment_sql('b');
        $still = $pdo->prepare("SELECT 1 FROM bookings b WHERE b.id = ? AND b.status = 'confirmed' AND b.check_in_date > CURDATE() AND NOT {$noPay}" . rh_checkin_arrangement_exclude_sql('b'));
        $still->execute([$cm]);
        $out['release_member'] = (bool)$still->fetchColumn();
        $still->execute([$cx]);
        $out['release_control'] = (bool)$still->fetchColumn();
        // 083 the same way: a member whose primary has "Check in before payment" on (no company) is left out too.
        $pdo->prepare("UPDATE bookings SET billing_account_id = NULL, checkin_without_payment = 1, checkin_arrangement_note = 'test' WHERE id = ?")->execute([$cp]);
        $out['chase_083_member'] = in_array($cm, rh_auto_unpaid_candidates($pdo, 1), true);
        break;

    case 'docs':
        // No billing email: statement and invoice are NOT sent anywhere, logged, staff told.
        require_once $root . '/config/invoice.php';
        [$b] = br_booking($pdo, 'checked-out', 1);
        rh_billing_set_on_booking($pdo, $b, ['account_id' => $acc, 'reference' => 'LPO-D'], $user);
        $st = rh_billing_send_statement($pdo, $acc);
        $out['statement'] = $st;
        $out['statement_log'] = br_row($pdo, "SELECT status, email_kind, account_type FROM email_send_log WHERE account_type = 'company' AND account_id = ? ORDER BY id DESC LIMIT 1", [$acc]);
        $inv = generateAndSendFinalInvoice($b, $user['id'], true);
        $out['invoice'] = array_intersect_key($inv, array_flip(['success', 'email_sent', 'company', 'staff_note']));
        $out['invoice_log'] = br_row($pdo, "SELECT status, email_kind FROM email_send_log WHERE account_type = 'room' AND account_id = ? ORDER BY id DESC LIMIT 1", [$b]);
        $out['invoice_history'] = br_row($pdo, "SELECT action, description FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Checked out on company account' LIMIT 1", [$b]);
        $acRow = rh_billing_account($pdo, $acc);
        $html = rh_billing_statement_html($pdo, $acRow, rh_billing_open_bookings($pdo, $acc));
        $out['statement_html_has_lpo'] = strpos($html, 'LPO-D') !== false;
        $bk = br_row($pdo, "SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?", [$b]);
        $ih = buildInvoiceHTML($bk, 'TEST-INV-1', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK');
        $out['invoice_billed_to_company'] = strpos($ih, htmlspecialchars((string)$acRow['name'])) !== false && strpos($ih, 'LPO-D') !== false;
        $out['sent_rows'] = (int)br_row($pdo, "SELECT COUNT(*) c FROM email_send_log WHERE ((account_type = 'company' AND account_id = ?) OR (account_type = 'room' AND account_id = ?)) AND status <> 'not_sent'", [$acc, $b])['c'];
        break;
}
echo json_encode($out);
