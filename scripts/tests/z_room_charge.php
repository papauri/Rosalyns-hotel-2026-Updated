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

echo "\n--- 6. Same Z definition on the toolbar, stations overview, System Logs, CSV export (2026-11-11 LATER) ---\n";
// 6a. Window totals: till vs room split by status 'completed', which never overlaps the till sale window.
$wSql = rh_pos_z_window_sql();
zr_check(substr_count($wSql, '?') === 8 && strpos($wSql, rh_pos_z_sale_sql('o')) !== false, 'window totals: the Z rule, 8 params', $wSql);
$part = [];
foreach (['placed', 'paid', 'completed', 'refunded', 'voided', 'cancelled'] as $st) {
    foreach ([null, '2026-11-11 09:00:00'] as $pa) {
        $r = ['status' => $st, 'paid_at' => $pa, 'created_at' => '2026-11-11 08:00:00', 'payment_method' => 'cash', 'split_count' => 1];
        $t = rh_pos_z_order_tender($r);
        if ($t !== null && (($st === 'completed') !== ($t === 'room'))) { $part[] = $r; }
    }
}
zr_check($part === [], "window totals: status 'completed' is exactly the room line among Z rows", $part);
// Fold the section-4 fixture the window-SQL way and match the Z totals of rh_pos_shift_totals_assemble.
$fold = ['till_n' => 0, 'till_amt' => 0.0, 'room_n' => 0, 'room_amt' => 0.0];
foreach ($orders as $ord) {
    if (rh_pos_z_order_tender($ord) === null || !$in($ord['paid_at'] ?? $ord['created_at'])) { continue; }
    if ($ord['status'] === 'completed') { $fold['room_n']++; $fold['room_amt'] += $ord['total']; } else { $fold['till_n']++; $fold['till_amt'] += $ord['total']; }
}
$wz = rh_pos_z_window_assemble($fold);
zr_check($wz['orders'] === $with['orders_all_count'] && abs($wz['sales'] - $with['sales_all_total']) < 0.005, 'window totals = the Z orders / sales of the shift maths', [$wz, $with['orders_all_count'], $with['sales_all_total']]);
zr_check($wz['till_count'] === $with['orders_count'] && abs($wz['till_amount'] - $with['sales_total']) < 0.005 && $wz['room_charge_amount'] === 300.0, 'window totals: till line = till-only figures, room line separate', $wz);
zr_check(rh_pos_z_window_assemble([]) === ['till_count' => 0, 'till_amount' => 0.0, 'room_charge_count' => 0, 'room_charge_amount' => 0.0, 'orders' => 0, 'sales' => 0.0], 'window totals: empty row is all zeros');
zr_check(rh_pos_z_window_assemble(['till_n' => '2', 'till_amt' => '10.005', 'room_n' => '1', 'room_amt' => '0.1'])['sales'] === 10.11, 'window totals: DB strings cast and rounded to cents');

// 6b. Stored close + room line (System Logs, CSV): till kept, Z = till + room, drawer untouched.
$close = ['id' => 7, 'orders_count' => 5, 'total_revenue' => 430.0, 'expected_cash' => 290.0, 'declared_cash' => 290.0, 'variance_cash' => 0.0];
$cz = rh_pos_close_add_room($close, ['count' => 1, 'amount' => 300.0]);
zr_check($cz['orders_count'] === 6 && $cz['total_revenue'] === 730.0 && $cz['till_orders_count'] === 5 && $cz['till_revenue'] === 430.0 && $cz['room_charge_count'] === 1 && $cz['room_charge_amount'] === 300.0, 'stored close: Z orders 5 + 1, sales 430 + 300; till kept', $cz);
zr_check($cz['expected_cash'] === 290.0 && $cz['declared_cash'] === 290.0 && $cz['variance_cash'] === 0.0, 'stored close: drawer figures untouched');
zr_check(rh_pos_close_add_room($close, ['count' => 0, 'amount' => 0.0])['orders_count'] === 5 && rh_pos_close_add_room($close, [])['total_revenue'] === 430.0, 'stored close: no room charge = stored figures');

// 6c. Toolbar: Orders / Gross / Net on the Z definition; room chip separate.
$tb = rh_pos_toolbar_z_figures(['orders_today' => 5, 'revenue_today' => 500.0, 'orders_all' => 6, 'revenue_all' => 800.0, 'room_charge_count' => 1, 'room_charge_amount' => 300.0, 'refund_today' => 80.0]);
zr_check($tb === ['orders' => 6, 'gross' => 800.0, 'net' => 720.0, 'room' => 300.0], 'toolbar: orders 6, gross 800, net 800 - 80 refunds, room 300', $tb);
$tb2 = rh_pos_toolbar_z_figures(['orders_today' => 5, 'revenue_today' => 500.0, 'room_charge_count' => 1, 'room_charge_amount' => 300.0]);
zr_check($tb2['orders'] === 6 && $tb2['gross'] === 800.0 && $tb2['net'] === 800.0, 'toolbar: falls back to till + room when the Z keys are absent', $tb2);

// 6d. Station CSV SQL: placeholder count = params, every pair [start, end), Z rule for sales, voids by void time.
$sq = rh_pos_station_report_sql($W0, $W1, 7200);
zr_check(($sq['detail']['params'][0] ?? null) === 7200 && strpos($sq['detail']['sql'], 'SELECT DATE(DATE_ADD(COALESCE(o.paid_at, o.voided_at, o.created_at), INTERVAL ? SECOND)) AS day') !== false, 'station CSV detail: date shifted to hotel time (offset is the first param)');
foreach (['summary', 'detail'] as $k) {
    $ps = $sq[$k]['params'];
    if ($k === 'detail') { array_shift($ps); }
    $sqlK = $k === 'detail' ? substr_replace($sq[$k]['sql'], '', strpos($sq[$k]['sql'], 'INTERVAL ?'), strlen('INTERVAL ?')) : $sq[$k]['sql'];
    $pairsOk = count($ps) % 2 === 0;
    for ($i = 0; $pairsOk && $i < count($ps); $i += 2) { $pairsOk = ($ps[$i] === $W0 && $ps[$i + 1] === $W1); }
    zr_check(substr_count($sqlK, '?') === count($ps) && $pairsOk, "station CSV $k: " . count($ps) . ' placeholders = params, each a [start, end) pair');
    zr_check(strpos($sq[$k]['sql'], rh_pos_room_charge_sql('o')) !== false && strpos($sq[$k]['sql'], "o.status='paid' THEN") === false && strpos($sq[$k]['sql'], 'o.voided_at >= ?') !== false, "station CSV $k: room column, no paid-only sum, voids by void time");
}

// 6e. Wiring (text checks).
$pos = zr_src('admin/pos.php');
zr_check(strpos($pos, "\$tbZ = rh_pos_toolbar_z_figures(\$shift);") !== false && strpos($pos, "'revenue_all' => (float)(\$t['sales_all_total'] ?? \$t['sales_total'])") !== false, 'toolbar first render uses the Z figures');
zr_check(strpos($pos, "setText('tbStatOrders', String(ordersAll));") !== false && strpos($pos, "setText('closeShiftTillCount', String(orders));") !== false, 'toolbar poll: Orders = Z count; close form till count stays till-only');
zr_check(strpos($pos, "setText('tbStatCash', currencySymbol + ' ' + fmtMoneyNoDecimals(cash));") !== false && strpos($pos, "const cash = Number(shift.cash_today || 0);") !== false, 'toolbar Cash / Mobile / Card stay drawer (till) figures');
zr_check(strpos($pos, "rh_pos_z_window_totals(\$pdo, \$restaurantWindow['start_sql'], \$restaurantWindow['trading_end_sql'])") !== false && strpos($pos, "'room_charge_today' => (float)\$todayZ['room_charge_amount']") !== false, 'stations overview: Z orders / sales + room line');
$sl = zr_src('admin/system-logs.php');
zr_check(strpos($sl, '$scRoom = rh_pos_closes_room_charges_batch($pdo, $shiftCloses);') !== false && strpos($sl, 'rh_pos_close_room_charges(') === false, 'System Logs shift closes: room read for the page in one batch (no query per close)');
$rb = zr_src('includes/report-builders.php');
zr_check(strpos($rb, 'rh_pos_station_report_sql($a, $b, $hotelOffset)') !== false && strpos($rb, "'Date (hotel time)'") !== false && strpos($rb, 'rh_pos_z_window_totals($pdo, $a, $b)') !== false && strpos($rb, '$closeRoom = rh_pos_closes_room_charges_batch($pdo, $closeRows);') !== false, 'CSV export: station report + shift closes on the Z definition');

// 6f. End of Day: which day each side uses (rules unchanged, note only).
require_once dirname(__DIR__, 2) . '/admin/includes/eod-revenue.php';
$note = rh_eod_day_rules_text('11 Nov', 'Nov 11 06:00 - Nov 12 06:00', 'Restaurant');
zr_check(strpos($note, 'calendar day (11 Nov, midnight to midnight)') !== false && strpos($note, 'Restaurant sales count on the trading day (Nov 11 06:00 - Nov 12 06:00') !== false, 'EOD note names both day rules', $note);
zr_check(strpos(rh_eod_day_rules_text('11 Nov', '', ''), 'POS sales count on the trading day (opening to the next opening)') !== false, 'EOD note: no window / label falls back to plain words');
$sn = rh_eod_day_rules_text('11 Nov', 'Nov 11 06:00 - Nov 12 06:00', 'POS', true);
zr_check(strlen($sn) < 120 && strpos($sn, 'calendar day (11 Nov)') !== false && strpos($sn, 'trading day (Nov 11 06:00 - Nov 12 06:00)') !== false, 'EOD note: short PDF form names both rules', $sn);
zr_check(strpos(zr_src('admin/end-of-day-report.php'), 'rh_eod_day_rules_note($report_date, $posShort)') !== false
    && strpos(zr_src('includes/eod-pdf-builder.php'), 'rh_pos_short_label() : \'POS\', true) : ') !== false
    && substr_count(zr_src('admin/api/end-of-day-send.php'), 'rh_eod_day_rules_note($date,') === 2, 'EOD page, PDF, e-mail and WhatsApp carry the note');
zr_check(strpos(zr_src('admin/includes/pos-shift-totals.php'), "WHERE voided = 0 AND DATE(posted_at) = ?") !== false, 'folio side still the calendar day (rule unchanged)');

echo "\n--- 7. Daily e-mail: ended trading day, sections independent; System Logs batch (2026-11-11 fixes) ---\n";
// 7a. Last ENDED trading day: windows 06:00 -> next day 06:00 (hotel time).
$tz = new DateTimeZone('Africa/Blantyre');
$winFor = static function (string $d) use ($tz): array { return ['trading_end' => (new DateTimeImmutable($d . ' 06:00:00', $tz))->modify('+1 day')]; };
zr_check(rh_pos_last_ended_trading_date(new DateTimeImmutable('2026-11-12 03:00:00', $tz), $winFor) === '2026-11-10', 'ended day: 03:00 on the 12th -> the 10th (the 11th trades until 06:00)');
zr_check(rh_pos_last_ended_trading_date(new DateTimeImmutable('2026-11-12 06:00:00', $tz), $winFor) === '2026-11-11', 'ended day: at the 06:00 opening the 11th has ended');
zr_check(rh_pos_last_ended_trading_date(new DateTimeImmutable('2026-11-12 23:30:00', $tz), $winFor) === '2026-11-11', 'ended day: late evening -> yesterday, never the half day in progress');
$win24 = static function (string $d) use ($tz): array { return ['trading_end' => new DateTimeImmutable($d . ' 23:59:59', $tz)]; };
zr_check(rh_pos_last_ended_trading_date(new DateTimeImmutable('2026-11-12 23:59:59', $tz), $win24) === '2026-11-12', 'ended day: a day whose trading end has passed counts itself');
// 7b. One failing section never stops the e-mail.
require_once dirname(__DIR__, 2) . '/includes/report-sections.php';
$logged = [];
$logFn = static function (string $n, Throwable $e) use (&$logged): void { $logged[] = $n . ':' . $e->getMessage(); };
$okSec = rh_report_section('bookings', static fn() => ['filename' => 'b.csv', 'csv' => 'x'], $logFn);
$badSec = rh_report_section('station-day', static function () { throw new RuntimeException('SQLSTATE[42000] boom'); }, $logFn);
zr_check($okSec['ok'] === true && $okSec['result']['filename'] === 'b.csv', 'section: a good builder returns its CSV');
zr_check($badSec['ok'] === false && $badSec['result'] === null && $logged === ['station-day:SQLSTATE[42000] boom'], 'section: a failing builder is logged and comes back not ok (no exception)', $logged);
$throwLog = rh_report_section('x', static function () { throw new RuntimeException('a'); }, static function () { throw new RuntimeException('log down'); });
zr_check($throwLog['ok'] === false, 'section: a failing logger does not stop the e-mail either');
zr_check(strpos(rh_report_failed_line('Station report'), 'Station report could not be loaded') === 0 && strpos(rh_report_failed_line('Station report'), 'SQLSTATE') === false, 'section: e-mail line says "could not be loaded", no error detail');
$dr = zr_src('scripts/daily_reports.php') . zr_src('includes/daily-reports-send.php'); // CLI + shared build/send (2026-11-11 timer)
zr_check(substr_count($dr, 'rh_report_section(') === 4 && strpos($dr, "rh_log_event('daily_reports', 'error'") !== false && strpos($dr, "if (\$sec['ok'] && is_array(\$sec['result']))") !== false, 'daily e-mail: four independent sections, failures logged, only good CSVs attached');
zr_check(strpos($dr, "rh_pos_last_ended_trading_date(new DateTimeImmutable('now', rh_site_timezone()))") !== false && strpos($dr, "trading day ' . htmlspecialchars(\$stationDays)") !== false, 'daily e-mail: station report = last ended trading day, named in the e-mail');
// 7c. System Logs batch = the per-close rule, with no query per close.
$biz = static function (string $d): array { return ['start_sql' => $d . ' 06:00:00', 'trading_end_sql' => (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d') . ' 06:00:00']; };
$cl = [
    ['id' => 11, 'user_id' => 5, 'shift_date' => '2026-11-11', 'closed_at' => '2026-11-11 15:00:00'],
    ['id' => 12, 'user_id' => 5, 'shift_date' => '2026-11-11', 'closed_at' => '2026-11-11 23:00:00'],
    ['id' => 13, 'user_id' => 9, 'shift_date' => '2026-11-11', 'closed_at' => '2026-11-13 09:00:00'], // recorded late: capped at trading end
];
$others = array_map(static fn(array $c): array => ['id' => $c['id'], 'user_id' => $c['user_id'], 'closed_at' => $c['closed_at'], 'shift_date' => ''], $cl);
$wins = rh_pos_close_windows_batch($cl, $others, $biz);
zr_check($wins[11]['from'] === '2026-11-10 06:00:00' && $wins[11]['end'] === '2026-11-11 15:00:00', 'batch window: first close looks back to the floor', $wins[11]);
zr_check($wins[12]['from'] === '2026-11-11 15:00:00' && $wins[12]['end'] === '2026-11-11 23:00:00', 'batch window: next close starts where the previous one stopped', $wins[12]);
zr_check($wins[13]['end'] === '2026-11-12 06:00:00' && $wins[13]['from'] === '2026-11-10 06:00:00', 'batch window: a late close is capped at its trading end; other users ignored', $wins[13]);
$rcOrders = [
    ['uid' => 5, 'ts' => '2026-11-11 10:00:00', 'amount' => '100.00'],
    ['uid' => 5, 'ts' => '2026-11-11 15:00:00', 'amount' => '50.00'],  // exactly at close 11's end -> close 12
    ['uid' => 5, 'ts' => '2026-11-11 23:30:00', 'amount' => '70.00'],  // after close 12 -> neither
    ['uid' => 9, 'ts' => '2026-11-11 20:00:00', 'amount' => '30.10'],
];
$byW = rh_pos_room_charges_by_windows($wins, $rcOrders);
zr_check($byW[11] === ['count' => 1, 'amount' => 100.0] && $byW[12] === ['count' => 1, 'amount' => 50.0] && $byW[13] === ['count' => 1, 'amount' => 30.1], 'batch room charges: each order in exactly one close of its own user, [from, end)', $byW);
$pstSrc = zr_src('admin/includes/pos-shift-totals.php');
$bStart = strpos($pstSrc, 'function rh_pos_closes_room_charges_batch');
$bBody = substr($pstSrc, $bStart, strpos($pstSrc, "if (!function_exists('rh_pos_station_report_sql'))") - $bStart);
zr_check(substr_count($bBody, '->prepare(') === 2 && strpos($bBody, '->execute(') !== false && preg_match('/foreach[^\n]*\n[^\n]*->(prepare|execute)\(/', $bBody) === 0, 'batch: exactly two queries, none inside a loop');

// 7d. A failed room read is never shown as 0 (absence rule); all sections failed -> exit 1.
zr_check(strpos($pstSrc, "error_log('rh_pos_closes_room_charges_batch: ' . \$e->getMessage());\n            return null;") !== false || strpos($pstSrc, "error_log('rh_pos_closes_room_charges_batch: ' . \$e->getMessage());\r\n            return null;") !== false, 'batch: a DB error returns null, not an empty (= zero) result');
$sl2 = zr_src('admin/system-logs.php');
zr_check(strpos($sl2, "\$scRow['room_unavailable'] = (\$scRoom === null);") !== false && strpos($sl2, 'Charged to room could not be loaded') !== false, 'System Logs: failed room read says "could not be loaded"');
$rb2 = zr_src('includes/report-builders.php');
zr_check(strpos($rb2, '$roomOk = $closeRoom !== null;') !== false && strpos($rb2, "'Charged to room could not be loaded'") !== false, 'CSV shift closes: failed room read says "could not be loaded"');
$dr2 = zr_src('scripts/daily_reports.php');
zr_check(strpos($dr2, "if (!\$out['any_ok']) {") !== false && strpos(zr_src('includes/daily-reports-send.php'), "'any_ok' => (bool)array_filter(\$sections, static fn(array \$sec): bool => \$sec['ok'])") !== false && strpos($dr2, 'exit(1);') !== false && strpos($dr2, 'Always sends FOUR') === false, 'daily e-mail: exit 1 when every section failed; header comment fixed');

echo "\n=== z_room_charge: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
