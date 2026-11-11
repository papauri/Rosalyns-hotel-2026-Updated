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
so_check(count($c) === 1 && count($p) === 4, 'status=sold adds no extra status clause inside the sale window', $c);
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

echo "\n--- 5. Unknown values are dropped, never shown as a filter ---\n";
$f = rh_so_normalize_filters(['date' => '2026-11-01', 'status' => 'bogus', 'payment_method' => "cash' OR 1", 'hour' => '24', 'kitchen_status' => 'x', 'health' => 'y']);
so_check(implode('', $f) === '', 'all invalid -> no filter', $f);
$f = rh_so_normalize_filters(['status' => 'refunded']);
so_check($f['status'] === 'refunded', 'refunded is an accepted status');

echo "\nPASS $P  FAIL $F\n";
exit($F > 0 ? 1 : 0);
