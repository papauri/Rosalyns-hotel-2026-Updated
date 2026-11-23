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
$made = ['rooms' => [], 'individual_rooms' => [], 'room_combinations' => [], 'bookings' => [], 'rate_plans' => [], 'blocked_dates' => []];
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
    foreach ($made['rate_plans'] ?? [] as $id) { $pdo->prepare('DELETE FROM rate_plans WHERE id = ?')->execute([$id]); }
    foreach ($made['blocked_dates'] ?? [] as $id) { $pdo->prepare('DELETE FROM blocked_dates WHERE id = ?')->execute([$id]); }
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

    // Second fixture (follow-up): a member type with 5 rooms, a joined type with TWO Shared pairs (A = P1+P2,
    // B = P3+P4), and two rate plans for the far-future window: -10% on the joined type only, +50% on the member
    // type only. Made before any price is read (the rate-plan list is cached per run).
    $M = $D + 100;
    $mt2 = mk_type($pdo, '8E TEST MEMBER2', 400000, 2, 5);
    $p1 = mk_room($pdo, $mt2, 'P1'); $p2 = mk_room($pdo, $mt2, 'P2'); $p3 = mk_room($pdo, $mt2, 'P3'); $p4 = mk_room($pdo, $mt2, 'P4'); $p5 = mk_room($pdo, $mt2, 'P5');
    $j2 = mk_type($pdo, '8E TEST JOINED2', 735000, 5, 2);
    $mkPair = static function (string $label, int $a, int $b, ?float $override, int $active) use ($pdo, $j2, $run): int {
        global $made;
        $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active) VALUES (?, ?, ?, ?, ?, 5, ?)")
            ->execute(['8E TEST MP-' . $label . ' ' . $run, $j2, $a, $b, $override, $active]);
        return $made['room_combinations'][] = (int)$pdo->lastInsertId();
    };
    $pairA = $mkPair('A', $p1, $p2, null, 1);
    $pairB = $mkPair('B', $p3, $p4, 600000, 1);
    $mkPlan = static function (string $name, array $typeIds, float $pct, int $from = 190) use ($pdo, $day, $D, $run): int {
        global $made;
        $pdo->prepare("INSERT INTO rate_plans (name, description, rule_type, start_date, end_date, adjustment_type, adjustment_value, applies_to, room_type_ids, priority, is_stacking, is_active)
            VALUES (?, '8e test', 'seasonal', ?, ?, 'percentage', ?, 'room_types', ?, 0, 0, 1)")
            ->execute([$name . ' ' . $run, $day($D + $from), $day($D + $from + 9), $pct, json_encode($typeIds)]);
        return $made['rate_plans'][] = (int)$pdo->lastInsertId();
    };
    $planJ = $mkPlan('8E TEST JOINED -10', [$j2], -10);
    $planM = $mkPlan('8E TEST MEMBER +50', [$mt2], 50);
    $planUp = $mkPlan('8E TEST BOTH +50', [$mt2, $j2], 50, 300);   // one plan covering both types
    $planDown = $mkPlan('8E TEST BOTH -10', [$mt2, $j2], -10, 320);

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
    $exp9 = (float)applyDynamicPricing($pdo, $joined, $day($D + 40), $day($D + 41), 1, 735000)['final_price']; // any hotel-wide plan applies as on create
    check(abs($rate - $exp9) < 0.01, 'Upgrade rack for a joined target = type price (+ its rate plans), not the occupancy price', $rate . ' vs ' . $exp9);
    $pdo->prepare('UPDATE room_combinations SET price_override = 500000 WHERE id = ?')->execute([$combo]);
    $rate2 = rh_room_type_base_rate($jrow, ['id' => 0, 'room_id' => 0, 'occupancy_type' => 'single', 'check_in_date' => $day($D + 40), 'check_out_date' => $day($D + 41)]);
    $exp9b = (float)applyDynamicPricing($pdo, $joined, $day($D + 40), $day($D + 41), 1, 500000)['final_price'];
    check(abs($rate2 - $exp9b) < 0.01, 'With a pair override the upgrade rack is the override (+ rate plans)', $rate2 . ' vs ' . $exp9b);
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
    // The All Bookings check-in button and the bulk Check in both run bookings_checkin_gates() (moved out of
    // admin/bookings.php into admin/includes/booking-row-actions.php by 1cd4193): the gates themselves must give a
    // joined booking with no rooms its pair, and refuse the check-in when no pair is free. Arriving today.
    require_once $root . '/admin/includes/booking-row-actions.php';
    $gUser = ['id' => (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn(), 'role' => 'admin'];
    $wg = mk_booking($pdo, $joined, $day(0), $day(1), 735000);
    // An agreed pay-later arrangement, so the payment gate (checked first) lets the check-in reach the pair step.
    $payLater = $pdo->prepare("UPDATE bookings SET checkin_without_payment = 1, checkin_arrangement_note = '8e test pay later' WHERE id = ?");
    $payLater->execute([$wg]);
    $gErr = '';
    try { bookings_checkin_gates($pdo, $wg, $gUser, []); } catch (Throwable $e) { $gErr = $e->getMessage(); }
    check($gErr === '' && count(getBookingRoomIds($wg)) === 2, 'All Bookings / bulk check-in gates join the free pair', $gErr);
    $wg2 = mk_booking($pdo, $joined, $day(0), $day(1), 735000);
    $payLater->execute([$wg2]);
    $gErr2 = '';
    try { bookings_checkin_gates($pdo, $wg2, $gUser, []); } catch (Throwable $e) { $gErr2 = $e->getMessage(); }
    check(stripos($gErr2, 'No free joined pair') !== false && count(getBookingRoomIds($wg2)) === 0, 'All Bookings / bulk check-in gates refuse when no pair is free (no rooms given)', $gErr2);

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

    echo "\n--- 14. occupancy: a joined type with no active pair has 0 rooms in the reports ---\n";
    check(rh_room_type_unit_total($joined, 2) === 0, 'Joined type, pair inactive: report rooms 0 (not the stored 2)', (string)rh_room_type_unit_total($joined, 2));
    check(rh_room_type_unit_total($legacy, 2) === 2, 'Legacy type with no rooms keeps its stored count');
    check(rh_room_type_unit_total($j2, 2) === 2 && rh_room_type_unit_total($mt2, 5) === 5, 'Two Shared pairs = 2 joined units; their 4 rooms still count under the member type (5)',
        rh_room_type_unit_total($j2, 2) . '/' . rh_room_type_unit_total($mt2, 5));

    echo "\n--- 15. more than one pair: which pair is given ---\n";
    $av = checkRoomAvailability($j2, $day($M), $day($M + 2));
    check(!empty($av['available']) && (int)$av['remaining_rooms'] === 2, 'Two free pairs = 2 joined rooms to sell', json_encode($av['remaining_rooms'] ?? null));
    check(array_values(getRoomTypeNightlyAvailability($j2, $day($M), $day($M + 2))) === [2, 2], 'Nightly / calendar: 2 each night');
    $free = getAvailableRoomCombinations($j2, $day($M), $day($M + 2));
    check(array_map(fn($c) => (int)$c['id'], $free) === [$pairA, $pairB], 'Order for a later stay: by pair name (A, then B)');
    check(count(rh_joined_quote_queue($free)) === 2, 'Quote queue offers the same 2 pairs');
    $u1 = mk_booking($pdo, $j2, $day($M), $day($M + 2), 735000);
    $av = checkRoomAvailability($j2, $day($M), $day($M + 2));
    check((int)$av['remaining_rooms'] === 1, 'One unassigned joined booking holds a pair: 1 left', json_encode($av['remaining_rooms'] ?? null));
    $u2 = mk_booking($pdo, $j2, $day($M), $day($M + 2), 735000);
    $r1a = autoAssignIndividualRoom($u1);
    $r2a = autoAssignIndividualRoom($u2);
    $c1 = (int)col($pdo, 'SELECT room_combination_id FROM bookings WHERE id = ?', [$u1]);
    $c2 = (int)col($pdo, 'SELECT room_combination_id FROM bookings WHERE id = ?', [$u2]);
    check(!empty($r1a['success']) && !empty($r2a['success']) && $c1 === $pairA && $c2 === $pairB, 'Two bookings, same nights: first gets A, second gets B (never the same pair)', json_encode([$c1, $c2, $r2a['message'] ?? '']));
    $u3 = mk_booking($pdo, $j2, $day($M), $day($M + 2), 735000);
    $r3a = autoAssignIndividualRoom($u3);
    check(empty($r3a['success']) && count(getBookingRoomIds($u3)) === 0, 'Third booking: no free pair, nothing assigned', (string)($r3a['message'] ?? ''));
    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$u3]);

    // Pair A partly booked as a single room: B is used.
    $s1 = mk_booking($pdo, $mt2, $day($M + 10), $day($M + 12), 400000);
    $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$p1, $s1]);
    syncBookingRooms($s1, [$p1]);
    $av = checkRoomAvailability($j2, $day($M + 10), $day($M + 12));
    check((int)$av['remaining_rooms'] === 1, 'Room P1 sold on its own: only pair B left', json_encode($av['remaining_rooms'] ?? null));
    $u4 = mk_booking($pdo, $j2, $day($M + 10), $day($M + 12), 735000);
    autoAssignIndividualRoom($u4);
    check((int)col($pdo, 'SELECT room_combination_id FROM bookings WHERE id = ?', [$u4]) === $pairB, 'Joined booking gets pair B');

    // A member room blocked / in maintenance, or the member type blocked: the pair is skipped.
    $pdo->prepare("INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', '8e test')")->execute([$p3, $day($M + 20)]);
    $ids = array_map(fn($c) => (int)$c['id'], getAvailableRoomCombinations($j2, $day($M + 20), $day($M + 21)));
    check($ids === [$pairA], 'Room P3 blocked that night: pair B skipped', json_encode($ids));
    $pdo->prepare("UPDATE individual_rooms SET status = 'maintenance' WHERE id = ?")->execute([$p1]);
    check(getAvailableRoomCombinations($j2, $day($M + 20), $day($M + 21)) === [] && empty(checkRoomAvailability($j2, $day($M + 20), $day($M + 21))['available']), 'And P1 in maintenance: no pair, joined type sold out');
    check(rh_room_type_unit_total($j2, 2) === 1, 'Reports: a pair with a member in maintenance is not a sellable unit', (string)rh_room_type_unit_total($j2, 2));
    $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id = ?")->execute([$p1]);
    $pdo->prepare("INSERT INTO blocked_dates (room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', '8e test')")->execute([$mt2, $day($M + 30)]);
    $made['blocked_dates'][] = (int)$pdo->lastInsertId();
    check(getAvailableRoomCombinations($j2, $day($M + 30), $day($M + 31)) === [] && array_values(getRoomTypeNightlyAvailability($j2, $day($M + 30), $day($M + 31))) === [0],
        'Member type closed that night: both pairs skipped, calendar 0');

    // Staff pick a specific pair.
    $u5 = mk_booking($pdo, $j2, $day($M + 40), $day($M + 41), 735000);
    $man = assignRoomCombinationToBooking($u5, $pairB);
    check(!empty($man['success']) && getBookingRoomIds($u5) == [$p3, $p4], 'Manual pick: pair B given exactly', (string)($man['message'] ?? ''));
    $cands = rh_room_assignment_candidates($j2, $day($M + 40), $day($M + 41), $u5);
    $cur = array_values(array_filter($cands['items'], fn($i) => !empty($i['current'])));
    check($cands['mode'] === 'combinations' && count($cands['items']) === 2 && count($cur) === 1 && (int)$cur[0]['id'] === $pairB, 'Picker lists both pairs, B marked current');

    // A stay that starts today: a clean pair comes before one being cleaned (as single rooms do).
    $pdo->prepare("UPDATE individual_rooms SET status = 'cleaning' WHERE id = ?")->execute([$p1]);
    $ids = array_map(fn($c) => (int)$c['id'], getAvailableRoomCombinations($j2, $day(0), $day(1)));
    check($ids === [$pairB, $pairA], 'Same-day stay: pair A being cleaned goes after clean pair B', json_encode($ids));
    $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id = ?")->execute([$p1]);
    $ids = array_map(fn($c) => (int)$c['id'], getAvailableRoomCombinations($j2, $day(0), $day(1)));
    check($ids === [$pairA, $pairB], 'Both clean: back to name order', json_encode($ids));

    echo "\n--- 16. upgrade into a joined type: priced like a new booking, on the pair it is given ---\n";
    $ui = $day($D + 192); $uo = $day($D + 194);
    $blk = mk_booking($pdo, $mt2, $ui, $uo, 400000); // P1 taken those nights, so pair A is not free
    $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$p1, $blk]);
    syncBookingRooms($blk, [$p1]);
    $up = mk_booking($pdo, $mt2, $ui, $uo, 600000); // booked under the member plan: 400,000 +50%
    $opts = rh_room_change_options($pdo, $up);
    $opt = array_values(array_filter($opts['options'], fn($o) => (int)$o['id'] === $j2))[0] ?? null;
    $dyn = applyDynamicPricing($pdo, $j2, $ui, $uo, 2, 600000);
    $expNight = (float)$dyn['final_price'];
    check(in_array($planJ, array_map('intval', $dyn['rate_plan_ids'] ?? [(int)($dyn['rate_plan_id'] ?? 0)]), true) && !in_array($planM, array_map('intval', $dyn['rate_plan_ids'] ?? []), true),
        'Joined-type plan applies, member-type plan does not', json_encode($dyn['rate_plan_ids'] ?? $dyn['rate_plan_id']));
    check($opt !== null && $opt['available'] && abs((float)$opt['quote']['new_rack'] - $expNight) < 0.01, 'Options quote: pair B override 600,000 + the joined plan (-10%)', json_encode($opt['quote']['new_rack'] ?? $opt));
    $mvReason = static fn(array $q): string => $q['direction'] === 'downgrade' ? 'downgrade_guest_request' : ($q['direction'] === 'upgrade' ? 'upgrade_guest_request' : 'change_guest_request');
    $res = rh_apply_room_type_change($pdo, $up, $j2, 'charge', $mvReason($opt['quote']), '8e test move', ['id' => 0, 'full_name' => '8e test'], false);
    check(!empty($res['success']), 'Upgrade done', (string)($res['message'] ?? ''));
    $row = $pdo->prepare('SELECT room_id, room_combination_id, total_with_vat FROM bookings WHERE id = ?');
    $row->execute([$up]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    check((int)$row['room_combination_id'] === $pairB && count(getBookingRoomIds($up)) === 2, 'Given the pair it was priced on (B)', json_encode($row));
    check(abs((float)$row['total_with_vat'] - round($expNight * 2, 2)) <= BALANCE_TOLERANCE && abs((float)$row['total_with_vat'] - (float)$opt['quote']['charge_total']) <= BALANCE_TOLERANCE,
        'Bill = booked rate x 2 nights + the rack difference = joined rate x 2; same as the quote', $row['total_with_vat'] . ' vs ' . ($expNight * 2));

    echo "\n--- 16c. a plan covering BOTH types: the move costs what a new booking of the new type costs ---\n";
    // Upgrade under +50% on both: booked member 600,000 (400,000 +50%), new joined booking 900,000 (pair B 600,000 +50%).
    $ci = $day($D + 302); $co = $day($D + 304);
    $blk2 = mk_booking($pdo, $mt2, $ci, $co, 600000);
    $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$p1, $blk2]);
    syncBookingRooms($blk2, [$p1]); // pair A not free: pair B (override 600,000) is the one priced and given
    $upB = mk_booking($pdo, $mt2, $ci, $co, (float)applyDynamicPricing($pdo, $mt2, $ci, $co, 2, 400000)['final_price']);
    $newJ = (float)applyDynamicPricing($pdo, $j2, $ci, $co, 2, 600000)['final_price'];
    $o = array_values(array_filter(rh_room_change_options($pdo, $upB)['options'], fn($x) => (int)$x['id'] === $j2))[0] ?? null;
    check($o !== null && abs((float)$o['quote']['charge_total'] - round($newJ * 2, 2)) <= BALANCE_TOLERANCE, 'Upgrade under +50% on both: quote = new joined booking (no overcharge)', json_encode([$o['quote']['charge_total'] ?? null, $newJ * 2]));
    $rU = rh_apply_room_type_change($pdo, $upB, $j2, 'charge', $mvReason($o['quote']), '8e test move', ['id' => 0, 'full_name' => '8e test'], false);
    check(!empty($rU['success']) && abs((float)col($pdo, 'SELECT total_with_vat FROM bookings WHERE id = ?', [$upB]) - round($newJ * 2, 2)) <= BALANCE_TOLERANCE, 'Upgrade: saved bill = the quote', (string)($rU['message'] ?? ''));
    // Downgrade under -10% on both: booked joined 540,000 (pair B 600,000 -10%), new member booking 360,000.
    $ci = $day($D + 322); $co = $day($D + 324);
    $dn = mk_booking($pdo, $j2, $ci, $co, (float)applyDynamicPricing($pdo, $j2, $ci, $co, 2, 600000)['final_price']);
    $asD = assignRoomCombinationToBooking($dn, $pairB);
    $newM = (float)applyDynamicPricing($pdo, $mt2, $ci, $co, 2, 400000)['final_price'];
    $o = array_values(array_filter(rh_room_change_options($pdo, $dn)['options'], fn($x) => (int)$x['id'] === $mt2))[0] ?? null;
    check(!empty($asD['success']) && $o !== null && $o['quote']['direction'] === 'downgrade' && abs((float)$o['quote']['charge_total'] - round($newM * 2, 2)) <= BALANCE_TOLERANCE,
        'Downgrade under -10% on both: quote = new member booking (no overcharge)', json_encode([$o['quote']['charge_total'] ?? null, $newM * 2, $asD['message'] ?? '']));
    $rD = rh_apply_room_type_change($pdo, $dn, $mt2, 'charge', $mvReason($o['quote']), '8e test move', ['id' => 0, 'full_name' => '8e test'], false);
    check(!empty($rD['success']) && abs((float)col($pdo, 'SELECT total_with_vat FROM bookings WHERE id = ?', [$dn]) - round($newM * 2, 2)) <= BALANCE_TOLERANCE, 'Downgrade: saved bill = the quote', (string)($rD['message'] ?? ''));
    // A move between two normal types is unchanged (catalogue racks, no plans).
    check(rh_room_type_base_rate(['id' => $mt2, 'price_per_night' => 400000], ['check_in_date' => $ci, 'check_out_date' => $co, 'number_of_nights' => 2]) === 400000.0, 'Normal-type rack without the joined flag ignores plans');

    echo "\n--- 16b. in-house guest upgraded into the joined type: moves to a clean pair now ---\n";
    $ih = mk_booking($pdo, $mt2, $day(0), $day(2), 400000, 'checked-in');
    $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$p5, $ih]);
    syncBookingRooms($ih, [$p5]);
    $pdo->prepare("UPDATE individual_rooms SET status = 'occupied' WHERE id = ?")->execute([$p5]);
    $pdo->prepare("UPDATE individual_rooms SET status = 'cleaning' WHERE id = ?")->execute([$p1]); // pair A not clean
    $resIh = rh_apply_room_type_change($pdo, $ih, $j2, 'charge', 'upgrade_guest_request', '', ['id' => 0, 'full_name' => '8e test'], false);
    $ihRooms = getBookingRoomIds($ih);
    sort($ihRooms);
    check(!empty($resIh['success']) && (int)col($pdo, 'SELECT room_combination_id FROM bookings WHERE id = ?', [$ih]) === $pairB && $ihRooms == [$p3, $p4],
        'In-house: given clean pair B (A is being cleaned)', json_encode([$resIh['message'] ?? '', $ihRooms]));
    check((string)col($pdo, 'SELECT status FROM individual_rooms WHERE id = ?', [$p5]) === 'cleaning'
        && (string)col($pdo, 'SELECT status FROM individual_rooms WHERE id = ?', [$p3]) === 'occupied'
        && (string)col($pdo, 'SELECT status FROM individual_rooms WHERE id = ?', [$p4]) === 'occupied', 'Old room to housekeeping, both pair rooms occupied');
    $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id IN (?, ?, ?, ?)")->execute([$p1, $p3, $p4, $p5]);
    $pdo->prepare("UPDATE booking_rooms SET released_at = NOW() WHERE booking_id = ?")->execute([$ih]);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', individual_room_id = NULL, room_combination_id = NULL WHERE id = ?")->execute([$ih]);

    echo "\n--- 17. a room is in only one active pair (save and activate) ---\n";
    $hs = harness('admin/individual-rooms.php', ['action' => 'save_room_combination', 'combination_id' => '0', 'combined_name' => '8E TEST MP-C ' . $run,
        'combined_room_type_id' => (string)$j2, 'room_a_id' => (string)$p1, 'room_b_id' => (string)$p5, 'max_guests_combined' => '5', 'combination_sale_mode' => 'shared', 'combination_is_active' => '1'],
        ["SELECT COUNT(*) AS n FROM room_combinations WHERE combined_room_type_id = $j2"]);
    check((int)($hs['verify'][0][0]['n'] ?? 0) === 2 && stripos(implode(' ', $hs['output_flash'] ?? []), 'already part of the active joined room') !== false, 'Save refused: P1 is already in active pair A', json_encode($hs['output_flash'] ?? $hs));
    $pairC = $mkPair('C', $p2, $p5, null, 0);
    $hc = harness('admin/individual-rooms.php', ['action' => 'activate_room_combination', 'combination_id' => (string)$pairC], ["SELECT is_active AS a FROM room_combinations WHERE id = $pairC"]);
    check((int)($hc['verify'][0][0]['a'] ?? 1) === 0 && stripos(implode(' ', $hc['output_flash'] ?? []), 'already part of the active joined room') !== false, 'Activate refused: P2 is already in active pair A', json_encode($hc['output_flash'] ?? $hc));

    echo "\n--- 18. Individual Rooms list: filter by the joined type, status cards = rows ---\n";
    $src = (string)file_get_contents($root . '/admin/individual-rooms.php');
    check(strpos($src, 'rcf.combined_room_type_id = ? AND rcf.is_active = 1') !== false, 'Type filter includes the member rooms of the type\'s active pairs');
    check(strpos($src, "array_map(static function (\$r) { return (string)\$r['display_status']; }, \$individualRooms)") !== false && strpos($src, "(string)\$row['display_status'] !== (string)\$filter_status") !== false,
        'Cards, Status filter and badge use one display status');
    check(strpos($src, "row.dataset.filterHidden = match ? '' : '1';") !== false && strpos($src, 'rhPagerRefresh(true)') !== false, 'Card filter works with the 10-per-page pager');
    check(strpos($src, "['maintenance', 'out_of_order', 'cleaning']") === false, 'A room being cleaned can still be assigned');
    check(strpos($src, "\$irRowRef['display_status'] = 'inactive';") !== false, 'A switched-off room is shown Inactive and counted on no status card');

    echo "\n--- 13. static checks ---\n";
    $rm = (string)file_get_contents($root . '/admin/room-management.php');
    check(substr_count($rm, 'rm-tax-line" data-tax-for=') === 8 && strpos($rm, "rh_stay_totals(\$price, 'price')") !== false, 'Room prices show the VAT / levy lines from rh_stay_totals (8 fields + card)');
    check(strpos($rm, "'vat_rate'") !== false && strpos($rm, '17.5') === false, 'No hard-coded VAT rate');
    $rx = (string)file_get_contents($root . '/api/reports-export.php');
    check(strpos($rx, 'FROM bookings WHERE deleted_at IS NULL AND created_at >= ?') !== false && strpos($rx, 'WHERE b.deleted_at IS NULL AND b.created_at >= ?') !== false, 'Bookings export leaves deleted/merged bookings out');
    foreach (['admin/includes/booking-row-actions.php', 'admin/process-checkin.php', 'admin/booking-details.php'] as $f) {
        check(strpos((string)file_get_contents($root . '/' . $f), 'rh_checkin_ensure_joined_pair(') !== false, "$f joins the pair at check-in");
    }
    // admin/bookings.php and the bulk bar check in through the shared gates (behaviour asserted in section 12), since 1cd4193.
    foreach (['admin/bookings.php', 'admin/includes/bookings-bulk.php'] as $f) {
        check(strpos((string)file_get_contents($root . '/' . $f), 'bookings_checkin_gates($pdo') !== false, "$f checks in through bookings_checkin_gates() (joins the pair)");
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
