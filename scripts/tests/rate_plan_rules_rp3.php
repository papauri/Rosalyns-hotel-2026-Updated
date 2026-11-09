<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * RP-3 (owner 2026-11-10):
 *  (a) rate plans: surcharges and discounts chosen apart (single best per group when any is non-stacking, else all
 *      stack), surcharges first, then discounts. Rosalyn's live plans must price byte-identically to the old rule.
 *  (b) a staff date change removes a length-of-stay / weekend / seasonal discount the new stay no longer qualifies for.
 *  (c) the tourism levy is never taken out of the package part of a repriced stay.
 * Usage (project root, TEST HOTEL ONLY - Liwonde): php scripts/tests/rate_plan_rules_rp3.php
 * Parts A-C are pure (no writes). Part D runs admin pages through scripts/tests/page_harness.php (one outer
 * transaction, rolled back): nothing is kept.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ': ' . substr($detail, 0, 1500) : '') . "\n"; }
}
function rp3_page(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['raw' => substr((string)$out, -1500)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res += $r; } }
    return $res;
}

/** The selection rule BEFORE RP-3, copied verbatim from includes/pricing.php (only $today is a parameter). */
function rp3_old_apply(array $plans, int $room_id, string $check_in, string $check_out, int $nights, float $base_price, DateTime $today): array {
    $empty = ['final_price' => $base_price, 'original_price' => $base_price, 'discount_amount' => 0.0, 'rate_plan_id' => null, 'rate_plan_label' => '', 'rate_plan_row' => null];
    if ($nights < 1 || $base_price <= 0 || empty($plans)) { return $empty; }
    $checkInDate  = new DateTime($check_in);
    $checkOutDate = new DateTime($check_out);
    $daysUntilArrival = (int)$today->diff($checkInDate)->days;
    if ($checkInDate < $today) { $daysUntilArrival = 0; }
    $matching = [];
    foreach ($plans as $plan) {
        if (!_planAppliesToRoom($plan, $room_id)) { continue; }
        if (!_planMatchesStay($plan, $checkInDate, $checkOutDate, $nights, $daysUntilArrival)) { continue; }
        $matching[] = $plan;
    }
    if (empty($matching)) { return $empty; }
    $hasNonStacking = false;
    foreach ($matching as $plan) { if (empty($plan['is_stacking'])) { $hasNonStacking = true; break; } }
    if ($hasNonStacking) {
        $bestPlan = null; $bestPrice = null;
        foreach ($matching as $plan) {
            $candidate = applyRatePlanToPrice($plan, $base_price);
            if ($bestPrice === null || $candidate < $bestPrice - 0.00001) { $bestPrice = $candidate; $bestPlan = $plan; }
        }
        $applied = [$bestPlan];
    } else {
        $applied = $matching;
    }
    $currentPrice = $base_price;
    foreach ($applied as $plan) { $currentPrice = applyRatePlanToPrice($plan, $currentPrice); }
    $totalDiscount = $base_price - $currentPrice;
    $appliedPlan = $applied[0];
    $labels = []; $ids = [];
    foreach ($applied as $plan) { $labels[] = (string)$plan['name']; $ids[] = (int)$plan['id']; }
    return ['final_price' => round($currentPrice, 2), 'original_price' => $base_price, 'discount_amount' => round($totalDiscount, 2),
        'rate_plan_id' => (int)$appliedPlan['id'], 'rate_plan_label' => implode(', ', $labels), 'rate_plan_ids' => $ids, 'rate_plan_row' => $appliedPlan];
}

/** rh_reprice_at_booked_rate() BEFORE RP-3, verbatim. */
function rp3_old_reprice(array $b, int $newNights): array {
    $split = rh_booked_stay_split($b);
    $newNights = max(1, $newNights);
    $newGross = round($split['per_night'] * $newNights + $split['package_gross'], 2);
    $tt = rh_stay_totals($newGross, 'final', rh_booking_has_levy($b));
    $oldChild = (float)($b['child_supplement_total'] ?? 0);
    $newChild = $oldChild > 0 ? round($oldChild * ($newNights / $split['nights']), 2) : 0.0;
    return ['old_gross' => $split['total_with_vat'], 'new_gross' => $tt['total_with_vat'], 'delta' => round($tt['total_with_vat'] - $split['total_with_vat'], 2),
        'totals' => $tt, 'per_night' => $split['per_night'], 'new_child_supplement' => $newChild];
}

/** A rate_plans row shaped like the database returns it (strings), priority DESC order is the caller's job. */
function rp3_plan(int $id, string $name, string $rule, float $value, array $extra = []): array {
    return array_merge(['id' => (string)$id, 'name' => $name, 'description' => null, 'rule_type' => $rule, 'start_date' => null, 'end_date' => null,
        'days_of_week' => null, 'min_nights' => null, 'max_nights' => null, 'days_before_min' => null, 'days_before_max' => null,
        'adjustment_type' => 'percentage', 'adjustment_value' => number_format($value, 2, '.', ''), 'applies_to' => 'all', 'room_type_ids' => null,
        'priority' => '0', 'is_stacking' => '0', 'is_active' => '1'], $extra);
}

global $_SITE_SETTINGS;
$_SITE_SETTINGS['vat_enabled'] = '1';
$_SITE_SETTINGS['vat_rate'] = '17.5';
$_SITE_SETTINGS['vat_pricing_mode'] = 'inclusive';
$_SITE_SETTINGS['tourism_levy_enabled'] = '1';
$_SITE_SETTINGS['tourism_levy_percent'] = '1';
$today = new DateTime('2026-11-10');

echo "== (a) Rosalyn's live plans: byte-identical to the old rule ==\n";
$ros = [
    rp3_plan(11, 'Festival', 'seasonal', 18.4, ['start_date' => '2026-12-18', 'end_date' => '2027-01-03', 'applies_to' => 'room_types', 'room_type_ids' => '[2]']),
    rp3_plan(12, 'Festive', 'seasonal', 54.8, ['start_date' => '2026-12-18', 'end_date' => '2027-01-03', 'applies_to' => 'room_types', 'room_type_ids' => '["7"]']),
    rp3_plan(13, 'Festivity', 'seasonal', 83.5, ['start_date' => '2026-12-18', 'end_date' => '2027-01-03', 'applies_to' => 'room_types', 'room_type_ids' => '[1]']),
];
$same = 0; $diff = []; $hits = 0;
foreach ([1, 2, 7, 5] as $rt) {
    foreach (['2026-12-17', '2026-12-18', '2026-12-25', '2027-01-03', '2027-01-04', '2026-11-12'] as $ci) {
        foreach ([1, 3, 10] as $n) {
            $co = date('Y-m-d', strtotime($ci . ' +' . $n . ' day'));
            foreach ([100000.0, 85000.55, 1.0, 356000.0, 123456.78] as $base) {
                $old = rp3_old_apply($ros, $rt, $ci, $co, $n, $base, $today);
                $new = rh_apply_rate_plans($ros, $rt, $ci, $co, $n, $base, $today);
                if ($old === $new) { $same++; } else { $diff[] = json_encode([$rt, $ci, $n, $base, $old, $new]); }
                if ($new['rate_plan_id'] !== null) { $hits++; }
            }
        }
    }
}
ok("Rosalyn's 3 festive surcharges: every price key, id and label identical ($same cases, $hits with a plan)", !$diff && $hits > 0, implode("\n", array_slice($diff, 0, 3)));
$fv = rh_apply_rate_plans($ros, 1, '2026-12-20', '2026-12-23', 3, 100000.0, $today);
ok('Festivity +83.5% on type 1: 100,000 -> 183,500, discount_amount -83,500, label Festivity', $fv['final_price'] === 183500.0 && $fv['discount_amount'] === -83500.0 && $fv['rate_plan_label'] === 'Festivity' && $fv['rate_plan_id'] === 13 && $fv['rate_plan_ids'] === [13]);

echo "== (a) new rule ==\n";
// A non-stacking discount and a non-stacking surcharge now BOTH apply (old rule: only the discount).
$mix = [rp3_plan(21, 'Promo 10 off', 'promotion', -10, ['priority' => '9']), rp3_plan(22, 'Peak +20', 'promotion', 20, ['priority' => '1'])];
$r = rh_apply_rate_plans($mix, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('discount + surcharge: 100 +20% = 120, then -10% = 108; discount_amount = base - final = -8', $r['final_price'] === 108.0 && $r['discount_amount'] === -8.0, json_encode($r));
ok('discount + surcharge: ids and label in priority order, rate_plan_id / row = first in priority (the discount, priority 9)',
    $r['rate_plan_ids'] === [21, 22] && $r['rate_plan_label'] === 'Promo 10 off, Peak +20' && $r['rate_plan_id'] === 21 && (int)$r['rate_plan_row']['id'] === 21, json_encode($r));
ok('old rule on the same plans took only the discount (90)', rp3_old_apply($mix, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today)['final_price'] === 90.0);

$twoS = [rp3_plan(31, 'S +10%', 'promotion', 10, ['priority' => '5']), rp3_plan(32, 'S +25%', 'promotion', 25, ['priority' => '4']),
    rp3_plan(33, 'S +30 fixed', 'promotion', 30, ['priority' => '3', 'adjustment_type' => 'fixed', 'is_stacking' => '1'])];
$r = rh_apply_rate_plans($twoS, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('two non-stacking surcharges (+ a stacking one): only the single highest price applies (fixed +30 -> 130)', $r['final_price'] === 130.0 && $r['rate_plan_ids'] === [33] && $r['rate_plan_label'] === 'S +30 fixed', json_encode($r));
$tie = [rp3_plan(41, 'Tie A +20%', 'promotion', 20, ['priority' => '5']), rp3_plan(42, 'Tie B +20', 'promotion', 20, ['priority' => '2', 'adjustment_type' => 'fixed'])];
$r = rh_apply_rate_plans($tie, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('surcharge tie (120 vs 120): the earlier plan in priority order wins', $r['rate_plan_ids'] === [41] && $r['final_price'] === 120.0, json_encode($r));

$stack = [rp3_plan(51, 'D -10% a', 'promotion', -10, ['priority' => '5', 'is_stacking' => '1']), rp3_plan(52, 'S +10%', 'promotion', 10, ['priority' => '4', 'is_stacking' => '1']),
    rp3_plan(53, 'D -10% b', 'promotion', -10, ['priority' => '3', 'is_stacking' => '1'])];
$r = rh_apply_rate_plans($stack, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('all-stacking: surcharge first (110), then both discounts in priority order (99, 89.10)', $r['final_price'] === 89.1 && $r['discount_amount'] === 10.9 && $r['rate_plan_ids'] === [51, 52, 53], json_encode($r));
$stackD = [rp3_plan(54, 'D -10% a', 'promotion', -10, ['priority' => '3', 'is_stacking' => '1']), rp3_plan(55, 'D -10% b', 'promotion', -10, ['priority' => '2', 'is_stacking' => '1'])];
ok('all-stacking discounts only: 81, same as the old rule', rh_apply_rate_plans($stackD, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today) === rp3_old_apply($stackD, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today));

// The best discount is judged on the price after surcharges: on 150, -10% (135) beats -12 (138); on base 100 -12 (88) would beat -10% (90).
$judge = [rp3_plan(61, 'S +50', 'promotion', 50, ['adjustment_type' => 'fixed', 'priority' => '5']), rp3_plan(62, 'D -10%', 'promotion', -10, ['priority' => '4']),
    rp3_plan(63, 'D -12', 'promotion', -12, ['priority' => '3', 'adjustment_type' => 'fixed'])];
$r = rh_apply_rate_plans($judge, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('best discount judged on the post-surcharge price (150 -> -10% = 135)', $r['final_price'] === 135.0 && $r['rate_plan_ids'] === [61, 62], json_encode($r));
$r = rh_apply_rate_plans([rp3_plan(71, 'Huge', 'promotion', -500, ['adjustment_type' => 'fixed'])], 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('never below 0', $r['final_price'] === 0.0 && $r['discount_amount'] === 100.0, json_encode($r));
$long = [rp3_plan(81, str_repeat('A', 100), 'promotion', 10), rp3_plan(82, str_repeat('B', 100), 'promotion', -10)];
$r = rh_apply_rate_plans($long, 1, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('label of two 100-character names cut to the 120-character column', mb_strlen($r['rate_plan_label']) === 120 && $r['rate_plan_ids'] === [81, 82], (string)mb_strlen($r['rate_plan_label']));
$none = rh_apply_rate_plans($mix, 9, '2026-12-01', '2026-12-03', 2, 100.0, $today);
ok('no plans / no match: the same empty result as before', rh_apply_rate_plans([], 1, '2026-12-01', '2026-12-03', 2, 100.0, $today) === rp3_old_apply([], 1, '2026-12-01', '2026-12-03', 2, 100.0, $today)
    && rh_apply_rate_plans([rp3_plan(91, 'x', 'promotion', -5, ['applies_to' => 'room_types', 'room_type_ids' => '[3]'])], 1, '2026-12-01', '2026-12-03', 2, 100.0, $today)['rate_plan_id'] === null
    && rh_apply_rate_plans($mix, 1, '2026-12-01', '2026-12-01', 0, 100.0, $today)['final_price'] === 100.0);
ok('applyDynamicPricing still returns every key', count(array_diff(['final_price', 'original_price', 'discount_amount', 'rate_plan_id', 'rate_plan_label', 'rate_plan_row'],
    array_keys(applyDynamicPricing($pdo, 1, '2027-06-01', '2027-06-03', 2, 100.0)))) === 0);

echo "== (b) discount removed when a staff date change means the stay no longer qualifies ==\n";
$los = rp3_plan(101, 'Long stay 7+', 'los_discount', -10, ['min_nights' => '7']);
$bk = ['rate_plan_id' => '101', 'rate_plan_label' => 'Long stay 7+', 'rate_plan_discount' => '10000.00', 'room_id' => '1'];
$d = rh_rate_plan_drop_check($bk, $los, '2027-03-01', '2027-03-04', 3, 1);
ok('7-night LOS shortened to 3: removed, 10,000 a night added back, plain reason', $d['removed'] && $d['per_night'] === 10000.0 && $d['plan_name'] === 'Long stay 7+'
    && strpos($d['reason'], 'now 3 nights') !== false && strpos($d['reason'], 'at least 7') !== false, json_encode($d));
$d = rh_rate_plan_drop_check($bk, $los, '2027-03-01', '2027-03-09', 8, 1);
ok('7-night LOS extended to 8: kept', !$d['removed'] && $d['per_night'] === 0.0, json_encode($d));
$losMax = array_merge($los, ['max_nights' => '10']);
$d = rh_rate_plan_drop_check($bk, $losMax, '2027-03-01', '2027-03-13', 12, 1);
ok('LOS 7-10 extended to 12: removed ("at most 10")', $d['removed'] && strpos($d['reason'], 'at most 10') !== false, json_encode($d));
$eb = rp3_plan(102, 'Early bird', 'early_bird', -15, ['days_before_min' => '30']);
$d = rh_rate_plan_drop_check(['rate_plan_id' => '102', 'rate_plan_label' => 'Early bird', 'rate_plan_discount' => '15000', 'room_id' => '1'], $eb, '2026-11-11', '2026-11-12', 1, 1);
ok('early bird moved (now 1 day ahead): kept', !$d['removed']);
foreach (['last_minute' => 'Last minute', 'promotion' => 'Promo'] as $rule => $nm) {
    $d = rh_rate_plan_drop_check(['rate_plan_id' => '103', 'rate_plan_label' => $nm, 'rate_plan_discount' => '5000', 'room_id' => '1'], rp3_plan(103, $nm, $rule, -5, ['days_before_max' => '0']), '2027-05-01', '2027-05-02', 1, 1);
    ok("$rule discount: kept", !$d['removed']);
}
$d = rh_rate_plan_drop_check(array_merge($bk, ['rate_plan_label' => 'Long stay 7+, Weekend']), $los, '2027-03-01', '2027-03-04', 3, 1);
ok('stacked label (two plans): kept', !$d['removed']);
ok('deleted plan: kept', !rh_rate_plan_drop_check($bk, null, '2027-03-01', '2027-03-04', 3, 1)['removed']);
ok('discount at or below BALANCE_TOLERANCE: kept', !rh_rate_plan_drop_check(array_merge($bk, ['rate_plan_discount' => '0.01']), $los, '2027-03-01', '2027-03-04', 3, 1)['removed']);
ok("surcharge booking (Rosalyn's Festival, stored as -18,400) moved out of season: never touched",
    !rh_rate_plan_drop_check(['rate_plan_id' => '11', 'rate_plan_label' => 'Festival', 'rate_plan_discount' => '-18400.00', 'room_id' => '2'], $ros[0], '2027-02-01', '2027-02-04', 3, 2)['removed']);
$wk = rp3_plan(104, 'Weekend', 'weekend', -10, ['days_of_week' => '5,6']);
$wb = ['rate_plan_id' => '104', 'rate_plan_label' => 'Weekend', 'rate_plan_discount' => '8000', 'room_id' => '1'];
$d = rh_rate_plan_drop_check($wb, $wk, '2027-03-08', '2027-03-11', 3, 1); // Mon-Wed nights
ok('weekend plan moved to Mon-Wed nights: removed ("no night ... Fri, Sat")', $d['removed'] && strpos($d['reason'], 'Fri, Sat') !== false, json_encode($d));
ok('weekend plan moved but a Saturday night stays: kept', !rh_rate_plan_drop_check($wb, $wk, '2027-03-13', '2027-03-15', 2, 1)['removed']);
$se = rp3_plan(105, 'Green season', 'seasonal', -20, ['start_date' => '2027-01-10', 'end_date' => '2027-03-31']);
$sb = ['rate_plan_id' => '105', 'rate_plan_label' => 'Green season', 'rate_plan_discount' => '20000', 'room_id' => '1'];
$d = rh_rate_plan_drop_check($sb, $se, '2027-04-02', '2027-04-05', 3, 1);
ok('seasonal plan moved out of its window (check-in 2 Apr): removed, dates named', $d['removed'] && strpos($d['reason'], '2 Apr 2027') !== false && strpos($d['reason'], '31 Mar 2027') !== false, json_encode($d));
ok('seasonal plan: check-in still inside (stay runs past the end): kept (check-in date only)', !rh_rate_plan_drop_check($sb, $se, '2027-03-30', '2027-04-06', 7, 1)['removed']);
$d = rh_rate_plan_drop_check($bk, array_merge($los, ['applies_to' => 'room_types', 'room_type_ids' => '[4]']), '2027-03-01', '2027-03-09', 8, 1);
ok('plan no longer covers the room type: removed even though 8 nights qualify', $d['removed'] && strpos($d['reason'], 'room type') !== false, json_encode($d));
ok('rh_rate_plan_drop_sentence: one plain sentence', rh_rate_plan_drop_sentence(rh_rate_plan_drop_check($bk, $los, '2027-03-01', '2027-03-04', 3, 1)) === 'The Long stay 7+ discount was removed: the stay is now 3 nights and the plan needs at least 7 nights.');
$xss = rh_rate_plan_drop_check(array_merge($bk, ['rate_plan_label' => '<b>x</b>']), array_merge($los, ['name' => '<b>x</b>']), '2027-03-01', '2027-03-04', 3, 1);
$xs = rh_rate_plan_drop_sentence($xss);
ok('staff message escapes a plan name "<b>x</b>" (Alert.show inserts HTML)', $xss['removed'] && strpos($xs, '<b>') === false && strpos($xs, '&lt;b&gt;x&lt;/b&gt;') !== false, $xs);
ok('rate-plans save message is escaped before innerHTML (overlap note carries plan names)', strpos((string)file_get_contents($root . '/admin/rate-plans.php'), "+ '\"></i> ' + rpMsg;") !== false);

echo "== (b)+(c) rh_reprice_at_booked_rate ==\n";
foreach (['inclusive', 'exclusive'] as $vm) {
    $_SITE_SETTINGS['vat_pricing_mode'] = $vm;
    // 7 nights at 90,000 all-in (10,000 discount a night), levy on, no package.
    $roomTt = rh_stay_totals(630000.0, 'final', true);
    $b7 = ['total_with_vat' => 630000.0, 'total_amount' => round($roomTt['net'] + $roomTt['levy'], 2), 'vat_amount' => $roomTt['vat'], 'tourism_levy_amount' => $roomTt['levy'],
        'tourism_levy_percent' => 1, 'number_of_nights' => 7, 'package_total' => 0, 'child_supplement_total' => 0];
    $same = true;
    foreach ([1, 3, 7, 8, 12] as $n) { if (rh_reprice_at_booked_rate($b7, $n) !== rp3_old_reprice($b7, $n)) { $same = false; } }
    ok("[$vm] no package, nothing added back: identical to the old reprice for 1/3/7/8/12 nights", $same);
    $b7c = array_merge($b7, ['child_supplement_total' => 70000.0]);
    ok("[$vm] with a children charge: identical to the old reprice", rh_reprice_at_booked_rate($b7c, 3) === rp3_old_reprice($b7c, 3));
    $rp = rh_reprice_at_booked_rate($b7, 3, 10000.0);
    ok("[$vm] shortened to 3 with the 10,000 discount added back: (90,000 + 10,000) x 3 = 300,000", abs($rp['new_gross'] - 300000.0) <= BALANCE_TOLERANCE && abs($rp['per_night'] - 100000.0) <= BALANCE_TOLERANCE
        && abs($rp['totals']['net'] + $rp['totals']['vat'] + $rp['totals']['levy'] - 300000.0) <= BALANCE_TOLERANCE, json_encode($rp));
    // Same stay with a 35,000 package (per stay) inside the total.
    $pkg = rh_stay_totals(35000.0, 'price', false);
    $b7p = array_merge($b7, ['total_with_vat' => 665000.0, 'package_total' => 35000.0, 'total_amount' => round($roomTt['net'] + $roomTt['levy'] + $pkg['net'], 2),
        'vat_amount' => round($roomTt['vat'] + $pkg['vat'], 2)]);
    $new = rh_reprice_at_booked_rate($b7p, 3);
    $old = rp3_old_reprice($b7p, 3);
    $expRoom = rh_stay_totals(270000.0, 'final', true);
    ok("[$vm] with a package: the total is unchanged (305,000), only net / VAT / levy move", abs($new['new_gross'] - 305000.0) <= BALANCE_TOLERANCE && $new['new_gross'] === $old['new_gross'], json_encode([$new['totals'], $old['totals']]));
    ok("[$vm] with a package: levy only on the room part (270,000), none on the package", abs($new['totals']['levy'] - $expRoom['levy']) <= BALANCE_TOLERANCE && $new['totals']['levy'] < $old['totals']['levy'], json_encode($new['totals']));
    ok("[$vm] with a package: net + VAT + levy = total, VAT = room VAT + package VAT", abs($new['totals']['net'] + $new['totals']['vat'] + $new['totals']['levy'] - $new['new_gross']) <= BALANCE_TOLERANCE
        && abs($new['totals']['vat'] - round($expRoom['vat'] + $pkg['vat'], 2)) <= BALANCE_TOLERANCE, json_encode($new['totals']));
}
$_SITE_SETTINGS['vat_pricing_mode'] = 'inclusive';

echo "== (c) rh_stay_totals_with_package ==\n";
$allSame = true;
foreach (['inclusive', 'exclusive'] as $vm) {
    $_SITE_SETTINGS['vat_pricing_mode'] = $vm;
    foreach ([0.0, 1.0, 333333.33, 1068000.0] as $g) {
        foreach ([true, false] as $lv) {
            if (rh_stay_totals_with_package($g, 0.0, $lv) !== rh_stay_totals($g, 'final', $lv)) { $allSame = false; }
        }
    }
}
ok('no package: byte-identical to rh_stay_totals(gross, final) in both VAT modes, levy on and off', $allSame);
$_SITE_SETTINGS['vat_pricing_mode'] = 'inclusive';
$s = rh_stay_totals_with_package(330000.0, 30000.0, true);
$bp = rh_stay_totals(300000.0, 'price', true);
$pp = rh_stay_totals(30000.0, 'price', false);
ok('with a package: the same split booking.php saves (room with levy + package without)', abs($s['levy'] - $bp['levy']) <= BALANCE_TOLERANCE && abs($s['vat'] - round($bp['vat'] + $pp['vat'], 2)) <= BALANCE_TOLERANCE
    && abs($s['net'] - round($bp['net'] + $pp['net'], 2)) <= BALANCE_TOLERANCE && $s['total_with_vat'] === 330000.0, json_encode($s));
$_SITE_SETTINGS['tourism_levy_enabled'] = '0';
ok('levy switched off: package split changes nothing but the parts adding up', abs(rh_stay_totals_with_package(330000.0, 30000.0, true)['total_with_vat'] - 330000.0) <= BALANCE_TOLERANCE
    && rh_stay_totals_with_package(330000.0, 30000.0, true)['levy'] === 0.0);
$_SITE_SETTINGS['tourism_levy_enabled'] = '1';

echo "== (d) pages (rolled back): Edit Booking save, Bookings stay preview ==\n";
$room = null;
foreach ($pdo->query("SELECT * FROM rooms WHERE is_active = 1 AND price_per_night > 0 AND max_guests >= 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    if (!roomTypeHasActiveCombinations((int)$cand['id'])) { $room = $cand; break; }
}
if (!$room) {
    ok('skipped page checks: no active single room type', true);
} else {
    $rt = (int)$room['id'];
    $pol = resolveOccupancyPolicy($room, null);
    $occ = !empty($pol['single_enabled']) ? 'single' : (!empty($pol['double_enabled']) ? 'double' : 'triple');
    $guests = $occ === 'single' ? 1 : 2;
    $setSql = "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','17.5'),('vat_pricing_mode','inclusive'),('tourism_levy_enabled','1'),('tourism_levy_percent','1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $planId = 2099000301;
    $planSql = "INSERT INTO rate_plans (id, name, rule_type, min_nights, adjustment_type, adjustment_value, applies_to, priority, is_stacking, is_active)
                VALUES ({$planId}, 'ZZ RP3 Long stay', 'los_discount', 7, 'percentage', -10, 'all', 0, 0, 0)";
    $ci = date('Y-m-d', strtotime('+720 day'));
    $co7 = date('Y-m-d', strtotime($ci . ' +7 day'));
    $tt = rh_stay_totals(630000.0, 'final', true);
    $mkBooking = function (int $id, string $status) use ($rt, $ci, $co7, $tt, $planId, $occ, $guests): string {
        return "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                number_of_guests, adult_guests, child_guests, occupancy_type, status, payment_status, total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate,
                total_with_vat, amount_paid, amount_due, package_total, child_supplement_total, rate_plan_id, rate_plan_label, rate_plan_discount, created_at)
            VALUES ({$id}, 'ZZRP3{$id}', {$rt}, 'RP3 Guest', 'rp3@example.invalid', '000', '{$ci}', '{$co7}', 7, {$guests}, {$guests}, 0, '{$occ}', '{$status}', 'unpaid',
                " . round($tt['net'] + $tt['levy'], 2) . ", {$tt['vat']}, {$tt['levy']}, 1, 17.5, 630000, 0, 630000, 0, 0, {$planId}, 'ZZ RP3 Long stay', 10000, NOW())";
    };
    $bid = 2099000311;
    $post = ['guest_name' => 'RP3 Guest', 'guest_email' => 'rp3@example.invalid', 'guest_phone' => '000', 'guest_country' => '', 'check_in_date' => $ci,
        'number_of_guests' => (string)$guests, 'child_guests' => '0', 'occupancy_type' => $occ, 'individual_room_id' => '', 'special_requests' => '', 'booking_notes' => '', 'notify_guest' => '0'];
    $verify = ["SELECT rate_plan_id, rate_plan_label, rate_plan_discount, total_with_vat, number_of_nights, vat_amount, tourism_levy_amount FROM bookings WHERE id = {$bid}",
        "SELECT action, note, performed_by FROM booking_audit_log WHERE booking_id = {$bid} ORDER BY id"];

    $r = rp3_page('admin/edit-booking.php', ['get' => ['id' => (string)$bid], 'post' => $post + ['check_out_date' => date('Y-m-d', strtotime($ci . ' +3 day'))],
        'setup' => [$setSql, $planSql, $mkBooking($bid, 'confirmed')], 'verify' => $verify]);
    $row = $r['verify'][0][0] ?? [];
    $audit = $r['verify'][1] ?? [];
    $dropLog = null;
    foreach ($audit as $a) { if (($a['action'] ?? '') === 'discount_removed') { $dropLog = $a; } }
    $dbg = json_encode([$row, $audit, $r['raw'] ?? '']);
    ok('Edit Booking: 7-night LOS shortened to 3 -> total 300,000 (booked 90,000 + 10,000 added back, x 3)', isset($row['total_with_vat']) && abs((float)$row['total_with_vat'] - 300000.0) <= BALANCE_TOLERANCE && (int)$row['number_of_nights'] === 3, $dbg);
    ok('Edit Booking: rate_plan_id / label / discount cleared', array_key_exists('rate_plan_id', $row) && $row['rate_plan_id'] === null && $row['rate_plan_label'] === null && $row['rate_plan_discount'] === null, $dbg);
    ok('Edit Booking: History has who, the plan and old -> new nightly and total', $dropLog !== null && !empty($dropLog['performed_by'])
        && strpos((string)$dropLog['note'], 'ZZ RP3 Long stay') !== false && strpos((string)$dropLog['note'], '→') !== false && strpos((string)$dropLog['note'], '300,000') !== false && strpos((string)$dropLog['note'], '630,000') !== false, $dbg);
    ok('Edit Booking: rolled back, no leak', !empty($r['rolled_back']) && empty($r['leak']), $dbg);

    $r = rp3_page('admin/edit-booking.php', ['get' => ['id' => (string)$bid], 'post' => $post + ['check_out_date' => date('Y-m-d', strtotime($ci . ' +8 day'))],
        'setup' => [$setSql, $planSql, $mkBooking($bid, 'confirmed')], 'verify' => $verify]);
    $row = $r['verify'][0][0] ?? [];
    $dbg = json_encode([$row, $r['verify'][1] ?? [], $r['raw'] ?? '']);
    ok('Edit Booking: extended to 8 -> discount kept, 8 x 90,000 = 720,000', isset($row['total_with_vat']) && abs((float)$row['total_with_vat'] - 720000.0) <= BALANCE_TOLERANCE
        && (int)$row['rate_plan_id'] === $planId && (float)$row['rate_plan_discount'] === 10000.0, $dbg);
    ok('Edit Booking (extend): no discount_removed history row', !in_array('discount_removed', array_column($r['verify'][1] ?? [], 'action'), true), $dbg);

    $r2 = rp3_page('admin/bookings.php', ['ajax' => true, 'post' => ['action' => 'stay_price_preview', 'booking_id' => (string)$bid, 'new_check_in' => $ci,
        'new_check_out' => date('Y-m-d', strtotime($ci . ' +3 day'))], 'setup' => [$setSql, $planSql, $mkBooking($bid, 'confirmed')]]);
    $j = $r2['json'] ?? null;
    $out = json_encode($r2);
    ok('Bookings stay preview: shortened to 3 shows the same 300,000 and a "discount will be removed" note', is_array($j) && abs((float)($j['new_total'] ?? 0) - 300000.0) <= BALANCE_TOLERANCE
        && strpos((string)($j['plan_note'] ?? ''), 'ZZ RP3 Long stay discount will be removed') !== false, substr($out, 0, 1200));
    $r3 = rp3_page('admin/bookings.php', ['ajax' => true, 'post' => ['action' => 'stay_price_preview', 'booking_id' => (string)$bid, 'new_check_in' => $ci,
        'new_check_out' => date('Y-m-d', strtotime($ci . ' +8 day'))], 'setup' => [$setSql, $planSql, $mkBooking($bid, 'confirmed')]]);
    $j = $r3['json'] ?? null;
    $out = json_encode($r3);
    ok('Bookings stay preview: extended to 8 keeps the discount (720,000, no note)', is_array($j) && abs((float)($j['new_total'] ?? 0) - 720000.0) <= BALANCE_TOLERANCE && ($j['plan_note'] ?? 'x') === '', substr($out, 0, 1200));
    // Booking details "Adjust stay dates" (processBookingDateAdjustment): the same rule. No guest email (empty email).
    $mkAdj = function (int $id, bool $withPlan) use ($mkBooking): string {
        $sql = str_replace("'rp3@example.invalid'", "''", $mkBooking($id, 'confirmed'));
        return $withPlan ? $sql : str_replace(", 2099000301, 'ZZ RP3 Long stay', 10000, NOW())", ", NULL, NULL, NULL, NOW())", $sql);
    };
    $adjPost = ['adjust_dates' => '1', 'new_check_in' => $ci, 'new_check_out' => date('Y-m-d', strtotime($ci . ' +3 day')), 'adjustment_reason' => 'RP3 test'];
    $r = rp3_page('admin/booking-details.php', ['get' => ['id' => (string)$bid], 'post' => $adjPost, 'setup' => [$setSql, $planSql, $mkAdj($bid, true)], 'verify' => $verify]);
    $row = $r['verify'][0][0] ?? [];
    $dbg = json_encode([$row, $r['verify'][1] ?? [], $r['raw'] ?? '']);
    ok('Adjust stay dates: 7-night LOS shortened to 3 -> 300,000, plan fields cleared, History row (who, plan, old -> new)', isset($row['total_with_vat'])
        && abs((float)$row['total_with_vat'] - 300000.0) <= BALANCE_TOLERANCE && $row['rate_plan_id'] === null && $row['rate_plan_discount'] === null
        && in_array('discount_removed', array_column($r['verify'][1] ?? [], 'action'), true), $dbg);
    $r = rp3_page('admin/booking-details.php', ['get' => ['id' => (string)$bid], 'post' => $adjPost, 'setup' => [$setSql, $mkAdj($bid, false)], 'verify' => $verify]);
    $row = $r['verify'][0][0] ?? [];
    $noDisc = ['total_with_vat' => 630000.0, 'total_amount' => round($tt['net'] + $tt['levy'], 2), 'vat_amount' => $tt['vat'], 'tourism_levy_amount' => $tt['levy'], 'tourism_levy_percent' => 1,
        'number_of_nights' => 7, 'package_total' => 0, 'child_supplement_total' => 0];
    $_SITE_SETTINGS['vat_pricing_mode'] = 'inclusive';
    $calc = calculateDateAdjustmentAmount($noDisc, $ci, date('Y-m-d', strtotime($ci . ' +3 day')));
    $dbg = json_encode([$row, $r['verify'][1] ?? [], $r['raw'] ?? '']);
    ok('Adjust stay dates, no discount: byte-identical to the old booked-rate reprice (270,000), no History discount row', $calc['totals'] === rp3_old_reprice($noDisc, 3)['totals']
        && isset($row['total_with_vat']) && abs((float)$row['total_with_vat'] - 270000.0) <= BALANCE_TOLERANCE && abs((float)$row['vat_amount'] - $calc['totals']['vat']) <= BALANCE_TOLERANCE
        && !in_array('discount_removed', array_column($r['verify'][1] ?? [], 'action'), true), $dbg);

    $left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE id = {$bid}")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM rate_plans WHERE id = {$planId}")->fetchColumn();
    ok('nothing left behind (booking and plan rolled back)', $left === 0);
}

echo "== (a) Rate Plans overlap note (rolled back) ==\n";
$ovSetup = [
    "INSERT INTO rate_plans (name, rule_type, adjustment_type, adjustment_value, applies_to, priority, is_stacking, is_active) VALUES ('ZZ RP3 OV Disc', 'promotion', 'percentage', -5, 'all', 0, 0, 1)",
    "INSERT INTO rate_plans (name, rule_type, adjustment_type, adjustment_value, applies_to, priority, is_stacking, is_active) VALUES ('ZZ RP3 OV Sur', 'promotion', 'percentage', 5, 'all', 0, 0, 1)",
    "INSERT INTO rate_plans (name, rule_type, start_date, end_date, adjustment_type, adjustment_value, applies_to, priority, is_stacking, is_active) VALUES ('ZZ RP3 OV FarSeason', 'seasonal', '2027-12-01', '2027-12-31', 'percentage', -10, 'all', 0, 0, 1)",
];
$r = rp3_page('admin/rate-plans.php', ['ajax' => true, 'setup' => $ovSetup, 'post' => ['action' => 'save', 'name' => 'ZZ RP3 OV New', 'rule_type' => 'seasonal', 'start_date' => '2027-06-01',
    'end_date' => '2027-06-30', 'adjustment_type' => 'percentage', 'adjustment_value' => '-15', 'applies_to' => 'all', 'priority' => '1', 'is_active' => '1'],
    'verify' => ["SELECT COUNT(*) AS n FROM rate_plans WHERE name LIKE 'ZZ RP3 OV%'"]]);
$msg = (string)($r['json']['message'] ?? '');
ok('overlap note: names the other discount, not the surcharge, not the seasonal discount whose dates do not overlap', ($r['json']['success'] ?? false) === true
    && strpos($msg, 'ZZ RP3 OV Disc') !== false && strpos($msg, 'ZZ RP3 OV Sur') === false && strpos($msg, 'FarSeason') === false && strpos($msg, 'discount plan(s)') !== false, $msg . ' ' . ($r['raw'] ?? ''));
ok('overlap note: rolled back', !empty($r['rolled_back']) && empty($r['leak']) && (int)$pdo->query("SELECT COUNT(*) FROM rate_plans WHERE name LIKE 'ZZ RP3 OV%'")->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
