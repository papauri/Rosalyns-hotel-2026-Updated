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

    // ---- Print arrival list rows ------------------------------------------------------------------------------------
    $arr = rh_bk_bulk_arrival_rows($pdo, [$P1, $P5, $P2]);
    ok('arrival list: deleted left out, sorted by check-in', array_column($arr, 'reference') === [(string)$get($P2)['booking_reference'], (string)$get($P1)['booking_reference']], json_encode(array_column($arr, 'reference')));
    ok('arrival list: balance is what is still owed', abs($arr[1]['balance'] - 500.0) < (float)BALANCE_TOLERANCE, (string)($arr[1]['balance'] ?? ''));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// ---- leak check ----------------------------------------------------------------------------------------------
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GBLK-%'")->fetchColumn();
ok('leak check: no test row left behind', $leak === 0, (string)$leak);
ok('the mirrored ICTAm group is untouched',
    $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0)) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn() === $ictamBefore);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
