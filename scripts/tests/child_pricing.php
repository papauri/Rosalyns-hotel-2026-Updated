<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Child charge rules (owner decision 2026-10-22). Usage (project root): php scripts/tests/child_pricing.php
 *
 * One rule per room type with a per-room override: a % of the room rate OR a fixed amount per child per night,
 * a "first N children free" allowance and a most-children-per-room limit; 0% and a fixed 0 are free.
 *   - rule maths (percent unchanged vs the old formula for every live room type, fixed, free allowance, free = 0)
 *   - individual room overrides the room type; blank = inherit; a room that kept its own % stays a % override
 *   - max children: refused by the shared limit check, by the guest-allocation split (website) and by seating (admin)
 *   - admin create-booking saves what the rule says (each scenario runs in scripts/tests/page_harness.php and is
 *     rolled back); the booking keeps its rule (snapshot columns); the website's calculation gives the same figure
 *   - the edit / room-change nightly rate and the document wording follow the same rule
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/guest-allocation.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function near(float $a, float $b, float $tol = 0.005): bool { return abs($a - $b) <= $tol; }
function cp_run(string $page, array $post, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}

// ── 1. Rule maths ───────────────────────────────────────────────────────────
echo "== rule maths ==\n";
$pct50 = ['child_price_multiplier' => 50, 'child_charge_mode' => 'percent', 'child_charge_amount' => null, 'free_children' => 0, 'max_children' => null];
$rule = rh_child_rule($pct50);
ok('percent 50: 2 children x 3 nights at MWK 100,000 = 300,000 (rate x % x children x nights)', near(rh_child_supplement($rule, 2, 3, 100000), 300000));
ok('no children = no charge', rh_child_supplement($rule, 0, 3, 100000) === 0.0);

$fixed = rh_child_rule(['child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'child_price_multiplier' => 50, 'free_children' => 0, 'max_children' => null]);
ok('fixed 10,000: 1 child x 7 nights = 70,000 whatever the room rate', near(rh_child_supplement($fixed, 1, 7, 100000), 70000) && near(rh_child_supplement($fixed, 1, 7, 999999), 70000));

$free2 = rh_child_rule(['child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'free_children' => 2, 'max_children' => null]);
ok('free allowance: 2 children with 2 free cost nothing', rh_child_supplement($free2, 2, 5, 100000) === 0.0);
ok('free allowance: the 3rd child is charged (1 x 10,000 x 5 = 50,000)', near(rh_child_supplement($free2, 3, 5, 100000), 50000));
$free1pct = rh_child_rule(['child_price_multiplier' => 25, 'child_charge_mode' => 'percent', 'free_children' => 1]);
ok('free allowance also works on a % rule (3 children, 1 free, 25% of 80,000 x 2 nights = 80,000)', near(rh_child_supplement($free1pct, 3, 2, 80000), 80000));

ok('0% means free', rh_child_supplement(rh_child_rule(['child_price_multiplier' => 0, 'child_charge_mode' => 'percent']), 3, 4, 123456) === 0.0);
ok('fixed amount 0 means free', rh_child_supplement(rh_child_rule(['child_charge_mode' => 'fixed', 'child_charge_amount' => 0]), 3, 4, 123456) === 0.0);
ok('rounded to 2 decimals like the saved figure', rh_child_supplement(rh_child_rule(['child_price_multiplier' => 33.33, 'child_charge_mode' => 'percent']), 1, 1, 100.01) === round(100.01 * 0.3333, 2));

// Percent mode is identical to the old formula for every live room type (existing rows keep their prices)
$legacyOk = true; $legacyDetail = '';
foreach ($pdo->query('SELECT * FROM rooms')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $r = rh_child_rule($row);
    $cpm = (float)$row['child_price_multiplier'];
    if ($r['mode'] !== 'percent' || $r['free'] !== 0 || !near($r['percent'], $cpm)) { $legacyOk = false; $legacyDetail = 'rule for ' . $row['name'] . ' ' . json_encode($r); }
    foreach ([1, 2, 3, 5] as $kids) { foreach ([1, 2, 7, 30] as $n) { foreach ([1.0, 99999.99, 356000.0, 712345.67] as $rate) {
        $old = $kids > 0 ? $rate * ($cpm / 100) * $kids * $n : 0;
        if (!near(rh_child_supplement($r, $kids, $n, $rate), round($old, 2), 0.011)) { $legacyOk = false; $legacyDetail = "{$row['name']} $kids x $n @ $rate"; }
    } } }
}
ok('percent mode gives the same supplement as the old formula on every live room type (no price changes)', $legacyOk, $legacyDetail);

// ── 2. Individual room overrides the room type ──────────────────────────────
echo "== individual room overrides the type ==\n";
$typeFixed = ['child_price_multiplier' => 50, 'child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'free_children' => 1, 'max_children' => 3];
ok('a room with every child setting blank inherits the type', rh_child_rule($typeFixed, ['child_charge_mode' => null, 'child_charge_amount' => null, 'free_children' => null, 'max_children' => null, 'child_price_multiplier' => null]) == rh_child_rule($typeFixed) + ['source' => 'type']);
$indFixed = rh_child_rule($pct50, ['child_charge_mode' => 'fixed', 'child_charge_amount' => 5000, 'free_children' => null, 'max_children' => null, 'child_price_multiplier' => null]);
ok('a room set to fixed 5,000 beats a 50% type: 2 children x 2 nights = 20,000', $indFixed['mode'] === 'fixed' && $indFixed['source'] === 'room' && near(rh_child_supplement($indFixed, 2, 2, 100000), 20000));
$indPct = rh_child_rule($typeFixed, ['child_charge_mode' => 'percent', 'child_price_multiplier' => 20, 'child_charge_amount' => null, 'free_children' => 0, 'max_children' => null]);
ok('a room set to 20% beats a fixed type, and its own free count (0) beats the type\'s 1', $indPct['mode'] === 'percent' && $indPct['free'] === 0 && near(rh_child_supplement($indPct, 1, 1, 100000), 20000));
$indLegacy = rh_child_rule($typeFixed, ['child_price_multiplier' => 10, 'child_charge_mode' => null, 'child_charge_amount' => null, 'free_children' => null, 'max_children' => null]);
ok('a room that already had its own % (before fixed amounts existed) stays a % override', $indLegacy['mode'] === 'percent' && near($indLegacy['percent'], 10));
$indMax = rh_child_rule($typeFixed, ['max_children' => 1, 'child_charge_mode' => null, 'child_charge_amount' => null, 'free_children' => null, 'child_price_multiplier' => null]);
ok('a room can lower the type\'s maximum children', $indMax['max'] === 1 && $indMax['mode'] === 'fixed');
$indZero = rh_child_rule($pct50, ['child_charge_mode' => 'fixed', 'child_charge_amount' => 0, 'free_children' => null, 'max_children' => null, 'child_price_multiplier' => null]);
ok('a room can make children free on a paying type (fixed 0)', rh_child_supplement($indZero, 4, 3, 500000) === 0.0);
// 0% is a real value, not "blank": it must stay 0 at every layer (owner 2026-10-26).
$indPct0 = rh_child_rule($pct50, ['child_charge_mode' => 'percent', 'child_price_multiplier' => '0.00', 'child_charge_amount' => null, 'free_children' => null, 'max_children' => null]);
ok('a room set to 0% (stored 0.00) stays free even though the type charges 50%', $indPct0['source'] === 'room' && near($indPct0['percent'], 0) && rh_child_supplement($indPct0, 3, 2, 100000) === 0.0);
$indPct0Legacy = rh_child_rule($pct50, ['child_charge_mode' => null, 'child_price_multiplier' => 0, 'child_charge_amount' => null, 'free_children' => null, 'max_children' => null]);
ok('a room that kept its own 0 (no mode saved) is still a 0% override, not the room type 50%', $indPct0Legacy['source'] === 'room' && near($indPct0Legacy['percent'], 0));
$indBlank = rh_child_rule($pct50, ['child_charge_mode' => null, 'child_price_multiplier' => null, 'child_charge_amount' => null, 'free_children' => null, 'max_children' => null]);
ok('a room with every child box blank follows the room type (50%)', $indBlank['source'] === 'type' && near($indBlank['percent'], 50));
$indBlankStr = rh_child_rule($pct50, ['child_charge_mode' => '', 'child_price_multiplier' => '', 'child_charge_amount' => '', 'free_children' => '', 'max_children' => '']);
ok('empty strings from a form also mean "follow the room type"', $indBlankStr['source'] === 'type' && near($indBlankStr['percent'], 50));
$typeZeroStr = rh_child_rule(['child_price_multiplier' => '0.00', 'child_charge_mode' => 'percent', 'child_charge_amount' => null, 'free_children' => '0', 'max_children' => null]);
ok('a room type saved as 0.00 charges nothing (does not fall back to the 50% default)', near($typeZeroStr['percent'], 0) && rh_child_supplement($typeZeroStr, 2, 3, 100000) === 0.0);
$postZero = rh_child_rule_post(['child_charge_mode' => 'percent', 'child_price_multiplier' => '0.00', 'free_children' => '', 'max_children' => ''], false);
ok('room type form: 0.00 is stored as 0, not the default 50', $postZero['error'] === null && $postZero['percent'] === 0.0);
$postIndZero = rh_child_rule_post(['child_charge_mode' => 'percent', 'child_price_multiplier' => '0.00', 'free_children' => '', 'max_children' => ''], true);
ok('individual room form: 0.00 is stored as a real 0% override', $postIndZero['error'] === null && $postIndZero['mode'] === 'percent' && $postIndZero['percent'] === 0.0);
$postIndBlank = rh_child_rule_post(['child_charge_mode' => '', 'child_price_multiplier' => '', 'free_children' => '', 'max_children' => ''], true);
ok('individual room form: all blank stores nothing (follows the room type)', $postIndBlank['error'] === null && $postIndBlank['mode'] === null && $postIndBlank['percent'] === null);

// ── 3. Most children in one room ────────────────────────────────────────────
echo "== most children per room ==\n";
$max1 = rh_child_rule(['child_price_multiplier' => 50, 'child_charge_mode' => 'percent', 'max_children' => 1]);
ok('limit check accepts up to the maximum', rh_child_limit_error($max1, 1, true, 'Suite') === '');
ok('limit check refuses beyond the maximum', strpos(rh_child_limit_error($max1, 2, true, 'Suite'), 'at most 1 child per room') !== false);
ok('limit check refuses children where they are not allowed', rh_child_limit_error(rh_child_rule($pct50), 1, false, 'Suite') !== '');
ok('no limit when blank', rh_child_limit_error(rh_child_rule($pct50), 12, true, 'Suite') === '');
$roomRow = ['name' => 'Cap Test', 'max_guests' => 4, 'price_per_night' => 100000, 'price_single_occupancy' => 100000, 'price_double_occupancy' => 100000, 'price_triple_occupancy' => 100000,
    'single_occupancy_enabled' => 1, 'double_occupancy_enabled' => 1, 'triple_occupancy_enabled' => 1, 'children_allowed' => 1] + $pct50;
$planOk = ga_plan($roomRow, 3, 2);
ok('website split: 1 adult + 2 children fits a room with no limit', !empty($planOk['valid']) && (int)$planOk['allocation'][0]['children'] === 2);
$planNo = ga_plan(array_merge($roomRow, ['max_children' => 1]), 3, 2);
ok('website split: refuses 2 children when the room type takes 1', empty($planNo['valid']) && ($planNo['reason'] ?? '') === 'children_max' && strpos((string)$planNo['message'], 'at most 1 child per room') !== false, json_encode($planNo));
$planSpread = ga_plan(array_merge($roomRow, ['max_children' => 1, 'max_guests' => 2, 'triple_occupancy_enabled' => 0]), 4, 2);
ok('website split: 2 adults + 2 children over two 2-guest rooms, 1 child each, is allowed', !empty($planSpread['valid']) && (int)$planSpread['allocation'][0]['children'] === 1 && (int)$planSpread['allocation'][1]['children'] === 1, json_encode($planSpread));
$seatNo = ga_distribute_party([['capacity' => 4, 'children_allowed' => true, 'name' => 'Room', 'max_children' => 1]], 1, 2);
ok('admin seating: refuses 2 children in a room limited to 1', empty($seatNo['valid']) && strpos((string)$seatNo['message'], 'maximum children per room') !== false, json_encode($seatNo));
$seatSpread = ga_distribute_party([['capacity' => 4, 'children_allowed' => true, 'name' => 'Room', 'max_children' => 1], ['capacity' => 4, 'children_allowed' => true, 'name' => 'Room', 'max_children' => 1]], 2, 2);
ok('admin seating: 2 children go one to each of two limited rooms', !empty($seatSpread['valid']) && $seatSpread['rows'][0]['children'] === 1 && $seatSpread['rows'][1]['children'] === 1, json_encode($seatSpread));

// ── 4. Form fields are read and validated by one function ──────────────────
echo "== form fields ==\n";
$p = rh_child_rule_post(['child_charge_mode' => 'fixed', 'child_charge_amount' => '1,500', 'free_children' => '2', 'max_children' => '3', 'child_price_multiplier' => '40'], false);
ok('room type form: fixed amount, free 2, max 3, % kept', $p['error'] === null && $p['mode'] === 'fixed' && near($p['amount'], 1500) && $p['free'] === 2 && $p['max'] === 3 && near($p['percent'], 40));
$p = rh_child_rule_post(['child_charge_mode' => 'fixed', 'child_charge_amount' => '0'], false);
ok('room type form: fixed 0 is accepted (free)', $p['error'] === null && near($p['amount'], 0) && $p['free'] === 0 && $p['max'] === null);
$p = rh_child_rule_post(['child_charge_mode' => 'fixed', 'child_charge_amount' => ''], false);
ok('room type form: fixed with no amount is refused', $p['error'] !== null);
$p = rh_child_rule_post(['child_charge_mode' => 'percent', 'child_price_multiplier' => '0'], false);
ok('room type form: 0% is accepted (free)', $p['error'] === null && near($p['percent'], 0));
ok('room type form: 150% refused', rh_child_rule_post(['child_charge_mode' => 'percent', 'child_price_multiplier' => '150'], false)['error'] !== null);
ok('room type form: free children must be a whole number', rh_child_rule_post(['child_charge_mode' => 'percent', 'child_price_multiplier' => '50', 'free_children' => '1.5'], false)['error'] !== null);
$p = rh_child_rule_post(['child_charge_mode' => '', 'free_children' => '', 'max_children' => ''], true);
ok('individual room form: blank everything = inherit (all NULL)', $p['error'] === null && $p['mode'] === null && $p['percent'] === null && $p['amount'] === null && $p['free'] === null && $p['max'] === null);
$p = rh_child_rule_post(['child_charge_mode' => 'fixed', 'child_charge_amount' => '7000', 'free_children' => '0'], true);
ok('individual room form: fixed 7,000 with 0 free stores a real override', $p['error'] === null && $p['mode'] === 'fixed' && near($p['amount'], 7000) && $p['free'] === 0);

// ── 5. Edit / room change nightly rate and document wording ─────────────────
echo "== edit, room change and documents ==\n";
require_once $root . '/includes/booking-room-change.php';
$base = ['price_per_night' => 100000, 'price_single_occupancy' => 100000, 'price_double_occupancy' => 100000, 'price_triple_occupancy' => 100000];
$b0 = ['occupancy_type' => 'double', 'child_guests' => 0];
$b2 = ['occupancy_type' => 'double', 'child_guests' => 2];
$rack0 = rh_room_type_rack_night($base + $pct50, $b0);
ok('rack night: percent rule adds 50% x 2 children', near(rh_room_type_rack_night($base + $pct50, $b2) - $rack0, rh_stay_totals(100000 + 100000, 'price', false)['total_with_vat'] - rh_stay_totals(100000, 'price', false)['total_with_vat']));
$fx = $base + ['child_price_multiplier' => 50, 'child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'free_children' => 1];
ok('rack night: fixed 10,000 with 1 free, 2 children = 1 charged child', near(rh_room_type_rack_night($fx, $b2) - rh_room_type_rack_night($fx, $b0), rh_stay_totals(100000 + 10000, 'price', false)['total_with_vat'] - rh_stay_totals(100000, 'price', false)['total_with_vat']));
ok('rack night: 2 free children cost nothing extra', near(rh_room_type_rack_night($base + ['child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'free_children' => 2], $b2), $rack0));

$snapRow = ['child_guests' => 2, 'number_of_nights' => 7, 'child_price_multiplier' => 50, 'child_charge_mode' => 'percent', 'child_charge_amount' => null, 'free_children' => 2];
ok('document wording: "2 children, first 2 free"', rh_child_charge_label($snapRow, 'MWK') === '2 children, first 2 free', rh_child_charge_label($snapRow, 'MWK'));
$snapFixed = ['child_guests' => 1, 'number_of_nights' => 7, 'child_price_multiplier' => 50, 'child_charge_mode' => 'fixed', 'child_charge_amount' => 10000, 'free_children' => 0];
ok('document wording: "1 child x MWK 10,000 x 7 nights"', rh_child_charge_label($snapFixed, 'MWK') === '1 child x MWK 10,000 x 7 nights', rh_child_charge_label($snapFixed, 'MWK'));
ok('document wording: a % booking from before the change still reads sensibly', rh_child_charge_label(['child_guests' => 2, 'number_of_nights' => 3, 'child_price_multiplier' => 50], 'MWK') === '2 children x 50% of room rate x 3 nights');
ok('document wording: free children over the allowance say what is charged', rh_child_charge_label(['child_guests' => 3, 'number_of_nights' => 2, 'child_charge_mode' => 'fixed', 'child_charge_amount' => 5000, 'free_children' => 2], 'MWK') === '3 children, first 2 free: 1 child x MWK 5,000 x 2 nights');
ok('document wording: a group says the allowance is per room', strpos(rh_child_doc_label([['child_guests' => 1, 'number_of_nights' => 2, 'free_children' => 1], ['child_guests' => 2, 'number_of_nights' => 2, 'free_children' => 1]], 'MWK'), 'first 1 free in each room') !== false);

// ── 6. Admin create-booking saves what the rule says (rolled back) ─────────
echo "== admin create-booking (rolled back) ==\n";
$ci = date('Y-m-d', strtotime('+640 day'));
$co = date('Y-m-d', strtotime('+645 day'));
$nights = 5;
$type = null;
foreach ($pdo->query("SELECT r.* FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    if (roomTypeHasActiveCombinations((int)$cand['id'])) { continue; }
    $av = checkRoomAvailability((int)$cand['id'], $ci, $co);
    $nInd = (int)$pdo->query('SELECT COUNT(*) FROM individual_rooms WHERE is_active = 1 AND room_type_id = ' . (int)$cand['id'])->fetchColumn();
    if (!empty($av['available']) && (int)($av['remaining_rooms'] ?? 0) >= 2 && $nInd >= 1) { $type = $cand; break; }
}
if (!$type) {
    ok('skipped: no plain room type with free rooms and an individual room on this database (nothing to run)', true);
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}
$rid = (int)$type['id'];
$indId = (int)$pdo->query('SELECT id FROM individual_rooms WHERE is_active = 1 AND room_type_id = ' . $rid . ' ORDER BY id LIMIT 1')->fetchColumn();
$rate = (float)$type['price_per_night'];
echo "== type #$rid {$type['name']} rate $rate, individual room #$indId, $ci -> $co ($nights nights) ==\n";

function cp_setup(int $rid, string $mode, ?float $amount, float $pct, int $free, ?int $max): array {
    return ["UPDATE rooms SET max_guests = 4, triple_occupancy_enabled = 1, children_allowed = 1,
        price_single_occupancy = price_per_night, price_double_occupancy = price_per_night, price_triple_occupancy = price_per_night,
        child_price_multiplier = $pct, child_charge_mode = '$mode', child_charge_amount = " . ($amount === null ? 'NULL' : $amount) . ",
        free_children = $free, max_children = " . ($max === null ? 'NULL' : $max) . " WHERE id = $rid"];
}
/** Book 1 adult + $children children in ONE room of the type; returns [saved rows, run result]. */
function cp_book(int $rid, array $setup, int $children, string $tag, ?int $individualId = null): array {
    global $ci, $co, $pdo;
    $guests = 1 + $children;
    $occ = $guests >= 3 ? 'triple' : ($guests === 2 ? 'double' : 'single');
    $post = [
        'create_booking' => '1', 'guest_name' => 'CHILDTEST ' . $tag, 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009',
        'number_of_guests' => (string)$guests, 'child_guests' => (string)$children, 'check_in_date' => $ci, 'check_out_date' => $co,
        'booking_status' => 'confirmed',
        'room_line_room_id' => [(string)$rid], 'room_line_qty' => ['1'], 'room_line_occupancy' => [$occ], 'room_line_override' => [''],
    ];
    if ($individualId) { $post['individual_room_id'] = (string)$individualId; } else { $post['auto_assign_room'] = '1'; }
    $r = cp_run('admin/create-booking.php', $post, [
        'setup' => $setup,
        'verify' => ['SELECT id, child_guests, child_supplement_total, total_with_vat, child_price_multiplier, child_charge_mode, child_charge_amount, free_children, individual_room_id, occupancy_type FROM bookings WHERE guest_name = ' . $pdo->quote('CHILDTEST ' . $tag) . ' ORDER BY id'],
    ]);
    $rows = is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [];
    return [$rows, $r];
}
$rateNow = function (string $occ = 'triple') use ($pdo, $rid, $ci, $co, $nights, $rate): float {
    return (float)applyDynamicPricing($pdo, $rid, $ci, $co, $nights, $rate)['final_price'];
};
$expTotal = function (float $nightly, float $sup) use ($nights): float {
    return rh_stay_totals($nightly * $nights + $sup, 'price')['total_with_vat'];
};
$nightly = $rateNow();

$scen = [
    ['percent 50, 2 children',               cp_setup($rid, 'percent', null, 50, 0, null), 2, null, round($nightly * 0.5 * 2 * $nights, 2), 'percent', 0],
    ['fixed 10,000, 2 children',             cp_setup($rid, 'fixed', 10000, 50, 0, null), 2, null, 2 * 10000 * $nights * 1.0, 'fixed', 0],
    ['fixed 10,000, 1 free, 2 children',     cp_setup($rid, 'fixed', 10000, 50, 1, null), 2, null, 1 * 10000 * $nights * 1.0, 'fixed', 1],
    ['2 free, 2 children = no charge',       cp_setup($rid, 'fixed', 10000, 50, 2, null), 2, null, 0.0, 'fixed', 2],
    ['percent 0 = free',                     cp_setup($rid, 'percent', null, 0, 0, null), 2, null, 0.0, 'percent', 0],
    ['fixed 0 = free',                       cp_setup($rid, 'fixed', 0, 50, 0, null), 2, null, 0.0, 'fixed', 0],
    ['room fixed 7,000 beats type percent 50', array_merge(cp_setup($rid, 'percent', null, 50, 0, null), ["UPDATE individual_rooms SET child_charge_mode = 'fixed', child_charge_amount = 7000, free_children = 0 WHERE id = $indId"]), 2, $indId, 2 * 7000 * $nights * 1.0, 'fixed', 0],
    ['room with blank settings inherits the type', array_merge(cp_setup($rid, 'fixed', 10000, 50, 1, null), ["UPDATE individual_rooms SET child_charge_mode = NULL, child_charge_amount = NULL, free_children = NULL, max_children = NULL, child_price_multiplier = NULL WHERE id = $indId"]), 2, $indId, 1 * 10000 * $nights * 1.0, 'fixed', 1],
];
$pubRoom = array_merge($type, ['max_guests' => 4, 'triple_occupancy_enabled' => 1, 'children_allowed' => 1, 'price_single_occupancy' => $rate, 'price_double_occupancy' => $rate, 'price_triple_occupancy' => $rate]);
foreach ($scen as $i => [$label, $setup, $kids, $indRoom, $expSup, $expMode, $expFree]) {
    [$rows, $r] = cp_book($rid, $setup, $kids, 's' . $i, $indRoom);
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
    ok("$label: one booking saved", count($rows) === 1, 'saved ' . count($rows) . ' ' . json_encode($r['output_flash'] ?? []));
    if (count($rows) !== 1) { continue; }
    $row = $rows[0];
    ok("$label: child supplement saved = " . number_format($expSup, 2), near((float)$row['child_supplement_total'], $expSup, 0.011), (string)$row['child_supplement_total']);
    ok("$label: booking total = room + supplement through the shared totals", near((float)$row['total_with_vat'], $expTotal($nightly, $expSup), 0.011), $row['total_with_vat'] . ' vs ' . $expTotal($nightly, $expSup));
    ok("$label: the booking keeps its rule (mode $expMode, $expFree free)", $row['child_charge_mode'] === $expMode && (int)$row['free_children'] === $expFree, json_encode($row));
    if ($indRoom === null) {
        // The website path: same room-type row, same allocation, same helper => the same supplement.
        $typeAfter = array_merge($pubRoom, ['child_price_multiplier' => $row['child_price_multiplier'], 'child_charge_mode' => $row['child_charge_mode'], 'child_charge_amount' => $row['child_charge_amount'], 'free_children' => $row['free_children']]);
        $pub = ga_plan($typeAfter, 1 + $kids, $kids);
        $pubSup = 0.0;
        foreach ($pub['allocation'] as $part) { $pubSup += rh_child_supplement(rh_child_rule($typeAfter), (int)$part['children'], $nights, $nightly); }
        ok("$label: the website calculates the same supplement as the admin screen saved", !empty($pub['valid']) && near($pubSup, (float)$row['child_supplement_total'], 0.011), "$pubSup vs {$row['child_supplement_total']}");
    }
}

// Max children: refused by the admin screen (type limit, room limit); nothing is saved.
[$rows, $r] = cp_book($rid, cp_setup($rid, 'percent', null, 50, 0, 1), 2, 'max-type');
ok('type limit of 1 child: a booking with 2 children is refused and nothing is saved', count($rows) === 0 && !empty($r['rolled_back']), json_encode($r['output_flash'] ?? []));
ok('type limit of 1 child: the refusal says why', stripos(json_encode($r['output_flash'] ?? []), 'child') !== false, json_encode($r['output_flash'] ?? []));
[$rows, $r] = cp_book($rid, array_merge(cp_setup($rid, 'percent', null, 50, 0, null), ["UPDATE individual_rooms SET max_children = 1, child_charge_mode = NULL, child_charge_amount = NULL, free_children = NULL, child_price_multiplier = NULL WHERE id = $indId"]), 2, 'max-room', $indId);
ok('room limit of 1 child (type has none): 2 children in that room are refused', count($rows) === 0 && !empty($r['rolled_back']), json_encode($r['output_flash'] ?? []));
[$rows, $r] = cp_book($rid, cp_setup($rid, 'percent', null, 50, 0, 2), 2, 'max-ok');
ok('type limit of 2: 2 children are accepted', count($rows) === 1, json_encode($r['output_flash'] ?? []));

// Edit Booking: the booking keeps the rule it was saved with (fixed 10,000, first child free); adding a 2nd child charges 1.
$ttOld = rh_stay_totals($nightly * $nights, 'price');
$editBookingId = 990000011;
$levyPct = (float)($ttOld['levy_rate'] ?? 0);
$editSetup = array_merge(cp_setup($rid, 'percent', null, 50, 0, null), [
    // the room type changed its rule AFTER this booking was made: the booking must still price on its own saved rule
    "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
        number_of_guests, adult_guests, child_guests, occupancy_type, status, payment_status, child_price_multiplier, child_charge_mode, child_charge_amount, free_children,
        total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate, total_with_vat, amount_due, child_supplement_total)
     VALUES ($editBookingId, 'CHILDEDIT01', $rid, 'CHILDTEST edit', 'childtest@example.invalid', '+265000000009', '$ci', '$co', $nights,
        2, 1, 1, 'double', 'confirmed', 'unpaid', 50, 'fixed', 10000, 1,
        " . ($ttOld['net'] + $ttOld['levy']) . ', ' . $ttOld['vat'] . ', ' . $ttOld['levy'] . ', ' . $levyPct . ', ' . (float)$ttOld['vat_rate'] . ', ' . $ttOld['total_with_vat'] . ', ' . $ttOld['total_with_vat'] . ', 0)',
]);
$r = cp_run('admin/edit-booking.php', [
    'guest_name' => 'CHILDTEST edit', 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009', 'guest_country' => '',
    'check_in_date' => $ci, 'check_out_date' => $co, 'number_of_guests' => '3', 'child_guests' => '2', 'occupancy_type' => 'triple', 'notify_guest' => '0',
], [
    'get' => ['id' => $editBookingId], 'setup' => $editSetup,
    'verify' => ['SELECT child_guests, child_supplement_total, total_with_vat, child_charge_mode, free_children, child_charge_amount FROM bookings WHERE id = ' . $editBookingId],
]);
$eb = $r['verify'][0][0] ?? [];
ok('edit booking: rolled back, nothing left behind', empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['output_flash'] ?? null, $r['raw'] ?? null]));
ok('edit booking: 2nd child on a fixed-10,000 / 1-free booking charges exactly one child, on the booking\'s own rule though the type is now 50%',
    // Edit Booking has always stored the supplement on the rack (gross) basis: one charged child x 10,000 x nights, through the shared totals.
    (int)($eb['child_guests'] ?? 0) === 2 && near((float)($eb['child_supplement_total'] ?? -1), 10000 * $nights, 0.011), json_encode([$eb, $r['output_flash'] ?? null]));
ok('edit booking: the booking keeps its saved rule', ($eb['child_charge_mode'] ?? '') === 'fixed' && (int)($eb['free_children'] ?? -1) === 1, json_encode($eb));
$r = cp_run('admin/edit-booking.php', [
    'guest_name' => 'CHILDTEST edit', 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009', 'guest_country' => '',
    'check_in_date' => $ci, 'check_out_date' => $co, 'number_of_guests' => '3', 'child_guests' => '2', 'occupancy_type' => 'triple', 'notify_guest' => '0',
], [
    'get' => ['id' => $editBookingId], 'setup' => array_merge($editSetup, ["UPDATE rooms SET max_children = 1 WHERE id = $rid"]),
    'verify' => ['SELECT child_guests FROM bookings WHERE id = ' . $editBookingId],
]);
ok('edit booking: a type limit of 1 child refuses the 2nd child and saves nothing', (int)($r['verify'][0][0]['child_guests'] ?? -1) === 1, json_encode([$r['verify'] ?? null, $r['output_flash'] ?? null]));

// ── Accounting review fixes ─────────────────────────────────────────────────
echo "== edit booking stores the supplement on the price basis (both VAT modes) ==\n";
$eci = date('Y-m-d', strtotime('+660 day'));
$eco2 = date('Y-m-d', strtotime('+662 day'));
$eco3 = date('Y-m-d', strtotime('+663 day'));
$rate100 = ["UPDATE rooms SET price_per_night = 100000, price_single_occupancy = 100000, price_double_occupancy = 100000 WHERE id = $rid"];
foreach (['inclusive', 'exclusive'] as $vm) {
    if ($vm === 'inclusive') {
        $old = rh_stay_totals(300000, 'price');           // live settings: VAT inclusive 17.5%, levy 1%
        $oldRow = [$old['net'] + $old['levy'], $old['vat'], $old['levy'], $old['total_with_vat']];
        $expTotal = rh_stay_totals(450000, 'price')['total_with_vat'];
        $expVat = 0.0;
    } else {
        $oldRow = [303000, 52500, 3000, 355500];
        $expTotal = 533250.00;
        $expVat = 78750.00;
    }
    $bid = 990000020 + ($vm === 'inclusive' ? 0 : 1);
    $setup = array_merge(cp_setup($rid, 'percent', null, 50, 0, null), $rate100, [
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_pricing_mode', '$vm') ON DUPLICATE KEY UPDATE setting_value = '$vm'",
    ], $vm === 'exclusive' ? [
        // The exclusive figures above assume VAT 17.5% + levy 1% on, whatever this hotel uses.
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled', '1') ON DUPLICATE KEY UPDATE setting_value = '1'",
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_rate', '17.5') ON DUPLICATE KEY UPDATE setting_value = '17.5'",
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('tourism_levy_enabled', '1') ON DUPLICATE KEY UPDATE setting_value = '1'",
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('tourism_levy_percent', '1') ON DUPLICATE KEY UPDATE setting_value = '1'",
    ] : [], [
        "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
            number_of_guests, adult_guests, child_guests, occupancy_type, status, payment_status, child_price_multiplier, child_charge_mode, child_charge_amount, free_children,
            total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate, total_with_vat, amount_due, child_supplement_total)
         VALUES ($bid, 'CHILDVAT$bid', $rid, 'CHILDTEST vat', 'childtest@example.invalid', '+265000000009', '$eci', '$eco2', 2, 2, 1, 1, 'double', 'confirmed', 'unpaid', 50, 'percent', NULL, 0,
            {$oldRow[0]}, {$oldRow[1]}, {$oldRow[2]}, 1, 17.5, {$oldRow[3]}, {$oldRow[3]}, 100000)",
    ]);
    $r = cp_run('admin/edit-booking.php', [
        'guest_name' => 'CHILDTEST vat', 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009', 'guest_country' => '',
        'check_in_date' => $eci, 'check_out_date' => $eco3, 'number_of_guests' => '2', 'child_guests' => '1', 'occupancy_type' => 'double', 'notify_guest' => '0',
    ], ['get' => ['id' => $bid], 'setup' => $setup,
        'verify' => ["SELECT child_supplement_total, total_amount, vat_amount, tourism_levy_amount, total_with_vat FROM bookings WHERE id = $bid"]]);
    $v = $r['verify'][0][0] ?? [];
    ok("$vm VAT: edit 2 -> 3 nights, harness rolled back", empty($r['leak']) && !empty($r['rolled_back']), json_encode([$r['output_flash'] ?? null, $r['raw'] ?? null]));
    ok("$vm VAT: child supplement is 150,000 (price basis, not the gross rack figure)", near((float)($v['child_supplement_total'] ?? -1), 150000, 0.05), json_encode($v));
    ok("$vm VAT: booking total unchanged by the basis fix (" . number_format($expTotal, 2) . ')', near((float)($v['total_with_vat'] ?? -1), $expTotal, 0.05), json_encode($v));
    // What the documents print: room line = gross - levy (- VAT when exclusive) - child
    $lines = (float)$v['total_with_vat'] - (float)$v['tourism_levy_amount'] - ($vm === 'exclusive' ? (float)$v['vat_amount'] : 0.0);
    // Owner 2026-10-24: inclusive price is all-in, so the levy is carved out of the 300,000 room share (was levy on top).
    $expRoomLine = $vm === 'inclusive' ? 300000 - (float)$v['tourism_levy_amount'] : 300000;
    ok("$vm VAT: documents' room line is " . number_format($expRoomLine, 2) . ' (lines ' . number_format($lines, 2) . ' - child 150,000)', near($lines - (float)$v['child_supplement_total'], $expRoomLine, 0.1), json_encode($v));
}

echo "== room type change keeps the child rule unless the whole stay is re-priced ==\n";
// Another active room type than the test's own (ids differ per hotel).
$typeB = (int)$pdo->query('SELECT id FROM rooms WHERE is_active = 1 AND id <> ' . (int)$rid . ' ORDER BY id LIMIT 1')->fetchColumn();
$bSetup = array_merge(cp_setup($rid, 'percent', null, 50, 0, null), $rate100, [
    "UPDATE rooms SET children_allowed = 1, max_guests = 4, price_per_night = 200000, price_single_occupancy = 200000, price_double_occupancy = 200000, triple_occupancy_enabled = 1, price_triple_occupancy = 200000,
        child_charge_mode = 'fixed', child_charge_amount = 20000, free_children = 0, max_children = NULL, child_price_multiplier = 50 WHERE id = $typeB",
    "UPDATE individual_rooms SET status = 'available', housekeeping_status = 'completed' WHERE room_type_id = $typeB",
]);
$oldB = rh_stay_totals(300000, 'price');
$mkB = function (int $bid, string $status, string $in, string $out, int $nights) use ($rid, $oldB): string {
    return "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
            number_of_guests, adult_guests, child_guests, occupancy_type, status, payment_status, child_price_multiplier, child_charge_mode, child_charge_amount, free_children,
            total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate, total_with_vat, amount_due, child_supplement_total)
         VALUES ($bid, 'CHILDRC$bid', $rid, 'CHILDTEST rc', 'childtest@example.invalid', '+265000000009', '$in', '$out', $nights, 2, 1, 1, 'double', '$status', 'unpaid', 50, 'percent', NULL, 0,
            " . ($oldB['net'] + $oldB['levy']) . ', ' . $oldB['vat'] . ', ' . $oldB['levy'] . ', 1, ' . $oldB['vat_rate'] . ', ' . $oldB['total_with_vat'] . ', ' . $oldB['total_with_vat'] . ', 100000)';
};
$rcci = date('Y-m-d', strtotime('+670 day'));
$rcco = date('Y-m-d', strtotime('+672 day'));
$yest = date('Y-m-d', strtotime('-1 day'));
$plus1 = date('Y-m-d', strtotime('+1 day'));
foreach ([
    ['charge, whole stay moves', 990000030, 'confirmed', $rcci, $rcco, 2, 'charge', 'fixed', true],
    ['keep (complimentary)',      990000031, 'confirmed', $rcci, $rcco, 2, 'keep',   'percent', false],
    ['in-house, only later nights re-rated', 990000032, 'checked-in', $yest, $plus1, 2, 'charge', 'percent', false],
] as [$label, $bid, $st, $in, $out, $n, $pricing, $expMode, $expMoved]) {
    $r = cp_run('admin/bookings.php', ['action' => 'upgrade_room_type', 'booking_id' => (string)$bid, 'new_room_id' => (string)$typeB, 'pricing' => $pricing, 'reason_code' => $pricing === 'keep' ? 'upgrade_loyalty' : 'upgrade_guest_request', 'note' => 'Guest asked at the desk, manager approved'],
        ['ajax' => true, 'setup' => array_merge($bSetup, [$mkB($bid, $st, $in, $out, $n)]),
         'verify' => ["SELECT room_id, child_charge_mode, child_charge_amount, free_children, child_supplement_total FROM bookings WHERE id = $bid"]]);
    $v = $r['verify'][0][0] ?? [];
    $moved = (int)($v['room_id'] ?? 0) === $typeB;
    ok("room change ($label): rolled back and the booking moved", !empty($r['rolled_back']) && empty($r['leak']) && $moved, json_encode([$r['json'] ?? $r['output_flash'] ?? null, $v]));
    ok("room change ($label): child rule snapshot " . ($expMoved ? 'becomes the new type\'s (fixed 20,000)' : 'stays the booking\'s own (percent 50)'),
        ($v['child_charge_mode'] ?? '') === $expMode && ($expMoved ? near((float)($v['child_charge_amount'] ?? 0), 20000) : ($v['child_charge_amount'] ?? null) === null), json_encode($v));
}

echo "== max children also checked when the room changes (edit booking) ==\n";
foreach ([['limit 1 refuses', 1, 0], ['limit 2 accepts', 2, 1]] as [$label, $lim, $expMoved]) {
    $bid = 990000040 + $lim;
    $r = cp_run('admin/edit-booking.php', [
        'guest_name' => 'CHILDTEST rm', 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009', 'guest_country' => '',
        'check_in_date' => $rcci, 'check_out_date' => $rcco, 'number_of_guests' => '3', 'child_guests' => '2', 'occupancy_type' => 'triple', 'individual_room_id' => (string)$indId, 'notify_guest' => '0',
    ], ['get' => ['id' => $bid], 'setup' => array_merge(cp_setup($rid, 'percent', null, 50, 0, null), $rate100, [
        "UPDATE individual_rooms SET max_children = $lim, child_charge_mode = NULL, child_charge_amount = NULL, free_children = NULL, child_price_multiplier = NULL WHERE id = $indId",
        str_replace("'double'", "'triple'", str_replace('2, 1, 1,', '3, 1, 2,', $mkB($bid, 'confirmed', $rcci, $rcco, 2))),
    ]), 'verify' => ["SELECT individual_room_id FROM bookings WHERE id = $bid"]]);
    $got = (int)($r['verify'][0][0]['individual_room_id'] ?? 0);
    ok("moving a booking with 2 children to a room: $label", $expMoved ? $got === $indId : $got === 0, json_encode([$r['verify'] ?? null, $r['output_flash'] ?? null]));
}

echo "== documents: charged children, mixed groups, manager override ==\n";
$row = fn(int $kids, int $free, string $mode = 'fixed', float $amt = 5000) => ['child_guests' => $kids, 'number_of_nights' => 2, 'child_price_multiplier' => 50, 'child_charge_mode' => $mode, 'child_charge_amount' => $amt, 'free_children' => $free];
ok('invoice quantity: 3 children with 2 free = 1 paying child', rh_child_doc_charged([$row(3, 2)]) === 1);
ok('invoice quantity: group of two rooms, each with 1 free: 3 + 2 children = 3 paying', rh_child_doc_charged([$row(3, 1), $row(2, 1)]) === 3);
ok('invoice quantity: all free = 0 paying', rh_child_doc_charged([$row(2, 2)]) === 0);
ok('group label when the rooms charge differently is neutral', rh_child_doc_label([$row(1, 0), $row(2, 1, 'percent')], 'MWK') === '3 children in 2 rooms', rh_child_doc_label([$row(1, 0), $row(2, 1, 'percent')], 'MWK'));
ok('group label when the rooms charge the same keeps the rule', strpos(rh_child_doc_label([$row(1, 1), $row(2, 1)], 'MWK'), 'first 1 free in each room') !== false);
$ovrSetup = cp_setup($rid, 'fixed', 10000, 50, 2, null);
$r = cp_run('admin/create-booking.php', [
    'create_booking' => '1', 'guest_name' => 'CHILDTEST ovr', 'guest_email' => 'childtest@example.invalid', 'guest_phone' => '+265000000009',
    'number_of_guests' => '3', 'child_guests' => '2', 'check_in_date' => $ci, 'check_out_date' => $co, 'booking_status' => 'confirmed', 'auto_assign_room' => '1',
    'room_line_room_id' => [(string)$rid], 'room_line_qty' => ['1'], 'room_line_occupancy' => ['triple'], 'room_line_override' => ['1500000'],
], ['setup' => $ovrSetup, 'verify' => ["SELECT child_supplement_total, free_children, child_charge_mode FROM bookings WHERE guest_name = 'CHILDTEST ovr'"]]);
$v = $r['verify'][0][0] ?? [];
ok('manager override: nothing separate for children, and no "first N free" allowance saved to print at 0', $v && near((float)$v['child_supplement_total'], 0) && (int)$v['free_children'] === 0, json_encode([$v, $r['output_flash'] ?? null]));

$leftover = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE guest_name LIKE 'CHILDTEST%'")->fetchColumn();
ok('no test booking was left behind', $leftover === 0, (string)$leftover);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
