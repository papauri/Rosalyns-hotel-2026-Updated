<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Cancelled LEAD room of a group (owner 2026-11-13). Usage (Liwonde root): php scripts/tests/group_lead_cancel.php
 * The group's documents are built from the next active room when the lead (primary_booking_id target) is cancelled;
 * no row is re-parented. Everything runs inside ONE transaction that is rolled back (a second connection checks nothing
 * leaked). Refuses to run against Rosalyn's (LIVE).
 *  - Pure pick: lead live = lead; lead cancelled = next active room (checked-out counts; cancelled / no-show / expired /
 *    deleted never); every other room closed = lead; lead not in the set = 0.
 *  - Lead live: the invoice is built from the lead (unchanged).
 *  - Lead cancelled, no payment: invoice from the next room; the old lead is off unless it carries money; total = sum of
 *    the listed rooms; lines foot to the total; every receipt counts the same rooms and names the new lead's reference;
 *    no primary_booking_id changed.
 *  - Lead cancelled with a payment: the old lead stays, marked Cancelled; Amount paid = sum of the per-room receipts.
 *  - Whole group cancelled: the lead stays the original lead.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/config/invoice.php';
require_once $root . '/includes/booked-rate.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;

// ---- Pure pick (no DB rows: ids that do not exist have no room label, so the order is by id) ----
$R = static fn(int $id, string $st, bool $del = false): array => ['id' => $id, 'status' => $st, 'deleted_at' => $del ? '2026-01-01' : null];
ok('pure: live lead stays the lead', rh_booking_group_doc_lead([$R(9000000000 + 9, 'confirmed'), $R(9000000000 + 8, 'confirmed')], 9000000000 + 9) === 9000000000 + 9);
ok('pure: no-show lead stays the lead (only a cancelled lead hands over)', rh_booking_group_doc_lead([$R(9000000000 + 9, 'no-show'), $R(9000000000 + 8, 'confirmed')], 9000000000 + 9) === 9000000000 + 9);
ok('pure: cancelled lead -> next active room', rh_booking_group_doc_lead([$R(9000000000 + 9, 'cancelled'), $R(9000000000 + 7, 'confirmed'), $R(9000000000 + 8, 'checked-in')], 9000000000 + 9) === 9000000000 + 7);
ok('pure: cancelled / no-show / expired / deleted rooms never lead', rh_booking_group_doc_lead([$R(9000000000 + 9, 'cancelled'), $R(9000000000 + 8, 'cancelled'), $R(9000000000 + 7, 'no-show'),
    $R(9000000000 + 6, 'expired'), $R(9000000000 + 5, 'confirmed', true), $R(9000000000 + 4, 'checked-out')], 9000000000 + 9) === 9000000000 + 4);
ok('pure: every other room closed -> the original lead', rh_booking_group_doc_lead([$R(9000000000 + 9, 'cancelled'), $R(9000000000 + 8, 'cancelled'), $R(9000000000 + 7, 'no-show')], 9000000000 + 9) === 9000000000 + 9);
ok('pure: lead not in the set -> 0 (caller keeps its own pick)', rh_booking_group_doc_lead([$R(9000000000 + 8, 'confirmed')], -9) === 0);

/** A room booking 1,165 a night all-in (1,000 net + 165 VAT, no levy), far in the future, confirmed. */
function gl_booking(PDO $pdo, ?int $primaryId = null, string $guest = 'GLC TEST'): int {
    $in = date('Y-m-d', strtotime('+700 day'));
    $out = date('Y-m-d', strtotime('+702 day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),?,'','+265000000009','Malawi',1,1,0,50,?,?,2,2000,0,0,0,16.5,330,2330,'group lead cancel test','confirmed','unpaid',0,'single',?,?,NOW())")
        ->execute(['GLCT' . random_int(100000, 999999), $guest, $in, $out, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function gl_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['GLCP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function gl_ref(PDO $pdo, int $id): string {
    $s = $pdo->prepare('SELECT booking_reference FROM bookings WHERE id = ?');
    $s->execute([$id]);
    return (string)$s->fetchColumn();
}
function gl_parents(PDO $pdo, array $ids): array {
    $s = $pdo->prepare('SELECT id, primary_booking_id FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $s->execute($ids);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
/** Invoice built from any room of the group, plus what it lists and what each listed room's folio says. */
function gl_doc(PDO $pdo, int $anyId): array {
    $rows = rh_invoice_doc_rows($pdo, $anyId);
    $t = null;
    $html = buildInvoiceHTML($rows['booking'], 'TEST-GLC', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    $listed = [];
    $grand = 0.0; $paid = 0.0;
    $set = $rows['group_bookings'] ? rh_inv_group_rooms($pdo, $rows['booking'], array_values($rows['group_bookings'])) : [$rows['booking']];
    foreach ($set as $g) {
        $listed[(int)$g['id']] = (string)($g['rh_inv_removed'] ?? '');
        $fs = getBookingFolioSummary((int)$g['id']);
        $grand += (float)($fs['grand_total'] ?? 0);
        $paid += (float)($fs['amount_paid'] ?? 0);
    }
    return ['lead' => (int)$rows['booking']['id'], 'listed' => $listed, 't' => $t, 'html' => (string)$html, 'grand' => round($grand, 2), 'paid' => round($paid, 2)];
}
function gl_has_money(PDO $pdo, int $id): bool {
    $fs = getBookingFolioSummary($id);
    $p = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL");
    $p->execute([$id]);
    return (float)($fs['grand_total'] ?? 0) > BALANCE_TOLERANCE || (int)$p->fetchColumn() > 0;
}
/** Every room's receipt: the same N as the invoice, and "Part of group <REF>". */
function gl_receipts(PDO $pdo, array $ids, int $n, string $ref): array {
    $bad = [];
    foreach ($ids as $id) {
        rh_booked_rate_invoice_room_count($pdo, 0, true);
        $lines = implode(' | ', rh_booked_rate_room_receipt_lines($pdo, $id));
        rh_booked_rate_invoice_room_count($pdo, 0, true);
        $cnt = rh_booked_rate_invoice_room_count($pdo, $id);
        if ($cnt !== $n || strpos($lines, 'Part of group ' . $ref . ' (' . $n . ' rooms)') === false) {
            $bad[] = $id . ': n=' . var_export($cnt, true) . ' ' . $lines;
        }
    }
    return $bad;
}
$foots = static function (array $t) use ($near): bool {
    $m = vat_mode();
    $sum = (float)$t['subtotal'] + ($m === 'exclusive' ? (float)$t['vat'] : 0.0) + (float)$t['levy'];
    return $near($sum, $t['total']) || ($m === 'off' && $near((float)$t['subtotal'] + (float)$t['levy'], $t['total']));
};
$admin = (int)$pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

if (function_exists('ensureBookingAuditTable')) {
    ensureBookingAuditTable($pdo); // DDL commits implicitly: never inside the test transaction
}
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$made = [];
$pdo->beginTransaction();
try {
    // ---- Case A: 3 rooms, lead cancelled, no payment ----
    $a0 = gl_booking($pdo); $a1 = gl_booking($pdo, $a0); $a2 = gl_booking($pdo, $a0);
    $made = array_merge($made, [$a0, $a1, $a2]);
    $before = gl_doc($pdo, $a1);
    ok('A: lead live -> the invoice is built from the lead', $before['lead'] === $a0, (string)$before['lead']);
    ok('A: lead live -> all three rooms listed', array_keys($before['listed']) === [$a0, $a1, $a2], json_encode($before['listed']));
    $res = cancelRoomBookingSettled($pdo, $a0, $admin, 'group lead cancel test');
    ok('A: lead cancelled through the real cancel path', !empty($res['success']), (string)($res['error'] ?? ''));
    ok('A: no row re-parented (primary_booking_id unchanged)', gl_parents($pdo, [$a0, $a1, $a2]) === [$a0 => null, $a1 => $a0, $a2 => $a0], json_encode(gl_parents($pdo, [$a0, $a1, $a2])));
    $after = [];
    foreach ([$a0, $a1, $a2] as $id) {
        $after[$id] = gl_doc($pdo, $id);
    }
    ok('A: the same invoice whichever room it is opened from', count(array_unique(array_map(static fn($d) => md5(json_encode([$d['lead'], $d['listed'], $d['t']])), $after))) === 1);
    $d = $after[$a1];
    ok('A: invoice now built from the next active room', $d['lead'] === $a1, (string)$d['lead']);
    ok('A: effective lead helper agrees', rh_booking_group_effective_lead($pdo, $a2) === $a1 && rh_booking_group_effective_lead($pdo, $a0) === $a1);
    $aMoney = gl_has_money($pdo, $a0);
    ok('A: old lead ' . ($aMoney ? 'kept (money), marked Cancelled' : 'off the invoice (no money)'),
        $aMoney ? (($d['listed'][$a0] ?? null) === 'Cancelled') : !isset($d['listed'][$a0]), json_encode($d['listed']));
    ok('A: active rooms listed, live', ($d['listed'][$a1] ?? null) === '' && ($d['listed'][$a2] ?? null) === '', json_encode($d['listed']));
    ok('A: total = sum of the listed rooms (folio summaries)', $near($d['t']['total'], $d['grand']), json_encode([$d['t'], $d['grand']]));
    ok('A: total = the two active rooms (+ what the cancelled lead kept)', $near($d['t']['total'], 2 * 2330 + ($aMoney ? (float)getBookingFolioSummary($a0)['grand_total'] : 0)), json_encode($d['t']));
    ok('A: printed lines foot to the total (no cancelled full-stay line)', $foots($d['t']), json_encode($d['t']));
    ok('A: amount paid = sum of the listed rooms', $near($d['t']['paid'], $d['paid']));
    $bad = gl_receipts($pdo, [$a0, $a1, $a2], count($d['listed']), gl_ref($pdo, $a1));
    ok('A: every receipt counts the invoice rooms and names the new lead', !$bad, implode(' || ', $bad));
    ok('A: group membership unchanged (secondaries still find each other)', rh_booking_group_ids($pdo, $a2) === [$a2, $a1]);

    // ---- Case B: 3 rooms, payment on the lead, lead cancelled ----
    $b0 = gl_booking($pdo); $b1 = gl_booking($pdo, $b0); $b2 = gl_booking($pdo, $b0);
    $made = array_merge($made, [$b0, $b1, $b2]);
    gl_pay($pdo, $b0, 500.0);
    gl_pay($pdo, $b2, 300.0);
    $res = cancelRoomBookingSettled($pdo, $b0, $admin, 'group lead cancel test (paid)');
    ok('B: paid lead cancelled', !empty($res['success']), (string)($res['error'] ?? ''));
    $d = gl_doc($pdo, $b0);
    ok('B: invoice built from the next active room', $d['lead'] === $b1, (string)$d['lead']);
    ok('B: old lead stays, marked Cancelled (it carries money)', ($d['listed'][$b0] ?? null) === 'Cancelled', json_encode($d['listed']));
    ok('B: lead first, then the other active room, then the cancelled one', array_keys($d['listed']) === [$b1, $b2, $b0], json_encode(array_keys($d['listed'])));
    ok('B: total = sum of the listed rooms', $near($d['t']['total'], $d['grand']), json_encode([$d['t'], $d['grand']]));
    ok('B: printed lines foot to the total', $foots($d['t']), json_encode($d['t']));
    $rcptPaid = 0.0;
    foreach ([$b0, $b1, $b2] as $id) {
        $rcptPaid += (float)(getBookingFolioSummary($id)['amount_paid'] ?? 0);
    }
    ok('B: Amount paid = sum of the per-room receipts (net of any refund)', $near($d['t']['paid'], $rcptPaid), json_encode([$d['t']['paid'], $rcptPaid]));
    $bad = gl_receipts($pdo, [$b0, $b1, $b2], 3, gl_ref($pdo, $b1));
    ok('B: receipts say 3 rooms and the new lead', !$bad, implode(' || ', $bad));
    $sp = rh_billing_doc_scope($pdo, $b2);
    ok('B: billing scope lead = new lead, all rooms in scope', (int)$sp['lead'] === $b1 && $sp['ids'] === [$b0, $b1, $b2], json_encode($sp));

    // ---- Case C: whole group cancelled -> the original lead stays ----
    $c0 = gl_booking($pdo); $c1 = gl_booking($pdo, $c0); $c2 = gl_booking($pdo, $c0);
    $made = array_merge($made, [$c0, $c1, $c2]);
    $gr = rh_cancel_booking_group($pdo, [$c0, $c1, $c2], $admin, 'group lead cancel test (whole group)');
    ok('C: whole group cancelled', !empty($gr['success']), (string)($gr['error'] ?? ''));
    $d = gl_doc($pdo, $c2);
    ok('C: invoice still built from the original lead', $d['lead'] === $c0 && rh_booking_group_effective_lead($pdo, $c1) === $c0, (string)$d['lead']);

    // ---- Case D: lead live, a secondary cancelled -> unchanged ----
    $e0 = gl_booking($pdo); $e1 = gl_booking($pdo, $e0); $e2 = gl_booking($pdo, $e0);
    $made = array_merge($made, [$e0, $e1, $e2]);
    cancelRoomBookingSettled($pdo, $e1, $admin, 'group lead cancel test (member)');
    $d = gl_doc($pdo, $e2);
    ok('D: member cancelled, lead live -> lead unchanged', $d['lead'] === $e0 && rh_booking_group_effective_lead($pdo, $e1) === $e0, (string)$d['lead']);

    // ---- Case E: the next lead is the lowest room NUMBER (not the lowest id); an unassigned room comes after ----
    $irs = $pdo->query("SELECT id, room_number FROM individual_rooms WHERE TRIM(COALESCE(room_number, '')) <> '' ORDER BY id LIMIT 40")->fetchAll(PDO::FETCH_KEY_PAIR);
    uasort($irs, static fn($x, $y) => strnatcasecmp((string)$x, (string)$y));
    $irIds = array_keys($irs);
    if (count($irIds) >= 2 && strnatcasecmp((string)$irs[$irIds[0]], (string)$irs[end($irIds)]) < 0) {
        $f0 = gl_booking($pdo); $f1 = gl_booking($pdo, $f0); $f2 = gl_booking($pdo, $f0); $f3 = gl_booking($pdo, $f0);
        $made = array_merge($made, [$f0, $f1, $f2, $f3]);
        $br = $pdo->prepare("INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot) VALUES (?, ?, NULL, 1, NOW(), 'assigned')");
        $br->execute([$f1, end($irIds)]);  // highest room number on the lower id
        $br->execute([$f2, $irIds[0]]);    // lowest room number on the higher id; $f3 stays unassigned
        cancelRoomBookingSettled($pdo, $f0, $admin, 'group lead cancel test (room order)');
        ok('E: next lead = lowest room number, unassigned last', rh_booking_group_effective_lead($pdo, $f3) === $f2
            && rh_invoice_doc_rows($pdo, $f1)['booking']['id'] == $f2, json_encode([rh_booking_group_effective_lead($pdo, $f3), $f1, $f2, $f3]));
    } else {
        ok('E: skipped (fewer than two numbered rooms)', true);
    }
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
if ($made) {
    $s = $chk->prepare('SELECT COUNT(*) FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($made), '?')) . ')');
    $s->execute($made);
    ok('rolled back: no test booking left', (int)$s->fetchColumn() === 0);
}
ok('rolled back: no test payment left', (int)$chk->query("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'GLCP%'")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
