<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group no-show / expiry charge (setting group_noshow_charge, code default Off). Usage (repo root):
 *   php scripts/tests/group_noshow_charge.php
 * Everything runs inside ONE transaction that is rolled back (a second connection checks nothing leaked). The setting
 * and the no-show refund policy are switched IN MEMORY only ($_SITE_SETTINGS), never written to site_settings.
 *  - Off: no charge row, the room keeps billing its booked stay (today's figures).
 *  - On, first night: one tagged row; folio summary = invoice = End of Day figure; a rerun (or a later setting) never
 *    charges twice; a single booking is never charged.
 *  - On, none: a voided zero row; the room bills nothing and (no payment) comes off the group invoice.
 *  - Expiry: the call the expiry paths make posts the charge with actor = system.
 *  - Refund: policy first_night / full with the charge = paid - charge - extras; policy none = no refund, the rest is credit.
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
require_once $root . '/admin/includes/eod-revenue.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;
$setMode = static function (string $v): void { $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = $v; };
$setPolicy = static function (string $v): void { $GLOBALS['_SITE_SETTINGS']['no_show_refund_policy'] = $v; };

/** A room booking 1,165 a night all-in (1,000 net + 165 VAT, no levy), check-in in the past (a no-show can be marked). */
function gn_booking(PDO $pdo, string $status, int $nights = 2, ?int $primaryId = null): int {
    $in = date('Y-m-d', strtotime('-' . $nights . ' day'));
    $out = date('Y-m-d');
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'GNS TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'group no-show test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute(['GNST' . random_int(100000, 999999), $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function gn_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['GNSP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function gn_rows(PDO $pdo, int $bid): array {
    $st = $pdo->prepare('SELECT id, line_total, vat_amount, voided, description FROM booking_charges WHERE booking_id = ? AND description LIKE ?');
    $st->execute([$bid, '%' . rh_noshow_fee_tag($bid)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function gn_invoice(PDO $pdo, int $anyId): array {
    $rows = rh_invoice_doc_rows($pdo, $anyId);
    $t = null;
    $h = buildInvoiceHTML($rows['booking'], 'TEST-GNS', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    return ['t' => $t, 'h' => $h];
}
function gn_refunds(PDO $pdo, int $bid): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payments WHERE booking_id = ? AND booking_type = 'room' AND payment_type = 'refund'");
    $st->execute([$bid]);
    return round((float)$st->fetchColumn(), 2);
}
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
// ---- display helper: the idempotency tag never reaches a person; only a trailing tag is dropped ----
ok('display: trailing tag stripped', rh_charge_display_desc('No-show charge - first night [noshow-fee:123]') === 'No-show charge - first night');
ok('display: tag in the middle left alone', rh_charge_display_desc('Fee [noshow-fee:5] extra') === 'Fee [noshow-fee:5] extra');
ok('display: other bracket tags left alone', rh_charge_display_desc('Late fee [ref:5]') === 'Late fee [ref:5]' && rh_charge_display_desc('Minibar') === 'Minibar');
ok('display: empty / null give empty', rh_charge_display_desc('') === '' && rh_charge_display_desc(null) === '');
ok('display: the real tag shape is what is stripped', rh_charge_display_desc('X ' . rh_noshow_fee_tag(987654)) === 'X');
$pdo->beginTransaction();
try {
    // ---- Off (default): nothing posted, today's figures ----
    unset($GLOBALS['_SITE_SETTINGS']['group_noshow_charge']);
    ok('code default is Off (no setting row needed)', rh_group_noshow_charge_mode() === '', rh_group_noshow_charge_mode());
    $setMode('0');
    $setPolicy('none');
    $p = gn_booking($pdo, 'confirmed', 2);
    $m = gn_booking($pdo, 'confirmed', 2, $p);
    $before = gn_invoice($pdo, $p)['t'];
    $r = rh_mark_booking_no_show($pdo, $m, $admin, 'Test');
    $fs = getBookingFolioSummary($m);
    $after = gn_invoice($pdo, $p)['t'];
    ok('Off: no-show marked, no charge row, the room keeps billing its booked stay (2,330); group invoice total unchanged (4,660)',
        !empty($r['success']) && gn_rows($pdo, $m) === [] && $near($fs['grand_total'], 2330) && $near($before['total'], 4660) && $near($after['total'], $before['total']),
        json_encode([$r['error'] ?? '', $fs['grand_total'] ?? null, $before['total'] ?? null, $after['total'] ?? null]));
    $apply = rh_group_noshow_fee_apply($pdo, $m, 'no-show');
    ok('Off: the charge helper returns at once (mode empty, nothing posted)', $apply['mode'] === '' && !$apply['posted'] && gn_rows($pdo, $m) === []);

    // ---- On, first night ----
    $setMode('first_night');
    $p2 = gn_booking($pdo, 'confirmed', 2);
    $m2 = gn_booking($pdo, 'confirmed', 2, $p2);
    $today = date('Y-m-d');
    $eodBefore = rh_eod_earned_revenue($pdo, $today, ['bookings' => true, 'pos' => false]);
    $r2 = rh_mark_booking_no_show($pdo, $m2, $admin, 'Test');
    $rows2 = gn_rows($pdo, $m2);
    $fs2 = getBookingFolioSummary($m2);
    $bk2 = $pdo->query('SELECT amount_due, payment_status FROM bookings WHERE id = ' . (int)$m2)->fetch(PDO::FETCH_ASSOC);
    $inv2 = gn_invoice($pdo, $p2);
    $eodAfter = rh_eod_earned_revenue($pdo, $today, ['bookings' => true, 'pos' => true]);
    $eodDelta = round((float)$eodAfter['folio_other_gross'] - (float)$eodBefore['folio_other_gross'], 2);
    $fsP2 = getBookingFolioSummary($p2);
    ok('On first night: ONE tagged row of 1,165 (VAT 165) posted with the no-show', !empty($r2['success']) && count($rows2) === 1 && $near($rows2[0]['line_total'], 1165)
        && $near($rows2[0]['vat_amount'], 165) && (int)$rows2[0]['voided'] === 0 && !empty($r2['group_fee']['posted']), json_encode([$r2, $rows2]));
    ok('On first night: folio summary bills 1,165 (stay no longer billed), booking balance agrees', $near($fs2['grand_total'], 1165) && $near($fs2['balance_due'], 1165)
        && $near($bk2['amount_due'], 1165), json_encode([$fs2, $bk2]));
    ok('On first night: group invoice total = lead 2,330 + 1,165 = sum of folio summaries; the room is marked No-show; the lines foot',
        $near($inv2['t']['total'], 3495) && $near($inv2['t']['total'], $fsP2['grand_total'] + $fs2['grand_total']) && stripos($inv2['h'], 'No-show room:') !== false
        && stripos($inv2['h'], 'No-show charge - first night') !== false && $near((float)$inv2['t']['subtotal'] + (vat_mode() === 'exclusive' ? (float)$inv2['t']['vat'] : 0.0) + (float)$inv2['t']['levy'], $inv2['t']['total']), json_encode($inv2['t']));
    // Owner 2026-11-14 (step 8b F8): a no-show is earned on its check-in date as its own line (Kept on no-shows), so the
    // tagged charge is no longer a guest-bill extra on the day it was posted, and is counted once, on the check-in date.
    $m2Row = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int)$m2)->fetch(PDO::FETCH_ASSOC);
    $eodIn = rh_eod_earned_revenue($pdo, (string)$m2Row['check_in_date'], ['bookings' => true, 'pos' => true]);
    ok('On first night: End of Day counts the charge once, on the check-in date as Kept on no-shows (1,165), not as a guest-bill extra today',
        $near($eodDelta, 0) && $near(rh_eod_noshow_earned($pdo, $m2Row), 1165) && (float)$eodIn['no_show_kept_gross'] >= 1165 - 0.01, json_encode([$eodDelta, $eodIn['no_show_kept_gross']]));
    ok('On first night: the invoice prints the charge without its internal tag; the stored row keeps it (still detected)',
        strpos($inv2['h'], 'noshow-fee:') === false && rh_noshow_fee_tagged($pdo, $m2)
        && substr((string)$rows2[0]['description'], -strlen(rh_noshow_fee_tag($m2))) === rh_noshow_fee_tag($m2), (string)($rows2[0]['description'] ?? ''));
    $again = rh_group_noshow_fee_apply($pdo, $m2, 'no-show', $admin);
    $setMode('full');
    $again2 = rh_group_noshow_fee_apply($pdo, $m2, 'no-show', $admin);
    ok('rerun (and a later change to full stay) never charges twice', !$again['posted'] && !$again2['posted'] && count(gn_rows($pdo, $m2)) === 1);
    $tl = $pdo->prepare("SELECT performed_by_type, new_value FROM booking_timeline_logs WHERE booking_id = ? AND action = 'No-show charge posted'");
    $tl->execute([$m2]);
    $tlRows = $tl->fetchAll(PDO::FETCH_ASSOC);
    ok('history: one "No-show charge posted" entry (old -> new) by the staff member', count($tlRows) === 1 && $tlRows[0]['performed_by_type'] === 'admin'
        && strpos((string)$tlRows[0]['new_value'], 'charge ') === 0 && strpos((string)$tlRows[0]['new_value'], 'noshow-fee') === false, json_encode($tlRows));

    // ---- single booking: never charged ----
    $setMode('first_night');
    $s = gn_booking($pdo, 'confirmed', 2);
    $rs = rh_mark_booking_no_show($pdo, $s, $admin, 'Test');
    ok('single booking: no charge row, bills its booked stay as before', !empty($rs['success']) && gn_rows($pdo, $s) === [] && $near(getBookingFolioSummary($s)['grand_total'], 2330));

    // ---- On, none ----
    $setMode('none');
    $p3 = gn_booking($pdo, 'confirmed', 2);
    $m3 = gn_booking($pdo, 'confirmed', 2, $p3);
    $m3b = gn_booking($pdo, 'confirmed', 2, $p3);
    rh_mark_booking_no_show($pdo, $m3, $admin, 'Test');
    $rows3 = gn_rows($pdo, $m3);
    $inv3 = gn_invoice($pdo, $p3);
    ok('On none: one VOIDED zero row (never money); the room bills 0 and, with no payment, is off the group invoice',
        count($rows3) === 1 && (int)$rows3[0]['voided'] === 1 && $near($rows3[0]['line_total'], 0) && $near(getBookingFolioSummary($m3)['grand_total'], 0)
        && !in_array($m3, $inv3['t']['ids'] ?? [], true) && $near($inv3['t']['total'], 4660), json_encode([$rows3, $inv3['t']]));
    $st3 = $pdo->query('SELECT payment_status FROM bookings WHERE id = ' . (int)$m3)->fetchColumn();
    ok('On none: the room reads unpaid (nothing billed, nothing paid), not "paid"', $st3 === 'unpaid', (string)$st3);

    // ---- Expiry (the call every expiry path makes; no user = system) ----
    $setMode('first_night');
    $p4 = gn_booking($pdo, 'confirmed', 3);
    $e4 = gn_booking($pdo, 'pending', 3, $p4);
    $pdo->prepare("UPDATE bookings SET status = 'expired', expired_at = NOW() WHERE id = ?")->execute([$e4]);
    $ap4 = rh_group_noshow_fee_apply($pdo, $e4, 'expired');
    $rows4 = gn_rows($pdo, $e4);
    $tl4 = $pdo->prepare("SELECT performed_by_type FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Expiry charge posted'");
    $tl4->execute([$e4]);
    ok('expiry On: one "Expired booking charge - first night" of 1,165 (3,495 / 3 nights), actor system; folio bills 1,165',
        $ap4['posted'] && count($rows4) === 1 && $near($rows4[0]['line_total'], 1165) && stripos($rows4[0]['description'], 'Expired booking charge') === 0
        && $tl4->fetchColumn() === 'system' && $near(getBookingFolioSummary($e4)['grand_total'], 1165), json_encode([$ap4, $rows4]));
    $setMode('0');
    $p5 = gn_booking($pdo, 'confirmed', 3);
    $e5 = gn_booking($pdo, 'pending', 3, $p5);
    $pdo->prepare("UPDATE bookings SET status = 'expired', expired_at = NOW() WHERE id = ?")->execute([$e5]);
    ok('expiry Off: nothing posted, the expired room bills its stay as today', !rh_group_noshow_fee_apply($pdo, $e5, 'expired')['posted'] && gn_rows($pdo, $e5) === []
        && $near(getBookingFolioSummary($e5)['grand_total'], 3495));

    // ---- Refund combinations (room paid in full 2,330; charge first night 1,165) ----
    foreach (['first_night' => 1165.0, 'full' => 1165.0, 'none' => 0.0] as $pol => $wantRefund) {
        $setMode('first_night');
        $setPolicy($pol);
        $pp = gn_booking($pdo, 'confirmed', 2);
        $mm = gn_booking($pdo, 'confirmed', 2, $pp);
        gn_pay($pdo, $mm, 2330);
        $rr = rh_mark_booking_no_show($pdo, $mm, $admin, 'Test');
        $credit = (float)$pdo->query('SELECT credit_balance FROM bookings WHERE id = ' . (int)$mm)->fetchColumn();
        ok("refund policy $pol + charge: refund " . number_format($wantRefund, 2) . ' (paid - charge - extras; the first night is never kept twice)',
            !empty($rr['success']) && $near(gn_refunds($pdo, $mm), $wantRefund) && ($pol !== 'none' || $near($credit, 1165)), json_encode([$rr['refund'] ?? null, gn_refunds($pdo, $mm), $credit]));
    }
    $setMode('0');
    $setPolicy('full');
    $pq = gn_booking($pdo, 'confirmed', 2);
    $mq = gn_booking($pdo, 'confirmed', 2, $pq);
    gn_pay($pdo, $mq, 2330);
    rh_mark_booking_no_show($pdo, $mq, $admin, 'Test');
    ok('Off + policy full: refund 2,330 exactly as before (no charge)', $near(gn_refunds($pdo, $mq), 2330) && gn_rows($pdo, $mq) === []);

    $seen = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id IN (?, ?)');
    $seen->execute([$p, $m2]);
    ok('nothing visible outside the transaction (no implicit commit)', (int)$seen->fetchColumn() === 0);
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
// The expiry sweeps call the helper OUTSIDE any transaction (own-transaction path). This part commits, so its rows are
// removed again in finally (test bookings only, by id).
$own = [];
try {
    $setMode('first_night');
    $own[] = $op = gn_booking($pdo, 'confirmed', 2);
    $own[] = $oe = gn_booking($pdo, 'pending', 2, $op);
    $pdo->prepare("UPDATE bookings SET status = 'expired', expired_at = NOW() WHERE id = ?")->execute([$oe]);
    $oa = rh_group_noshow_fee_apply($pdo, $oe, 'expired');
    $oseen = $chk->prepare('SELECT COUNT(*) FROM booking_charges WHERE booking_id = ? AND voided = 0');
    $oseen->execute([$oe]);
    ok('expiry sweep path (no transaction open): the charge commits on its own, once', $oa['posted'] && !$pdo->inTransaction() && (int)$oseen->fetchColumn() === 1
        && !rh_group_noshow_fee_apply($pdo, $oe, 'expired')['posted'], json_encode($oa));
} catch (Throwable $e) {
    ok('own-transaction path: no exception', false, $e->getMessage());
} finally {
    if ($own) {
        $in = implode(',', array_map('intval', $own));
        $pdo->exec("DELETE FROM booking_timeline_logs WHERE booking_id IN ($in)");
        $pdo->exec("DELETE FROM booking_charges WHERE booking_id IN ($in)");
        $pdo->exec("DELETE FROM bookings WHERE id IN ($in) AND special_requests = 'group no-show test'");
    }
    $setMode('0');
}
$left = $chk->query("SELECT COUNT(*) FROM bookings WHERE special_requests = 'group no-show test'")->fetchColumn();
ok('rolled back: no test bookings left', (int)$left === 0, (string)$left);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
