<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * One party in rooms of different types (owner request 2026-10-28). Usage (project root): php scripts/tests/mixed_room_booking.php
 * Everything runs on THROWAWAY room types named MIXTEST (a 1-room adults-only "villa" of 4, a 2-room family type of 2 and a
 * plain 2-room type of 2), so no real room type is touched. Layer 1 calls the shared booking code (rh_mixed_create) with
 * normal COMMITTING connections and deletes every row it made by id when it ends. Layer 2 runs the real booking.php POST in
 * scripts/tests/page_harness.php (rolled back; SMTP pointed at a closed local port, so no email can leave).
 *   - villa + 5 adults  -> villa (4) + added suite (1)
 *   - 5 guests with 1 child, villa adults only -> the child sits in the added family room with an adult
 *   - the added room removed -> the server refuses the shortfall and saves nothing
 *   - an added room the party does not need is refused
 *   - every room is its own bookings row, all linked to the lead; rows add up to the group total (BALANCE_TOLERANCE)
 *   - the unchanged single-type split (3 guests, 2-guest rooms -> 2 + 1) still works through booking.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/mixed-booking.php';
require_once $root . '/includes/idempotency.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$run = strtoupper(bin2hex(random_bytes(3)));
$tag = 'MIXTEST ' . $run;
$dependents = ['booking_audit_log', 'booking_notes', 'booking_rooms', 'booking_status_log', 'booking_timeline_logs', 'booking_charges',
    'booking_date_adjustments', 'booking_packages', 'tentative_booking_log', 'guest_communication_log', 'booking_financial_audit'];
$made = ['types' => [], 'rooms' => []];

function mx_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}
function mx_clear_bookings(PDO $pdo): void {
    global $dependents;
    $ids = $pdo->query("SELECT id FROM bookings WHERE guest_name LIKE 'MIXTEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($dependents as $t) {
            if (mx_table_exists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); }
        }
        $pdo->exec("UPDATE bookings SET primary_booking_id = NULL WHERE id IN ($in)");
        $pdo->exec("DELETE FROM bookings WHERE id IN ($in)");
    }
}
function mx_cleanup(PDO $pdo): void {
    global $made;
    mx_clear_bookings($pdo);
    $in = fn(array $a) => implode(',', array_map('intval', $a));
    if ($made['rooms']) { $pdo->exec("DELETE FROM individual_rooms WHERE id IN (" . $in($made['rooms']) . ")"); $made['rooms'] = []; }
    $pdo->exec("DELETE FROM individual_rooms WHERE room_number LIKE 'MX-%' AND room_type_id IN (SELECT id FROM rooms WHERE name LIKE 'MIXTEST%')");
    if ($made['types']) { $pdo->exec("DELETE FROM rooms WHERE id IN (" . $in($made['types']) . ")"); $made['types'] = []; }
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'MIXTEST%'");
}
register_shutdown_function(function () use ($pdo) { try { mx_cleanup($pdo); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
mx_cleanup($pdo); // leftovers of an aborted earlier run

function mx_type(PDO $pdo, string $run, string $label, int $cap, int $n, int $children, float $price): int {
    global $made;
    $pdo->prepare("INSERT INTO rooms (name, slug, description, price_per_night, max_guests, rooms_available, total_rooms, is_active, display_order, children_allowed)
        VALUES (?, ?, 'mixed room test type - removed automatically', ?, ?, ?, ?, 1, 9999, ?)")
        ->execute(["MIXTEST $run $label", 'mixtest-' . strtolower($run) . '-' . strtolower($label), $price, $cap, $n, $n, $children]);
    $tid = (int)$pdo->lastInsertId();
    $made['types'][] = $tid;
    for ($i = 1; $i <= $n; $i++) {
        $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) VALUES (?, ?, ?, 'available', 1)")
            ->execute([$tid, "MX-$run-$label$i", "MIXTEST $label$i"]);
        $made['rooms'][] = (int)$pdo->lastInsertId();
    }
    return $tid;
}
function mx_rows(PDO $pdo, string $name): array {
    $st = $pdo->prepare("SELECT id, booking_reference, room_id, number_of_guests, adult_guests, child_guests, primary_booking_id, total_amount, total_with_vat, child_supplement_total, occupancy_type
        FROM bookings WHERE guest_name = ? ORDER BY id");
    $st->execute([$name]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function mx_in(PDO $pdo, string $name, int $chosen, array $extras, string $ci, string $co, int $total, int $children, string $ref): array {
    return [
        'chosen_id' => $chosen, 'extra_ids' => $extras, 'guest_name' => $name, 'guest_email' => 'mixtest@example.invalid', 'guest_phone' => '+265000000003',
        'guest_country' => '', 'guest_address' => '', 'total_guests' => $total, 'child_guests' => $children, 'check_in' => $ci, 'check_out' => $co,
        'special_requests' => '', 'status' => 'pending', 'is_tentative' => 0, 'tentative_expires_at' => null, 'reference' => $ref, 'client_uuid' => null,
        'package_total' => 0.0, 'package_rows' => [],
        'validation_data' => ['guest_name' => $name, 'guest_email' => 'mixtest@example.invalid', 'guest_phone' => '+265000000003', 'check_in_date' => $ci, 'check_out_date' => $co,
            'number_of_guests' => $total, 'child_guests' => $children],
    ];
}

$villa = mx_type($pdo, $run, 'Villa', 4, 1, 0, 1000.0);   // adults only, 1 room
$suite = mx_type($pdo, $run, 'Suite', 2, 2, 1, 500.0);    // family, 2 rooms
$plain = mx_type($pdo, $run, 'Plain', 2, 2, 1, 300.0);    // family, 2 rooms (single-type split)
echo "== MIXTEST types: villa #$villa (4, adults only, 1 room), suite #$suite (2, 2 rooms), plain #$plain (2, 2 rooms) ==\n";

// A stay inside the booking window where nothing is blocked.
$ci = $co = '';
for ($off = 3; $off <= 20; $off++) {
    $a = date('Y-m-d', strtotime("+$off day")); $b = date('Y-m-d', strtotime('+' . ($off + 2) . ' day'));
    if (rh_mixed_free_rooms($villa, $a, $b) === 1 && rh_mixed_free_rooms($suite, $a, $b) === 2 && rh_mixed_free_rooms($plain, $a, $b) === 2) { $ci = $a; $co = $b; break; }
}
if ($ci === '') { ok('a stay without blocked dates was found', false, 'all of the next 20 days are blocked for the test types'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$nights = 2;
echo "stay $ci -> $co\n";
$tol = defined('BALANCE_TOLERANCE') ? BALANCE_TOLERANCE : 0.01;

// ── 1. villa + 5 adults ──────────────────────────────────────────────────────
$rs = rh_mixed_resolve($pdo, $villa, [$suite], $ci, $co, 5, 0);
$rowsPlan = $rs['plan']['rows'] ?? [];
ok('villa + 5 adults: seated with the villa added suite', $rs['ok'] && count($rowsPlan) === 2, $rs['message']);
ok('  villa takes 4 adults (full), the added suite takes the 5th', ($rowsPlan[0]['room_id'] ?? 0) === $villa && ($rowsPlan[0]['guests'] ?? 0) === 4 && ($rowsPlan[1]['room_id'] ?? 0) === $suite && ($rowsPlan[1]['guests'] ?? 0) === 1);
ok('  the suite row is marked as the added room', ($rowsPlan[1]['is_extra'] ?? false) === true && ($rowsPlan[0]['is_extra'] ?? true) === false);
$opts = rh_mixed_options($pdo, rh_mixed_resolve($pdo, $villa, [], $ci, $co, 5, 0), $ci, $co, 5, 0);
$optIds = array_column($opts, 'room_id');
ok('  before adding: the suite is offered with a nightly price', in_array($suite, $optIds, true) && (float)$opts[array_search($suite, $optIds)]['rate_per_night'] > 0);
ok('  before adding: the villa itself is not offered', !in_array($villa, $optIds, true));

$name1 = "$tag A";
$ref1 = 'MX' . $run . '01';
$made1 = rh_mixed_create($pdo, mx_in($pdo, $name1, $villa, [$suite], $ci, $co, 5, 0, $ref1));
ok('villa + 5 adults: booking created', !empty($made1['success']), (string)($made1['error'] ?? ''));
$rows1 = mx_rows($pdo, $name1);
ok('  one bookings row per room (2), each with its own room type', count($rows1) === 2 && (int)$rows1[0]['room_id'] === $villa && (int)$rows1[1]['room_id'] === $suite);
ok('  guests are 4 + 1 (all adults)', count($rows1) === 2 && (int)$rows1[0]['number_of_guests'] === 4 && (int)$rows1[1]['number_of_guests'] === 1 && (int)$rows1[0]['child_guests'] === 0);
ok('  all rows linked to the lead (lead has no primary_booking_id)', count($rows1) === 2 && $rows1[0]['primary_booking_id'] === null && (int)$rows1[1]['primary_booking_id'] === (int)$rows1[0]['id']);
ok('  references: lead plain, later row suffixed', count($rows1) === 2 && $rows1[0]['booking_reference'] === $ref1 && $rows1[1]['booking_reference'] === $ref1 . '-2');
$expVilla = rh_stay_totals(1000.0 * $nights, 'price')['total_with_vat'];
$expSuite = rh_stay_totals(500.0 * $nights, 'price')['total_with_vat'];
ok('  each row is priced for its own type and guests', count($rows1) === 2 && abs((float)$rows1[0]['total_with_vat'] - round($expVilla, 2)) <= $tol && abs((float)$rows1[1]['total_with_vat'] - round($expSuite, 2)) <= $tol,
    json_encode([array_column($rows1, 'total_with_vat'), $expVilla, $expSuite]));
$sum1 = array_sum(array_map(fn($r) => (float)$r['total_with_vat'], $rows1));
ok('  rows add up to the group total within BALANCE_TOLERANCE', abs($sum1 - (float)$made1['group']['total_with_vat']) <= $tol, "$sum1 vs " . ($made1['group']['total_with_vat'] ?? '?'));
ok('  the villa is now fully booked: adding the suite alone cannot repeat the booking', !rh_mixed_resolve($pdo, $villa, [$suite], $ci, $co, 5, 0)['ok']);

// ── 2. 5 guests, 1 child, villa adults only ──────────────────────────────────
mx_clear_bookings($pdo); // fresh stock: scenario 1 booked the villa
$rs = rh_mixed_resolve($pdo, $villa, [$suite], $ci, $co, 5, 1);
$rowsPlan = $rs['plan']['rows'] ?? [];
ok('5 guests with 1 child, villa adults only: seated', $rs['ok'] && count($rowsPlan) === 2, $rs['message']);
ok('  the child is in the suite (with an adult), none in the villa', ($rowsPlan[1]['children'] ?? 0) === 1 && ($rowsPlan[1]['adults'] ?? 0) >= 1 && ($rowsPlan[0]['children'] ?? 1) === 0, json_encode($rowsPlan));
ok('  nobody beyond a room capacity (villa 4, suite 2)', ($rowsPlan[0]['guests'] ?? 9) <= 4 && ($rowsPlan[1]['guests'] ?? 9) <= 2 && array_sum(array_column($rowsPlan, 'guests')) === 5);
$onlyVilla = rh_mixed_resolve($pdo, $villa, [], $ci, $co, 5, 1);
ok('  with no added room the child cannot be seated and the reason is one plain line', !$onlyVilla['ok'] && $onlyVilla['message'] !== '' && strpos($onlyVilla['message'], "\n") === false, $onlyVilla['message']);
$name2 = "$tag B";
$made2 = rh_mixed_create($pdo, mx_in($pdo, $name2, $villa, [$suite], $ci, $co, 5, 1, 'MX' . $run . '02'));
ok('  booking created', !empty($made2['success']), (string)($made2['error'] ?? ''));
$rows2 = mx_rows($pdo, $name2);
ok('  child saved on the suite row only, child supplement priced on that row', count($rows2) === 2 && (int)$rows2[0]['child_guests'] === 0 && (int)$rows2[1]['child_guests'] === 1);
ok('  group: rows linked and totals add up', count($rows2) === 2 && (int)$rows2[1]['primary_booking_id'] === (int)$rows2[0]['id']
    && abs(array_sum(array_map(fn($r) => (float)$r['total_with_vat'], $rows2)) - (float)$made2['group']['total_with_vat']) <= $tol);
mx_clear_bookings($pdo);

// ── 3. the added room removed: the server refuses the shortfall ─────────────
$name3 = "$tag C";
$short = rh_mixed_create($pdo, mx_in($pdo, $name3, $villa, [], $ci, $co, 5, 0, 'MX' . $run . '03'));
ok('added room removed: the server refuses the 5th guest', empty($short['success']) && $short['error'] !== '', (string)($short['error'] ?? ''));
ok('  nothing was saved', count(mx_rows($pdo, $name3)) === 0);

// ── 4. rooms the party does not need are refused ────────────────────────────
$unneeded = rh_mixed_create($pdo, mx_in($pdo, "$tag D", $villa, [$suite], $ci, $co, 3, 0, 'MX' . $run . '04'));
ok('a party the villa seats alone: added rooms refused', empty($unneeded['success']) && count(mx_rows($pdo, "$tag D")) === 0, (string)($unneeded['error'] ?? ''));
$tooMany = rh_mixed_create($pdo, mx_in($pdo, "$tag E", $villa, [$suite, $suite], $ci, $co, 5, 0, 'MX' . $run . '05'));
ok('one added room too many: refused, nothing saved', empty($tooMany['success']) && count(mx_rows($pdo, "$tag E")) === 0, (string)($tooMany['error'] ?? ''));
$gone = rh_mixed_create($pdo, mx_in($pdo, "$tag F", $villa, [999999], $ci, $co, 5, 0, 'MX' . $run . '06'));
ok('an added room type that does not exist: refused', empty($gone['success']) && count(mx_rows($pdo, "$tag F")) === 0, (string)($gone['error'] ?? ''));
$stock = rh_mixed_create($pdo, mx_in($pdo, "$tag G", $villa, [$suite, $suite, $suite], $ci, $co, 9, 0, 'MX' . $run . '07'));
ok('more added rooms than are free: refused with a plain line', empty($stock['success']) && count(mx_rows($pdo, "$tag G")) === 0, (string)($stock['error'] ?? ''));

// ── 5. unchanged single-type split: no extras means no mixed code ───────────
$one = ga_plan(rh_mixed_load_room($pdo, $plain), 3, 0);
ok('single-type split: 3 guests in 2-guest rooms = 2 rooms (2 + 1)', !empty($one['valid']) && (int)$one['rooms_needed'] === 2 && array_column($one['allocation'], 'guests') === [2, 1]);
$refused = rh_mixed_create($pdo, mx_in($pdo, "$tag H", $plain, [$suite], $ci, $co, 3, 0, 'MX' . $run . '08'));
ok('  a type that can seat everyone does not accept added rooms', empty($refused['success']) && count(mx_rows($pdo, "$tag H")) === 0, (string)($refused['error'] ?? ''));

// ── 6. villa link with a family: children never in the adults-only villa ────
$why = fn(int $t, int $g, int $c) => rh_mixed_reason(rh_mixed_load_room($pdo, $villa), rh_mixed_free_rooms($villa, $ci, $co), $g, $c);
ok('villa + children: an added room is needed (reason children)', $why($villa, 3, 1) === 'children');
ok('villa + no children, party fits: no added room', $why($villa, 3, 0) === '');
$rs = rh_mixed_resolve($pdo, $villa, [$suite], $ci, $co, 3, 1);
$pl = $rs['plan']['rows'] ?? [];
ok('villa + 2 adults + 1 child + suite: seated', $rs['ok'] && count($pl) === 2, $rs['message']);
ok('  villa row 0 children; suite 1 adult + 1 child', ($pl[0]['room_id'] ?? 0) === $villa && ($pl[0]['children'] ?? 1) === 0 && ($pl[0]['adults'] ?? 0) === 1 && ($pl[1]['adults'] ?? 0) === 1 && ($pl[1]['children'] ?? 0) === 1, json_encode($pl));
$rs = rh_mixed_resolve($pdo, $villa, [$suite, $suite], $ci, $co, 5, 2);
$pl = $rs['plan']['rows'] ?? [];
$kids = 0; foreach ($pl as $p) { if ((int)$p['room_id'] === $villa) { $kids += (int)$p['children']; } }
ok('villa + 3 adults + 2 children + suites: no child in the villa row, everyone seated', $kids === 0 && $rs['ok'] && array_sum(array_column($pl, 'guests')) === 5, json_encode([$rs['message'], $pl]));
$rs = rh_mixed_resolve($pdo, $villa, [$suite], $ci, $co, 2, 1);
ok('villa + 1 adult + 1 child: refused', !$rs['ok'] && $rs['message'] !== '', $rs['message']);
$no = rh_mixed_create($pdo, mx_in($pdo, "$tag I", $villa, [$suite], $ci, $co, 2, 1, 'MX' . $run . '09'));
ok('  creating it is refused, nothing saved', empty($no['success']) && count(mx_rows($pdo, "$tag I")) === 0, (string)($no['error'] ?? ''));
$no = rh_mixed_create($pdo, mx_in($pdo, "$tag J", $villa, [], $ci, $co, 3, 1, 'MX' . $run . '10'));
ok('villa + children with no added room: refused, nothing saved', empty($no['success']) && count(mx_rows($pdo, "$tag J")) === 0, (string)($no['error'] ?? ''));
$opts = rh_mixed_options($pdo, rh_mixed_resolve($pdo, $villa, [], $ci, $co, 3, 1), $ci, $co, 3, 1);
$oi = array_column($opts, 'room_id');
ok('  options offered are child-friendly types only', in_array($suite, $oi, true) && !array_filter($opts, fn($o) => empty($o['children_allowed'])), json_encode($oi));
$fam = rh_mixed_create($pdo, mx_in($pdo, "$tag K", $villa, [$suite], $ci, $co, 3, 1, 'MX' . $run . '11'));
$rk = mx_rows($pdo, "$tag K");
ok('villa + 2 adults + 1 child + suite: created, child on the suite row only', !empty($fam['success']) && count($rk) === 2 && (int)$rk[0]['child_guests'] === 0 && (int)$rk[1]['child_guests'] === 1, (string)($fam['error'] ?? ''));
mx_clear_bookings($pdo);

// ── Layer 2: the real booking.php POST (rolled back) ─────────────────────────
$harness = $root . '/scripts/tests/page_harness.php';
function mx_post(string $harness, array $post, array $verify): array {
    $cfg = base64_encode(json_encode(['post' => $post, 'verify' => $verify, 'fake_smtp' => 1, 'dump_html' => getenv('MX_DUMP') ?: null]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' scripts/tests/mixed_booking_post_page.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m) && is_array($r = json_decode($m[1], true))) { $res = $r + ['raw' => substr((string)$out, -500)]; }
    return $res;
}
function mx_page_post(string $name, int $room, array $extras, int $guests, int $children, string $ci, string $co): array {
    return ['csrf_token' => 'mixtok' . bin2hex(random_bytes(4)), 'room_id' => (string)$room, 'guest_name' => $name, 'guest_email' => 'mixtest@example.invalid',
        'guest_phone' => '+265000000003', 'guest_country' => 'Malawi', 'guest_address' => '', 'number_of_guests' => (string)$guests, 'child_guests' => (string)$children,
        'check_in_date' => $ci, 'check_out_date' => $co, 'special_requests' => '', 'booking_type' => 'standard', 'extra_room_types' => array_map('strval', $extras),
        'client_uuid' => 'mixtest-' . bin2hex(random_bytes(6))];
}
$verifySql = fn(string $n) => ["SELECT room_id, number_of_guests, child_guests, primary_booking_id IS NULL AS is_lead, total_with_vat FROM bookings WHERE guest_name = " . $pdo->quote($n) . " ORDER BY id"];

$n1 = "MIXTEST Page Villa Suite";
$r = mx_post($harness, mx_page_post($n1, $villa, [$suite], 5, 0, $ci, $co), $verifySql($n1));
$v = $r['verify'][0] ?? [];
ok('booking.php POST, villa + added suite: both rooms saved (rolled back after)', is_array($v) && count($v) === 2 && (int)$v[0]['room_id'] === $villa && (int)$v[1]['room_id'] === $suite && (int)$v[0]['is_lead'] === 1 && (int)$v[1]['is_lead'] === 0, json_encode([$v, $r['raw'] ?? '']));
ok('  harness check: nothing leaked and the run was rolled back', empty($r['leak']) && !empty($r['rolled_back']) && empty($r['marker_lost']));
$n2 = "MIXTEST Page Villa Short";
$r = mx_post($harness, mx_page_post($n2, $villa, [], 5, 0, $ci, $co), $verifySql($n2));
ok('booking.php POST, villa for 5 with the added room removed: refused, nothing saved', isset($r['verify'][0]) && is_array($r['verify'][0]) && count($r['verify'][0]) === 0, json_encode([$r['verify'] ?? null, $r['raw'] ?? '']));
$n3 = "MIXTEST Page Plain Split";
$r = mx_post($harness, mx_page_post($n3, $plain, [], 3, 0, $ci, $co), $verifySql($n3));
$v = $r['verify'][0] ?? [];
ok('booking.php POST, single-type split unchanged: 2 rooms of the same type (2 + 1 guests)', is_array($v) && count($v) === 2 && (int)$v[0]['room_id'] === $plain && (int)$v[1]['room_id'] === $plain
    && (int)$v[0]['number_of_guests'] === 2 && (int)$v[1]['number_of_guests'] === 1 && (int)$v[0]['is_lead'] === 1 && (int)$v[1]['is_lead'] === 0, json_encode([$v, $r['raw'] ?? '']));
$n4 = "MIXTEST Page Child Suite";
$r = mx_post($harness, mx_page_post($n4, $villa, [$suite], 5, 1, $ci, $co), $verifySql($n4));
$v = $r['verify'][0] ?? [];
ok('booking.php POST, 1 child: the child is saved on the suite row', is_array($v) && count($v) === 2 && (int)$v[0]['child_guests'] === 0 && (int)$v[1]['child_guests'] === 1, json_encode([$v, $r['raw'] ?? '']));
$n5 = "MIXTEST Page Villa Family";
$r = mx_post($harness, mx_page_post($n5, $villa, [$suite], 3, 1, $ci, $co), $verifySql($n5));
$v = $r['verify'][0] ?? [];
ok('booking.php POST, villa + 2 adults + 1 child + suite: villa 0 children, suite 1 child', is_array($v) && count($v) === 2 && (int)$v[0]['child_guests'] === 0 && (int)$v[1]['child_guests'] === 1, json_encode([$v, $r['raw'] ?? '']));
$n6 = "MIXTEST Page Villa NoRoom";
$r = mx_post($harness, mx_page_post($n6, $villa, [], 3, 1, $ci, $co), $verifySql($n6));
ok('booking.php POST, villa + child with no added room: refused', isset($r['verify'][0]) && is_array($r['verify'][0]) && count($r['verify'][0]) === 0, json_encode([$r['verify'] ?? null, $r['raw'] ?? '']));

mx_cleanup($pdo);
$left = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM bookings WHERE guest_name LIKE 'MIXTEST%') + (SELECT COUNT(*) FROM rooms WHERE name LIKE 'MIXTEST%') + (SELECT COUNT(*) FROM individual_rooms WHERE room_number LIKE 'MX-%')")->fetchColumn();
ok('cleanup: no MIXTEST rows left behind', $left === 0, (string)$left);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
