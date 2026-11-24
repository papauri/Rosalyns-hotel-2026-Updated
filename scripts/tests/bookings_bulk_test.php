<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * All Bookings bulk actions (G5a): admin/includes/bookings-bulk.php.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/bookings_bulk_test.php
 * Checks: the ids whitelist (POST and GET), per-room skip reasons for every op, the permission skip, the review token,
 * Make a group / Add to a group / Remove from group through the same group helpers, and that the test hotel's mail
 * guard is on. NO email is sent: send ops are only planned, or applied where every room is skipped.
 * Builds its OWN rows (references GBLK-*, emails @example.invalid) inside ONE transaction that is rolled back; the
 * mirrored ICTAm group is never touched. A leak check counts every marker row after the rollback.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/cancellation-policy.php';
require_once $root . '/config/email.php';
require_once $root . '/config/invoice.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/bookings-bulk.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// ---- mail guard: this is the test hotel, every email would go to the test recipient (none is sent here) -----------
ok('mail guard: test platform mode is on (no real guest gets mail)', function_exists('rh_test_platform_active') && rh_test_platform_active());

// ---- ids whitelist ------------------------------------------------------------------------------------------------
$_POST['bulk_ids'] = ['12', 'abc', '-3', '12', '0', '1234567890123456789', ' 5', '7', ['9'], '1e3'];
ok('POST ids: digits only, unique, no zero / negative / padded / nested', adminBulkIds() === [12, 7], json_encode(adminBulkIds()));
$_POST['booking_ids'] = ['4', '4', 'x', '6'];
ok('POST ids under booking_ids (Make a group)', adminBulkIds('booking_ids') === [4, 6]);
unset($_POST['bulk_ids'], $_POST['booking_ids']);
ok('GET ids: "3,abc,3, 4 ,0,-1" -> [3,4]', rh_bk_bulk_get_ids('3,abc,3, 4 ,0,-1') === [3, 4], json_encode(rh_bk_bulk_get_ids('3,abc,3, 4 ,0,-1')));
ok('GET ids: not a string -> none', rh_bk_bulk_get_ids(['1']) === [] && rh_bk_bulk_get_ids(null) === []);
ok('unknown op refused', rh_bk_bulk_plan($pdo, 'delete_all', [1], 0)['error'] === 'Unknown bulk action.');
ok('no ids refused', rh_bk_bulk_plan($pdo, 'reminder', [], 0)['error'] === 'Tick at least one booking first.');
ok('send batch capped at 50', strpos(rh_bk_bulk_plan($pdo, 'reminder', range(1, 51), 0)['error'], 'at most 50') !== false);

// An active administrator who has Edit Booking (permission-allowed runs); user 0 has nothing.
$adminId = 0;
foreach ($pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    if (hasPermission((int)$aid, 'edit_booking')) { $adminId = (int)$aid; break; }
}
ok('setup: an administrator with Edit Booking exists', $adminId > 0);
$admin = ['id' => $adminId, 'full_name' => 'bookings_bulk_test', 'username' => 'bookings_bulk_test'];

// Warm up anything that might run DDL (an implicit commit inside the transaction would leak rows).
$src = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL AND (primary_booking_id IS NULL OR primary_booking_id = 0)
    AND id <> 2100008837 AND NOT EXISTS (SELECT 1 FROM bookings m WHERE m.primary_booking_id = bookings.id) ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($src <= 0) { fwrite(STDERR, "No source booking to clone.\n"); exit(1); }
getBookingFolioSummary($src);
rh_billing_ready($pdo);
rh_cancel_policy_column_ready($pdo);
rh_invoice_paid_refusal($pdo, 'room', $src);
ensureBookingAuditTable($pdo);
$anyType = (int)$pdo->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
rh_room_assignment_candidates($anyType, date('Y-m-d', strtotime('+500 days')), date('Y-m-d', strtotime('+501 days')), 0, false);
roomTypeHasActiveCombinations($anyType);
evaluateCheckInRoomReady($src, 0, false);
$auditGblkBefore = (int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn();
$ictamBefore = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0)) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();

$cols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$n = 0;
$today = date('Y-m-d');
$mk = static function (array $over = []) use ($pdo, $cols, $src, &$n, $today): int {
    $n++;
    $over += ['booking_reference' => 'GBLK-' . getmypid() . '-' . $n, 'guest_email' => 'gblk' . $n . '@example.invalid', 'guest_name' => 'Gblk Test ' . $n,
        'primary_booking_id' => null, 'billing_account_id' => null, 'billing_reference' => null, 'billing_due_date' => null, 'billing_covers' => null,
        'final_invoice_number' => null, 'deleted_at' => null, 'status' => 'confirmed', 'client_uuid' => null, 'cancellation_policy_snapshot' => null,
        'individual_room_id' => null, 'room_combination_id' => null, 'room_guest_name' => null,
        'check_in_date' => date('Y-m-d', strtotime($today . ' +5 days')), 'check_out_date' => date('Y-m-d', strtotime($today . ' +7 days'))];
    $sel = [];
    $args = [];
    foreach ($cols as $c) {
        if ($c === 'id') { continue; }
        if (array_key_exists($c, $over)) { $sel[] = '?'; $args[] = $over[$c]; } else { $sel[] = '`' . $c . '`'; }
    }
    $ins = array_values(array_filter($cols, static fn($c) => $c !== 'id'));
    $pdo->prepare('INSERT INTO bookings (`' . implode('`,`', $ins) . '`) SELECT ' . implode(',', $sel) . ' FROM bookings WHERE id = ?')->execute(array_merge($args, [$src]));
    return (int)$pdo->lastInsertId();
};
$get = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$hist = static function (int $id, string $action) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND action = ?');
    $st->execute([$id, $action]);
    return (int)$st->fetchColumn();
};
$room = static function (array $plan, int $id): array {
    foreach ($plan['rooms'] as $r) { if ((int)$r['id'] === $id) { return $r; } }
    return [];
};
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$audit = static function (int $id, string $action) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_audit_log WHERE booking_id = ? AND action = ?');
    $st->execute([$id, $action]);
    return (int)$st->fetchColumn();
};
$day = static fn(int $d): string => date('Y-m-d', strtotime($today . ' ' . ($d >= 0 ? '+' : '') . $d . ' days'));
$tag = strtoupper(bin2hex(random_bytes(3)));

$pdo->beginTransaction();
try {
    // ---- per-room skip reasons (send ops are planned only) ------------------------------------------------------
    $P1 = $mk(['amount_due' => 500]);                                                           // confirmed, arrives in 5 days
    $P2 = $mk(['check_in_date' => $yesterday, 'check_out_date' => date('Y-m-d', strtotime($today . ' +2 days'))]); // missed check-in
    $P3 = $mk(['check_in_date' => $yesterday, 'check_out_date' => date('Y-m-d', strtotime($today . ' +2 days')), 'guest_email' => '']);
    $P4 = $mk(['status' => 'cancelled', 'amount_due' => 0]);
    $P5 = $mk(['deleted_at' => date('Y-m-d H:i:s')]);
    $P6 = $mk(['status' => 'pending']);
    $P7 = $mk(['status' => 'checked-out', 'amount_due' => 0]);
    $P8 = $mk(['status' => 'checked-in', 'check_in_date' => date('Y-m-d', strtotime($today . ' -3 days')), 'check_out_date' => $yesterday]); // overdue checkout
    $missing = 999999999999;
    $ids = [$P1, $P2, $P3, $P4, $P5, $P6, $P7, $P8, $missing];

    $rem = rh_bk_bulk_plan($pdo, 'reminder', $ids, $adminId);
    ok('reminder: a future arrival is skipped (not late)', ($room($rem, $P1)['why'] ?? '') === 'not late');
    ok('reminder: a missed check-in will be sent', !empty($room($rem, $P2)['will']) && strpos($room($rem, $P2)['text'], 'missed check-in') !== false, $room($rem, $P2)['text'] ?? '');
    ok('reminder: an overdue checkout will be sent', !empty($room($rem, $P8)['will']) && strpos($room($rem, $P8)['text'], 'overdue checkout') !== false, $room($rem, $P8)['text'] ?? '');
    ok('reminder: no email address is skipped', ($room($rem, $P3)['why'] ?? '') === 'no email address');
    ok('reminder: a cancelled booking is skipped', ($room($rem, $P4)['why'] ?? '') === 'not an active booking');
    ok('reminder: a deleted booking is skipped', ($room($rem, $P5)['why'] ?? '') === 'deleted');
    ok('reminder: a missing booking is skipped', ($room($rem, $missing)['why'] ?? '') === 'not found');
    ok('reminder: counts (2 will, 7 skipped) and a review token', $rem['will'] === 2 && $rem['skip'] === 7 && strlen($rem['state']) === 40, $rem['will'] . '/' . $rem['skip']);
    ok('review keeps the ticked order', array_column($rem['rooms'], 'id') === $ids);

    $con = rh_bk_bulk_plan($pdo, 'confirmation', $ids, $adminId);
    ok('confirmation: confirmed -> booking confirmed email', strpos($room($con, $P1)['text'] ?? '', 'booking confirmed email') !== false);
    ok('confirmation: pending -> booking received email', strpos($room($con, $P6)['text'] ?? '', 'booking received email') !== false);
    ok('confirmation: cancelled / checked-out have nothing to resend', ($room($con, $P4)['why'] ?? '') === 'no confirmation for this status' && ($room($con, $P7)['why'] ?? '') === 'no confirmation for this status');

    $inv = rh_bk_bulk_plan($pdo, 'invoice', $ids, $adminId);
    ok('invoice: checked-out owing nothing is skipped', ($room($inv, $P7)['why'] ?? '') === 'nothing owed');
    ok('invoice: pending is skipped (not confirmed)', ($room($inv, $P6)['why'] ?? '') === 'not confirmed');
    $p1Inv = $room($inv, $P1);
    ok('invoice: a confirmed booking that owes is sent (or skipped by the paid rule)', !empty($p1Inv['will']) || ($p1Inv['why'] ?? '') === 'fully paid', json_encode($p1Inv));

    // Permission: Remove from group needs Edit Booking (the single action's key); a user without it gets every room skipped.
    $noPerm = rh_bk_bulk_plan($pdo, 'remove_group', [$P1, $P2], 0);
    ok('permission: no Edit Booking -> every room skipped with the reason', $noPerm['will'] === 0
        && count(array_filter($noPerm['rooms'], static fn($r) => $r['why'] === 'no permission' && strpos($r['text'], 'permission') !== false)) === 2);
    ok('permission: emails need no extra key (same as the single Resend email)', !empty($room(rh_bk_bulk_plan($pdo, 'reminder', [$P2], 0), $P2)['will']));

    // Apply where every room is skipped: nothing is sent, the outcome says why.
    $skipAll = rh_bk_bulk_plan($pdo, 'reminder', [$P1, $P4], $adminId);
    $ap = rh_bk_bulk_apply($pdo, 'reminder', [$P1, $P4], $admin, $skipAll['state']);
    ok('apply: all skipped -> 0 sent with reasons', !empty($ap['success']) && $ap['done'] === 0
        && $ap['message'] === '0 sent, 2 skipped (not late x1; not an active booking x1).', $ap['message'] ?? $ap['error']);
    $stale = rh_bk_bulk_apply($pdo, 'reminder', [$P1, $P4], $admin, sha1('other'));
    ok('apply: a different review token does nothing', empty($stale['success']) && strpos($stale['error'], 'changed since you reviewed') !== false);
    $threw = '';
    try { bookings_send_booking_email($pdo, $get($P1), 'not_a_type', [], $admin); } catch (Throwable $e) { $threw = $e->getMessage(); }
    ok('shared sender: an unknown email type is refused (nothing sent)', $threw === 'Invalid email type selected', $threw);
    ok('shared sender: the email-log context is cleared afterwards', rh_email_context_current() === []);

    // ---- Make a group: rooms that cannot join are skipped with their reason ---------------------------------------
    $G1 = $mk();
    $G2 = $mk();
    $G3 = $mk(['status' => 'cancelled', 'amount_due' => 0]);    // cancelled, owes nothing: cannot join
    $H = $mk();
    $H2 = $mk();
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$H, $H2]);
    $f = rh_bk_bulk_group_filter($pdo, $G1, [$G1, $G2, $G3, $H2, $missing]);
    ok('make filter: keeps the lead and the rooms that can join', $f['ids'] === [$G1, $G2], json_encode($f['ids']));
    $why = array_column($f['skipped'], 'reason', 'id');
    ok('make filter: cancelled owing nothing skipped with the G4 reason', strpos($why[$G3] ?? '', 'cancelled and owes nothing') !== false, $why[$G3] ?? '');
    ok('make filter: a room in another group skipped', strpos($why[$H2] ?? '', 'already in another group') !== false);
    ok('make filter: a missing booking skipped', isset($why[$missing]));
    $amt = (float)$get($G2)['amount_due'];
    $pv = rh_group_make($pdo, $G1, $f['ids'], ['dry_run' => true, 'user_id' => $adminId, 'user_name' => 'bookings_bulk_test']);
    $mk1 = rh_group_make($pdo, $G1, $f['ids'], ['expect_state' => (string)$pv['state'], 'user_id' => $adminId, 'user_name' => 'bookings_bulk_test']);
    ok('make: the group is made through rh_group_make', !empty($mk1['success']) && (int)$get($G2)['primary_booking_id'] === $G1, $mk1['error'] ?? '');
    ok('make: History "Added to a group" on the joining room', $hist($G2, 'Added to a group') === 1);
    ok('make: no money moved', abs((float)$get($G2)['amount_due'] - $amt) < (float)BALANCE_TOLERANCE);

    // ---- Add to a group: any member's reference -> the group's primary room leads -----------------------------------
    $lf = rh_bk_bulk_group_lead_for_ref($pdo, (string)$get($G2)['booking_reference']);
    ok('add: a member reference resolves to the primary room', $lf['lead'] === $G1, json_encode($lf));
    ok('add: a single booking is not a group', strpos(rh_bk_bulk_group_lead_for_ref($pdo, (string)$get($P1)['booking_reference'])['error'], 'not in a group') !== false);
    ok('add: an unknown reference is refused', strpos(rh_bk_bulk_group_lead_for_ref($pdo, 'GBLK-NO-SUCH')['error'], 'No booking has the reference') !== false);
    $G5 = $mk();
    $f2 = rh_bk_bulk_group_filter($pdo, $lf['lead'], [$G5, $G3]);
    $pv2 = rh_group_make($pdo, $lf['lead'], $f2['ids'], ['dry_run' => true]);
    $add = rh_group_make($pdo, $lf['lead'], $f2['ids'], ['expect_state' => (string)$pv2['state'], 'user_id' => $adminId, 'user_name' => 'bookings_bulk_test']);
    ok('add: the ticked room joins, the cancelled one is skipped', !empty($add['success']) && (int)$get($G5)['primary_booking_id'] === $G1 && count($f2['skipped']) === 1, $add['error'] ?? '');

    // ---- Remove from group: rh_group_remove per room, members first, root last ---------------------------------------
    $rp = rh_bk_bulk_plan($pdo, 'remove_group', [$G1, $G2, $G5, $P1], $adminId);
    ok('remove plan: 3 in the group will go, a single booking is skipped', $rp['will'] === 3 && ($room($rp, $P1)['why'] ?? '') === 'not in a group', json_encode(array_column($rp['rooms'], 'why')));
    ok('remove plan: the primary room says who leads the rest', strpos($room($rp, $G1)['text'] ?? '', 'becomes the primary room') !== false);
    $ra = rh_bk_bulk_apply($pdo, 'remove_group', [$G1, $G2, $G5, $P1], $admin, $rp['state']);
    ok('remove apply: outcome', !empty($ra['success']) && $ra['done'] === 3 && $ra['message'] === '3 taken out of their group, 1 skipped (not in a group x1).', $ra['message'] ?? $ra['error']);
    $left = array_map(static fn($id) => (int)$get($id)['primary_booking_id'], [$G1, $G2, $G5]);
    ok('remove apply: every ticked room is on its own', $left === [0, 0, 0] && (int)$pdo->query('SELECT COUNT(*) FROM bookings WHERE primary_booking_id = ' . (int)$G1)->fetchColumn() === 0, json_encode($left));
    ok('remove apply: History "Removed from the group" on the members', $hist($G2, 'Removed from the group') === 1 && $hist($G5, 'Removed from the group') === 1);
    ok('remove apply: results keep the ticked order', array_column($ra['results'], 'id') === [$G1, $G2, $G5, $P1]);
    // Only the primary room of a 3-room group ticked: it leaves, the next room leads the other two.
    $H3 = $mk();
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$H, $H3]);
    $hp = rh_bk_bulk_plan($pdo, 'remove_group', [$H], $adminId);
    $ha = rh_bk_bulk_apply($pdo, 'remove_group', [$H], $admin, $hp['state']);
    ok('remove primary only: it leaves, the group is handed on', !empty($ha['success']) && $ha['done'] === 1 && (int)$get($H)['primary_booking_id'] === 0
        && (int)$get($H3)['primary_booking_id'] === $H2, json_encode([$get($H)['primary_booking_id'], $get($H2)['primary_booking_id'], $get($H3)['primary_booking_id']]));
    ok('remove: a deleted booking is skipped', ($room(rh_bk_bulk_plan($pdo, 'remove_group', [$P5], $adminId), $P5)['why'] ?? '') === 'deleted');
    // Ship Q1 (owner 2026-11-18): rooms inheriting the group's pay later get a keep / drop pick (per room, no default).
    $off = ['checkin_without_payment' => 0, 'checkin_arrangement_note' => null];
    $K1 = $mk(['checkin_without_payment' => 1, 'checkin_arrangement_note' => 'GBLK monthly account']);
    $K2 = $mk($off);
    $K3 = $mk($off);
    $K4 = $mk($off);
    $K5 = $mk(['checkin_without_payment' => 1, 'checkin_arrangement_note' => 'GBLK own']);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id IN (?, ?, ?, ?)')->execute([$K1, $K2, $K3, $K4, $K5]);
    $kp = rh_bk_bulk_plan($pdo, 'remove_group', [$K2, $K3, $K4, $K5], $adminId);
    $kOpts = array_column($room($kp, $K2)['options'] ?? [], 'value');
    ok('Q1 plan: an inheriting room offers keep / drop with nothing picked, and says so', $kOpts === ['', 'keep', 'drop'] && ($room($kp, $K2)['pick'] ?? 'x') === ''
        && strpos($room($kp, $K2)['text'] ?? '', 'pay later from its group (note: GBLK monthly account)') !== false, json_encode($room($kp, $K2)));
    ok('Q1 plan: a room with its own switch gets no pick', !isset($room($kp, $K5)['options']) && $kp['will'] === 4);
    $ka = rh_bk_bulk_apply($pdo, 'remove_group', [$K2, $K3, $K4, $K5], $admin, $kp['state'], null, [$K2 => 'keep', $K3 => 'drop']);
    $rK = static function (array $res, int $id): array { foreach ($res['results'] as $x) { if ((int)$x['id'] === $id) { return $x; } } return []; };
    ok('Q1 apply: keep / drop applied, a missing pick is refused for that room only', !empty($ka['success']) && $ka['done'] === 3
        && !empty($rK($ka, $K2)['ok']) && strpos($rK($ka, $K2)['text'], 'Keeps check in now, pay later') !== false
        && !empty($rK($ka, $K3)['ok']) && strpos($rK($ka, $K3)['text'], 'Pay later dropped') !== false
        && empty($rK($ka, $K4)['ok']) && strpos($rK($ka, $K4)['text'], 'Skipped: ') === 0 && !empty($rK($ka, $K5)['ok']), json_encode($ka));
    $bK2 = $get($K2);
    $bK3 = $get($K3);
    ok('Q1 apply: keep copies the switch + note; drop copies nothing; the unpicked room stays in the group', (int)$bK2['checkin_without_payment'] === 1
        && $bK2['checkin_arrangement_note'] === 'GBLK monthly account' && (int)$bK2['primary_booking_id'] === 0
        && (int)$bK3['checkin_without_payment'] === 0 && (int)$bK3['primary_booking_id'] === 0 && (int)$get($K4)['primary_booking_id'] === $K1
        && (int)$get($K5)['checkin_without_payment'] === 1 && $get($K5)['checkin_arrangement_note'] === 'GBLK own', json_encode([$bK2['checkin_without_payment'], $bK3['checkin_without_payment']]));
    ok('Q1 apply: History "Kept pay-later from group" / "Dropped pay-later from group" once each, none on the others',
        $hist($K2, 'Kept pay-later from group') === 1 && $hist($K3, 'Dropped pay-later from group') === 1 && $hist($K4, 'Removed from the group') === 0
        && $hist($K5, 'Kept pay-later from group') + $hist($K5, 'Dropped pay-later from group') === 0);

    // ---- Print arrival list rows ------------------------------------------------------------------------------------
    $arr = rh_bk_bulk_arrival_rows($pdo, [$P1, $P5, $P2]);
    ok('arrival list: deleted left out, sorted by check-in', array_column($arr, 'reference') === [(string)$get($P2)['booking_reference'], (string)$get($P1)['booking_reference']], json_encode(array_column($arr, 'reference')));
    ok('arrival list: balance is what is still owed', abs($arr[1]['balance'] - 500.0) < (float)BALANCE_TOLERANCE, (string)($arr[1]['balance'] ?? ''));

    // ---- G5b fixtures: a throw-away room type with three clean rooms (no joined pairs), all rolled back ------------
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', 100, 2, 3, 3, 1, ?)")
        ->execute(['ZZ GBLK type ' . $tag, 'zz-gblk-' . strtolower($tag)]);
    $T = (int)$pdo->lastInsertId();
    $mkRoom = static function (string $num) use ($pdo, $T, $tag): int {
        $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, floor, status, housekeeping_status, is_active) VALUES (?, ?, 'zz gblk', 1, 'available', 'completed', 1)")
            ->execute([$T, 'ZB' . $num . $tag]);
        return (int)$pdo->lastInsertId();
    };
    $R1 = $mkRoom('1');
    $R2 = $mkRoom('2');
    $R3 = $mkRoom('3');
    $onT = ['room_id' => $T, 'number_of_guests' => 2, 'adult_guests' => 2, 'child_guests' => 0, 'is_tentative' => 0, 'payment_status' => 'unpaid'];
    $giveRoom = static function (int $bid, int $rid) use ($pdo): void {
        $pdo->prepare('UPDATE bookings SET individual_room_id = ? WHERE id = ?')->execute([$rid, $bid]);
        syncBookingRooms($bid, [$rid]);
    };

    // ---- Confirm: tentative hold / pending / not confirmable / no room free / permission ------------------------------
    $K1 = $mk(['status' => 'tentative', 'is_tentative' => 1, 'check_in_date' => $day(410), 'check_out_date' => $day(412)] + $onT);
    $K2 = $mk(['status' => 'pending', 'check_in_date' => $day(410), 'check_out_date' => $day(412)] + $onT);
    $K3 = $mk(['check_in_date' => $day(410), 'check_out_date' => $day(412)] + $onT);
    $pdo->prepare("INSERT INTO rooms (name, description, short_description, price_per_night, max_guests, rooms_available, total_rooms, is_active, slug) VALUES (?, '', '', 100, 2, 0, 0, 1, ?)")
        ->execute(['ZZ GBLK full ' . $tag, 'zz-gblk-full-' . strtolower($tag)]);
    $Tfull = (int)$pdo->lastInsertId();
    $K4 = $mk(['status' => 'pending', 'check_in_date' => $day(410), 'check_out_date' => $day(412), 'room_id' => $Tfull] + $onT);
    $cp = rh_bk_bulk_plan($pdo, 'confirm', [$K1, $K2, $K3, $K4], $adminId);
    ok('confirm: a tentative hold is converted (same as the row)', !empty($room($cp, $K1)['will']) && strpos($room($cp, $K1)['text'], 'Confirms the tentative booking') === 0, $room($cp, $K1)['text'] ?? '');
    ok('confirm: a pending booking with a free room will be confirmed', !empty($room($cp, $K2)['will']) && strpos($room($cp, $K2)['text'], 'Confirms the booking.') === 0, $room($cp, $K2)['text'] ?? '');
    ok('confirm: a confirmed booking is skipped', ($room($cp, $K3)['why'] ?? '') === 'not pending or tentative');
    ok('confirm: no free room of its type is skipped', ($room($cp, $K4)['why'] ?? '') === 'no room free', json_encode($room($cp, $K4)));
    $cpNo = rh_bk_bulk_plan($pdo, 'confirm', [$K2], 0);
    ok('confirm: no Edit Booking -> skipped for permission', ($room($cpNo, $K2)['why'] ?? '') === 'no permission');

    // ---- Assign rooms: one proposal per room, never the same room twice for overlapping nights -----------------------
    $A1 = $mk(['check_in_date' => $day(400), 'check_out_date' => $day(402)] + $onT);
    $A2 = $mk(['check_in_date' => $day(400), 'check_out_date' => $day(402)] + $onT);
    $A3 = $mk(['check_in_date' => $day(401), 'check_out_date' => $day(403)] + $onT);
    $A4 = $mk(['check_in_date' => $day(401), 'check_out_date' => $day(404)] + $onT);
    $A5 = $mk(['status' => 'pending', 'check_in_date' => $day(400), 'check_out_date' => $day(402)] + $onT);
    $A6 = $mk(['check_in_date' => $day(402), 'check_out_date' => $day(403)] + $onT); // starts on A1's checkout day
    $ap1 = rh_bk_bulk_plan($pdo, 'assign', [$A1, $A2, $A3, $A4, $A5], $adminId);
    $picks = array_filter([$room($ap1, $A1)['pick'] ?? '', $room($ap1, $A2)['pick'] ?? '', $room($ap1, $A3)['pick'] ?? '']);
    ok('assign: three overlapping bookings get three different rooms', count($picks) === 3 && count(array_unique($picks)) === 3, json_encode($picks));
    ok('assign: the fourth overlapping booking is skipped (every free room already proposed)', ($room($ap1, $A4)['why'] ?? '') === 'no free room'
        && strpos($room($ap1, $A4)['text'], 'already proposed') !== false, $room($ap1, $A4)['text'] ?? '');
    ok('assign: a pending booking is skipped', ($room($ap1, $A5)['why'] ?? '') === 'not confirmed');
    ok('assign: each will-row lists the free rooms to pick from', count($room($ap1, $A1)['options'] ?? []) === 3);
    $amtA = (float)$get($A1)['total_with_vat'];
    // The person picks A1's room for A2 too: A1 gets it, A2 is refused (room chosen twice), A3 keeps its proposal.
    $dup = (string)$room($ap1, $A1)['pick'];
    $aa = rh_bk_bulk_apply($pdo, 'assign', [$A1, $A2, $A3, $A4, $A5], $admin, $ap1['state'], null, [$A2 => $dup]);
    $res = array_column($aa['results'] ?? [], null, 'id');
    ok('assign apply: 2 given a room', !empty($aa['success']) && $aa['done'] === 2, $aa['message'] ?? $aa['error']);
    ok('assign apply: the same room chosen twice is refused', !$res[$A2]['ok'] && strpos($res[$A2]['text'], 'already given to another ticked booking') !== false, $res[$A2]['text'] ?? '');
    ok('assign apply: rooms held as chosen', getBookingRoomIds($A1) === [(int)substr($dup, 2)] && getBookingRoomIds($A2) === []
        && getBookingRoomIds($A3) === [(int)substr((string)$room($ap1, $A3)['pick'], 2)], json_encode([getBookingRoomIds($A1), getBookingRoomIds($A3)]));
    ok('assign apply: History "room_assigned" via the row\'s own path', $audit($A1, 'room_assigned') === 1 && $audit($A3, 'room_assigned') === 1 && $audit($A2, 'room_assigned') === 0);
    ok('assign apply: the price did not change', abs((float)$get($A1)['total_with_vat'] - $amtA) < (float)BALANCE_TOLERANCE
        && strpos($res[$A1]['text'], 'The price did not change') !== false, $res[$A1]['text'] ?? '');
    ok('assign: the checkout day is free (A6 can take A1\'s room)', in_array($dup, array_column($room(rh_bk_bulk_plan($pdo, 'assign', [$A6], $adminId), $A6)['options'] ?? [], 'value'), true));
    // A stale choice (a room given away since) is refused, nothing changed.
    $ap2 = rh_bk_bulk_plan($pdo, 'assign', [$A2], $adminId);
    $aa2 = rh_bk_bulk_apply($pdo, 'assign', [$A2], $admin, $ap2['state'], null, [$A2 => $dup]);
    ok('assign: a room no longer free is refused', !empty($aa2['success']) && $aa2['done'] === 0 && ($aa2['results'][0]['text'] ?? '') === 'Skipped: the room chosen is no longer free for these dates. Nothing was changed.'
        && getBookingRoomIds($A2) === [], json_encode($aa2));
    // Availability re-checked at confirm: the last free room is taken by another booking after the review.
    $ap3 = rh_bk_bulk_plan($pdo, 'assign', [$A2], $adminId);
    $last = (int)substr((string)($room($ap3, $A2)['pick'] ?? 'r:0'), 2);
    $single = bookings_assign_individual_room($pdo, $A4, $last, $admin);
    $aa3 = rh_bk_bulk_apply($pdo, 'assign', [$A2], $admin, $ap3['state']);
    ok('assign: taken since the review -> nothing done', !empty($single['success']) && empty($aa3['success']) && strpos($aa3['error'], 'changed since you reviewed') !== false
        && getBookingRoomIds($A2) === [], ($single['message'] ?? '') . ' / ' . ($aa3['error'] ?? $aa3['message']));

    // ---- Check in: every gate skips with a plain reason ------------------------------------------------------------
    $C1 = $mk(['check_in_date' => $today, 'check_out_date' => $day(2), 'payment_status' => 'paid'] + $onT);
    $giveRoom($C1, $R1);
    $C2 = $mk(['check_in_date' => $day(5), 'check_out_date' => $day(7), 'payment_status' => 'paid'] + $onT);
    $C3 = $mk(['check_in_date' => $today, 'check_out_date' => $day(2), 'amount_due' => 500, 'amount_paid' => 0, 'checkin_without_payment' => 0] + $onT);
    $C4 = $mk(['check_in_date' => $yesterday, 'check_out_date' => $day(2), 'payment_status' => 'paid'] + $onT);
    $giveRoom($C4, $R2);
    $C5 = $mk(['check_in_date' => $today, 'check_out_date' => $day(2), 'payment_status' => 'paid'] + $onT);
    $C6 = $mk(['check_in_date' => $today, 'check_out_date' => $day(2), 'payment_status' => 'paid'] + $onT);
    $giveRoom($C6, $R3);
    $pdo->prepare("UPDATE individual_rooms SET status = 'cleaning', housekeeping_status = 'pending' WHERE id = ?")->execute([$R3]);
    $C7 = $mk(['status' => 'pending', 'check_in_date' => $today, 'check_out_date' => $day(2), 'payment_status' => 'paid'] + $onT);
    $cips = [$C1, $C2, $C3, $C4, $C5, $C6, $C7];
    $cip = rh_bk_bulk_plan($pdo, 'checkin', $cips, $adminId);
    $whys = array_column($cip['rooms'], 'why', 'id');
    ok('checkin: arriving today, paid, clean room -> will check in', !empty($room($cip, $C1)['will']) && strpos($room($cip, $C1)['text'], 'No email is sent') !== false, $room($cip, $C1)['text'] ?? '');
    ok('checkin: arrival in the future skipped', $whys[$C2] === 'arrival date not reached');
    ok('checkin: no payment skipped', $whys[$C3] === 'no payment yet', $whys[$C3] . ' ' . $room($cip, $C3)['text']);
    ok('checkin: late arrival skipped (needs the row\'s question)', $whys[$C4] === 'late arrival');
    ok('checkin: no room assigned skipped', $whys[$C5] === 'no room assigned');
    ok('checkin: room not clean skipped', $whys[$C6] === 'room not ready', $room($cip, $C6)['text'] ?? '');
    ok('checkin: pending skipped', $whys[$C7] === 'not confirmed');
    ok('checkin: no Check-in permission -> skipped', ($room(rh_bk_bulk_plan($pdo, 'checkin', [$C1], 0), $C1)['why'] ?? '') === 'no permission');

    // ---- One-time confirm: the bulk apply runs once; a replay gives the first result back -------------------------
    $store = [];
    $scope = 'bulk|checkin|' . implode(',', $cips);
    $tok = rh_bk_bulk_once_issue($store, $scope);
    $runs = 0;
    $run = static function () use ($pdo, $cips, $admin, $cip, &$runs): array {
        $runs++;
        $r = rh_bk_bulk_apply($pdo, 'checkin', $cips, $admin, $cip['state']);
        return ['success' => !empty($r['success']), 'message' => $r['success'] ? $r['message'] : $r['error'], 'results' => $r['results']];
    };
    $first = rh_bk_bulk_once_run($store, $tok, $scope, $run);
    ok('checkin apply: 1 checked in, 6 skipped', !empty($first['success']) && strpos($first['message'], '1 checked in, 6 skipped') === 0, $first['message']);
    $irSt = $pdo->prepare('SELECT status FROM individual_rooms WHERE id = ?');
    $irSt->execute([$R1]);
    ok('checkin apply: booking checked in, its room occupied, History written', $get($C1)['status'] === 'checked-in' && $irSt->fetchColumn() === 'occupied' && $audit($C1, 'checked-in') === 1);
    ok('checkin apply: the skipped ones are unchanged', $get($C6)['status'] === 'confirmed' && $get($C4)['status'] === 'confirmed');
    $again = rh_bk_bulk_once_run($store, $tok, $scope, $run);
    ok('replay: first result returned, nothing run again', $runs === 1 && !empty($again['replayed']) && strpos($again['message'], 'Already done:') === 0 && $audit($C1, 'checked-in') === 1, $again['message']);
    ok('replay: another scope (other ticked rows) refused', strpos(rh_bk_bulk_once_run($store, $tok, 'bulk|checkin|1', $run)['message'], 'no longer matches') !== false && $runs === 1);
    ok('replay: an unknown token refused', empty(rh_bk_bulk_once_run($store, 'nope', $scope, $run)['success']) && $runs === 1);
    $tok2 = rh_bk_bulk_once_issue($store, 'x');
    $store[$tok2]['state'] = 'running';
    ok('a confirm still running answers "result unknown"', !empty(rh_bk_bulk_once_run($store, $tok2, 'x', $run)['unknown']) && $runs === 1);
    $tok3 = rh_bk_bulk_once_issue($store, 'y');
    $boom = rh_bk_bulk_once_run($store, $tok3, 'y', static function (): array { throw new RuntimeException('db gone'); });
    ok('a confirm that breaks part way answers "result unknown"', !empty($boom['unknown']) && empty($boom['success']) && strpos($boom['message'], 'result is unknown') !== false);
    $tok4 = rh_bk_bulk_once_issue($store, 'z');
    $nope = rh_bk_bulk_once_run($store, $tok4, 'z', static fn(): array => ['success' => false, 'message' => 'The bookings changed since you reviewed them.']);
    ok('a refused confirm is given back as it was on replay', rh_bk_bulk_once_run($store, $tok4, 'z', $run) === $nope && $runs === 1);

    // ---- G5c Cancel: per-room review (locked policy fee), skips, groups, apply through the row's own Cancel ----------
    // No email: applied test rooms are pending (only a confirmed room is emailed), have no address, and email_guest is '0'.
    // Confirmed rooms (X6, X7, X8) are only planned or refused before any write, never cancelled.
    $snapP = static fn(int $ns = 100) => json_encode(['tiers' => [['days' => 7, 'pct' => 50]], 'kind' => 'individual', 'noshow_pct' => $ns, 'policy_version' => 1, 'text' => 'GBLK G5c policy']);
    $X0 = ['status' => 'pending', 'amount_paid' => 0, 'total_with_vat' => 2400, 'guest_email' => '', 'check_in_date' => $day(5), 'check_out_date' => $day(7)] + $onT;
    $X1 = $mk($X0);                                                    // no policy: the hotel rule
    $X2 = $mk(['cancellation_policy_snapshot' => $snapP()] + $X0);     // policy: 50% inside 7 days
    $X3 = $mk(['status' => 'cancelled'] + $X0);
    $X4 = $mk(['status' => 'checked-in'] + $X0);
    $X5 = $mk(['status' => 'checked-out'] + $X0);
    $X6 = $mk(['status' => 'confirmed'] + $X0);
    // A confirmed room under the policy (a pending room owes no fee): planned only, never applied (no email loop).
    $X8 = $mk(['status' => 'confirmed', 'cancellation_policy_snapshot' => $snapP()] + $X0);
    $xp = rh_bk_bulk_plan($pdo, 'cancel', [$X1, $X2, $X3, $X4, $X5, $X6, $X8], $adminId);
    $xw = array_column($xp['rooms'], 'why', 'id');
    ok('cancel: no policy -> will cancel under the hotel rule, nothing quoted', !empty($room($xp, $X1)['will']) && ($room($xp, $X1)['quoted'] ?? 'x') === ''
        && ($room($xp, $X1)['cols']['fee'] ?? '') === 'Hotel rule', json_encode($room($xp, $X1)));
    $q8 = rh_cancel_policy_dialog_quote($pdo, $X8)['single'] ?? [];
    ok('cancel: policy room quotes the locked fee = the single Cancel dialog\'s quote', !empty($room($xp, $X8)['will']) && !empty($room($xp, $X8)['policy'])
        && ($room($xp, $X8)['quoted'] ?? '') === number_format((float)($q8['amount'] ?? -1), 2, '.', '')
        && abs((float)($q8['amount'] ?? 0) - 1200.0) < (float)BALANCE_TOLERANCE && strpos($room($xp, $X8)['text'], 'Fee kept: ') !== false
        && strpos((string)($room($xp, $X8)['cols']['fee'] ?? ''), ' x ') !== false, json_encode([$room($xp, $X8), $q8['amount'] ?? null]));
    ok('cancel: already cancelled / checked in / checked out skipped', $xw[$X3] === 'already cancelled' && $xw[$X4] === 'checked in' && $xw[$X5] === 'checked out', json_encode($xw));
    ok('cancel: email tick default as the row (confirmed yes, pending no)', !empty($room($xp, $X6)['email_default']) && empty($room($xp, $X1)['email_default']));
    ok('cancel: no Cancel Booking permission -> skipped', ($room(rh_bk_bulk_plan($pdo, 'cancel', [$X1], 0), $X1)['why'] ?? '') === 'no permission');
    $xp2 = rh_bk_bulk_plan($pdo, 'cancel', [$X1, $X2, $X3], $adminId);
    ok('cancel apply: no reason -> refused, nothing done', strpos((string)rh_bk_bulk_apply($pdo, 'cancel', [$X1, $X2, $X3], $admin, $xp2['state'], null, [], ['email_guest' => '0'])['error'], 'Say why') === 0
        && $get($X1)['status'] === 'pending');
    $xa = rh_bk_bulk_apply($pdo, 'cancel', [$X1, $X2, $X3], $admin, $xp2['state'], null, [], ['cancellation_reason' => 'GBLK bulk test', 'email_guest' => '0']);
    $xr = array_column($xa['results'] ?? [], null, 'id');
    ok('cancel apply: 2 cancelled, 1 skipped', !empty($xa['success']) && $xa['done'] === 2 && strpos($xa['message'], '2 cancelled, 1 skipped') === 0, $xa['message'] ?? $xa['error']);
    ok('cancel apply: statuses + History through the row\'s path', $get($X1)['status'] === 'cancelled' && $get($X2)['status'] === 'cancelled'
        && $audit($X1, 'cancelled') === 1 && $audit($X2, 'cancelled') === 1 && $hist($X2, 'Cancellation settled') === 1 && $get($X3)['status'] === 'cancelled');
    ok('cancel apply: result per room', strpos($xr[$X2]['text'] ?? '', 'Cancelled. ') === 0 && !$xr[$X3]['ok'], json_encode($xr));
    // A fee that changed after the review stops the confirm (the fee is in the review token).
    $X7 = $mk(['status' => 'confirmed', 'cancellation_policy_snapshot' => $snapP()] + $X0); // fee 1200, then 2400 after the change
    $xp3 = rh_bk_bulk_plan($pdo, 'cancel', [$X7], $adminId);
    $pdo->prepare('UPDATE bookings SET cancellation_policy_snapshot = ? WHERE id = ?')
        ->execute([json_encode(['tiers' => [['days' => 7, 'pct' => 100]], 'kind' => 'individual', 'noshow_pct' => 100, 'policy_version' => 2, 'text' => 'GBLK G5c policy 2']), $X7]);
    $xa3 = rh_bk_bulk_apply($pdo, 'cancel', [$X7], $admin, $xp3['state'], null, [], ['cancellation_reason' => 'GBLK bulk test', 'email_guest' => '0']);
    ok('cancel: fee changed since the review -> nothing done', empty($xa3['success']) && strpos((string)$xa3['error'], 'changed since you reviewed') !== false
        && $get($X7)['status'] === 'confirmed', ($xa3['error'] ?? '') . ' ' . ($xa3['message'] ?? ''));
    // Behind the token, the single path's own guard: a quoted fee that no longer matches is refused (bulk passes the
    // reviewed figure as fee_quoted, rh_bk_bulk_cancel_one -> bookings_cancel_commit -> cancelRoomBookingSettled).
    $x9 = cancelRoomBookingSettled($pdo, $X7, $adminId, 'GBLK bulk test', 'completed', ['quoted' => '1200.00', 'override' => '', 'reason' => '']);
    ok('cancel: a stale quoted fee is refused by the single path, nothing changed', empty($x9['success']) && strpos((string)$x9['error'], 'cancellation fee changed') !== false
        && $get($X7)['status'] === 'confirmed', json_encode($x9));
    // Whole group ticked: cancelled together (all or none), the group's summed fee checked like the single "Whole group".
    $G1 = $mk(['cancellation_policy_snapshot' => $snapP()] + $X0);
    $G2 = $mk(['primary_booking_id' => $G1, 'cancellation_policy_snapshot' => $snapP()] + $X0);
    $gp = rh_bk_bulk_plan($pdo, 'cancel', [$G2, $G1], $adminId);
    $gs = [$G1, $G2];
    sort($gs);
    ok('cancel: whole group ticked -> together, summed fee', ($room($gp, $G1)['group'] ?? []) === $gs && ($room($gp, $G2)['group'] ?? []) === $gs
        && ($room($gp, $G1)['group_quoted'] ?? '') === number_format(round((float)$room($gp, $G1)['quoted'] + (float)$room($gp, $G2)['quoted'], 2), 2, '.', '')
        && strpos($room($gp, $G1)['text'], 'cancelled together') !== false, json_encode($gp['rooms']));
    $ga = rh_bk_bulk_apply($pdo, 'cancel', [$G2, $G1], $admin, $gp['state'], null, [], ['cancellation_reason' => 'GBLK bulk test', 'email_guest' => '0']);
    $gr = array_column($ga['results'] ?? [], null, 'id');
    ok('cancel apply: whole group cancelled together', !empty($ga['success']) && $ga['done'] === 2 && $get($G1)['status'] === 'cancelled' && $get($G2)['status'] === 'cancelled'
        && strpos($gr[$G1]['text'] ?? '', 'with its whole group') !== false && strpos($gr[$G2]['text'] ?? '', 'with its whole group') !== false
        && $audit($G1, 'cancelled') === 1 && $audit($G2, 'cancelled') === 1, json_encode($ga));
    // Part of a group ticked: that room alone, as the single "Only this room".
    $H1 = $mk($X0);
    $H2 = $mk(['primary_booking_id' => $H1] + $X0);
    $H3 = $mk(['primary_booking_id' => $H1] + $X0);
    $hp = rh_bk_bulk_plan($pdo, 'cancel', [$H2], $adminId);
    $ha = rh_bk_bulk_apply($pdo, 'cancel', [$H2], $admin, $hp['state'], null, [], ['cancellation_reason' => 'GBLK bulk test', 'email_guest' => '0']);
    ok('cancel: part of a group ticked -> only that room', empty($room($hp, $H2)['group']) && !empty($ha['success']) && $ha['done'] === 1
        && $get($H2)['status'] === 'cancelled' && $get($H1)['status'] === 'pending' && $get($H3)['status'] === 'pending', json_encode($ha));
    // Whole group ticked but one room cannot be cancelled: the group is skipped, the review names the room to untick.
    $J1 = $mk($X0);
    $J2 = $mk(['primary_booking_id' => $J1, 'status' => 'checked-in'] + $X0);
    $jp = rh_bk_bulk_plan($pdo, 'cancel', [$J1, $J2], $adminId);
    ok('cancel: group with an in-house room -> all skipped, says which to untick', empty($room($jp, $J1)['will']) && ($room($jp, $J2)['why'] ?? '') === 'checked in'
        && ($room($jp, $J1)['why'] ?? '') === 'its group cannot all be cancelled' && strpos($room($jp, $J1)['text'], 'Untick') !== false && $jp['will'] === 0, json_encode($jp['rooms']));

    // ---- G5c Mark no-show: only what the row's No-show allows; fee per room; apply through rh_mark_booking_no_show ------
    $N0 = ['status' => 'confirmed', 'amount_paid' => 0, 'guest_email' => '', 'check_in_date' => $yesterday, 'check_out_date' => $day(2)] + $onT;
    $N1 = $mk($N0);
    $N2 = $mk(['check_in_date' => $today] + $N0);
    $N3 = $mk(['status' => 'pending'] + $N0);
    $N4 = $mk(['status' => 'no-show'] + $N0);
    $N5 = $mk(['cancellation_policy_snapshot' => $snapP(100)] + $N0);
    $np = rh_bk_bulk_plan($pdo, 'noshow', [$N1, $N2, $N3, $N4, $N5], $adminId);
    $nw = array_column($np['rooms'], 'why', 'id');
    ok('noshow: confirmed, arrival passed -> will, no email address said', !empty($room($np, $N1)['will']) && strpos($room($np, $N1)['text'], 'No email address') !== false, $room($np, $N1)['text'] ?? '');
    ok('noshow: arrival today / pending / already no-show skipped', $nw[$N2] === 'arrival date not passed' && $nw[$N3] === 'not confirmed' && $nw[$N4] === 'already no-show', json_encode($nw));
    ok('noshow: policy 100% -> the booked stay keeps billing', strpos((string)($room($np, $N5)['cols']['fee'] ?? ''), 'Booked stay') === 0, json_encode($room($np, $N5)));
    ok('noshow: no Cancel Booking permission -> skipped', ($room(rh_bk_bulk_plan($pdo, 'noshow', [$N1], 0), $N1)['why'] ?? '') === 'no permission');
    $np2 = rh_bk_bulk_plan($pdo, 'noshow', [$N1, $N2], $adminId);
    $na = rh_bk_bulk_apply($pdo, 'noshow', [$N1, $N2], $admin, $np2['state'], null, [], ['note' => 'GBLK bulk test']);
    $nr = array_column($na['results'] ?? [], null, 'id');
    ok('noshow apply: 1 marked, 1 skipped', !empty($na['success']) && $na['done'] === 1 && strpos($na['message'], '1 marked no-show, 1 skipped') === 0, $na['message'] ?? $na['error']);
    ok('noshow apply: status + History through the row\'s path', $get($N1)['status'] === 'no-show' && $audit($N1, 'no-show') === 1 && $hist($N1, 'Guest marked as no-show') === 1
        && $get($N2)['status'] === 'confirmed' && strpos($nr[$N1]['text'] ?? '', 'Marked no-show.') === 0, json_encode($nr));
    $na2 = rh_bk_bulk_apply($pdo, 'noshow', [$N1, $N2], $admin, $np2['state'], null, [], []);
    ok('noshow: a second confirm on the same review does nothing (state changed)', empty($na2['success']) && $audit($N1, 'no-show') === 1);

    // ---- CSV export: formula-looking text gets an apostrophe; numbers and phone numbers do not ---------------------
    $csv = ['=HYPERLINK("x")' => "'=HYPERLINK(\"x\")", '+cmd' => "'+cmd", '-cmd|x' => "'-cmd|x", '@SUM(A1)' => "'@SUM(A1)", "\tx" => "'\tx", "\rx" => "'\rx",
        '+265 99 123 4567' => '+265 99 123 4567', '-50.00' => '-50.00', '' => '', 'Alice' => 'Alice', 'a=b' => 'a=b'];
    $bad = [];
    foreach ($csv as $in => $want) {
        if (rh_bk_bulk_csv_cell((string)$in) !== $want) { $bad[] = json_encode((string)$in); }
    }
    ok('csv: formula cells neutralised, numbers / phones / plain text untouched', !$bad, implode(', ', $bad));
    ok('csv: non-strings untouched', rh_bk_bulk_csv_cell(12.5) === 12.5 && rh_bk_bulk_csv_cell(null) === null);
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// ---- leak check ----------------------------------------------------------------------------------------------
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE slug LIKE 'zz-gblk-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM individual_rooms WHERE room_name = 'zz gblk'")->fetchColumn()
    + ((int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn() - $auditGblkBefore);
ok('leak check: no test row left behind', $leak === 0, (string)$leak);
ok('the mirrored ICTAm group is untouched',
    $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0)) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn() === $ictamBefore);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
