<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group table row actions (owner 2026-11-18, G2 "Change dates" + G3 "Who's staying"): includes/group-row-actions.php
 * and the admin/booking-details.php handler / landing.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/group_row_actions_test.php
 * Library checks build their OWN rows (references GRAT-*, emails @example.invalid) inside ONE transaction that is rolled
 * back; page checks run through scripts/tests/page_harness.php (rolled back). A leak check runs at the end.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/booking-group-ops.php';
require_once $root . '/includes/group-row-actions.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// ---- G2: which existing path a room uses (pure) -----------------------------------------------------------------
$cases = [
    // status, deleted, edit, correct => path
    ['confirmed', false, true, false, 'adjust'], ['pending', false, true, false, 'adjust'], ['tentative', false, true, true, 'adjust'],
    ['checked-in', false, true, false, 'adjust'], ['Checked-In', false, true, false, 'adjust'],
    ['checked-out', false, true, true, 'correct'], ['checked-out', false, true, false, ''], ['checked-out', false, false, true, 'correct'],
    ['confirmed', false, false, true, ''], ['cancelled', false, true, true, ''], ['no-show', false, true, true, ''], ['expired', false, true, true, ''],
    ['confirmed', true, true, true, ''], ['checked-out', true, true, true, ''],
];
foreach ($cases as [$st, $del, $ed, $co, $want]) {
    $p = rh_group_row_date_path($st, $del, $ed, $co);
    ok("G2 path: $st" . ($del ? ' (deleted)' : '') . ' edit=' . (int)$ed . ' correct=' . (int)$co . " -> " . ($want ?: 'none'),
        $p['path'] === $want && ($want === '' ? $p['reason'] !== '' : $p['reason'] === ''), json_encode($p));
}
// Same answer as the room's own page flags ($can_adjust_dates && edit_booking; $bd_can_correct_stay).
$pageFlags = static function (string $st, bool $del, bool $ed, bool $co): string {
    $closed = in_array($st, ['checked-out', 'cancelled', 'no-show', 'expired'], true);
    if (!$del && $st === 'checked-out' && $co) { return 'correct'; }
    return (!$del && !$closed && $ed) ? 'adjust' : '';
};
$same = true;
foreach (['pending', 'tentative', 'confirmed', 'checked-in', 'checked-out', 'cancelled', 'no-show', 'expired'] as $st) {
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$ed, $co]) {
        foreach ([false, true] as $del) {
            if (rh_group_row_date_path($st, $del, $ed, $co)['path'] !== $pageFlags($st, $del, $ed, $co)) { $same = false; echo "  mismatch $st del=$del ed=$ed co=$co\n"; }
        }
    }
}
ok('G2 path matches the target page\'s own button flags for every status / permission', $same);
ok('G2 reason names the permission label', strpos(rh_group_row_date_path('confirmed', false, false, false)['reason'], '"') !== false);
ok('G2 cancelled reason is plain', rh_group_row_date_path('no-show', false, true, true)['reason'] === 'This room is no show, so its dates cannot be changed.');

// ---- G3: save "Who's staying" (library, own rows, rolled back) ---------------------------------------------------
$src = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($src <= 0) { fwrite(STDERR, "No source booking to clone.\n"); exit(1); }
getBookingFolioSummary($src); // warm up anything that could run DDL before the transaction
$cols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$n = 0;
$mk = static function (array $over = []) use ($pdo, $cols, $src, &$n): int {
    $n++;
    $over += ['booking_reference' => 'GRAT-' . getmypid() . '-' . $n, 'guest_email' => 'grat' . $n . '@example.invalid', 'guest_name' => 'Grat Lead ' . $n,
        'room_guest_name' => null, 'primary_booking_id' => null, 'billing_account_id' => null, 'final_invoice_number' => null, 'final_invoice_generated' => 0,
        'final_invoice_path' => null, 'deleted_at' => null, 'status' => 'confirmed', 'client_uuid' => null, 'individual_room_id' => null, 'room_combination_id' => null];
    $sel = []; $args = [];
    foreach ($cols as $c) {
        if ($c === 'id') { continue; }
        if (array_key_exists($c, $over)) { $sel[] = '?'; $args[] = $over[$c]; } else { $sel[] = '`' . $c . '`'; }
    }
    $ins = array_values(array_filter($cols, static fn($c) => $c !== 'id'));
    $pdo->prepare('INSERT INTO bookings (`' . implode('`,`', $ins) . '`) SELECT ' . implode(',', $sel) . ' FROM bookings WHERE id = ?')->execute(array_merge($args, [$src]));
    return (int)$pdo->lastInsertId();
};
$name = static function (int $id) use ($pdo) {
    $st = $pdo->prepare('SELECT room_guest_name FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetchColumn();
};
$hist = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare("SELECT old_value, new_value, description, performed_by_id FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Name on the room changed' ORDER BY id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$adminId = (int)$pdo->query("SELECT id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$staffId = (int)$pdo->query("SELECT id FROM admin_users WHERE username = 'test.staff' AND is_active = 1")->fetchColumn();
$refreshed = [];
$opts = ['user_id' => $adminId, 'user_name' => 'group_row_actions_test', 'refresh' => static function (PDO $p, int $id) use (&$refreshed): string { $refreshed[] = $id; return ' [refreshed]'; }];

$pdo->beginTransaction();
try {
    $A = $mk(); $B = $mk(); $C = $mk(['status' => 'checked-out']); $D = $mk(['status' => 'cancelled']); $E = $mk(['deleted_at' => date('Y-m-d H:i:s')]);
    $X = $mk(); // another, unrelated booking
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id IN (?,?,?,?)')->execute([$A, $B, $C, $D, $E]);

    $r = rh_group_room_guest_save($pdo, $A, $B, '  Mr  &  Mrs Banda ', $opts);
    ok('G3 set from the lead\'s page on another room: saved, cleaned', $r['success'] && $name($B) === 'Mr & Mrs Banda' && $r['changed'], json_encode($r));
    $h = $hist($B);
    ok('G3 History on THAT room: (none) -> new, by the user', count($h) === 1 && $h[0]['old_value'] === '(none)' && $h[0]['new_value'] === 'Mr & Mrs Banda'
        && (int)$h[0]['performed_by_id'] === $adminId && strpos((string)$h[0]['description'], 'set to "Mr & Mrs Banda"') !== false, json_encode($h));
    ok('G3 no History on the page\'s own room', count($hist($A)) === 0);
    ok('G3 open room: no invoice rebuild', $refreshed === []);

    $r = rh_group_room_guest_save($pdo, $A, $B, 'Mr & Mrs Banda', $opts);
    ok('G3 same name again: no write, no History', $r['success'] && !$r['changed'] && count($hist($B)) === 1, json_encode($r));

    $r = rh_group_room_guest_save($pdo, $B, $B, 'Grace Phiri', $opts);
    $h = $hist($B);
    ok('G3 change on its own page: old -> new in History', $r['success'] && $name($B) === 'Grace Phiri' && count($h) === 2 && $h[1]['old_value'] === 'Mr & Mrs Banda' && $h[1]['new_value'] === 'Grace Phiri', json_encode($h));

    $long = str_repeat('Ab', 100) . "\x07tail";
    $r = rh_group_room_guest_save($pdo, $A, $B, $long, $opts);
    ok('G3 capped at 150 characters', $r['success'] && mb_strlen((string)$name($B)) === 150, (string)mb_strlen((string)$name($B)));

    $r = rh_group_room_guest_save($pdo, $A, $B, '   ', $opts);
    $h = $hist($B);
    ok('G3 empty clears to NULL, display falls back to the lead booker', $r['success'] && $name($B) === null && $r['display'] === 'Grat Lead 2' && end($h)['new_value'] === '(none)', json_encode($r));

    $refreshed = [];
    $r = rh_group_room_guest_save($pdo, $A, $C, 'After Checkout', $opts);
    ok('G3 checked-out room: saved and its invoices rebuilt', $r['success'] && $name($C) === 'After Checkout' && $refreshed === [$C] && strpos($r['message'], '[refreshed]') !== false, json_encode($r));

    $r = rh_group_room_guest_save($pdo, $A, $D, 'Cancelled Room', $opts);
    ok('G3 cancelled room: allowed (any status)', $r['success'] && $name($D) === 'Cancelled Room');

    $r = rh_group_room_guest_save($pdo, $A, $E, 'Deleted', $opts);
    ok('G3 deleted room: refused, unchanged', !$r['success'] && $name($E) === null && count($hist($E)) === 0, json_encode($r));

    $r = rh_group_room_guest_save($pdo, $A, $X, 'Other', $opts);
    ok('G3 room outside the group: refused', !$r['success'] && $name($X) === null, json_encode($r));

    $r = rh_group_room_guest_save($pdo, $A, $B, 'Nope', ['user_id' => 0] + $opts);
    ok('G3 nobody signed in: refused', !$r['success'] && $r['status'] === 403 && $name($B) === null);
    if ($staffId > 0 && !hasPermission($staffId, 'edit_booking')) {
        $r = rh_group_room_guest_save($pdo, $A, $B, 'Nope', ['user_id' => $staffId] + $opts);
        ok('G3 staff without Edit Booking: refused', !$r['success'] && $r['status'] === 403 && $name($B) === null, json_encode($r));
    } else {
        echo "SKIP staff permission check: no active test.staff without Edit Booking\n";
    }
    ok('G3 runs inside the caller\'s transaction (no nested begin)', $pdo->inTransaction());
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ---- The real page through the rolled-back harness (a mirrored Liwonde group) ------------------------------------------
$g = $pdo->query("SELECT m.id AS member, m.primary_booking_id AS lead_id FROM bookings m JOIN bookings l ON l.id = m.primary_booking_id
    WHERE m.deleted_at IS NULL AND l.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$h = $root . '/scripts/tests/page_harness.php';
$run = static function (array $cfg) use ($h): array {
    $out = shell_exec('php ' . escapeshellarg($h) . ' ' . escapeshellarg('admin/booking-details.php') . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { return $r; } }
    return ['error' => 'no result', 'raw' => substr((string)$out, -400)];
};
$clean = static fn(array $r): bool => !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']);
if (!$g) {
    echo "SKIP page checks: no group at this hotel\n";
} else {
    $lead = (int)$g['lead_id']; $member = (int)$g['member'];
    $before = $name($member);
    $post = ['action' => 'group_room_guest_save', 'target_id' => (string)$member, 'room_guest_name' => 'Harness Name'];
    $verify = ["SELECT room_guest_name FROM bookings WHERE id = $member",
        "SELECT COUNT(*) AS n FROM booking_timeline_logs WHERE booking_id = $member AND action = 'Name on the room changed' AND new_value = 'Harness Name'"];
    $r = $run(['ajax' => true, 'get' => ['id' => (string)$lead], 'post' => $post, 'verify' => $verify]);
    ok('page: admin saves the name on another room of the group', !empty($r['json']['success']) && ($r['verify'][0][0]['room_guest_name'] ?? '') === 'Harness Name'
        && (int)($r['verify'][1][0]['n'] ?? 0) === 1, json_encode($r['json'] ?? $r));
    ok('page run rolled back', $clean($r), json_encode($r));
    $r = $run(['ajax' => true, 'get' => ['id' => (string)$lead], 'post' => $post + ['csrf_token' => 'not-the-token'], 'verify' => [$verify[0]]]);
    ok('page: no valid CSRF token -> refused, unchanged', empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? ''), 'token') !== false
        && ($r['verify'][0][0]['room_guest_name'] ?? null) === $before, json_encode($r['json'] ?? $r));
    if ($staffId > 0 && !hasPermission($staffId, 'edit_booking')) {
        $r = $run(['ajax' => true, 'as_username' => 'test.staff', 'get' => ['id' => (string)$lead], 'post' => $post, 'verify' => [$verify[0]]]);
        ok('page: staff without Edit Booking -> refused, unchanged', empty($r['json']['success']) && ($r['verify'][0][0]['room_guest_name'] ?? null) === $before, json_encode($r['json'] ?? $r));
    }
    // G2 landing: ?open=dates&from=<lead> on the member shows the way back and opens the dialog the page's flags pick.
    $r = $run(['method' => 'GET', 'get' => ['id' => (string)$member, 'open' => 'dates', 'from' => (string)$lead],
        'grep' => ['bd-group-jump__back', 'Back to the group', "window[\"openDateAdjustModal\"]", "window[\"openStayCorrectModal\"]", 'data-who-edit', 'open=dates&amp;from=' . $member]]);
    $f = $r['found'] ?? [];
    $mSt = strtolower((string)$pdo->query("SELECT status FROM bookings WHERE id = $member")->fetchColumn());
    $want = $mSt === 'checked-out' ? 'window["openStayCorrectModal"]' : (in_array($mSt, ['cancelled', 'no-show', 'expired'], true) ? '' : 'window["openDateAdjustModal"]');
    ok('page: landing shows "Back to the group"', !empty($f['Back to the group']) && !empty($f['bd-group-jump__back']), json_encode($f));
    ok("page: landing opens the right dialog for a $mSt room", $want === '' ? (empty($f['window["openDateAdjustModal"]']) && empty($f['window["openStayCorrectModal"]'])) : !empty($f[$want]), json_encode($f));
    ok('page: rows carry Who\'s staying and Change dates links back to this page', !empty($f['data-who-edit']) && !empty($f['open=dates&amp;from=' . $member]), json_encode($f));
    $r = $run(['method' => 'GET', 'get' => ['id' => (string)$member, 'open' => 'dates', 'from' => '1'], 'grep' => ['bd-group-jump__back']]);
    ok('page: a "from" outside the group is ignored', empty($r['found']['bd-group-jump__back']), json_encode($r['found'] ?? $r));
    ok('page GET runs rolled back', $clean($r));
}

// ---- leak check ----------------------------------------------------------------------------------------------
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GRAT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GRAT-%' OR new_value = 'Harness Name'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE room_guest_name = 'Harness Name'")->fetchColumn();
ok('leak check: no test row left behind', $leak === 0, (string)$leak);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
