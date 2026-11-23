<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Extend (GRID_UNIFY_PLAN Phase 3): blocks (includes/block-save.php rh_block_extend + admin/blocked-dates.php
 * extend_blocks), maintenance jobs (includes/ops-extend.php rh_maint_extend + room-maintenance.php bulk_schedules
 * extend) and housekeeping repeats (rh_hk_extend + housekeeping.php bulk_assignments extend).
 * Usage (from the repo root, Liwonde test DB only): php scripts/tests/extend_test.php
 *
 * Part 1 runs in-process on a connection that can never commit (clash_test_lib.php): every fixture and every save
 * lives inside one outer transaction that is rolled back. Part 2 runs the pages inside page_harness.php (rolled back).
 * Block / maintenance fixtures sit 800+ days ahead; housekeeping ones about 300 days ahead (its 1-year cap).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$h = __DIR__ . '/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function run(string $page, array $cfg): array {
    global $h;
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = (string)$out; return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
}

require_once $root . '/config/database.php';
require_once __DIR__ . '/clash_test_lib.php';
require_once $root . '/includes/block-save.php';
require_once $root . '/includes/ops-extend.php';

$hex = bin2hex(random_bytes(2));
$tag = 'ZZ ext ' . $hex;
$E = static fn(int $k): string => date('Y-m-d', strtotime('+' . (800 + $k) . ' days'));
$H = static fn(int $k): string => date('Y-m-d', strtotime('+' . (300 + $k) . ' days'));

echo "== Date steps ==\n";
check(rh_extend_add('2027-01-31', 1, 'months') === '2027-02-28' && rh_extend_add('2028-01-31', 1, 'months') === '2028-02-29'
    && rh_extend_add('2028-02-29', 1, 'years') === '2029-02-28' && rh_extend_add('2027-03-31', 1, 'months') === '2027-04-30',
    '31 Jan + 1 month = end of Feb (28 / 29 leap); 29 Feb + 1 year = 28 Feb; 31 Mar + 1 month = 30 Apr');
check(rh_extend_add('2027-01-10', 2, 'weeks') === '2027-01-24' && rh_extend_add('2027-12-30', 3, 'days') === '2028-01-02' && rh_extend_add('2027-01-10', 1, 'hours') === ''
    && rh_extend_add('2027-02-30', 1, 'days') === '', '2 weeks = 14 days; days roll over the year; an unknown unit or a bad date gives nothing');

echo "== Part 1: in-process (never committed) ==\n";
$pdo = clash_test_pdo();
$GLOBALS['pdo'] = $pdo;
$pdo->beginTransaction();
try {
    $f = clash_test_fixtures($pdo, 'ZZ ext ' . $hex);
    $num = static fn(int $rid): string => (string)$pdo->query("SELECT room_number FROM individual_rooms WHERE id = $rid")->fetchColumn();
    $T = static fn(int $rid, int $type): array => ['scope' => 'room', 'id' => $rid, 'room_ids' => [$rid], 'type_id' => $type, 'label' => 'Room ' . $num($rid)];
    $seed = static function (int $rid, array $days, string $type, string $reason) use ($pdo): void {
        $st = $pdo->prepare('INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) VALUES (?, ?, ?, ?)');
        foreach ($days as $d) { $st->execute([$rid, $d, $type, $reason]); }
    };
    $rows = static fn(int $rid, string $from, string $to): int => (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = $rid AND block_date BETWEEN '$from' AND '$to'")->fetchColumn();
    $maxLog = static fn(): int => (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_activity_log')->fetchColumn();
    $logsSince = static fn(int $id, string $action): int => (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE id > $id AND action = '$action'")->fetchColumn();
    $meta = ['by' => null, 'user_id' => 0, 'user_name' => 'extend test'];
    $by = static fn(int $n, string $unit): array => ['mode' => 'by', 'n' => $n, 'unit' => $unit];

    // Blocks: 2 weeks, type + reason copied, one log row.
    $seed($f['r1'], [$E(1), $E(2), $E(3)], 'event', 'R1 ' . $tag);
    $l0 = $maxLog();
    $res = rh_block_extend($pdo, [['target' => $T($f['r1'], $f['T1']), 'last' => $E(3), 'how' => $by(2, 'weeks')]], $meta);
    $copied = (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r1']} AND block_date BETWEEN '" . $E(4) . "' AND '" . $E(17) . "' AND block_type = 'event' AND reason = 'R1 $tag'")->fetchColumn();
    check($res['ok'] && $res['saved'] === 14 && $copied === 14 && $rows($f['r1'], $E(18), $E(30)) === 0 && $logsSince($l0, 'room_block') === 1,
        'block extended by 2 weeks: 14 new nights copy the type and reason, nothing beyond, ONE Staff activity row', json_encode([$res['message'], $copied, $logsSince($l0, 'room_block')]));

    // Selecting the middle of a run extends from the run's end.
    $seed($f['r2'], [$E(1), $E(2), $E(3), $E(4), $E(5)], 'manual', 'R2 ' . $tag);
    $res = rh_block_extend($pdo, [['target' => $T($f['r2'], $f['T1']), 'last' => $E(2), 'how' => $by(2, 'days')]], $meta);
    check($res['ok'] && $rows($f['r2'], $E(1), $E(10)) === 7 && $rows($f['r2'], $E(6), $E(7)) === 2, 'a night in the middle of a run: the 2 days are added after the run\'s end', $res['message']);

    // Crossing a booking: booked nights skipped and listed, the rest saved.
    clash_test_booking($pdo, 'ZE' . $hex . 'B', $f['T1'], $f['r3'], $E(10), $E(12));
    $seed($f['r3'], [$E(8), $E(9)], 'manual', 'R3 ' . $tag);
    $res = rh_block_extend($pdo, [['target' => $T($f['r3'], $f['T1']), 'last' => $E(9), 'how' => ['mode' => 'until', 'until' => $E(14)]]], $meta);
    check($res['ok'] && $rows($f['r3'], $E(10), $E(11)) === 0 && $rows($f['r3'], $E(12), $E(14)) === 3 && strpos($res['message'], 'Skipped 2 booked night') !== false
        && strpos($res['message'], 'ZE' . $hex . 'B') !== false, 'extend until a date across a booking: its 2 nights are skipped and the booking is named; the 3 free nights are blocked', $res['message']);
    $pv = rh_block_extend($pdo, [['target' => $T($f['r3'], $f['T1']), 'last' => $E(14), 'how' => $by(1, 'days')]], $meta, ['preview' => true]);
    check($pv['ok'] && $pv['saved'] === 1 && $rows($f['r3'], $E(15), $E(15)) === 0 && strpos($pv['message'], 'Will block 1') !== false, 'preview says what would be blocked and saves nothing', $pv['message']);

    // An own block on a new night is left exactly as it was.
    $seed($f['r4'], [$E(1)], 'manual', 'A ' . $tag);
    $seed($f['r4'], [$E(4)], 'maintenance', 'B ' . $tag);
    $res = rh_block_extend($pdo, [['target' => $T($f['r4'], $f['T2']), 'last' => $E(1), 'how' => $by(4, 'days')]], $meta);
    $e4 = (string)$pdo->query("SELECT CONCAT(block_type, '|', reason) FROM individual_room_blocked_dates WHERE individual_room_id = {$f['r4']} AND block_date = '" . $E(4) . "'")->fetchColumn();
    check($res['ok'] && $res['saved'] === 3 && $e4 === 'maintenance|B ' . $tag && strpos($res['message'], 'already blocked') !== false,
        'a new night the room already blocks keeps its own type and reason (not rewritten) and is reported as already blocked', json_encode([$res['message'], $e4]));

    // Type rule: no OWN block on the night -> the whole action is refused.
    $pdo->prepare("INSERT INTO blocked_dates (room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', ?)")->execute([$f['T2'], $E(30), $tag]);
    $before = $rows($f['r4'], $E(29), $E(40));
    $res = rh_block_extend($pdo, [['target' => $T($f['r1'], $f['T1']), 'last' => $E(3), 'how' => $by(1, 'days')], ['target' => $T($f['r4'], $f['T2']), 'last' => $E(30), 'how' => $by(3, 'days')]], $meta);
    check(!$res['ok'] && !empty($res['refused']) && strpos($res['message'], 'no block of its own') !== false && $rows($f['r4'], $E(29), $E(40)) === $before && $rows($f['r1'], $E(18), $E(18)) === 0,
        'a room covered only by its room-type block cannot be extended: the whole action is refused, the other item is not saved either', $res['message']);

    // Two items for one row with a different reason.
    $seed($f['r1'], [$E(40)], 'manual', 'X ' . $tag);
    $seed($f['r1'], [$E(42)], 'manual', 'Y ' . $tag);
    $res = rh_block_extend($pdo, [['target' => $T($f['r1'], $f['T1']), 'last' => $E(40), 'how' => $by(1, 'days')], ['target' => $T($f['r1'], $f['T1']), 'last' => $E(42), 'how' => $by(1, 'days')]], $meta);
    check(!$res['ok'] && strpos($res['message'], 'different type or reason') !== false && $rows($f['r1'], $E(41), $E(43)) === 1, 'two runs of one room with different reasons in one action are refused', $res['message']);

    // Room-type block over a night with a booking that has no room yet: only the free rooms, one by one.
    $pdo->prepare("INSERT INTO blocked_dates (room_id, block_date, block_type, reason) VALUES (?, ?, 'manual', ?)")->execute([$f['T1'], $E(50), 'TT ' . $tag]);
    clash_test_booking($pdo, 'ZE' . $hex . 'T', $f['T1'], null, $E(52), $E(53));
    $tt = ['scope' => 'type', 'id' => $f['T1'], 'room_ids' => [], 'type_id' => $f['T1'], 'label' => 'All T1 rooms'];
    $res = rh_block_extend($pdo, [['target' => $tt, 'last' => $E(50), 'how' => ['mode' => 'until', 'until' => $E(53)]]], $meta);
    $typeRows = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE room_id = {$f['T1']} AND block_date BETWEEN '" . $E(50) . "' AND '" . $E(53) . "'")->fetchColumn();
    $singles = (int)$pdo->query("SELECT COUNT(*) FROM individual_room_blocked_dates WHERE block_date = '" . $E(52) . "' AND individual_room_id IN ({$f['r1']}, {$f['r2']}, {$f['r3']})")->fetchColumn();
    check($res['ok'] && $typeRows === 3 && $singles === 2 && strpos($res['message'], 'one by one') !== false,
        'room-type extend: free nights get the type block; the night with a no-room booking blocks 2 of 3 rooms singly (one kept)', json_encode([$res['message'], $typeRows, $singles]));

    // Caps: 3 years per row.
    $seed($f['r2'], [$E(100)], 'manual', 'C ' . $tag);
    $res = rh_block_extend($pdo, [['target' => $T($f['r2'], $f['T1']), 'last' => $E(100), 'how' => $by(1097, 'days')]], $meta, ['preview' => true]);
    $ok1096 = rh_block_extend($pdo, [['target' => $T($f['r2'], $f['T1']), 'last' => $E(100), 'how' => $by(1096, 'days')]], $meta, ['preview' => true]);
    check(!$res['ok'] && strpos($res['message'], 'at most 3 years') !== false && $ok1096['ok'] && $ok1096['saved'] === 1096,
        '1097 new nights for one room are refused; 1096 (3 years) are allowed (the 366 cap is raised for Extend only)', json_encode([$res['message'], $ok1096['saved']]));
    check(count(rh_clean_block_dates_probe()) === 366, 'the normal 366-night cap is unchanged elsewhere');

    // All or nothing: a failure just before COMMIT leaves nothing (two rows, two reasons, one action).
    $seed($f['r1'], [$E(60)], 'manual', 'P ' . $tag);
    $seed($f['r2'], [$E(60)], 'event', 'Q ' . $tag);
    $two = [['target' => $T($f['r1'], $f['T1']), 'last' => $E(60), 'how' => $by(3, 'days')], ['target' => $T($f['r2'], $f['T1']), 'last' => $E(60), 'how' => $by(3, 'days')]];
    $l0 = $maxLog();
    $res = rh_block_extend($pdo, $two, $meta, ['before_commit' => static function () { throw new RuntimeException('forced test failure'); }]);
    check(!$res['ok'] && $rows($f['r1'], $E(61), $E(63)) === 0 && $rows($f['r2'], $E(61), $E(63)) === 0 && $logsSince($l0, 'room_block') === 0,
        'forced failure before COMMIT: nothing saved for either room and no log row', $res['message']);
    $res = rh_block_extend($pdo, $two, $meta);
    $logDetail = (string)$pdo->query("SELECT details FROM admin_activity_log WHERE id > $l0 AND action = 'room_block' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check($res['ok'] && $rows($f['r1'], $E(61), $E(63)) === 3 && $rows($f['r2'], $E(61), $E(63)) === 3 && $logsSince($l0, 'room_block') === 1 && strpos($logDetail, 'Types:') !== false,
        'the same action without the failure: both rooms extended in one transaction with ONE log row naming both types', json_encode([$res['message'], $logDetail]));

    // ---------------------------------------------------------------- maintenance
    $job = static function (int $rid, string $s, string $e, int $block, string $status = 'planned') use ($pdo, $tag): int {
        $pdo->prepare("INSERT INTO room_maintenance_schedules (individual_room_id, title, status, priority, maintenance_type, block_room, start_date, end_date, due_date) VALUES (?, ?, ?, 'medium', 'repair', ?, ?, ?, ?)")
            ->execute([$rid, 'Job ' . $tag . ' ' . bin2hex(random_bytes(2)), $status, $block, $s, $e, substr($s, 0, 10)]);
        return (int)$pdo->lastInsertId();
    };
    $endOf = static fn(int $id): string => (string)$pdo->query("SELECT end_date FROM room_maintenance_schedules WHERE id = $id")->fetchColumn();
    $actor = ['id' => null, 'name' => 'extend test'];
    $jA = $job($f['r1'], $E(200) . ' 14:00:00', $E(202) . ' 11:00:00', 1);
    $jB = $job($f['r2'], $E(200) . ' 14:00:00', $E(202) . ' 11:00:00', 1);
    $jC = $job($f['r3'], $E(200) . ' 14:00:00', $E(202) . ' 11:00:00', 1, 'completed');
    $jD = $job($f['r4'], $E(200) . ' 14:00:00', $E(202) . ' 11:00:00', 0);
    clash_test_booking($pdo, 'ZE' . $hex . 'M', $f['T1'], $f['r2'], $E(203), $E(205));
    clash_test_booking($pdo, 'ZE' . $hex . 'N', $f['T2'], $f['r4'], $E(203), $E(205));
    $l0 = $maxLog();
    $res = rh_maint_extend($pdo, [$jA, $jB, $jC, $jD], $by(3, 'days'), $actor);
    check($res['ok'] && $endOf($jA) === $E(205) . ' 11:00:00' && $endOf($jB) === $E(202) . ' 11:00:00' && $endOf($jC) === $E(202) . ' 11:00:00' && $endOf($jD) === $E(205) . ' 11:00:00'
        && $res['done'] === 2 && strpos($res['message'], 'ZE' . $hex . 'M') !== false && strpos($res['message'], 'Free:') !== false && $logsSince($l0, 'maintenance_extend') === 1,
        'maintenance +3 days keeps the time of day; the room-closing job over a booking is refused (booking + free dates listed), the completed job skipped, the job that does not close its room extended; ONE log row',
        json_encode([$res['message'], $endOf($jA), $endOf($jD), $logsSince($l0, 'maintenance_extend')]));
    $res = rh_maint_extend($pdo, [$jA], ['mode' => 'until', 'until' => $E(210) . 'T18:30'], $actor);
    check($res['ok'] && $endOf($jA) === $E(210) . ' 18:30:00', 'extend to a date and time', $res['message']);
    $res = rh_maint_extend($pdo, [$jA], ['mode' => 'until', 'until' => $E(204) . ' 09:00'], $actor);
    $res2 = rh_maint_extend($pdo, [$jA], ['mode' => 'until', 'until' => $E(204) . ' 09:00'], $actor, ['resize' => true]);
    $res3 = rh_maint_extend($pdo, [$jA], ['mode' => 'until', 'until' => $E(200) . ' 14:30'], $actor, ['resize' => true]);
    check(!$res['ok'] && $res2['ok'] && $endOf($jA) === $E(204) . ' 09:00:00' && !$res3['ok'] && strpos($res3['message'], '1 hour after it starts') !== false,
        'an earlier end is refused by Extend, allowed by the drag handle (resize) but never less than 1 hour after the start', json_encode([$res['message'], $res3['message']]));
    $res = rh_maint_extend($pdo, [$jA], $by(4, 'years'), $actor);
    check(!$res['ok'] && strpos($res['message'], 'at most 3 years') !== false && $endOf($jA) === $E(204) . ' 09:00:00', 'a job longer than 3 years from its start is refused', $res['message']);
    $res = rh_maint_extend($pdo, [$jA, $jD], $by(1, 'weeks'), $actor, ['before_commit' => static function () { throw new RuntimeException('forced test failure'); }]);
    check(!$res['ok'] && $endOf($jA) === $E(204) . ' 09:00:00' && $endOf($jD) === $E(205) . ' 11:00:00', 'forced failure before COMMIT: neither job changed', $res['message']);
    $pv = rh_maint_extend($pdo, [$jB], $by(3, 'days'), $actor, ['preview' => true]);
    check(!$pv['ok'] && strpos($pv['message'], 'ZE' . $hex . 'M') !== false && $endOf($jB) === $E(202) . ' 11:00:00', 'preview names the booking that refuses the job and saves nothing', $pv['message']);

    // ---------------------------------------------------------------- housekeeping
    $task = static function (int $rid, string $due, int $recurring = 0, string $status = 'pending') use ($pdo, $tag): int {
        $pdo->prepare("INSERT INTO housekeeping_assignments (individual_room_id, status, due_date, assignment_type, priority, notes, is_recurring, scheduled_time, estimated_duration) VALUES (?, ?, ?, 'regular_cleaning', 'high', ?, ?, '10:30:00', 45)")
            ->execute([$rid, $status, $due, 'HK ' . $tag, $recurring]);
        return (int)$pdo->lastInsertId();
    };
    $hkCount = static fn(int $rid, string $from, string $to): int => (int)$pdo->query("SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = $rid AND due_date BETWEEN '$from' AND '$to'")->fetchColumn();
    $tS = $task($f['r1'], $H(0));
    $l0 = $maxLog();
    $res = rh_hk_extend($pdo, [$tS], ['every' => 'daily', 'until' => $H(6)], $actor);
    $same = (int)$pdo->query("SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = {$f['r1']} AND due_date BETWEEN '" . $H(1) . "' AND '" . $H(6) . "' AND scheduled_time = '10:30:00' AND is_recurring = 0 AND status = 'pending' AND priority = 'high' AND assignment_type = 'regular_cleaning'")->fetchColumn();
    check($res['ok'] && $res['added'] === 6 && $same === 6 && $logsSince($l0, 'housekeeping_repeat') === 1, 'repeat daily until +6 days: 6 new tasks with the same time, type and priority, not recurring; ONE log row', $res['message']);
    $res = rh_hk_extend($pdo, [$tS], ['every' => 'daily', 'until' => $H(6)], $actor);
    check(!$res['ok'] && $res['added'] === 0 && $res['dupes'] === 6 && $hkCount($f['r1'], $H(1), $H(6)) === 6, 'repeating again adds no duplicate (same room + type + day)', $res['message']);
    $tU = $task($f['r2'], $H(0));
    $task($f['r2'], $H(3));
    $res = rh_hk_extend($pdo, [$tU], ['every' => 'daily', 'until' => $H(6)], $actor);
    check($res['ok'] && $res['added'] === 5 && $res['dupes'] === 1 && $hkCount($f['r2'], $H(3), $H(3)) === 1 && strpos($res['message'], '1 day(s) skipped') !== false,
        'an open task already on day 3: 5 added, 1 skipped', $res['message']);
    $tW = $task($f['r3'], $H(0));
    $res = rh_hk_extend($pdo, [$tW], ['every' => 'weekly', 'until' => $H(21)], $actor);
    check($res['ok'] && $res['added'] === 3 && $hkCount($f['r3'], $H(7), $H(7)) === 1 && $hkCount($f['r3'], $H(1), $H(6)) === 0, 'weekly until +21 days: 3 tasks, one a week', $res['message']);
    $tR = $task($f['r4'], $H(0), 1);
    $res = rh_hk_extend($pdo, [$tR], ['every' => 'daily', 'until' => $H(3)], $actor);
    check(!$res['ok'] && strpos($res['message'], 'already repeats') !== false && $hkCount($f['r4'], $H(1), $H(3)) === 0, 'a task that already repeats is skipped', $res['message']);
    $res = rh_hk_extend($pdo, [$tW], ['every' => 'daily', 'until' => date('Y-m-d', strtotime('+367 days'))], $actor);
    check(!$res['ok'] && strpos($res['message'], 'at most one year') !== false, 'repeating more than a year ahead is refused', $res['message']);
    $tF = $task($f['p1'], $H(0));
    $res = rh_hk_extend($pdo, [$tF], ['every' => 'daily', 'until' => $H(4)], $actor, ['before_commit' => static function () { throw new RuntimeException('forced test failure'); }]);
    check(!$res['ok'] && $hkCount($f['p1'], $H(1), $H(4)) === 0, 'forced failure before COMMIT: no task added', $res['message']);
} catch (Throwable $e) {
    bad('unexpected error', $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    $pdo->finalRollback();
}

echo "== Part 2: the pages (rolled back) ==\n";
$num = 'ZX' . strtoupper($hex);
$slug = 'zz-ext-' . $hex;
$fx = [
    "INSERT INTO rooms (name, slug, description, price_per_night, max_guests, total_rooms, rooms_available, display_order, is_active) VALUES ('$tag A', '$slug-a', 'ZZ clash test', 1, 2, 2, 2, 0, 1)",
    "INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) SELECT t.id, CONCAT('$num', x.n), 'ZZ clash test', 'available', 1 FROM rooms t JOIN (SELECT '1' n UNION SELECT '2') x WHERE t.slug = '$slug-a'",
    "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) SELECT id, '" . $E(1) . "', 'manual', '$tag' FROM individual_rooms WHERE room_number = '{$num}1'",
    "INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason) SELECT id, '" . $E(2) . "', 'manual', '$tag' FROM individual_rooms WHERE room_number = '{$num}1'",
    "INSERT INTO blocked_dates (room_id, block_date, block_type, reason) SELECT id, '" . $E(2) . "', 'manual', '$tag' FROM rooms WHERE slug = '$slug-a'",
];
$vars = ['r1' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}1'", 'r2' => "SELECT id FROM individual_rooms WHERE room_number = '{$num}2'"];
$cR1 = "SELECT COUNT(*) AS c FROM individual_room_blocked_dates d JOIN individual_rooms r ON r.id = d.individual_room_id WHERE r.room_number = '{$num}1'";
$ext = static fn(array $post, array $verify = [], array $extra = []): array => run('admin/blocked-dates.php', ['post' => ['action' => 'extend_blocks'] + $post, 'ajax' => true, 'setup' => $fx, 'vars' => $vars, 'verify' => $verify] + $extra);
$item = static fn(string $var, string $last): string => json_encode([['t' => 'room', 'id' => '{' . $var . '}', 'last' => $last]]);
$r = $ext(['items' => $item('r1', $E(2)), 'mode' => 'by', 'n' => '1', 'unit' => 'weeks'], [$cR1]);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 9 && !empty($r['rolled_back']), 'Blocked Dates extend_blocks: 1 week added to the room (2 + 7 rows), page rolled back', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$r = $ext(['items' => $item('r1', $E(2)), 'mode' => 'until', 'until' => $E(5), 'preview' => '1'], [$cR1]);
check(($r['json']['success'] ?? false) === true && ($r['json']['add_nights'] ?? 0) === 3 && (int)($r['verify'][0][0]['c'] ?? 0) === 2, 'preview: 3 nights would be added, nothing saved', json_encode($r['json'] ?? $r['raw']));
$r = $ext(['items' => $item('r2', $E(2)), 'mode' => 'by', 'n' => '2', 'unit' => 'days'], ["SELECT COUNT(*) AS c FROM individual_room_blocked_dates d JOIN individual_rooms r ON r.id = d.individual_room_id WHERE r.room_number = '{$num}2'"]);
check(($r['json']['success'] ?? true) === false && ($r['json']['refused'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? -1) === 0,
    'a room whose night is blocked only by its room type cannot be extended (refused, nothing saved)', json_encode($r['json'] ?? $r['raw']));
// Shorten = unblock the trimmed nights: the room's own block goes, the room-type block stays and is reported.
$r = run('admin/blocked-dates.php', ['post' => ['action' => 'grid_apply', 'op' => 'unblock', 'items' => json_encode([['t' => 'room', 'id' => '{r1}', 'd' => [$E(2)]]])], 'ajax' => true, 'setup' => $fx, 'vars' => $vars, 'verify' => [$cR1]]);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 1 && stripos((string)($r['json']['message'] ?? ''), 'still blocked by a room-type') !== false,
    'shorten (drag left) unblocks the trimmed own night; the room-type block on it stays and is reported', json_encode($r['json'] ?? $r['raw']));
$u = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role <> 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($u) {
    $deny = ["DELETE FROM user_permissions WHERE user_id = " . (int)$u['id'] . " AND permission_key IN ('blocked_dates','block_rooms')",
        "INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (" . (int)$u['id'] . ", 'blocked_dates', 1), (" . (int)$u['id'] . ", 'block_rooms', 0)"];
    $r = run('admin/blocked-dates.php', ['post' => ['action' => 'extend_blocks', 'items' => $item('r1', $E(2)), 'mode' => 'by', 'n' => '1', 'unit' => 'days'], 'ajax' => true,
        'setup' => array_merge($fx, $deny), 'vars' => $vars, 'verify' => [$cR1], 'as_username' => $u['username']]);
    check(($r['json']['success'] ?? true) === false && (int)($r['verify'][0][0]['c'] ?? -1) === 2 && stripos((string)($r['json']['message'] ?? ''), 'block rooms permission') !== false,
        'extend_blocks without the Block Rooms permission is refused', json_encode($r['json'] ?? $r['raw']));
}
$mfx = array_merge($fx, ["INSERT INTO room_maintenance_schedules (individual_room_id, title, status, priority, maintenance_type, block_room, start_date, end_date, due_date) SELECT id, 'Job $tag', 'planned', 'medium', 'repair', 1, '" . $E(300) . " 14:00:00', '" . $E(301) . " 11:00:00', '" . $E(300) . "' FROM individual_rooms WHERE room_number = '{$num}2'"]);
$mvars = $vars + ['j1' => "SELECT id FROM room_maintenance_schedules WHERE title = 'Job $tag'"];
$r = run('admin/room-maintenance.php', ['post' => ['action' => 'bulk_schedules', 'bulk_op' => 'extend', 'bulk_ids' => ['{j1}'], 'mode' => 'by', 'n' => '2', 'unit' => 'days'], 'ajax' => true,
    'setup' => $mfx, 'vars' => $mvars, 'verify' => ["SELECT end_date AS c FROM room_maintenance_schedules WHERE title = 'Job $tag'"]]);
check(($r['json']['success'] ?? false) === true && ($r['verify'][0][0]['c'] ?? '') === $E(303) . ' 11:00:00', 'Maintenance bulk Extend +2 days through the page', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));
$hfx = array_merge($fx, ["INSERT INTO housekeeping_assignments (individual_room_id, status, due_date, assignment_type, priority, notes, is_recurring, scheduled_time) SELECT id, 'pending', '" . $H(0) . "', 'regular_cleaning', 'medium', 'HK $tag', 0, '09:15:00' FROM individual_rooms WHERE room_number = '{$num}1'"]);
$hvars = $vars + ['t1' => "SELECT id FROM housekeeping_assignments WHERE notes = 'HK $tag' ORDER BY id LIMIT 1"];
$r = run('admin/housekeeping.php', ['post' => ['action' => 'bulk_assignments', 'bulk_op' => 'extend', 'bulk_ids' => ['{t1}'], 'every' => 'daily', 'until' => $H(3)], 'ajax' => true,
    'setup' => $hfx, 'vars' => $hvars, 'verify' => ["SELECT COUNT(*) AS c FROM housekeeping_assignments WHERE notes = 'HK $tag' AND scheduled_time = '09:15:00'"]]);
check(($r['json']['success'] ?? false) === true && (int)($r['verify'][0][0]['c'] ?? 0) === 4, 'Housekeeping bulk Repeat daily +3 days through the page (1 + 3 tasks, same time)', json_encode([$r['json'] ?? $r['raw'], $r['verify'] ?? null]));

check(clash_test_left_behind() === 0, 'nothing left behind');
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

/** rh_clean_block_dates lives in the Blocked Dates page; this mirrors a call with the default cap through a probe copy of its rule. */
function rh_clean_block_dates_probe(): array
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/blocked-dates.php');
    if (!preg_match('/function rh_clean_block_dates\(\$dates, int \$cap = (\d+)\)/', $src, $m)) {
        return [];
    }
    return array_fill(0, (int)$m[1], 1);
}
