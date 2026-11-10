<?php
/**
 * Pure (no-DB) tests for the gapless trading day (owner 2026-11-10 "count with the day that ended"):
 * a trading day runs from its first station opening until the next day's first opening, so a till
 * sale between closing time and the next opening (e.g. 03:09 with hours 06:00-03:00) belongs to the
 * night that just ended. Open-hours fields ('end', 'closes_at', 'is_open_now') keep their meaning.
 *
 *   php scripts/tests/trading_window_gapless.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

date_default_timezone_set('Africa/Blantyre'); // app + MySQL session run on hotel time (config/database.php)

$TW = [];      // settings stub
$TW_MOD = [];  // module stub (missing = on)
function getSetting(string $key, $default = '') { return $GLOBALS['TW'][$key] ?? $default; }
function moduleEnabled(string $key): bool { return $GLOBALS['TW_MOD'][$key] ?? true; }

require_once dirname(__DIR__, 2) . '/includes/station-hours.php';
require_once dirname(__DIR__, 2) . '/admin/includes/pos-shift-totals.php'; // pure helpers only (no DB at include)

$P = 0; $F = 0;
function tw_check(bool $c, string $label, $detail = ''): void
{
    global $P, $F;
    if ($c) { $P++; echo "  [PASS] $label\n"; return; }
    $F++; echo "  [FAIL] $label" . ($detail !== '' ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function tw_hours(array $h, array $mod = []): void
{
    global $TW, $TW_MOD;
    $TW = ['site_timezone' => 'Africa/Blantyre'];
    foreach ($h as $st => [$o, $c]) { $TW["station_{$st}_opens_at"] = $o; $TW["station_{$st}_closes_at"] = $c; }
    $TW_MOD = $mod;
}
function tw_at(string $ts): DateTimeImmutable { return new DateTimeImmutable($ts, new DateTimeZone('Africa/Blantyre')); }
function tw_fmt(DateTimeImmutable $d): string { return $d->format('Y-m-d H:i:s'); }
/** Every 7 minutes over 3 days: exactly one trading day holds t, and it is the one the selector picks. */
function tw_partition(string $label): void
{
    $t = tw_at('2026-11-09 00:00:00');
    $stop = tw_at('2026-11-12 00:00:00');
    $bad = [];
    while ($t < $stop) {
        $hits = [];
        foreach (['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
            $w = rh_station_union_window_for_date($d);
            if ($t >= $w['start'] && $t < $w['trading_end']) { $hits[] = $d; }
        }
        $sel = rh_station_union_trading_window($t)['business_date'];
        if (count($hits) !== 1 || $hits[0] !== $sel) { $bad[] = tw_fmt($t) . ' in ' . json_encode($hits) . ' sel ' . $sel; }
        $t = $t->modify('+7 minutes');
    }
    tw_check($bad === [], "$label: every minute sits in exactly one trading day, the one selected", array_slice($bad, 0, 3));
}

echo "\n--- 1. Normal day (all stations 06:00-22:00) ---\n";
tw_hours(['kitchen' => ['06:00', '22:00'], 'bar' => ['06:00', '22:00'], 'coffee_bar' => ['06:00', '22:00']]);
$w = rh_station_union_trading_window(tw_at('2026-11-10 12:00:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['start_sql'] === '2026-11-10 06:00:00' && $w['end_sql'] === '2026-11-10 22:00:00' && $w['trading_end_sql'] === '2026-11-11 06:00:00', 'Noon: today, 06:00 -> trading end next 06:00, end stays 22:00', $w['start_sql'] . ' / ' . $w['end_sql'] . ' / ' . $w['trading_end_sql']);
tw_check($w['is_open_now'] === true, 'Noon: is_open_now true');
$w = rh_station_union_trading_window(tw_at('2026-11-10 23:30:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['is_open_now'] === false, '23:30 (after closing): still today, stations shown closed');
tw_check(rh_station_trading_business_date(tw_at('2026-11-10 05:59:59')) === '2026-11-09', '05:59:59 belongs to yesterday');
tw_check(rh_station_trading_business_date(tw_at('2026-11-10 06:00:00')) === '2026-11-10', '06:00:00 starts today (half-open boundary)');
tw_partition('Normal day');

echo "\n--- 2. Cross-midnight (kitchen 06:00-23:00, bar 11:00-03:00, coffee 06:00-22:00) ---\n";
tw_hours(['kitchen' => ['06:00', '23:00'], 'bar' => ['11:00', '03:00'], 'coffee_bar' => ['06:00', '22:00']]);
$w = rh_station_union_trading_window(tw_at('2026-11-11 02:30:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['is_open_now'] === true && $w['end_sql'] === '2026-11-11 03:00:00' && $w['trading_end_sql'] === '2026-11-11 06:00:00', '02:30 (bar open): last night, open, end 03:00, trading end 06:00', $w);
$old = rh_station_union_business_window(tw_at('2026-11-11 02:30:00'));
tw_check($old['business_date'] === '2026-11-10' && $old['is_open_now'] === true, 'Open-hours window agrees while the bar is open');
tw_partition('Cross-midnight');

echo "\n--- 3. Gap sale at 03:09 (06:00-03:00 hours) ---\n";
tw_hours(['kitchen' => ['06:00', '03:00'], 'bar' => ['06:00', '03:00'], 'coffee_bar' => ['06:00', '03:00']]);
$now = tw_at('2026-11-11 03:09:00');
$w = rh_station_union_trading_window($now);
$sale = '2026-11-11 03:09:00';
tw_check($w['business_date'] === '2026-11-10', '03:09 counts with the day that ended (2026-11-10)', $w['business_date']);
tw_check($w['start_sql'] <= $sale && $sale < $w['trading_end_sql'], '03:09 sale inside [start, trading_end)', $w['start_sql'] . ' - ' . $w['trading_end_sql']);
tw_check(!($w['start_sql'] <= $sale && $sale < $w['end_sql']), 'The same sale is outside [start, end): the old window lost it');
tw_check($w['is_open_now'] === false && $w['closes_at'] === '03:00' && $w['end_sql'] === '2026-11-11 03:00:00', 'Open-hours meaning kept: closed, closes_at 03:00, end 03:00');
$old = rh_station_union_business_window($now);
tw_check($old['business_date'] === '2026-11-11' && $old['is_open_now'] === false && !($old['start_sql'] <= $sale && $sale < $old['end_sql']), 'KDS selector unchanged (today, closed) and holds no gap sale: the fault this fixes');
$close = '2026-11-11 03:30:00';
tw_check($close < $w['trading_end_sql'] && $w['trading_end_sql'] === '2026-11-11 06:00:00', "Cashier's 03:30 close is inside the trading day; next day starts at its opening 06:00");
tw_check(rh_station_trading_business_date(tw_at('2026-11-11 06:00:00')) === '2026-11-11', 'At 06:00 the new trading day begins');
$next = rh_station_union_window_for_date('2026-11-11');
tw_check($next['start_sql'] === $w['trading_end_sql'], 'Consecutive trading days touch: no gap, no overlap');
tw_partition('06:00-03:00');

echo "\n--- 4. 24h stations ---\n";
tw_hours(['kitchen' => ['00:00', '00:00'], 'bar' => ['00:00', '00:00'], 'coffee_bar' => ['00:00', '00:00']]);
$w = rh_station_union_trading_window(tw_at('2026-11-10 23:59:59'));
tw_check($w['business_date'] === '2026-11-10' && $w['end_sql'] === '2026-11-11 00:00:00' && $w['trading_end_sql'] === $w['end_sql'] && $w['is_open_now'] === true, '00:00-00:00: open all day, trading end = end = midnight', $w);
tw_check(rh_station_trading_business_date(tw_at('2026-11-11 00:00:00')) === '2026-11-11', 'Midnight starts the next day');
tw_partition('24h 00:00');
tw_hours(['kitchen' => ['07:00', '07:00'], 'bar' => ['07:00', '07:00'], 'coffee_bar' => ['07:00', '07:00']]);
$w = rh_station_union_trading_window(tw_at('2026-11-11 06:59:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['is_open_now'] === true && $w['trading_end_sql'] === '2026-11-11 07:00:00', '24h from 07:00: 06:59 is still the previous day, open', $w);
tw_partition('24h 07:00');

echo "\n--- 5. Stations switched off (only the bar runs, 11:00-03:00) ---\n";
tw_hours(['kitchen' => ['06:00', '23:00'], 'bar' => ['11:00', '03:00'], 'coffee_bar' => ['06:00', '22:00']], ['station_kds' => false, 'station_cds' => false]);
$w = rh_station_union_trading_window(tw_at('2026-11-11 05:00:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['start_sql'] === '2026-11-10 11:00:00' && $w['trading_end_sql'] === '2026-11-11 11:00:00' && $w['is_open_now'] === false, '05:00 with only the bar on: last night, trading day 11:00 -> 11:00', $w);
tw_check(rh_station_trading_business_date(tw_at('2026-11-11 10:59:00')) === '2026-11-10', '10:59 (bar not yet open) still last night');
tw_partition('Bar only');
tw_hours(['kitchen' => ['06:00', '23:00'], 'bar' => ['11:00', '03:00'], 'coffee_bar' => ['06:00', '22:00']], ['pos' => false]);
$w = rh_station_union_trading_window(tw_at('2026-11-11 05:00:00'));
tw_check($w['business_date'] === '2026-11-10' && $w['start_sql'] === '2026-11-10 06:00:00' && $w['trading_end_sql'] === '2026-11-11 06:00:00', 'Every station off: falls back to all station hours, still a full trading day', $w);

echo "\n--- 6. A station closing after the next opening (kitchen 05:00-22:00, bar 11:00-07:00) ---\n";
tw_hours(['kitchen' => ['05:00', '22:00'], 'bar' => ['11:00', '07:00'], 'coffee_bar' => ['05:00', '22:00']]);
$w = rh_station_union_window_for_date('2026-11-10');
tw_check($w['end_sql'] === '2026-11-11 07:00:00' && $w['trading_end_sql'] === '2026-11-11 05:00:00', 'Open-hours end 07:00 (+1); money day ends at the next opening 05:00 (no double count)', $w);
tw_partition('Overlapping hours');

echo "\n--- 7. Per-station windows ---\n";
tw_hours(['kitchen' => ['06:00', '23:00'], 'bar' => ['11:00', '03:00'], 'coffee_bar' => ['06:00', '22:00']]);
$ok = true;
foreach (['kitchen', 'bar', 'coffee_bar'] as $st) {
    $sw = rh_station_window_for_date($st, '2026-11-10');
    $nx = rh_station_window_for_date($st, '2026-11-11');
    $ok = $ok && $sw['trading_end_sql'] === $nx['start_sql'] && $sw['trading_end'] >= $sw['end'];
}
tw_check($ok, 'Each station: trading end = its next opening, never before its closing');
$bw = rh_station_business_window('bar', tw_at('2026-11-11 03:09:00'));
tw_check($bw['business_date'] === '2026-11-11' && $bw['is_open_now'] === false, 'Per-station open-hours selector unchanged (bar closed at 03:09)');

echo "\n--- 8. Africa/Blantyre has no DST ---\n";
$tz = new DateTimeZone('Africa/Blantyre');
tw_check((new DateTimeImmutable('2026-01-15 12:00', $tz))->getOffset() === 7200 && (new DateTimeImmutable('2026-07-15 12:00', $tz))->getOffset() === 7200, 'UTC+2 in January and July');
$okLen = true;
foreach (['2026-03-29', '2026-10-25', '2026-12-31', '2027-01-01'] as $d) {
    $u = rh_station_union_window_for_date($d);
    $okLen = $okLen && ($u['trading_end']->getTimestamp() - $u['start']->getTimestamp()) === 86400;
}
tw_check($okLen, 'Every trading day is exactly 24h (incl. EU DST dates and the year change)');

echo "\n--- 9. Every till sale lands in exactly one cashier close (Liwonde hours) ---\n";
tw_hours(['kitchen' => ['06:00', '23:00'], 'bar' => ['11:00', '03:00'], 'coffee_bar' => ['06:00', '22:00']]);
/** Window of a close at $closedAt for one cashier, exactly as rh_pos_shift_window_start picks it ($closes = that user's earlier closes). */
function tw_close_window(array $closes, string $closedAt): array
{
    $tw = rh_station_union_trading_window(tw_at($closedAt));
    $floor = rh_pos_shift_window_floor($tw['start_sql']);
    $last = null;
    foreach ($closes as $c) { if ($c >= $floor && $c < $closedAt && ($last === null || $c > $last)) { $last = $c; } }
    return [rh_pos_shift_window_from($last, $floor), $closedAt];
}
/** sale => list of closes whose window [from, closedAt) holds it. */
function tw_assign(array $closes, array $sales): array
{
    sort($closes);
    $out = array_fill_keys($sales, []);
    foreach ($closes as $i => $c) {
        [$from, $to] = tw_close_window(array_slice($closes, 0, $i), $c);
        foreach ($sales as $s) { if ($s >= $from && $s < $to) { $out[$s][] = $c; } }
    }
    return $out;
}
tw_check(rh_pos_shift_window_floor('2026-11-11 06:00:00') === '2026-11-10 06:00:00', 'Look-back floor = previous trading day opening');
tw_check(rh_pos_shift_window_from(null, '2026-11-10 06:00:00') === '2026-11-10 06:00:00' && rh_pos_shift_window_from('2026-11-11 03:30:00', '2026-11-10 06:00:00') === '2026-11-11 03:30:00' && rh_pos_shift_window_from('2026-11-09 15:00:00', '2026-11-10 06:00:00') === '2026-11-10 06:00:00', 'Window starts at the last close, never before the floor');
$a = tw_assign(['2026-11-11 03:30:00', '2026-11-11 06:30:00', '2026-11-11 15:00:00'], ['2026-11-10 12:00:00', '2026-11-11 02:00:00', '2026-11-11 05:00:00', '2026-11-11 07:00:00']);
tw_check($a['2026-11-11 05:00:00'] === ['2026-11-11 06:30:00'], 'Close 03:30, sale 05:00, close 06:30: the sale is in the 06:30 close only', $a);
$once = true; foreach ($a as $hits) { $once = $once && count($hits) === 1; }
tw_check($once && $a['2026-11-10 12:00:00'] === ['2026-11-11 03:30:00'] && $a['2026-11-11 02:00:00'] === ['2026-11-11 03:30:00'] && $a['2026-11-11 07:00:00'] === ['2026-11-11 15:00:00'], 'Day, after-midnight and morning sales: each in exactly one close', $a);
$b = tw_assign(['2026-11-09 15:00:00', '2026-11-11 06:30:00'], ['2026-11-10 22:00:00', '2026-11-11 02:00:00', '2026-11-11 05:00:00']);
$onceB = true; foreach ($b as $hits) { $onceB = $onceB && $hits === ['2026-11-11 06:30:00']; }
tw_check($onceB, 'Night shift that never closed, closing at 06:30 after opening: the whole night counted once', $b);
$c = tw_assign(['2026-11-11 03:30:00', '2026-11-11 03:45:00'], ['2026-11-11 03:40:00']);
tw_check($c['2026-11-11 03:40:00'] === ['2026-11-11 03:45:00'], 'Two closes the same night: a sale between them only in the second', $c);
$d = tw_assign(['2026-11-11 06:30:00'], ['2026-11-09 20:00:00']);
tw_check($d['2026-11-09 20:00:00'] === [], 'Look-back is one trading day: a sale two days before an only close stays in the day reports (documented limit)', $d);

echo "\n--- 10. Accountant closes and per-close windows (no double count) ---\n";
tw_check(rh_pos_close_covered_end('2026-11-11 03:30:00', '2026-11-10') === '2026-11-11 03:30:00', 'Cashier close at 03:30 (dated the night before) covers up to 03:30');
tw_check(rh_pos_close_covered_end('2026-11-11 14:00:00', '2026-11-10') === '2026-11-11 06:00:00', 'Accountant close of 11-10 recorded 11-11 14:00 covers only up to 11-11 06:00');
tw_check(rh_pos_close_dates_covering('2026-11-10') === ['2026-11-10', '2026-11-11'], 'Closed for 11-10 = a close dated 11-10 or 11-11 (the next close looks back one day)');
// Night shift never closed 11-10; closes 11-11 06:30 (dated 11-11): its window takes the 11-10 sales ...
$floorB = rh_pos_shift_window_floor(rh_station_union_trading_window(tw_at('2026-11-11 06:30:00'))['start_sql']);
tw_check(rh_pos_shift_window_from_closes([], $floorB, '2026-11-12 06:00:00') === '2026-11-10 06:00:00', 'Close at 11-11 06:30 with no earlier close counts from 11-10 06:00 (the unclosed night)');
// ... so the accountant screen for 11-10 must treat this user as closed: a close dated 11-11 exists.
tw_check(in_array('2026-11-11', rh_pos_close_dates_covering('2026-11-10'), true), 'Accountant close for 11-10 skips the user (nothing offered or expected again)');
// Accountant closes 11-10 at 11-11 14:00 for a user with no close; the user then closes 11-11 20:00.
$fromLate = rh_pos_shift_window_from_closes([['closed_at' => '2026-11-11 14:00:00', 'shift_date' => '2026-11-10']], rh_pos_shift_window_floor('2026-11-11 06:00:00'), '2026-11-12 06:00:00');
tw_check($fromLate === '2026-11-11 06:00:00', 'Later cashier close starts where the accountant close stopped (11-11 06:00), so 11-11 morning sales are not lost', $fromLate);
// Rebuild of a stored close ignores closes that came later.
$fromRebuild = rh_pos_shift_window_from_closes([['closed_at' => '2026-11-11 03:30:00', 'shift_date' => '2026-11-10'], ['closed_at' => '2026-11-11 15:00:00', 'shift_date' => '2026-11-11']], '2026-11-10 06:00:00', '2026-11-11 06:30:01');
tw_check($fromRebuild === '2026-11-11 03:30:00', 'Rebuilding the 06:30 close: starts at the 03:30 close, ignores the later 15:00 close', $fromRebuild);
// Accountant catches up in date order two days later: 11-10 closed (recorded 11-12 12:00), then 11-11.
$catchUp = [['closed_at' => '2026-11-12 12:00:00', 'shift_date' => '2026-11-10']];
$fromCatch = rh_pos_shift_window_from_closes($catchUp, rh_pos_shift_window_floor('2026-11-11 06:00:00'), '2026-11-12 06:00:00');
tw_check($fromCatch === '2026-11-11 06:00:00', 'Catch-up: the 11-11 close starts where the (later recorded) 11-10 close stopped, not at 11-10 06:00', $fromCatch);

echo "\n=== Result: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
