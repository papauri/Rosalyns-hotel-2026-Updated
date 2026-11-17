<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group operations (group bookings plan R3 / R5 / R6, rule A6): includes/booking-group-ops.php.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/group_ops_test.php
 * Builds its OWN rows (references GOPT-*, emails @example.invalid) inside ONE transaction that is rolled back; the
 * mirrored ICTAm group is never touched. Then a leak check counts every marker row after the rollback.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || !is_dir($root . '/../Liwonde_Sun_Hotel_2026') || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/cancellation-policy.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/booking-group-ops.php';
require_once $root . '/admin/includes/group-payment.php'; // G4: whole-group payment cap on a joined no-show / cancelled room (read-only preview)

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$tol = (float)BALANCE_TOLERANCE;

// Warm up anything that might run DDL (an implicit commit inside the transaction would leak rows).
$src = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL AND (primary_booking_id IS NULL OR primary_booking_id = 0)
    AND id <> 2100008837 AND NOT EXISTS (SELECT 1 FROM bookings m WHERE m.primary_booking_id = bookings.id) ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($src <= 0) { fwrite(STDERR, "No source booking to clone.\n"); exit(1); }
getBookingFolioSummary($src);
rh_billing_ready($pdo);
rh_cancel_policy_column_ready($pdo);
$ictamBefore = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0)) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();

$cols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$n = 0;
$mk = static function (array $over = []) use ($pdo, $cols, $src, &$n): int {
    $n++;
    $over += ['booking_reference' => 'GOPT-' . getmypid() . '-' . $n, 'guest_email' => 'gopt' . $n . '@example.invalid', 'guest_name' => 'Gopt Test ' . $n,
        'primary_booking_id' => null, 'billing_account_id' => null, 'billing_reference' => null, 'billing_due_date' => null, 'billing_covers' => null,
        'final_invoice_number' => null, 'deleted_at' => null, 'status' => 'confirmed', 'client_uuid' => null, 'cancellation_policy_snapshot' => null,
        'individual_room_id' => null, 'room_combination_id' => null];
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
$money = static function (array $ids): array {
    $o = [];
    foreach ($ids as $id) { $o[$id] = rh_group_ops_money($id); }
    return $o;
};
$sumOwed = static fn(array $m): float => round(array_sum(array_column($m, 'owed')), 2);
$paySnap = static function (array $ids) use ($pdo): string {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT(id, ':', booking_id, ':', amount) ORDER BY id) FROM payments WHERE booking_type = 'room' AND booking_id IN ($ph)");
    $st->execute($ids);
    return (string)$st->fetchColumn();
};
$chase = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare("SELECT CONCAT(account_type, '|', stage) FROM automated_email_log WHERE job = 'overdue_payment_reminders' AND account_id = ? ORDER BY account_type, stage");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$hist = static function (int $id, string $action) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND action = ?');
    $st->execute([$id, $action]);
    return (int)$st->fetchColumn();
};
$addChase = static function (int $id, string $type, string $stage, string $status = 'sent') use ($pdo): void {
    $pdo->prepare("INSERT INTO automated_email_log (job, account_type, account_id, stage, recipient, subject, status) VALUES ('overdue_payment_reminders', ?, ?, ?, 'gopt@example.invalid', 'GOPT', ?)")
        ->execute([$type, $id, $stage, $status]);
};
$opts = ['user_id' => 0, 'user_name' => 'group_ops_test'];
$testIds = [];

$pdo->beginTransaction();
try {
    // Two made-up company accounts.
    $acc = [];
    foreach (['GOPT Company One', 'GOPT Company Two'] as $nm) {
        $pdo->prepare('INSERT INTO billing_accounts (name) VALUES (?)')->execute([$nm]);
        $acc[] = (int)$pdo->lastInsertId();
    }

    // ---- R3 / A6 re-point ----------------------------------------------------------------------------------------
    $A = $mk(['billing_account_id' => $acc[0], 'billing_reference' => 'PO-GOPT', 'billing_covers' => 'stay', 'final_invoice_number' => 'GOPT-INV-A']);
    $B = $mk(['final_invoice_number' => 'GOPT-INV-B']);
    $C = $mk(['status' => 'cancelled']);
    $D = $mk(['status' => 'no-show']);
    $E = $mk(['status' => 'checked-out']);
    $F = $mk(['deleted_at' => date('Y-m-d H:i:s')]);
    $testIds = array_merge($testIds, [$A, $B, $C, $D, $E, $F]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id IN (?,?,?,?,?)')->execute([$A, $B, $C, $D, $E, $F]);
    // Give B a lower id than A in the invoice lookup sense: B holds its own number, A (root) the group's.
    $addChase($A, 'room', 'r7');
    $addChase($A, 'room_g:' . $acc[0], 'r14');
    $addChase($A, 'room', 'r30', 'skipped');
    $grp = [$A, $B, $C, $D, $E, $F];
    $live = [$A, $B, $C, $D, $E];
    $m0 = $money($live);
    $p0 = $paySnap($grp);
    $inv0 = rh_invoice_group_number($pdo, $live, $A);
    ok('setup: group invoice number = the root\'s', $inv0 === 'GOPT-INV-A', $inv0);

    $pv = rh_group_repoint($pdo, $A, $B, $opts + ['dry_run' => true]);
    ok('R3 preview: ok, billing would be copied, nothing written', !empty($pv['success']) && $pv['billing'] === 'copied' && (int)$get($B)['primary_booking_id'] === $A, json_encode($pv));
    $bad = rh_group_repoint($pdo, $A, $B, $opts + ['expect_state' => 'stale']);
    ok('R3: a stale preview refuses', empty($bad['success']) && (int)$get($B)['primary_booking_id'] === $A);
    ok('R3: a deleted room cannot be primary', empty(rh_group_repoint($pdo, $A, $F, $opts)['success']));
    ok('R3: a room outside the group refuses', empty(rh_group_repoint($pdo, $A, $src, $opts)['success']));
    $rp = rh_group_repoint($pdo, $A, $B, $opts + ['expect_state' => $pv['state']]);
    ok('R3: re-point succeeds', !empty($rp['success']), $rp['error'] ?? '');
    $okPtr = array_key_exists('primary_booking_id', $get($B)) && (int)$get($B)['primary_booking_id'] === 0;
    foreach ([$A, $C, $D, $E, $F] as $id) { $okPtr = $okPtr && (int)$get($id)['primary_booking_id'] === $B; }
    ok('R3: every row (cancelled / no-show / checked-out / deleted too) now points at the new root', $okPtr,
        json_encode(array_map(static fn($id) => [$id, $get($id)['primary_booking_id'] ?? 'x'], [$A, $B, $C, $D, $E, $F])));
    $bB = $get($B);
    ok('R3: billing columns copied onto the new root', (int)$bB['billing_account_id'] === $acc[0] && $bB['billing_reference'] === 'PO-GOPT' && $bB['billing_covers'] === 'stay');
    ok('R3: group invoice number unchanged (owner answer 4)', rh_invoice_group_number($pdo, $live, $B) === 'GOPT-INV-A' && $bB['final_invoice_number'] === 'GOPT-INV-A' && $rp['invoice_copied']);
    ok('R3: chase sent rows copied to the new key, skipped not, old rows kept',
        $chase($B) === ['room|r7', 'room_g:' . $acc[0] . '|r14'] && count($chase($A)) === 3, json_encode([$chase($B), $chase($A)]));
    $m1 = $money($live);
    ok('R3: every room\'s billed / paid / owed unchanged; sum of room balances unchanged', $m1 == $m0 && abs($sumOwed($m1) - $sumOwed($m0)) <= $tol);
    ok('R3: no payment row moved', $paySnap($grp) === $p0);
    ok('R3: History on both rooms', $hist($B, 'Made the primary room of the group') === 1 && $hist($A, 'Primary room of the group moved') === 1);
    ok('R3: effective lead follows the new root', rh_booking_group_effective_lead($pdo, $C) === $B);

    // Billing refusals.
    $G = $mk(['billing_account_id' => $acc[0]]);
    $H = $mk(['billing_account_id' => $acc[1]]);
    $I = $mk();
    $J = $mk(['billing_account_id' => $acc[0]]);
    $testIds = array_merge($testIds, [$G, $H, $I, $J]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$G, $H]);
    $r = rh_group_repoint($pdo, $G, $H, $opts);
    ok('A6: a new root on a different company refuses', empty($r['success']) && (int)$get($H)['primary_booking_id'] === $G, $r['error']);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$I, $J]);
    $r = rh_group_repoint($pdo, $I, $J, $opts);
    ok('A6: guest-pays group to a company root refuses', empty($r['success']), $r['error']);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = NULL WHERE id IN (?, ?)')->execute([$H, $J]);

    // ---- R5 make a group -----------------------------------------------------------------------------------------
    $L = $mk();
    $S1 = $mk();
    $S2 = $mk(['billing_account_id' => $acc[1]]);
    $X = $mk(['status' => 'cancelled', 'cancellation_retained_amount' => 0]);
    recalculateBookingFinancials($X);
    $Y = $mk(['status' => 'expired']);
    $testIds = array_merge($testIds, [$L, $S1, $S2, $X, $Y]);
    $addChase($S1, 'room', 'r7');
    $mk0 = $money([$L, $S1, $S2]);
    $pk0 = $paySnap([$L, $S1, $S2]);
    $rX = rh_group_make($pdo, $L, [$L, $X], $opts + ['dry_run' => true]);
    ok('R5 / G4: a cancelled booking that owes nothing refuses', (float)$get($X)['amount_due'] <= $tol && empty($rX['success'])
        && stripos($rX['error'], 'cancelled and owes nothing') !== false, $get($X)['amount_due'] . ' ' . $rX['error']);
    $rY = rh_group_make($pdo, $L, [$L, $Y], $opts + ['dry_run' => true]);
    ok('R5 / G4: an expired booking refuses (even when it owes)', empty($rY['success']) && stripos($rY['error'], 'expired') !== false, $rY['error']);
    ok('R5: a deleted booking refuses', empty(rh_group_make($pdo, $L, [$L, $F], $opts + ['dry_run' => true])['success']));
    ok('R5: a booking in another group refuses', empty(rh_group_make($pdo, $L, [$L, $E], $opts + ['dry_run' => true])['success']));
    ok('R5: one booking alone refuses', empty(rh_group_make($pdo, $L, [$L], $opts + ['dry_run' => true])['success']));
    $r = rh_group_make($pdo, $G, [$G, $S1], $opts + ['dry_run' => true]);
    ok('R5: lead on a company + joining guest-pays refuses', empty($r['success']), $r['error']);
    $pv = rh_group_make($pdo, $L, [$L, $S1, $S2], $opts + ['dry_run' => true]);
    ok('R5 preview: rooms + totals, nothing written', !empty($pv['success']) && count($pv['rooms']) === 3 && (int)$get($S1)['primary_booking_id'] === 0
        && abs($pv['totals']['owed'] - $sumOwed($mk0)) <= $tol, json_encode($pv['error']));
    $r = rh_group_make($pdo, $L, [$L, $S1, $S2], $opts + ['expect_state' => $pv['state']]);
    ok('R5: make a group succeeds', !empty($r['success']), $r['error']);
    ok('R5: both singles point at the lead, the lead stays root', (int)$get($S1)['primary_booking_id'] === $L && (int)$get($S2)['primary_booking_id'] === $L && (int)$get($L)['primary_booking_id'] === 0);
    ok('R5: the joining room keeps its own company account', (int)$get($S2)['billing_account_id'] === $acc[1]);
    ok('R5: single\'s chase key copied to the group key', in_array('room|r7', $chase($L), true) && in_array('room|r7', $chase($S1), true));
    $mk1 = $money([$L, $S1, $S2]);
    ok('R5: each booking keeps its paid / owed; group sum = sum before', $mk1 == $mk0 && abs($sumOwed($mk1) - $sumOwed($mk0)) <= $tol);
    ok('R5: no payment row moved', $paySnap([$L, $S1, $S2]) === $pk0);
    ok('R5: History on both sides', $hist($S1, 'Added to a group') === 1 && $hist($L, 'Rooms added to the group') === 1);
    ok('R5: grouping the same rooms again refuses', empty(rh_group_make($pdo, $L, [$L, $S1], $opts + ['dry_run' => true])['success']));
    // Lead chosen inside an existing group: re-pointed first.
    $W = $mk();
    $testIds[] = $W;
    $pv = rh_group_make($pdo, $S1, [$S1, $W], $opts + ['dry_run' => true]);
    $r = rh_group_make($pdo, $S1, [$S1, $W], $opts + ['expect_state' => (string)($pv['state'] ?? '')]); // review state = confirm state
    ok('R5: a non-primary lead re-points its group, then the single joins', !empty($r['success']) && $r['repointed']
        && (int)$get($S1)['primary_booking_id'] === 0 && (int)$get($L)['primary_booking_id'] === $S1 && (int)$get($S2)['primary_booking_id'] === $S1 && (int)$get($W)['primary_booking_id'] === $S1, $r['error']);

    // ---- G4 (owner 2026-11-18): a no-show, and a cancelled room still owing its kept fee, may join ----------------
    $cj = static fn(array $r, bool $lead = false): array => rh_group_can_join($r + ['booking_reference' => 'GOPT-X'], $lead);
    ok('G4 rule: live booking joins and can lead', $cj(['status' => 'confirmed', 'amount_due' => 0]) === ['ok' => true, 'reason' => '', 'can_lead' => true]);
    ok('G4 rule: no-show joins, cannot lead', ($r = $cj(['status' => 'no-show', 'amount_due' => 0]))['ok'] && !$r['can_lead']);
    ok('G4 rule: cancelled owing joins, cannot lead', ($r = $cj(['status' => 'cancelled', 'amount_due' => 50]))['ok'] && !$r['can_lead']);
    ok('G4 rule: cancelled owing nothing / within tolerance refused', !$cj(['status' => 'cancelled', 'amount_due' => 0])['ok'] && !$cj(['status' => 'cancelled', 'amount_due' => $tol / 2])['ok']);
    ok('G4 rule: expired refused even when owing', !$cj(['status' => 'expired', 'amount_due' => 100])['ok']);
    ok('G4 rule: deleted refused', !$cj(['status' => 'no-show', 'amount_due' => 100, 'deleted_at' => '2026-11-01 10:00:00'])['ok']);
    ok('G4 rule: no-show / cancelled owing as the lead refused with a plain message',
        stripos($cj(['status' => 'no-show'], true)['reason'], 'cannot be the primary room') !== false && !$cj(['status' => 'cancelled', 'amount_due' => 50], true)['ok']);

    $gSnap = json_encode(['tiers' => [['days' => 7, 'pct' => 50]], 'kind' => 'individual', 'noshow_pct' => 100, 'policy_version' => 1, 'text' => 'GOPT G4 policy']);
    $L4 = $mk();
    $N4 = $mk(['status' => 'no-show', 'cancellation_policy_snapshot' => $gSnap]);
    $C4 = $mk(['cancellation_policy_snapshot' => $gSnap]);
    $testIds = array_merge($testIds, [$L4, $N4, $C4]);
    recalculateBookingFinancials($N4);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 500 WHERE id = ?")->execute([$C4]);
    recalculateBookingFinancials($C4);
    $keep = static function (int $id) use ($get): string {
        $b = $get($id);
        return json_encode([$b['status'], $b['cancellation_policy_snapshot'], $b['cancellation_retained_amount'] ?? null, $b['amount_due'], $b['amount_paid']]);
    };
    $k0 = [$N4 => $keep($N4), $C4 => $keep($C4)];
    $g4m0 = $money([$L4, $N4, $C4]);
    $g4p0 = $paySnap([$L4, $N4, $C4]);
    ok('G4 setup: the cancelled room owes its kept fee, billed = the kept fee only', (float)$get($C4)['amount_due'] > $tol && abs($g4m0[$C4]['billed'] - 500) <= $tol, json_encode($g4m0[$C4]));
    $r = rh_group_make($pdo, $N4, [$N4, $L4], $opts + ['dry_run' => true]);
    ok('G4: a no-show as the primary room refuses', empty($r['success']) && stripos($r['error'], 'cannot be the primary room') !== false, $r['error']);
    $r = rh_group_make($pdo, $C4, [$C4, $L4], $opts);
    ok('G4: a cancelled room as the primary room refuses (confirm path too), nothing written', empty($r['success']) && stripos($r['error'], 'cannot be the primary room') !== false
        && (int)$get($L4)['primary_booking_id'] === 0, $r['error']);
    $pv = rh_group_make($pdo, $L4, [$L4, $N4, $C4], $opts + ['dry_run' => true]);
    ok('G4 preview: ok; group totals = sum of each room (the cancelled one counts only its kept fee)', !empty($pv['success'])
        && abs($pv['totals']['billed'] - round($g4m0[$L4]['billed'] + $g4m0[$N4]['billed'] + $g4m0[$C4]['billed'], 2)) <= $tol
        && abs($pv['totals']['owed'] - $sumOwed($g4m0)) <= $tol, json_encode([$pv['error'], $pv['totals']]));
    $r = rh_group_make($pdo, $L4, [$L4, $N4, $C4], $opts + ['expect_state' => (string)($pv['state'] ?? '')]);
    ok('G4: the no-show and the cancelled room owing join', !empty($r['success']) && (int)$get($N4)['primary_booking_id'] === $L4 && (int)$get($C4)['primary_booking_id'] === $L4, $r['error']);
    ok('G4: status, cancellation snapshot, kept fee and balance unchanged on both', $keep($N4) === $k0[$N4] && $keep($C4) === $k0[$C4], json_encode([$keep($N4), $keep($C4)]));
    ok('G4: every room\'s billed / paid / owed unchanged, no payment row moved', $money([$L4, $N4, $C4]) == $g4m0 && $paySnap([$L4, $N4, $C4]) === $g4p0);
    $hd = static function (int $id) use ($pdo): string {
        $st = $pdo->prepare("SELECT description FROM booking_timeline_logs WHERE booking_id = ? AND action = 'Added to a group' ORDER BY id DESC LIMIT 1");
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    };
    ok('G4: History on each joining room says how it joined', stripos($hd($N4), 'Joined as a no-show') !== false && stripos($hd($C4), 'Joined as a cancelled booking') !== false
        && $hist($L4, 'Rooms added to the group') === 1, $hd($N4) . ' | ' . $hd($C4));
    // B1 cap (admin/includes/group-payment.php): a huge whole-group payment pays each closed room only what it owes; the extra goes to the live lead.
    $gp = rh_group_pay_preview($pdo, $L4, 999999999.0);
    $ap = array_column($gp['rooms'], 'apply', 'id');
    $ow = array_column($gp['rooms'], 'owed', 'id');
    ok('G4 group payment: the no-show and cancelled rooms count, each capped at what it owes; the extra lands on the live lead',
        !empty($gp['can_group']) && isset($ap[$N4], $ap[$C4]) && abs($ap[$N4] - $ow[$N4]) <= $tol && abs($ap[$C4] - $ow[$C4]) <= $tol
        && (int)$gp['excess_room_id'] === $L4, json_encode([$gp['reason'], $ap, $ow, $gp['excess_room_id']]));
    // R3 Make primary follows the same lead rule: a joined no-show / cancelled room is never made primary.
    $r = rh_group_repoint($pdo, $L4, $N4, $opts + ['dry_run' => true]);
    $r2 = rh_group_repoint($pdo, $L4, $C4, $opts);
    ok('G4 R3: Make primary on a joined no-show / cancelled room refuses with a plain message, nothing written',
        empty($r['success']) && stripos($r['error'], 'a no-show, so it cannot be the primary room') !== false && empty($r2['success'])
        && stripos($r2['error'], 'cancelled, so it cannot be the primary room') !== false && (int)$get($L4)['primary_booking_id'] === 0, $r['error'] . ' | ' . $r2['error']);
    // Removing the primary room when every other room is no-show / cancelled still hands the group on (no live room left).
    $Z = $mk();
    $Z1 = $mk(['status' => 'no-show']);
    $Z2 = $mk(['status' => 'cancelled']);
    $testIds = array_merge($testIds, [$Z, $Z1, $Z2]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id IN (?, ?)')->execute([$Z, $Z1, $Z2]);
    $pv = rh_group_remove($pdo, $Z, $opts + ['dry_run' => true]);
    $r = rh_group_remove($pdo, $Z, $opts + ['expect_state' => (string)($pv['state'] ?? '')]);
    ok('G4 R6: the primary room leaves a group of only no-show / cancelled rooms: handed to the first of them', !empty($r['success']) && $r['repointed']
        && (int)$r['new_root'] === $Z1 && (int)$get($Z1)['primary_booking_id'] === 0 && (int)$get($Z2)['primary_booking_id'] === $Z1 && (int)$get($Z)['primary_booking_id'] === 0,
        (string)($r['error'] ?? ''));

    // Distinct policy texts on group documents.
    $snap = static fn(string $t) => json_encode(['tiers' => [['days' => 7, 'pct' => 50]], 'kind' => 'individual', 'noshow_pct' => 100, 'policy_version' => 1, 'text' => $t]);
    $pdo->prepare('UPDATE bookings SET cancellation_policy_snapshot = ? WHERE id = ?')->execute([$snap('GOPT policy one'), $S1]);
    $pdo->prepare('UPDATE bookings SET cancellation_policy_snapshot = ? WHERE id = ?')->execute([$snap('GOPT policy two'), $W]);
    $pt = rh_booking_policy_text_by_id($pdo, $W);
    ok('A6: group documents print each distinct policy text', strpos($pt, 'GOPT policy one') !== false && strpos($pt, 'GOPT policy two') !== false
        && strpos($pt, 'GOPT policy one') < strpos($pt, 'GOPT policy two'), $pt);
    ok('A6: a room on its own still prints only its text', rh_booking_policy_text_by_id($pdo, $W, false) === 'GOPT policy two');

    // ---- R6 remove from the group --------------------------------------------------------------------------------
    // Company group P (root on acc0, holds the group number) with Q, R2 inheriting.
    $P = $mk(['billing_account_id' => $acc[0], 'billing_reference' => 'PO-P', 'billing_covers' => 'everything', 'final_invoice_number' => 'GOPT-INV-P']);
    $Q = $mk();
    $R2 = $mk();
    $testIds = array_merge($testIds, [$P, $Q, $R2]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id IN (?, ?)')->execute([$P, $Q, $R2]);
    $addChase($P, 'room', 'r7');
    $gm0 = $money([$P, $Q, $R2]);
    $gp0 = $paySnap([$P, $Q, $R2]);
    $pv = rh_group_remove($pdo, $Q, $opts + ['dry_run' => true]);
    ok('R6 preview: member, company copied, nothing written', !empty($pv['success']) && $pv['billing'] === 'copied' && (int)$get($Q)['primary_booking_id'] === $P, $pv['error']);
    $hook = 0;
    $r = rh_group_remove($pdo, $Q, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $db, array $res) use (&$hook) { $hook++; }]);
    ok('R6: remove a member succeeds, C2 hook ran once', !empty($r['success']) && $hook === 1, $r['error']);
    $bQ = $get($Q);
    ok('R6: the room is individual, keeps the payer (billing copied)', (int)$bQ['primary_booking_id'] === 0 && (int)$bQ['billing_account_id'] === $acc[0]
        && $bQ['billing_reference'] === 'PO-P' && $bQ['billing_covers'] === 'everything');
    ok('R6: chase group key copied to the room\'s own key', in_array('room|r7', $chase($Q), true) && in_array('room|r7', $chase($P), true));
    ok('R6: History on both sides', $hist($Q, 'Removed from the group') === 1 && $hist($P, 'Room removed from the group') === 1);
    $gm1 = $money([$P, $Q, $R2]);
    ok('R6: group balance before = group after + leaving room', $gm1 == $gm0 && abs($sumOwed($gm0) - ($sumOwed([$gm1[$P], $gm1[$R2]]) + $gm1[$Q]['owed'])) <= $tol);
    ok('R6: no payment row moved', $paySnap([$P, $Q, $R2]) === $gp0);
    // Remove the ROOT: re-pointed to R2 first, then detached; the group number stays with the group.
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$P, $Q]); // Q back in, to keep a group of 3
    $pv = rh_group_remove($pdo, $P, $opts + ['dry_run' => true]);
    $r = rh_group_remove($pdo, $P, $opts + ['expect_state' => (string)($pv['state'] ?? '')]); // review state = confirm state
    $bP = $get($P);
    ok('R6 root: re-pointed then detached', !empty($r['success']) && $r['repointed'] && (int)$bP['primary_booking_id'] === 0
        && (int)$get((int)$r['new_root'])['primary_booking_id'] === 0 && (int)$get($Q === (int)$r['new_root'] ? $R2 : $Q)['primary_booking_id'] === (int)$r['new_root'], $r['error']);
    $newRoot = (int)$r['new_root'];
    ok('R6 root: the review names the new primary room', strpos((string)$r['new_label'], (string)$get($newRoot)['booking_reference']) === 0);
    ok('R6 root: group number stays with the group, the leaving room no longer holds it',
        rh_invoice_group_number($pdo, [$Q, $R2], $newRoot) === 'GOPT-INV-P' && trim((string)$bP['final_invoice_number']) === '' && $r['invoice_cleared']);
    ok('R6 root: the leaving root keeps its own company account', (int)$bP['billing_account_id'] === $acc[0]);
    ok('R6 root: History', $hist($P, 'Removed from the group') === 1 && $hist($newRoot, 'Made the primary room of the group') === 1);
    ok('R6: a booking not in a group refuses', empty(rh_group_remove($pdo, $I, $opts)['success']));
    ok('R6: no payment row moved (root removal)', $paySnap([$P, $Q, $R2]) === $gp0);

    // Group of 2, the ROOT (holding the group number + a cached PDF) leaves: the number goes to the room that stays,
    // the leaving room loses the number AND the cached PDF (QA 2026-11-18), so no issued number is lost.
    $T = $mk(['final_invoice_number' => 'GOPT-INV-T']);
    $U = $mk();
    $testIds = array_merge($testIds, [$T, $U]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$T, $U]);
    $pdo->prepare("UPDATE bookings SET final_invoice_generated = 1, final_invoice_path = 'invoices/GOPT-T.pdf' WHERE id = ?")->execute([$T]);
    $pv = rh_group_remove($pdo, $T, $opts + ['dry_run' => true]);
    $r = rh_group_remove($pdo, $T, $opts + ['expect_state' => (string)($pv['state'] ?? '')]);
    $bT = $get($T);
    $bU = $get($U);
    ok('R6 group of 2: root leaves, the number stays with the room left', !empty($r['success']) && trim((string)$bU['final_invoice_number']) === 'GOPT-INV-T', ($r['error'] ?? '') . ' U=' . ($bU['final_invoice_number'] ?? 'null'));
    ok('R6 group of 2: the leaving room drops the number and the cached PDF',
        trim((string)$bT['final_invoice_number']) === '' && trim((string)($bT['final_invoice_path'] ?? '')) === '' && (int)($bT['final_invoice_generated'] ?? 0) === 0);
    // Group of 2, a MEMBER leaves: the root keeps its number untouched.
    $V = $mk(['final_invoice_number' => 'GOPT-INV-V']);
    $X = $mk();
    $testIds = array_merge($testIds, [$V, $X]);
    $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$V, $X]);
    $pv = rh_group_remove($pdo, $X, $opts + ['dry_run' => true]);
    $r = rh_group_remove($pdo, $X, $opts + ['expect_state' => (string)($pv['state'] ?? '')]);
    ok('R6 group of 2: member leaves, root keeps its number', !empty($r['success']) && trim((string)$get($V)['final_invoice_number']) === 'GOPT-INV-V' && trim((string)$get($X)['final_invoice_number']) === '', $r['error'] ?? '');
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// ---- permission gate: every group action needs Edit Booking ---------------------------------------------------
require_once $root . '/admin/includes/permissions.php';
$gates = array_map(static fn(string $a) => rhBookingActionPermission($a), ['group_make_preview', 'group_make_apply', 'group_op_preview', 'group_op_apply']);
ok('permission: Make a group / Make primary / Remove need Edit Booking', $gates === array_fill(0, 4, 'edit_booking'), implode(',', array_map('strval', $gates)));

// ---- leak check ----------------------------------------------------------------------------------------------
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GOPT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GOPT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM automated_email_log WHERE subject = 'GOPT'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM billing_accounts WHERE name LIKE 'GOPT %'")->fetchColumn();
ok('leak check: no test row left behind', $leak === 0, (string)$leak);
ok('the mirrored ICTAm group is untouched',
    $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0)) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn() === $ictamBefore);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
