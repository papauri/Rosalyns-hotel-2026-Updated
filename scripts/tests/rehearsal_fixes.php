<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Dress-rehearsal fixes (Liwonde, 2026-10-20), server side. Usage (project root): php scripts/tests/rehearsal_fixes.php
 *   F3  getCCEmails() reads email_settings.setting_key/setting_value and never throws;
 *       Adjust Stay Dates completes and writes its timeline entry.
 *   F2  early checkout: checkout_assess nights add up (booked - billed = unused), and checkout_settle with
 *       refund and with skip both complete the checkout.
 * Every scenario runs in scripts/tests/page_harness.php (outer transaction that never commits). Guests have no
 * email address, so nothing is emailed. The JS fixes (F1, F2 handler) are checked in the headless browser.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function rf_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'method' => $opts['method'] ?? 'POST', 'dump_html' => $opts['dump_html'] ?? '', 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [], 'grep' => $opts['grep'] ?? []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    return $res;
}
function rf_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
$rid = 0;
function rf_id(): int { global $rid; return random_int(2000000000, 2100000000) + (++$rid); }
/** A booking priced 100 a night for $nights nights, check-in $inDays days from today (negative = past). */
function rf_booking_sql(int $id, string $ref, string $status, int $inDays, int $nights): string {
    $in = date('Y-m-d', strtotime(($inDays >= 0 ? '+' : '') . $inDays . ' day'));
    $out = date('Y-m-d', strtotime($in . ' +' . $nights . ' day'));
    $total = 100 * $nights;
    return "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES ($id, " . rf_q($ref) . ", (SELECT id FROM rooms ORDER BY id LIMIT 1), 'REHEARSAL TEST', '', '+265000000009', 'Malawi', 2, 2, 0, 50, " . rf_q($in) . ", " . rf_q($out) . ", $nights, $total, 0, 0, 0, 0, 0, $total, 'rehearsal test', " . rf_q($status) . ", 'unpaid', 0, 'double', " . rf_q(bin2hex(random_bytes(16))) . ", NOW())";
}
function rf_paid_sql(int $id, int $bookingId, string $ref, float $amount): string {
    return "INSERT INTO payments (id, payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES ($id, " . rf_q('RFP' . $id) . ", 'room', $bookingId, " . rf_q($ref) . ", CURDATE(), $amount, 0, 0, $amount, 'cash', 'partial_payment', 'completed', 'completed', " . rf_q('RRF' . $id) . ", NOW())";
}

echo "== F3 getCCEmails ==\n";
$r = rf_run('scripts/tests/rehearsal_fixes_child.php', ['scenario' => 'cc']);
$j = $r['json'] ?? [];
ok('getCCEmails: no setting -> []', ($j['none'] ?? 'x') === [], json_encode($j ?: $r));
ok('getCCEmails: JSON list from setting_value, bad addresses dropped', ($j['json'] ?? null) === ['a@example.com', 'b@example.com'], json_encode($j));
ok('getCCEmails: comma list accepted', ($j['csv'] ?? null) === ['c@example.com', 'd@example.com'], json_encode($j));
ok('getCCEmails: never throws when the lookup fails', ($j['broken'] ?? 'x') === [], json_encode($j));

echo "== F3 Adjust Stay Dates ==\n";
$bid = rf_id(); $ref = 'RFA' . $bid % 100000;
$in = date('Y-m-d', strtotime('+300 day')); $newOut = date('Y-m-d', strtotime('+302 day'));
$r = rf_run('admin/booking-details.php', ['adjust_dates' => '1', 'new_check_in' => $in, 'new_check_out' => $newOut, 'adjustment_reason' => 'rehearsal test'], [
    'ajax' => true, 'get' => ['id' => $bid],
    'setup' => [rf_booking_sql($bid, $ref, 'confirmed', 300, 1)],
    'verify' => ["SELECT number_of_nights AS n FROM bookings WHERE id = $bid", "SELECT COUNT(*) AS n FROM booking_timeline_logs WHERE booking_id = $bid AND action_type = 'date_adjustment'"],
]);
ok('adjust dates: answers JSON success (no HTTP 500)', !empty($r['json']['success']), json_encode([$r['json'] ?? $r['raw'] ?? '', $r['error'] ?? '']));
ok('adjust dates: stay is now 2 nights and the timeline entry exists', ($r['verify'][0][0]['n'] ?? 0) == 2 && ($r['verify'][1][0]['n'] ?? 0) >= 1, json_encode($r['verify'] ?? $r));

// With a guest e-mail on file the page also builds the notification (which reads the CC list): it must still answer JSON.
// The SMTP host is blanked inside the rolled-back transaction so nothing is sent.
$bid = rf_id(); $ref = 'RFB' . $bid % 100000;
$r = rf_run('admin/booking-details.php', ['adjust_dates' => '1', 'new_check_in' => $in, 'new_check_out' => $newOut, 'adjustment_reason' => 'rehearsal test'], [
    'ajax' => true, 'get' => ['id' => $bid],
    'setup' => [rf_booking_sql($bid, $ref, 'confirmed', 300, 1), "UPDATE bookings SET guest_email = 'rehearsal@example.invalid' WHERE id = $bid", "UPDATE email_settings SET setting_value = '' WHERE setting_key = 'smtp_host'"],
    'verify' => ["SELECT number_of_nights AS n FROM bookings WHERE id = $bid"],
]);
ok('adjust dates with a guest e-mail: still answers JSON success (no HTTP 500)', !empty($r['json']['success']) && ($r['verify'][0][0]['n'] ?? 0) == 2, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));

echo "== F2/F6 early checkout ==\n";
// Same-day case: checked in today, booked 2 nights. The stay bills 1 night, so unused = 1 (not 2).
$bid = rf_id(); $ref = 'RFC' . $bid % 100000; $pid = rf_id();
$setup = [rf_booking_sql($bid, $ref, 'checked-in', 0, 2), rf_paid_sql($pid, $bid, $ref, 200.0), "UPDATE bookings SET amount_paid = 200, amount_due = 0, payment_status = 'paid' WHERE id = $bid"];
$r = rf_run('admin/bookings.php', ['action' => 'checkout_assess', 'id' => $bid], ['ajax' => true, 'setup' => $setup]);
$s = $r['json']['settlement'] ?? [];
ok('assess: early refund settlement offered', ($s['type'] ?? '') === 'refund', json_encode($r['json'] ?? $r['raw'] ?? ''));
ok('assess: nights add up (booked 2, billed 1, unused 1)', ($s['sched_nights'] ?? 0) == 2 && ($s['actual_nights'] ?? 0) == 1 && ($s['unused_nights'] ?? 0) == 1, json_encode($s));
ok('assess: paid amount is reported (for the "Paid X; no refund due" wording)', abs((float)($s['amount_paid'] ?? 0) - 200) < 0.01, json_encode($s));

// Checked in yesterday, booked 3 nights, paid in full: early checkout today refunds the unused night(s)
$bid = rf_id(); $ref = 'RFD' . $bid % 100000; $pid = rf_id();
$setup = [rf_booking_sql($bid, $ref, 'checked-in', -1, 3), rf_paid_sql($pid, $bid, $ref, 300.0), "UPDATE bookings SET amount_paid = 300, amount_due = 0, payment_status = 'paid' WHERE id = $bid"];
$r = rf_run('admin/bookings.php', ['action' => 'checkout_settle', 'id' => $bid, 'settlement_action' => 'refund', 'payment_method' => 'cash', 'notes' => 'rehearsal'], [
    'ajax' => true, 'setup' => $setup,
    'verify' => ["SELECT status FROM bookings WHERE id = $bid", "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt FROM payments WHERE booking_type='room' AND booking_id = $bid AND payment_type = 'refund'"],
]);
ok('checkout with refund: completes and the booking is checked out', !empty($r['json']['success']) && ($r['verify'][0][0]['status'] ?? '') === 'checked-out', json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
ok('checkout with refund: a refund of the unused night is recorded', ($r['verify'][1][0]['n'] ?? 0) >= 1 && ($r['verify'][1][0]['amt'] ?? 0) > 0, json_encode($r['verify'] ?? $r));

$bid = rf_id(); $ref = 'RFE' . $bid % 100000; $pid = rf_id();
$setup = [rf_booking_sql($bid, $ref, 'checked-in', -1, 3), rf_paid_sql($pid, $bid, $ref, 300.0), "UPDATE bookings SET amount_paid = 300, amount_due = 0, payment_status = 'paid' WHERE id = $bid"];
$r = rf_run('admin/bookings.php', ['action' => 'checkout_settle', 'id' => $bid, 'settlement_action' => 'skip', 'payment_method' => 'cash', 'notes' => ''], [
    'ajax' => true, 'setup' => $setup,
    'verify' => ["SELECT status FROM bookings WHERE id = $bid", "SELECT COUNT(*) AS n FROM payments WHERE booking_type='room' AND booking_id = $bid AND payment_type = 'refund'"],
]);
ok('checkout with settlement skipped: completes, no refund written', !empty($r['json']['success']) && ($r['verify'][0][0]['status'] ?? '') === 'checked-out' && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));

echo "== Rehearsal re-check: room picker, room release, timeline wording ==\n";
// Read-only look-up of one plain (non-joined) physical room to hang the test bookings on.
require_once $root . '/config/database.php';
$pick = $pdo->query("SELECT ir.id, ir.room_type_id FROM individual_rooms ir WHERE ir.is_active = 1
    AND ir.room_type_id NOT IN (SELECT combined_room_type_id FROM room_combinations WHERE is_active = 1)
    AND ir.id NOT IN (SELECT room_a_id FROM room_combinations UNION SELECT room_b_id FROM room_combinations) ORDER BY ir.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$pick) {
    ok('room picker tests: a plain physical room exists to test with', false, 'no individual room found');
} else {
    $irid = (int)$pick['id']; $rtid = (int)$pick['room_type_id'];
    $pickCi = date('Y-m-d', strtotime('+400 day')); $pickCo = date('Y-m-d', strtotime('+402 day'));
    $pickRoomState = function (string $status) use ($irid, $rtid, $pickCi, $pickCo): ?array {
        $bid = rf_id(); $ref = 'RFP' . $bid % 100000;
        $r = rf_run('admin/create-booking.php', ['action' => 'get_available_individual_rooms', 'room_type_id' => $rtid, 'check_in' => $pickCi, 'check_out' => $pickCo], [
            'ajax' => true,
            'setup' => [rf_booking_sql($bid, $ref, $status, 400, 2), "UPDATE bookings SET room_id = $rtid, individual_room_id = $irid WHERE id = $bid"],
        ]);
        foreach (($r['json']['data'] ?? []) as $row) { if ((int)$row['id'] === $irid) { return $row; } }
        return null;
    };
    $row = $pickRoomState('checked-out');
    ok('room picker: a checked-out booking on those dates does not make the room "Booked"', $row !== null && $row['conflict'] === false, json_encode($row));
    $row = $pickRoomState('expired');
    ok('room picker: an expired booking does not make the room "Booked"', $row !== null && $row['conflict'] === false, json_encode($row));
    $row = $pickRoomState('confirmed');
    ok('room picker: a confirmed booking on those dates still shows the room as taken', $row !== null && $row['conflict'] === true, json_encode($row));

    // Checkout releases the room hold (booking_rooms.released_at) on both checkout paths.
    foreach (['checkout' => ['action' => 'checkout'], 'checkout_settle' => ['action' => 'checkout_settle', 'settlement_action' => 'skip', 'payment_method' => 'cash', 'notes' => '']] as $label => $post) {
        $bid = rf_id(); $ref = 'RFR' . $bid % 100000; $pid = rf_id();
        $r = rf_run('admin/bookings.php', $post + ['id' => $bid], [
            'ajax' => true,
            'setup' => [rf_booking_sql($bid, $ref, 'checked-in', -1, 1), rf_paid_sql($pid, $bid, $ref, 100.0),
                "UPDATE bookings SET amount_paid = 100, amount_due = 0, payment_status = 'paid', room_id = $rtid, individual_room_id = $irid WHERE id = $bid",
                "INSERT INTO booking_rooms (booking_id, individual_room_id, is_primary, assigned_at, status_snapshot) VALUES ($bid, $irid, 1, NOW(), 'assigned')"],
            'verify' => ["SELECT status FROM bookings WHERE id = $bid",
                "SELECT COUNT(*) AS n FROM booking_rooms WHERE booking_id = $bid AND released_at IS NULL",
                "SELECT COUNT(*) AS n FROM booking_rooms WHERE booking_id = $bid AND released_at IS NOT NULL"],
        ]);
        ok("checkout ($label): the booking is checked out and answers JSON success", !empty($r['json']['success']) && ($r['verify'][0][0]['status'] ?? '') === 'checked-out', json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
        ok("checkout ($label): booking_rooms.released_at is set (no live hold left)", ($r['verify'][1][0]['n'] ?? 1) == 0 && ($r['verify'][2][0]['n'] ?? 0) == 1, json_encode($r['verify'] ?? $r));
    }
}

// A real Adjust Dates writes a description in the hotel currency (no "$") and stores the nights.
$bid = rf_id(); $ref = 'RFT' . $bid % 100000;
$in = date('Y-m-d', strtotime('+300 day')); $newOut = date('Y-m-d', strtotime('+303 day'));
$r = rf_run('admin/booking-details.php', ['adjust_dates' => '1', 'new_check_in' => $in, 'new_check_out' => $newOut, 'adjustment_reason' => 'rehearsal timeline'], [
    'ajax' => true, 'get' => ['id' => $bid],
    'setup' => [rf_booking_sql($bid, $ref, 'confirmed', 300, 1)],
    'verify' => ["SELECT description, old_value, new_value FROM booking_timeline_logs WHERE booking_id = $bid AND action_type = 'date_adjustment' ORDER BY id DESC LIMIT 1"],
]);
$tl = $r['verify'][0][0] ?? [];
ok('adjust dates: the timeline description uses the hotel currency, not "$"', !empty($tl['description']) && strpos($tl['description'], '$') === false, json_encode($tl));
ok('adjust dates: old / new nights are stored in old_value / new_value', strpos((string)($tl['old_value'] ?? ''), '"nights":1') !== false && strpos((string)($tl['new_value'] ?? ''), '"nights":3') !== false, json_encode($tl));

// The booking page renders those nights ("1 -> 3"), not "? -> ?".
$bid = rf_id(); $ref = 'RFU' . $bid % 100000;
$dump = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rf_timeline_' . $bid . '.html';
$r = rf_run('admin/booking-details.php', ['noop' => '1'], [
    'method' => 'GET', 'get' => ['id' => $bid], 'dump_html' => $dump,
    'setup' => [rf_booking_sql($bid, $ref, 'confirmed', 300, 1),
        "INSERT INTO booking_timeline_logs (booking_id, booking_reference, action, action_type, description, old_value, new_value, performed_by_type, metadata)
         VALUES ($bid, " . rf_q($ref) . ", 'Stay dates adjusted', 'date_adjustment', 'x', " . rf_q('{"old":{"nights":1}}') . ", " . rf_q('{"new":{"nights":3}}') . ", 'admin', " . rf_q('{"amount_delta":200,"reason":"t"}') . ")"],
]);
$html = is_file($dump) ? (string)file_get_contents($dump) : '';
@unlink($dump);
$flat = trim((string)preg_replace('/\s+/', ' ', strip_tags($html)));
ok('booking page timeline: shows "Nights 1 -> 3"', preg_match('/Nights 1 -> 3/', $flat) === 1 && strpos($flat, 'Nights ? ->') === false, substr($flat, 0, 120));
ok('booking page timeline: the amount line says it is the room charge change', strpos($flat, 'Room charge change') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
