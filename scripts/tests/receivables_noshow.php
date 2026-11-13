<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Still owed and no-show rooms (owner 2026-11-13). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/receivables_noshow.php
 * The ONE receivables rule (admin/includes/receivables.php rh_receivable_status_sql) now counts:
 *  - a no-show room still owing (amount_due = folio balance_due) -> in Still owed, status 'no-show', aged from check-in;
 *  - an expired room carrying the group no-show / expiry charge (tag [noshow-fee:<id>]) and owing -> in;
 *  - an untagged expired hold, a no-show in credit, a no-show whose charge is "none", a cancelled booking -> out.
 * End of Day "Still owed" (rh_eod_outstanding_split) moves by exactly the counted rows. Everything runs in ONE
 * transaction that is rolled back; the group charge setting is switched IN MEMORY only.
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
require_once $root . '/admin/includes/receivables.php';
require_once $root . '/admin/includes/eod-revenue.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
$setMode = static function (string $v): void { $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = $v; };

/** A room booking 1,165 a night all-in (1,000 net + 165 VAT), check-in $nights days ago. */
function rn_booking(PDO $pdo, string $status, int $nights = 2, ?int $primaryId = null): int {
    $in = date('Y-m-d', strtotime('-' . $nights . ' day'));
    $out = date('Y-m-d');
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'RNS TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,'receivables no-show test',?,'unpaid',0,'single',?,?,NOW())")
        ->execute(['RNST' . random_int(100000, 999999), $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function rn_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['RNSP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function rn_status(PDO $pdo, int $bid, string $status): void {
    $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $bid]);
    recalculateBookingFinancials($bid);
}
$mods = ['conference' => false, 'gym' => false, 'events' => false, 'tabs' => false];
$owedById = static function (PDO $pdo) use ($mods): array {
    $out = [];
    foreach (rh_receivable_rows($pdo, $mods) as $r) { $out[$r['id']] = $r; }
    return $out;
};

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
$before = rh_eod_outstanding_split($pdo, $mods);
$pdo->beginTransaction();
try {
    // Group of 6 rooms so the group charge applies to members.
    $lead = rn_booking($pdo, 'confirmed', 2);
    // A: untagged no-show, part paid -> owes the full stay less paid (owner: a no-show pays 100% of the stay).
    $a = rn_booking($pdo, 'confirmed', 2, $lead);
    rn_pay($pdo, $a, 1000);
    rn_status($pdo, $a, 'no-show');
    // B: no-show overpaid -> credit, never owed.
    $b = rn_booking($pdo, 'confirmed', 2, $lead);
    rn_pay($pdo, $b, 3000);
    rn_status($pdo, $b, 'no-show');
    // C: untagged expired hold, unpaid -> an unpaid hold, not a debt.
    $c = rn_booking($pdo, 'pending', 2, $lead);
    rn_status($pdo, $c, 'expired');
    // D: expired with the group charge (first night) -> owes the first night only.
    $setMode('first_night');
    $d = rn_booking($pdo, 'pending', 2, $lead);
    rn_status($pdo, $d, 'expired');
    $ap = rh_group_noshow_fee_apply($pdo, $d, 'expired');
    // E: no-show with the group charge "none" (voided zero row) -> bills nothing.
    $setMode('none');
    $e = rn_booking($pdo, 'confirmed', 2, $lead);
    rn_status($pdo, $e, 'no-show');
    $apE = rh_group_noshow_fee_apply($pdo, $e, 'no-show');
    $setMode('0');
    // F: cancelled, nothing retained -> out (cancellation fees are a separate feature).
    $f = rn_booking($pdo, 'confirmed', 2, $lead);
    rn_status($pdo, $f, 'cancelled');

    $owed = $owedById($pdo);
    $fa = getBookingFolioSummary($a);
    ok('A no-show owing is in Still owed', isset($owed[$a]));
    ok('A amount = folio balance_due (1,330)', isset($owed[$a]) && $near($owed[$a]['amount_due'], $fa['balance_due'] ?? -1) && $near($owed[$a]['amount_due'], 1330),
        json_encode([$owed[$a]['amount_due'] ?? null, $fa['balance_due'] ?? null]));
    ok('A status reads no-show (the list shows "No-show")', ($owed[$a]['status'] ?? '') === 'no-show');
    ok('A aged from its check-in date (2 days)', (int)($owed[$a]['days_outstanding'] ?? -1) === 2, (string)($owed[$a]['days_outstanding'] ?? ''));
    ok('B no-show in credit is not owed', !isset($owed[$b]));
    ok('C untagged expired hold is not owed', !isset($owed[$c]));
    ok('D tagged expired: charge posted', !empty($ap['posted']) && rh_noshow_fee_tagged($pdo, $d), json_encode($ap));
    ok('D tagged expired owing is in Still owed at its first night only (1,165)', isset($owed[$d]) && $near($owed[$d]['amount_due'], 1165)
        && $near($owed[$d]['amount_due'], getBookingFolioSummary($d)['balance_due'] ?? -1), json_encode($owed[$d] ?? null));
    ok('E no-show charged "none" bills nothing, not owed', rh_noshow_fee_tagged($pdo, $e) && !isset($owed[$e]), json_encode($apE));
    ok('F cancelled (nothing retained) is not owed', !isset($owed[$f]));
    ok('lead (confirmed, unpaid) still owed as before', isset($owed[$lead]) && $near($owed[$lead]['amount_due'], 2330));

    // End of Day "Still owed" moves by exactly the counted test rows (lead 2,330 + A 1,330 + D 1,165).
    $after = rh_eod_outstanding_split($pdo, $mods);
    ok('End of Day Still owed (folio) moves by exactly lead + A + D = 4,825', $near($after['folio'] - $before['folio'], 4825),
        json_encode([$before['folio'], $after['folio']]));
    $sum = rh_receivable_summary($pdo, $mods);
    ok('summary total = End of Day folio figure (one rule)', $near($sum['total'], $after['folio']), json_encode([$sum['total'], $after['folio']]));

    // Deposits not yet confirmed never take a no-show / expired room (closed), so nothing is counted twice.
    $dep = [];
    foreach (rh_unconfirmed_deposit_rows($pdo, $mods) as $r) { $dep[$r['id']] = true; }
    ok('no-show / expired rooms never in "Deposits taken, not yet confirmed"', !isset($dep[$a]) && !isset($dep[$b]) && !isset($dep[$c]) && !isset($dep[$d]));

    // Payments list row note: a payment on the owing no-show reads "still owes" (account_in_rule from the same rule).
    $st = $pdo->prepare('SELECT CASE WHEN b.id IS NOT NULL AND (' . rh_receivable_status_sql('room', 'b') . ') THEN 1 ELSE 0 END FROM bookings b WHERE b.id = ?');
    $st->execute([$a]); $inA = (int)$st->fetchColumn();
    $st->execute([$c]); $inC = (int)$st->fetchColumn();
    $st->execute([$d]); $inD = (int)$st->fetchColumn();
    ok('rule SQL: no-show in, untagged expired out, tagged expired in', $inA === 1 && $inC === 0 && $inD === 1, json_encode([$inA, $inC, $inD]));
    // Unaliased form (bookings.id) works too.
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE id IN (?, ?) AND ' . rh_receivable_status_sql('room'));
    $cnt->execute([$c, $d]);
    ok('rule SQL without an alias: tagged expired in, untagged out', (int)$cnt->fetchColumn() === 1);
} catch (Throwable $ex) {
    ok('no exception', false, $ex->getMessage() . ' @' . $ex->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $setMode('0');
}
$left = $chk->query("SELECT COUNT(*) FROM bookings WHERE special_requests = 'receivables no-show test'")->fetchColumn();
ok('rolled back: no test bookings left', (int)$left === 0, (string)$left);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
