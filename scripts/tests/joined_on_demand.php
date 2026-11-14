<?php
/**
 * Step 8e: joined rooms on demand (.claude/JOINED_ON_DEMAND_SPEC.md §H + Fable §9).
 * Usage (LIWONDE ONLY, refuses anywhere else - Rosalyn's is live):  php scripts/tests/joined_on_demand.php
 *
 * Builds its own made-up fixture (a member room type with 3 rooms, a joined type, one Shared pair, a legacy type)
 * far in the future, COMMITS it because the standalone pair assign runs its own transaction (locks + stock check),
 * and deletes every row it made in a finally block (by id). The page checks run through the rolled-back harness.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$root = dirname(__DIR__, 2);
if (stripos((string)realpath($root), 'Liwonde') === false) {
    echo "[FAIL] refused: this is not the Liwonde test hotel (Rosalyn's is live)\n";
    exit(1);
}
chdir($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $root . '/config/database.php';
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/booking-soft-delete.php';
require_once $root . '/includes/booking-room-change.php';
require_once $root . '/includes/mixed-booking.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "[PASS] $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function col(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$run = strtoupper(bin2hex(random_bytes(3)));
$made = ['rooms' => [], 'individual_rooms' => [], 'room_combinations' => [], 'bookings' => []];
$day = static fn(int $n): string => date('Y-m-d', strtotime('+' . $n . ' day'));
$D = 600; // far future, clear of real bookings

function mk_type(PDO $pdo, string $name, float $price, int $max, int $total): int {
    global $made, $run;
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', ?, ?, ?, ?, 1, ?)")
        ->execute([$name . ' ' . $run, $price, $max, $total, $total, strtolower(preg_replace('/\W+/', '-', $name)) . '-' . strtolower($run)]);
    return $made['rooms'][] = (int)$pdo->lastInsertId();
}
function mk_room(PDO $pdo, int $typeId, string $no): int {
    global $made, $run;
    $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status) VALUES (?, ?, '8e test', 1, 'available')")
        ->execute([$typeId, $no . $run]);
    return $made['individual_rooms'][] = (int)$pdo->lastInsertId();
}
function mk_booking(PDO $pdo, int $typeId, string $in, string $out, float $nightly, string $status = 'confirmed', int $guests = 2): int {
    global $made, $run;
    static $seq = 0;
    $seq++;
    $nights = max(1, (int)round((strtotime($out) - strtotime($in)) / 86400));
    $tt = rh_stay_totals($nightly * $nights, 'price');
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            check_in_date, check_out_date, number_of_nights, total_amount, amount_paid, amount_due, vat_rate, vat_amount, total_with_vat, tourism_levy_amount, tourism_levy_percent,
            special_requests, status, payment_status, is_tentative, occupancy_type, created_at)
        VALUES (?, ?, '8E TEST GUEST', '8e-test@example.invalid', '+265000000008', 'Malawi', ?, ?, 0, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, '8e test', ?, 'unpaid', 0, 'double', NOW())")
        ->execute(['JD' . $run . $seq, $typeId, $guests, $guests, $in, $out, $nights, round($tt['net'] + $tt['levy'], 2), $tt['total_with_vat'],
            $tt['vat_rate'], $tt['vat'], $tt['total_with_vat'], $tt['levy'], $tt['levy_rate'], $status]);
    return $made['bookings'][] = (int)$pdo->lastInsertId();
}
function harness(string $page, array $post, array $verify): array {
    $cfg = base64_encode(json_encode(['post' => $post, 'ajax' => false, 'verify' => $verify]));
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/page_harness.php') . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $x = json_decode($m[1], true); if (is_array($x)) { return $x; } }
    return ['error' => 'no result', 'raw' => substr($out, -600)];
}
function cleanup(PDO $pdo): void {
    global $made;
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $ids = $made['bookings'];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (['booking_rooms', 'booking_timeline_logs', 'booking_audit_log', 'booking_notes', 'quotations'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE booking_id IN ($ph)")->execute($ids); } catch (Throwable $e) { /* table may not exist */ }
        }
        $pdo->prepare("DELETE FROM bookings WHERE id IN ($ph)")->execute($ids);
    }
    foreach ($made['room_combinations'] as $id) { $pdo->prepare('DELETE FROM room_combinations WHERE id = ?')->execute([$id]); }
    foreach ($made['individual_rooms'] as $id) {
        foreach (['room_maintenance_log', 'housekeeping_assignments', 'individual_room_blocked_dates'] as $t) {
            try { $pdo->prepare("DELETE FROM $t WHERE individual_room_id = ?")->execute([$id]); } catch (Throwable $e) { /* optional table */ }
        }
        $pdo->prepare('DELETE FROM individual_rooms WHERE id = ?')->execute([$id]);
    }
    foreach ($made['rooms'] as $id) { $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$id]); }
}
register_shutdown_function(static function () use ($pdo) { cleanup($pdo); });

try {
    // ---------------------------------------------------------------- fixture
    $member = mk_type($pdo, '8E TEST MEMBER', 400000, 2, 3);
    $r1 = mk_room($pdo, $member, 'J1'); $r2 = mk_room($pdo, $member, 'J2'); $r3 = mk_room($pdo, $member, 'J3');
    $joined = mk_type($pdo, '8E TEST JOINED', 735000, 5, 2); // total_rooms 2 must never be sold on its own
    $legacy = mk_type($pdo, '8E TEST LEGACY', 300000, 2, 2);
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active) VALUES (?, ?, ?, ?, NULL, 5, 0)")
        ->execute(['8E TEST PAIR ' . $run, $joined, $r1, $r2]);
    $combo = $made['room_combinations'][] = (int)$pdo->lastInsertId();

    echo "\n--- 1. joined type, pair inactive, no own rooms: sold out everywhere ---\n";
    $a = checkRoomAvailability($joined, $day($D), $day($D + 2));
    check(empty($a['available']) && (int)($a['remaining_rooms'] ?? -1) === 0, 'checkRoomAvailability: unavailable, 0 left (not total_rooms 2)', json_encode([$a['available'] ?? null, $a['remaining_rooms'] ?? null]));
    check(stripos((string)($a['conflict_message'] ?? ''), 'No joined-room pair') !== false, 'Staff are told no pair is set up', (string)($a['conflict_message'] ?? ''));
    check(rh_joined_type_without_pairs($joined), 'Predicate: joined type without pairs');
    check(rh_room_type_sellable_count($joined, 2) === 0, 'Sellable count 0 (not the stored 2)');
    $n = getRoomTypeNightlyAvailability($joined, $day($D), $day($D + 2));
    check(is_array($n) && array_sum($n) === 0 && count($n) === 2, 'Nightly availability: 0 every night (not null / legacy)', json_encode($n));
    check(count(getBookedDatesForRoom($joined, $day($D), $day($D + 2))) === 2, 'Calendar: every night fully booked');
    check(!rh_joined_type_without_pairs($legacy), 'A type never named by a pair is not affected');
    $l = checkRoomAvailability($legacy, $day($D), $day($D + 2));
    check(!empty($l['available']) && (int)$l['remaining_rooms'] === 2, '10. Legacy type keeps its total_rooms count', json_encode($l['remaining_rooms'] ?? null));

    echo "\n--- 2. pair active (Shared): joined only for the stay's nights ---\n";
    $pdo->prepare('UPDATE room_combinations SET is_active = 1 WHERE id = ?')->execute([$combo]);
    $a = checkRoomAvailability($joined, $day($D), $day($D + 2));
    check(!empty($a['available']) && (int)$a['remaining_rooms'] === 1, 'Joined type: 1 unit (one pair)', json_encode($a['remaining_rooms'] ?? null));
    $n = getRoomTypeNightlyAvailability($joined, $day($D), $day($D + 2));
    check(is_array($n) && array_values($n) === [1, 1], 'Nightly counted from the pair (no own rooms)', json_encode($n));
    $b1 = mk_booking($pdo, $joined, $day($D), $day($D + 2), 735000);
    $before = (float)col($pdo, 'SELECT total_with_vat FROM bookings WHERE id = ?', [$b1]);
    $as = assignRoomCombinationToBooking($b1, $combo);
    check(!empty($as['success']), 'Pair assigned to a joined booking', (string)($as['message'] ?? ''));
    check(count(getBookingRoomIds($b1)) === 2, 'Booking holds both rooms (booking_rooms)');
    check(abs((float)col($pdo, 'SELECT total_with_vat FROM bookings WHERE id = ?', [$b1]) - $before) <= BALANCE_TOLERANCE, '9. Assignment changes no money');
    $m = checkRoomAvailability($member, $day($D), $day($D + 2));
    check((int)$m['remaining_rooms'] === 1, 'Member type loses both rooms on the stay nights (3 -> 1)', json_encode($m['remaining_rooms'] ?? null));
    $m2 = checkRoomAvailability($member, $day($D + 2), $day($D + 3));
    check((int)$m2['remaining_rooms'] === 3, 'Checkout day: both rooms sell on their own again', json_encode($m2['remaining_rooms'] ?? null));
    $nj = getRoomTypeNightlyAvailability($joined, $day($D), $day($D + 3));
    check(is_array($nj) && array_values($nj) === [0, 0, 1], 'Joined nightly: taken on the stay nights, free on the checkout night', json_encode($nj));

    echo "\n--- 3. one member room booked on its own: the pair is unavailable ---\n";
    $bm = mk_booking($pdo, $member, $day($D + 5), $day($D + 6), 400000);
    $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$r1, $bm]);
    syncBookingRooms($bm, [$r1]);
    $a = checkRoomAvailability($joined, $day($D + 5), $day($D + 6));
    check(empty($a['available']), 'Joined type sold out while a member room is taken');
    $m = checkRoomAvailability($member, $day($D + 5), $day($D + 6));
    check((int)$m['remaining_rooms'] === 2, 'Member type itself unaffected (2 left)', json_encode($m['remaining_rooms'] ?? null));

    echo "\n--- 4. two desks join the same pair at once: exactly one wins ---\n";
    $x = mk_booking($pdo, $joined, $day($D + 10), $day($D + 12), 735000);
    $y = mk_booking($pdo, $joined, $day($D + 10), $day($D + 12), 735000);
    $pdoA = $pdo;
    $pdoB = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdoB->exec("SET SESSION innodb_lock_wait_timeout = 2");
    $pdoA->beginTransaction();
    $resA = assignRoomCombinationToBooking($x, $combo); // desk A, not committed yet
    $GLOBALS['pdo'] = $pdoB;
    $resB = assignRoomCombinationToBooking($y, $combo); // desk B, while A holds the rooms
    $GLOBALS['pdo'] = $pdoA;
    $pdoA->commit();
    check(!empty($resA['success']) && empty($resB['success']), 'Desk B is stopped while desk A holds the pair', json_encode([$resA['message'] ?? '', $resB['message'] ?? '']));
    $resB2 = assignRoomCombinationToBooking($y, $combo);
    check(empty($resB2['success']), 'After A saves, B retrying is refused (room taken)', (string)($resB2['message'] ?? ''));
    check(count(getBookingRoomIds($y)) === 0, 'Booking B holds no rooms');
    $pdoB = null;

    echo "\n--- 5. pair assign refused when the member rooms are promised to other bookings ---\n";
    mk_booking($pdo, $member, $day($D + 20), $day($D + 21), 400000); // unassigned member bookings
    mk_booking($pdo, $member, $day($D + 20), $day($D + 21), 400000);
    $z = mk_booking($pdo, $joined, $day($D + 20), $day($D + 21), 735000);
    $rz = assignRoomCombinationToBooking($z, $combo);
    check(empty($rz['success']) && stripos((string)$rz['message'], 'no longer available') !== false, 'Refused: only 1 member room left for a pair that needs 2', (string)($rz['message'] ?? ''));
    check(count(getBookingRoomIds($z)) === 0, 'Nothing was assigned');

    echo "\n--- Fable 1: a stay that started yesterday (late arrival) can still be given the pair ---\n";
    $p = mk_booking($pdo, $joined, date('Y-m-d', strtotime('-1 day')), $day(2), 735000);
    $rp = assignRoomCombinationToBooking($p, $combo);
    check(!empty($rp['success']), 'Past-dated stay assigned (stock checked from today)', (string)($rp['message'] ?? ''));
    $pdo->prepare('UPDATE booking_rooms SET released_at = NOW() WHERE booking_id = ?')->execute([$p]);
    $pdo->prepare('UPDATE bookings SET individual_room_id = NULL, room_combination_id = NULL, status = \'cancelled\' WHERE id = ?')->execute([$p]);

    echo "\n--- 6. a type with its own rooms is never 'without pairs' ---\n";
    $own = mk_type($pdo, '8E TEST OWNROOMS', 500000, 4, 1);
    mk_room($pdo, $own, 'K1');
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active) VALUES (?, ?, ?, ?, NULL, 4, 0)")
        ->execute(['8E TEST OLDPAIR ' . $run, $own, $r3, $r3 === $r1 ? $r2 : $r1]);
    $made['room_combinations'][] = (int)$pdo->lastInsertId();
    check(!rh_joined_type_without_pairs($own), 'Deactivated joined-only pair: its own rooms keep selling');
    check(!empty(checkRoomAvailability($own, $day($D + 30), $day($D + 31))['available']), 'That type is still bookable');

    echo "\n--- 7. mixed booking queue for the joined type ---\n";
    $q = rh_mixed_combination_queue($joined, 1, $day($D + 40), $day($D + 41));
    check(count($q['queue'] ?? []) === 1 && (int)$q['queue'][0]['id'] === $combo, 'Mixed booking queues the one free pair', json_encode($q['error'] ?? ''));

    echo "\n--- 9. price: pair override empty = the joined type's own price, one rate whatever the occupancy ---\n";
    $jrow = ['id' => $joined, 'price_per_night' => 735000, 'price_single_occupancy' => 100, 'price_triple_occupancy' => 999];
    $rate = rh_room_type_base_rate($jrow, ['id' => 0, 'room_id' => 0, 'occupancy_type' => 'triple', 'check_in_date' => $day($D + 40), 'check_out_date' => $day($D + 41)]);
    check(abs($rate - 735000) < 0.01, 'Upgrade rack for a joined target = type price (not the occupancy price)', (string)$rate);
    $pdo->prepare('UPDATE room_combinations SET price_override = 500000 WHERE id = ?')->execute([$combo]);
    $rate2 = rh_room_type_base_rate($jrow, ['id' => 0, 'room_id' => 0, 'occupancy_type' => 'single', 'check_in_date' => $day($D + 40), 'check_out_date' => $day($D + 41)]);
    check(abs($rate2 - 500000) < 0.01, 'With a pair override the upgrade rack is the override', (string)$rate2);
    $pdo->prepare('UPDATE room_combinations SET price_override = NULL WHERE id = ?')->execute([$combo]);
    check(rh_room_type_base_rate(['price_per_night' => 300, 'price_double_occupancy' => 250], ['occupancy_type' => 'double']) === 250.0, 'Normal types keep their occupancy price');

    echo "\n--- 12. check-in: a joined booking with no rooms gets the pair, or is refused ---\n";
    $w = mk_booking($pdo, $joined, $day($D + 50), $day($D + 51), 735000);
    $ci = rh_checkin_ensure_joined_pair($w);
    check($ci['ok'] && $ci['assigned'] !== '' && count(getBookingRoomIds($w)) === 2, 'Pair joined at check-in', json_encode($ci));
    $w2 = mk_booking($pdo, $joined, $day($D + 50), $day($D + 51), 735000);
    $ci2 = rh_checkin_ensure_joined_pair($w2);
    check(!$ci2['ok'] && stripos($ci2['message'], 'No free joined pair') !== false, 'No free pair: check-in refused', json_encode($ci2));
    $normal = mk_booking($pdo, $legacy, $day($D + 50), $day($D + 51), 300000);
    check(rh_checkin_ensure_joined_pair($normal)['ok'], 'Other room types are left alone');

    echo "\n--- 8. merged / cancelled joined booking frees both rooms, not counted as a cancellation ---\n";
    $cxBefore = rh_cancellations_on_date($pdo, date('Y-m-d'));
    $del = rh_soft_delete_booking($pdo, $b1, 0, '8e test', 'Merged into test survivor (8e test)');
    check(!empty($del['success']), 'Closed as merged (soft delete)', (string)($del['message'] ?? ''));
    check((int)col($pdo, 'SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND released_at IS NULL', [$b1]) === 0, 'Both rooms released');
    check(!empty(checkRoomAvailability($joined, $day($D), $day($D + 2))['available']), 'The pair sells again for those nights');
    check(rh_cancellations_on_date($pdo, date('Y-m-d')) === $cxBefore, 'Not counted as a cancellation today');
    check(abs((float)col($pdo, 'SELECT amount_due FROM bookings WHERE id = ?', [$b1])) <= BALANCE_TOLERANCE, 'Nothing owed on the closed booking');

    echo "\n--- 11. Room Combinations page (rolled-back harness) ---\n";
    $pdo->prepare('UPDATE room_combinations SET is_active = 0 WHERE id = ?')->execute([$combo]);
    $hd = harness('admin/individual-rooms.php', ['action' => 'delete_room_combination', 'combination_id' => (string)$combo], ["SELECT COUNT(*) AS n FROM room_combinations WHERE id = $combo"]);
    check(empty($hd['leak']) && !empty($hd['rolled_back']), 'Harness rolled back', json_encode($hd['raw'] ?? ''));
    check((int)($hd['verify'][0][0]['n'] ?? 0) === 1 && stripos(implode(' ', $hd['output_flash'] ?? []), 'only pair') !== false, 'Deleting the last pair of a room-less joined type is refused', json_encode($hd['output_flash'] ?? $hd));
    $waiting = mk_booking($pdo, $joined, $day($D + 60), $day($D + 62), 735000);
    $ha = harness('admin/individual-rooms.php', ['action' => 'activate_room_combination', 'combination_id' => (string)$combo],
        ["SELECT COUNT(*) AS n FROM booking_rooms WHERE booking_id = $waiting AND released_at IS NULL"]);
    check((int)($ha['verify'][0][0]['n'] ?? 0) === 2 && stripos(implode(' ', $ha['output_flash'] ?? []), 'assigned') !== false, 'Switching the pair on gives it to the waiting joined booking', json_encode($ha['output_flash'] ?? $ha));
    $hr = harness('admin/individual-rooms.php', ['action' => 'save_room_combination', 'combination_id' => (string)$combo, 'combined_name' => '8E TEST PAIR ' . $run,
        'combined_room_type_id' => (string)$legacy, 'room_a_id' => (string)$r1, 'room_b_id' => (string)$r2, 'max_guests_combined' => '5', 'combination_sale_mode' => 'shared'],
        ["SELECT combined_room_type_id AS t FROM room_combinations WHERE id = $combo"]);
    check((int)($hr['verify'][0][0]['t'] ?? 0) === $joined && stripos(implode(' ', $hr['output_flash'] ?? []), 'only pair') !== false, 'Changing the Book As type of the last pair is refused', json_encode($hr['output_flash'] ?? $hr));

    echo "\n--- 13. static checks ---\n";
    $rm = (string)file_get_contents($root . '/admin/room-management.php');
    check(substr_count($rm, 'rm-tax-line" data-tax-for=') === 8 && strpos($rm, "rh_stay_totals(\$price, 'price')") !== false, 'Room prices show the VAT / levy lines from rh_stay_totals (8 fields + card)');
    check(strpos($rm, "'vat_rate'") !== false && strpos($rm, '17.5') === false, 'No hard-coded VAT rate');
    $rx = (string)file_get_contents($root . '/api/reports-export.php');
    check(strpos($rx, 'FROM bookings WHERE deleted_at IS NULL AND created_at >= ?') !== false && strpos($rx, 'WHERE b.deleted_at IS NULL AND b.created_at >= ?') !== false, 'Bookings export leaves deleted/merged bookings out');
    foreach (['admin/bookings.php', 'admin/process-checkin.php', 'admin/booking-details.php'] as $f) {
        check(strpos((string)file_get_contents($root . '/' . $f), 'rh_checkin_ensure_joined_pair(') !== false, "$f joins the pair at check-in");
    }
} catch (Throwable $e) {
    bad('Test run crashed', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $GLOBALS['pdo'] = $pdo;
    cleanup($pdo);
    $left = 0;
    foreach (['rooms' => 'rooms', 'individual_rooms' => 'individual_rooms', 'room_combinations' => 'room_combinations', 'bookings' => 'bookings'] as $k => $t) {
        foreach ($made[$k] as $id) { $left += (int)col($pdo, "SELECT COUNT(*) FROM $t WHERE id = ?", [$id]); }
    }
    check($left === 0, 'Cleanup: every test row removed', (string)$left);
    $made = ['rooms' => [], 'individual_rooms' => [], 'room_combinations' => [], 'bookings' => []];
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
