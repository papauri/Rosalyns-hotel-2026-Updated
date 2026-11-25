<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * In-process scenarios for scripts/tests/room_packages_test.php, run by page_harness.php (one outer transaction that
 * never commits, rolled back). Everything is built from throw-away room types, packages and bookings.
 * $_POST: scenario, mode ('inclusive' | 'exclusive': VAT pricing mode forced for this run).
 */
$sc = (string)($_POST['scenario'] ?? '');
$mode = (string)($_POST['mode'] ?? 'inclusive');
$out = ['scenario' => $sc, 'mode' => $mode];
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/booked-rate-change.php';
require_once $root . '/includes/booking-room-change.php';
require_once $root . '/includes/booking-timeline.php';

global $_SITE_SETTINGS;
$_SITE_SETTINGS['vat_enabled'] = '1';
$_SITE_SETTINGS['vat_rate'] = '17.5';
$_SITE_SETTINGS['vat_pricing_mode'] = $mode === 'exclusive' ? 'exclusive' : 'inclusive';
$_SITE_SETTINGS['tourism_levy_enabled'] = '1';
$_SITE_SETTINGS['tourism_levy_percent'] = '1';
$_SITE_SETTINGS['currency_symbol'] = 'MWK';
// Proof the forced mode reached the pricing code (the driver checks the two runs differ here).
$out['mode_seen'] = vat_mode();
$out['vat_on_100k'] = rh_stay_totals(100000, 'price', false)['vat'];

$adminRow = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$actor = ['id' => (int)$adminRow['id'], 'full_name' => (string)$adminRow['full_name'], 'username' => (string)$adminRow['username']];
$tag = strtoupper(bin2hex(random_bytes(3)));
$in = date('Y-m-d', strtotime('+20 day'));
$addDays = static function (string $d, int $n): string {
    return date('Y-m-d', strtotime($d . ' +' . $n . ' day'));
};

$mkType = function (string $name) use ($pdo, $tag): int {
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', 100000, 4, 3, 3, 1, ?)")
        ->execute(['ZZ RP ' . $name . ' ' . $tag, 'zz-rp-' . strtolower($name) . '-' . strtolower($tag)]);
    return (int)$pdo->lastInsertId();
};
$mkPkg = function (string $name, string $type, float $price, ?array $typeIds = null, int $active = 1) use ($pdo, $tag): int {
    $pdo->prepare("INSERT INTO room_packages (name, slug, description, short_description, icon, price_type, price_amount, inclusions, applies_to, room_type_ids, is_featured, is_active, sort_order)
        VALUES (?, ?, '', '', 'fa-box', ?, ?, '', ?, ?, 0, ?, 0)")
        ->execute(['ZZ RP ' . $name . ' ' . $tag, 'zz-rp-' . strtolower($name) . '-' . strtolower($tag), $type, $price,
            $typeIds === null ? 'all' : 'room_types', $typeIds === null ? null : json_encode($typeIds), $active]);
    return (int)$pdo->lastInsertId();
};
// $pk: [[package_id, name, type, price, quantity, total], ...]; totals all-in (room gross + package gross).
$mkBooking = function (int $typeId, int $nights, int $adults, array $pk, ?int $primaryId = null, float $roomNight = 100000.0, ?float $pkgTotalOverride = null) use ($pdo, $tag, $in, $addDays): int {
    static $n = 0;
    $n++;
    $pkgTotal = $pkgTotalOverride ?? round(array_sum(array_column($pk, 5)), 2);
    $gross = round($roomNight * $nights + $pkgTotal, 2);
    $tt = rh_stay_totals_with_package($gross, $pkgTotal, true);
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, package_total, primary_booking_id, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (?,?,'ZZ RP TEST','zz@example.invalid','+265000000009','Malawi',?,?,0,50,?,?,?,?,0,?,1,17.5,?,?,?,?,'room packages test','confirmed','unpaid',0,'double',?,NOW())")
        ->execute(['ZRP' . $tag . $n, $typeId, $adults, $adults, $in, $addDays($in, $nights), $nights, round($tt['net'] + $tt['levy'], 2), $tt['levy'], $tt['vat'],
            $tt['total_with_vat'], $pkgTotal, $primaryId, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO booking_packages (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($pk as $p) {
        $ins->execute([$id, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]]);
    }
    recalculateBookingFinancials($id);
    return $id;
};
$row = function (int $id) use ($pdo): array {
    $s = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};
$pkgRowsOf = function (int $id) use ($pdo): array {
    $s = $pdo->prepare("SELECT package_name, price_type, price_amount, quantity, total_cost FROM booking_packages WHERE booking_id = ? ORDER BY id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$history = function (int $id) use ($pdo): array {
    $s = $pdo->prepare("SELECT action, description, old_value, new_value FROM booking_timeline_logs WHERE booking_id = ? AND action LIKE '%ackage%' ORDER BY id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
// The invariants every DB case checks: package_total = sum of its rows, levy from the room part only, totals add up.
$inv = function (int $id) use ($row, $pkgRowsOf): array {
    $b = $row($id);
    $rows = $pkgRowsOf($id);
    $sum = round(array_sum(array_map('floatval', array_column($rows, 'total_cost'))), 2);
    $pkg = round((float)$b['package_total'], 2);
    $split = rh_booked_stay_split($b);
    $want = rh_stay_totals_with_package((float)$b['total_with_vat'], $split['package_gross'], true);
    return [
        'pkg_eq_rows' => !$rows || abs($sum - $pkg) <= 0.01,
        'levy_room_only' => abs((float)$b['tourism_levy_amount'] - $want['levy']) <= 0.02,
        'parts_add_up' => abs(round((float)$b['total_amount'] + (float)$b['vat_amount'], 2) - round((float)$b['total_with_vat'], 2)) <= 0.02,
    ];
};

if ($sc === 'shorten') {
    // Single booking, 5 nights, 2 adults: Breakfast 20,000 pp/night (saved as quantity 1, older rows), Dinner 50,000 a night, Transfer 30,000 per stay.
    $t = $mkType('S');
    $pB = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $pD = $mkPkg('Dinner', 'per_night', 50000);
    $pT = $mkPkg('Transfer', 'per_stay', 30000);
    $id = $mkBooking($t, 5, 2, [[$pB, 'Breakfast', 'per_person_per_night', 20000, 1, 200000], [$pD, 'Dinner', 'per_night', 50000, 1, 250000], [$pT, 'Transfer', 'per_stay', 30000, 1, 30000]]);
    $b = $row($id);
    $out['before'] = ['total' => (float)$b['total_with_vat'], 'pkg' => (float)$b['package_total'], 'per_night' => rh_booked_stay_split($b)['per_night']];
    $rp = rh_reprice_at_booked_rate($b, 2);
    $out['rp'] = ['new_gross' => $rp['new_gross'], 'package_gross' => $rp['package_gross'], 'package_gross_old' => $rp['package_gross_old'], 'note' => $rp['packages']['note'] ?? ''];
    $out['line'] = rh_booked_rate_change_for_booking($b, $rp, 2, ['currency' => 'MWK'])['text'] ?? '';
    rh_write_booking_stay_totals($pdo, $id, $rp['totals'], 2, $in, $addDays($in, 2));
    $a = $row($id);
    $out['after'] = ['total' => (float)$a['total_with_vat'], 'pkg' => (float)$a['package_total'], 'per_night' => rh_booked_stay_split($a)['per_night']];
    $out['rows'] = $pkgRowsOf($id);
    $out['history'] = $history($id);
    $out['inv'] = $inv($id);
} elseif ($sc === 'extend') {
    // End to end through processBookingDateAdjustment(): 5 -> 7 nights.
    $t = $mkType('E');
    $pB = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $pD = $mkPkg('Dinner', 'per_night', 50000);
    $pT = $mkPkg('Transfer', 'per_stay', 30000);
    $id = $mkBooking($t, 5, 2, [[$pB, 'Breakfast', 'per_person_per_night', 20000, 10, 200000], [$pD, 'Dinner', 'per_night', 50000, 5, 250000], [$pT, 'Transfer', 'per_stay', 30000, 1, 30000]]);
    $res = processBookingDateAdjustment($id, $in, $addDays($in, 7), 'room packages test', (int)$actor['id'], $actor['full_name']);
    $out['res'] = ['success' => $res['success'] ?? null, 'message' => $res['message'] ?? ''];
    $a = $row($id);
    $out['after'] = ['total' => (float)$a['total_with_vat'], 'pkg' => (float)$a['package_total'], 'nights' => (int)$a['number_of_nights'], 'per_night' => rh_booked_stay_split($a)['per_night']];
    $out['rows'] = $pkgRowsOf($id);
    $out['history'] = $history($id);
    $out['inv'] = $inv($id);
} elseif ($sc === 'adults') {
    // Edit booking: 2 -> 3 adults, same nights (rh_reprice_at_booked_rate with the new adult count).
    $t = $mkType('A');
    $pB = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $pD = $mkPkg('Dinner', 'per_night', 50000);
    $id = $mkBooking($t, 5, 2, [[$pB, 'Breakfast', 'per_person_per_night', 20000, 10, 200000], [$pD, 'Dinner', 'per_night', 50000, 5, 250000]]);
    $b = $row($id);
    $rp = rh_reprice_at_booked_rate($b, 5, 0.0, ['adults' => 3]);
    rh_write_booking_stay_totals($pdo, $id, $rp['totals']);
    $a = $row($id);
    $out['after'] = ['total' => (float)$a['total_with_vat'], 'pkg' => (float)$a['package_total'], 'per_night' => rh_booked_stay_split($a)['per_night']];
    $out['rows'] = $pkgRowsOf($id);
    $out['history'] = $history($id);
    $out['inv'] = $inv($id);
    // A per-night-only package does not move on an adults change.
    $pD2 = $mkPkg('Dinner2', 'per_night', 50000);
    $id2 = $mkBooking($t, 5, 2, [[$pD2, 'Dinner2', 'per_night', 50000, 5, 250000]]);
    $q2 = rh_booking_package_requote($pdo, $row($id2), 5, 3);
    $out['per_night_only_changed'] = $q2['changed'] ?? null;
} elseif ($sc === 'group') {
    // Lead L (2 adults, 5 nights) + second room S (2 adults, 3 nights). Breakfast on L for the party: 20,000 x 4 x 5.
    $t = $mkType('G');
    $pB = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $L = $mkBooking($t, 5, 2, [[$pB, 'Breakfast', 'per_person_per_night', 20000, 20, 400000]]);
    $S = $mkBooking($t, 3, 2, [], $L);
    $out['before'] = ['L' => (float)$row($L)['total_with_vat'], 'S' => (float)$row($S)['total_with_vat'], 'L_per_night' => rh_booked_stay_split($row($L))['per_night']];
    // 1) S goes from 2 adults to 1: the party package on L is worked out again on 3 adults x L's 5 nights.
    $rp = rh_reprice_at_booked_rate($row($S), 3, 0.0, ['adults' => 1]);
    $out['rp1'] = ['self' => $rp['packages']['self'] ?? null, 'changed' => $rp['packages']['changed'] ?? null, 'S_new_gross' => $rp['new_gross'], 'note' => $rp['packages']['note'] ?? ''];
    rh_write_booking_stay_totals($pdo, $S, $rp['totals']);
    $pdo->prepare("UPDATE bookings SET adult_guests = 1, number_of_guests = 1 WHERE id = ?")->execute([$S]); // what Edit Booking saves with it
    $out['step1'] = ['L' => (float)$row($L)['total_with_vat'], 'L_pkg' => (float)$row($L)['package_total'], 'S' => (float)$row($S)['total_with_vat'],
        'L_per_night' => rh_booked_stay_split($row($L))['per_night'], 'L_rows' => $pkgRowsOf($L), 'L_due' => (float)$row($L)['amount_due']];
    $out['hist_L'] = $history($L);
    $out['hist_S'] = $history($S);
    // 2) S goes from 3 nights to 2: the package uses L's nights, adults unchanged -> no package change.
    $rp2 = rh_reprice_at_booked_rate($row($S), 2);
    $out['rp2_changed'] = $rp2['packages']['changed'] ?? false;
    rh_write_booking_stay_totals($pdo, $S, $rp2['totals'], 2, $in, $addDays($in, 2));
    $out['step2'] = ['L' => (float)$row($L)['total_with_vat'], 'S' => (float)$row($S)['total_with_vat']];
    // 3) L itself shortens 5 -> 4 nights: 20,000 x 3 adults x 4.
    $rp3 = rh_reprice_at_booked_rate($row($L), 4);
    rh_write_booking_stay_totals($pdo, $L, $rp3['totals'], 4, $in, $addDays($in, 4));
    $out['step3'] = ['L' => (float)$row($L)['total_with_vat'], 'L_pkg' => (float)$row($L)['package_total']];
    $out['inv_L'] = $inv($L);
    $out['inv_S'] = $inv($S);
    // 4) A stale quote (the lead's package moved meanwhile) stops the save.
    $stale = rh_booking_package_requote($pdo, $row($S), 2, 2);
    $pdo->prepare("UPDATE bookings SET package_total = package_total + 1 WHERE id = ?")->execute([$L]);
    try {
        rh_booking_package_requote_write($pdo, $S, $stale);
        $out['stale_refused'] = false;
    } catch (RuntimeException $e) {
        $out['stale_refused'] = strpos($e->getMessage(), 'changed while this was being saved') !== false;
    }
} elseif ($sc === 'unverified') {
    // package_total does not equal its rows (older data): never recalculated, staff told.
    $t = $mkType('U');
    $pB = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $id = $mkBooking($t, 5, 2, [[$pB, 'Breakfast', 'per_person_per_night', 20000, 10, 200000]], null, 100000.0, 250000.0);
    $b = $row($id);
    $rp = rh_reprice_at_booked_rate($b, 2);
    $out['rp'] = ['new_gross' => $rp['new_gross'], 'package_gross' => $rp['package_gross'], 'unverified' => $rp['packages']['unverified'] ?? null,
        'changed' => $rp['packages']['changed'] ?? null, 'note' => $rp['packages']['note'] ?? ''];
    $out['line'] = rh_booked_rate_change_for_booking($b, $rp, 2, ['currency' => 'MWK'])['text'] ?? '';
} elseif ($sc === 'room_change') {
    // Breakfast is offered for type A only; moving the booking to type B needs "I have told the guest".
    $tA = $mkType('RA');
    $tB = $mkType('RB');
    $pB = $mkPkg('Breakfast', 'per_stay', 30000, [$tA]);
    $pAll = $mkPkg('Spa', 'per_stay', 10000);
    $id = $mkBooking($tA, 2, 2, [[$pB, 'Breakfast', 'per_stay', 30000, 1, 30000], [$pAll, 'Spa', 'per_stay', 10000, 1, 10000]]);
    $b = $row($id);
    $rs = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $rs->execute([$tA]);
    $roomA = $rs->fetch(PDO::FETCH_ASSOC);
    $rs->execute([$tB]);
    $roomB = $rs->fetch(PDO::FETCH_ASSOC);
    $pkRows = rh_room_change_package_rows($pdo, $b);
    $out['flags_B'] = rh_room_change_quote($b, $roomA, $roomB, $pkRows)['package_flags'];
    $out['flags_pure'] = rh_room_change_quote($b, $roomA, $roomB)['package_flags'];
    $out['flag_text'] = rh_room_change_package_flag_text($out['flags_B'], (string)$roomB['name']);
    $opts = rh_room_change_options($pdo, $id);
    foreach ($opts['options'] as $o) {
        if ($o['id'] === $tB) {
            $out['options_flag_text'] = $o['quote']['package_flag_text'] ?? null;
        }
    }
    $before = (float)$b['total_with_vat'];
    $r1 = rh_apply_room_type_change($pdo, $id, $tB, 'charge', 'change_guest_request', '', $actor, true, false);
    $out['no_ack'] = ['success' => $r1['success'], 'message' => $r1['message'], 'room_id_kept' => (int)$row($id)['room_id'] === $tA];
    $r2 = rh_apply_room_type_change($pdo, $id, $tB, 'charge', 'change_guest_request', '', $actor, true, true);
    $out['ack'] = ['success' => $r2['success'], 'message' => $r2['message'], 'room_id' => (int)$row($id)['room_id'] === $tB,
        'pkg_kept' => abs((float)$row($id)['package_total'] - 40000) <= 0.01, 'total_same' => abs((float)$row($id)['total_with_vat'] - $before) <= 0.01];
    $s = $pdo->prepare("SELECT description FROM booking_timeline_logs WHERE booking_id = ? AND description LIKE '%Package check%' ORDER BY id DESC LIMIT 1");
    $s->execute([$id]);
    $out['ack_history'] = (string)$s->fetchColumn();
} elseif ($sc === 'refuse') {
    // Fault C: a package switched off, or not offered for the room, is refused at submit with its name.
    $tA = $mkType('CA');
    $tB = $mkType('CB');
    $pOk = $mkPkg('Breakfast', 'per_person_per_night', 20000);
    $pOff = $mkPkg('Sunset', 'per_stay', 10000, null, 0);
    $pOther = $mkPkg('Safari', 'per_night', 5000, [$tB]);
    $r = rh_packages_for_booking($pdo, $tA, [$pOk, $pOff, $pOther], 2, 3);
    $out['missing'] = $r['missing'];
    $out['rows'] = array_map(static function ($x) {
        return ['name' => $x['package_name'], 'quantity' => $x['quantity'], 'total' => $x['total_cost']];
    }, $r['rows']);
    $out['total'] = $r['total'];
    $out['message'] = rh_package_unavailable_message($r['missing']);
}
echo json_encode($out);
