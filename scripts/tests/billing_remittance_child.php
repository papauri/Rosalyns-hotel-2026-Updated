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
        // b1 still holds 1,830 of company money: taking the company off is refused (fix 7)
        $out['b1_off_refused'] = br_try(function () use ($pdo, $b1, $user) { rh_billing_set_on_booking($pdo, $b1, ['account_id' => 0], $user); });
        // take the company off a booking with no company money: the automatic 083 note switches off too
        [$b4] = br_booking($pdo, 'confirmed', 1);
        rh_billing_set_on_booking($pdo, $b4, ['account_id' => $acc, 'reference' => 'LPO-79'], $user);
        rh_billing_set_on_booking($pdo, $b4, ['account_id' => 0], $user);
        $out['b1_off'] = br_row($pdo, "SELECT checkin_without_payment, checkin_arrangement_note, billing_account_id FROM bookings WHERE id = ?", [$b4]);
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

    case 'fixes':
        // Fix round 2026-11-13 (independent audit repros). Nothing is emailed: the company has no billing email and the
        // bookings have no guest email.
        require_once $root . '/config/invoice.php';
        require_once $root . '/config/credit-notes.php';
        require_once $root . '/config/email.php';
        require_once $root . '/includes/stay-nights.php';
        require_once $root . '/admin/includes/billing-panel.php';
        $gpay = static function (PDO $pdo, int $bid, float $gross, string $date) {
            $sp = rh_account_vat_split($pdo, 'room', $bid, $gross, 16.5);
            $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, receipt_number, created_at)
                VALUES (?, 'room', ?, (SELECT booking_reference FROM bookings WHERE id = ?), ?, ?, ?, ?, ?, 'cash', 'partial_payment', 'completed', ?, NOW())")
                ->execute(['BRGP' . random_int(100000, 999999), $bid, $bid, $date, $sp['net'], $sp['rate'], $sp['vat'], $gross, 'RBRG' . random_int(100000, 999999)]);
            $id = (int)$pdo->lastInsertId();
            recalculateBookingFinancials($bid);
            return $id;
        };
        $who = static function (array $allocs, int $legId): array {
            return array_map(static fn($a) => [((int)$a['payment']['id'] === $legId ? 'company' : 'guest'), round((float)$a['amount'], 2)], $allocs);
        };
        $remit = static function (PDO $pdo, int $acc, int $bid, float $amt, array $user, string $date = '') {
            return rh_billing_record_remittance($pdo, $acc, ['amount' => (string)$amt, 'payment_method' => 'bank_transfer', 'received_date' => $date !== '' ? $date : date('Y-m-d'),
                'bank_reference' => 'BT-FX', 'notes' => '', 'lines' => [$bid => (string)$amt]], $user);
        };

        // F1a: company paid the 3-night stay (3,495), the guest paid its 200 bar bill in cash afterwards; the stay
        // shrinks to 2 nights: the 1,165 going back is company money, not the guest's.
        [$f1] = br_booking($pdo, 'checked-in', 3);
        br_charge($pdo, $f1, 200.0);
        rh_billing_set_on_booking($pdo, $f1, ['account_id' => $acc, 'reference' => 'LPO-F1', 'covers' => 'stay'], $user);
        $r1 = $remit($pdo, $acc, $f1, 3495.0, $user, date('Y-m-d', strtotime('-1 day')));
        $leg1 = (int)$r1['legs'][0]['payment_id'];
        $gpay($pdo, $f1, 200.0, date('Y-m-d'));
        $pdo->prepare('UPDATE bookings SET number_of_nights = 2, total_amount = 2000, vat_amount = 330, total_with_vat = 2330 WHERE id = ?')->execute([$f1]);
        recalculateBookingFinancials($f1);
        $out['f1a'] = $who(allocateBookingRefunds($pdo, $f1, 1165.0, false), $leg1);
        // F1b: the guest paid 300 against a 200 bar bill BEFORE the company paid the stay: the 100 going back is the guest's.
        [$f1b] = br_booking($pdo, 'checked-in', 1);
        br_charge($pdo, $f1b, 200.0);
        rh_billing_set_on_booking($pdo, $f1b, ['account_id' => $acc, 'reference' => 'LPO-F1B', 'covers' => 'stay'], $user);
        $gpay($pdo, $f1b, 300.0, date('Y-m-d', strtotime('-2 day')));
        $r1b = $remit($pdo, $acc, $f1b, 1065.0, $user);
        $out['f1b'] = $who(allocateBookingRefunds($pdo, $f1b, 100.0, false), (int)$r1b['legs'][0]['payment_id']);
        // F1c: not on account: newest payment first, as before.
        [$f1c] = br_booking($pdo, 'checked-in', 1);
        $p1 = $gpay($pdo, $f1c, 600.0, date('Y-m-d', strtotime('-1 day')));
        $gpay($pdo, $f1c, 565.0, date('Y-m-d'));
        $out['f1c'] = $who(allocateBookingRefunds($pdo, $f1c, 700.0, false), $p1); // 'company' here = the OLDER payment
        // F1d: refund notice for a company leg goes to the company billing email; none set = not sent, logged.
        $legRow = br_row($pdo, 'SELECT * FROM payments WHERE id = ?', [$leg1]);
        $out['f1d_mail'] = sendRefundNotificationEmail($legRow, 'BRTEST-REF', 100.0, 'early_checkout');
        $out['f1d_log'] = br_row($pdo, "SELECT status, email_kind, error FROM email_send_log WHERE email_kind = 'refund' ORDER BY id DESC LIMIT 1");
        // F1e: nights taken off as a credit note on a company-paid stay: the note is made out to the company.
        $in = date('Y-m-d', strtotime('-1 day'));
        $pdo->prepare('UPDATE bookings SET check_in_date = ?, check_out_date = ?, number_of_nights = 3, total_amount = 3000, vat_amount = 495, total_with_vat = 3495 WHERE id = ?')
            ->execute([$in, date('Y-m-d', strtotime('+2 day')), $f1]);
        recalculateBookingFinancials($f1);
        $fb = br_row($pdo, 'SELECT * FROM bookings WHERE id = ?', [$f1]);
        $qn = rh_stay_nights_quote($pdo, $fb, -1, null, false, 'MWK');
        $out['f1e_quote'] = ['ok' => $qn['ok'] ?? false, 'error' => $qn['error'] ?? '', 'money_back' => $qn['money_back'] ?? null];
        if (!empty($qn['ok'])) {
            try {
                $sn = rh_stay_nights_apply($pdo, $f1, ['delta' => -1, 'expected_new_total' => $qn['new_total'], 'expected_total_delta' => $qn['total_delta'], 'reason' => 'company test',
                    'money_back_mode' => 'credit_note', 'money_back_typed' => number_format((float)$qn['money_back'], 2), 'can_refund' => true], $user);
                $cnId = (int)($sn['credit_note']['id'] ?? 0);
                $out['f1e_cn'] = br_row($pdo, 'SELECT guest_name, guest_email, original_amount FROM credit_notes WHERE id = ?', [$cnId]);
                $out['f1e_cns'] = (int)br_row($pdo, "SELECT COUNT(*) c FROM credit_notes WHERE booking_type = 'room' AND booking_id = ?", [$f1])['c'];
            } catch (Throwable $e) {
                $out['f1e_err'] = $e->getMessage();
            }
        }

        // F2: company invoice = the company's share only; the guest gets an extras-only document.
        [$f2] = br_booking($pdo, 'checked-out', 2); // 2,330 stay
        br_charge($pdo, $f2, 200.0);                // guest's bar bill
        rh_billing_set_on_booking($pdo, $f2, ['account_id' => $acc, 'reference' => 'LPO-F2', 'covers' => 'stay'], $user);
        $bk2 = br_row($pdo, 'SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?', [$f2]);
        $ih = buildInvoiceHTML($bk2, 'TEST-INV-F2', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK');
        $out['f2_company'] = ['has_2330' => strpos($ih, '2,330.00') !== false, 'has_2530' => strpos($ih, '2,530.00') !== false, 'has_bar' => strpos($ih, 'Bar (test)') !== false];
        if (function_exists('rh_guest_extras_invoice_html')) {
            $gh = rh_guest_extras_invoice_html($bk2, 'EXT-TEST');
            $out['f2_guest'] = ['has_bar' => strpos($gh, 'Bar (test)') !== false, 'has_200' => strpos($gh, '200.00') !== false, 'has_2330' => strpos($gh, '2,330.00') !== false,
                'billed_guest' => strpos($gh, 'COMPANY BILL TEST') !== false];
        }
        $fin = generateAndSendFinalInvoice($f2, $user['id'], null);
        $out['f2_final'] = array_intersect_key($fin, array_flip(['success', 'email_sent', 'balance_due', 'staff_note', 'guest_extras']));
        $out['f2_final_again'] = !empty(generateAndSendFinalInvoice($f2, $user['id'], null)['idempotent']);

        // F4: a group member of an on-account primary can check in (the switch lives on the primary).
        [$p4] = br_booking($pdo, 'checked-in', 1);
        [$m4] = br_booking($pdo, 'confirmed', 1, $p4);
        $pdo->prepare('UPDATE bookings SET check_in_date = CURDATE(), check_out_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) WHERE id = ?')->execute([$m4]);
        rh_billing_set_on_booking($pdo, $p4, ['account_id' => $acc, 'reference' => 'LPO-G4'], $user);
        $mr = br_row($pdo, 'SELECT * FROM bookings WHERE id = ?', [$m4]);
        $out['f4_gate'] = rh_checkin_payment_gate($mr);
        $out['f4_allowed'] = !empty(validateCheckIn($mr)['allowed']);
        // ... also when only the primary's company is set (its switch turned off)
        $pdo->prepare('UPDATE bookings SET checkin_without_payment = 0 WHERE id = ?')->execute([$p4]);
        $out['f4_gate_company_only'] = rh_checkin_payment_gate($mr);
        [$m4x] = br_booking($pdo, 'confirmed', 1); // control: no arrangement anywhere
        $out['f4_control'] = rh_checkin_payment_gate(br_row($pdo, 'SELECT * FROM bookings WHERE id = ?', [$m4x]));

        // F5: fully paid on-account booking: checkout assess still names the company.
        [$f5] = br_booking($pdo, 'checked-in', 1);
        rh_billing_set_on_booking($pdo, $f5, ['account_id' => $acc, 'reference' => 'LPO-F5'], $user);
        $remit($pdo, $acc, $f5, 1165.0, $user);
        $g5 = evaluateCheckoutBalance($pdo, $f5, null, false);
        $out['f5'] = array_intersect_key($g5, array_flip(['allowed', 'balance', 'company_due', 'company_name']));

        // F6: the company is deactivated; an LPO edit keeps the company.
        [$f6] = br_booking($pdo, 'confirmed', 1);
        rh_billing_set_on_booking($pdo, $f6, ['account_id' => $acc, 'reference' => 'LPO-F6'], $user);
        $pdo->prepare('UPDATE billing_accounts SET active = 0 WHERE id = ?')->execute([$acc]);
        $out['f6_edit'] = br_try(function () use ($pdo, $f6, $acc, $user) { rh_billing_set_on_booking($pdo, $f6, ['account_id' => $acc, 'reference' => 'LPO-F6b'], $user); });
        $out['f6_row'] = br_row($pdo, 'SELECT billing_account_id, billing_reference FROM bookings WHERE id = ?', [$f6]);
        $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, NULL, 30, 0, NOW())")->execute(['ZZ OFF CO ' . random_int(1000, 9999)]);
        $off = (int)$pdo->lastInsertId();
        $out['f6_other_inactive'] = br_try(function () use ($pdo, $f6, $off, $user) { rh_billing_set_on_booking($pdo, $f6, ['account_id' => $off], $user); });
        if (function_exists('rh_billing_panel_render')) {
            ob_start();
            rh_billing_panel_render($pdo, br_row($pdo, 'SELECT * FROM bookings WHERE id = ?', [$f6]), $user, 'x');
            $panel = (string)ob_get_clean();
            $out['f6_panel_lists_current'] = strpos($panel, 'value="' . $acc . '" selected') !== false && stripos($panel, 'inactive') !== false;
        }
        $pdo->prepare('UPDATE billing_accounts SET active = 1 WHERE id = ?')->execute([$acc]);

        // F7: company money on the booking: removing or switching the company is refused.
        $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, NULL, 30, 1, NOW())")->execute(['ZZ OTHER CO ' . random_int(1000, 9999)]);
        $other = (int)$pdo->lastInsertId();
        $out['f7_remove'] = br_try(function () use ($pdo, $f5, $user) { rh_billing_set_on_booking($pdo, $f5, ['account_id' => 0], $user); });
        $out['f7_switch'] = br_try(function () use ($pdo, $f5, $other, $user) { rh_billing_set_on_booking($pdo, $f5, ['account_id' => $other], $user); });
        $out['f7_row'] = (int)br_row($pdo, 'SELECT billing_account_id FROM bookings WHERE id = ?', [$f5])['billing_account_id'];
        $out['f7_lpo_edit'] = br_try(function () use ($pdo, $f5, $acc, $user) { rh_billing_set_on_booking($pdo, $f5, ['account_id' => $acc, 'reference' => 'LPO-F5b'], $user); });

        // F8: "received this month" nets refunds of company payments.
        if (function_exists('rh_billing_received_net')) {
            $from = date('Y-m-01');
            $before = rh_billing_received_net($pdo, $from, $acc);
            $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, created_at)
                VALUES (?, 'room', ?, 'X', CURDATE(), 300, 0, 0, 300, 'bank_transfer', 'refund', 'completed', ?, 'other', 'completed', 300, NOW())")
                ->execute(['BRF8' . random_int(100000, 999999), $f5, (int)br_row($pdo, 'SELECT payment_id FROM billing_remittance_lines WHERE booking_id = ?', [$f5])['payment_id']]);
            $out['f8'] = ['before' => $before, 'after' => rh_billing_received_net($pdo, $from, $acc)];
        }
        break;

    case 'later_fixes':
        // LATER round 2026-11-13: split-payer group documents, the invoice email copy, credit notes made out to who
        // overpaid, the company payment idempotency id.
        require_once $root . '/config/invoice.php';
        require_once $root . '/config/credit-notes.php';
        require_once $root . '/includes/idempotency.php';
        $rowOf = static function (PDO $pdo, int $id): array {
            return br_row($pdo, 'SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?', [$id]);
        };
        $accName = br_row($pdo, 'SELECT name FROM billing_accounts WHERE id = ?', [$acc])['name'];
        $pdo->prepare("UPDATE billing_accounts SET billing_email = 'a-billing@example.invalid' WHERE id = ?")->execute([$acc]);
        $pdo->prepare("INSERT INTO billing_accounts (name, billing_email, terms_days, active, created_at) VALUES (?, 'b-billing@example.invalid', 30, 1, NOW())")
            ->execute(['ZZ TEST CO B ' . random_int(1000, 9999)]);
        $accB = (int)$pdo->lastInsertId();
        $accBName = br_row($pdo, 'SELECT name FROM billing_accounts WHERE id = ?', [$accB])['name'];
        // Group: primary + m1 on company A (stay), m2 on its OWN company B (stay). 1,165 a room.
        [$p] = br_booking($pdo, 'checked-in', 1);
        [$m1] = br_booking($pdo, 'checked-in', 1, $p);
        [$m2] = br_booking($pdo, 'checked-in', 1, $p);
        rh_billing_set_on_booking($pdo, $p, ['account_id' => $acc, 'reference' => 'LPO-A', 'covers' => 'stay'], $user);
        $pdo->prepare("UPDATE bookings SET billing_account_id = ?, billing_reference = 'LPO-B', billing_covers = 'stay', checkin_without_payment = 1 WHERE id = ?")->execute([$accB, $m2]);
        $out['lf_scope'] = ['p' => rh_billing_doc_scope($pdo, $p), 'm1' => rh_billing_doc_scope($pdo, $m1), 'm2' => rh_billing_doc_scope($pdo, $m2), 'ids' => [$p, $m1, $m2]];
        $out['lf_split'] = ['p' => rh_billing_group_split($pdo, $p)['company_due'], 'm2' => rh_billing_group_split($pdo, $m2)['company_due']];
        $doc = static function (PDO $pdo, int $id) {
            $rows = rh_invoice_doc_rows($pdo, $id);
            $t = null;
            $h = buildInvoiceHTML($rows['booking'], 'TEST-LF', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
            return [$h, $t, (int)$rows['booking']['id'], count($rows['group_bookings'])];
        };
        [$hA, $tA, $leadA, $nA] = $doc($pdo, $m1);
        [$hB, $tB, $leadB, $nB] = $doc($pdo, $m2);
        $out['lf_docA'] = ['lead' => $leadA, 'rooms' => $nA, 'total' => $tA['total'] ?? null, 'balance' => $tA['balance'] ?? null, 'ids' => $tA['ids'] ?? null,
            'billed_A' => strpos($hA, $accName) !== false, 'billed_B' => strpos($hA, $accBName) !== false, 'has_3495' => strpos($hA, '3,495.00') !== false];
        $out['lf_docB'] = ['lead' => $leadB, 'rooms' => $nB, 'total' => $tB['total'] ?? null, 'balance' => $tB['balance'] ?? null, 'ids' => $tB['ids'] ?? null,
            'billed_A' => strpos($hB, $accName) !== false, 'billed_B' => strpos($hB, $accBName) !== false];
        // The invoice email copy for m1 (asked for one room, the company name already put in guest_name by the sender):
        // same rooms and figures as the attachment, the real guest named.
        $copy = rh_invoice_email_copy($pdo, ['guest_name' => $accName, 'guest_email' => 'a-billing@example.invalid'] + $rowOf($pdo, $m1), 'TEST-LF');
        $out['lf_copy'] = ['total' => $copy['totals']['total'] ?? null, 'balance' => $copy['totals']['balance'] ?? null, 'ids' => $copy['totals']['ids'] ?? null,
            'has_2330' => strpos($copy['html'], '2,330.00') !== false, 'guest_named' => strpos($copy['html'], 'COMPANY BILL TEST') !== false];

        // Overpayment credit notes made out to who overpaid. One booking on company A, 2 nights (2,330): the company pays the
        // stay, the guest pays 200 on top, then the bill drops to 1 night (1,165): company overpaid 1,165, guest 200.
        [$c] = br_booking($pdo, 'checked-in', 2);
        rh_billing_set_on_booking($pdo, $c, ['account_id' => $acc, 'reference' => 'LPO-CN', 'covers' => 'stay'], $user);
        rh_billing_record_remittance($pdo, $acc, ['amount' => '2330', 'payment_method' => 'bank_transfer', 'received_date' => date('Y-m-d'), 'lines' => [$c => '2330'],
            'client_uuid' => 'brlf-' . bin2hex(random_bytes(6))], $user);
        $sp = rh_account_vat_split($pdo, 'room', $c, 200.0, 16.5);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, ?, ?, 200, 'cash', 'partial_payment', 'completed', NOW())")
            ->execute(['BRLF' . random_int(100000, 999999), $c, $sp['net'], $sp['rate'], $sp['vat']]);
        $pdo->prepare("UPDATE bookings SET number_of_nights = 1, check_in_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY), total_amount = 1000, vat_amount = 165, total_with_vat = 1165 WHERE id = ?")->execute([$c]);
        recalculateBookingFinancials($c);
        $out['lf_credit_before'] = (float)br_row($pdo, 'SELECT credit_balance FROM bookings WHERE id = ?', [$c])['credit_balance'];
        $cn = rh_auto_credit_overpayment($pdo, 'room', $c, 5000, 'TESTREF', $user['id']);
        $out['lf_cn'] = array_map(static function ($n) use ($pdo) {
            $r = br_row($pdo, 'SELECT guest_name, guest_email, original_amount, reason_notes FROM credit_notes WHERE id = ?', [$n['credit_note_id']]);
            $legs = br_row($pdo, "SELECT COUNT(*) n, SUM(CASE WHEN l.payment_id IS NULL THEN 1 ELSE 0 END) guest_legs FROM payments f LEFT JOIN billing_remittance_lines l ON l.payment_id = f.original_payment_id
                WHERE f.credit_note_id = ? AND f.payment_type = 'refund' AND f.deleted_at IS NULL", [$n['credit_note_id']]);
            return ['payer' => $n['payer'], 'name' => $r['guest_name'], 'email' => $r['guest_email'], 'amount' => (float)$r['original_amount'],
                'lpo' => strpos((string)$r['reason_notes'], 'LPO-CN') !== false, 'legs' => (int)$legs['n'], 'guest_legs' => (int)$legs['guest_legs']];
        }, $cn['notes'] ?? []);
        $out['lf_credit_after'] = (float)br_row($pdo, 'SELECT credit_balance FROM bookings WHERE id = ?', [$c])['credit_balance'];
        // Not on account: one note to the guest, as before.
        [$g] = br_booking($pdo, 'checked-in', 1);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), 1500, 0, 0, 1500, 'cash', 'full_payment', 'completed', NOW())")
            ->execute(['BRLG' . random_int(100000, 999999), $g]);
        recalculateBookingFinancials($g);
        $gn = rh_auto_credit_overpayment($pdo, 'room', $g, 5000, 'TESTREF2', $user['id']);
        $out['lf_guest_cn'] = ['count' => count($gn['notes'] ?? []), 'payer' => $gn['notes'][0]['payer'] ?? null,
            'name' => br_row($pdo, 'SELECT guest_name FROM credit_notes WHERE id = ?', [(int)($gn['credit_note_id'] ?? 0)])['guest_name'] ?? null, 'amount' => $gn['excess'] ?? null];
        // The company payment's idempotency id is on its payment row (a replayed Add Payment finds it).
        $uu = 'brlf-' . bin2hex(random_bytes(8));
        [$u] = br_booking($pdo, 'checked-in', 1);
        rh_billing_set_on_booking($pdo, $u, ['account_id' => $acc, 'covers' => 'stay'], $user);
        $rem = rh_billing_record_remittance($pdo, $acc, ['amount' => '500', 'payment_method' => 'cash', 'received_date' => date('Y-m-d'), 'lines' => [$u => '500'], 'client_uuid' => $uu], $user);
        $found = idem_find_existing_payment($pdo, $uu);
        $out['lf_uuid'] = ['found' => (int)($found['id'] ?? 0) === (int)$rem['legs'][0]['payment_id']];
        // A booking that already holds its invoice number (converted quotation / Generate Invoice): the checkout final
        // invoice keeps that number, never a second one (no guest email on the fixture: nothing is sent).
        [$q] = br_booking($pdo, 'checked-out', 1);
        $qNo = 'QTLF-' . random_int(100000, 999999);
        $pdo->prepare('UPDATE bookings SET final_invoice_number = ? WHERE id = ?')->execute([$qNo, $q]);
        $fi = generateAndSendFinalInvoice($q, $user['id'], false);
        $out['lf_final_no'] = ['want' => $qNo, 'got' => $fi['invoice_number'] ?? null,
            'stored' => br_row($pdo, 'SELECT final_invoice_number n FROM bookings WHERE id = ?', [$q])['n'] ?? null];
        break;

    case 'group_invoice':
        // QA fix round: a 3-room group on a company account. Every figure covers ONLY the rooms the document lists.
        require_once $root . '/config/invoice.php';
        $roomName = static function (PDO $pdo, int $id): array {
            return br_row($pdo, 'SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?', [$id]);
        };
        [$p] = br_booking($pdo, 'checked-in', 1);        // 1,165 each
        [$m1] = br_booking($pdo, 'checked-in', 1, $p);
        [$m2] = br_booking($pdo, 'checked-in', 1, $p);
        br_charge($pdo, $m1, 100.0);                      // m1's guest has a bar bill
        rh_billing_set_on_booking($pdo, $p, ['account_id' => $acc, 'reference' => 'LPO-GRP', 'covers' => 'stay'], $user);
        // primary: the guest put 200 towards the stay, the company paid 765 -> company still owes 200 on it
        $sp = rh_account_vat_split($pdo, 'room', $p, 200.0, 16.5);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, ?, ?, 200, 'cash', 'partial_payment', 'completed', NOW())")
            ->execute(['BRGI' . random_int(100000, 999999), $p, $sp['net'], $sp['rate'], $sp['vat']]);
        recalculateBookingFinancials($p);
        rh_billing_record_remittance($pdo, $acc, ['amount' => '765', 'payment_method' => 'bank_transfer', 'received_date' => date('Y-m-d'), 'lines' => [$p => '765']], $user);
        // one room on its own (e.g. the invoice inside the invoice email): m1's figures only
        $h1 = buildInvoiceHTML($roomName($pdo, $m1), 'TEST-M1', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK');
        $out['gi_m1_alone'] = ['has_1165' => strpos($h1, '1,165.00') !== false, 'has_2530' => strpos($h1, '2,530.00') !== false, 'has_2330' => strpos($h1, '2,330.00') !== false,
            'has_765' => strpos($h1, '765.00') !== false, 'has_bar' => strpos($h1, 'Bar (test)') !== false];
        // the primary on its own: company paid 765 (company money only), guest 200 towards the stay, owes 200
        $hp = buildInvoiceHTML($roomName($pdo, $p), 'TEST-P', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK');
        $out['gi_p_alone'] = ['paid_765' => strpos($hp, '765.00') !== false, 'guest_row' => strpos($hp, 'Paid by the guest towards the stay') !== false,
            'owes_200' => strpos($hp, '200.00') !== false, 'has_965' => strpos($hp, '965.00') !== false];
        // the whole group, as generateInvoicePDF prints it (from the primary, every room): 200 + 1,165 + 1,165 = 2,530
        $grp = $pdo->prepare('SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = ? OR b.primary_booking_id = ? ORDER BY b.id');
        $grp->execute([$p, $p]);
        $hg = buildInvoiceHTML($roomName($pdo, $p), 'TEST-G', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $grp->fetchAll(PDO::FETCH_ASSOC));
        $out['gi_group'] = ['has_2530' => strpos($hg, '2,530.00') !== false, 'has_bar' => strpos($hg, 'Bar (test)') !== false];
        // m1 checks out: the final invoice matches the document it attaches (the group, from the primary); ONE extras
        // document, for m1's guest only
        $pdo->prepare("UPDATE bookings SET status = 'checked-out' WHERE id = ?")->execute([$m1]);
        $fm = generateAndSendFinalInvoice($m1, $user['id'], null);
        $out['gi_final'] = ['balance_due' => $fm['balance_due'] ?? null, 'extras' => $fm['guest_extras']['number'] ?? null];
        $out['gi_refs'] = ['m1' => br_row($pdo, 'SELECT booking_reference r FROM bookings WHERE id = ?', [$m1])['r'], 'm2' => br_row($pdo, 'SELECT booking_reference r FROM bookings WHERE id = ?', [$m2])['r']];
        // the invoice email for one room states that room's company share only
        $out['gi_email_due'] = rh_billing_split_for_ids($pdo, [$m2])['company_due'];
        break;

    case 'group_docs':
    case 'group_docs_custom':
    case 'group_docs_excl':
        // Group booking documents (owner decisions 2026-11-13): per-room rows with each room's own guest and dates, extras
        // under their own room, a cancelled / deleted room with no money comes off, one with money stays marked, and the
        // receipt of one room prints only that room + "Part of group REF (N rooms)". Not on a company account.
        if ($sc === 'group_docs_custom') {
            // In memory only (no DB / file cache write): render the admin-customised stored template path.
            $GLOBALS['_SITE_SETTINGS']['invoice_document_template_mode'] = 'custom';
        }
        if ($sc === 'group_docs_excl') {
            $GLOBALS['_SITE_SETTINGS']['vat_pricing_mode'] = 'exclusive'; // in memory only: VAT shown as its own row
        }
        require_once $root . '/config/invoice.php';
        require_once $root . '/includes/booked-rate.php';
        [$p, $pRef] = br_booking($pdo, 'checked-in', 2);   // lead, 2,330
        [$m1] = br_booking($pdo, 'checked-in', 1, $p);     // 1,165 incl. a 165 child supplement + a 200 bar bill
        [$c1] = br_booking($pdo, 'confirmed', 2, $p);      // cancelled, first night kept (1,165), guest paid 1,165
        [$c2] = br_booking($pdo, 'confirmed', 1, $p);      // cancelled, nothing kept, nothing paid -> off
        [$d1] = br_booking($pdo, 'confirmed', 1, $p);      // deleted, nothing kept, nothing paid -> off
        [$e1] = br_booking($pdo, 'confirmed', 1, $p);      // expired: its folio summary still bills the full stay (1,165) -> stays, marked
        $guestOf = [$p => 'GDOC LEAD', $m1 => 'GDOC MEMBER', $c1 => 'GDOC CANCELPAID', $c2 => 'GDOC CANCELZERO', $d1 => 'GDOC DELETED', $e1 => 'GDOC EXPIRED'];
        foreach ($guestOf as $id => $g) {
            $pdo->prepare('UPDATE bookings SET room_guest_name = ? WHERE id = ?')->execute([$g, $id]);
        }
        $pdo->prepare("UPDATE bookings SET check_in_date = DATE_ADD(check_in_date, INTERVAL 1 DAY), check_out_date = DATE_ADD(check_out_date, INTERVAL 1 DAY) WHERE id = ?")->execute([$m1]);
        $pdo->prepare('UPDATE bookings SET child_guests = 1, number_of_guests = 2, child_supplement_total = 165 WHERE id = ?')->execute([$m1]);
        br_charge($pdo, $m1, 200.0);
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 1165 WHERE id = ?")->execute([$c1]);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), 1165, 0, 0, 1165, 'cash', 'partial_payment', 'completed', NOW())")
            ->execute(['BRGD' . random_int(100000, 999999), $c1]);
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = NULL WHERE id IN (?, ?)")->execute([$c2, $d1]);
        $pdo->prepare('UPDATE bookings SET deleted_at = NOW() WHERE id = ?')->execute([$d1]);
        $pdo->prepare("UPDATE bookings SET status = 'expired' WHERE id = ?")->execute([$e1]);
        foreach ([$p, $m1, $c1, $c2, $d1, $e1] as $id) {
            recalculateBookingFinancials($id);
        }
        $rows = rh_invoice_doc_rows($pdo, $m1);
        $t = null;
        $h = buildInvoiceHTML($rows['booking'], 'TEST-GD', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
        $pos = static function (string $needle) use ($h): int {
            $at = stripos($h, $needle);
            return $at === false ? -1 : $at;
        };
        $sumGrand = 0.0;
        $sumPaid = 0.0;
        foreach ([$p, $m1, $c1, $e1] as $id) {
            $fs = getBookingFolioSummary($id);
            $sumGrand += (float)$fs['grand_total'];
            $sumPaid += (float)$fs['amount_paid'];
        }
        $paidRows = (float)br_row($pdo, "SELECT COALESCE(SUM(total_amount),0) s FROM payments WHERE booking_type = 'room' AND deleted_at IS NULL AND payment_status = 'completed'
            AND booking_id IN (?, ?, ?, ?, ?, ?)", [$p, $m1, $c1, $c2, $d1, $e1])['s'];
        $mIn = br_row($pdo, 'SELECT check_in_date, check_out_date FROM bookings WHERE id = ?', [$m1]);
        $out['gd'] = [
            'ids' => $t['ids'] ?? null, 'want_ids' => [$p, $m1, $c1, $e1],
            'total' => $t['total'] ?? null, 'paid' => $t['paid'] ?? null, 'balance' => $t['balance'] ?? null, 'subtotal' => $t['subtotal'] ?? null,
            'vat' => $t['vat'] ?? null, 'levy' => $t['levy'] ?? null,
            'sum_grand' => round($sumGrand, 2), 'sum_paid' => round($sumPaid, 2), 'paid_rows' => round($paidRows, 2),
            'p_lead' => $pos('GDOC LEAD'), 'p_member' => $pos('GDOC MEMBER'), 'p_child' => $pos('Child supplement'), 'p_bar' => $pos('Bar (test)'),
            'p_cancel' => $pos('Cancelled room:'), 'p_expired' => $pos('Expired room:'), 'p_eguest' => $pos('GDOC EXPIRED'), 'p_cpaid' => $pos('GDOC CANCELPAID'), 'zero_shown' => $pos('GDOC CANCELZERO'), 'deleted_shown' => $pos('GDOC DELETED'),
            'member_dates' => strpos($h, date('d M Y', strtotime($mIn['check_in_date'])) . ' to ' . date('d M Y', strtotime($mIn['check_out_date']))) !== false,
            'rooms_hdr' => strpos($h, '2 rooms') !== false, 'legacy' => strpos($h, '<!--rh-th=') === false, 'mode' => vat_mode(),
        ];
        // Receipts: one room only + "Part of group REF (N rooms)"; a booking outside a group prints exactly as before.
        $out['gd_receipt_m1'] = rh_booked_rate_room_receipt_lines($pdo, $m1);
        $out['gd_receipt_c1'] = rh_booked_rate_room_receipt_lines($pdo, $c1);
        $out['gd_ref'] = $pRef;
        [$solo] = br_booking($pdo, 'checked-in', 1);
        $out['gd_solo_same'] = rh_booked_rate_room_receipt_lines($pdo, $solo) === rh_booked_rate_group_lines(rh_booked_rate_group_texts($pdo, $solo));
        $out['gd_solo'] = rh_booked_rate_room_receipt_lines($pdo, $solo);
        break;

    case 'perf':
        // F9: the batch split (one query for company money, primary billing joined in) gives exactly the figures of
        // the per-booking rh_booking_bill_split() on every kind of booking.
        $ids = [];
        [$a1] = br_booking($pdo, 'checked-in', 2); br_charge($pdo, $a1, 200.0);
        rh_billing_set_on_booking($pdo, $a1, ['account_id' => $acc, 'reference' => 'P1', 'covers' => 'stay'], $user); $ids[] = $a1;            // stay + extras
        [$a2] = br_booking($pdo, 'checked-out', 1); br_charge($pdo, $a2, 150.0);
        rh_billing_set_on_booking($pdo, $a2, ['account_id' => $acc, 'covers' => 'everything'], $user); $ids[] = $a2;                           // everything
        [$a3] = br_booking($pdo, 'checked-in', 2); br_charge($pdo, $a3, 100.0);
        rh_billing_set_on_booking($pdo, $a3, ['account_id' => $acc], $user);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, created_at)
            VALUES (?, 'room', ?, 'X', CURDATE(), 300, 0, 0, 300, 'cash', 'partial_payment', 'completed', NOW())")->execute(['BRP3' . random_int(100000, 999999), $a3]);
        recalculateBookingFinancials($a3); $ids[] = $a3;                                                                                            // guest deposit
        [$a4] = br_booking($pdo, 'checked-in', 2);
        rh_billing_set_on_booking($pdo, $a4, ['account_id' => $acc], $user);
        $rm = rh_billing_record_remittance($pdo, $acc, ['amount' => '2330', 'payment_method' => 'bank_transfer', 'received_date' => date('Y-m-d'), 'lines' => [$a4 => '2330']], $user);
        $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, created_at)
            VALUES (?, 'room', ?, 'X', CURDATE(), 400, 0, 0, 400, 'bank_transfer', 'refund', 'completed', ?, 'other', 'completed', 400, NOW())")
            ->execute(['BRP4' . random_int(100000, 999999), $a4, (int)$rm['legs'][0]['payment_id']]);
        recalculateBookingFinancials($a4); $ids[] = $a4;                                                                                            // leg refund
        [$a5] = br_booking($pdo, 'confirmed', 2);
        rh_billing_set_on_booking($pdo, $a5, ['account_id' => $acc], $user);
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 1165 WHERE id = ?")->execute([$a5]);
        recalculateBookingFinancials($a5); $ids[] = $a5;                                                                                            // cancelled, first night kept
        [$a6] = br_booking($pdo, 'checked-in', 1, $a1); $ids[] = $a6;                                                                                // member via primary
        [$a7] = br_booking($pdo, 'checked-in', 1); $ids[] = $a7;                                                                                     // not on account
        $out['perf_ready'] = function_exists('rh_billing_splits_for_rows');
        if ($out['perf_ready']) {
            $in = implode(',', array_map('intval', $ids));
            $rows = $pdo->query("SELECT * FROM bookings WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            $batch = rh_billing_splits_for_rows($pdo, $rows);
            $diff = [];
            foreach ($ids as $id) {
                $old = rh_booking_bill_split($pdo, $id);
                $new = $batch[$id] ?? [];
                foreach (['on_account', 'company_due', 'guest_due', 'company_paid', 'guest_paid', 'amount_due', 'name', 'reference', 'due_date', 'covers', 'days_past_due', 'account_id'] as $k) {
                    if (!array_key_exists($k, $new) || (is_float($old[$k]) ? abs($old[$k] - (float)$new[$k]) > 0.001 : $old[$k] !== $new[$k])) {
                        $diff[] = $id . ':' . $k . ' old=' . json_encode($old[$k]) . ' new=' . json_encode($new[$k] ?? null);
                    }
                }
            }
            $out['perf_diff'] = $diff;
            $out['perf_count'] = count($batch);
            // the company page's single load gives the same totals as the per-company function
            $open = rh_billing_open_bookings($pdo, $acc);
            $out['perf_open'] = count($open);
            $out['perf_totals'] = rh_billing_account_totals($pdo, $acc);
            if (function_exists('rh_billing_totals_from_rows')) {
                $out['perf_totals_rows'] = rh_billing_totals_from_rows($open);
            }
            // receivables rows (batched) = the per-booking split
            $recv = array_values(array_filter(rh_receivable_rows($pdo, ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false]), static fn($r) => in_array($r['id'], $ids, true)));
            $rdiff = [];
            foreach ($recv as $r) {
                $o = rh_booking_bill_split($pdo, (int)$r['id']);
                if ($o['on_account'] && (abs($o['company_due'] - $r['company_due']) > 0.001 || abs($o['guest_due'] - $r['guest_due']) > 0.001)) {
                    $rdiff[] = $r['id'];
                }
            }
            $out['perf_recv_diff'] = $rdiff;
            $out['perf_recv_n'] = count($recv);
        }
        break;
}
echo json_encode($out);
