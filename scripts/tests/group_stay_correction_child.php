<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/** In-process scenarios for scripts/tests/group_stay_correction_test.php (run by page_harness.php, rolled back). */
$sc = (string)($_POST['scenario'] ?? '');
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/audit-functions.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/stay-correction.php';
require_once $root . '/includes/group-room-table.php';

$GS_ROOM = (int)$pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$GS_HAS_SNAP = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_policy_snapshot'")->fetchColumn();

/** A room booking billed 1,165 a night all-in (1,000 net + 165 VAT, no levy), no guest email (nothing is emailed). */
function gs_booking(PDO $pdo, string $status, string $in, string $out, ?int $primary = null, ?int $indRoom = null): array {
    global $GS_ROOM;
    $ref = 'GSCT' . random_int(100000, 999999);
    $n = (int)(new DateTime($in))->diff(new DateTime($out))->days;
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, primary_booking_id, guest_name, room_guest_name, guest_email, guest_phone, guest_country,
            number_of_guests, adult_guests, child_guests, child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total,
            tourism_levy_amount, tourism_levy_percent, vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,?,'GROUP STAY TEST',?,'','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'group stay correction test',?,'unpaid',0,'single',?,NOW())")
        ->execute([$ref, $GS_ROOM, $indRoom, $primary, 'Guest ' . substr($ref, -3), $in, $out, $n, 1000 * $n, 165 * $n, 1165 * $n, $status, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return [$id, $ref];
}
function gs_pay(PDO $pdo, int $bid, string $ref, float $gross): int {
    $sp = rh_account_vat_split($pdo, 'room', $bid, $gross, 16.5);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, invoice_generated, created_at)
        VALUES (?,'room',?,?,CURDATE(),?,?,?,?,'bank_transfer','full_payment','completed','completed',?,0,NOW())")
        ->execute(['GSCP' . random_int(100000, 999999), $bid, $ref, $sp['net'], $sp['rate'], $sp['vat'], $gross, 'RGSC' . random_int(100000, 999999)]);
    $pid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bid);
    return $pid;
}
function gs_bk(PDO $pdo, int $id): array { $st = $pdo->prepare("SELECT * FROM bookings WHERE id = ?"); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function gs_row(PDO $pdo, string $sql, array $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function gs_try(callable $fn): string { try { $fn(); return 'NOT REFUSED'; } catch (Throwable $e) { return $e->getMessage(); } }
/** A checked-out group like ICTAm: 3 rooms 3 nights at 3,495, 3,000 paid on each (owes 495); room 3 is a no-show; one deleted room. */
function gs_group(PDO $pdo, string $in, string $out, ?int $indRoom = null): array {
    [$r, $rRef] = gs_booking($pdo, 'checked-out', $in, $out, null, $indRoom);
    [$m2, $m2Ref] = gs_booking($pdo, 'checked-out', $in, $out, $r);
    [$m3, $m3Ref] = gs_booking($pdo, 'no-show', $in, $out, $r);
    [$m4] = gs_booking($pdo, 'checked-out', $in, $out, $r);
    $pdo->prepare("UPDATE bookings SET deleted_at = NOW() WHERE id = ?")->execute([$m4]);
    foreach ([[$r, $rRef], [$m2, $m2Ref], [$m3, $m3Ref]] as [$id, $ref]) {
        gs_pay($pdo, $id, $ref, 3000.00);
    }
    return ['root' => $r, 'root_ref' => $rRef, 'm2' => $m2, 'm3' => $m3, 'm4' => $m4];
}

$admin = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name']];
$GLOBALS['user'] = $user;
$in = date('Y-m-d', strtotime('-6 day'));
$out = date('Y-m-d', strtotime('-3 day'));
$outM1 = date('Y-m-d', strtotime('-4 day'));
$outP1 = date('Y-m-d', strtotime('-2 day'));
$res = ['scenario' => $sc, 'has_snap_col' => $GS_HAS_SNAP];
$pub = static function (array $q): array { return rh_stay_correction_public($q); };

switch ($sc) {
    case 'group_table':
        $g = gs_group($pdo, $in, $out);
        $t = rh_group_room_table($pdo, $g['m2'], 'K');
        $res['root'] = $t['root'];
        $res['expect_root'] = $g['root'];
        $res['ids'] = array_column($t['rows'], 'id');
        $res['expect_ids'] = [$g['root'], $g['m2'], $g['m3']];
        $res['deleted_out'] = !in_array($g['m4'], $res['ids'], true);
        $res['rows_match'] = true;
        $res['mismatch'] = [];
        $sum = ['billed' => 0.0, 'paid' => 0.0, 'owed' => 0.0, 'credit' => 0.0, 'refunds' => 0.0];
        foreach ($t['rows'] as $row) {
            $fs = getBookingFolioSummary((int)$row['id']);
            $b = gs_bk($pdo, (int)$row['id']);
            $credit = max(0.0, (float)$fs['amount_paid'] - (float)$fs['grand_total']);
            if ($credit <= BALANCE_TOLERANCE) { $credit = (float)$b['credit_balance']; }
            if (abs($row['billed'] - $fs['grand_total']) > 0.005 || abs($row['paid'] - $fs['amount_paid']) > 0.005
                || abs($row['owed'] - $fs['balance_due']) > 0.005 || abs($row['credit'] - $credit) > 0.005) {
                $res['rows_match'] = false;
                $res['mismatch'][] = [$row, $fs];
            }
            foreach ($sum as $k => $v) { $sum[$k] = round($v + $row[$k], 2); }
        }
        $res['sum'] = $sum;
        $res['totals'] = $t['totals'];
        $noshow = array_values(array_filter($t['rows'], static function ($r) use ($g) { return $r['id'] === $g['m3']; }))[0] ?? [];
        $res['noshow'] = ['status' => $noshow['status'] ?? '', 'paid' => $noshow['paid'] ?? null, 'billed' => $noshow['billed'] ?? null];
        $res['rate_compact'] = $t['rows'][0]['rate_compact'] ?? '';
        $res['from_root_same'] = rh_group_room_table($pdo, $g['root'], 'K')['totals'] === $t['totals'];
        // QA 2026-11-17: a group's ONE invoice number sits on the lead's payment row; the other rooms' rows hold only the
        // cached file path. The correction preview for a non-lead room must still name the group invoice.
        $gscNo = 'GSCTEST-' . random_int(100000, 999999);
        $pdo->prepare("UPDATE payments SET invoice_generated = 1, invoice_number = ?, invoice_path = ? WHERE booking_type = 'room' AND booking_id = ?")
            ->execute([$gscNo, 'invoices/' . $gscNo . '.pdf', $g['root']]);
        $pdo->prepare("UPDATE payments SET invoice_generated = 1, invoice_path = ? WHERE booking_type = 'room' AND booking_id = ?")
            ->execute(['invoices/' . $gscNo . '.pdf', $g['m2']]);
        $res['group_inv_expect'] = $gscNo;
        $res['group_inv_nums'] = rh_stay_correction_invoice_numbers($pdo, gs_bk($pdo, $g['m2']));
        break;

    case 'early_credit':
    case 'early_refund':
        $g = gs_group($pdo, $in, $out);
        $bid = $g['root'];
        $q = rh_stay_correction_quote($pdo, gs_bk($pdo, $bid), $in, $outM1, null, null, 'K');
        $res['q'] = $pub($q);
        $base = ['new_check_in' => $in, 'new_check_out' => $outM1, 'reason' => 'guest left a day early', 'expected_new_bill' => $q['new_bill'],
            'expected_money_back' => $q['money_back'], 'can_correct' => true, 'can_refund' => true];
        $res['no_perm'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, ['can_correct' => false] + $base, $user); });
        $res['no_reason'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, ['reason' => ''] + $base, $user); });
        $res['no_mode'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, $base, $user); });
        $res['refund_no_perm'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, ['money_back_mode' => 'refund', 'money_back_typed' => '670', 'can_refund' => false] + $base, $user); });
        $res['stale'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, ['expected_new_bill' => $base['expected_new_bill'] + 5, 'money_back_mode' => 'credit_note', 'money_back_typed' => '670'] + $base, $user); });
        $res['unchanged'] = array_intersect_key(gs_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1]);
        $mode = $sc === 'early_refund' ? 'refund' : 'credit_note';
        $ap = rh_stay_correction_apply($pdo, $bid, ['money_back_mode' => $mode, 'money_back_typed' => '670.00'] + $base, $user);
        $res['apply'] = ['refund_total' => $ap['refund_total'], 'credit_notes' => count($ap['credit_notes']), 'after' => $ap['after']];
        $res['row'] = array_intersect_key(gs_bk($pdo, $bid), ['check_in_date' => 1, 'check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1,
            'amount_due' => 1, 'amount_paid' => 1, 'credit_balance' => 1, 'status' => 1]);
        $res['fs'] = array_intersect_key(getBookingFolioSummary($bid), ['grand_total' => 1, 'amount_paid' => 1, 'balance_due' => 1]);
        $res['refund_rows'] = gs_row($pdo, "SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) s, MIN(payment_method) m FROM payments WHERE booking_id = ? AND booking_type = 'room' AND payment_type = 'refund'", [$bid]);
        $res['timeline'] = gs_row($pdo, "SELECT action, description FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $res['audit'] = (string)(gs_row($pdo, "SELECT action FROM booking_audit_log WHERE booking_id = ? AND action = 'stay_corrected_after_checkout' ORDER BY id DESC LIMIT 1", [$bid])['action'] ?? '');
        $res['date_log'] = gs_row($pdo, "SELECT old_check_out_date, new_check_out_date, adjustment_reason FROM booking_date_adjustments WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid]);
        $t = rh_group_room_table($pdo, $bid, 'K');
        $rootRow = $t['rows'][0];
        $res['table_root'] = ['refunds' => $rootRow['refunds'], 'cn' => $rootRow['credit_note_refunds'], 'credit' => $rootRow['credit'], 'owed' => $rootRow['owed'], 'billed' => $rootRow['billed']];
        $res['table_net'] = $t['totals']['net'];
        break;

    case 'late':
        $ir = (int)$pdo->query("SELECT ir.id FROM individual_rooms ir WHERE ir.is_active = 1 ORDER BY ir.id LIMIT 1")->fetchColumn();
        $g = gs_group($pdo, $in, $out, $ir ?: null);
        // Another guest stayed in the same room on the added night.
        [$other, $otherRef] = gs_booking($pdo, 'checked-out', $out, $outP1, null, $ir ?: null);
        $res['other_ref'] = $otherRef;
        $res['ir'] = $ir;
        // Room 2: one extra night at a different agreed rate (1,000 all-in).
        $m2 = $g['m2'];
        $q2 = rh_stay_correction_quote($pdo, gs_bk($pdo, $m2), $in, $outP1, 1000.0, null, 'K');
        $res['q_rate'] = $pub($q2);
        $b2 = ['new_check_in' => $in, 'new_check_out' => $outP1, 'extra_rate' => '1000', 'reason' => 'stayed one more night', 'expected_new_bill' => $q2['new_bill'],
            'expected_money_back' => $q2['money_back'], 'can_correct' => true, 'can_refund' => true];
        $res['rate_no_reason'] = gs_try(function () use ($pdo, $m2, $b2, $user) { rh_stay_correction_apply($pdo, $m2, $b2, $user); });
        rh_stay_correction_apply($pdo, $m2, ['rate_reason' => 'agreed lower rate for the extra night'] + $b2, $user);
        $res['m2_after'] = array_intersect_key(gs_bk($pdo, $m2), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1, 'amount_due' => 1]);
        // Root: one extra night at the booked rate, a clash with the other booking -> tick needed.
        $bid = $g['root'];
        $q = rh_stay_correction_quote($pdo, gs_bk($pdo, $bid), $in, $outP1, null, null, 'K');
        $res['q'] = $pub($q);
        $base = ['new_check_in' => $in, 'new_check_out' => $outP1, 'reason' => 'left a day later', 'expected_new_bill' => $q['new_bill'],
            'expected_money_back' => $q['money_back'], 'can_correct' => true, 'can_refund' => true];
        $res['no_ack'] = gs_try(function () use ($pdo, $bid, $base, $user) { rh_stay_correction_apply($pdo, $bid, $base, $user); });
        rh_stay_correction_apply($pdo, $bid, ['clash_ack' => 1] + $base, $user);
        $res['after'] = array_intersect_key(gs_bk($pdo, $bid), ['check_out_date' => 1, 'number_of_nights' => 1, 'total_with_vat' => 1, 'amount_due' => 1, 'credit_balance' => 1]);
        $res['future'] = rh_stay_correction_quote($pdo, gs_bk($pdo, $bid), $in, date('Y-m-d', strtotime('+1 day')), null, null, 'K')['error'] ?? '';
        break;

    case 'guards':
        $g = gs_group($pdo, $in, $out);
        $bid = $g['root'];
        if ($GS_HAS_SNAP) {
            $pdo->prepare("UPDATE bookings SET cancellation_policy_snapshot = ? WHERE id = ?")->execute(['{"kind":"group","tiers":[]}', $bid]);
        }
        $b = gs_bk($pdo, $bid);
        $res['cut'] = rh_stay_correction_quote($pdo, $b, $in, $outM1, null, null, 'K')['error'] ?? '';
        $res['cut_late_arrival'] = rh_stay_correction_quote($pdo, $b, date('Y-m-d', strtotime('-5 day')), $out, null, null, 'K')['error'] ?? '';
        $add = rh_stay_correction_quote($pdo, $b, $in, $outP1, null, null, 'K');
        $res['add_ok'] = !empty($add['ok']);
        $move = rh_stay_correction_quote($pdo, $b, date('Y-m-d', strtotime('-5 day')), $outP1, null, null, 'K');
        $res['move_ok'] = !empty($move['ok']) && (int)$move['new_nights'] === 3;
        $res['noshow'] = rh_stay_correction_quote($pdo, gs_bk($pdo, $g['m3']), $in, $outM1, null, null, 'K')['error'] ?? '';
        $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$g['m2']]);
        $res['cancelled'] = rh_stay_correction_quote($pdo, gs_bk($pdo, $g['m2']), $in, $outM1, null, null, 'K')['error'] ?? '';
        $res['deleted'] = rh_stay_correction_quote($pdo, gs_bk($pdo, $g['m4']), $in, $outM1, null, null, 'K')['error'] ?? '';
        $res['same'] = rh_stay_correction_quote($pdo, $b, $in, $out, null, null, 'K')['error'] ?? '';
        // No snapshot (ICTAm): a cut is allowed and has no automatic unused-nights fee: bill = 2 nights at the booked rate.
        $pdo->prepare("UPDATE bookings SET status = 'checked-out', deleted_at = NULL WHERE id = ?")->execute([$g['m2']]);
        $nq = rh_stay_correction_quote($pdo, gs_bk($pdo, $g['m2']), $in, $outM1, null, null, 'K');
        $res['nosnap_cut'] = ['ok' => !empty($nq['ok']), 'new_room_total' => $nq['new_room_total'] ?? null];
        break;
}
echo json_encode($res);
