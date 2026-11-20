<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Calendar: Room Timeline + Month view shared data (admin/includes/calendar-data.php). Usage (repo root, TEST HOTEL):
 *   php scripts/tests/calendar_timeline_test.php
 * SELECT-only: nothing is written. Cases:
 *  (a) the Month view's shared loader gives the same booking -> lane -> night map, lanes, blocks and night statuses as
 *      the old in-page Month code (frozen below as the oracle), this month and next
 *  (b) a joined booking is drawn once, on room A's lane (pure builders, synthetic pair)
 *  (c) a booking with no room sits on its type's Unassigned lane (rt_<type>), timeline lanes = []
 *  (d) an in-house guest past check-out extends to the effective check-out (live row when there is one + synthetic)
 *  (e) deleted and cancelled bookings are absent
 *  (f) occupancy / arrivals / departures for sample days match direct COUNT queries
 *  (g) the range API answers 401 without a session; 403 code path without the calendar permission
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/admin/includes/calendar-data.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

/**
 * Oracle: the Month view's data block exactly as it was in admin/calendar.php before the shared loader
 * (lanes, blocks grouped per lane, live bookings per night per lane). Frozen here; do not "fix" it.
 */
function cal_legacy_month(PDO $pdo, int $currentYear, int $currentMonth): array {
    $stmt = $pdo->query("
        SELECT ir.*, r.name as room_type_name, r.price_per_night, r.slug as room_type_slug
        FROM individual_rooms ir
        INNER JOIN rooms r ON ir.room_type_id = r.id
        WHERE ir.is_active = 1 AND r.is_active = 1
        ORDER BY r.name, ir.display_order ASC, ir.room_number ASC
    ");
    $individualRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $laneOfRoom = [];
    $laneRoomIds = [];
    $pairsByA = [];
    foreach (rh_joined_only_pairs(true) as $jp) {
        $pairsByA[$jp['room_a_id']] = $jp;
    }
    $rowsById = [];
    foreach ($individualRooms as $irRow) {
        $rowsById[(int)$irRow['id']] = $irRow;
    }
    $lanes = [];
    foreach ($individualRooms as $irRow) {
        $rid = (int)$irRow['id'];
        $laneOfRoom[$rid] = $laneOfRoom[$rid] ?? $rid;
        $laneRoomIds[$laneOfRoom[$rid]] = $laneRoomIds[$laneOfRoom[$rid]] ?? [$rid];
        if (isset($pairsByA[$rid]) && isset($rowsById[$pairsByA[$rid]['room_b_id']])) {
            $jp = $pairsByA[$rid];
            $bRow = $rowsById[$jp['room_b_id']];
            $laneOfRoom[$jp['room_b_id']] = $rid;
            $laneRoomIds[$rid] = [$rid, $jp['room_b_id']];
            $irRow['room_number'] = $jp['label'];
            $irRow['room_name'] = (string)$jp['combined_name'];
            $irRow['status'] = rh_joined_pair_status((string)$irRow['status'], (string)$bRow['status']);
            $floorsPair = array_values(array_unique(array_filter([trim((string)($irRow['floor'] ?? '')), trim((string)($bRow['floor'] ?? ''))], static fn($v) => $v !== '')));
            $irRow['floor'] = implode(' / ', $floorsPair);
            if ($jp['price_override'] !== null && $jp['price_override'] !== '') {
                $irRow['price_per_night'] = $jp['price_override'];
            }
            $irRow['is_joined_lane'] = true;
        }
        $lanes[$rid] = $irRow;
    }
    $individualRooms = array_values(array_filter($lanes, static function ($row) use ($laneOfRoom) {
        return $laneOfRoom[(int)$row['id']] === (int)$row['id'];
    }));
    $allFloors = [];
    foreach ($individualRooms as $ir) {
        $f = trim($ir['floor'] ?? '');
        if ($f !== '' && !in_array($f, $allFloors, true)) {
            $allFloors[] = $f;
        }
    }
    natsort($allFloors);
    $allFloors = array_values($allFloors);

    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate = date('Y-m-t', strtotime($startDate));
    $blockedDates = getBlockedDates(null, $startDate, $endDate);
    $blockedDatesByDate = [];
    $blockLaneSeen = [];
    foreach ($blockedDates as $blocked) {
        $dateKey = $blocked['block_date'];
        if (!isset($blockedDatesByDate[$dateKey])) {
            $blockedDatesByDate[$dateKey] = [];
        }
        if (($blocked['block_scope'] ?? 'type') === 'individual') {
            $blockedRid = (int)($blocked['individual_room_id'] ?? 0);
            $lane = $laneOfRoom[$blockedRid] ?? $blockedRid;
            $laneKeySeen = $lane . '|' . $dateKey;
            if ($lane !== $blockedRid || count($laneRoomIds[$lane] ?? []) > 1) {
                if (isset($blockLaneSeen[$laneKeySeen])) {
                    continue;
                }
                $blockLaneSeen[$laneKeySeen] = true;
                $blocked['individual_room_id'] = $lane;
            }
        }
        $blockedDatesByDate[$dateKey][] = $blocked;
    }

    $effCo = rh_effective_checkout_sql('b');
    $stmt = $pdo->prepare("
        SELECT b.*, {$effCo} AS effective_check_out, r.name as room_name, r.id as room_id, r.price_per_night,
               ir.id as individual_room_id, ir.room_number as individual_room_number,
               ir.room_name as individual_room_name, ir.floor as individual_room_floor,
               ir.status as individual_room_status
        FROM bookings b
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        WHERE b.deleted_at IS NULL AND b.status IN ('pending', 'tentative', 'confirmed', 'checked-in')
        AND (
            (b.check_in_date <= :end_date AND {$effCo} >= :start_date)
        )
        ORDER BY b.check_in_date ASC, r.name ASC, ir.room_number ASC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $heldRooms = [];
    if ($bookings) {
        $ids = array_map('intval', array_column($bookings, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $hr = $pdo->prepare("SELECT booking_id, individual_room_id FROM booking_rooms WHERE released_at IS NULL AND booking_id IN ($in)");
        $hr->execute($ids);
        foreach ($hr->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $heldRooms[(int)$row['booking_id']][(int)$row['individual_room_id']] = true;
        }
    }
    $bookingsByDate = [];
    foreach ($bookings as $booking) {
        $checkIn = new DateTime($booking['check_in_date']);
        $checkOut = new DateTime($booking['effective_check_out'] ?? $booking['check_out_date']);
        $roomIdsHeld = $heldRooms[(int)$booking['id']] ?? [];
        if (!empty($booking['individual_room_id'])) {
            $roomIdsHeld[(int)$booking['individual_room_id']] = true;
        }
        $roomKeys = $roomIdsHeld
            ? array_values(array_unique(array_map(static fn ($rid) => 'ir_' . ($laneOfRoom[$rid] ?? $rid), array_keys($roomIdsHeld))))
            : ['rt_' . $booking['room_id']];
        $booking['pair_label'] = count($roomIdsHeld) > 1 ? getBookingRoomLabel((int)$booking['id']) : '';
        $currentDate = clone $checkIn;
        while ($currentDate < $checkOut) {
            $dateKey = $currentDate->format('Y-m-d');
            foreach ($roomKeys as $roomKey) {
                $bookingsByDate[$dateKey][$roomKey][] = $booking;
            }
            $currentDate->modify('+1 day');
        }
    }
    return compact('individualRooms', 'laneOfRoom', 'laneRoomIds', 'allFloors', 'blockedDatesByDate', 'bookings', 'bookingsByDate');
}

/** Comparable shape of a night map: date => lane key => [booking id => pair label]. */
function cal_night_shape(array $bookingsByDate): array {
    $out = [];
    foreach ($bookingsByDate as $d => $lanes) {
        foreach ($lanes as $k => $bs) {
            foreach ($bs as $b) {
                $out[$d][$k][(int)$b['id']] = (string)($b['pair_label'] ?? '');
            }
            ksort($out[$d][$k]);
        }
        ksort($out[$d]);
    }
    ksort($out);
    return $out;
}
function cal_lane_shape(array $rooms): array {
    return array_map(static fn ($r) => [(int)$r['id'], (string)$r['room_number'], (string)$r['room_name'], (string)$r['status'], (string)($r['floor'] ?? '')], $rooms);
}
function cal_block_shape(array $byDate): array {
    $out = [];
    foreach ($byDate as $d => $bl) {
        foreach ($bl as $b) {
            $out[$d][] = [$b['block_scope'] ?? 'type', (int)($b['room_id'] ?? 0), (int)($b['individual_room_id'] ?? 0), (string)($b['block_type'] ?? '')];
        }
    }
    ksort($out);
    return $out;
}

$today = date('Y-m-d');
$live = ['pending', 'tentative', 'confirmed', 'checked-in'];

// ---------------------------------------------------------------------------------------------- (a)
echo "\n(a) Month view: shared loader == old in-page code\n";
$monthsChecked = 0;
$nightsSeen = 0;
foreach ([0, 1] as $plus) {
    $ts = strtotime(date('Y-m-01') . " +$plus month");
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $label = sprintf('%04d-%02d', $y, $m);
    $old = cal_legacy_month($pdo, $y, $m);
    $start = sprintf('%04d-%02d-01', $y, $m);
    $new = rh_calendar_load($pdo, $start, date('Y-m-t', strtotime($start)));
    ok("$label: no loader error", $new['error'] === null, (string)$new['error']);
    ok("$label: same lanes (id, number, name, status, floor) in the same order", cal_lane_shape($old['individualRooms']) === cal_lane_shape($new['rooms']));
    ok("$label: same floors", $old['allFloors'] === $new['floors']);
    ok("$label: same room -> lane map", $old['laneOfRoom'] == $new['laneOfRoom'] && $old['laneRoomIds'] == $new['laneRoomIds']);
    ok("$label: same bookings in the same order", array_column($old['bookings'], 'id') === array_column($new['bookings'], 'id'));
    $oldN = cal_night_shape($old['bookingsByDate']);
    $newN = cal_night_shape($new['bookingsByDate']);
    ok("$label: same booking -> lane -> night map (incl. pair labels)", $oldN === $newN, json_encode(['old' => count($oldN), 'new' => count($newN)]));
    ok("$label: same blocks per night per lane", cal_block_shape($old['blockedDatesByDate']) === cal_block_shape($new['blockedDatesByDate']));
    $statusSame = true;
    $dim = (int)date('t', strtotime($start));
    foreach ($new['rooms'] as $r) {
        for ($d = 1; $d <= $dim; $d++) {
            $k = sprintf('%04d-%02d-%02d', $y, $m, $d);
            if (getTimelineAwareRoomStatus($r, $k, $new['bookingsByDate']) !== getTimelineAwareRoomStatus($r, $k, $old['bookingsByDate'])) {
                $statusSame = false;
            }
        }
    }
    ok("$label: same night status per room per day", $statusSame);
    $monthsChecked++;
    $nightsSeen += count($oldN);
}
ok('(a) compared real data (at least one booked night across the two months)', $nightsSeen > 0, "nights=$nightsSeen");

// ---------------------------------------------------------------------------------------------- (b)
echo "\n(b) Joined booking drawn once, on room A's lane\n";
$synthRooms = [
    ['id' => 9001, 'room_number' => '9A', 'room_name' => 'Nine A', 'status' => 'available', 'floor' => '9', 'room_type_id' => 77, 'price_per_night' => 100],
    ['id' => 9002, 'room_number' => '9B', 'room_name' => 'Nine B', 'status' => 'available', 'floor' => '9', 'room_type_id' => 77, 'price_per_night' => 100],
    ['id' => 9003, 'room_number' => '9C', 'room_name' => 'Nine C', 'status' => 'available', 'floor' => '9', 'room_type_id' => 78, 'price_per_night' => 80],
];
$synthPairs = [['room_a_id' => 9001, 'room_b_id' => 9002, 'label' => '9A + 9B', 'combined_name' => 'Nine Suite', 'price_override' => null]];
$L = rh_cal_build_lanes($synthRooms, $synthPairs);
ok('pair: two rooms become one lane (3 rooms -> 2 lanes)', count($L['rooms']) === 2);
ok('pair: lane carries the pair label', ($L['rooms'][0]['room_number'] ?? '') === '9A + 9B');
ok('pair: room B maps to room A\'s lane', ($L['laneOfRoom'][9002] ?? 0) === 9001 && $L['laneRoomIds'][9001] === [9001, 9002]);
$jb = ['id' => 5001, 'room_id' => 77, 'individual_room_id' => 9001, 'check_in_date' => '2031-01-10', 'check_out_date' => '2031-01-13',
    'effective_check_out' => '2031-01-13', 'status' => 'confirmed'];
$held = [5001 => [9001 => true, 9002 => true]];
$keys = rh_cal_booking_lane_keys($jb, rh_cal_booking_room_ids($jb, $held), $L['laneOfRoom']);
ok('joined booking: one lane key ir_9001', $keys === ['ir_9001'], json_encode($keys));
$nights = rh_cal_index_nights([$jb], $held, $L['laneOfRoom'], [5001 => '9A + 9B']);
$onceEachNight = count($nights) === 3;
foreach ($nights as $d => $ln) {
    if (array_keys($ln) !== ['ir_9001'] || count($ln['ir_9001']) !== 1 || $ln['ir_9001'][0]['pair_label'] !== '9A + 9B') {
        $onceEachNight = false;
    }
}
ok('joined booking: 3 nights, each once on ir_9001 with the pair label (checkout day free)', $onceEachNight && !isset($nights['2031-01-13']));
$jbFull = $jb + ['booking_reference' => 'T-1', 'guest_name' => 'Test Guest', 'number_of_guests' => 2, 'total_amount' => 300,
    'amount_paid' => 0, 'amount_due' => 300, 'payment_status' => 'unpaid', 'room_name' => 'Nine Suite', 'individual_room_number' => '9A'];
$item = rh_cal_booking_item($jbFull, $held, $L['laneOfRoom'], $L['laneRoomIds'], [5001 => '9A + 9B'], [], '2031-01-01', 'MWK');
ok('joined booking item: lanes [9001], joined, not a group, label 9A + 9B', $item['lanes'] === [9001] && $item['joined'] === true && $item['group'] === false && $item['room_label'] === '9A + 9B', json_encode([$item['lanes'], $item['joined'], $item['group'], $item['room_label']]));
// Two separate rooms (not a pair) held by one booking: drawn on both lanes, flagged as a group.
$gb = ['id' => 5002, 'room_id' => 77, 'individual_room_id' => 9001] + $jb;
$gb['id'] = 5002;
$heldG = [5002 => [9001 => true, 9003 => true]];
$keysG = rh_cal_booking_lane_keys($gb, rh_cal_booking_room_ids($gb, $heldG), $L['laneOfRoom']);
sort($keysG);
ok('two separate rooms: drawn on both lanes', $keysG === ['ir_9001', 'ir_9003'], json_encode($keysG));

// ---------------------------------------------------------------------------------------------- (c)
echo "\n(c) Unassigned booking on its type's Unassigned lane\n";
$ub = ['id' => 5003, 'room_id' => 78, 'individual_room_id' => null, 'check_in_date' => '2031-02-01', 'check_out_date' => '2031-02-03',
    'effective_check_out' => '2031-02-03', 'status' => 'confirmed'];
$keysU = rh_cal_booking_lane_keys($ub, rh_cal_booking_room_ids($ub, []), $L['laneOfRoom']);
ok('no room: lane key rt_78', $keysU === ['rt_78'], json_encode($keysU));
$nU = rh_cal_index_nights([$ub], [], $L['laneOfRoom'], []);
ok('no room: 2 nights on rt_78 only', array_keys($nU) === ['2031-02-01', '2031-02-02'] && array_keys($nU['2031-02-01']) === ['rt_78']);
$itemU = rh_cal_booking_item($ub + ['booking_reference' => 'T-3', 'guest_name' => 'U', 'total_amount' => 0, 'amount_paid' => 0, 'amount_due' => 0], [], $L['laneOfRoom'], $L['laneRoomIds'], [], [], '2031-01-01', 'MWK');
ok('no room: timeline item has no lanes and type 78', $itemU['lanes'] === [] && $itemU['type_id'] === 78);
// Live: every unassigned live booking in the next 31 days is on an rt_ lane and has no timeline lane.
$tl = rh_cal_timeline_payload($pdo, $today, 31, ['today' => $today]);
ok('payload for 31 days has no error', $tl['ok'] === true && $tl['error'] === null, (string)$tl['error']);
$unLive = $pdo->prepare("SELECT b.id FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('pending','tentative','confirmed','checked-in')
    AND (b.individual_room_id IS NULL OR b.individual_room_id = 0)
    AND NOT EXISTS (SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.released_at IS NULL)
    AND b.check_in_date <= ? AND " . rh_effective_checkout_sql('b') . " >= ?");
$unLive->execute([$tl['end'], $today]);
$unIds = array_map('intval', $unLive->fetchAll(PDO::FETCH_COLUMN));
$itemsById = [];
foreach ($tl['bookings'] as $bi) {
    $itemsById[$bi['id']] = $bi;
}
$unOk = true;
foreach ($unIds as $uid) {
    if (!isset($itemsById[$uid]) || $itemsById[$uid]['lanes'] !== []) {
        $unOk = false;
    }
}
ok('live: every unassigned booking in range is in the payload with no lane (' . count($unIds) . ' found)', $unOk);

// ---------------------------------------------------------------------------------------------- (d)
echo "\n(d) In-house past check-out extends to the effective check-out\n";
$od = ['id' => 5004, 'room_id' => 78, 'individual_room_id' => 9003, 'check_in_date' => '2031-03-01', 'check_out_date' => '2031-03-03',
    'effective_check_out' => '2031-03-06', 'status' => 'checked-in'];
$nD = rh_cal_index_nights([$od], [], $L['laneOfRoom'], []);
ok('synthetic: nights run to the effective check-out minus one (5 nights, 03-06 free)', array_keys($nD) === ['2031-03-01', '2031-03-02', '2031-03-03', '2031-03-04', '2031-03-05']);
ok('synthetic: departure is today for an overdue guest', rh_cal_departure_date($od, '2031-03-05') === '2031-03-05' && rh_cal_departure_date($od, '2031-03-02') === '2031-03-03');
$ovQ = $pdo->prepare("SELECT id FROM bookings WHERE deleted_at IS NULL AND status = 'checked-in' AND check_out_date < ? ORDER BY id LIMIT 5");
$ovQ->execute([$today]);
$ovIds = array_map('intval', $ovQ->fetchAll(PDO::FETCH_COLUMN));
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
if ($ovIds) {
    $ovOk = true;
    foreach ($ovIds as $oid) {
        $it = $itemsById[$oid] ?? null;
        if (!$it || $it['eco'] !== $tomorrow || $it['dep'] !== $today || $it['overdue'] !== true) {
            $ovOk = false;
        }
    }
    ok('live: ' . count($ovIds) . ' overdue in-house booking(s) held to tomorrow, due out today, flagged overdue', $ovOk);
} else {
    echo "[INFO] live: no overdue in-house booking today; the synthetic case above covers the rule\n";
}

// ---------------------------------------------------------------------------------------------- (e)
echo "\n(e) Deleted and cancelled bookings are absent\n";
$deadQ = $pdo->query("SELECT id, check_in_date, check_out_date FROM bookings
    WHERE (deleted_at IS NOT NULL OR status IN ('cancelled', 'expired', 'no-show', 'checked-out')) AND check_out_date > check_in_date
    ORDER BY (deleted_at IS NOT NULL) DESC, id DESC LIMIT 20");
$dead = $deadQ->fetchAll(PDO::FETCH_ASSOC);
ok('(e) found deleted / cancelled bookings to check (' . count($dead) . ')', count($dead) > 0);
$deadOk = true;
$deadDetail = '';
foreach ($dead as $d) {
    $rows = rh_cal_fetch_bookings($pdo, (string)$d['check_in_date'], (string)$d['check_out_date']);
    if (in_array((int)$d['id'], array_map('intval', array_column($rows, 'id')), true)) {
        $deadOk = false;
        $deadDetail = 'booking ' . $d['id'];
    }
}
ok('(e) none of them is in the calendar data for its own dates', $deadOk, $deadDetail);
$liveInTl = true;
foreach ($tl['bookings'] as $bi) {
    if (!in_array($bi['status'], $live, true)) {
        $liveInTl = false;
    }
}
ok('(e) timeline payload holds live statuses only', $liveInTl && count($tl['bookings']) > 0, 'bookings=' . count($tl['bookings']));

// ---------------------------------------------------------------------------------------------- (f)
echo "\n(f) Per-day figures match direct COUNT queries\n";
$sample = [$today];
foreach ($tl['stats'] as $d => $s) {
    if ($s['arr'] > 0 && $d !== $today) { $sample[] = $d; break; }
}
foreach ($tl['stats'] as $d => $s) {
    if ($s['dep'] > 0 && !in_array($d, $sample, true)) { $sample[] = $d; break; }
}
$effCo = rh_effective_checkout_sql('b');
$lanesTotal = 0;
foreach ($tl['lanes'] as $ln) {
    if ($ln['status'] !== 'out_of_order') { $lanesTotal++; }
}
$laneOf = rh_calendar_load($pdo, $today, $today)['laneOfRoom'];
$anyNonZero = false;
foreach ($sample as $d) {
    $s = $tl['stats'][$d];
    $q = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status IN ('pending','tentative','confirmed') AND check_in_date = ?");
    $q->execute([$d]);
    $arr = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status IN ('pending','tentative','confirmed','checked-in')
        AND (check_out_date = ? OR (status = 'checked-in' AND check_out_date < ? AND ? = ?))");
    $q->execute([$d, $today, $d, $today]);
    $dep = (int)$q->fetchColumn();
    // Rooms with a stay that night: every room held (booking_rooms + primary pointer) by a live booking, as lanes;
    // plus one per live booking with no room; capped at the sellable total.
    $q = $pdo->prepare("SELECT DISTINCT x.rid FROM (
            SELECT br.individual_room_id AS rid FROM booking_rooms br JOIN bookings b ON b.id = br.booking_id
             WHERE br.released_at IS NULL AND b.deleted_at IS NULL AND b.status IN ('pending','tentative','confirmed','checked-in')
               AND b.check_in_date <= ? AND {$effCo} > ?
            UNION
            SELECT b.individual_room_id FROM bookings b
             WHERE b.individual_room_id IS NOT NULL AND b.individual_room_id > 0 AND b.deleted_at IS NULL
               AND b.status IN ('pending','tentative','confirmed','checked-in') AND b.check_in_date <= ? AND {$effCo} > ?
        ) x");
    $q->execute([$d, $d, $d, $d]);
    $laneSet = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (isset($laneOf[(int)$rid])) { $laneSet[$laneOf[(int)$rid]] = true; }
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('pending','tentative','confirmed','checked-in')
        AND (b.individual_room_id IS NULL OR b.individual_room_id = 0)
        AND NOT EXISTS (SELECT 1 FROM booking_rooms br WHERE br.booking_id = b.id AND br.released_at IS NULL)
        AND b.check_in_date <= ? AND {$effCo} > ?");
    $q->execute([$d, $d]);
    $booked = min($lanesTotal, count($laneSet) + (int)$q->fetchColumn());
    ok("$d: arrivals $s[arr] == COUNT $arr", $s['arr'] === $arr);
    ok("$d: departures $s[dep] == COUNT $dep", $s['dep'] === $dep);
    ok("$d: rooms booked $s[booked] / $s[total] == COUNT $booked / $lanesTotal", $s['booked'] === $booked && $s['total'] === $lanesTotal);
    if ($arr + $dep + $booked > 0) { $anyNonZero = true; }
}
ok('(f) at least one sampled figure is non-zero (real data compared)', $anyNonZero);

// ---------------------------------------------------------------------------------------------- (g)
echo "\n(g) Range API access\n";
$apiFile = $root . '/admin/api/calendar-timeline.php';
$runner = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["PHP_SELF"]="/admin/api/calendar-timeline.php"; $_GET=["start"=>"' . $today . '","days"=>"7"]; require ' . var_export($apiFile, true) . ';';
// proc_open with an argument list: no shell, so quoting is the same on Windows and Linux.
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$out = '';
if (is_resource($proc)) {
    $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
ok('no session: answers "Not authenticated" and no calendar data', strpos($out, 'Not authenticated') !== false && strpos($out, '"bookings"') === false, substr($out, 0, 200));
$src = (string)file_get_contents($apiFile);
$pPerm = strpos($src, "requireApiPermission('calendar')");
$pMod = strpos($src, "rhApiModuleGate('calendar.php')");
$pData = strpos($src, 'rh_cal_timeline_payload(');
ok('code path: calendar permission and module gate run before any data is read', $pPerm !== false && $pMod !== false && $pData !== false && $pPerm < $pData && $pMod < $pData);
$apiInit = (string)file_get_contents($root . '/admin/api/api-init.php');
ok('code path: requireApiPermission answers 403 when the user lacks it', (bool)preg_match('/function requireApiPermission.*?hasPermission\(\$userId, \$permissionKey\).*?api_json_forbidden\(\)/s', $apiInit));
require_once $root . '/admin/includes/permissions.php';
$noUser = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 100000 FROM admin_users")->fetchColumn();
ok('a user id that does not exist has no calendar permission', hasPermission($noUser, 'calendar') === false);
$canNone = rh_cal_timeline_can(0);
ok('timeline links: nobody signed in gets no actions', !in_array(true, $canNone, true));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
