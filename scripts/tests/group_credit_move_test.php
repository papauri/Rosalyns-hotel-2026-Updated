<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Group money moves (group bookings plan A5 + D-R6): includes/group-credit-move.php.
 * Usage (repo root, TEST HOTEL ONLY): php scripts/tests/group_credit_move_test.php
 * Builds its OWN rows (references GCMT-*, emails @example.invalid) inside ONE transaction that is rolled back (refusal
 * cases inside savepoints); the mirrored ICTAm group is never touched. Then a leak check counts every marker row.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false || basename($root) !== 'Liwonde_Sun_Hotel_2026') {
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/billing-accounts.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/group-credit-move.php';
require_once $root . '/includes/group-room-table.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$tol = (float)BALANCE_TOLERANCE;
$near = static fn(float $a, float $b): bool => abs($a - $b) <= (float)BALANCE_TOLERANCE;

// Warm up anything that might run DDL (an implicit commit inside the transaction would leak rows).
$src = (int)$pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL AND status IN ('confirmed','checked-in','checked-out') AND (primary_booking_id IS NULL OR primary_booking_id = 0)
    AND id <> 2100008837 AND total_with_vat > 1000 AND NOT EXISTS (SELECT 1 FROM bookings m WHERE m.primary_booking_id = bookings.id) ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($src <= 0) { fwrite(STDERR, "No source booking to clone.\n"); exit(1); }
getBookingFolioSummary($src);
rh_billing_ready($pdo);
finance_ensure_sequence_tables($pdo);
$ictamBefore = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0), ':', amount_paid) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();
$cnMax0 = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM credit_notes')->fetchColumn();
$payMax0 = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM payments')->fetchColumn();
$payCols = array_column($pdo->query('SHOW COLUMNS FROM payments')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$hasReceipt = in_array('receipt_number', $payCols, true);

$cols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$n = 0;
$mk = static function (array $over = []) use ($pdo, $cols, $src, &$n): int {
    $n++;
    $over += ['booking_reference' => 'GCMT-' . getmypid() . '-' . $n, 'guest_email' => 'gcmt' . $n . '@example.invalid', 'guest_name' => 'Gcmt Test ' . $n,
        'room_guest_name' => null, 'primary_booking_id' => null, 'billing_account_id' => null, 'billing_reference' => null, 'billing_due_date' => null,
        'billing_covers' => null, 'final_invoice_number' => null, 'deleted_at' => null, 'status' => 'confirmed', 'client_uuid' => null,
        'individual_room_id' => null, 'room_combination_id' => null, 'amount_paid' => 0, 'amount_due' => 0, 'credit_balance' => 0];
    $sel = []; $args = [];
    foreach ($cols as $c) {
        if ($c === 'id') { continue; }
        if (array_key_exists($c, $over)) { $sel[] = '?'; $args[] = $over[$c]; } else { $sel[] = '`' . $c . '`'; }
    }
    $ins = array_values(array_filter($cols, static fn($c) => $c !== 'id'));
    $pdo->prepare('INSERT INTO bookings (`' . implode('`,`', $ins) . '`) SELECT ' . implode(',', $sel) . ' FROM bookings WHERE id = ?')->execute(array_merge($args, [$src]));
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
};
$pay = static function (int $id, float $amt) use ($pdo): void {
    if ($amt <= 0) { return; }
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'GCMT', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['GCMT' . random_int(10000000, 99999999), $id, $amt, $amt]);
    recalculateBookingFinancials($id);
};
$group = static function (int $rootId, array $members) use ($pdo): void {
    foreach ($members as $m) { $pdo->prepare('UPDATE bookings SET primary_booking_id = ? WHERE id = ?')->execute([$rootId, $m]); }
};
$fig = static fn(int $id): array => rh_gcm_figures($id);
$billed = static fn(int $id): float => rh_gcm_figures($id)['billed'];
// Payment rows as they were: id, booking, amounts, receipt number (payment_status may change: refund legs refresh it).
$paySnap = static function (array $ids) use ($pdo, $hasReceipt): array {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, CONCAT(booking_id, ':', payment_amount, ':', vat_amount, ':', total_amount, ':', COALESCE(payment_method, ''), ':', COALESCE(payment_type, '')"
        . ($hasReceipt ? ", ':', COALESCE(receipt_number, '')" : '') . ") AS k FROM payments WHERE booking_type = 'room' AND booking_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
};
$newRows = static function (array $ids, array $before) use ($pdo): array {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, booking_id, payment_method, payment_type, total_amount, credit_note_id FROM payments WHERE booking_type = 'room' AND booking_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), static fn($r) => !isset($before[$r['id']])));
};
$hist = static function (int $id, string $action) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND action = ?');
    $st->execute([$id, $action]);
    return (int)$st->fetchColumn();
};
$cnCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM credit_notes WHERE id > ' . $cnMax0)->fetchColumn();
$sumPaid = static function (array $ids) use ($fig): float { $s = 0.0; foreach ($ids as $i) { $s += $fig($i)['paid']; } return round($s, 2); };
$opts = ['user_id' => 0, 'user_name' => 'group_credit_move_test'];

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO billing_accounts (name) VALUES (?)')->execute(['GCMT Company']);
    $acc = (int)$pdo->lastInsertId();

    // ===== A5: credit fully used, split across 2 owing rooms (+ a cancelled and a no-show room that owe: left out) =====
    $A = $mk(); $B = $mk(); $C = $mk(); $D = $mk(); $E = $mk(['status' => 'no-show']);
    $group($A, [$B, $C, $D, $E]);
    $pay($A, $billed($A) + 150); $pay($B, $billed($B) - 100); $pay($C, $billed($C) - 80); $pay($D, $billed($D));
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 500 WHERE id = ?")->execute([$D]); recalculateBookingFinancials($D);
    $g1 = [$A, $B, $C, $D, $E];
    $t0 = rh_group_room_table($pdo, $A);
    $p0 = $paySnap($g1);
    $pv = rh_group_credit_move($pdo, $A, $opts + ['dry_run' => true]);
    ok('A5 preview ok', !empty($pv['success']), $pv['error']);
    ok('A5 preview: moves the whole credit 150 (others owe 180)', $near($pv['moved'], 150) && $near($pv['left_over'], 0), json_encode([$pv['moved'], $pv['left_over']]));
    ok('A5 preview: only the 2 open owing rooms receive (cancelled / no-show left out)', array_column($pv['rooms'], 'id') === [$B, $C], json_encode(array_column($pv['rooms'], 'id')));
    ok('A5 preview: shares add up exactly and are pro rata', $near(array_sum(array_column($pv['rooms'], 'apply')), 150) && $near($pv['rooms'][0]['apply'], 83.34) && $near($pv['rooms'][1]['apply'], 66.66), json_encode(array_column($pv['rooms'], 'apply')));
    ok('A5 preview: group net position unchanged', $near($pv['net_before'], $pv['net_after']), $pv['net_before'] . ' / ' . $pv['net_after']);
    ok('A5 preview: nothing written', $paySnap($g1) === $p0 && $cnCount() === 0);

    // Stale review: a payment lands on B after the preview -> refused, nothing written.
    $pay($B, 10);
    $p0b = $paySnap($g1);
    $st = rh_group_credit_move($pdo, $A, $opts + ['expect_state' => $pv['state']]);
    ok('A5 stale review refused', empty($st['success']) && stripos($st['error'], 'changed since you reviewed') !== false, $st['error']);
    ok('A5 stale: nothing written', $paySnap($g1) === $p0b && $cnCount() === 0);
    $noState = rh_group_credit_move($pdo, $A, $opts);
    ok('A5 confirm without a review refused', empty($noState['success']) && $cnCount() === 0, $noState['error']);

    $pv = rh_group_credit_move($pdo, $A, $opts + ['dry_run' => true]);
    $tB = rh_group_room_table($pdo, $A);
    $paidB = $sumPaid($g1);
    $res = rh_group_credit_move($pdo, $A, $opts + ['expect_state' => $pv['state']]);
    ok('A5 confirm ok', !empty($res['success']), $res['error']);
    ok('A5: ONE credit note, fully used', $cnCount() === 1 && (float)$pdo->query('SELECT balance FROM credit_notes WHERE id > ' . $cnMax0)->fetchColumn() <= $tol
        && (string)$pdo->query('SELECT status FROM credit_notes WHERE id > ' . $cnMax0)->fetchColumn() === 'fully_applied');
    ok('A5: credit on A now 0', $near($fig($A)['credit'], 0), json_encode($fig($A)));
    ok('A5: B and C owed as reviewed', $near($fig($B)['owed'], $pv['rooms'][0]['owed_after']) && $near($fig($C)['owed'], $pv['rooms'][1]['owed_after']));
    $tA = rh_group_room_table($pdo, $A);
    ok('A5: group net position unchanged', $near($tA['totals']['net'], $tB['totals']['net']), $tB['totals']['net'] . ' -> ' . $tA['totals']['net']);
    ok('A5: group paid and billed unchanged', $near($sumPaid($g1), $paidB) && $near($tA['totals']['billed'], $tB['totals']['billed']));
    $rowSum = 0.0; foreach ($tA['rows'] as $r) { $rowSum += $r['owed']; }
    ok('A5: Σ room owed = group owed', $near(round($rowSum, 2), $tA['totals']['owed']));
    $after = $paySnap($g1);
    $kept = array_intersect_key($after, $p0b);
    ok('A5: existing payment rows unchanged (amounts, receipts)', $kept === $p0b);
    $added = $newRows($g1, $p0b);
    ok('A5: only credit-note rows added (legs on A, payments on B / C)', count($added) >= 3 && count(array_filter($added, static fn($r) => $r['payment_method'] !== 'credit_note')) === 0
        && count(array_filter($added, static fn($r) => (int)$r['booking_id'] === $A && $r['payment_type'] === 'refund')) >= 1
        && count(array_filter($added, static fn($r) => in_array((int)$r['booking_id'], [$B, $C], true) && $r['payment_type'] !== 'refund')) === 2, json_encode($added));
    ok('A5: History on both sides', $hist($A, 'Credit moved to rooms that owe') === 1 && $hist($B, 'Credit received from another room') === 1 && $hist($C, 'Credit received from another room') === 1);
    ok('A5: cancelled / no-show rooms untouched', $fig($D)['paid'] === rh_gcm_figures($D)['paid'] && count(array_filter($added, static fn($r) => in_array((int)$r['booking_id'], [$D, $E], true))) === 0);
    $again = rh_group_credit_move($pdo, $A, $opts + ['dry_run' => true]);
    ok('A5: no credit left -> nothing to move', empty($again['success']) && stripos($again['error'], 'no credit') !== false, $again['error']);

    // ===== A5: credit partly used (more credit than owed): the rest stays as credit on the source =====
    $F = $mk(); $G = $mk();
    $group($F, [$G]);
    $pay($F, $billed($F) + 300); $pay($G, $billed($G) - 50);
    $tF0 = rh_group_room_table($pdo, $F);
    $pv = rh_group_credit_move($pdo, $F, $opts + ['dry_run' => true]);
    $cn0 = $cnCount();
    $res = rh_group_credit_move($pdo, $F, $opts + ['expect_state' => $pv['state'] ?? '']);
    ok('A5 partly used: moves 50, 250 stays as credit on the source', !empty($res['success']) && $near($res['moved'], 50) && $near($fig($F)['credit'], 250) && $near($fig($G)['owed'], 0), json_encode([$res['error'], $fig($F), $fig($G)]));
    ok('A5 partly used: credit note fully used, net unchanged', $cnCount() === $cn0 + 1 && $near(rh_group_room_table($pdo, $F)['totals']['net'], $tF0['totals']['net']));

    // ===== A5: different payers refused (company-billed room) =====
    $H = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $I = $mk();
    $group($H, [$I]);
    $pay($H, $billed($H) + 100); $pay($I, $billed($I) - 100);
    $pv = rh_group_credit_move($pdo, $H, $opts + ['dry_run' => true]);
    ok('A5 payer refusal: company-billed group refused', empty($pv['success']) && stripos($pv['error'], 'GCMT Company') !== false, $pv['error']);
    $J = $mk(); $K = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']);
    $group($J, [$K]);
    $pay($J, $billed($J) + 100); $pay($K, $billed($K) - 100);
    $pv = rh_group_credit_move($pdo, $J, $opts + ['dry_run' => true]);
    ok('A5 payer refusal: guest credit to a company room refused', empty($pv['success']) && stripos($pv['error'], 'company') !== false, $pv['error']);

    // ===== D-R6: lump-sum move inside rh_group_remove (after_detach) =====
    $L = $mk(); $M = $mk(); $N = $mk(); $O = $mk();
    $group($L, [$M, $N, $O]);
    $pay($L, $billed($L) + 200); $pay($M, $billed($M)); // N leaves owing everything; O owes everything too
    $g4 = [$L, $M, $N, $O];
    $q0 = rh_group_lump_quote($pdo, $N, $L, 0);
    ok('D-R6 quote 0: ok, max = what N owes (others paid more)', $q0['ok'] && $near($q0['max'], $fig($N)['owed']), json_encode([$q0['error'], $q0['max']]));
    $over = rh_group_lump_quote($pdo, $N, $L, $q0['max'] + 1);
    ok('D-R6 over the max refused', !$over['ok'] && stripos($over['error'], 'At most') !== false, $over['error']);
    $Lpaid = $fig($L)['paid']; $Mpaid = $fig($M)['paid'];
    $maxBySources = round($Lpaid + $Mpaid, 2);
    ok('D-R6 max never above what the sources paid', $q0['max'] <= $maxBySources + $tol);

    // Stale: quote, then a payment lands on M -> the removal refuses and is rolled back whole.
    $amt = 250.0;
    $q = rh_group_lump_quote($pdo, $N, $L, $amt);
    ok('D-R6 quote 250: taken from L\'s credit first (200), then pro rata to what is left', $q['ok'] && $near(array_sum(array_column($q['sources'], 'take')), 250)
        && $near((float)array_column($q['sources'], 'take', 'id')[$L], 200 + round(50 * ($Lpaid - 200) / ($Lpaid - 200 + $Mpaid), 2)), json_encode([$q['error'], $q['sources']]));
    $pdo->exec('SAVEPOINT gcmt_stale');
    $pay($M, 5);
    $pv = rh_group_remove($pdo, $N, $opts + ['dry_run' => true]);
    $msg = '';
    try {
        rh_group_remove($pdo, $N, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($N, $amt, $q, $opts): void {
            rh_group_lump_apply($pdo, $N, (int)$r['new_root'], $amt, $q['state'], $opts);
        }]);
    } catch (RhGroupMoneyRefused $e) {
        $msg = $e->getMessage();
    }
    ok('D-R6 stale review refused', stripos($msg, 'changed since you reviewed') !== false, $msg);
    $pdo->exec('ROLLBACK TO SAVEPOINT gcmt_stale');
    ok('D-R6 stale: room still in the group after the rollback', (int)$pdo->query('SELECT primary_booking_id FROM bookings WHERE id = ' . $N)->fetchColumn() === $L);

    // Payer refusal inside the remove.
    $pdo->exec('SAVEPOINT gcmt_payer');
    $pdo->prepare('UPDATE bookings SET billing_account_id = ?, billing_covers = ? WHERE id = ?')->execute([$acc, 'stay', $L]);
    $qp = rh_group_lump_quote($pdo, $N, $L, 100);
    ok('D-R6 different payers refused', !$qp['ok'] && stripos($qp['error'], 'company') !== false, $qp['error']);
    $pdo->exec('ROLLBACK TO SAVEPOINT gcmt_payer');

    // Partial move: 250 to N.
    $p4 = $paySnap($g4);
    $paid4 = $sumPaid($g4);
    $q = rh_group_lump_quote($pdo, $N, $L, $amt);
    $pv = rh_group_remove($pdo, $N, $opts + ['dry_run' => true]);
    $cn0 = $cnCount();
    $res = rh_group_remove($pdo, $N, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($N, $amt, $q, $opts): void {
        rh_group_lump_apply($pdo, $N, (int)$r['new_root'], $amt, $q['state'], $opts);
    }]);
    ok('D-R6 remove + move 250 ok', !empty($res['success']), $res['error'] ?? '');
    ok('D-R6: N is individual, paid +250', (int)$pdo->query('SELECT COALESCE(primary_booking_id, 0) FROM bookings WHERE id = ' . $N)->fetchColumn() === 0
        && $near($fig($N)['paid'], 250) && $near($fig($N)['owed'], $q['leaving']['owed_after']));
    ok('D-R6: one credit note per giving room (2), all used', $cnCount() === $cn0 + 2
        && (float)$pdo->query('SELECT COALESCE(SUM(balance), 0) FROM credit_notes WHERE id > ' . $cnMax0)->fetchColumn() <= $tol);
    ok('D-R6: group + room totals add up exactly (Σ paid of all 4 unchanged)', $near($sumPaid($g4), $paid4));
    ok('D-R6: existing payment rows unchanged', array_intersect_key($paySnap($g4), $p4) === $p4);
    ok('D-R6: History on both sides', $hist($L, 'Group payment moved to a room that left') === 1 && $hist($M, 'Group payment moved to a room that left') === 1
        && $hist($N, 'Group payment received on leaving the group') === 2);

    // Move 0: no money moves.
    $cn0 = $cnCount();
    $p5 = $paySnap($g4);
    $q = rh_group_lump_quote($pdo, $O, $L, 0);
    $pv = rh_group_remove($pdo, $O, $opts + ['dry_run' => true]);
    $res = rh_group_remove($pdo, $O, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($O, $q, $opts): void {
        rh_group_lump_apply($pdo, $O, (int)$r['new_root'], 0, $q['state'], $opts);
    }]);
    ok('D-R6 move 0: removed, no credit note, no payment rows', !empty($res['success']) && $cnCount() === $cn0 && $paySnap($g4) === $p5);

    // Removing the PRIMARY room with a move: the sources are the same rooms before (old root) and after (new root).
    $P = $mk(); $Q = $mk(); $R = $mk();
    $group($P, [$Q, $R]);
    $pay($P, $billed($P) - 120); $pay($Q, $billed($Q) + 100); $pay($R, $billed($R));
    $g5 = [$P, $Q, $R];
    $paid5 = $sumPaid($g5);
    $q = rh_group_lump_quote($pdo, $P, rh_group_ops_root($pdo, $P), 120);
    $pv = rh_group_remove($pdo, $P, $opts + ['dry_run' => true]);
    $res = rh_group_remove($pdo, $P, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($P, $q, $opts): void {
        rh_group_lump_apply($pdo, $P, (int)$r['new_root'], 120, $q['state'], $opts);
    }]);
    ok('D-R6 remove the primary room + move 120 ok', !empty($res['success']) && $q['ok'], ($res['error'] ?? '') . ' ' . $q['error']);
    ok('D-R6 primary: P owes 0, Q is the new root, totals unchanged', $near($fig($P)['owed'], 0) && (int)$res['new_root'] === $Q && $near($sumPaid($g5), $paid5), json_encode([$fig($P), $res['new_root']]));

    // A cancelled room that kept money gives only its credit (what it kept stays earned, it never ends up owing).
    $U = $mk(); $V = $mk();
    $group($U, [$V]);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 500 WHERE id = ?")->execute([$V]); recalculateBookingFinancials($V);
    $pay($V, $billed($V) + 100);
    $vCredit = $fig($V)['credit'];
    $qv = rh_group_lump_quote($pdo, $U, $U, 0);
    ok('D-R6 cancelled source: max = its credit only', $qv['ok'] && $near($vCredit, 100) && $near($qv['max'], min($fig($U)['owed'], $vCredit)), json_encode([$qv['max'], $fig($V)]));
    $qo = rh_group_lump_quote($pdo, $U, $U, $vCredit + 1);
    ok('D-R6 cancelled source: more than its credit refused', !$qo['ok'], $qo['error']);
    $qv = rh_group_lump_quote($pdo, $U, $U, $vCredit);
    $pv = rh_group_remove($pdo, $U, $opts + ['dry_run' => true]);
    $res = rh_group_remove($pdo, $U, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($U, $vCredit, $qv, $opts): void {
        rh_group_lump_apply($pdo, $U, (int)$r['new_root'], $vCredit, $qv['state'], $opts);
    }]);
    ok('D-R6 cancelled source: moved, it still owes 0', !empty($res['success']) && $near($fig($V)['owed'], 0) && $near($fig($V)['credit'], 0), json_encode([$res['error'] ?? '', $fig($V)]));

    // A closed leaving room takes nothing.
    $S = $mk(); $T = $mk(['status' => 'no-show']);
    $group($S, [$T]);
    $pay($S, $billed($S) + 100);
    $qc = rh_group_lump_quote($pdo, $T, $S, 0);
    ok('D-R6 no-show leaving room: max 0 with a note', $qc['ok'] && $near($qc['max'], 0) && $qc['note'] !== '', json_encode($qc));
} catch (Throwable $e) {
    ok('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$GLOBALS['rh_payment_audit_queue'] = []; // the rolled-back rows must not reach History

// Leak check: nothing left behind, ICTAm untouched.
$leak = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_reference LIKE 'GCMT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'GCMT%' OR booking_reference LIKE 'GCMT%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM credit_notes WHERE booking_reference LIKE 'GCMT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM billing_accounts WHERE name = 'GCMT Company'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GCMT-%'")->fetchColumn();
ok('leak check: no test rows left', $leak === 0, (string)$leak);
$ictamAfter = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0), ':', amount_paid) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();
ok('ICTAm group untouched', $ictamAfter === $ictamBefore);

echo "\ngroup_credit_move: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
