<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Step 8h (owner 2026-11-15): one rate plan with a different % or fixed amount per room type, and a price basis
 * (all-in price vs base rate before VAT/levy).
 *
 * Usage (project root):
 *   php scripts/tests/rate_plan_room_adjustments.php
 *       PURE: loads includes/pricing.php only (settings are stubbed). Never opens a database. Safe in either tree.
 *   php scripts/tests/rate_plan_room_adjustments.php --db=<path to the TEST hotel root (Liwonde)>
 *       adds the database part against THAT hotel's .env: migration 086 present, a plan with own amounts saved and
 *       read back inside one transaction that is rolled back (nothing kept), and every live plan priced the same as
 *       the pre-8h rule. Refuses to run against this tree's own .env (Rosalyn's is live).
 */
$root = dirname(__DIR__, 2);
chdir($root);

if (!defined('BALANCE_TOLERANCE')) { define('BALANCE_TOLERANCE', 0.01); }
$GLOBALS['_SITE_SETTINGS'] = [];
if (!function_exists('getSetting')) {
    function getSetting(string $key, $default = '') { return $GLOBALS['_SITE_SETTINGS'][$key] ?? $default; }
}
if (!function_exists('vat_mode')) {
    function vat_mode(): string {
        $s = $GLOBALS['_SITE_SETTINGS'];
        if (empty($s['vat_enabled']) || (float)($s['vat_rate'] ?? 0) <= 0) { return 'off'; }
        return ($s['vat_pricing_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    }
}
require_once $root . '/includes/pricing.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ': ' . substr($detail, 0, 800) : '') . "\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }
function setTax(float $vat, float $levy): void {
    $GLOBALS['_SITE_SETTINGS'] = ['vat_enabled' => $vat > 0 ? '1' : '0', 'vat_rate' => (string)$vat, 'vat_pricing_mode' => 'inclusive',
        'tourism_levy_enabled' => $levy > 0 ? '1' : '0', 'tourism_levy_percent' => (string)$levy];
}

/** A rate_plans row shaped like the database returns it (strings). */
function p8(int $id, string $name, float $value, array $extra = []): array {
    return array_merge(['id' => (string)$id, 'name' => $name, 'description' => null, 'rule_type' => 'promotion', 'start_date' => null, 'end_date' => null,
        'days_of_week' => null, 'min_nights' => null, 'max_nights' => null, 'days_before_min' => null, 'days_before_max' => null,
        'adjustment_type' => 'percentage', 'adjustment_value' => number_format($value, 2, '.', ''), 'applies_to' => 'all', 'room_type_ids' => null,
        'priority' => '0', 'is_stacking' => '0', 'is_active' => '1'], $extra);
}

/** The rate plan rule BEFORE step 8h (RP-3, includes/pricing.php at commit 7541672), frozen as the baseline. */
function p8_legacy_apply(array $plans, int $room_id, string $check_in, string $check_out, int $nights, float $base_price, DateTime $today): array {
    $empty = ['final_price' => $base_price, 'original_price' => $base_price, 'discount_amount' => 0.0, 'rate_plan_id' => null, 'rate_plan_label' => '', 'rate_plan_row' => null];
    if ($nights < 1 || $base_price <= 0 || empty($plans)) { return $empty; }
    $in = new DateTime($check_in); $out = new DateTime($check_out);
    $days = (int)$today->diff($in)->days; if ($in < $today) { $days = 0; }
    $sur = []; $dis = []; $order = [];
    foreach (array_values($plans) as $plan) {
        if (!_planAppliesToRoom($plan, $room_id) || !_planMatchesStay($plan, $in, $out, $nights, $days)) { continue; }
        $v = (float)($plan['adjustment_value'] ?? 0);
        if ($v > 0) { $sur[] = $plan; } elseif ($v < 0) { $dis[] = $plan; } else { continue; }
        $order[] = $plan;
    }
    if (!$sur && !$dis) { return $empty; }
    $pick = static function (array $g, float $price, bool $isD): array {
        if (!$g) { return []; }
        $non = false; foreach ($g as $p) { if (empty($p['is_stacking'])) { $non = true; break; } }
        if (!$non) { return $g; }
        $best = null; $bp = null;
        foreach ($g as $p) { $c = applyRatePlanToPrice($p, $price); if ($bp === null || ($isD && $c < $bp - 0.00001) || (!$isD && $c > $bp + 0.00001)) { $bp = $c; $best = $p; } }
        return [$best];
    };
    $cur = $base_price; $appl = [];
    foreach ([[$sur, false], [$dis, true]] as [$g, $isD]) { foreach ($pick($g, $cur, $isD) as $p) { $cur = applyRatePlanToPrice($p, $cur); $appl[] = $p; } }
    $applied = []; foreach ($order as $o) { foreach ($appl as $p) { if ($p === $o) { $applied[] = $o; break; } } }
    $labels = []; $ids = []; foreach ($applied as $p) { $labels[] = (string)$p['name']; $ids[] = (int)$p['id']; }
    $label = implode(', ', $labels); if (mb_strlen($label) > 120) { $label = rtrim(mb_substr($label, 0, 120)); }
    return ['final_price' => round($cur, 2), 'original_price' => $base_price, 'discount_amount' => round($base_price - $cur, 2),
        'rate_plan_id' => (int)$applied[0]['id'], 'rate_plan_label' => $label, 'rate_plan_ids' => $ids, 'rate_plan_row' => $applied[0]];
}

$today = new DateTime('2026-11-15');
$ci = '2026-12-01'; $co = '2026-12-03';
setTax(16.5, 1.0); // F = 1.175

echo "== existing plans (no own amounts, basis missing = before migration 086) price exactly as before ==\n";
$legacySets = [
    [p8(1, 'Promo -10', -10)],
    [p8(2, 'Fixed -5000', -5000, ['adjustment_type' => 'fixed'])],
    [p8(3, 'Sur +20', 20), p8(4, 'Disc -15', -15), p8(5, 'Fixed +7000', 7000, ['adjustment_type' => 'fixed', 'is_stacking' => '1'])],
    [p8(6, 'Deluxe only', 18.4, ['applies_to' => 'room_types', 'room_type_ids' => '[2]']), p8(7, 'Std', -12.5, ['applies_to' => 'room_types', 'room_type_ids' => '["1"]'])],
    [p8(8, 'Huge', -500000, ['adjustment_type' => 'fixed'])],
];
$same = true; $why = '';
foreach ($legacySets as $set) {
    foreach ([1, 2, 3] as $rt) {
        foreach ([100000.0, 85000.5, 1234.0] as $base) {
            $new = rh_apply_rate_plans($set, $rt, $ci, $co, 2, $base, $today);
            $old = p8_legacy_apply($set, $rt, $ci, $co, 2, $base, $today);
            if ($new !== $old) { $same = false; $why = json_encode([$rt, $base, $new['final_price'], $old['final_price']]); }
        }
    }
}
ok('legacy plans: rh_apply_rate_plans identical to the pre-8h rule (every key)', $same, $why);
$withCols = array_map(static fn($p) => $p + ['room_adjustments' => null, 'price_basis' => 'all_in'], $legacySets[2]);
ok('after 086 with NULL own amounts + all_in: still identical', rh_apply_rate_plans($withCols, 1, $ci, $co, 2, 100000.0, $today)['final_price'] === p8_legacy_apply($legacySets[2], 1, $ci, $co, 2, 100000.0, $today)['final_price']);
ok('missing-086 fallback: plan without the new keys = default amount, all-in', rh_rate_plan_effective(p8(9, 'x', -10), 3) === ['type' => 'percentage', 'value' => -10.0, 'basis' => 'all_in', 'override' => false]);
ok('legacy applyRatePlanToPrice fixed path unrounded (P + v)', abs(applyRatePlanToPrice(['adjustment_type' => 'fixed', 'adjustment_value' => '0.333'], 100.0) - 100.333) < 1e-9);

echo "== per-room-type amounts ==\n";
$ov = json_encode(['2' => ['type' => 'percentage', 'value' => 25], '3' => ['type' => 'fixed', 'value' => 15000]]);
$plan = p8(20, 'Festive', 10, ['room_adjustments' => $ov, 'price_basis' => 'all_in']);
ok('per-room %: Deluxe (2) +25% -> 125,000', rh_apply_rate_plans([$plan], 2, $ci, $co, 2, 100000.0, $today)['final_price'] === 125000.0);
ok('per-room fixed all-in: Suite (3) +15,000 -> 215,000', rh_apply_rate_plans([$plan], 3, $ci, $co, 2, 200000.0, $today)['final_price'] === 215000.0);
ok('applies_to all + override: Standard (1) uses default +10% -> 110,000', rh_apply_rate_plans([$plan], 1, $ci, $co, 2, 100000.0, $today)['final_price'] === 110000.0);
ok('applies_to all: a room type added later (99) uses the default', rh_apply_rate_plans([$plan], 99, $ci, $co, 2, 50000.0, $today)['final_price'] === 55000.0);
$planRt = p8(21, 'Selected', -10, ['applies_to' => 'room_types', 'room_type_ids' => '[2,3]', 'room_adjustments' => json_encode(['3' => ['type' => 'percentage', 'value' => -20]])]);
ok('applies_to room_types: uncovered room 1 unchanged', rh_apply_rate_plans([$planRt], 1, $ci, $co, 2, 100000.0, $today)['rate_plan_id'] === null);
ok('applies_to room_types: room 2 default -10%, room 3 own -20%', rh_apply_rate_plans([$planRt], 2, $ci, $co, 2, 100000.0, $today)['final_price'] === 90000.0
    && rh_apply_rate_plans([$planRt], 3, $ci, $co, 2, 100000.0, $today)['final_price'] === 80000.0);
ok('bad JSON / zero / bad type rows are ignored (default used)', rh_rate_plan_effective(p8(22, 'b', 5, ['room_adjustments' => '{"1":{"type":"x","value":9},"2":{"type":"fixed","value":0}']), 1)['value'] === 5.0
    && rh_rate_plan_effective(p8(22, 'b', 5, ['room_adjustments' => 'not json']), 2)['value'] === 5.0);

echo "== price basis ==\n";
$F = rh_rate_plan_tax_factor();
ok('tax factor = 1 + VAT + levy (1.175)', near($F, 1.175));
$fixBase = p8(30, 'Fix base', 10000, ['adjustment_type' => 'fixed', 'price_basis' => 'base']);
$fixAll = p8(31, 'Fix all', 10000, ['adjustment_type' => 'fixed', 'price_basis' => 'all_in']);
ok('fixed on base: +10,000 before tax costs 11,750 -> 111,750', rh_apply_rate_plans([$fixBase], 1, $ci, $co, 2, 100000.0, $today)['final_price'] === 111750.0);
ok('fixed on all-in: +10,000 -> 110,000', rh_apply_rate_plans([$fixAll], 1, $ci, $co, 2, 100000.0, $today)['final_price'] === 110000.0);
$bd = rh_rate_plan_breakdown($fixBase, 1, 100000.0);
ok('fixed on base: the new base is the old base + 10,000 (within a cent)', near($bd['after']['base'] - $bd['before']['base'], 10000.0), json_encode($bd));
ok('breakdown parts add up to the all-in prices', near($bd['before']['base'] + $bd['before']['vat'] + $bd['before']['levy'], 100000.0) && near($bd['after']['base'] + $bd['after']['vat'] + $bd['after']['levy'], 111750.0));
ok('breakdown change: up by 11,750 (11.75%)', near($bd['diff'], 11750.0) && near((float)$bd['pct'], 11.75));
$pctBase = p8(32, 'Pct base', 12.5, ['price_basis' => 'base']);
$pctAll = p8(33, 'Pct all', 12.5);
ok('a percentage gives the same guest total on either basis', rh_rate_plan_price($pctBase, 1, 86543.21) === rh_rate_plan_price($pctAll, 1, 86543.21));
ok('fixed discount on base: -10,000 before tax takes 11,750 off', rh_rate_plan_price(p8(34, 'd', -10000, ['adjustment_type' => 'fixed', 'price_basis' => 'base']), 1, 100000.0) === 88250.0);
ok('never below 0', rh_rate_plan_price(p8(35, 'd', -90000, ['adjustment_type' => 'fixed', 'price_basis' => 'base']), 1, 100000.0) === 0.0);
setTax(0, 0);
ok('VAT and levy off: factor 1, basis choice does not matter', near(rh_rate_plan_tax_factor(), 1.0) && !rh_rate_plan_basis_matters());
ok('VAT and levy off: fixed on base = fixed on all-in', near(rh_rate_plan_price($fixBase, 1, 100000.0), rh_rate_plan_price($fixAll, 1, 100000.0)));
setTax(16.5, 0);
ok('VAT only: factor 1.165', near(rh_rate_plan_tax_factor(), 1.165));
setTax(16.5, 1.0);

echo "== direction + best pick per room type ==\n";
$a = p8(40, 'A', -10, ['room_adjustments' => json_encode(['2' => ['type' => 'percentage', 'value' => -30]])]);
$b = p8(41, 'B', -20);
$r1 = rh_apply_rate_plans([$a, $b], 1, $ci, $co, 2, 100000.0, $today);
$r2 = rh_apply_rate_plans([$a, $b], 2, $ci, $co, 2, 100000.0, $today);
ok('non-stacking: room 1 takes B (-20% beats -10%)', $r1['rate_plan_id'] === 41 && $r1['final_price'] === 80000.0, json_encode($r1['rate_plan_id']));
ok('non-stacking: room 2 takes A (its own -30% beats -20%)', $r2['rate_plan_id'] === 40 && $r2['final_price'] === 70000.0, json_encode($r2['rate_plan_id']));
$s1 = p8(42, 'S1', 5, ['room_adjustments' => json_encode(['2' => ['type' => 'fixed', 'value' => 20000]])]);
$s2 = p8(43, 'S2', 10);
ok('surcharges: room 2 takes S1 (+20,000 beats +10%), room 1 takes S2', rh_apply_rate_plans([$s1, $s2], 2, $ci, $co, 2, 100000.0, $today)['rate_plan_id'] === 42
    && rh_apply_rate_plans([$s1, $s2], 1, $ci, $co, 2, 100000.0, $today)['rate_plan_id'] === 43);
$stack = [p8(44, 'St1', -10, ['is_stacking' => '1', 'room_adjustments' => json_encode(['2' => ['type' => 'percentage', 'value' => -50]])]), p8(45, 'St2', -10, ['is_stacking' => '1'])];
ok('stacking: both apply with each room type\'s own amount (room 2: 100,000 x 0.5 x 0.9 = 45,000)', rh_apply_rate_plans($stack, 2, $ci, $co, 2, 100000.0, $today)['final_price'] === 45000.0);
ok('surcharge then discount order unchanged across own amounts', rh_apply_rate_plans([$s1, $a], 2, $ci, $co, 2, 100000.0, $today)['final_price'] === 84000.0);

echo "== save checks (rh_rate_plan_validate_rooms) ==\n";
$rooms = [1 => ['name' => 'Standard', 'price' => 80000.0], 2 => ['name' => 'Deluxe', 'price' => 120000.0], 3 => ['name' => 'Suite', 'price' => 9000.0]];
$v = rh_rate_plan_validate_rooms('percentage', 10, 'per_room', [2 => ['type' => 'percentage', 'value' => '25'], 3 => ['type' => 'fixed', 'value' => '']], null, $rooms, 'base', 1.175);
ok('valid per-room: blank row dropped, own amount kept, basis base kept', $v['error'] === '' && $v['overrides'] === [2 => ['type' => 'percentage', 'value' => 25.0]] && $v['basis'] === 'base', json_encode($v));
$v = rh_rate_plan_validate_rooms('percentage', 10, 'same', [2 => ['type' => 'percentage', 'value' => '25']], null, $rooms, 'all_in', 1.175);
ok('"Same for all" saves no own amounts (NULL)', $v['error'] === '' && $v['overrides'] === null);
$v = rh_rate_plan_validate_rooms('percentage', 10, 'per_room', [2 => ['type' => 'percentage', 'value' => '-5']], null, $rooms, 'all_in', 1.175);
ok('mixed signs refused (default up, Deluxe down)', $v['error'] !== '' && stripos($v['error'], 'Deluxe') !== false, $v['error']);
$v = rh_rate_plan_validate_rooms('percentage', 10, 'per_room', [2 => ['type' => 'fixed', 'value' => '0']], null, $rooms, 'all_in', 1.175);
ok('own amount 0 refused', $v['error'] !== '');
$v = rh_rate_plan_validate_rooms('percentage', 10, 'per_room', [2 => ['type' => 'percentage', 'value' => '1001']], null, $rooms, 'all_in', 1.175);
ok('own % above 1000 refused', $v['error'] !== '');
$v = rh_rate_plan_validate_rooms('percentage', -10, 'per_room', [3 => ['type' => 'fixed', 'value' => '-9000']], null, $rooms, 'all_in', 1.175);
ok('fixed discount at a room type\'s price refused (Suite 9,000)', $v['error'] !== '' && stripos($v['error'], 'Suite') !== false, $v['error']);
$v = rh_rate_plan_validate_rooms('percentage', -10, 'per_room', [3 => ['type' => 'fixed', 'value' => '-8000']], null, $rooms, 'base', 1.175);
ok('fixed discount on base x F reaching the price refused (8,000 x 1.175 = 9,400)', $v['error'] !== '');
$v = rh_rate_plan_validate_rooms('fixed', -9500, 'same', [], null, $rooms, 'all_in', 1.175);
ok('default fixed discount at or above a room type\'s price refused', $v['error'] !== '' && stripos($v['error'], 'Suite') !== false);
$v = rh_rate_plan_validate_rooms('percentage', 10, 'per_room', [2 => ['type' => 'percentage', 'value' => '25'], 1 => ['type' => 'percentage', 'value' => '30']], [2], $rooms, 'all_in', 1.175);
ok('room_types: own amounts outside the chosen room types are dropped', $v['error'] === '' && array_keys((array)$v['overrides']) === [2]);
$v = rh_rate_plan_validate_rooms('fixed', 500, 'same', [], null, $rooms, 'base', 1.0);
ok('VAT and levy off: basis saved as all_in', $v['basis'] === 'all_in');

echo "== history text ==\n";
$h = rh_rate_plan_room_hist_text($plan, 2, 100000.0, 1.175, 'MWK');
ok('history line: "+25% (100,000 -> 125,000)"', $h === '+25% (100,000 -> 125,000)', $h);
$h = rh_rate_plan_room_hist_text($fixBase, 1, 100000.0, 1.175, 'MWK');
ok('history line fixed on base, under 60 characters', $h === '+MWK 10,000 before VAT/levy (100,000 -> 111,750)' && strlen($h) < 60, $h);
ok('history line empty when the room type is not covered', rh_rate_plan_room_hist_text($planRt, 1, 100000.0) === '');

echo "== New Booking page JS mirrors the server ==\n";
$cb = (string)@file_get_contents($root . '/admin/create-booking.php');
$js = '';
if (preg_match('/function ratePlanEffective\(plan, roomId\) \{.*?\r?\n        \}\r?\n/s', $cb, $m1) && preg_match('/function applyRatePlanToPrice\(plan, price, roomId\) \{.*?\r?\n        \}\r?\n/s', $cb, $m2)) {
    $js = $m1[0] . $m2[0];
}
$node = trim((string)@shell_exec('node --version 2>&1'));
if ($js === '' || strpos($node, 'v') !== 0) {
    ok('JS functions found and node available', false, $js === '' ? 'functions not found' : 'node missing');
} else {
    $cases = [[$plan, 2, 100000.0], [$plan, 3, 200000.0], [$plan, 1, 100000.0], [$fixBase, 1, 100000.0], [$fixAll, 1, 99999.99],
        [$a, 2, 123456.0], [p8(50, 'n', -2500.5, ['adjustment_type' => 'fixed', 'price_basis' => 'base']), 1, 86000.0]];
    $jsPlans = [];
    $expect = [];
    foreach ($cases as [$p, $rid, $price]) {
        $jsPlans[] = ['plan' => ['adjustment_type' => $p['adjustment_type'], 'adjustment_value' => (float)$p['adjustment_value'],
            'room_adjustments' => (object)rh_rate_plan_overrides($p), 'price_basis' => ($p['price_basis'] ?? 'all_in') === 'base' ? 'base' : 'all_in'], 'room' => $rid, 'price' => $price];
        $expect[] = rh_rate_plan_price($p, $rid, $price, 1.175);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rp8h') . '.js';
    file_put_contents($tmp, "const ratePlanTaxFactor = 1.175;\n" . $js . "\nconst cases = " . json_encode($jsPlans) . ";\nconsole.log(JSON.stringify(cases.map(c => applyRatePlanToPrice(c.plan, c.price, c.room))));\n");
    $got = json_decode((string)shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1'), true);
    @unlink($tmp);
    $match = is_array($got) && count($got) === count($expect);
    if ($match) { foreach ($expect as $i => $e) { if (abs((float)$got[$i] - $e) > 0.005) { $match = false; } } }
    ok('New Booking JS price = server price for 7 cases', $match, json_encode([$got, $expect]));
}

/* ── Database part (TEST hotel only) ───────────────────────────────────────────────────────────────────────────── */
$dbArg = null;
foreach ($argv as $arg) { if (strpos($arg, '--db=') === 0) { $dbArg = substr($arg, 5); } }
if ($dbArg !== null) {
    echo "== database (rolled back) ==\n";
    $target = realpath($dbArg);
    if ($target === false || !is_readable($target . '/.env')) {
        ok('--db path has a .env', false, (string)$dbArg);
    } elseif (realpath($root) === $target || stripos($target, 'Rosalyn') !== false) {
        ok('refused: the database part never runs against Rosalyn\'s', false);
    } else {
        $cfg = ['DB_HOST' => '', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASS' => '', 'DB_PORT' => '3306'];
        foreach (file($target . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
            [$k, $val] = explode('=', $line, 2);
            if (array_key_exists(trim($k), $cfg)) { $cfg[trim($k)] = trim(trim($val), "\"'"); }
        }
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['DB_HOST'], $cfg['DB_PORT'] ?: '3306', $cfg['DB_NAME']),
            $cfg['DB_USER'], $cfg['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        echo "   database: " . $cfg['DB_NAME'] . "\n";
        ok('migration 086 present (rh_rate_plans_086_ready)', rh_rate_plans_086_ready($pdo));
        $GLOBALS['rh_rate_plans_086_override'] = false;
        ok('readiness switch can force the pre-086 path', rh_rate_plans_086_ready($pdo) === false);
        $GLOBALS['rh_rate_plans_086_override'] = null;
        $col = $pdo->query("SELECT COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_plans' AND COLUMN_NAME = 'price_basis'")->fetch();
        ok('price_basis NOT NULL, default all_in', $col && $col['IS_NULLABLE'] === 'NO' && trim((string)$col['COLUMN_DEFAULT'], "'") === 'all_in', json_encode($col));
        $live = $pdo->query("SELECT * FROM rate_plans ORDER BY priority DESC, id ASC")->fetchAll();
        $allNull = true; foreach ($live as $p) { if ($p['room_adjustments'] !== null || $p['price_basis'] !== 'all_in') { $allNull = false; } }
        ok('existing plans: no own amounts, basis all_in after the migration (' . count($live) . ' plans)', $allNull);
        $roomIds = array_map('intval', $pdo->query("SELECT id FROM rooms ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_COLUMN));
        $same = true; $why = '';
        $legacyRows = array_map(static function ($p) { unset($p['room_adjustments'], $p['price_basis']); return $p; }, $live);
        foreach ($roomIds as $rid) {
            foreach ([['2026-12-20', '2026-12-23', 3], ['2027-01-02', '2027-01-03', 1], ['2027-03-05', '2027-03-12', 7]] as [$i, $o, $n]) {
                $new = rh_apply_rate_plans($live, $rid, $i, $o, $n, 100000.0, $today);
                $old = p8_legacy_apply($legacyRows, $rid, $i, $o, $n, 100000.0, $today);
                if ($new['final_price'] !== $old['final_price'] || $new['rate_plan_label'] !== $old['rate_plan_label']) { $same = false; $why = json_encode([$rid, $i, $new['final_price'], $old['final_price']]); }
            }
        }
        ok('every Liwonde plan prices the same as before on every room type', $same, $why);
        $pdo->beginTransaction();
        try {
            $r1 = $roomIds[0] ?? 1; $r2 = $roomIds[1] ?? 2;
            $ins = $pdo->prepare("INSERT INTO rate_plans (name, rule_type, adjustment_type, adjustment_value, applies_to, room_type_ids, priority, is_stacking, is_active, room_adjustments, price_basis)
                VALUES ('ZZ 8H test plan', 'promotion', 'percentage', 10, 'all', NULL, 0, 0, 0, ?, 'base')");
            $ins->execute([json_encode([(string)$r2 => ['type' => 'fixed', 'value' => 10000]], JSON_FORCE_OBJECT)]);
            $id = (int)$pdo->lastInsertId();
            $st = $pdo->prepare('SELECT * FROM rate_plans WHERE id = ?'); $st->execute([$id]); $row = $st->fetch();
            ok('saved plan reads back: room ' . $r2 . ' own fixed on base, room ' . $r1 . ' default +10%',
                $row && rh_rate_plan_price($row, $r2, 100000.0, 1.175) === 111750.0 && rh_rate_plan_price($row, $r1, 100000.0, 1.175) === 110000.0, json_encode($row));
        } finally {
            $pdo->rollBack();
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM rate_plans WHERE name = 'ZZ 8H test plan'"); $st->execute();
        ok('nothing kept after rollback', (int)$st->fetchColumn() === 0);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
