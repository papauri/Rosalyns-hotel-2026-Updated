<?php
/**
 * Pure (no-DB) tests: "Charged to room" on the till Z, End of Day and Orders & Tabs (owner 2026-11-11,
 * standard hotel POS practice). An order closed to a guest bill (status completed, no till tender) is on
 * the Z order count and sales once, as its own tender line, and never in any drawer figure.
 *  1. rh_pos_z_order_tender classifies cash / mobile / card / split / room / not-a-sale; a room-service
 *     order paid at the till is its tender, never a room charge.
 *  2. The SQL rules: rh_pos_room_charge_sql (4 params), rh_pos_z_sale_sql = till sale OR room charge (8 params).
 *  3. The Z rule selects exactly the rows End of Day's earlier inline rule selected (so EOD does not move).
 *  4. rh_pos_shift_totals_assemble with a fixture (cash + card + split + room charge + refund + void):
 *     every drawer figure is identical with and without the room charge; orders / sales include it once.
 *  5. Wiring: closes still store till figures; Z / EOD / Orders & Tabs read the shared helpers.
 *
 *   php scripts/tests/z_room_charge.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require_once dirname(__DIR__, 2) . '/admin/includes/pos-shift-totals.php'; // pure helpers only at include

$P = 0; $F = 0;
function zr_check(bool $c, string $label, $detail = ''): void
{
    global $P, $F;
    if ($c) { $P++; echo "  [PASS] $label\n"; return; }
    $F++; echo "  [FAIL] $label" . ($detail !== '' ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function zr_src(string $rel): string
{
    return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

$W0 = '2026-11-11 06:00:00';
$W1 = '2026-11-12 06:00:00';
$in = static fn(?string $t): bool => $t !== null && $t >= $W0 && $t < $W1;

echo "\n--- 1. Classifier ---\n";
$o = static fn(array $x): array => $x + ['status' => 'paid', 'paid_at' => '2026-11-11 12:00:00', 'created_at' => '2026-11-11 11:50:00', 'payment_method' => 'cash', 'split_count' => 1, 'order_type' => 'restaurant'];
zr_check(rh_pos_z_order_tender($o([])) === 'cash', 'cash sale -> cash');
zr_check(rh_pos_z_order_tender($o(['payment_method' => 'mobile_money'])) === 'mobile', 'mobile money -> mobile');
zr_check(rh_pos_z_order_tender($o(['payment_method' => 'card_pos'])) === 'card' && rh_pos_z_order_tender($o(['payment_method' => 'card_manual'])) === 'card', 'card POS / manual -> card');
zr_check(rh_pos_z_order_tender($o(['split_count' => 2, 'payment_method' => 'card_pos'])) === 'split', 'split bill -> split (legs read per tender)');
zr_check(rh_pos_z_order_tender($o(['status' => 'completed', 'paid_at' => null, 'payment_method' => null, 'order_type' => 'room_service'])) === 'room', 'closed to a guest bill -> room');
zr_check(rh_pos_z_order_tender($o(['order_type' => 'room_service'])) === 'cash', 'room service PAID at the till -> its tender, not a room charge (never twice)');
zr_check(rh_pos_z_order_tender($o(['status' => 'refunded'])) === 'cash' && rh_pos_z_order_tender($o(['status' => 'voided'])) === 'cash', 'paid then refunded / voided -> still a sale in its tender (payout separate)');
zr_check(rh_pos_z_order_tender($o(['payment_method' => 'other'])) === 'other', 'till sale with no drawer tender -> other');
zr_check(rh_pos_z_order_tender($o(['paid_at' => null])) === 'cash', 'legacy paid row without paid_at -> sale');
foreach (['placed', 'cancelled', 'voided', 'refunded', 'pending'] as $s) {
    zr_check(rh_pos_z_order_tender($o(['status' => $s, 'paid_at' => null])) === null, "unpaid $s -> not a sale");
}

echo "\n--- 2. SQL rules ---\n";
$rcSql = rh_pos_room_charge_sql('o');
$zSql = rh_pos_z_sale_sql('o');
zr_check(substr_count($rcSql, '?') === 4 && strpos($rcSql, "o.status = 'completed'") !== false, 'room charge rule: status completed, 4 params', $rcSql);
zr_check(strpos(rh_pos_sale_window_sql('o'), 'completed') === false, 'till sale window never selects a room charge (drawer queries untouched)');
zr_check(substr_count($zSql, '?') === 8 && strpos($zSql, rh_pos_sale_window_sql('o')) !== false && strpos($zSql, $rcSql) !== false, 'Z rule = till sale OR room charge, 8 params');
zr_check(rh_pos_z_sale_params($W0, $W1) === [$W0, $W1, $W0, $W1, $W0, $W1, $W0, $W1], 'Z params = window x4');

echo "\n--- 3. Z rule = End of Day's earlier inline rule, row for row ---\n";
// The rule End of Day used before this change (copied verbatim into PHP).
$eodOld = static function (array $r) use ($in): bool {
    return ($r['paid_at'] !== null && in_array($r['status'], ['paid', 'completed', 'refunded', 'voided'], true) && $in($r['paid_at']))
        || ($r['paid_at'] === null && in_array($r['status'], ['paid', 'completed'], true) && $in($r['created_at']));
};
// The Z rule in PHP: a sale or room charge (classifier), counted at paid_at, else created_at.
$zNew = static function (array $r) use ($in): bool {
    return rh_pos_z_order_tender($r) !== null && $in($r['paid_at'] ?? $r['created_at']);
};
$mismatch = [];
$n = 0;
foreach (['placed', 'paid', 'completed', 'refunded', 'voided', 'cancelled', 'pending', 'confirmed'] as $st) {
    foreach ([null, '2026-11-11 09:00:00', '2026-11-10 22:00:00', '2026-11-12 07:00:00'] as $pa) {
        foreach (['2026-11-11 08:00:00', '2026-11-10 20:00:00', '2026-11-12 06:30:00'] as $ca) {
            $r = ['status' => $st, 'paid_at' => $pa, 'created_at' => $ca, 'payment_method' => 'cash', 'split_count' => 1];
            $n++;
            if ($eodOld($r) !== $zNew($r)) { $mismatch[] = $r; }
        }
    }
}
zr_check($mismatch === [], "Z rule selects exactly End of Day's rows ($n status x paid x created cases)", $mismatch);

echo "\n--- 4. Z totals assembly: fixture ---\n";
// Fixture orders in the window (one cashier). Tips are drawer money on cash orders.
$orders = [
    ['ref' => 'CASH1', 'status' => 'paid', 'paid_at' => '2026-11-11 12:00:00', 'created_at' => '2026-11-11 11:40:00', 'payment_method' => 'cash', 'split_count' => 1, 'total' => 100.0, 'tip' => 10.0],
    ['ref' => 'CARD1', 'status' => 'paid', 'paid_at' => '2026-11-11 13:00:00', 'created_at' => '2026-11-11 12:40:00', 'payment_method' => 'card_pos', 'split_count' => 1, 'total' => 200.0, 'tip' => 0.0],
    ['ref' => 'SPLIT', 'status' => 'paid', 'paid_at' => '2026-11-11 14:00:00', 'created_at' => '2026-11-11 13:30:00', 'payment_method' => 'card_pos', 'split_count' => 2, 'total' => 120.0, 'tip' => 0.0,
     'legs' => [['payment_method' => 'cash', 'amount' => 50.0], ['payment_method' => 'card_pos', 'amount' => 70.0]]],
    ['ref' => 'ROOM1', 'status' => 'completed', 'paid_at' => null, 'created_at' => '2026-11-11 15:00:00', 'payment_method' => null, 'split_count' => 1, 'total' => 300.0, 'tip' => 0.0, 'order_type' => 'room_service'],
    ['ref' => 'REFD', 'status' => 'refunded', 'paid_at' => '2026-11-11 16:00:00', 'created_at' => '2026-11-11 15:50:00', 'payment_method' => 'cash', 'split_count' => 1, 'total' => 80.0, 'tip' => 0.0],
    ['ref' => 'VOID', 'status' => 'voided', 'paid_at' => null, 'created_at' => '2026-11-11 17:00:00', 'payment_method' => null, 'split_count' => 1, 'total' => 40.0, 'tip' => 0.0],
];
// Fold the fixture the way the SQL aggregates it: tender rows via rh_pos_tender_bucket, room via the classifier.
$gross = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
$m = ['n' => 0, 'tips' => 0.0, 'tab_n' => 0, 'tab_amt' => 0.0, 'sales_total' => 0.0];
$rc = ['count' => 0, 'amount' => 0.0];
foreach ($orders as $ord) {
    $t = rh_pos_z_order_tender($ord);
    if ($t === null || !$in($ord['paid_at'] ?? $ord['created_at'])) { continue; }
    if ($t === 'room') { $rc['count']++; $rc['amount'] += $ord['total']; continue; }
    if ($t === 'split') {
        foreach ($ord['legs'] as $leg) { $gross[rh_pos_tender_bucket($leg['payment_method'])] += $leg['amount']; }
    } elseif (($b = rh_pos_tender_bucket($ord['payment_method'])) !== null) {
        $gross[$b] += $ord['total'] + $ord['tip'];
    }
    $m['n']++; $m['tips'] += $ord['tip']; $m['sales_total'] += $ord['total'];
}
$refund = ['cash' => 80.0, 'mobile' => 0.0, 'card' => 0.0]; // REFD paid out in cash this shift
$v = ['n' => 1, 'amt' => 40.0];
$fl = ['amount' => 50.0, 'recorded' => true];

$with = rh_pos_shift_totals_assemble($gross, $refund, 1, 80.0, $m, $v, $fl, $rc);
$without = rh_pos_shift_totals_assemble($gross, $refund, 1, 80.0, $m, $v, $fl, ['count' => 0, 'amount' => 0.0]);

zr_check($rc === ['count' => 1, 'amount' => 300.0], 'fixture: one room charge of 300', $rc);
zr_check($gross === ['cash' => 240.0, 'mobile' => 0.0, 'card' => 270.0], 'tenders: cash 100+10 tip+50 leg+80 = 240, card 200+70 = 270, no room money', $gross);
$drawerKeys = ['float', 'float_recorded', 'drawer_cash', 'cash', 'mobile', 'card', 'gross_cash', 'gross_mobile', 'gross_card',
    'refund_cash', 'refund_mobile', 'refund_card', 'refund_count', 'refund_amount', 'tips_total', 'orders_count', 'sales_total',
    'settled_from_tabs_count', 'settled_from_tabs_amount', 'voids_count', 'voids_amount'];
$diff = [];
foreach ($drawerKeys as $k) {
    if (!array_key_exists($k, $with) || $with[$k] !== $without[$k]) { $diff[$k] = [$with[$k] ?? null, $without[$k] ?? null]; }
}
zr_check($diff === [], 'every drawer / stored figure identical with and without the room charge', $diff);
zr_check($with['drawer_cash'] === 210.0 && $with['cash'] === 160.0 && $with['card'] === 270.0 && $with['mobile'] === 0.0, 'expected drawer: float 50 + cash 240 - refund 80 = 210; card 270', [$with['drawer_cash'], $with['cash'], $with['card']]);
zr_check($with['orders_count'] === 4 && $with['sales_total'] === 500.0, 'till orders 4 (cash, card, split, refunded later) / till sales 500 (voided unpaid not a sale)', [$with['orders_count'], $with['sales_total']]);
zr_check($with['room_charge_count'] === 1 && $with['room_charge_amount'] === 300.0, 'Charged to room line: 1 / 300');
zr_check($with['orders_all_count'] === 5 && $with['sales_all_total'] === 800.0, 'Z orders 5 / Z sales 800: room charge counted once');
zr_check($without['orders_all_count'] === $without['orders_count'] && $without['sales_all_total'] === $without['sales_total'], 'no room charges: Z totals = till totals');
$net = round($with['cash'] + $with['mobile'] + $with['card'], 2);
zr_check($net === 430.0, 'stored close revenue (net takings) stays tender-only: 160 + 270 = 430', $net);

echo "\n--- 5. Wiring ---\n";
$pos = zr_src('admin/pos.php');
zr_check(strpos($pos, "\$closeRevenue = round((float)\$E['cash'] + (float)\$E['mobile'] + (float)\$E['card'], 2);") !== false, 'till close still stores net takings (tenders only)');
zr_check(strpos($pos, "(float)\$E['card'], \$declCard, \$vCard, (int)\$E['orders_count'],") !== false, 'till close still stores till orders_count (old and new closes alike)');
zr_check(strpos($pos, "\$expCashDrawer = (float)\$E['drawer_cash'];") !== false, 'expected drawer cash unchanged');
zr_check(strpos($pos, 'closeShiftRoomAmount') !== false && strpos($pos, "Charged to room (<?php echo (int)(\$justClosedShift['room_charge_count']") !== false, 'close form + result show the Charged to room line');
$scr = zr_src('admin/shift-close-report.php');
zr_check(substr_count($scr, 'rh_pos_close_room_charges(') === 1 && substr_count($scr, '$rhZWithRoom(') === 3, 'Z report: single, date and list views add the room line on view');
zr_check(strpos($scr, 'rh_pos_z_sale_sql') !== false && strpos($scr, "rh_pos_z_order_tender(\$o)") !== false, 'Z order list uses the Z rule and the classifier');
$pst = zr_src('admin/includes/pos-shift-totals.php');
zr_check(strpos($pst, "\$saleCond = rh_pos_z_sale_sql('o');") !== false, 'End of Day POS block uses the Z rule');
zr_check(strpos(zr_src('admin/includes/eod-revenue.php'), "\$saleCond = rh_pos_z_sale_sql('o');") !== false, 'EOD earned revenue folio offset uses the same rule');
$so = zr_src('admin/stock-orders.php');
zr_check(strpos($so, "\$shift['settled_today_n']  = (int)(\$soShiftTotals['orders_all_count']") !== false, 'Orders & Tabs Settled Today = Z order count');
zr_check(strpos(zr_src('admin/stock-dashboard.php'), "rh_so_folio_exclude_sql('o')") !== false, 'stock dashboard Net Sales still leaves guest bills out (labelled)');

echo "\n=== z_room_charge: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
