<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The one booking-clash check (includes/room-night-clash.php, GRID_UNIFY_PLAN Phase 2).
 * Usage (from the repo root, Liwonde test DB only): php scripts/tests/room_night_clash_test.php
 *
 * Runs in ONE transaction on a connection that can never commit (savepoints for inner begin/commit) and is rolled
 * back at the end: fixture room types, rooms, a joined pair and bookings are written inside it, nothing survives.
 * Fixture nights are 600+ days ahead, past every real booking.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/config/database.php';
require_once $root . '/includes/room-night-clash.php';
require_once __DIR__ . '/clash_test_lib.php';

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$pdo = clash_test_pdo();
$GLOBALS['pdo'] = $pdo;
$pdo->beginTransaction();
try {
    $fx = clash_test_fixtures($pdo, 'ZZ clash ' . bin2hex(random_bytes(2)));
    [$T1, $T2, $T3] = [$fx['T1'], $fx['T2'], $fx['T3']];
    [$r1, $r2, $r3, $r4, $p1, $p2] = [$fx['r1'], $fx['r2'], $fx['r3'], $fx['r4'], $fx['p1'], $fx['p2']];
    $D = static fn(int $k): string => date('Y-m-d', strtotime('+' . (600 + $k) . ' days'));
    $bk = static fn(string $ref, int $type, ?int $room, string $ci, string $co, string $status = 'confirmed', ?string $exp = null): int
        => clash_test_booking($pdo, $ref, $type, $room, $ci, $co, $status, $exp);
    $room = static fn(int $rid, array $nights, string $label = 'R'): array => ['scope' => 'room', 'room_ids' => [$rid], 'type_id' => (int)$pdo->query("SELECT room_type_id FROM individual_rooms WHERE id = $rid")->fetchColumn(), 'label' => $label . $rid, 'nights' => $nights];
    $clash = static fn(array $targets, array $opt = []): array => rh_room_nights_clash($pdo, $targets, $opt);
    $days = static fn(int $a, int $b): array => rh_rnc_days($D($a), $D($b));

    echo "== R1/R2: nights a booking sleeps ==\n";
    $b1 = $bk('ZZC-1', $T1, $r1, $D(10), $D(13));
    $res = $clash([$room($r1, $days(9, 13))]);
    check($res['targets'][0]['booked'] === [$D(10), $D(11), $D(12)] && $res['targets'][0]['free'] === [$D(9), $D(13)],
        'check-in .. check-out-1 are booked; the night before and the check-out night are free', json_encode($res['targets'][0]));
    $b = $res['bookings'][$b1] ?? null;
    check($b && $b['ref'] === 'ZZC-1' && !$b['no_room'] && $b['where']['R' . $r1] === [$D(10), $D(11), $D(12)] && $res['clash'] === true, 'the booking is listed with its booked nights', json_encode($b));
    $txt = rh_room_nights_clash_text($res['bookings']);
    check(strpos($txt, 'ZZC-1') !== false && strpos($txt, 'booked ') !== false && strpos($txt, 'stay ') !== false, 'the clash text names guest, reference, stay and booked nights', $txt);

    $bk('ZZC-2a', $T1, $r2, $D(20), $D(21), 'pending');
    $bk('ZZC-2b', $T1, $r2, $D(21), $D(22), 'tentative', date('Y-m-d H:i:s', strtotime('+1 day')));
    $bk('ZZC-2c', $T1, $r2, $D(22), $D(23), 'tentative', date('Y-m-d H:i:s', strtotime('-1 hour')));
    $bk('ZZC-2d', $T1, $r2, $D(23), $D(24), 'cancelled');
    $bk('ZZC-2e', $T1, $r2, $D(24), $D(25), 'checked-out');
    $bk('ZZC-2f', $T1, $r2, $D(25), $D(26), 'no-show');
    $res = $clash([$room($r2, $days(20, 25))]);
    check($res['targets'][0]['booked'] === [$D(20), $D(21)], 'pending and a live tentative hold the room; expired tentative, cancelled, checked-out, no-show do not', json_encode($res['targets'][0]));

    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $bk('ZZC-3', $T1, $r3, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('-1 day')), 'checked-in');
    $res = $clash([$room($r3, [$today, $tomorrow])]);
    check($res['targets'][0]['booked'] === [$today] && $res['targets'][0]['free'] === [$tomorrow], 'an overdue in-house guest holds the room tonight (effective check-out = tomorrow)', json_encode($res['targets'][0]));

    $b4 = $bk('ZZC-4', $T2, null, $D(30), $D(31));
    $pdo->prepare('INSERT INTO booking_rooms (booking_id, individual_room_id) VALUES (?, ?)')->execute([$b4, $r4]);
    $b5 = $bk('ZZC-5', $T1, $r1, $D(31), $D(32));
    $pdo->prepare('INSERT INTO booking_rooms (booking_id, individual_room_id, released_at) VALUES (?, ?, NOW())')->execute([$b5, $r4]);
    $res = $clash([$room($r4, [$D(30), $D(31)])]);
    check($res['targets'][0]['booked'] === [$D(30)] && $res['targets'][0]['free'] === [$D(31)], 'a room held only through booking_rooms is booked; a released row frees it', json_encode($res['targets'][0]));

    echo "== R3: bookings with no room yet (typed capacity) ==\n";
    foreach ([1, 2, 3] as $k) {
        $bk('ZZC-6' . $k, $T1, null, $D(40), $D(41));
    }
    $res = $clash([$room($r1, [$D(40)])]);
    $noRoom = array_values(array_filter($res['bookings'], static fn($b) => $b['no_room']));
    check($res['targets'][0]['booked'] === [$D(40)] && count($noRoom) === 3, '3 free rooms, 3 bookings with no room: no room of the type can be taken off sale (the bookings are listed as "no room yet")', json_encode([$res['targets'][0], count($noRoom)]));
    check(strpos(rh_room_nights_clash_text($res['bookings']), 'no room yet') !== false, 'the text says "no room yet"');
    foreach ([1, 2] as $k) {
        $bk('ZZC-7' . $k, $T1, null, $D(41), $D(42));
    }
    $res = $clash([$room($r1, [$D(41)])]);
    check($res['targets'][0]['free'] === [$D(41)], '3 free rooms, 2 bookings with no room: one room can still be blocked', json_encode($res['targets'][0]));
    $pdo->prepare("INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', 'ZZ clash')")->execute([$r2, $D(40)]);
    $res = $clash([$room($r2, [$D(40)])]);
    check($res['targets'][0]['free'] === [$D(40)], 'a room already blocked that night uses no unit, so R3 does not flag it', json_encode($res['targets'][0]));
    // Fable required change 1: the budget is cumulative across the whole save (3 free units, 1 typed booking, 3 rooms).
    $bk('ZZC-8', $T1, null, $D(42), $D(43));
    $res = $clash([$room($r3, [$D(42)]), $room($r1, [$D(42)]), $room($r2, [$D(42)])]);
    $savedRooms = [];
    foreach ([[0, $r3], [1, $r1], [2, $r2]] as [$i, $rid]) {
        if ($res['targets'][$i]['free']) {
            $savedRooms[] = $rid;
        }
    }
    sort($savedRooms);
    $want = [$r1, $r2, $r3];
    sort($want);
    check($savedRooms === array_slice($want, 0, 2) && count(array_filter($res['bookings'], static fn($b) => $b['no_room'] && $b['ref'] === 'ZZC-8')) === 1,
        'blocking k-j+1 rooms in one save keeps exactly k-j (lowest room ids first) and lists the booking with no room', json_encode([$savedRooms, $want, array_column($res['bookings'], 'ref')]));
    $res2 = $clash([$room($r2, [$D(42)]), $room($r1, [$D(42)]), $room($r3, [$D(42)])]);
    $saved2 = [];
    foreach ([[0, $r2], [1, $r1], [2, $r3]] as [$i, $rid]) {
        if ($res2['targets'][$i]['free']) {
            $saved2[] = $rid;
        }
    }
    sort($saved2);
    check($saved2 === $savedRooms, 'the result does not depend on the order the rooms were posted', json_encode($saved2));

    echo "== R4 / R5: room type and All rooms ==\n";
    $b9 = $bk('ZZC-9', $T2, null, $D(50), $D(51));
    $pdo->prepare('INSERT INTO booking_rooms (booking_id, individual_room_id) VALUES (?, ?)')->execute([$b9, $r2]);
    $typeT = static fn(int $tid, array $nights): array => ['scope' => 'type', 'room_ids' => [], 'type_id' => $tid, 'label' => 'T' . $tid, 'nights' => $nights];
    $res = $clash([$typeT($T1, [$D(41), $D(50), $D(51)]), $typeT($T2, [$D(41)])]);
    check($res['targets'][0]['booked'] === [$D(41), $D(50)] && $res['targets'][0]['free'] === [$D(51)] && $res['targets'][1]['free'] === [$D(41)],
        'type scope: a typed booking of the type and a booking of another type holding one of its rooms make the night booked; another type stays free', json_encode($res['targets']));
    $freeNight = null;
    for ($k = 70; $k < 90 && $freeNight === null; $k++) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE check_in_date <= '" . $D($k) . "' AND check_out_date > '" . $D($k) . "' AND status IN ('pending','tentative','confirmed','checked-in')")->fetchColumn() === 0) {
            $freeNight = $D($k);
        }
    }
    $res = $clash([['scope' => 'all', 'room_ids' => [], 'type_id' => null, 'label' => 'All rooms', 'nights' => array_values(array_filter([$D(10), $freeNight]))]]);
    check($res['targets'][0]['booked'] === [$D(10)] && $res['targets'][0]['free'] === array_values(array_filter([$freeNight])), 'All rooms: any booking makes the night booked; a night with none is free', json_encode($res['targets'][0]));

    echo "== Owner answer 2: a type / All-rooms night with bookings blocks only the free rooms ==\n";
    $bk('ZZC-10', $T1, $r1, $D(80), $D(81));
    $bk('ZZC-11', $T1, null, $D(82), $D(83));
    $res = $clash([$typeT($T1, [$D(80), $D(81), $D(82)])], ['convert' => true]);
    $t0 = $res['targets'][0];
    $singleRooms = static function (array $units): array {
        $ids = [];
        foreach ($units as $u) {
            $ids = array_merge($ids, $u['room_ids']);
        }
        sort($ids);
        return $ids;
    };
    $lowTwo = [$r1, $r2, $r3];
    sort($lowTwo);
    check($t0['free'] === [$D(81)] && $singleRooms($t0['singles'][$D(80)] ?? []) === array_values(array_diff($lowTwo, [$r1])) && count($t0['left_open'][$D(80)] ?? []) === 1,
        'a night with a booked room blocks the other rooms one by one; the night with no booking keeps the type block', json_encode($t0));
    check(count($t0['singles'][$D(82)] ?? []) === 2 && count($t0['left_open'][$D(82)] ?? []) === 1,
        'a booking with no room yet keeps one room free (2 of 3 blocked singly)', json_encode($t0['singles'][$D(82)] ?? null));
    $res = $clash([$typeT($T1, [$D(40)])], ['convert' => true]);
    check($res['targets'][0]['booked'] === [$D(40)] && !$res['targets'][0]['singles'], 'when every free room is needed by bookings with no room, the night is skipped', json_encode($res['targets'][0]));

    echo "== Joined room ==\n";
    $b12 = $bk('ZZC-12', $T3, null, $D(60), $D(61));
    $pdo->prepare('INSERT INTO booking_rooms (booking_id, individual_room_id) VALUES (?, ?)')->execute([$b12, $p2]);
    $res = $clash([['scope' => 'room', 'room_ids' => [$p1, $p2], 'type_id' => $T3, 'label' => 'Pair', 'nights' => [$D(60), $D(61)]]]);
    check($res['targets'][0]['booked'] === [$D(60)] && $res['targets'][0]['free'] === [$D(61)], 'a joined room with a booking on one of its rooms is booked for both', json_encode($res['targets'][0]));
    $bk('ZZC-13', $T3, null, $D(62), $D(63));
    $res = $clash([['scope' => 'room', 'room_ids' => [$p1, $p2], 'type_id' => $T3, 'label' => 'Pair', 'nights' => [$D(62)]]]);
    check($res['targets'][0]['booked'] === [$D(62)], 'the only pair of a joined type cannot be blocked while a joined booking with no room needs it', json_encode($res['targets'][0]));

    echo "== Maintenance nights: PHP rule = the SQL rule ==\n";
    $cases = [['2030-01-05 14:00:00', '2030-01-08 10:00:00'], ['2030-01-05 00:00:00', '2030-01-06 00:00:00'], ['2030-01-05 23:00:00', '2030-01-06 00:00:01'], ['2030-01-31 09:00:00', '2030-02-02 00:00:00']];
    $okAll = true;
    foreach ($cases as [$s, $e]) {
        $row = $pdo->query("SELECT DATE('$s') AS a, DATE(DATE_SUB('$e', INTERVAL 1 SECOND)) AS b")->fetch(PDO::FETCH_ASSOC);
        if (rh_maint_job_nights($s, $e) !== rh_rnc_days($row['a'], $row['b'])) {
            $okAll = false;
            echo "  mismatch $s .. $e\n";
        }
    }
    check($okAll, 'rh_maint_job_nights matches DATE(start) .. DATE(end - 1 s) for 4 edge cases');
    check(rh_maint_status_is_open('planned') && rh_maint_status_is_open('in_progress') && rh_maint_status_is_open('') && rh_maint_status_is_open(null)
        && !rh_maint_status_is_open('completed') && !rh_maint_status_is_open('cancelled'), 'open job = status not completed / cancelled / verified (the availability rule)');

    echo "== Guards ==\n";
    $plain = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $threw = false;
    try {
        rh_room_nights_clash($plain, [$room($r1, [$D(10)])]);
    } catch (RuntimeException $e) {
        $threw = strpos($e->getMessage(), 'transaction') !== false;
    }
    check($threw, 'the check refuses to run outside a transaction (it is only valid after the room locks)');
    $res = rh_room_nights_clash($plain, [$room($r1, [$D(9)])], ['advisory' => true]);
    check(isset($res['targets'][0]), 'advisory (preview) mode runs without a transaction');
    $plan = rh_room_nights_lock_plan($pdo, [$room($r1, [$D(9)]), $typeT($T2, [$D(9)])]);
    check(in_array($T1, $plan['type_ids'], true) && in_array($T2, $plan['type_ids'], true) && in_array($r1, $plan['room_ids'], true) && in_array($r4, $plan['room_ids'], true),
        'the lock plan holds the rooms\' types, the type target and its rooms', json_encode($plan));
    rh_room_nights_take_locks($pdo, $plan);
    ok('the locks are taken inside the transaction');
} catch (Throwable $e) {
    bad('unexpected error', $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo->finalRollback();
}
check(clash_test_left_behind() === 0, 'nothing left behind (fixtures rolled back)');
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
