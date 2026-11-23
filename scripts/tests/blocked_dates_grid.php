<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Blocked Dates calendar grid (admin/blocked-dates.php, action=grid_apply + Calendar / List views).
 * Usage (from the repo root): php scripts/tests/blocked_dates_grid.php
 *
 * Every page run is inside page_harness.php (one outer transaction that never commits, rolled back
 * at the end). Seed rows are written in that same transaction. Nothing reaches the database.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function run(array $cfg): array {
    global $h;
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/blocked-dates.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = (string)$out; return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
}
function grid($items, string $op = 'block', array $setup = [], array $verify = [], array $extra = []): array {
    return run(['post' => ['action' => 'grid_apply', 'op' => $op, 'items' => is_string($items) ? $items : json_encode($items), 'block_type' => $extra['block_type'] ?? 'maintenance', 'reason' => $extra['reason'] ?? $GLOBALS['tag']]
        , 'ajax' => true, 'setup' => $setup, 'verify' => $verify] + (isset($extra['as']) ? ['as_username' => $extra['as']] : []));
}
function msg(array $r): string { return strtolower((string)($r['json']['message'] ?? '')); }
function c(array $r, int $i = 0): int { return (int)($r['verify'][$i][0]['c'] ?? -1); }
function noPhpNoise(array $r): bool { return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b/', (string)$r['raw']); }

require_once $root . '/config/database.php';

$pairs = rh_joined_only_pairs(true);
$pairMembers = [];
foreach ($pairs as $p) { foreach ($p['room_ids'] as $x) { $pairMembers[$x] = true; } }
$rooms = $pdo->query("SELECT ir.id, ir.room_type_id FROM individual_rooms ir JOIN rooms r ON r.id = ir.room_type_id WHERE ir.is_active = 1 AND r.is_active = 1 ORDER BY ir.id")->fetchAll(PDO::FETCH_ASSOC);
$room = null;
foreach ($rooms as $rw) { if (!isset($pairMembers[(int)$rw['id']])) { $room = $rw; break; } }
if (!$room) { bad('Fixture: an active room that is not in a joined pair'); echo "\n$pass passed, $fail failed\n"; exit(1); }
$rid = (int)$room['id'];
$tid = (int)$room['room_type_id'];
$d1 = date('Y-m-d', strtotime('+400 days'));
$d2 = date('Y-m-d', strtotime('+401 days'));
$past = date('Y-m-d', strtotime('-2 days'));
$tag = 'ZZ grid test ' . bin2hex(random_bytes(3));
$cInd = "SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE reason = '$tag'";
$cType = "SELECT COUNT(*) AS c FROM blocked_dates WHERE reason = '$tag' AND room_id = $tid";
$cAll = "SELECT COUNT(*) AS c FROM blocked_dates WHERE reason = '$tag' AND room_id IS NULL";

echo "== Views ==\n";
$r = run(['method' => 'GET', 'post' => [], 'get' => [], 'grep' => ['id="bdgTable"', 'data-t="room"', 'data-t="type"', 'data-t="all"', 'id="bdgBlockBtn"', 'blocked-dates-grid.js']]);
check(!in_array(false, $r['found'] ?? [false], true) && noPhpNoise($r), 'Calendar view renders the grid, all three row kinds and the action bar', json_encode($r['found'] ?? $r['raw']));
$monthLabel = date('F Y', strtotime(substr($d1, 0, 7) . '-01'));
$r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => substr($d1, 0, 7)], 'grep' => [$monthLabel, 'data-d="' . $d1 . '"']]);
check(!in_array(false, $r['found'] ?? [false], true) && noPhpNoise($r), 'month=YYYY-MM shows that month', json_encode($r['found'] ?? []));
$r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => '2026-13'], 'grep' => [date('F Y')]]);
check(!empty($r['found'][date('F Y')]) && noPhpNoise($r), 'an invalid month falls back to this month');
$r = run(['method' => 'GET', 'post' => [], 'get' => ['view' => 'list', 'start_date' => 'garbage', 'end_date' => "2026-02-31' OR 1"], 'grep' => ['blocked-dates-filter-form', 'id="bdgTable"', 'value="' . date('Y-m-01') . '"']]);
check(!empty($r['found']['blocked-dates-filter-form']) && empty($r['found']['id="bdgTable"']) && !empty($r['found']['value="' . date('Y-m-01') . '"']) && noPhpNoise($r), 'List view renders; invalid filter dates fall back to this month', json_encode($r['found'] ?? []));
// Past nights are never selectable on the grid.
$r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => substr($past, 0, 7)], 'grep' => ['data-sel="1" data-t="room" data-id="' . $rid . '" data-d="' . $past . '"']]);
check(empty($r['found']['data-sel="1" data-t="room" data-id="' . $rid . '" data-d="' . $past . '"']), 'a past night is not selectable');
// A night already blocked shows as blocked on its row (seeded in the rolled-back transaction).
$seedInd = "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES ($rid, '$d1', 'maintenance', '$tag')";
$r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => substr($d1, 0, 7)], 'setup' => [$seedInd], 'grep' => ['data-t="room" data-id="' . $rid . '" data-d="' . $d1 . '" data-state="direct"']]);
check(!empty(array_values($r['found'] ?? [])[0]), 'a blocked room night is drawn as blocked on its own row');
$seedType = "INSERT INTO blocked_dates (room_id, block_date, block_type, reason) VALUES ($tid, '$d1', 'event', '$tag')";
$r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => substr($d1, 0, 7)], 'setup' => [$seedType], 'grep' => ['data-t="room" data-id="' . $rid . '" data-d="' . $d1 . '" data-state="inherited"', 'data-t="type" data-id="' . $tid . '" data-d="' . $d1 . '" data-state="direct"']]);
check(!in_array(false, $r['found'] ?? [false], true), 'a room-type block shows on the type row and as inherited on its rooms', json_encode($r['found'] ?? []));

echo "== Block ==\n";
$items = [['t' => 'room', 'id' => $rid, 'd' => [$d1, $d2]], ['t' => 'type', 'id' => $tid, 'd' => [$d1]], ['t' => 'all', 'id' => null, 'd' => [$d2]]];
$r = grid($items, 'block', [], [$cInd, $cType, $cAll, "SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE reason = '$tag' AND block_type = 'maintenance'"]);
check(($r['json']['success'] ?? false) === true && c($r, 0) === 2 && c($r, 1) === 1 && c($r, 2) === 1 && c($r, 3) === 2 && strpos(msg($r), 'blocked 4 night') !== false,
    'one save blocks a room (2 nights), its type (1) and all rooms (1) with the chosen type', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
check(!empty($r['rolled_back']) && empty($r['marker_lost']), 'block test rolled back');
// The message names each room and its nights (not "rows"): "Room 101 on 12 to 13 Dec 2026; All Deluxe rooms on 12 Dec 2026; All rooms on 13 Dec 2026".
$roomNo = (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = $rid")->fetchColumn();
$typeName = (string)$pdo->query("SELECT name FROM rooms WHERE id = $tid")->fetchColumn();
$roomText = (ctype_digit(substr(trim($roomNo), 0, 1)) ? 'room ' : '') . strtolower(trim($roomNo)); // "Room 101" / "Villa 2"
$t1 = strtotime($d1); $t2 = strtotime($d2);
$rangeText = date('Y-m', $t1) === date('Y-m', $t2) ? date('j', $t1) . ' to ' . date('j M Y', $t2)
    : (date('Y', $t1) === date('Y', $t2) ? date('j M', $t1) . ' to ' . date('j M Y', $t2) : date('j M Y', $t1) . ' to ' . date('j M Y', $t2));
$want = [$roomText . ' on ' . strtolower($rangeText), 'all ' . strtolower($typeName) . ' rooms on ' . strtolower(date('j M Y', $t1)), 'all rooms on ' . strtolower(date('j M Y', $t2))];
$miss = array_values(array_filter($want, static fn($w) => strpos(msg($r), $w) === false));
check(!$miss && strpos(msg($r), 'row') === false, 'the block message names each room and its nights, and never says "rows"', json_encode([$miss, $r['json']['message'] ?? null]));
// The older single-date form names the room and the date too.
$r = run(['post' => ['action' => 'block_date', 'block_scope' => 'individual', 'individual_room_id' => (string)$rid, 'block_date' => $d1, 'block_type' => 'maintenance', 'reason' => $tag], 'ajax' => true, 'verify' => [$cInd]]);
// The list form now saves through the calendar's engine (GRID_UNIFY_PLAN Phase 2), so it uses the calendar's words.
check(($r['json']['success'] ?? false) === true && c($r) === 1 && strpos(msg($r), 'blocked 1 night(s): ' . $roomText . ' on ' . strtolower(date('j M Y', $t1))) !== false, 'the single-date form says which room was blocked and on which date', json_encode($r['json'] ?? null));
$r = run(['post' => ['action' => 'block_multiple', 'block_scope' => 'type', 'room_id' => (string)$tid, 'dates' => json_encode([$d1, $d2]), 'block_type' => 'event', 'reason' => $tag], 'ajax' => true, 'verify' => [$cType]]);
check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), 'all ' . strtolower($typeName) . ' rooms on ' . strtolower($rangeText)) !== false, 'the several-dates form names the room type and the nights', json_encode($r['json'] ?? null));

$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1, $d2, $d1]]], 'block', [$seedInd], [$cInd, "SELECT block_type AS c FROM individual_room_blocked_dates WHERE individual_room_id = $rid AND block_date = '$d1'"], ['block_type' => 'event']);
check(($r['json']['success'] ?? false) === true && c($r) === 2 && ($r['verify'][1][0]['c'] ?? '') === 'event' && strpos(msg($r), 'blocked 1 night') !== false && strpos(msg($r), '1 night(s) were already blocked') !== false,
    're-blocking a blocked night updates it (no duplicate row), duplicates in the post are ignored', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));

// Joined room: use an existing active joined-only pair, or seed one (rolled back) from two rooms of one type.
$pairSeed = [];
if ($pairs) {
    $p = reset($pairs);
} else {
    $two = $pdo->query("SELECT room_type_id, MIN(id) AS a FROM individual_rooms WHERE is_active = 1 AND id <> $rid GROUP BY room_type_id HAVING COUNT(*) >= 2 ORDER BY room_type_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $p = null;
    if ($two) {
        $bId = (int)$pdo->query("SELECT MIN(id) FROM individual_rooms WHERE is_active = 1 AND room_type_id = " . (int)$two['room_type_id'] . " AND id NOT IN ($rid, " . (int)$two['a'] . ")")->fetchColumn();
        if ($bId > 0) {
            $p = ['room_a_id' => (int)$two['a'], 'room_b_id' => $bId];
            $pairSeed = ["INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, is_active) VALUES ('$tag pair', " . (int)$two['room_type_id'] . ", {$p['room_a_id']}, {$p['room_b_id']}, 1)"];
        }
    }
}
if ($p) {
    $cPair = "SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE reason = '$tag' AND individual_room_id IN ({$p['room_a_id']}, {$p['room_b_id']})";
    $r = grid([['t' => 'room', 'id' => $p['room_b_id'], 'd' => [$d1]]], 'block', $pairSeed, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), 'blocked 1 night') !== false, 'blocking a joined room (posted as either member) blocks both rooms, counted once', json_encode($r['json'] ?? null));
    $r = grid([['t' => 'room', 'id' => $p['room_a_id'], 'd' => [$d1]], ['t' => 'room', 'id' => $p['room_b_id'], 'd' => [$d1]]], 'block', $pairSeed, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), 'blocked 1 night') !== false, 'both members posted = one joined room target', json_encode($r['json'] ?? null));
    $r = run(['method' => 'GET', 'post' => [], 'get' => ['month' => substr($d1, 0, 7)], 'setup' => $pairSeed, 'grep' => ['data-t="room" data-id="' . $p['room_a_id'] . '"', 'data-t="room" data-id="' . $p['room_b_id'] . '"']]);
    check(!empty($r['found']['data-t="room" data-id="' . $p['room_a_id'] . '"']) && empty($r['found']['data-t="room" data-id="' . $p['room_b_id'] . '"']), 'a joined room is ONE row on the grid', json_encode($r['found'] ?? []));
    $sp = array_merge($pairSeed, ["INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES ({$p['room_a_id']}, '$d1', 'manual', '$tag'), ({$p['room_b_id']}, '$d1', 'manual', '$tag')"]);
    $r = grid([['t' => 'room', 'id' => $p['room_a_id'], 'd' => [$d1]]], 'unblock', $sp, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 0 && strpos(msg($r), 'unblocked 1 night') !== false, 'unblocking a joined room frees both rooms', json_encode($r['json'] ?? null));
    // Half a joined room blocked (old data): blocking it completes the pair and counts as newly blocked, not "already blocked".
    $half = array_merge($pairSeed, ["INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES ({$p['room_a_id']}, '$d1', 'manual', '$tag')"]);
    $r = grid([['t' => 'room', 'id' => $p['room_a_id'], 'd' => [$d1]]], 'block', $half, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), 'blocked 1 night') !== false && strpos(msg($r), 'already blocked') === false, 'a half-blocked joined room is completed and counted as newly blocked', json_encode($r['json'] ?? null));
    // Unblocking a half-blocked joined room removes the leftover half.
    $r = grid([['t' => 'room', 'id' => $p['room_b_id'], 'd' => [$d1]]], 'unblock', $half, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 0 && strpos(msg($r), 'unblocked 1 night') !== false, 'unblocking a half-blocked joined room clears it', json_encode($r['json'] ?? null));
    // Both members posted with the same past night: one row, so the past night is counted once.
    $r = grid([['t' => 'room', 'id' => $p['room_a_id'], 'd' => [$past, $d1]], ['t' => 'room', 'id' => $p['room_b_id'], 'd' => [$past]]], 'block', $pairSeed, [$cPair]);
    check(($r['json']['success'] ?? false) === true && c($r) === 2 && strpos(msg($r), ' 1 past night(s)') !== false, 'a joined room posted as both members counts a past night once', json_encode($r['json'] ?? null));
} else {
    echo "SKIP joined-room checks (no type with two spare rooms)\n";
}

echo "== Refusals (nothing written) ==\n";
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$past]]], 'block', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0 && strpos(msg($r), 'past nights') !== false, 'past nights are refused', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$past, $d1]]], 'block', [], [$cInd, "SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE reason = '$tag' AND block_date < CURDATE()"]);
check(($r['json']['success'] ?? false) === true && c($r) === 1 && c($r, 1) === 0 && strpos(msg($r), '1 past night(s) in the selection were skipped') !== false, 'a past night mixed into a selection is skipped and the message says so', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$past, $d1]], ['t' => 'room', 'id' => $rid, 'd' => [$past]]], 'block', [], [$cInd]);
check(($r['json']['success'] ?? false) === true && c($r) === 1 && strpos(msg($r), ' 1 past night(s)') !== false, 'the same past night posted twice for one row is counted once', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]], ['t' => 'type', 'id' => 99999999, 'd' => [$d1]]], 'block', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'an unknown room type refuses the whole save (the valid room is not blocked either)', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'block', ["UPDATE individual_rooms SET is_active = 0 WHERE id = $rid"], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0 && strpos(msg($r), 'inactive') !== false, 'an inactive room is refused with a clear message', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'delete', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'an unknown op is refused');
$r = grid('not json', 'block', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'a malformed selection is refused');
$r = grid([['t' => 'room', 'id' => $rid, 'd' => ['2026-02-31', 'x']]], 'block', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'invalid dates are refused');
$u = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($u) {
    $uid = (int)$u['id'];
    $deny = ["DELETE FROM user_permissions WHERE user_id = $uid AND permission_key IN ('blocked_dates','block_rooms')",
        "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES ($uid, 'blocked_dates', 1), ($uid, 'block_rooms', 0)"];
    $r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'block', $deny, [$cInd], ['as' => $u['username']]);
    check(($r['json']['success'] ?? true) === false && c($r) === 0 && strpos(msg($r), 'block rooms permission') !== false, 'without Block Rooms the save is refused', json_encode($r['json'] ?? null));
    $r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'unblock', array_merge($deny, [$seedInd]), [$cInd], ['as' => $u['username']]);
    check(($r['json']['success'] ?? true) === false && c($r) === 1, 'without Block Rooms an unblock is refused (block kept)', json_encode($r['json'] ?? null));
    $r = run(['method' => 'GET', 'post' => [], 'get' => [], 'setup' => $deny, 'as_username' => $u['username'], 'grep' => ['id="bdgTable"', 'data-sel="1"', 'id="bdgBlockBtn"', 'blocked-dates-grid.js']]);
    check(!empty($r['found']['id="bdgTable"']) && empty($r['found']['data-sel="1"']) && empty($r['found']['id="bdgBlockBtn"']) && empty($r['found']['blocked-dates-grid.js']), 'without Block Rooms the grid is view-only', json_encode($r['found'] ?? []));
}

echo "== Unblock ==\n";
$seedInd2 = "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES ($rid, '$d2', 'manual', '$tag')";
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1, $d2]]], 'unblock', [$seedInd, $seedInd2], [$cInd]);
check(($r['json']['success'] ?? false) === true && c($r) === 0 && strpos(msg($r), 'unblocked 2 night') !== false, 'unblocking removes the room\'s own blocks', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'unblock', [$seedType], [$cType]);
check(($r['json']['success'] ?? true) === false && c($r) === 1 && strpos(msg($r), 'still blocked by a room-type') !== false, 'a night blocked by the room type stays blocked when unblocking one room (and says so)', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'unblock', [$seedInd, $seedType], [$cInd, $cType]);
check(($r['json']['success'] ?? false) === true && c($r, 0) === 0 && c($r, 1) === 1 && strpos(msg($r), 'still blocked') !== false, 'unblock removes the room block but reports the type block still covers it', json_encode($r['json'] ?? null));
$r = grid([['t' => 'type', 'id' => $tid, 'd' => [$d1]]], 'unblock', [$seedType], [$cType]);
check(($r['json']['success'] ?? false) === true && c($r) === 0 && strpos(msg($r), 'still blocked') === false && strpos(msg($r), 'unblocked 1 night') !== false, 'unblocking the type row removes the type block (and does not call its own block a wider one)', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'unblock', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && strpos(msg($r), 'none of the selected nights were blocked') !== false && strpos(msg($r), $roomText . ' on ') !== false, 'unblocking open nights changes nothing and says which room and night', json_encode($r['json'] ?? null));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$past, $d1]]], 'unblock', [], [$cInd]);
check(($r['json']['success'] ?? true) === false && strpos(msg($r), 'none of the selected nights were blocked') !== false && strpos(msg($r), '1 past night(s) in the selection were skipped') !== false, 'unblocking open nights with a past night also says the past night was skipped', json_encode($r['json'] ?? null));

echo "== Logs: Staff activity + Booking History ==\n";
$cLogBlock = "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%$tag%'";
$cLogUnblock = "SELECT COUNT(*) AS c FROM admin_activity_log WHERE action = 'room_unblock' AND details LIKE '%" . addslashes($roomText) . "%' AND created_at >= NOW() - INTERVAL 1 MINUTE";
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1, $d2]]], 'block', [], [$cLogBlock, "SELECT details AS c FROM admin_activity_log WHERE action = 'room_block' AND details LIKE '%$tag%' LIMIT 1"]);
$det = strtolower((string)($r['verify'][1][0]['c'] ?? ''));
check(($r['json']['success'] ?? false) === true && c($r) === 1 && strpos($det, $roomText . ' on ' . strtolower($rangeText)) !== false && strpos($det, 'type: maintenance') !== false,
    'a calendar block writes ONE Staff activity row naming the room, nights, type and reason', json_encode([$r['verify'] ?? null]));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]]], 'unblock', [$seedInd], [$cLogUnblock]);
check(($r['json']['success'] ?? false) === true && c($r) === 1, 'a calendar unblock writes a Staff activity row', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
$r = grid([['t' => 'room', 'id' => $rid, 'd' => [$d1]], ['t' => 'type', 'id' => 99999999, 'd' => [$d1]]], 'block', [], [$cLogBlock]);
check(($r['json']['success'] ?? true) === false && c($r) === 0, 'a refused save writes no log row');
$r = run(['post' => ['action' => 'block_date', 'block_scope' => 'individual', 'individual_room_id' => (string)$rid, 'block_date' => $d1, 'block_type' => 'event', 'reason' => $tag], 'ajax' => true, 'verify' => [$cLogBlock]]);
check(($r['json']['success'] ?? false) === true && c($r) === 1, 'the List-view form block is logged too', json_encode($r['verify'] ?? null));
$bulkId = 2000000000 + random_int(1, 99999);
$r = run(['post' => ['action' => 'bulk_unblock', 'bulk_ids' => ['individual-' . $bulkId]], 'setup' => ["INSERT INTO individual_room_blocked_dates (id, individual_room_id, block_date, block_type, reason) VALUES ($bulkId, $rid, '$d1', 'manual', '$tag')"], 'verify' => [$cLogUnblock, $cInd]]);
check(c($r, 0) === 1 && c($r, 1) === 0, 'the List-view bulk unblock is logged', json_encode($r['verify'] ?? null));

// A booking that already sleeps in a room: the preview names it (nothing saved) and the block SKIPS that night
// (owner 2026-11-23: never save a block over a booked night). Uses a real future stay; everything is rolled back.
$bk = $pdo->query("SELECT b.id, b.booking_reference, b.individual_room_id, GREATEST(b.check_in_date, CURDATE()) AS night
    FROM bookings b JOIN individual_rooms ir ON ir.id = b.individual_room_id AND ir.is_active = 1
    WHERE b.status IN ('confirmed', 'checked-in') AND b.check_out_date > GREATEST(b.check_in_date, CURDATE()) ORDER BY b.check_in_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$bk) {
    bad('Fixture: a confirmed or in-house booking with a room and a night from today on');
} else {
    $bNight = (string)$bk['night'];
    $bItems = [['t' => 'room', 'id' => (int)$bk['individual_room_id'], 'd' => [$bNight]]];
    $cBlocksHere = "SELECT COUNT(*) AS c FROM individual_room_blocked_dates WHERE block_date = '$bNight' AND reason = '$tag'";
    $r = run(['post' => ['action' => 'preview_block', 'items' => json_encode($bItems)], 'ajax' => true, 'verify' => [$cBlocksHere]]);
    check(($r['json']['success'] ?? false) === true && (int)($r['json']['booking_count'] ?? 0) >= 1 && strpos((string)($r['json']['bookings'] ?? ''), (string)$bk['booking_reference']) !== false && c($r) === 0,
        'preview lists the booking already on that night and saves nothing', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
    $cNote = "SELECT COUNT(*) AS c FROM booking_timeline_logs WHERE booking_id = " . (int)$bk['id'] . " AND action = 'Room blocked on a night of this stay' AND description LIKE '%$tag%'";
    $r = grid($bItems, 'block', [], [$cNote, $cLogBlock, $cBlocksHere]);
    check(($r['json']['success'] ?? true) === false && c($r, 0) === 0 && c($r, 1) === 0 && c($r, 2) === 0 && strpos((string)($r['json']['message'] ?? ''), (string)$bk['booking_reference']) !== false
        && strpos(msg($r), 'booked') !== false,
        'blocking a night a booking sleeps on is skipped (nothing saved, no log, no note) and the message names the booking', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
    check(($r['json']['success'] ?? true) === false && (int)(run(['post' => ['action' => 'preview_block', 'items' => json_encode($bItems)], 'ajax' => true])['json']['skip_nights'] ?? 0) === 1,
        'the preview says that night will be skipped');
    $cNote2 = "SELECT COUNT(*) AS c FROM booking_timeline_logs WHERE booking_id = " . (int)$bk['id'] . " AND action = 'Block removed from a night of this stay' AND created_at >= NOW() - INTERVAL 1 MINUTE";
    $seedB = "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (" . (int)$bk['individual_room_id'] . ", '$bNight', 'manual', '$tag')";
    $r = grid($bItems, 'unblock', [$seedB], [$cNote2]);
    check(($r['json']['success'] ?? false) === true && c($r) === 1, 'unblocking that night notes it on the booking too', json_encode([$r['json'] ?? null, $r['verify'] ?? null]));
}
if ($u ?? null) {
    $r = run(['post' => ['action' => 'preview_block', 'items' => json_encode([['t' => 'room', 'id' => $rid, 'd' => [$d1]]])], 'ajax' => true, 'setup' => $deny, 'as_username' => $u['username']]);
    check(($r['json']['success'] ?? true) === false, 'without Block Rooms the preview is refused too', json_encode($r['json'] ?? null));
}

echo "== Availability follows the blocks (one transaction, rolled back) ==\n";
// The booking checks read the same rows the grid writes. A block is one NIGHT: a stay that checks out
// on the blocked date is still fine.
// Find two free nights for the test room and its type (from +400 days on), so the checks never skip.
$free = null;
for ($k = 400; $k < 700 && $free === null; $k++) {
    $a0 = date('Y-m-d', strtotime("+$k days"));
    $a1 = date('Y-m-d', strtotime('+' . ($k + 1) . ' days'));
    $a2 = date('Y-m-d', strtotime('+' . ($k + 2) . ' days'));
    if (!empty(checkRoomAvailability($tid, $a1, $a2)['available']) && !empty(checkRoomAvailability($tid, $a0, $a1)['available'])
        && !empty(checkIndividualRoomAvailability($rid, $a0, $a2)['available'])) {
        $free = [$a0, $a1, $a2];
    }
}
$pdo->beginTransaction();
try {
    if ($free === null) {
        bad('Fixture: two free nights for the test room and its type within +400..+700 days');
    } else {
        [$d0, $d1, $d2] = $free;
        $base = checkRoomAvailability($tid, $d1, $d2);
        blockIndividualRoomDate($rid, $d1, 'maintenance', $tag);
        $a = checkIndividualRoomAvailability($rid, $d1, $d2);
        check(empty($a['available']) && !empty($a['blocked_dates']), 'a room block makes that room unavailable for a stay sleeping on the night', json_encode($a['error'] ?? null));
        $a = checkIndividualRoomAvailability($rid, $d0, $d1);
        check(!empty($a['available']), 'a stay that checks out on the blocked date is still allowed');
        $t = checkRoomAvailability($tid, $d1, $d2);
        if (isset($base['remaining_rooms'], $t['remaining_rooms'])) {
            check((int)$t['remaining_rooms'] === (int)$base['remaining_rooms'] - 1, 'a room block takes exactly one room off the room type count (the type stays on sale if others are free)', json_encode([$base['remaining_rooms'], $t['remaining_rooms']]));
        }
        unblockIndividualRoomDate($rid, $d1);
        check(!empty(checkIndividualRoomAvailability($rid, $d1, $d2)['available']), 'unblocking puts the room back on sale');

        blockRoomDate($tid, $d1, 'event', $tag);
        $t = checkRoomAvailability($tid, $d1, $d2);
        check(empty($t['available']) && !empty($t['blocked_dates']), 'a room-type block takes the whole type off sale', json_encode($t['error'] ?? null));
        check(!empty(checkRoomAvailability($tid, $d0, $d1)['available']), 'a type stay that checks out on the blocked date is still allowed');
        unblockRoomDate($tid, $d1);
        check(!empty(checkRoomAvailability($tid, $d1, $d2)['available']), 'unblocking the type puts it back on sale');

        blockRoomDate(null, $d1, 'event', $tag);
        $t = checkRoomAvailability($tid, $d1, $d2);
        check(empty($t['available']) && !empty($t['blocked_dates']), 'an all-rooms block takes every type off sale', json_encode($t['error'] ?? null));
        unblockRoomDate(null, $d1);

        // Public booking calendar: a type whose rooms are ALL blocked one by one is greyed out that night.
        require_once $root . '/includes/blocked-nights.php';
        $typeRooms = array_map('intval', $pdo->query("SELECT id FROM individual_rooms WHERE is_active = 1 AND room_type_id = $tid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        if (roomTypeHasActiveCombinations($tid)) {
            echo "SKIP calendar greying for a room type (the test type sells joined pairs)\n";
        } else {
            $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
            check(!in_array($d1, $full[$tid] ?? [], true), 'calendar: the type is not greyed out while a room is free');
            foreach ($typeRooms as $x) {
                blockIndividualRoomDate($x, $d1, 'maintenance', $tag);
            }
            $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
            check(in_array($d1, $full[$tid] ?? [], true) && !in_array($d0, $full[$tid] ?? [], true) && !in_array($d2, $full[$tid] ?? [], true),
                'calendar: every room of the type blocked one by one greys out exactly that night', json_encode($full[$tid] ?? []));
            check(empty(checkRoomAvailability($tid, $d1, $d2)['available']), 'and the booking check agrees: the type cannot be booked that night');
            unblockIndividualRoomDate($typeRooms[0], $d1);
            $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
            check(!in_array($d1, $full[$tid] ?? [], true), 'calendar: unblocking one room opens the night again');
            if (count($typeRooms) > 1) {
                $pdo->prepare("UPDATE individual_rooms SET status = 'out_of_order' WHERE id = ?")->execute([$typeRooms[0]]);
                $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
                check(in_array($d1, $full[$tid] ?? [], true) && !in_array($d0, $full[$tid] ?? [], true), 'calendar: the last free room out of order also greys the night (other nights stay open)', json_encode($full[$tid] ?? []));
            }
            foreach ($typeRooms as $x) {
                unblockIndividualRoomDate($x, $d1);
            }
        }
        // A joined type sells pairs: one blocked room of its only pair greys it out.
        if ($p) {
            foreach ($pairSeed as $sql) {
                $pdo->exec($sql);
            }
            $ct = (int)$pdo->query("SELECT combined_room_type_id FROM room_combinations WHERE is_active = 1 AND room_a_id = {$p['room_a_id']} AND room_b_id = {$p['room_b_id']} LIMIT 1")->fetchColumn();
            $pairCount = (int)$pdo->query("SELECT COUNT(*) FROM room_combinations WHERE is_active = 1 AND combined_room_type_id = $ct")->fetchColumn();
            $pairFree = !empty(checkIndividualRoomAvailability($p['room_a_id'], $d0, $d2)['available']) && !empty(checkIndividualRoomAvailability($p['room_b_id'], $d0, $d2)['available']);
            if ($ct > 0 && $pairCount === 1 && $pairFree) {
                $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
                check(!in_array($d1, $full[$ct] ?? [], true), 'calendar: a joined type with a free pair is not greyed out');
                blockIndividualRoomDate($p['room_b_id'], $d1, 'maintenance', $tag);
                $full = rh_fully_blocked_type_nights($pdo, $d0, $d2);
                check(in_array($d1, $full[$ct] ?? [], true) && !in_array($d0, $full[$ct] ?? [], true), 'calendar: one blocked room of its only pair greys the joined type out that night', json_encode($full[$ct] ?? []));
                unblockIndividualRoomDate($p['room_b_id'], $d1);
            } else {
                echo "SKIP joined-type calendar greying (pair type has $pairCount pairs or the pair is busy)\n";
            }
        }
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$left = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM blocked_dates WHERE reason = " . $pdo->quote($tag) . ") + (SELECT COUNT(*) FROM individual_room_blocked_dates WHERE reason = " . $pdo->quote($tag) . ")")->fetchColumn();
check($left === 0, 'availability checks left nothing behind');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
