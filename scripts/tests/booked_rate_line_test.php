<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Booked rate line (includes/booked-rate.php). NO DATABASE: only the pure helpers are loaded and fed
 * rh_booked_stay_split()-shaped arrays. Story: RBH2026796618 booked at K356,000 x 3 = K1,068,000; the Superior Suite
 * price later went to K450,300. Usage (repo root): php scripts/tests/booked_rate_line_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

define('BALANCE_TOLERANCE', 0.01);
require_once $root . '/includes/booked-rate.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the pure helpers load without the database or settings');

$split = static function (float $room, int $nights, float $pkg = 0.0): array {
    return ['room_gross' => $room, 'package_gross' => $pkg, 'nights' => $nights];
};

// 1. 1,068,000 over 3 nights -> K356,000 x 3
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'room_name' => 'Superior Suite', 'has_vat' => true, 'has_levy' => true]);
check($l['ok'] && abs($l['per_night'] - 356000) < 0.001 && $l['nights'] === 3, '356,000 a night x 3');
check($l['text'] === 'K356,000.00 × 3 nights = K1,068,000.00', 'text', $l['text']);
check($l['compact'] === 'K356,000.00 × 3', 'compact', $l['compact']);
check($l['guest_text'] === 'Superior Suite - K356,000.00 × 3 nights (a night, incl. VAT and tourism levy)', 'guest text', $l['guest_text']);
check(abs($l['room_total'] - 1068000) < 0.001 && abs($l['total'] - 1068000) < 0.001, 'room total is the stored total');

// 2. one night, singular
$l = rh_booked_rate_text($split(356000, 1), ['currency' => 'MWK']);
check($l['text'] === 'MWK 356,000.00 × 1 night = MWK 356,000.00', '1 night (singular, word currency spaced)', $l['text']);
check($l['guest_text'] === 'MWK 356,000.00 × 1 night', 'guest text without tax words or room', $l['guest_text']);

// 3. package shown separately, not folded into the nightly rate
$l = rh_booked_rate_text($split(1068000, 3, 150000), ['currency' => 'K']);
check(abs($l['per_night'] - 356000) < 0.001, 'package not in the nightly rate');
check($l['text'] === 'K356,000.00 × 3 nights = K1,068,000.00 + package K150,000.00 = K1,218,000.00', 'package text', $l['text']);
check(abs($l['total'] - 1218000) < 0.001, 'total includes the package');

// 3b. children shown separately too
$l = rh_booked_rate_text($split(1128000, 3), ['currency' => 'K', 'child' => 60000]);
check($l['text'] === 'K356,000.00 × 3 nights = K1,068,000.00 + children K60,000.00 = K1,128,000.00', 'children text', $l['text']);
$l = rh_booked_rate_text($split(100000, 2), ['currency' => 'K', 'child' => 100000]);
check(abs($l['per_night'] - 50000) < 0.001 && $l['child'] == 0.0, 'legacy child figure as big as the room folds back');

// 4. today's price note
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 450300]);
check($l['today_text'] === "Today's price is K450,300.00 a night — this booking keeps the price it was booked at.", 'today note shown when the price changed', $l['today_text']);
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 356000]);
check($l['today_text'] === '', 'no note when today = booked rate');
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 356000.005]);
check($l['today_text'] === '', 'no note inside BALANCE_TOLERANCE');
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => null]);
check($l['today_text'] === '', 'no note when today is unknown');
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 400000, 'discount_per_night' => 44000]);
check($l['today_text'] === '', 'rate plan: no note when today = booked rate + discount');
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 450300, 'discount_per_night' => 44000]);
check($l['today_text'] !== '', 'rate plan: note when today matches neither');
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 300000, 'discount_per_night' => -56000]);
check($l['today_text'] === '', 'rate plan surcharge: no note when today = booked rate - surcharge (the list price)', $l['today_text']);
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'today' => 450300, 'discount_per_night' => -56000]);
check($l['today_text'] !== '', 'rate plan surcharge: note when today matches neither');

// 5. rounding: the total is the stored figure, never per_night x nights
$l = rh_booked_rate_text($split(1000000, 3), ['currency' => 'K']);
check(abs($l['per_night'] - 333333.33) < 0.001, 'per night rounded to 2 dp');
check($l['text'] === 'K333,333.33 × 3 nights = K1,000,000.00', 'total stays the stored K1,000,000.00', $l['text']);
check(abs($l['room_total'] - 1000000) < 0.001, 'room_total not 999,999.99');
$l = rh_booked_rate_text($split(1068000.004, 3), ['currency' => 'K']);
check($l['text'] === 'K356,000.00 × 3 nights = K1,068,000.00', 'sub-tambala noise rounds away', $l['text']);

// 6. empty / zero / cancelled
$l = rh_booked_rate_text($split(0, 3), ['currency' => 'K']);
check(!$l['ok'] && $l['text'] === '' && $l['compact'] === '', 'zero total: nothing to show');
$l = rh_booked_rate_text(['room_gross' => 500, 'nights' => 0], ['currency' => 'K']);
check($l['nights'] === 1 && $l['text'] === 'K500.00 × 1 night = K500.00', 'zero nights treated as one', $l['text']);
$l = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'status' => 'cancelled']);
check($l['cancelled'] === true, 'cancelled flag');

// 7. today's price for the room / occupancy / joined room
$b = ['occupancy_type' => 'double', 'rh_pp_night' => '450300', 'rh_pp_double' => '480000', 'rh_pp_single' => null];
check(rh_room_today_price($b) === 480000.0, 'occupancy price when set');
check(rh_room_today_price(['occupancy_type' => 'single'] + $b) === 450300.0, 'falls back to price_per_night');
check(rh_room_today_price($b, ['price_override' => '600000']) === 600000.0, 'joined-room override');
check(rh_room_today_price($b, ['price_override' => null]) === 450300.0, 'joined room without override');
check(rh_room_today_price(['occupancy_type' => '']) === null, 'unknown price is null');

// 8. children with VAT and levy, inclusive and exclusive mode (QA 2026-11-09). Settings are stubbed in memory (no DB):
// the stay is priced exactly as create-booking.php does it, rh_stay_totals($room + $child, 'price'), and the child
// figure is put on the same basis by rh_booked_rate_child_gross(). The nightly rate must come out exact, with no
// false "Today's price" note.
$GLOBALS['__stub'] = ['vat_rate' => 16.5, 'tourism_levy_enabled' => '1', 'tourism_levy_percent' => 1, 'mode' => 'inclusive'];
if (!function_exists('getSetting')) { // conditional, so it is declared only here (not hoisted before check 0)
    function getSetting($k, $d = null) { return $GLOBALS['__stub'][$k] ?? $d; }
    function vat_mode(): string { return $GLOBALS['__stub']['mode']; }
}
require_once $root . '/includes/pricing.php';
foreach (['inclusive', 'exclusive'] as $mode) {
    foreach ([true, false] as $levy) {
        $GLOBALS['__stub']['mode'] = $mode;
        $GLOBALS['__stub']['tourism_levy_enabled'] = $levy ? '1' : '0';
        $childStored = 3 * 178000.0;                       // 50% child at K356,000 x 3 nights
        $tt = rh_stay_totals(1068000 + $childStored, 'price', $levy);
        $childGross = rh_booked_rate_child_gross($childStored, $levy);
        $l = rh_booked_rate_text($split($tt['total_with_vat'], 3), ['currency' => 'K', 'child' => $childGross, 'today' => 356000]);
        $tag = $mode . ($levy ? ' + levy' : ' no levy');
        check(abs($l['per_night'] - 356000) < 0.001, "children ($tag): nightly rate stays K356,000", (string)$l['per_night']);
        check($l['today_text'] === '', "children ($tag): no false today note");
        check(abs($l['room_total'] + $l['child'] - $tt['total_with_vat']) < 0.011, "children ($tag): room + children = stored total");
    }
}
check(rh_booked_rate_child_gross(0.0, true) === 0.0, 'no children: 0');

// 9. stay changes (includes/booked-rate-change.php): booked rate × NEW nights = the repriced total.
// $rp is shaped like rh_reprice_at_booked_rate(): its per night INCLUDES the children charge.
require_once $root . '/includes/booked-rate-change.php';
$l = rh_booked_rate_change_line(['new_gross' => 1424000, 'new_child_supplement' => 0], 0, 4, ['currency' => 'K']);
check($l['ok'] && $l['text'] === 'K356,000.00 × 4 nights = K1,424,000.00', 'extend 3 -> 4 nights at the booked rate', $l['text']);
$l = rh_booked_rate_change_line(['new_gross' => 712000, 'new_child_supplement' => 0], 0, 2, ['currency' => 'MWK']);
check($l['text'] === 'MWK 356,000.00 × 2 nights = MWK 712,000.00' && $l['compact'] === 'MWK 356,000.00 × 2', 'shorten 3 -> 2 nights', $l['text']);
// Booked: K1,128,000 room (incl. children K60,000) over 3 nights + package K150,000 -> 1 night:
// 376,000 (room + children a night) × 1 + 150,000 = 526,000; children 60,000 × 1/3 = 20,000.
$orig = rh_booked_rate_text($split(1128000, 3, 150000), ['currency' => 'K', 'child' => 60000]);
$l = rh_booked_rate_change_line(['new_gross' => 526000, 'new_child_supplement' => 20000], 150000, 1, ['currency' => 'K']);
check(abs($l['per_night'] - $orig['per_night']) < 0.001 && abs($l['per_night'] - 356000) < 0.001, 'children + package: the rate stays the booked K356,000');
check($l['text'] === 'K356,000.00 × 1 night = K356,000.00 + children K20,000.00 + package K150,000.00 = K526,000.00', 'children + package + 1 night text', $l['text']);
check(abs($l['total'] - 526000) < 0.001, 'the line total equals the repriced total');
$l = rh_booked_rate_change_line(['new_gross' => 1800000, 'new_child_supplement' => 0], 0, 3, ['currency' => 'K', 'status' => 'cancelled']);
check($l['text'] === 'K600,000.00 × 3 nights = K1,800,000.00' && $l['cancelled'], 'joined rooms priced as one pair; cancelled flag carried', $l['text']);
$l = rh_booked_rate_change_line([], 0, 2, ['currency' => 'K']);
check(!$l['ok'] && $l['text'] === '', 'no figures: nothing to show');
$l = rh_booked_rate_change_line(['new_gross' => 526000, 'new_child_supplement' => 17000], 150000, 1, ['currency' => 'K', 'child' => 20000]);
check(abs($l['child'] - 20000) < 0.001 && abs($l['per_night'] - 356000) < 0.001, 'an all-in children figure passed in opts wins over the stored one');

// 10. "How the total is made up" card (rh_booking_money_card): stored figures only. RBH2026796618 as stored:
// total_amount = net 901,265.82 + levy 9,012.66; VAT 157,721.52 at 17.5%; levy 1%; total 1,068,000.
$cardRow = ['total_with_vat' => 1068000, 'total_amount' => 910278.48, 'vat_amount' => 157721.52, 'vat_rate' => 17.5,
    'tourism_levy_amount' => 9012.66, 'tourism_levy_percent' => 1, 'number_of_nights' => 3, 'status' => 'confirmed',
    'amount_paid' => 0, 'amount_due' => 1068000, 'credit_balance' => 0, 'folio_charges_total' => 0];
$cardVals = static function (array $c): array {
    $o = [];
    foreach ($c['lines'] as $ln) {
        $o[$ln['label']] = $ln['value'];
    }
    return $o;
};
$rate = rh_booked_rate_text($split(1068000, 3), ['currency' => 'MWK']);
$c = rh_booking_money_card($cardRow, $rate, ['currency' => 'MWK']);
$v = $cardVals($c);
check($c['ok'] && $c['adds_up'], 'card: RBH2026796618 adds up');
check(($v['Room'] ?? '') === 'MWK 356,000.00 × 3 nights = MWK 1,068,000.00 (all-in)', 'card: room line', $v['Room'] ?? '');
check(($v['Net room price (excl. VAT and levy)'] ?? '') === 'MWK 901,265.82', 'card: net = total_amount - levy', $v['Net room price (excl. VAT and levy)'] ?? '');
check(($v['VAT 17.5%'] ?? '') === 'MWK 157,721.52' && ($v['Tourism levy 1%'] ?? '') === 'MWK 9,012.66', 'card: VAT and levy with their rates');
check(($v['= Total'] ?? '') === 'MWK 1,068,000' && ($v['Balance due'] ?? '') === 'MWK 1,068,000' && !isset($v['Paid so far']), 'card: total, balance due, no paid line when nothing paid');

// children, package, rate plan, folio extras, part paid
$row2 = ['total_with_vat' => 1278000, 'total_amount' => 1089238.64, 'vat_amount' => 188761.36, 'vat_rate' => 17.5,
    'tourism_levy_amount' => 0, 'number_of_nights' => 3, 'status' => 'checked-in', 'rate_plan_label' => 'Early bird',
    'rate_plan_discount' => 20000, 'amount_paid' => 500000, 'amount_due' => 853000, 'credit_balance' => 0, 'folio_charges_total' => 75000];
$c = rh_booking_money_card($row2, rh_booked_rate_text($split(1128000, 3, 150000), ['currency' => 'K', 'child' => 60000]), ['currency' => 'K']);
$v = $cardVals($c);
check($c['adds_up'] && ($v['Child charge'] ?? '') === 'K60,000' && ($v['Package'] ?? '') === 'K150,000', 'card: child charge and package lines');
check(($v['Early bird (already taken off the room rate)'] ?? '') === '-K60,000', 'card: rate plan named, discount x nights, not added');
$c3 = rh_booking_money_card(['rate_plan_label' => 'Festival Season', 'rate_plan_discount' => -20000] + $row2, rh_booked_rate_text($split(1128000, 3, 150000), ['currency' => 'K', 'child' => 60000]), ['currency' => 'K']);
$v3 = $cardVals($c3);
$k3 = array_values(array_filter($c3['lines'], fn($ln) => strpos($ln['label'], 'surcharge') !== false))[0] ?? [];
check($c3['adds_up'] && ($v3['Festival Season surcharge (already in the room rate)'] ?? '') === '+K60,000' && ($k3['kind'] ?? '') === 'note' && !isset($v3['Festival Season (already taken off the room rate)']), 'card: surcharge named, + x nights, a note (not added), total unchanged');
check(isset($v['Net price (excl. VAT and levy)']) && !isset($v['Tourism levy']), 'card: net label covers extras; no levy line when none');
check(($v['Folio extras (meals, services and other charges)'] ?? '') === 'K75,000' && ($v['Paid so far'] ?? '') === 'K500,000' && ($v['Balance due'] ?? '') === 'K853,000', 'card: extras, paid, stored balance');

// old row that does not add up: stored figures, no "=" line
$c = rh_booking_money_card(['total_with_vat' => 1068000, 'total_amount' => 900000, 'vat_amount' => 150000, 'tourism_levy_amount' => 0,
    'number_of_nights' => 3, 'status' => 'confirmed'], $rate, ['currency' => 'K']);
check($c['ok'] && !$c['adds_up'] && !isset($cardVals($c)['= Total']) && strpos($c['note'], 'do not add up') !== false, 'card: old row shows no wrong sum');

// cancelled: said so, credit shown, never a balance due
$c = rh_booking_money_card(['status' => 'cancelled', 'credit_balance' => 200000, 'amount_due' => 868000] + $cardRow, $rate, ['currency' => 'K']);
$v = $cardVals($c);
check($c['closed'] && !isset($v['Balance due']) && ($v['Credit owed to guest'] ?? '') === 'K200,000' && strpos($c['note'], 'cancelled') !== false, 'card: cancelled says so, no balance due');
$c = rh_booking_money_card(['status' => 'no-show'] + $cardRow, $rate, ['currency' => 'K']);
check(!isset($cardVals($c)['Balance due']) && strpos($c['note'], 'no-show') !== false, 'card: no-show, no balance due');

// 11. closed bookings still show what is owed (QA 2026-11-09): kept first night + unvoided extras are chased by
// Payments outstanding and receivables, so the card must not say "no balance is due" then.
$c = rh_booking_money_card(['status' => 'cancelled', 'cancellation_retained_amount' => 356000, 'folio_charges_total' => 25000,
    'amount_paid' => 0, 'amount_due' => 381000, 'credit_balance' => 0] + $cardRow, $rate, ['currency' => 'K']);
$v = $cardVals($c);
check(($v['Still owed (cancelled booking)'] ?? '') === 'K381,000' && ($v['Kept on cancellation (still billed)'] ?? '') === 'K356,000'
    && !isset($v['Balance due']) && strpos($c['note'], 'no balance is due') === false && strpos($c['note'], 'K381,000 is still owed') !== false,
    'closed: cancelled with something owed shows it', $c['note']);
$c = rh_booking_money_card(['status' => 'cancelled', 'cancellation_retained_amount' => 0, 'amount_due' => 0.004, 'credit_balance' => 0] + $cardRow, $rate, ['currency' => 'K']);
$v = $cardVals($c);
check(!isset($v['Still owed (cancelled booking)']) && !isset($v['Kept on cancellation (still billed)']) && strpos($c['note'], 'no balance is due') !== false,
    'closed: cancelled with nothing owed (within tolerance) says no balance is due', $c['note']);
$c = rh_booking_money_card(['status' => 'expired', 'amount_due' => 0, 'credit_balance' => 0] + $cardRow, $rate, ['currency' => 'K']);
check($c['closed'] && strpos($c['note'], 'expired') !== false && strpos($c['note'], 'no balance is due') !== false && !isset($cardVals($c)['Balance due']), 'closed: expired, nothing owed');
$c = rh_booking_money_card(['status' => 'expired', 'amount_due' => 50000, 'credit_balance' => 0] + $cardRow, $rate, ['currency' => 'K']);
check(($cardVals($c)['Still owed (expired booking)'] ?? '') === 'K50,000', 'closed: expired with something owed');
// VAT switched off: no VAT line, net + levy = total still adds up
$c = rh_booking_money_card(['total_with_vat' => 1068000, 'total_amount' => 1068000, 'vat_amount' => 0, 'vat_rate' => 0,
    'tourism_levy_amount' => 10574.26, 'tourism_levy_percent' => 1, 'number_of_nights' => 3, 'status' => 'confirmed',
    'amount_due' => 1068000], $rate, ['currency' => 'K']);
$v = $cardVals($c);
check($c['adds_up'] && !isset($v['VAT']) && ($v['Net room price (excl. VAT and levy)'] ?? '') === 'K1,057,425.74' && ($v['= Total'] ?? '') === 'K1,068,000',
    'VAT off: no VAT line, net + levy = total');

// nothing stored: no card
check(!rh_booking_money_card(['total_with_vat' => 0, 'total_amount' => 0], $rate)['ok'], 'card: empty booking shows nothing');

// Group / multi-room receipt and {{booked_rate}}: rh_booked_rate_group_lines (PURE)
$gt = rh_booked_rate_text($split(1068000, 3), ['currency' => 'K', 'room_name' => 'Superior Suite'])['guest_text'];
$dt = rh_booked_rate_text($split(900000, 3), ['currency' => 'K', 'room_name' => 'Deluxe Room'])['guest_text'];
check(rh_booked_rate_group_lines([]) === [] && rh_booked_rate_group_lines(['', '  ']) === [], 'group lines: nothing to show -> no line');
check(rh_booked_rate_group_lines([$gt]) === ['Room: Superior Suite - K356,000.00 × 3 nights'], 'group lines: one room keeps the single "Room: ..." line',
    json_encode(rh_booked_rate_group_lines([$gt]), JSON_UNESCAPED_UNICODE));
$g2 = rh_booked_rate_group_lines([$gt, '', $dt]);
check($g2 === ['Room 1: Superior Suite - K356,000.00 × 3 nights', 'Room 2: Deluxe Room - K300,000.00 × 3 nights'], 'group lines: two rooms, one line each (blank skipped)',
    json_encode($g2, JSON_UNESCAPED_UNICODE));
$g4 = rh_booked_rate_group_lines([$gt, $dt, $gt, $dt]);
check(count($g4) === 4 && strpos($g4[3], 'Room 4: Deluxe Room') === 0, 'group lines: four rooms still one line each');
$g5 = rh_booked_rate_group_lines([$gt, $dt, $gt, $dt, $gt]);
check(count($g5) === 1 && strpos($g5[0], '5 rooms: Superior Suite - K356,000.00 × 3 nights; Deluxe Room') === 0 && substr_count($g5[0], '; ') === 4,
    'group lines: more than four rooms -> one "5 rooms: ..." line', $g5[0]);
$plain = str_replace(['×', '·'], ['x', '-'], $g2[0]);
check($plain === 'Room 1: Superior Suite - K356,000.00 x 3 nights' && strpos($plain, '×') === false, 'PDF copy uses a plain x', $plain);
check(strpos(implode(' ', $g5), "Today's price") === false, 'guest lines never carry today\'s price');
// One room's receipt in a group: a cancelled room says so on the group line; no note = the line as before.
$rl = rh_booked_rate_room_lines(['room' => 'Deluxe Room - 12', 'guest' => 'Mr Banda', 'check_in' => '2026-11-12', 'check_out' => '2026-11-15'], 'K300,000.00 × 3 nights', 'BK-1', 3, 'this room cancelled 10 Nov 2026');
check(($rl[2] ?? '') === 'Part of group BK-1 (3 rooms) - this room cancelled 10 Nov 2026', 'room receipt lines: cancelled note on the group line', json_encode($rl, JSON_UNESCAPED_UNICODE));
$rl0 = rh_booked_rate_room_lines(['room' => 'Deluxe Room - 12', 'guest' => '', 'check_in' => '', 'check_out' => ''], '', 'BK-1', 3);
check(($rl0[1] ?? '') === 'Part of group BK-1 (3 rooms)', 'room receipt lines: no note = unchanged group line', json_encode($rl0, JSON_UNESCAPED_UNICODE));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
