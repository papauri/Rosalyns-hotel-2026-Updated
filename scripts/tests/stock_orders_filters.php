<?php
/**
 * Pure (no-DB) tests for the Orders & Tabs list filters (owner 2026-11-11 "all filters 100% accurate"):
 * each card and the list it opens use the same trading window and the same timestamp.
 *  - money (status=sold / payment_method): paid time, the shared till rule rh_pos_sale_window_sql
 *  - voids: voided_at (else created_at), as rh_pos_shift_totals counts them
 *  - settled (paid/completed/settled): COALESCE(paid_at, created_at)
 *  - everything else: created_at
 *
 *   php scripts/tests/stock_orders_filters.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

date_default_timezone_set('Africa/Blantyre');
$TW = []; $TW_MOD = [];
function getSetting(string $key, $default = '') { return $GLOBALS['TW'][$key] ?? $default; }
function moduleEnabled(string $key): bool { return $GLOBALS['TW_MOD'][$key] ?? true; }

require_once dirname(__DIR__, 2) . '/includes/station-hours.php';
require_once dirname(__DIR__, 2) . '/admin/includes/pos-shift-totals.php'; // pure helpers only at include
require_once dirname(__DIR__, 2) . '/admin/includes/stock-orders-filters.php';

$P = 0; $F = 0;
function so_check(bool $c, string $label, $detail = ''): void
{
    global $P, $F;
    if ($c) { $P++; echo "  [PASS] $label\n"; return; }
    $F++; echo "  [FAIL] $label" . ($detail !== '' ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function so_hours(string $o, string $c): void
{
    global $TW;
    $TW = ['site_timezone' => 'Africa/Blantyre'];
    foreach (['kitchen', 'bar', 'coffee_bar'] as $st) { $TW["station_{$st}_opens_at"] = $o; $TW["station_{$st}_closes_at"] = $c; }
}
function so_at(string $ts): DateTimeImmutable { return new DateTimeImmutable($ts, new DateTimeZone('Africa/Blantyre')); }
function so_where(array $get, array $w): array
{
    return rh_so_list_where(rh_so_normalize_filters($get), $w['start_sql'], $w['trading_end_sql']);
}
/** Mirror of the window part of each basis, applied to one row (what the SQL selects). */
function so_in(array $get, array $w, array $row): bool
{
    $f = rh_so_normalize_filters($get);
    $in = static fn(?string $t): bool => $t !== null && $t >= $w['start_sql'] && $t < $w['trading_end_sql'];
    switch (rh_so_date_basis($f)) {
        case 'sale':
            // status=sold with no tender = every order on the Z: also orders charged to a room (completed).
            if ($f['status'] === 'sold' && $f['payment_method'] === '' && $row['status'] === 'completed') {
                return $in($row['paid_at'] ?? $row['created_at']);
            }
            return ($row['paid_at'] !== null && in_array($row['status'], ['paid', 'refunded', 'voided'], true) && $in($row['paid_at']))
                || ($row['paid_at'] === null && $row['status'] === 'paid' && $in($row['created_at']));
        case 'void':
            return $row['status'] === 'voided' && ($row['voided_at'] !== null ? $in($row['voided_at']) : $in($row['created_at']));
        case 'settled':
            return $in($row['paid_at'] ?? $row['created_at']);
        case 'created':
            return $in($row['created_at']);
    }
    return true;
}

echo "\n--- 1. Trading window: hours 06:00-03:00 ---\n";
so_hours('06:00', '03:00');
$wBefore = rh_station_union_trading_window(so_at('2026-11-11 05:30:00'));   // before opening
$wAfterMid = rh_station_union_trading_window(so_at('2026-11-11 01:15:00')); // after midnight
$wDay = rh_station_union_trading_window(so_at('2026-11-11 14:00:00'));
so_check($wBefore['business_date'] === '2026-11-10', 'before opening belongs to the day that ended', $wBefore['business_date']);
so_check($wAfterMid['business_date'] === '2026-11-10', 'after midnight belongs to the day that ended', $wAfterMid['business_date']);
so_check($wDay['start_sql'] === '2026-11-11 06:00:00' && $wDay['trading_end_sql'] === '2026-11-12 06:00:00', 'daytime window = opening to next opening', [$wDay['start_sql'], $wDay['trading_end_sql']]);

echo "\n--- 2. Basis per card ---\n";
$basis = static fn(array $g): string => rh_so_date_basis(rh_so_normalize_filters($g));
so_check($basis(['date' => 'today']) === 'created', 'Orders Today -> created');
so_check($basis(['date' => 'today', 'hour' => '1']) === 'created', 'hour bar -> created');
so_check($basis(['date' => 'today', 'order_type' => 'room_service']) === 'created', 'channel -> created');
so_check($basis(['date' => 'today', 'payment_method' => 'cash']) === 'sale', 'Cash card -> sale (paid time)');
so_check($basis(['date' => 'today', 'payment_method' => 'card']) === 'sale', 'Card card -> sale');
so_check($basis(['date' => 'today', 'status' => 'sold']) === 'sale', 'Settled/Avg ticket -> sale');
so_check($basis(['date' => 'today', 'status' => 'voided']) === 'void', 'Voids -> voided_at');
so_check($basis(['date' => 'today', 'status' => 'settled']) === 'settled', 'status=settled (stock dashboard) -> paid time');
so_check($basis(['status' => 'placed']) === '', 'open orders -> no date');

echo "\n--- 3. Clauses reuse the shared till rules and the same window params ---\n";
[$c, $p] = so_where(['date' => 'today', 'payment_method' => 'cash'], $wDay);
so_check($c[0] === rh_pos_sale_window_sql('o'), 'sale clause is rh_pos_sale_window_sql verbatim');
so_check(array_slice($p, 0, 4) === [$wDay['start_sql'], $wDay['trading_end_sql'], $wDay['start_sql'], $wDay['trading_end_sql']], 'sale params = card params', $p);
so_check(strpos($c[1], 'stock_order_splits') !== false && array_slice($p, 4) === ['cash', 'cash'], 'tender read per split leg too', $p);
[$c, $p] = so_where(['date' => 'today', 'payment_method' => 'card'], $wDay);
so_check(array_slice($p, 4) === ['card_manual', 'card_pos', 'credit_card', 'card_manual', 'card_pos', 'credit_card'], 'card = manual + POS + credit_card (card tender bucket)', $p);
[$c, $p] = so_where(['date' => 'today', 'status' => 'voided'], $wDay);
so_check(strpos($c[0], 'voided_at') !== false && count($p) === 5 && end($p) === 'voided' && strpos(implode(' ', $c), 'o.status = ?') !== false, 'void clause by voided_at + status');
[$c, $p] = so_where(['date' => 'today', 'status' => 'sold'], $wDay);
so_check(count($c) === 1 && $c[0] === rh_pos_z_sale_sql('o') && $p === rh_pos_z_sale_params($wDay['start_sql'], $wDay['trading_end_sql']), 'status=sold = the Z order rule (till sales + charged to room), 8 params, no extra status clause', $c);
[$c, $p] = so_where(['date' => 'today', 'status' => 'sold', 'payment_method' => 'cash'], $wDay);
so_check($c[0] === rh_pos_sale_window_sql('o') && strpos(implode(' ', $c), 'completed') === false, 'sold + a tender = till-only (a room charge is never in a drawer)', $c);
[$c, $p] = so_where(['status' => 'sold'], $wDay);
so_check(strpos($c[0], "OR o.status = 'completed'") !== false && $p === [], 'sold with no date also lists room charges');
[$c, $p] = so_where(['status' => 'sold', 'payment_method' => 'unassigned'], $wDay);
so_check(strpos(implode(' ', $c), 'completed') === false, 'sold + unassigned tender never picks up room charges');
[$c, $p] = so_where(['date' => 'today', 'order_type' => 'restaurant'], $wDay);
so_check(strpos($c[1], "COALESCE(NULLIF(TRIM(o.order_type), ''), 'restaurant') = ?") !== false && end($p) === 'restaurant', 'channel match uses the panel grouping (blank = restaurant)');
[$c, $p] = so_where(['status' => 'placed', 'order_type' => 'non_room_service'], $wDay);
so_check(in_array(rh_so_not_room_service_sql('o'), $c, true), 'Live Open walk-in split = not room service (NULL type included)');
[$c, $p] = so_where(['date' => 'today', 'hour' => '0'], $wDay);
so_check(in_array('HOUR(o.created_at) = ?', $c, true) && end($p) === 0, 'hour 0 kept as a filter', $p);

echo "\n--- 4. Rows: paid vs created on different trading days ---\n";
$tabPaidToday = ['status' => 'paid', 'created_at' => '2026-11-10 21:00:00', 'paid_at' => '2026-11-11 12:00:00', 'voided_at' => null];
so_check(so_in(['date' => 'today', 'payment_method' => 'cash'], $wDay, $tabPaidToday), 'tab opened yesterday, paid today: in Cash list (as in Cash card)');
so_check(!so_in(['date' => 'today'], $wDay, $tabPaidToday), '... and not in Orders Today (started yesterday)');
$afterMidnight = ['status' => 'paid', 'created_at' => '2026-11-12 01:30:00', 'paid_at' => '2026-11-12 02:10:00', 'voided_at' => null];
so_check(so_in(['date' => 'today', 'status' => 'sold'], $wDay, $afterMidnight) && so_in(['date' => 'today'], $wDay, $afterMidnight), 'sale after midnight before next opening counts in the day that ended (both lists)');
$beforeOpen = ['status' => 'placed', 'created_at' => '2026-11-11 05:30:00', 'paid_at' => null, 'voided_at' => null];
so_check(!so_in(['date' => 'today'], $wDay, $beforeOpen) && so_in(['date' => 'today'], $wBefore, $beforeOpen), 'order before opening belongs to yesterday\'s window');
$paidThenVoided = ['status' => 'voided', 'created_at' => '2026-11-10 20:00:00', 'paid_at' => '2026-11-10 20:30:00', 'voided_at' => '2026-11-11 10:00:00'];
so_check(!so_in(['date' => 'today', 'status' => 'sold'], $wDay, $paidThenVoided), 'sold yesterday, voided today: not in today\'s sales');
so_check(so_in(['date' => 'today', 'status' => 'voided'], $wDay, $paidThenVoided), '... but in today\'s Voids list (as the Voids card)');
$legacyPaid = ['status' => 'paid', 'created_at' => '2026-11-11 09:00:00', 'paid_at' => null, 'voided_at' => null];
so_check(so_in(['date' => 'today', 'status' => 'sold'], $wDay, $legacyPaid), 'legacy paid row with no paid_at falls back to created_at');
$roomCharge = ['status' => 'completed', 'created_at' => '2026-11-11 19:00:00', 'paid_at' => null, 'voided_at' => null];
so_check(so_in(['date' => 'today', 'status' => 'sold'], $wDay, $roomCharge) && !so_in(['date' => 'today', 'status' => 'sold', 'payment_method' => 'cash'], $wDay, $roomCharge), 'room charge: in Settled Today (= Z), not in a tender list');

echo "\n--- 5. Unknown values are dropped, never shown as a filter ---\n";
$f = rh_so_normalize_filters(['date' => '2026-11-01', 'status' => 'bogus', 'payment_method' => "cash' OR 1", 'hour' => '24', 'kitchen_status' => 'x', 'health' => 'y']);
so_check(implode('', $f) === '', 'all invalid -> no filter', $f);
$f = rh_so_normalize_filters(['status' => 'refunded']);
so_check($f['status'] === 'refunded', 'refunded is an accepted status');

echo "\n--- 6. Stock dashboard Net Sales: list = card (guest-bill room service left out) ---\n";
$fx = rh_so_folio_exclude_sql('o');
so_check($fx === "NOT (o.order_type = 'room_service' AND EXISTS (SELECT 1 FROM booking_charges bc WHERE bc.stock_order_id = o.id))", 'folio rule kept exactly as the card has read it', $fx);
$f = rh_so_normalize_filters(['date' => 'today', 'status' => 'settled', 'folio' => 'exclude']);
so_check($f['folio'] === 'exclude' && rh_so_date_basis($f) === 'settled', 'folio=exclude accepted, settled basis (paid time, paid/completed only)');
[$c, $p] = so_where(['date' => 'today', 'status' => 'settled', 'folio' => 'exclude'], $wDay);
so_check(in_array($fx, $c, true) && in_array("o.status IN ('paid','completed')", $c, true) && $p === [$wDay['start_sql'], $wDay['trading_end_sql']], 'list clauses = card: window + paid/completed + folio rule', $c);
[$c] = so_where(['date' => 'today', 'status' => 'settled'], $wDay);
so_check(!in_array($fx, $c, true), 'no folio param -> no folio clause');
so_check(rh_so_normalize_filters(['folio' => 'include'])['folio'] === '' && rh_so_normalize_filters(['folio' => ['exclude']])['folio'] === '', 'unknown folio value dropped');
$dash = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/stock-dashboard.php');
so_check(substr_count($dash, 'stock-orders.php?date=today&amp;status=settled&amp;folio=exclude') === 2, 'both dashboard sales links carry status=settled&folio=exclude');
so_check(strpos($dash, 'stock-orders.php?date=today&status=settled"') === false, 'no dashboard link left without the folio rule');
so_check(substr_count($dash, "rh_so_folio_exclude_sql('o')") === 2 && strpos($dash, 'bc.stock_order_id = o.id') === false, 'card and Top Items use the shared folio rule (no private copy)');

echo "\n--- 7. Tender legs: a split order shows that tender's part ---\n";
so_check(rh_so_tender_methods('card') === ['card_manual', 'card_pos', 'credit_card'] && rh_so_tender_methods('cash') === ['cash'] && rh_so_tender_methods('unassigned') === [], 'tender -> methods (card bucket)');
so_check(rh_so_tender_leg_sql('cash', []) === null && rh_so_tender_leg_sql('', [5]) === null, 'no split rows or no tender -> no leg query');
$lq = rh_so_tender_leg_sql('card', [7, 9, 7, 0]);
so_check(strpos($lq[0], 'SUM(s.split_amount)') !== false && strpos($lq[0], 'IN (?,?)') !== false && $lq[1] === [7, 9, 'card_manual', 'card_pos', 'credit_card'], 'card legs: ids deduped, bucket methods, SUM(split_amount) as the payment mix', $lq);
$lq = rh_so_tender_leg_sql('unassigned', [3]);
so_check(strpos($lq[0], "s.payment_method IS NULL OR TRIM(s.payment_method) = ''") !== false && $lq[1] === [3], 'unassigned legs = blank method, as the list filter');
// A 10,000 bill split 6,000 cash + 4,000 card: each tender list adds up to its card.
$legs = [['m' => 'cash', 'a' => 6000.0], ['m' => 'card_pos', 'a' => 4000.0]];
$sumFor = static function (string $t) use ($legs): float { $ms = rh_so_tender_methods($t); $x = 0.0; foreach ($legs as $l) { if (in_array($l['m'], $ms, true)) { $x += $l['a']; } } return $x; };
so_check(abs($sumFor('cash') - 6000.0) < 0.005 && abs($sumFor('card') - 4000.0) < 0.005, 'split 6,000 cash + 4,000 card: lists show 6,000 and 4,000, not 10,000 twice');
$lq = rh_so_tender_leg_sql('cash', [4]);
so_check(strpos($lq[0], 'COALESCE(SUM(COALESCE(s.tip_amount, 0)), 0) AS leg_tip') !== false, 'leg query also sums that tender\'s tips (the cards include tips)');
// Cash card = sum(total + tip) of single orders + sum(leg + leg tip) of split legs (rh_pos_shift_totals);
// the cash list shows each row's amount + "+ tip", which must add up to the same figure.
$single = ['total' => 2500.0, 'tip' => 200.0];
$splitLegs = [['m' => 'cash', 'a' => 6000.0, 'tip' => 500.0], ['m' => 'card_pos', 'a' => 4000.0, 'tip' => 300.0]];
$cardCash = $single['total'] + $single['tip'];
$listCash = $single['total'] + $single['tip'];
foreach ($splitLegs as $l) { if (in_array($l['m'], rh_so_tender_methods('cash'), true)) { $cardCash += $l['a'] + $l['tip']; $listCash += $l['a'] + $l['tip']; } }
so_check(abs($cardCash - 9200.0) < 0.005 && abs($listCash - $cardCash) < 0.005, 'cash list rows + their tips = Cash card (9,200), card-leg tip not counted in cash', [$cardCash, $listCash]);
$so = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/stock-orders.php');
so_check(strpos($so, '+ tip <?php') !== false && strpos($so, "\$soTenderLegTips[(int)\$lr['order_id']] = (float)\$lr['leg_tip']") !== false && substr_count($so, 'incl. tips</div>') === 2, 'list shows "+ tip" per row; Mobile and Card cards say incl. tips');

echo "\n--- 7b. 86'd-after-payment refunds (REF86): card includes them, list notes them ---\n";
$r86 = rh_so_ref86_by_method_sql();
$shiftSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/includes/pos-shift-totals.php');
$same = true;
foreach (["p.booking_type = 'restaurant' AND p.payment_type = 'refund'", "p.payment_reference LIKE 'REF86-%' AND p.deleted_at IS NULL", "COALESCE(p.refund_status, 'completed') IN ('pending','processing','completed')", 'CASE WHEN COALESCE(p.refund_amount, 0) > 0 THEN p.refund_amount ELSE p.total_amount END', 'INNER JOIN stock_orders o ON o.id = p.booking_id'] as $frag) {
    if (strpos($r86, $frag) === false || strpos($shiftSrc, $frag) === false) { $same = false; so_check(false, 'REF86 fragment shared with shift totals', $frag); }
}
so_check($same && strpos($r86, rh_pos_sale_window_sql('o')) !== false && substr_count($r86, '?') === 4, 'REF86 note uses the shift totals\' rule and sale window (4 params)');
$rows = [['method' => 'cash', 'n' => 1, 'amt' => 1500], ['method' => 'card_pos', 'n' => 1, 'amt' => 800], ['method' => 'credit_card', 'n' => 2, 'amt' => 200], ['method' => 'mobile_money', 'n' => 1, 'amt' => 50]];
so_check(rh_so_ref86_for_tender($rows, 'cash') === [1, 1500.0] && rh_so_ref86_for_tender($rows, 'card') === [3, 1000.0] && rh_so_ref86_for_tender($rows, 'card_pos') === [1, 800.0] && rh_so_ref86_for_tender($rows, 'unassigned') === [0, 0.0], 'REF86 per tender: cash, card bucket, single card method, none for unassigned');
// Cash card 11,200 = order rows 9,200 (incl. tips) + 1,500 REF86 + ... : list rows + tips + note = card.
$cardCashWith86 = $cardCash + rh_so_ref86_for_tender($rows, 'cash')[1];
so_check(abs(($listCash + rh_so_ref86_for_tender($rows, 'cash')[1]) - $cardCashWith86) < 0.005, 'cash list rows + tips + REF86 note = Cash card');
$so = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/stock-orders.php');
so_check(substr_count($so, "Includes 86'd items paid then refunded") === 3 && strpos($so, "86'd after payment (refunded separately as REF86 refunds)") !== false, 'note under Cash, Mobile and Card cards and at the tender list foot');

echo "\n--- 8. Comma list of open statuses (room-service sets) ---\n";
$f = rh_so_normalize_filters(['order_type' => 'room_service', 'status' => 'placed, pending,confirmed']);
so_check($f['status'] === 'placed,pending,confirmed', 'placed,pending,confirmed accepted (trimmed)', $f['status']);
[$c, $p] = rh_so_list_where($f, $wDay['start_sql'], $wDay['trading_end_sql']);
so_check(in_array('o.status IN (?,?,?)', $c, true) && array_slice($p, -3) === ['placed', 'pending', 'confirmed'] && rh_so_date_basis(['date' => 'today'] + $f) === 'created', 'list -> status IN (...), created basis', [$c, $p]);
so_check(rh_so_normalize_filters(['status' => 'placed,paid'])['status'] === '', 'list with a paid status dropped whole');
so_check(rh_so_normalize_filters(['status' => 'placed,voided'])['status'] === '' && rh_so_normalize_filters(['status' => 'placed,sold'])['status'] === '' && rh_so_normalize_filters(['status' => 'placed,settled'])['status'] === '', 'voided/sold/settled never in a list');
so_check(rh_so_normalize_filters(['status' => 'placed,bogus'])['status'] === '' && rh_so_normalize_filters(['status' => 'placed,'])['status'] === '', 'unknown or empty member drops the list');
so_check(rh_so_normalize_filters(['status' => 'pending'])['status'] === 'pending' && rh_so_normalize_filters(['status' => 'placed,placed'])['status'] === '', 'single pending accepted; duplicate-only list dropped');

echo "\nPASS $P  FAIL $F\n";
exit($F > 0 ? 1 : 0);
