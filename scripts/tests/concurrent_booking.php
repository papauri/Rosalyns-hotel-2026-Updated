<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Two receptionists saving a booking at the same moment (owner question 2026-10-22). Usage: php scripts/tests/concurrent_booking.php
 * Two real PHP processes run admin/create-booking.php's save together (scripts/tests/concurrent_booking_child.php) with
 * normal COMMITTING connections, because a rolled-back harness cannot show a race. Everything runs on THROWAWAY room types,
 * rooms and joined pairs named CONCTEST (far-future dates); no real room type is touched or changed. Every row made here is
 * deleted again by id when the test ends (and leftovers of an aborted run are removed first). No schema changes.
 *   1. the LAST room of a plain type              -> exactly one booking, the other gets a plain "not available"
 *   2. a joined pair vs the rooms it is made of   -> exactly one wins, no physical room sold twice
 *   3. the same joined type booked twice at once  -> exactly one wins
 *   4. two joined types that share a member room  -> exactly one wins
 *   5. the same client_uuid sent twice at once (double click / refresh) -> one booking
 * Tests run on the live DB: stop and report if anything cannot be cleaned up.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$run = strtoupper(bin2hex(random_bytes(3)));
$childScript = $root . '/scripts/tests/concurrent_booking_child.php';
$dependents = ['booking_audit_log', 'booking_notes', 'booking_rooms', 'booking_status_log', 'booking_timeline_logs', 'booking_charges',
    'booking_date_adjustments', 'booking_packages', 'tentative_booking_log', 'guest_communication_log', 'booking_financial_audit'];
$made = ['types' => [], 'rooms' => [], 'combos' => []]; // ids of the throwaway rows, deleted by id
$snapRooms = []; $snapIr = [];

function cb_table_exists(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}
function cb_cleanup_bookings(PDO $pdo): void {
    global $dependents;
    $ids = $pdo->query("SELECT id FROM bookings WHERE guest_name LIKE 'CONCTEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($dependents as $t) {
            if (cb_table_exists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE booking_id IN ($in)"); }
        }
        $pdo->exec("DELETE FROM bookings WHERE id IN ($in)");
    }
}
/** Delete every CONCTEST booking (+ its rows), the throwaway pairs, rooms and types, and this run's log lines. */
function cb_cleanup(PDO $pdo, string $run = ''): void {
    global $made;
    cb_cleanup_bookings($pdo);
    $in = fn(array $a) => implode(',', array_map('intval', $a));
    if ($made['combos']) { $pdo->exec("DELETE FROM room_combinations WHERE id IN (" . $in($made['combos']) . ")"); $made['combos'] = []; }
    $pdo->exec("DELETE FROM room_combinations WHERE combined_name LIKE 'CONCTEST%'");
    if ($made['rooms']) { $pdo->exec("DELETE FROM individual_rooms WHERE id IN (" . $in($made['rooms']) . ")"); $made['rooms'] = []; }
    $pdo->exec("DELETE FROM individual_rooms WHERE room_number LIKE 'CT-%' AND room_type_id IN (SELECT id FROM rooms WHERE name LIKE 'CONCTEST%')");
    if ($made['types']) { $pdo->exec("DELETE FROM rooms WHERE id IN (" . $in($made['types']) . ")"); $made['types'] = []; }
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'CONCTEST%'");
    if ($run !== '') {
        try { $pdo->prepare("DELETE FROM system_event_log WHERE request_uri LIKE ?")->execute(['%conctest=' . $run . '%']); } catch (Throwable $e) { /* table optional */ }
    }
}
register_shutdown_function(function () use ($pdo, $run) { try { cb_cleanup($pdo, $run); } catch (Throwable $e) { echo "CLEANUP ERROR: " . $e->getMessage() . "\n"; } });
cb_cleanup($pdo); // leftovers of an aborted earlier run
foreach ($pdo->query("SELECT id, rooms_available FROM rooms")->fetchAll(PDO::FETCH_ASSOC) as $r) { $snapRooms[(int)$r['id']] = (int)$r['rooms_available']; }
foreach ($pdo->query("SELECT id, status FROM individual_rooms")->fetchAll(PDO::FETCH_ASSOC) as $r) { $snapIr[(int)$r['id']] = (string)$r['status']; }

/** A throwaway room type with $n physical rooms ($n = 0: a joined type that only exists as pairs). */
function cb_type(PDO $pdo, string $run, string $label, int $n): array {
    global $made;
    $pdo->prepare("INSERT INTO rooms (name, slug, description, price_per_night, max_guests, rooms_available, total_rooms, is_active, display_order, children_allowed)
        VALUES (?, ?, 'concurrency test room type - removed automatically', 100, ?, ?, ?, 1, 9999, 1)")
        ->execute(['CONCTEST ' . $run . ' ' . $label, 'conctest-' . strtolower($run) . '-' . strtolower($label), $n > 0 ? 2 : 4, max($n, 1), max($n, 1)]);
    $tid = (int)$pdo->lastInsertId();
    $made['types'][] = $tid;
    $rooms = [];
    for ($i = 1; $i <= $n; $i++) {
        $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) VALUES (?, ?, ?, 'available', 1)")
            ->execute([$tid, 'CT-' . $run . '-' . $label . $i, 'CONCTEST ' . $label . $i]);
        $made['rooms'][] = $rooms[] = (int)$pdo->lastInsertId();
    }
    return ['id' => $tid, 'rooms' => $rooms];
}
function cb_pair(PDO $pdo, string $run, string $label, int $joinedType, int $a, int $b): int {
    global $made;
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, price_override, max_guests_combined, is_active, notes)
        VALUES (?, ?, ?, ?, NULL, 4, 1, 'concurrency test - removed automatically')")->execute(['CONCTEST ' . $run . ' ' . $label, $joinedType, $a, $b]);
    return $made['combos'][] = (int)$pdo->lastInsertId();
}

/** Start the children together, wait for them, return their parsed results. */
function cb_race(array $posts, string $run): array {
    global $childScript;
    $start = microtime(true) + 2.5; // the start line is shared; PHP start-up and sign-in happen before it
    $procs = [];
    foreach ($posts as $tag => $post) {
        $cfg = base64_encode(json_encode(['post' => $post, 'start_at' => $start, 'tag' => $tag, 'run' => $run]));
        $p = proc_open([PHP_BINARY, $childScript, $cfg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$tag] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as $tag => [$p, $pipes]) {
        $text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        $res = ['tag' => $tag, 'flash' => '', 'alerts' => [], 'raw' => substr($text, -400), 'ran' => false];
        if (preg_match('/@@RESULT@@(.*)$/m', $text, $m) && is_array($j = json_decode($m[1], true))) { $res = $j + ['raw' => '', 'ran' => true]; }
        $out[$tag] = $res;
    }
    return $out;
}
function cb_post(string $name, string $ci, string $co, array $roomTypeIds, int $guests, string $uuid = ''): array {
    $p = ['create_booking' => '1', 'guest_name' => $name, 'guest_email' => 'conctest@example.invalid', 'guest_phone' => '+265000000002',
        'number_of_guests' => (string)$guests, 'child_guests' => '0', 'check_in_date' => $ci, 'check_out_date' => $co,
        'booking_status' => 'pending', 'auto_assign_room' => '1',
        'room_line_room_id' => [], 'room_line_qty' => [], 'room_line_occupancy' => [], 'room_line_override' => []];
    foreach ($roomTypeIds as $rt) { $p['room_line_room_id'][] = (string)$rt; $p['room_line_qty'][] = '1'; $p['room_line_occupancy'][] = 'double'; $p['room_line_override'][] = ''; }
    if ($uuid !== '') { $p['client_uuid'] = $uuid; }
    return $p;
}
function cb_rows(PDO $pdo, string $name): array {
    $st = $pdo->prepare("SELECT id, room_id, individual_room_id, status FROM bookings WHERE guest_name = ? ORDER BY id");
    $st->execute([$name]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cb_plain_msg(array $r): bool {
    return $r['flash'] === '' && (bool)preg_match('/no longer|not available|unavailable|remain|only \d+|already|fully booked|someone else/i', implode(' ', $r['alerts']));
}
/** Run 3 rounds of A vs B; $want = [rooms A needs saved if it wins, rooms B needs saved if it wins]; $physical = room ids that may never be held twice. */
function cb_rounds(PDO $pdo, string $run, string $tag, string $ci, string $co, array $typesA, array $typesB, array $physical): void {
    for ($round = 1; $round <= 3; $round++) {
        $a = "CONCTEST $run $tag$round A"; $b = "CONCTEST $run $tag$round B";
        $res = cb_race(['A' => cb_post($a, $ci, $co, $typesA, 2), 'B' => cb_post($b, $ci, $co, $typesB, 2)], $run);
        $rowsA = cb_rows($pdo, $a); $rowsB = cb_rows($pdo, $b);
        $aWon = count($rowsA) === count($typesA); $bWon = count($rowsB) === count($typesB);
        ok("$tag round $round: both processes ran the page", $res['A']['ran'] && $res['B']['ran'], json_encode([$res['A']['raw'], $res['B']['raw']]));
        ok("$tag round $round: exactly ONE of the two bookings was saved (nothing half-saved)", ($aWon xor $bWon) && count($rowsA) + count($rowsB) === ($aWon ? count($typesA) : count($typesB)),
            'A=' . count($rowsA) . ' B=' . count($rowsB) . ' ' . json_encode([$res['A']['alerts'], $res['B']['alerts']]));
        $loser = $aWon ? $res['B'] : $res['A'];
        ok("$tag round $round: the other gets a plain 'not available' message (no raw database error)", cb_plain_msg($loser), json_encode(array_merge($loser['alerts'], [$loser['flash']])));
        if ($physical) {
            $ph = implode(',', array_map('intval', $physical));
            $held = $pdo->query("SELECT individual_room_id, COUNT(*) n FROM booking_rooms WHERE released_at IS NULL AND individual_room_id IN ($ph) AND booking_id IN
                (SELECT id FROM bookings WHERE guest_name LIKE " . $pdo->quote("CONCTEST $run $tag$round%") . " AND status IN ('pending','tentative','confirmed','checked-in')) GROUP BY individual_room_id HAVING n > 1");
            ok("$tag round $round: no physical room is held by two bookings", $held->fetchAll() === []);
        }
        $pdo->exec("DELETE FROM bookings WHERE guest_name IN (" . $pdo->quote($a) . "," . $pdo->quote($b) . ")");
    }
}

$ci = date('Y-m-d', strtotime('+800 day'));
$co = date('Y-m-d', strtotime('+802 day'));

echo "== 1. the last room of a plain type, two receptionists at once ==\n";
$P = cb_type($pdo, $run, 'P', 1);
ok('exactly one room is free before the race', (int)(checkRoomAvailability($P['id'], $ci, $co)['remaining_rooms'] ?? -1) === 1);
cb_rounds($pdo, $run, 'plain', $ci, $co, [$P['id']], [$P['id']], []);

echo "== 2 + 3. a joined pair vs the rooms it is made of, and the same pair twice ==\n";
$CA = cb_type($pdo, $run, 'CA', 2);
$JA = cb_type($pdo, $run, 'JA', 0);
cb_pair($pdo, $run, 'JA pair', $JA['id'], $CA['rooms'][0], $CA['rooms'][1]);
$avC = checkRoomAvailability($CA['id'], $ci, $co); $avJ = checkRoomAvailability($JA['id'], $ci, $co);
ok('before the race the pair and its two rooms are free', !empty($avJ['available']) && (int)($avC['remaining_rooms'] ?? 0) === 2, json_encode([$avC['remaining_rooms'] ?? null, $avJ['remaining_rooms'] ?? null, $avJ['error'] ?? null]));
cb_rounds($pdo, $run, 'pairVsRooms', $ci, $co, [$CA['id'], $CA['id']], [$JA['id']], $CA['rooms']);
cb_rounds($pdo, $run, 'pairTwice', $ci, $co, [$JA['id']], [$JA['id']], $CA['rooms']);

echo "== 4. two joined types that share a member room ==\n";
$CB = cb_type($pdo, $run, 'CB', 3);
$JB1 = cb_type($pdo, $run, 'JB1', 0);
$JB2 = cb_type($pdo, $run, 'JB2', 0);
cb_pair($pdo, $run, 'JB1 pair', $JB1['id'], $CB['rooms'][0], $CB['rooms'][1]);
cb_pair($pdo, $run, 'JB2 pair', $JB2['id'], $CB['rooms'][1], $CB['rooms'][2]);
ok('before the race both pairs look free (they share the middle room)', !empty(checkRoomAvailability($JB1['id'], $ci, $co)['available']) && !empty(checkRoomAvailability($JB2['id'], $ci, $co)['available']));
cb_rounds($pdo, $run, 'pairVsPair', $ci, $co, [$JB1['id']], [$JB2['id']], $CB['rooms']);

echo "== 5. the same booking sent twice at once (double click / refresh) ==\n";
$ci3 = date('Y-m-d', strtotime('+820 day'));
$co3 = date('Y-m-d', strtotime('+822 day'));
$D = cb_type($pdo, $run, 'D', 2);
$uuid = bin2hex(random_bytes(16));
$name3 = "CONCTEST $run DOUBLE";
$res = cb_race(['A' => cb_post($name3, $ci3, $co3, [$D['id']], 2, $uuid), 'B' => cb_post($name3, $ci3, $co3, [$D['id']], 2, $uuid)], $run);
$rows3 = cb_rows($pdo, $name3);
ok('both submissions ran', $res['A']['ran'] && $res['B']['ran'], json_encode([$res['A']['raw'], $res['B']['raw']]));
ok('only ONE booking exists for the double submission', count($rows3) === 1, 'rows=' . count($rows3));
$ref3 = (string)($pdo->query('SELECT booking_reference FROM bookings WHERE guest_name = ' . $pdo->quote($name3))->fetchColumn());
ok('both submissions end on that one booking (same reference, no error shown)', $ref3 !== '' && $res['A']['flash'] !== '' && $res['B']['flash'] !== '' && strpos($res['A']['flash'], $ref3) !== false && strpos($res['B']['flash'], $ref3) !== false && !$res['A']['alerts'] && !$res['B']['alerts'],
    json_encode([$ref3, $res['A']['flash'], $res['B']['flash'], $res['A']['alerts'], $res['B']['alerts']]));

// ── clean-up check ───────────────────────────────────────────────────────────
cb_cleanup($pdo, $run);
$left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE guest_name LIKE 'CONCTEST%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM room_combinations WHERE combined_name LIKE 'CONCTEST%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE name LIKE 'CONCTEST%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE room_number LIKE 'CT-%'")->fetchColumn();
ok('everything the test created has been removed', $left === 0, "left=$left");
$drift = 0;
foreach ($pdo->query("SELECT id, rooms_available FROM rooms")->fetchAll(PDO::FETCH_ASSOC) as $r) { if (($snapRooms[(int)$r['id']] ?? null) !== (int)$r['rooms_available']) { $drift++; } }
foreach ($pdo->query("SELECT id, status FROM individual_rooms")->fetchAll(PDO::FETCH_ASSOC) as $r) { if (($snapIr[(int)$r['id']] ?? null) !== (string)$r['status']) { $drift++; } }
ok('real room counters and room statuses were never changed', $drift === 0, "changed=$drift");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
