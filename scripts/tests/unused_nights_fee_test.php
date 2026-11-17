<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Kept for unused nights (group bookings plan D-R1a / A1-A3). Usage (repo root, LIWONDE only):
 *   php scripts/tests/unused_nights_fee_test.php
 * Pure quote checks, then DB checks inside ONE transaction that is rolled back (a second connection checks nothing
 * leaked). Covers: tier % by days, cap, no snapshot = 0, pending / tentative = 0, override with / without reason,
 * start-night drop (late arrival), group vs individual snapshot, folio row + VAT + levy + invoice + balance after the
 * post, two cuts on one room, a 5 -> 1 cut at 100% (levy not capped), a package booking.
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
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;

$tiers = [['days' => 7, 'pct' => 25], ['days' => 2, 'pct' => 50], ['days' => 1, 'pct' => 100]];
$snapInd = json_encode(['kind' => 'individual', 'policy_version' => 3, 'tiers' => $tiers, 'noshow_pct' => 100, 'text' => 'test']);
$snapGrp = json_encode(['kind' => 'group', 'policy_version' => 4, 'tiers' => [['days' => 14, 'pct' => 40]], 'noshow_pct' => 100, 'text' => 'test']);
/** 1,200 a night all-in: 1,000 net + 165 VAT + 35 levy. */
$row = static function (int $n, string $in, string $status = 'confirmed', ?string $snap = null, float $pkg = 0.0) use (&$snapInd): array {
    return ['id' => 0, 'booking_reference' => 'UNT', 'status' => $status, 'check_in_date' => $in,
        'check_out_date' => date('Y-m-d', strtotime($in . ' +' . $n . ' day')), 'number_of_nights' => $n,
        'total_amount' => 1035 * $n + $pkg, 'vat_amount' => 165 * $n, 'tourism_levy_amount' => 35 * $n, 'total_with_vat' => 1200 * $n + $pkg,
        'package_total' => $pkg, 'vat_rate' => 16.5, 'tourism_levy_percent' => 3.5, 'child_supplement_total' => 0,
        'cancellation_policy_snapshot' => $snap];
};
$b = $row(5, '2026-12-10', 'confirmed', $snapInd);

// ---- tier % by days (cut 10-15 Dec -> 10-13 Dec: 2 unused nights from 13 Dec, base 2,400) ----
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-01');
ok('12 days notice: outside every window = 0', $q['source'] === 'policy' && $q['unused'] === 2 && $q['days'] === 12 && $q['amount'] == 0.0
    && strpos($q['text'], 'outside every fee window') !== false, json_encode($q));
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-08');
ok('5 days notice: 25% of 2 x 1,200 = 600', $near($q['amount'], 600) && $q['pct'] == 25.0 && $q['first_unused'] === '2026-12-13', json_encode($q));
ok('text shows rate x nights x % = fee', strpos($q['text'], 'Kept for unused nights: ') === 0 && strpos($q['text'], '1,200.00 x 2 nights x 25% = ') !== false, $q['text']);
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-12');
ok('1 day notice: 50% = 1,200', $near($q['amount'], 1200), json_encode($q));
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-13');
ok('notice on the first unused night (checkout): 100% = 2,400', $near($q['amount'], 2400) && $q['days'] === 0, json_encode($q));
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-14');
ok('told after the first unused night: 100%', $near($q['amount'], 2400) && $q['days'] === -1);
ok('cap = bill reduction 2,400', $near($q['cap'], 2400) && !$q['capped']);

// ---- cap ----
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-13', ['new_gross' => 5000]);
ok('fee capped at old gross - new gross (1,000)', $near($q['amount'], 1000) && $q['capped'] && $near($q['full'], 2400), json_encode($q));

// ---- not a cut ----
$q = rh_unused_nights_quote($pdo, $b, '2026-12-12', '2026-12-17', '2026-12-01');
ok('moving the dates, same count: no cut', $q['source'] === 'no_cut' && $q['unused'] === 0 && $q['amount'] == 0.0);

// ---- no snapshot / pending / tentative ----
$q = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'confirmed', null), '2026-12-10', '2026-12-13', '2026-12-13');
ok('no snapshot: no fee, said so', $q['source'] === 'none' && $q['amount'] == 0.0 && !$q['overridable'] && strpos($q['text'], 'No cancellation policy saved') === 0, json_encode($q));
$q = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'pending', $snapInd), '2026-12-10', '2026-12-13', '2026-12-13');
ok('pending: 0', $q['source'] === 'status' && $q['amount'] == 0.0 && !$q['overridable'] && strpos($q['text'], 'Not confirmed yet (pending)') === 0, $q['text']);
$q = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'tentative', $snapInd), '2026-12-10', '2026-12-13', '2026-12-13');
ok('tentative: 0', $q['source'] === 'status' && $q['amount'] == 0.0);
$q = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'checked-in', $snapInd), '2026-12-10', '2026-12-13', '2026-12-13');
ok('checked-in: charged', $near($q['amount'], 2400));

// ---- start-night drop (late arrival: notice = actual arrival date) ----
$q = rh_unused_nights_quote($pdo, $b, '2026-12-12', '2026-12-15', '2026-12-12');
ok('late arrival: first unused = old arrival, told 2 days after = 100%', $q['first_unused'] === '2026-12-10' && $q['days'] === -2 && $near($q['amount'], 2400), json_encode($q));

// ---- group vs individual snapshot ----
$q = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'confirmed', $snapGrp), '2026-12-10', '2026-12-13', '2026-12-01');
ok('group snapshot: its own tier (40% inside 14 days)', $q['kind'] === 'group' && $q['pct'] == 40.0 && $near($q['amount'], 960) && $q['version'] === 4, json_encode($q));
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-01');
ok('individual snapshot: 0 at the same notice', $q['kind'] === 'individual' && $q['amount'] == 0.0);

// ---- override ----
$q = rh_unused_nights_quote($pdo, $b, '2026-12-10', '2026-12-13', '2026-12-13');
$c = rh_unused_nights_choice($q, '', '');
ok('no override = the quote', $c['ok'] && $near($c['amount'], 2400) && !$c['overridden']);
$c = rh_unused_nights_choice($q, '1000', '');
ok('override without reason refused', !$c['ok'] && strpos($c['error'], 'Say why') === 0, $c['error']);
$c = rh_unused_nights_choice($q, '1,000', 'Guest was ill');
ok('override with reason accepted', $c['ok'] && $near($c['amount'], 1000) && $c['overridden'] && $c['reason'] === 'Guest was ill');
$c = rh_unused_nights_choice($q, '2400', '');
ok('override equal to the quote needs no reason', $c['ok'] && !$c['overridden']);
$c = rh_unused_nights_choice($q, '2500', 'x x x');
ok('override above the cap refused', !$c['ok']);
$c = rh_unused_nights_choice($q, '-1', 'x x x');
ok('override below 0 refused', !$c['ok']);
$c = rh_unused_nights_choice($q, '0', 'Goodwill');
ok('override to 0 with reason', $c['ok'] && $c['amount'] == 0.0 && $c['overridden']);
$c = rh_unused_nights_choice($q, '', '', '600.00');
ok('reviewed quote differs: refused', !$c['ok'] && strpos($c['error'], 'changed since you reviewed') !== false);
$qn = rh_unused_nights_quote($pdo, $row(5, '2026-12-10', 'confirmed', null), '2026-12-10', '2026-12-13', '2026-12-13');
$c = rh_unused_nights_choice($qn, '500', 'x x x');
ok('no snapshot: an amount cannot be keyed', !$c['ok']);

// ---- shares: 5 -> 1 at 100% is not capped at the 1 night left; a package does not carry levy ----
$one = $row(1, '2026-12-10', 'checked-out', $snapInd);
$s = rh_unused_nights_share($one, 4800);
ok('share of 4 nights on a 1-night booking: VAT 660, levy 140', $near($s['vat'], 660) && $near($s['levy'], 140) && $near($s['net'], 4000), json_encode($s));
$pkgB = $row(5, '2026-12-10', 'confirmed', $snapInd, 500.0);
$pkgVat = (float)rh_stay_totals(500.0, 'price', false)['vat'];
$pkgB['vat_amount'] = 165 * 5 + $pkgVat;
$s = rh_unused_nights_share($pkgB, 2400);
ok('package booking: share on the room part only', $near($s['vat'], 330) && $near($s['levy'], 70), json_encode($s) . ' pkgvat=' . $pkgVat);
$q = rh_unused_nights_quote($pdo, $pkgB, '2026-12-10', '2026-12-13', '2026-12-13');
ok('package booking: rate a night excludes the package', $near($q['per_night'], 1200) && $near($q['amount'], 2400), json_encode($q));

// ---- d: checkout settlement input shape (rh_late_stay_quote), notice = arrival for start nights, else the leave date ----
require_once $root . '/includes/late-stay.php';
$dNotice = static fn(array $bk, array $lq): string => (string)$lq['new_check_in'] > substr((string)$bk['check_in_date'], 0, 10) ? (string)$lq['new_check_in'] : (string)$lq['leave_date'];
$lq = rh_late_stay_quote($b, '2026-12-13', 'actual', '2026-12-12', '2026-12-13');
$q = !empty($lq['ok']) ? rh_unused_nights_quote($pdo, $b, $lq['new_check_in'], $lq['new_check_out'], $dNotice($b, $lq), ['new_gross' => (float)$lq['new_gross']]) : [];
ok('d late arrival (actual nights): 2 start + 2 end nights unused, notice = arrival, 100% = 4,800',
    !empty($lq['ok']) && $dNotice($b, $lq) === '2026-12-12' && ($q['unused'] ?? 0) === 4 && $near($q['amount'] ?? 0, 4800), json_encode([$lq['error'] ?? '', $q]));
$lq = rh_late_stay_quote($b, '2026-12-13', 'booked', null, '2026-12-13');
$q = !empty($lq['ok']) ? rh_unused_nights_quote($pdo, $b, $lq['new_check_in'], $lq['new_check_out'], $dNotice($b, $lq), ['new_gross' => (float)$lq['new_gross']]) : [];
ok('d early leave (booked nights): notice = leave date, 100% of 2 nights = 2,400',
    !empty($lq['ok']) && $dNotice($b, $lq) === '2026-12-13' && ($q['unused'] ?? 0) === 2 && $near($q['amount'] ?? 0, 2400), json_encode([$lq['error'] ?? '', $q]));

// ---- display strip ----

ok('display: unused-nights tag stripped', rh_charge_display_desc('Kept for unused nights - 2 nights [unused-nights:12]') === 'Kept for unused nights - 2 nights');
ok('display: no-show tag still stripped', rh_charge_display_desc('No-show charge [noshow-fee:5]') === 'No-show charge');

// ---- DB: post, folio, levy, invoice, balance; two cuts ----
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$ref = 'UNT' . random_int(100000, 999999);
$pdo->beginTransaction();
try {
    $in = date('Y-m-d', strtotime('+10 day'));
    $out = date('Y-m-d', strtotime('+15 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, cancellation_policy_snapshot, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'UNT TEST','','+265000000009','Malawi',1,1,0,50,?,?,5,5175,0,175,3.5,16.5,825,6000,'unused nights test','confirmed','unpaid',0,'single',?,?,NOW())")
        ->execute([$ref, $in, $out, bin2hex(random_bytes(16)), $snapInd]);
    $bid = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($bid);
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, ?, CURDATE(), 6000, 0, 0, 6000, 'cash', 'full_payment', 'completed', NOW())")
        ->execute(['UNTP' . random_int(100000, 999999), $bid, $ref]);
    recalculateBookingFinancials($bid);
    $lock = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');

    // Cut 1: 5 -> 3 nights, told on the first unused night (checkout) = 100% of 2 x 1,200.
    $cut = static function (int $newNights, string $notice, $override = '', string $reason = '') use ($pdo, $lock, $bid, $in, $admin): array {
        $lock->execute([$bid]);
        $pre = $lock->fetch(PDO::FETCH_ASSOC);
        $newOut = date('Y-m-d', strtotime($in . ' +' . $newNights . ' day'));
        $q = rh_unused_nights_quote($pdo, $pre, $in, $newOut, $notice);
        $c = rh_unused_nights_choice($q, $override, $reason);
        $rp = rh_reprice_at_booked_rate($pre, $newNights);
        rh_write_booking_stay_totals($pdo, $bid, $rp['totals'], $newNights, $in, $newOut, 0.0);
        $p = rh_unused_nights_fee_post($pdo, $pre, $q, $c, $admin, 'Test', 'unused nights test');
        recalculateBookingFinancials($bid);
        return ['q' => $q, 'p' => $p];
    };
    $r1 = $cut(3, date('Y-m-d', strtotime($in . ' +3 day')));
    $st = $pdo->prepare('SELECT * FROM booking_charges WHERE booking_id = ? AND description LIKE ? ORDER BY id');
    $st->execute([$bid, '%' . rh_unused_nights_tag($bid)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ok('cut 1: one tagged folio row, charge_type other, 2,400 with VAT 330', count($rows) === 1 && $rows[0]['charge_type'] === 'other'
        && $near($rows[0]['line_total'], 2400) && $near($rows[0]['vat_amount'], 330) && (int)$rows[0]['voided'] === 0
        && strpos($rows[0]['description'], 'noshow-fee') === false, json_encode($rows));
    $fs = getBookingFolioSummary($bid);
    $lock->execute([$bid]);
    $after = $lock->fetch(PDO::FETCH_ASSOC);
    $lev1 = rh_unused_nights_share($after, 2400)['levy'];
    ok('cut 1: bill = 3 nights 3,600 + kept 2,400 = 6,000; kept levy in extras', $near($fs['grand_total'], 6000) && $lev1 > 0 && $near($fs['extras_levy'], $lev1)
        && $near($fs['total_levy'], (float)$after['tourism_levy_amount'] + $lev1), json_encode(array_intersect_key($fs, array_flip(['grand_total', 'extras_levy', 'total_levy', 'balance_due']))) . ' lev1=' . $lev1);
    ok('cut 1: paid 6,000, nothing owed, no credit', $near($after['amount_due'], 0) && $near($after['credit_balance'], 0), json_encode([$after['amount_due'], $after['credit_balance']]));
    $tl = $pdo->prepare("SELECT action, new_value, metadata FROM booking_timeline_logs WHERE booking_id = ? AND action LIKE '%unused nights%' ORDER BY id");
    $tl->execute([$bid]);
    $tlr = $tl->fetchAll(PDO::FETCH_ASSOC);
    ok('cut 1: History "Kept for unused nights" with the quote', count($tlr) === 1 && $tlr[0]['action'] === 'Kept for unused nights'
        && (int)(json_decode((string)$tlr[0]['metadata'], true)['quote'] ?? 0) === 2400, json_encode($tlr));

    // Invoice: levy prints on the levy line, the tag never shows.
    $docRows = rh_invoice_doc_rows($pdo, $bid);
    $t = null;
    $html = buildInvoiceHTML($docRows['booking'], 'TEST-UNT', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $docRows['group_bookings'], null, false, $t);
    ok('invoice: tag hidden, kept line shown', strpos($html, 'unused-nights:') === false && strpos($html, 'Kept for unused nights') !== false);
    ok('invoice total = folio total', is_array($t) ? $near($t['total_with_vat'] ?? ($t['total'] ?? 0), 6000) : true, json_encode($t));

    // Cut 2 on the same room: 3 -> 2 nights, staff override to 500 with a reason.
    $r2 = $cut(2, date('Y-m-d', strtotime($in . ' +2 day')), '500', 'Goodwill test');
    $st->execute([$bid, '%' . rh_unused_nights_tag($bid)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ok('cut 2: a second tagged row, readers sum both', count($rows) === 2 && $near($rows[1]['line_total'], 500)
        && strpos($rows[1]['description'], 'amount set by staff') !== false, json_encode($rows));
    $fs = getBookingFolioSummary($bid);
    $lev2 = round(rh_unused_nights_share($after, 2400)['levy'] + rh_unused_nights_share($after, 500)['levy'], 2);
    ok('cut 2: bill 2,400 + 2,400 + 500 = 5,300; extras levy summed row by row', $near($fs['grand_total'], 5300) && $near($fs['extras_levy'], $lev2),
        json_encode([$fs['grand_total'], $fs['extras_levy'], $lev2]));
    $lock->execute([$bid]);
    $after2 = $lock->fetch(PDO::FETCH_ASSOC);
    ok('cut 2: 700 over-paid shows as credit (path decides refund / credit note)', $near($after2['credit_balance'], 700), (string)$after2['credit_balance']);
    $tl->execute([$bid]);
    $tlr = $tl->fetchAll(PDO::FETCH_ASSOC);
    ok('cut 2: History has the override reason', count($tlr) === 2 && strpos((string)$tlr[1]['metadata'], 'Goodwill test') !== false);

    // Override to 0: no row, History says nothing kept.
    $r3 = $cut(1, date('Y-m-d', strtotime($in . ' +1 day')), '0', 'Waived test');
    $st->execute([$bid, '%' . rh_unused_nights_tag($bid)]);
    ok('override to 0: no new row', count($st->fetchAll()) === 2 && !$r3['p']['posted']);
    $tl->execute([$bid]);
    $tlr = $tl->fetchAll(PDO::FETCH_ASSOC);
    ok('override to 0: History "No fee kept for unused nights"', count($tlr) === 3 && $tlr[2]['action'] === 'No fee kept for unused nights', json_encode(end($tlr)));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
// ---- DB: the save paths (a: correct stay after check-out, b: date change, c: take off nights) ----
require_once $root . '/includes/stay-correction.php';
require_once $root . '/includes/stay-nights.php';
$refs = [$ref];
/** One test room booking at 1,200 a night all-in (no payment), individual policy snapshot. */
$mk = static function (string $status, string $in, int $n, bool $recalc = true) use ($pdo, $snapInd, &$refs): int {
    $r = 'UNT' . random_int(100000, 999999);
    $refs[] = $r;
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, cancellation_policy_snapshot, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'UNT TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,?,3.5,16.5,?,?,'unused nights test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute([$r, $in, date('Y-m-d', strtotime($in . ' +' . $n . ' day')), $n, 1035 * $n, 35 * $n, 165 * $n, 1200 * $n, $status, bin2hex(random_bytes(16)), $snapInd]);
    $id = (int)$pdo->lastInsertId();
    if ($recalc) {
        recalculateBookingFinancials($id);
    }
    return $id;
};
$tagRows = $pdo->prepare('SELECT line_total FROM booking_charges WHERE booking_id = ? AND voided = 0 AND description LIKE ?');
$tagSum = static function (int $id) use ($tagRows): float {
    $tagRows->execute([$id, '%' . rh_unused_nights_tag($id)]);
    return round(array_sum(array_map('floatval', $tagRows->fetchAll(PDO::FETCH_COLUMN))), 2);
};
$histAct = $pdo->prepare("SELECT action FROM booking_timeline_logs WHERE booking_id = ? AND action LIKE '%unused nights%' ORDER BY id");
$who = ['id' => $admin, 'full_name' => 'Test'];

$pdo->beginTransaction();
try {
    // c: checked in, 7 nights from yesterday; take 2 off today -> first unused night in 4 days = 25% of 2 x 1,200 = 600.
    $cid = $mk('checked-in', date('Y-m-d', strtotime('-1 day')), 7);
    $cb = $pdo->query('SELECT * FROM bookings WHERE id = ' . $cid)->fetch(PDO::FETCH_ASSOC);
    $cq = rh_stay_nights_quote($pdo, $cb, -2, null, false, 'MWK');
    ok('c quote: 600 kept, in the new total', !empty($cq['ok']) && $near($cq['fee_kept'], 600) && $near($cq['unused']['amount'], 600)
        && $cq['fee_error'] === '', json_encode([$cq['ok'] ?? null, $cq['error'] ?? '', $cq['fee_kept'] ?? null, $cq['unused']['amount'] ?? null]));
    $cIn = ['delta' => -2, 'reason' => 'unused nights test', 'expected_new_total' => $cq['new_total'], 'expected_total_delta' => $cq['total_delta'], 'can_refund' => false];
    $threw = '';
    try { rh_stay_nights_apply($pdo, $cid, $cIn, $who); } catch (Exception $e) { $threw = $e->getMessage(); }
    ok('c apply: refused when the amount was not shown', strpos($threw, 'Nothing was saved') !== false && $tagSum($cid) == 0.0, $threw);
    $threw = '';
    try { rh_stay_nights_apply($pdo, $cid, $cIn + ['un_fee_override' => '100'], $who); } catch (Exception $e) { $threw = $e->getMessage(); }
    ok('c apply: a different amount needs a reason', $threw !== '' && $tagSum($cid) == 0.0, $threw);
    rh_stay_nights_apply($pdo, $cid, $cIn + ['un_fee_quoted' => '600.00'], $who);
    $histAct->execute([$cid]);
    ok('c apply: 600 row posted + History', $near($tagSum($cid), 600) && $histAct->fetchAll(PDO::FETCH_COLUMN) === ['Kept for unused nights']);

    // a: checked out, 5 nights ended yesterday; corrected to 3 -> notice = the new check-out = 100% of 2 x 1,200 = 2,400.
    $aid = $mk('checked-out', date('Y-m-d', strtotime('-6 day')), 5);
    $ab = $pdo->query('SELECT * FROM bookings WHERE id = ' . $aid)->fetch(PDO::FETCH_ASSOC);
    $aOut = date('Y-m-d', strtotime('-3 day'));
    $aq = rh_stay_correction_quote($pdo, $ab, substr($ab['check_in_date'], 0, 10), $aOut, null, null, 'MWK');
    ok('a quote: 2,400 kept, new bill = 3,600 + 2,400', !empty($aq['ok']) && $near($aq['fee_kept'], 2400) && $near($aq['new_bill'], 6000),
        json_encode([$aq['ok'] ?? null, $aq['error'] ?? '', $aq['fee_kept'] ?? null, $aq['new_bill'] ?? null]));
    $aq2 = rh_stay_correction_quote($pdo, $ab, substr($ab['check_in_date'], 0, 10), $aOut, null, null, 'MWK', '0', 'Waived test');
    ok('a quote: staff 0 with a reason lowers the bill', $near($aq2['fee_kept'], 0) && $near($aq2['new_bill'], 3600) && $aq2['fee_error'] === '');
    $threw = '';
    try {
        rh_stay_correction_apply($pdo, $aid, ['new_check_in' => substr($ab['check_in_date'], 0, 10), 'new_check_out' => $aOut, 'reason' => 'unused nights test',
            'expected_new_bill' => 3600, 'expected_money_back' => 0, 'can_correct' => true, 'can_refund' => false], $who);
    } catch (Exception $e) { $threw = $e->getMessage(); }
    ok('a apply: a review without the kept amount is refused', $threw !== '' && $tagSum($aid) == 0.0, $threw);
    $ar = rh_stay_correction_apply($pdo, $aid, ['new_check_in' => substr($ab['check_in_date'], 0, 10), 'new_check_out' => $aOut, 'reason' => 'unused nights test',
        'expected_new_bill' => $aq['new_bill'], 'expected_money_back' => $aq['money_back'], 'can_correct' => true, 'can_refund' => false], $who);
    $histAct->execute([$aid]);
    ok('a apply: 2,400 row posted + History', $near($tagSum($aid), 2400) && $histAct->fetchAll(PDO::FETCH_COLUMN) === ['Kept for unused nights']);
} catch (Throwable $e) {
    ok('paths a/c: no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// b: processBookingDateAdjustment opens its own transaction, so only its refusals are checked (no write), on a booking
// committed for the test and deleted straight after.
$bid2 = 0;
try {
    $bid2 = $mk('confirmed', date('Y-m-d', strtotime('+3 day')), 5, false);
    $bIn = date('Y-m-d', strtotime('+3 day'));
    $bOut = date('Y-m-d', strtotime('+6 day'));
    $br = processBookingDateAdjustment($bid2, $bIn, $bOut, 'unused nights test', $admin, 'Test', []);
    ok('b: refused when the kept amount was not shown', empty($br['success']) && stripos((string)$br['message'], 'not shown') !== false && $tagSum($bid2) == 0.0, json_encode($br));
    $br = processBookingDateAdjustment($bid2, $bIn, $bOut, 'unused nights test', $admin, 'Test', ['quoted' => '1.00']);
    ok('b: refused when the quote shown differs', empty($br['success']) && $tagSum($bid2) == 0.0, json_encode($br));
    $nb = $pdo->prepare('SELECT check_out_date FROM bookings WHERE id = ?');
    $nb->execute([$bid2]);
    ok('b: dates unchanged after the refusals', substr((string)$nb->fetchColumn(), 0, 10) === date('Y-m-d', strtotime('+8 day')));
} catch (Throwable $e) {
    ok('path b: no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($bid2 > 0) {
        $pdo->prepare('DELETE FROM booking_timeline_logs WHERE booking_id = ?')->execute([$bid2]);
        $pdo->prepare('DELETE FROM bookings WHERE id = ? AND booking_reference LIKE ?')->execute([$bid2, 'UNT%']);
    }
}

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$lk = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE booking_reference IN (' . implode(',', array_fill(0, count($refs), '?')) . ')');
$lk->execute($refs);
ok('nothing leaked (rolled back / deleted)', (int)$lk->fetchColumn() === 0);


echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
