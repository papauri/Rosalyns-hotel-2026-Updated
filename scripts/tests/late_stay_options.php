<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Late check-in / leave-date choices (owner 2026-11-12 "Dont assume, have options").
 * Usage (project root, TEST HOTEL ONLY): php scripts/tests/late_stay_options.php
 *   P*  pure helper (includes/late-stay.php): left on the booked date = 0; today = N nights x booked rate; another
 *       date; early leave = refund; out-of-range dates refused; "nights actually stayed" basis; check-in choices;
 *       validateCheckIn() late_days / stay_ended.
 *   D*  admin/bookings.php through scripts/tests/page_harness.php (one outer transaction, always rolled back):
 *       assess quote == settle charge; booked date writes no money and keeps the stay; another date; out of range and
 *       old 'charge' posts refused; amount received recorded through the payment path; actual-arrival basis must be
 *       chosen; late check-in needs a choice; "already stayed and left" = checked out, no extra charge; History rows.
 * Fixtures are throw-away rows found by unique tags; the final invoice is pre-marked generated (no PDF / email).
 */
$root = dirname(__DIR__, 2);
chdir($root);
// Owner rail: DB tests run at the test hotel only. The live hotel's repo must never run this (checked before any DB use).
if (stripos(str_replace('\\', '/', $root), 'Rosalyns-hotel-2026') !== false) {
    fwrite(STDERR, "Refusing to run in the live hotel's repo. Copy the changed files to the test hotel and run it there.\n");
    exit(2);
}
require_once $root . '/config/database.php';
require_once $root . '/includes/late-stay.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$tol = BALANCE_TOLERANCE;
$d = function (int $offset, string $base = '2026-11-12'): string { return date('Y-m-d', strtotime($base . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' day')); };

echo "== P: pure quote (booked 8 -> 11 Nov, 3 nights, 1,068,000 all-in, paid in full; today 12 Nov) ==\n";
$bk = ['check_in_date' => '2026-11-08', 'check_out_date' => '2026-11-11', 'number_of_nights' => 3, 'total_with_vat' => 1068000,
    'total_amount' => 1068000, 'vat_amount' => 0, 'tourism_levy_amount' => 0, 'tourism_levy_percent' => 0, 'package_total' => 0,
    'child_supplement_total' => 0, 'amount_paid' => 1068000, 'folio_charges_total' => 0];
$T = '2026-11-12';
$q = rh_late_stay_quote($bk, '2026-11-11', 'booked', null, $T);
ok('P1 left on the booked date: no charge, nothing repriced', $q['ok'] && $q['direction'] === 'none' && abs($q['amount']) < $tol && $q['reprice'] === null && $q['nights'] === 3 && $q['new_check_out'] === '2026-11-11', json_encode($q));
$q = rh_late_stay_quote($bk, $T, 'booked', null, $T);
ok('P2 stayed until today: 1 extra night x booked rate (356,000)', $q['ok'] && $q['direction'] === 'charge' && $q['extra_nights'] === 1 && abs($q['amount'] - 356000) < $tol && $q['new_check_out'] === $T && $q['nights'] === 4, json_encode(array_diff_key($q, ['reprice' => 1])));
$q14 = rh_late_stay_quote($bk, '2026-11-14', 'booked', null, '2026-11-15');
ok('P3 another date (14 Nov, recorded 15th): 3 extra nights, check-out = the leave date, not today', $q14['ok'] && $q14['extra_nights'] === 3 && abs($q14['amount'] - 1068000) < $tol && $q14['new_check_out'] === '2026-11-14', json_encode(array_diff_key($q14, ['reprice' => 1])));
$q = rh_late_stay_quote($bk, '2026-11-10', 'booked', null, $T);
ok('P4 left early (10th, recorded 12th): refund 1 unused night, not a charge', $q['ok'] && $q['direction'] === 'refund' && $q['unused_nights'] === 1 && abs($q['amount'] - 356000) < $tol && abs($q['refundable'] - 356000) < $tol && $q['new_check_out'] === '2026-11-10', json_encode(array_diff_key($q, ['reprice' => 1])));
foreach (['2026-11-13' => 'after today', '2026-11-07' => 'before arrival', 'not-a-date' => 'not a date', '' => 'empty'] as $bad => $why) {
    $q = rh_late_stay_quote($bk, $bad, 'booked', null, $T);
    ok("P5 leave date $why is refused", !$q['ok'] && $q['error'] !== '', json_encode($q));
}
$q = rh_late_stay_quote($bk, '2026-11-14', 'actual', '2026-11-12', '2026-11-14');
ok('P6 actual basis (arrived 12th, left 14th): 2 nights, bill reduced by 1 night (refund path)', $q['ok'] && $q['nights'] === 2 && $q['direction'] === 'refund' && abs($q['amount'] - 356000) < $tol && $q['new_check_in'] === '2026-11-12' && $q['new_check_out'] === '2026-11-14', json_encode(array_diff_key($q, ['reprice' => 1])));
$q = rh_late_stay_quote($bk, '2026-11-14', 'booked', '2026-11-12', '2026-11-14');
ok('P7 booked basis, same dates: booked nights stand + 3 nights after the booked check-out', $q['ok'] && $q['extra_nights'] === 3 && $q['direction'] === 'charge' && abs($q['amount'] - 1068000) < $tol, json_encode(array_diff_key($q, ['reprice' => 1])));
$q = rh_late_stay_quote($bk, '2026-11-14', 'actual', null, '2026-11-14');
ok('P8 actual basis without a recorded later arrival is refused', !$q['ok'], json_encode($q));
$opts = rh_late_stay_options($bk, null, $T);
ok('P9 options list every date from the arrival to today, each quoted by the same helper', array_keys($opts['dates']) === ['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] && abs($opts['dates'][$T]['booked']['amount'] - rh_late_stay_quote($bk, $T, 'booked', null, $T)['amount']) < $tol && !$opts['has_actual'], json_encode(array_keys($opts['dates'])));
$opts = rh_late_stay_options($bk, '2026-11-12', '2026-11-14');
ok('P10 with a recorded later arrival both bases are quoted from that day', $opts['has_actual'] && isset($opts['dates']['2026-11-14']['actual']) && !isset($opts['dates']['2026-11-11']['actual']));
$desc = rh_late_stay_describe(rh_late_stay_quote($bk, '2026-11-11', 'booked', null, $T), 'MWK');
ok('P11 History wording names the option and the real leave date', strpos($desc, 'Guest left on the booked date 11 Nov 2026: no extra nights charged') === 0, $desc);
$desc = rh_late_stay_describe(rh_late_stay_quote($bk, $T, 'booked', null, $T), 'MWK');
ok('P12 charge wording carries nights and amount', strpos($desc, '1 night(s) more than booked') !== false && strpos($desc, 'MWK 356,000.00 added') !== false, $desc);

echo "== P: late check-in choices + validateCheckIn ==\n";
$w = rh_late_stay_window($bk, $T);
ok('P13 window: 4 days late, stay ended', $w['late_days'] === 4 && $w['stay_ended'] === true, json_encode($w));
ok('P14 no choice for a late arrival is refused', !rh_late_checkin_choice($w, null)['ok'] && !rh_late_checkin_choice($w, 'bogus')['ok']);
ok('P15 the three choices are accepted for an ended stay', rh_late_checkin_choice($w, 'booked_date')['ok'] && rh_late_checkin_choice($w, 'today')['ok'] && rh_late_checkin_choice($w, 'ended')['ok']);
$w2 = rh_late_stay_window(['check_in_date' => '2026-11-10', 'check_out_date' => '2026-11-14'], $T);
ok('P16 "already stayed and left" is refused while the stay is still running', !rh_late_checkin_choice($w2, 'ended')['ok'] && rh_late_checkin_choice($w2, 'today')['ok']);
$w3 = rh_late_stay_window(['check_in_date' => $T, 'check_out_date' => '2026-11-14'], $T);
ok('P17 on-time arrival needs no choice', rh_late_checkin_choice($w3, null) === ['ok' => true, 'choice' => '', 'error' => '']);
ok('P18 arrival recorded: booked date or today', rh_late_checkin_arrival($w, 'booked_date') === '2026-11-08' && rh_late_checkin_arrival($w, 'today') === $T);
$v = validateCheckIn(['status' => 'confirmed', 'payment_status' => 'paid', 'check_in_date' => date('Y-m-d', strtotime('-4 day')), 'check_out_date' => date('Y-m-d', strtotime('-1 day'))]);
ok('P19 validateCheckIn does not block a late arrival and reports late_days / stay_ended', $v['allowed'] && $v['late_days'] === 4 && $v['stay_ended'] === true, json_encode($v));
$v = validateCheckIn(['status' => 'pending', 'payment_status' => 'paid', 'check_in_date' => date('Y-m-d')]);
ok('P20 every validateCheckIn answer has the same keys', !$v['allowed'] && array_key_exists('late_days', $v) && $v['stay_ended'] === false, json_encode($v));

// ───────────────────────── D: through admin/bookings.php (rolled back) ─────────────────────────
$harness = $root . '/scripts/tests/page_harness.php';
function lso_run(array $cfg): array {
    global $harness;
    $cfg += ['ajax' => true, 'fake_smtp' => 9];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/bookings.php ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -800)]; } }
    return $res;
}
function lso_clean(array $r, string $label): void {
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
}
function lso_tag(): string { return strtoupper(bin2hex(random_bytes(3))); }
$today = date('Y-m-d');
$ago = function (int $n) use ($today): string { return date('Y-m-d', strtotime($today . ' -' . $n . ' day')); };
/** Room type + clean room + booking ZLS<tag>: $status, stay $in -> $out, 100 a night, nothing paid (a confirmed one reads
 *  'partial' so the check-in payment rule lets it through; the balance gate still sees 300 owed). */
function lso_fixture(string $tag, string $status, string $in, string $out, int $nights, ?string $arrival = null): array {
    $type = "(SELECT id FROM rooms WHERE slug = 'zz-ls-" . strtolower($tag) . "')";
    $room = "(SELECT id FROM individual_rooms WHERE room_number = 'ZL1{$tag}')";
    $b = "(SELECT id FROM bookings WHERE booking_reference = 'ZLS{$tag}')";
    $total = 100 * $nights;
    $sql = [
        "INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES ('ZZ LS TEST {$tag}', '', '', 100, 2, 2, 3, 1, 'zz-ls-" . strtolower($tag) . "')",
        "INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES ($type, 'ZL1{$tag}', 'zz test', 1, 'available', 'completed', 1)",
        "INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, amount_due, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, final_invoice_generated, created_at)
         VALUES ('ZLS{$tag}', $type, $room, 'ZZ LATE STAY TEST', '', '+265000000009', 'Malawi', 2, 2, 0, 50, '{$in}', '{$out}', {$nights}, {$total}, 0, 0, 0, 0, 0, {$total}, {$total},
            'late stay test', '{$status}', '" . ($status === 'confirmed' ? 'partial' : 'unpaid') . "', 0, 'double', '" . bin2hex(random_bytes(16)) . "', 1, NOW())",
        "INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot) VALUES ($b, $room, NULL, 1, NOW(), 'assigned')",
    ];
    if ($arrival !== null) {
        $sql[] = "INSERT INTO booking_audit_log (booking_id, booking_reference, action, old_values, new_values, note) VALUES ($b, 'ZLS{$tag}', 'checked-in', '{\"status\":\"confirmed\"}', '{\"status\":\"checked-in\",\"arrival_choice\":\"today\",\"actual_arrival\":\"{$arrival}\"}', 'zz test')";
    }
    return $sql;
}
function lso_verify(string $tag): array {
    $b = "(SELECT id FROM bookings WHERE booking_reference = 'ZLS{$tag}')";
    return [
        "SELECT status, check_in_date, check_out_date, number_of_nights, total_with_vat, amount_paid, amount_due FROM bookings WHERE booking_reference = 'ZLS{$tag}'",
        "SELECT payment_type, total_amount, vat_amount, payment_amount, notes FROM payments WHERE booking_id = $b ORDER BY id",
        "SELECT action, new_values, note FROM booking_audit_log WHERE booking_id = $b AND note <> 'zz test' ORDER BY id",
        "SELECT action, description FROM booking_timeline_logs WHERE booking_id = $b ORDER BY id",
    ];
}
$bid = function (string $tag): string { return "SELECT id FROM bookings WHERE booking_reference = 'ZLS{$tag}'"; };

echo "== D1: assess quotes every leave date; settle 'today' charges exactly the quote ==\n";
$t = lso_tag();
$fx = lso_fixture($t, 'checked-in', $ago(4), $ago(2), 2);
$r = lso_run(['post' => ['action' => 'checkout_assess', 'id' => '{b}'], 'setup' => $fx, 'vars' => ['b' => $bid($t)]]);
lso_clean($r, 'D1a');
$s = $r['json']['settlement'] ?? [];
$qToday = $s['quotes'][$today]['booked'] ?? null;
ok('D1 assess asks for a leave date (type leave), overdue, quotes from arrival to today', ($s['type'] ?? '') === 'leave' && !empty($s['overdue']) && count($s['quotes'] ?? []) === 5 && ($s['sched_ymd'] ?? '') === $ago(2), json_encode([$s['type'] ?? null, array_keys($s['quotes'] ?? []), $r['raw'] ?? null]));
ok('D1 booked date = no change; today = 2 extra nights', ($s['quotes'][$ago(2)]['booked']['direction'] ?? '') === 'none' && ($qToday['direction'] ?? '') === 'charge' && (int)($qToday['extra_nights'] ?? 0) === 2, json_encode([$s['quotes'][$ago(2)] ?? null, $qToday]));
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $today, 'confirm_checkout_with_balance' => '1'],
    'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D1b');
$row = $r['verify'][0][0] ?? [];
ok('D1 settle: checked out, check-out = today, 4 nights', ($row['status'] ?? '') === 'checked-out' && ($row['check_out_date'] ?? '') === $today && (int)($row['number_of_nights'] ?? 0) === 4, json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
ok('D1 quote == settle (to the cent)', $qToday !== null && abs(((float)($row['total_with_vat'] ?? 0) - 200) - (float)$qToday['amount']) < $tol, json_encode([$row['total_with_vat'] ?? null, $qToday['amount'] ?? null]));
ok('D1 nothing received = no payment row (the extra stays owed)', ($r['verify'][1] ?? null) === [], json_encode($r['verify'][1] ?? null));
$aud = $r['verify'][2] ?? [];
$last = end($aud) ?: [];
$nv = json_decode((string)($last['new_values'] ?? ''), true) ?: [];
ok('D1 History: checked-out audit row with the leave date, nights and amount', ($last['action'] ?? '') === 'checked-out' && ($nv['leave_date'] ?? '') === $today && (int)($nv['number_of_nights'] ?? 0) === 4 && abs((float)($nv['amount'] ?? 0) - (float)($qToday['amount'] ?? -1)) < $tol && strpos((string)($last['note'] ?? ''), '2 night(s) more than booked') !== false, json_encode($last));
ok('D1 timeline step "Checkout settlement" written', in_array('Checkout settlement', array_column($r['verify'][3] ?? [], 'action'), true), json_encode($r['verify'][3] ?? null));

echo "== D2: left on the booked date writes no money and keeps the booked stay ==\n";
$t = lso_tag();
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $ago(2), 'confirm_checkout_with_balance' => '1'],
    'setup' => lso_fixture($t, 'checked-in', $ago(4), $ago(2), 2), 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D2');
$row = $r['verify'][0][0] ?? [];
ok('D2 checked out; dates, nights and total unchanged; no payment', ($row['status'] ?? '') === 'checked-out' && ($row['check_out_date'] ?? '') === $ago(2) && (int)$row['number_of_nights'] === 2 && abs((float)$row['total_with_vat'] - 200) < $tol && ($r['verify'][1] ?? null) === [], json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
$aud = $r['verify'][2] ?? [];
$last = end($aud) ?: [];
ok('D2 History note: "Guest left on the booked date ...: no extra nights charged"', strpos((string)($last['note'] ?? ''), 'Guest left on the booked date ' . rh_late_stay_fmt($ago(2)) . ': no extra nights charged') === 0, json_encode($last));

echo "== D3: another date + amount received through the payment path ==\n";
$t = lso_tag();
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $ago(1), 'amount_received' => '300', 'payment_method' => 'cash'],
    'setup' => lso_fixture($t, 'checked-in', $ago(4), $ago(2), 2), 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D3');
$row = $r['verify'][0][0] ?? [];
$pays = $r['verify'][1] ?? [];
ok('D3 check-out = the chosen date (yesterday), 3 nights, total 300', ($row['status'] ?? '') === 'checked-out' && ($row['check_out_date'] ?? '') === $ago(1) && (int)$row['number_of_nights'] === 3 && abs((float)$row['total_with_vat'] - 300) < $tol, json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
ok('D3 one payment for exactly the amount received, VAT split, note carries the leave date', count($pays) === 1 && abs((float)$pays[0]['total_amount'] - 300) < $tol && abs((float)$pays[0]['payment_amount'] + (float)$pays[0]['vat_amount'] - 300) < $tol && strpos((string)$pays[0]['notes'], rh_late_stay_fmt($ago(1))) !== false, json_encode($pays));
ok('D3 settled: nothing owed', abs((float)($row['amount_due'] ?? 1)) < $tol, json_encode($row));

echo "== D4: refusals (nothing changed) ==\n";
foreach ([
    'D4a leave date tomorrow' => ['settlement_action' => 'leave', 'leave_date' => date('Y-m-d', strtotime($today . ' +1 day'))],
    'D4b leave date before arrival' => ['settlement_action' => 'leave', 'leave_date' => $ago(6)],
    'D4c old cached page posting charge' => ['settlement_action' => 'charge'],
    'D4d overpayment' => ['settlement_action' => 'leave', 'leave_date' => $today, 'amount_received' => '9999'],
    'D4e balance without confirm' => ['settlement_action' => 'leave', 'leave_date' => $today],
] as $label => $post) {
    $t = lso_tag();
    $r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}'] + $post, 'setup' => lso_fixture($t, 'checked-in', $ago(4), $ago(2), 2), 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
    lso_clean($r, $label);
    $row = $r['verify'][0][0] ?? [];
    ok("$label: refused, booking unchanged, no payment", empty($r['json']['success']) && ($row['status'] ?? '') === 'checked-in' && ($row['check_out_date'] ?? '') === $ago(2) && abs((float)$row['total_with_vat'] - 200) < $tol && ($r['verify'][1] ?? null) === [], json_encode([$r['json'] ?? null, $row]));
}

echo "== D5: recorded later arrival: the basis must be chosen; 'actual' charges only the nights stayed ==\n";
$t = lso_tag();
$fx = lso_fixture($t, 'checked-in', $ago(5), $ago(3), 2, $ago(2));
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $today, 'confirm_checkout_with_balance' => '1'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D5a');
ok('D5a no basis posted: refused', empty($r['json']['success']) && strpos((string)($r['json']['message'] ?? ''), 'how to count the nights') !== false, json_encode($r['json'] ?? $r['raw']));
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $today, 'stay_basis' => 'actual', 'confirm_checkout_with_balance' => '1'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D5b');
$row = $r['verify'][0][0] ?? [];
ok('D5b actual: stay = arrival -> today, 2 nights, total 200 (no nights before arrival billed)', ($row['status'] ?? '') === 'checked-out' && ($row['check_in_date'] ?? '') === $ago(2) && ($row['check_out_date'] ?? '') === $today && (int)$row['number_of_nights'] === 2 && abs((float)$row['total_with_vat'] - 200) < $tol, json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $today, 'stay_basis' => 'booked', 'confirm_checkout_with_balance' => '1'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D5c');
$row = $r['verify'][0][0] ?? [];
ok('D5c booked: booked nights stand + 3 nights after the booked check-out (5 nights, 500)', (int)($row['number_of_nights'] ?? 0) === 5 && abs((float)($row['total_with_vat'] ?? 0) - 500) < $tol && ($row['check_in_date'] ?? '') === $ago(5), json_encode([$row, $r['json'] ?? null]));

echo "== D6: late check-in ==\n";
$t = lso_tag();
$fx = lso_fixture($t, 'confirmed', $ago(4), $ago(1), 3);
$r = lso_run(['post' => ['action' => 'update_status', 'id' => '{b}', 'status' => 'checked-in', 'email_guest' => '0'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D6a');
ok('D6a late check-in without a choice is refused (still confirmed)', empty($r['json']['success']) && (($r['verify'][0][0]['status'] ?? '') === 'confirmed'), json_encode([$r['json'] ?? null, $r['raw'] ?? null]));
$r = lso_run(['post' => ['action' => 'update_status', 'id' => '{b}', 'status' => 'checked-in', 'late_arrival' => 'today', 'email_guest' => '0'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D6b');
$aud = $r['verify'][2] ?? [];
$ci = $aud[0] ?? [];
ok('D6b "arriving today": checked in, dates and total unchanged', ($r['verify'][0][0]['status'] ?? '') === 'checked-in' && ($r['verify'][0][0]['check_in_date'] ?? '') === $ago(4) && abs((float)($r['verify'][0][0]['total_with_vat'] ?? 0) - 300) < $tol, json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));
$nv = json_decode((string)($ci['new_values'] ?? ''), true) ?: [];
ok('D6b History: arrival choice + actual arrival date recorded', ($ci['action'] ?? '') === 'checked-in' && ($nv['arrival_choice'] ?? '') === 'today' && ($nv['actual_arrival'] ?? '') === $today && strpos((string)$ci['note'], 'guest is arriving today') !== false, json_encode($aud));
$r = lso_run(['post' => ['action' => 'update_status', 'id' => '{b}', 'status' => 'checked-in', 'late_arrival' => 'ended', 'email_guest' => '1', 'confirm_checkout_with_balance' => '1'], 'setup' => $fx, 'vars' => ['b' => $bid($t)], 'verify' => array_merge(lso_verify($t), ["SELECT COUNT(*) AS n FROM booking_rooms WHERE booking_id = (" . $bid($t) . ") AND released_at IS NULL"])]);
lso_clean($r, 'D6c');
$row = $r['verify'][0][0] ?? [];
$aud = $r['verify'][2] ?? [];
ok('D6c "already stayed and left": checked out at the booked dates, total unchanged, no payment', ($row['status'] ?? '') === 'checked-out' && ($row['check_out_date'] ?? '') === $ago(1) && (int)$row['number_of_nights'] === 3 && abs((float)$row['total_with_vat'] - 300) < $tol && ($r['verify'][1] ?? null) === [], json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
$nv0 = json_decode((string)($aud[0]['new_values'] ?? ''), true) ?: [];
$nv1 = json_decode((string)($aud[1]['new_values'] ?? ''), true) ?: [];
ok('D6c History: checked-in (choice ended, no check-in email) + checked-out (leave = booked date, 0 extra)', count($aud) >= 2 && ($nv0['arrival_choice'] ?? '') === 'ended' && strpos((string)$aud[0]['note'], 'no check-in email') !== false && ($aud[1]['action'] ?? '') === 'checked-out' && ($nv1['leave_date'] ?? '') === $ago(1) && (int)($nv1['extra_nights'] ?? -1) === 0, json_encode($aud));
ok('D6c the booking no longer holds its room', (int)($r['verify'][4][0]['n'] ?? -1) === 0, json_encode($r['verify'][4] ?? null));
ok('D6c no check-in email even with the box ticked', stripos((string)($r['json']['message'] ?? ''), 'No check-in email sent') !== false, json_encode($r['json'] ?? null));
$t = lso_tag();
$r = lso_run(['post' => ['action' => 'update_status', 'id' => '{b}', 'status' => 'checked-in', 'late_arrival' => 'ended'], 'setup' => lso_fixture($t, 'confirmed', $ago(3), $today, 3), 'vars' => ['b' => $bid($t)], 'verify' => lso_verify($t)]);
lso_clean($r, 'D6d');
ok('D6d "ended" with a balance and no confirm: refused, nothing changed (check-in undone too)', empty($r['json']['success']) && !empty($r['json']['needs_confirm_balance']) && (($r['verify'][0][0]['status'] ?? '') === 'confirmed'), json_encode([$r['json'] ?? null, $r['verify'][0] ?? null]));

echo "== D7: left early, recorded late (booked 4 -> 1 days ago, left 2 days ago): refund, quote == refund ==\n";
$t = lso_tag();
$fx = lso_fixture($t, 'checked-in', $ago(4), $ago(1), 3);
// Paid in full by a payment row; bookings.amount_paid is left stale at 0 so a quote from cached columns would show.
$fx[] = "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, status, recorded_by)
    VALUES ('ZLSP{$t}', 'room', (" . $bid($t) . "), 'ZLS{$t}', '{$ago(4)}', 300, 0, 0, 300, 'cash', 'partial_payment', 'completed', 'completed', NULL)";
$r = lso_run(['post' => ['action' => 'checkout_assess', 'id' => '{b}'], 'setup' => $fx, 'vars' => ['b' => $bid($t)]]);
lso_clean($r, 'D7a');
$qEarly = $r['json']['settlement']['quotes'][$ago(2)]['booked'] ?? null;
ok('D7 assess: leave date 2 days ago = refund of 1 unused night, refundable 100 (fresh paid figure)', ($qEarly['direction'] ?? '') === 'refund' && (int)($qEarly['unused_nights'] ?? 0) === 1 && abs((float)($qEarly['refundable'] ?? 0) - 100) < $tol, json_encode([$qEarly, $r['raw'] ?? null]));
$r = lso_run(['post' => ['action' => 'checkout_settle', 'id' => '{b}', 'settlement_action' => 'leave', 'leave_date' => $ago(2)], 'setup' => $fx, 'vars' => ['b' => $bid($t)],
    'verify' => array_merge(lso_verify($t), ["SELECT SUM(total_amount) AS refunded, MAX(refund_notes) AS notes FROM payments WHERE booking_id = (" . $bid($t) . ") AND payment_type = 'refund'"])]);
lso_clean($r, 'D7b');
$row = $r['verify'][0][0] ?? [];
$rf = $r['verify'][4][0] ?? [];
ok('D7 settle: checked out, check-out = the leave date, 2 nights, total 200', ($row['status'] ?? '') === 'checked-out' && ($row['check_out_date'] ?? '') === $ago(2) && (int)$row['number_of_nights'] === 2 && abs((float)$row['total_with_vat'] - 200) < $tol, json_encode([$row, $r['json'] ?? null, $r['raw'] ?? null]));
ok('D7 refund written = the quoted refundable (100), notes carry the leave date', $qEarly !== null && abs((float)($rf['refunded'] ?? 0) - (float)$qEarly['refundable']) < $tol && strpos((string)($rf['notes'] ?? ''), rh_late_stay_fmt($ago(2))) !== false, json_encode($rf));
$aud = $r['verify'][2] ?? [];
$last = end($aud) ?: [];
$cur = (string)getSetting('currency_symbol', 'MWK');
ok('D7 History: the note shows refundable and refunded as the same figure', strpos((string)($last['note'] ?? ''), 'refundable ' . $cur . ' 100.00') !== false && strpos((string)($last['note'] ?? ''), 'Refunded ' . $cur . ' 100.00') !== false, (string)($last['note'] ?? ''));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
