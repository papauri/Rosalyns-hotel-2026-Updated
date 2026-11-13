<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Kept cancellation fees and the group no-show charge (owner decisions 2026-11-14). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/kept_fees.php
 * D1 "count it everywhere": a cancelled room whose folio still owes (its kept fee / extras left posted) is in Still owed
 *    (rh_receivable_rows), End of Day outstanding, the Whole-group payment split and the group overdue reminder; the
 *    Bookings list badge reads "Cancelled - fee owed". Nothing kept / paid in full / refunded / in credit stays out.
 * D2 "like a room night": the kept night and the group no-show charge carry VAT and tourism levy like a room night
 *    (rh_room_night_share): folio summary, invoice VAT and levy lines, payment splits; gross totals never change.
 * Everything runs in ONE transaction that is rolled back; settings are switched IN MEMORY only.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/config/invoice.php';
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/admin/includes/eod-revenue.php';
require_once $root . '/admin/includes/group-payment.php';
require_once $root . '/admin/includes/bookings-list-filters.php';
require_once $root . '/includes/auto-email-jobs.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;
$set = static function (string $k, string $v): void { $GLOBALS['_SITE_SETTINGS'][$k] = $v; };

/**
 * A room booking 1,185 a night all-in: 1,000 net + 175 VAT (17.5%) + 10 levy (1% on net). total_amount = net + levy,
 * as the booking paths store it. $daysAhead: check-in that many days from today (negative = in the past).
 */
function kf_booking(PDO $pdo, string $status, int $nights = 3, ?int $primaryId = null, int $daysAhead = 5): int {
    $in = date('Y-m-d', strtotime(($daysAhead >= 0 ? '+' : '') . $daysAhead . ' day'));
    $out = date('Y-m-d', strtotime($in . ' +' . $nights . ' day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'KF TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,?,1,17.5,?,?,'kept fees test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute(['KFT' . random_int(100000, 999999), $in, $out, $nights, 1010 * $nights, 10 * $nights, 175 * $nights, 1185 * $nights, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function kf_pay(PDO $pdo, int $bid, float $amt): void {
    $sp = rh_account_vat_split($pdo, 'room', $bid, $amt, 17.5);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, ?, ?, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['KFP' . random_int(100000, 999999), $bid, $sp['net'], $sp['rate'], $sp['vat'], $amt]);
    recalculateBookingFinancials($bid);
}
function kf_row(PDO $pdo, int $bid): array {
    return $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$bid)->fetch(PDO::FETCH_ASSOC) ?: [];
}
function kf_invoice(PDO $pdo, int $anyId): array {
    $rows = rh_invoice_doc_rows($pdo, $anyId);
    $t = null;
    $h = buildInvoiceHTML($rows['booking'], 'TEST-KF', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    return ['t' => $t, 'h' => $h];
}
/** The printed lines foot to the total: exclusive = subtotal + VAT + levy; inclusive / off = subtotal + levy. */
function kf_foots(array $t): bool {
    $sum = (float)$t['subtotal'] + (vat_mode() === 'exclusive' ? (float)$t['vat'] : 0.0) + (float)$t['levy'];
    return abs($sum - (float)$t['total']) <= 0.011;
}
$mods = ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false];
$owedById = static function (PDO $pdo) use ($mods): array {
    $out = [];
    foreach (rh_receivable_rows($pdo, $mods) as $r) { $out[$r['id']] = $r; }
    return $out;
};
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

// ---- pure helpers ----
$sh = rh_room_night_share(['total_with_vat' => 3555, 'vat_amount' => 525, 'tourism_levy_amount' => 30], 1185);
ok('share: a night of 1,185 = 1,000 net + 175 VAT + 10 levy (the booking\'s own split)', $near($sh['vat'], 175) && $near($sh['levy'], 10) && $near($sh['net'], 1000) && $near($sh['gross'], 1185), json_encode($sh));
$sh0 = rh_room_night_share(['total_with_vat' => 3000, 'vat_amount' => 0, 'tourism_levy_amount' => 0], 1000);
ok('share: a booking priced with VAT off keeps 0 VAT and 0 levy', $sh0['vat'] == 0 && $sh0['levy'] == 0 && $near($sh0['net'], 1000));
$shR = rh_room_night_share(['total_with_vat' => 1000.01, 'vat_amount' => 148.94, 'tourism_levy_amount' => 8.52], 333.34);
ok('share: rounded parts always add up to the amount', $near($shR['vat'] + $shR['levy'] + $shR['net'], 333.34), json_encode($shR));
ok('label: cancelled owing -> "Cancelled - fee owed"; paid / other statuses -> no label', rh_cancelled_fee_owed_label('cancelled', 1185) === 'Cancelled - fee owed'
    && rh_cancelled_fee_owed_label('cancelled', 0.004) === '' && rh_cancelled_fee_owed_label('no-show', 500) === '' && rh_cancelled_fee_owed_label(null, 5) === '');

$pdo->beginTransaction();
try {
    $set('vat_enabled', '1');
    $set('vat_rate', '17.5');
    $set('vat_pricing_mode', 'inclusive');
    $set('group_noshow_charge', '0');

    // ---- D2(a): a single cancelled booking keeping its first night ----
    $set('cancellation_refund_mode', 'keep_first_night');
    $c = kf_booking($pdo, 'confirmed', 3);
    $cr = cancelRoomBookingSettled($pdo, $c, $admin, 'kept fees test');
    $fs = getBookingFolioSummary($c);
    ok('cancel keep first night: kept 1,185 (gross unchanged), still owed in full', !empty($cr['success']) && $near($cr['retained'], 1185) && $near($fs['grand_total'], 1185)
        && $near($fs['balance_due'], 1185), json_encode([$cr['error'] ?? '', $fs['grand_total'] ?? null]));
    ok('folio summary: the kept night carries its VAT 175 and levy 10 (was 0 / 0)', $near($fs['total_vat'], 175) && $near($fs['total_levy'], 10) && $near($fs['base_levy'], 10)
        && $near($fs['total_subtotal'], 1010), json_encode($fs));
    foreach (['inclusive', 'exclusive', 'off'] as $mode) {
        if ($mode === 'off') { $set('vat_enabled', '0'); } else { $set('vat_enabled', '1'); $set('vat_pricing_mode', $mode); }
        $inv = kf_invoice($pdo, $c);
        $t = $inv['t'];
        ok("invoice ($mode): total 1,185 unchanged, levy line 10, VAT 175, lines foot", $near($t['total'], 1185) && $near($t['levy'], 10) && $near($t['vat'], 175) && kf_foots($t)
            && $near($t['balance'], 1185), json_encode($t));
        ok("invoice ($mode): the note still says the gross kept (1,185.00)", strpos($inv['h'], '1,185.00') !== false && stripos($inv['h'], 'first night is kept') !== false);
    }
    $set('vat_enabled', '1');
    $set('vat_pricing_mode', 'inclusive');
    $sp = rh_account_vat_split($pdo, 'room', $c, 1185, 17.5);
    ok('a payment of the kept fee is split like a room night: VAT 175, levy 10, true rate 17.5', $near($sp['vat'], 175) && $near($sp['levy'] ?? 0, 10) && $near($sp['rate'], 17.5), json_encode($sp));

    // ---- D1: Still owed / End of Day / list ----
    $owed = $owedById($pdo);
    ok('Still owed: the cancelled room owing its kept fee is in (1,185)', isset($owed[$c]) && $near($owed[$c]['amount_due'], 1185), json_encode($owed[$c] ?? null));
    ok('Bookings list: left to pay 1,185 with nothing paid; badge "Cancelled - fee owed"', $near(rh_bk_left_to_pay('unpaid', 'cancelled', kf_row($pdo, $c)['amount_due'], 0), 1185)
        && rh_cancelled_fee_owed_label('cancelled', kf_row($pdo, $c)['amount_due']) === 'Cancelled - fee owed');
    $eod = rh_eod_outstanding_split($pdo, $mods);
    kf_pay($pdo, $c, 1185);
    $eod2 = rh_eod_outstanding_split($pdo, $mods);
    $owed = $owedById($pdo);
    $paidRow = $pdo->query("SELECT vat_amount FROM payments WHERE booking_type = 'room' AND booking_id = " . (int)$c . " AND payment_type = 'partial_payment'")->fetchColumn();
    ok('fee paid in full: out of Still owed, End of Day outstanding drops by exactly 1,185, the payment row holds VAT 175', !isset($owed[$c])
        && $near((float)$eod['total'] - (float)$eod2['total'], 1185) && $near($paidRow, 175), json_encode([$eod['total'] ?? null, $eod2['total'] ?? null, $paidRow]));

    $set('cancellation_refund_mode', 'void_refund_all');
    $v = kf_booking($pdo, 'confirmed', 3);
    $vr = cancelRoomBookingSettled($pdo, $v, $admin, 'kept fees test');
    $fsV = getBookingFolioSummary($v);
    $owed = $owedById($pdo);
    ok('void + refund all: nothing kept, no VAT / levy, out of Still owed, no badge', !empty($vr['success']) && $near($fsV['grand_total'], 0) && $near($fsV['total_vat'], 0)
        && $near($fsV['total_levy'], 0) && !isset($owed[$v]) && rh_cancelled_fee_owed_label('cancelled', kf_row($pdo, $v)['amount_due']) === '');
    // Extras left posted on a cancelled room that kept nothing: still owed (its folio owes).
    $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
        VALUES (?, 'other', 'KF minibar', 1, 117.5, 100, 17.5, 17.5, 117.5, NOW(), 0)")->execute([$v]);
    recalculateBookingFinancials($v);
    $owed = $owedById($pdo);
    ok('cancelled, nothing kept but an extra left unpaid (117.50): counted in Still owed', isset($owed[$v]) && $near($owed[$v]['amount_due'], 117.5), json_encode($owed[$v] ?? null));

    // ---- D1: whole-group payment and the group reminder ----
    $set('cancellation_refund_mode', 'keep_first_night');
    $g1 = kf_booking($pdo, 'confirmed', 3);
    $g2 = kf_booking($pdo, 'confirmed', 3, $g1);
    $g3 = kf_booking($pdo, 'confirmed', 3, $g1);
    $gc = cancelRoomBookingSettled($pdo, $g1, $admin, 'kept fees test lead');
    $rooms = rh_group_pay_rooms($pdo, $g2, false);
    $ids = array_column($rooms, 'id');
    ok('group payment: the cancelled lead owing its kept fee counts beside the 2 live rooms', !empty($gc['success']) && count($rooms) === 3 && in_array($g1, $ids, true)
        && $near(array_sum(array_column($rooms, 'owed')), 1185 + 2 * 3555), json_encode($rooms));
    ok('group count: 3 rooms count for "is this a group"', (rh_group_pay_counts($pdo, [$g2])[$g2] ?? 0) === 3);
    $pv = rh_group_pay_preview($pdo, $g2, 1185 + 2 * 3555);
    ok('group payment of exactly what the invoice says is due leaves no excess (the kept fee is settled too)', $near($pv['group_owed'], 8295) && $near($pv['excess'], 0)
        && $near(array_column($pv['rooms'], 'apply', 'id')[$g1] ?? 0, 1185), json_encode($pv));
    $pv2 = rh_group_pay_preview($pdo, $g2, 9000);
    ok('overpaying the group: the extra never goes on the cancelled lead', $near($pv2['excess'], 705) && (int)$pv2['excess_room_id'] !== $g1 && in_array((int)$pv2['excess_room_id'], [$g2, $g3], true),
        json_encode([$pv2['excess'], $pv2['excess_room_id']]));
    $grp = kf_invoice($pdo, $g2);
    $sumFs = 0.0;
    foreach ([$g1, $g2, $g3] as $gid) { $sumFs += (float)getBookingFolioSummary($gid)['grand_total']; }
    ok('group invoice: total = folio summaries (8,295), VAT 3 x 525 / 3 + 2 x 525 = 1,225, levy 70, lines foot', $near($grp['t']['total'], $sumFs) && $near($grp['t']['total'], 8295)
        && $near($grp['t']['vat'], 1225) && $near($grp['t']['levy'], 70) && kf_foots($grp['t']), json_encode($grp['t']));
    // Reminder: the live rooms checked out in the past; the cancelled room counts for its fee and does not block.
    $pdo->prepare("UPDATE bookings SET status = 'checked-out', check_in_date = DATE_SUB(CURDATE(), INTERVAL 6 DAY), check_out_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY),
        checkout_completed_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id IN (?, ?)")->execute([$g2, $g3]);
    $rem = rh_auto_group_reminders($pdo, $g1, [1, 3, 7], 60);
    $remAmt = array_sum(array_column($rem['entries'], 'amount'));
    ok('group reminder: due (cancelled room does not block) and chases the kept fee with the rooms (8,295)', $rem['entries'] && $near($remAmt, 8295), json_encode($rem));

    // ---- D2(b): the group no-show charge carries its levy ----
    $set('group_noshow_charge', 'first_night');
    $set('no_show_refund_policy', 'none');
    $n1 = kf_booking($pdo, 'confirmed', 3, null, -1);
    $n2 = kf_booking($pdo, 'confirmed', 3, $n1, -1);
    $nr = rh_mark_booking_no_show($pdo, $n2, $admin, 'Test');
    $fsN = getBookingFolioSummary($n2);
    ok('no-show charge: 1,185 billed, VAT 175, levy share 10 (extras_levy), gross unchanged', !empty($nr['success']) && $near($fsN['grand_total'], 1185) && $near($fsN['total_vat'], 175)
        && $near($fsN['extras_levy'], 10) && $near($fsN['total_levy'], 10) && $near($fsN['base_levy'], 0), json_encode($fsN));
    foreach (['inclusive', 'exclusive', 'off'] as $mode) {
        if ($mode === 'off') { $set('vat_enabled', '0'); } else { $set('vat_enabled', '1'); $set('vat_pricing_mode', $mode); }
        $ti = kf_invoice($pdo, $n1)['t'];
        ok("no-show group invoice ($mode): total 3,555 + 1,185 unchanged, levy line 30 + 10, lines foot", $near($ti['total'], 4740) && $near($ti['levy'], 40) && kf_foots($ti), json_encode($ti));
    }
    $set('vat_enabled', '1');
    $set('vat_pricing_mode', 'inclusive');
    $spN = rh_account_vat_split($pdo, 'room', $n2, 1185, 17.5);
    ok('a payment on the no-show charge: VAT 175, levy 10, true rate 17.5', $near($spN['vat'], 175) && $near($spN['levy'] ?? 0, 10) && $near($spN['rate'], 17.5), json_encode($spN));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
