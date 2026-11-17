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
// A company payment: a payment row + a remittance of $accId with its line (as rh_billing_record_remittance writes them).
$coPay = static function (int $id, float $amt, int $accId) use ($pdo): int {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'GCMT', CURDATE(), ?, 0, 0, ?, 'bank_transfer', 'partial_payment', 'completed', NOW())")
        ->execute(['GCMT' . random_int(10000000, 99999999), $id, $amt, $amt]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO billing_remittances (billing_account_id, remittance_reference, received_date, payment_method, amount) VALUES (?, ?, CURDATE(), 'bank_transfer', ?)")
        ->execute([$accId, 'GCMT-REM-' . $pid, $amt]);
    $pdo->prepare('INSERT INTO billing_remittance_lines (remittance_id, booking_id, payment_id, amount) VALUES (?, ?, ?, ?)')->execute([(int)$pdo->lastInsertId(), $id, $pid, $amt]);
    recalculateBookingFinancials($id);
    return $pid;
};
$charge = static function (int $id, float $gross) use ($pdo): void {
    $pdo->prepare("INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, voided)
        VALUES (?, 'other', 'Bar (GCMT test)', 1, ?, ?, 0, 0, ?, NOW(), 0)")->execute([$id, $gross, $gross, $gross]);
    recalculateBookingFinancials($id);
};
$histLike = static function (int $id, string $text) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = ? AND description LIKE ?');
    $st->execute([$id, '%' . $text . '%']);
    return (int)$st->fetchColumn();
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
    $pdo->prepare('INSERT INTO billing_accounts (name) VALUES (?)')->execute(['GCMT Company 2']);
    $acc2 = (int)$pdo->lastInsertId();

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

    // ===== G4 (owner 2026-11-18): a no-show and a cancelled room still owing, joined through Make a group, never receive credit =====
    $GA = $mk(); $GB = $mk(); $GN = $mk(['status' => 'no-show']); $GC = $mk();
    $pay($GA, $billed($GA) + 120);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 500 WHERE id = ?")->execute([$GC]); recalculateBookingFinancials($GC);
    $gmPv = rh_group_make($pdo, $GA, [$GA, $GB, $GN, $GC], $opts + ['dry_run' => true]);
    $gmRes = rh_group_make($pdo, $GA, [$GA, $GB, $GN, $GC], $opts + ['expect_state' => (string)($gmPv['state'] ?? '')]);
    ok('G4: no-show + cancelled-owing rooms joined through Make a group', !empty($gmRes['success'])
        && (int)$pdo->query('SELECT COUNT(*) FROM bookings WHERE primary_booking_id = ' . $GA)->fetchColumn() === 3, (string)($gmRes['error'] ?? $gmPv['error'] ?? ''));
    ok('G4: both closed rooms still owe (so leaving them out is the rule, not an accident)', $fig($GN)['owed'] > $tol && $fig($GC)['owed'] > $tol, json_encode([$fig($GN), $fig($GC)]));
    $gp0 = $paySnap([$GA, $GB, $GN, $GC]);
    $pv = rh_group_credit_move($pdo, $GA, $opts + ['dry_run' => true]);
    ok('G4: credit move preview: only the open owing room receives (joined no-show / cancelled left out)', !empty($pv['success']) && array_column($pv['rooms'], 'id') === [$GB],
        json_encode([$pv['error'], array_column($pv['rooms'], 'id')]));
    ok('G4: preview wrote nothing', $paySnap([$GA, $GB, $GN, $GC]) === $gp0 && $cnCount() === 1);

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

    // ===== A5 same payer (Ship C2b): every room billed to the same company -> only the company's money moves =====
    $H = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $I = $mk();
    $group($H, [$I]);
    $coPay($H, $billed($H) + 100, $acc); $coPay($I, $billed($I) - 100, $acc);
    $pv = rh_group_credit_move($pdo, $H, $opts + ['dry_run' => true]);
    ok('A5 same company: preview ok, payer is the company', !empty($pv['success']) && $pv['payer']['mode'] === 'company' && $pv['payer']['name'] === 'GCMT Company', $pv['error']);
    ok('A5 same company: moves 100, I gets what the company owes on it', $near($pv['moved'], 100) && $near($pv['rooms'][0]['company_due'] ?? -1, 100) && $near($pv['guest_credit'], 0), json_encode($pv['rooms']));
    $cpm0 = rh_billing_company_paid_map($pdo, [$H, $I]);
    $rn0 = rh_billing_received_net($pdo, date('Y-m-d'), $acc);
    $rnAll0 = rh_billing_received_net($pdo, date('Y-m-d'));
    $tH0 = rh_group_room_table($pdo, $H);
    $p6 = $paySnap([$H, $I]);
    $res = rh_group_credit_move($pdo, $H, $opts + ['expect_state' => $pv['state']]);
    ok('A5 same company: confirm ok', !empty($res['success']), $res['error']);
    $cpm1 = rh_billing_company_paid_map($pdo, [$H, $I]);
    ok('A5 same company: giver company paid -100, receiver +100', $near(($cpm1[$H] ?? 0) - ($cpm0[$H] ?? 0), -100) && $near(($cpm1[$I] ?? 0) - ($cpm0[$I] ?? 0), 100), json_encode([$cpm0, $cpm1]));
    ok('A5 same company: company money received unchanged (this company and all)', $near(rh_billing_received_net($pdo, date('Y-m-d'), $acc), $rn0) && $near(rh_billing_received_net($pdo, date('Y-m-d')), $rnAll0),
        $rn0 . ' -> ' . rh_billing_received_net($pdo, date('Y-m-d'), $acc));
    ok('A5 same company: group net / billed unchanged, I owes 0', $near(rh_group_room_table($pdo, $H)['totals']['net'], $tH0['totals']['net']) && $near($fig($I)['owed'], 0) && $near(rh_booking_bill_split($pdo, $I)['company_due'], 0));
    $cnRow = $pdo->query("SELECT guest_name, reason_notes FROM credit_notes WHERE credit_note_number = " . $pdo->quote($res['credit_note']))->fetch(PDO::FETCH_ASSOC);
    ok('A5 same company: credit note made out to the company', ($cnRow['guest_name'] ?? '') === 'GCMT Company' && stripos((string)$cnRow['reason_notes'], 'Company money of GCMT Company') !== false, json_encode($cnRow));
    $added6 = $newRows([$H, $I], $p6);
    $legH = array_values(array_filter($added6, static fn($r) => (int)$r['booking_id'] === $H && $r['payment_type'] === 'refund'));
    $cnPayI = array_values(array_filter($added6, static fn($r) => (int)$r['booking_id'] === $I && $r['payment_type'] !== 'refund'));
    ok('A5 same company: legs only on the company payment of H', count($legH) >= 1 && count($cnPayI) === 1);
    $cnPid = (int)($cnPayI[0]['id'] ?? 0);
    ok('A5 same company: the credit-note payment on I is a company leg (edit refused, refunds go to the company)', rh_billing_payment_is_company_leg($pdo, $cnPid)
        && (rh_billing_refund_recipient($pdo, ['booking_type' => 'room', 'id' => $cnPid, 'payment_type' => ''])['name'] ?? '') === 'GCMT Company');
    // QA C2b: the receiving room's company invoice lists the moved money it counts as paid (invoice foots).
    ok('A5 same company: I company invoice lists the moved credit-note payment', in_array($cnPid, rh_billing_split_for_ids($pdo, [$I], $I)['leg_ids'] ?? [], true),
        json_encode(rh_billing_split_for_ids($pdo, [$I], $I)['leg_ids'] ?? null));
    $plan = rh_billing_refund_plan($pdo, $I, [['id' => $cnPid, 'total_amount' => 100, 'already_refunded' => 0]], 1);
    ok('A5 same company: refund plan on I treats it as company money', is_array($plan) && ($plan[0]['payer'] ?? '') === 'company', json_encode($plan));
    ok('A5 same company: History says company money of the company', $histLike($H, 'company money of GCMT Company') === 1 && $histLike($I, 'company money of GCMT Company') === 1);
    // Refunded out to the company: I's company paid back to before, the company really got 100 back (counted once).
    $pdo->exec('SAVEPOINT gcmt_refund_out');
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, created_at)
        VALUES (?, 'room', ?, 'GCMT', CURDATE(), 100, 0, 0, 100, 'bank_transfer', 'refund', 'completed', ?, 'other', 'completed', 100, NOW())")
        ->execute(['GCMT' . random_int(10000000, 99999999), $I, $cnPid]);
    recalculateBookingFinancials($I);
    ok('A5 same company refunded out: I company paid back to before, received net -100 (this company and all)', $near(rh_billing_company_paid_map($pdo, [$I])[$I] ?? 0, $cpm0[$I] ?? 0)
        && $near(rh_billing_received_net($pdo, date('Y-m-d'), $acc), $rn0 - 100) && $near(rh_billing_received_net($pdo, date('Y-m-d')), $rnAll0 - 100),
        json_encode([rh_billing_company_paid_map($pdo, [$I]), $rn0, rh_billing_received_net($pdo, date('Y-m-d'), $acc), $rnAll0, rh_billing_received_net($pdo, date('Y-m-d'))]));
    $pdo->exec('ROLLBACK TO SAVEPOINT gcmt_refund_out');

    // ===== A5 refusals: mixed guest / company, and two companies =====
    $J = $mk(); $K = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']);
    $group($J, [$K]);
    $pay($J, $billed($J) + 100); $pay($K, $billed($K) - 100);
    $pv = rh_group_credit_move($pdo, $J, $opts + ['dry_run' => true]);
    ok('A5 payer refusal: guest credit to a company room refused (mixed)', empty($pv['success']) && stripos($pv['error'], 'same payer') !== false && stripos($pv['error'], 'GCMT Company') !== false, $pv['error']);
    $W = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $X = $mk(['billing_account_id' => $acc2, 'billing_covers' => 'stay']);
    $group($W, [$X]);
    $coPay($W, $billed($W) + 100, $acc); $coPay($X, $billed($X) - 100, $acc2);
    $pv = rh_group_credit_move($pdo, $W, $opts + ['dry_run' => true]);
    ok('A5 payer refusal: two different companies refused', empty($pv['success']) && stripos($pv['error'], 'different companies') !== false, $pv['error']);

    // ===== A5 same company: the guest's own surplus never pays the company's bill =====
    $Y = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $Z = $mk();
    $group($Y, [$Z]);
    $coPay($Y, $billed($Y), $acc); $pay($Y, 100); $coPay($Z, $billed($Z) - 100, $acc);
    $pv = rh_group_credit_move($pdo, $Y, $opts + ['dry_run' => true]);
    ok('A5 same company: credit that is all guest money refused', empty($pv['success']) && stripos($pv['error'], 'guest\'s own money') !== false, $pv['error']);
    $Y2 = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $Z2 = $mk();
    $group($Y2, [$Z2]);
    $coPay($Y2, $billed($Y2) + 60, $acc); $pay($Y2, 40); $coPay($Z2, $billed($Z2) - 100, $acc);
    $pv = rh_group_credit_move($pdo, $Y2, $opts + ['dry_run' => true]);
    ok('A5 same company: mixed credit -> only the company 60 moves, the guest 40 stays', !empty($pv['success']) && $near($pv['moved'], 60) && $near($pv['guest_credit'], 40) && $near($pv['left_over'], 40),
        json_encode([$pv['error'], $pv['moved'], $pv['guest_credit'], $pv['left_over']]));
    $res = rh_group_credit_move($pdo, $Y2, $opts + ['expect_state' => $pv['state'] ?? '']);
    ok('A5 same company: mixed credit applied, 40 guest credit left on Y2', !empty($res['success']) && $near($fig($Y2)['credit'], 40) && $near($fig($Z2)['owed'], 40), json_encode([$res['error'], $fig($Y2), $fig($Z2)]));

    // ===== A5 same company: a receiver with guest extras (covers 'stay') gets only what the company owes =====
    $AA = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $BB = $mk();
    $group($AA, [$BB]);
    $coPay($AA, $billed($AA) + 200, $acc); $coPay($BB, $billed($BB) - 50, $acc); $charge($BB, 80);
    $pv = rh_group_credit_move($pdo, $AA, $opts + ['dry_run' => true]);
    ok('A5 same company: receiver capped at the company due (50 of 130 owed)', !empty($pv['success']) && $near($pv['moved'], 50) && $near($pv['rooms'][0]['company_due'] ?? -1, 50)
        && $near($pv['rooms'][0]['owed'] ?? -1, 130) && $near($pv['left_over'], 150), json_encode([$pv['error'], $pv['rooms'], $pv['moved']]));
    $res = rh_group_credit_move($pdo, $AA, $opts + ['expect_state' => $pv['state'] ?? '']);
    ok('A5 same company: after the move BB owes only its guest extras', !empty($res['success']) && $near($fig($BB)['owed'], 80) && $near(rh_booking_bill_split($pdo, $BB)['company_due'], 0)
        && $near(rh_booking_bill_split($pdo, $BB)['guest_due'], 80), json_encode([$res['error'], rh_booking_bill_split($pdo, $BB)]));

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
    $pdo->prepare('UPDATE bookings SET billing_account_id = ?, billing_covers = ? WHERE id = ?')->execute([$acc, 'stay', $M]);
    $qp = rh_group_lump_quote($pdo, $N, $L, 100);
    ok('D-R6 different payers refused (guest rooms + one company room)', !$qp['ok'] && stripos($qp['error'], 'same payer') !== false, $qp['error']);
    $qp0 = rh_group_lump_quote($pdo, $N, $L, 0);
    ok('D-R6 different payers: review shows max 0 with the reason', $qp0['ok'] && $near($qp0['max'], 0) && stripos($qp0['note'], 'same payer') !== false, json_encode([$qp0['max'], $qp0['note']]));
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

    // ===== D-R6 same company (Ship C2b): an inherited member leaves; its company billing is copied, company money follows =====
    $CC = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $DD = $mk(); $EE = $mk();
    $group($CC, [$DD, $EE]);
    $coPay($CC, $billed($CC) + 200, $acc); $coPay($DD, $billed($DD), $acc); $pay($DD, 30); // DD: 30 of guest money above the bill
    $g7 = [$CC, $DD, $EE];
    $cpm7 = rh_billing_company_paid_map($pdo, $g7);
    $rn7 = rh_billing_received_net($pdo, date('Y-m-d'), $acc);
    $paid7 = $sumPaid($g7);
    $q = rh_group_lump_quote($pdo, $EE, $CC, 250);
    ok('D-R6 same company: quote ok, payer is the company', $q['ok'] && $q['payer']['mode'] === 'company' && $near($q['leaving']['company_due'] ?? -1, $fig($EE)['owed']), json_encode([$q['error'], $q['payer']]));
    $takes7 = array_column($q['sources'], 'take', 'id');
    ok('D-R6 same company: all 250 from CC (DD holds guest money above its bill, so it gives only company surplus: none)', $near(array_sum($takes7), 250)
        && $near((float)($takes7[$CC] ?? 0), 250) && !isset($takes7[$DD]), json_encode($q['sources']));
    $pv = rh_group_remove($pdo, $EE, $opts + ['dry_run' => true]);
    $res = rh_group_remove($pdo, $EE, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($EE, $q, $opts): void {
        rh_group_lump_apply($pdo, $EE, (int)$r['new_root'], 250, $q['state'], $opts);
    }]);
    ok('D-R6 same company: remove + move 250 ok', !empty($res['success']), $res['error'] ?? '');
    $cpm7b = rh_billing_company_paid_map($pdo, $g7);
    ok('D-R6 same company: EE keeps the company and its company paid is 250', (int)$pdo->query('SELECT COALESCE(billing_account_id, 0) FROM bookings WHERE id = ' . $EE)->fetchColumn() === $acc
        && $near($cpm7b[$EE] ?? 0, 250), json_encode($cpm7b));
    ok('D-R6 same company: Σ company paid, Σ paid and company money received unchanged', $near(array_sum($cpm7b), array_sum($cpm7)) && $near($sumPaid($g7), $paid7)
        && $near(rh_billing_received_net($pdo, date('Y-m-d'), $acc), $rn7), json_encode([$cpm7, $cpm7b]));
    ok('D-R6 same company: DD guest money untouched (still 30 credit)', $near($fig($DD)['credit'], 30) && $near(rh_booking_bill_split($pdo, $DD)['guest_paid'], 30), json_encode([$fig($DD), rh_booking_bill_split($pdo, $DD)]));
    ok('D-R6 same company: credit note made out to the company, History says so', (int)$pdo->query("SELECT COUNT(*) FROM credit_notes WHERE id > $cnMax0 AND booking_id IN ($CC, $DD) AND guest_name = 'GCMT Company'")->fetchColumn() === 1
        && $histLike($EE, 'company money of GCMT Company') === 1 && $histLike($CC, 'company money of GCMT Company') === 1);

    // D-R6 same company, the PRIMARY leaves: billing goes to the new root too.
    $FF = $mk(['billing_account_id' => $acc, 'billing_covers' => 'stay']); $GG = $mk();
    $group($FF, [$GG]);
    $coPay($FF, $billed($FF) - 120, $acc); $coPay($GG, $billed($GG) + 100, $acc);
    $g8 = [$FF, $GG];
    $cpm8 = rh_billing_company_paid_map($pdo, $g8);
    $q = rh_group_lump_quote($pdo, $FF, $FF, 120);
    $pv = rh_group_remove($pdo, $FF, $opts + ['dry_run' => true]);
    $res = rh_group_remove($pdo, $FF, $opts + ['expect_state' => $pv['state'], 'after_detach' => static function (PDO $pdo, array $r) use ($FF, $q, $opts): void {
        rh_group_lump_apply($pdo, $FF, (int)$r['new_root'], 120, $q['state'], $opts);
    }]);
    $cpm8b = rh_billing_company_paid_map($pdo, $g8);
    ok('D-R6 same company, primary leaves: moved 120, FF owes 0, company paid +120 / -120', $q['ok'] && !empty($res['success']) && $near($fig($FF)['owed'], 0)
        && $near(($cpm8b[$FF] ?? 0) - ($cpm8[$FF] ?? 0), 120) && $near(($cpm8b[$GG] ?? 0) - ($cpm8[$GG] ?? 0), -120), json_encode([$q['error'], $res['error'] ?? '', $cpm8, $cpm8b]));
    ok('D-R6 same company, primary leaves: GG still billed to the company', rh_booking_bill_split($pdo, $GG)['account_id'] === $acc || (int)rh_booking_bill_split($pdo, $GG)['account_id'] === $acc);

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
    + (int)$pdo->query("SELECT COUNT(*) FROM billing_accounts WHERE name LIKE 'GCMT Company%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM billing_remittances WHERE remittance_reference LIKE 'GCMT-%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_charges WHERE description = 'Bar (GCMT test)'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_reference LIKE 'GCMT-%'")->fetchColumn();
ok('leak check: no test rows left', $leak === 0, (string)$leak);
$ictamAfter = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(primary_booking_id, 0), ':', amount_paid) ORDER BY id) FROM bookings WHERE id = 2100008837 OR primary_booking_id = 2100008837")->fetchColumn();
ok('ICTAm group untouched', $ictamAfter === $ictamBefore);

echo "\ngroup_credit_move: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
