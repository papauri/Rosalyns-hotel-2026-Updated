<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * RP-2 (owner 2026-11-10): rate-plan surcharges are shown, never added; the booking page payment breakdown foots;
 * the end-of-day pricing card counts discounts and surcharges apart and skips deleted bookings. Display only.
 * Usage (project root, TEST HOTEL ONLY): php scripts/tests/rate_plan_surcharge_display.php
 * Every page run goes through scripts/tests/page_harness.php (one outer transaction, rolled back): nothing is kept.
 * Rendered pages are written to the temp folder and deleted at the end.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": " . substr($detail, 0, 1500) : '') . "\n"; }
}
$dumps = [];
register_shutdown_function(function () use (&$dumps) { foreach ($dumps as $f) { @unlink($f); } });
function rps_page(string $page, array $get, array $setup): array {
    global $harness, $dumps;
    $dump = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_rps_' . bin2hex(random_bytes(4)) . '.html';
    $dumps[] = $dump;
    $cfg = ['method' => 'GET', 'get' => $get, 'setup' => $setup, 'dump_html' => $dump];
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['raw' => substr((string)$out, -800)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res += $r; } }
    $res['html'] = is_file($dump) ? (string)file_get_contents($dump) : '';
    return $res;
}
function rps_num(string $v): float { return (float)preg_replace('/[^0-9.\-]/', '', $v); }
function rps_txt(string $h): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8'))); }
/** Payment breakdown rows on booking-details: [[label, value-text, main-label], ...] in page order. */
function rps_breakdown(string $html): array {
    $a = strpos($html, 'class="payment-tax-breakdown"');
    if ($a === false) { return []; }
    $seg = substr($html, $a, 12000);
    preg_match_all('#<div class="payment-tax-row[^"]*">\s*<span>(.*?)</span>\s*<strong[^>]*>(.*?)</strong>#s', $seg, $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $x) {
        $main = rps_txt(preg_replace('#<br\s*/?>.*$#s', '', $x[1]));
        $rows[] = [rps_txt($x[1]), rps_txt($x[2]), $main];
    }
    return $rows;
}
function rps_row(array $rows, string $startsWith): ?array {
    foreach ($rows as $r) { if (stripos($r[2], $startsWith) === 0) { return $r; } }
    return null;
}

$roomId = (int)$pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($roomId <= 0) { ok('skipped: no active room type', true); echo "\n$pass passed, $fail failed\n"; exit(0); }

// Settings the pages run with (inside the rolled-back harness) and the same in this process for the expected figures.
global $_SITE_SETTINGS;
foreach (['vat_enabled' => '1', 'vat_rate' => '17.5', 'vat_pricing_mode' => 'inclusive', 'tourism_levy_enabled' => '1', 'tourism_levy_percent' => '1'] as $k => $v) { $_SITE_SETTINGS[$k] = $v; }
$settingsSql = "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','17.5'),('vat_pricing_mode','inclusive'),('tourism_levy_enabled','1'),('tourism_levy_percent','1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";

// ---- 1. booking details: 3 nights all-in 300,000 room + 30,000 package (VAT inside, no levy) + a 27,000 dinner ----
$room = rh_stay_totals(300000.0, 'price', true);
$pkg = rh_stay_totals(30000.0, 'price', false);
$totalAmount = round($room['net'] + $room['levy'] + $pkg['net'], 2);
$vatAmt = round($room['vat'] + $pkg['vat'], 2);
$levyAmt = $room['levy'];
$twv = round($totalAmount + $vatAmt, 2);
$far = date('Y-m-d', strtotime('+710 day'));
$far3 = date('Y-m-d', strtotime('+713 day'));
foreach (['inclusive', 'exclusive'] as $vmode) {
$_SITE_SETTINGS['vat_pricing_mode'] = $vmode;
$room = rh_stay_totals(300000.0, 'price', true);
$pkg = rh_stay_totals(30000.0, 'price', false);
$totalAmount = round($room['net'] + $room['levy'] + $pkg['net'], 2);
$vatAmt = round($room['vat'] + $pkg['vat'], 2);
$levyAmt = $room['levy'];
$twv = round($totalAmount + $vatAmt, 2);
$modeSql = str_replace("'inclusive'", "'{$vmode}'", $settingsSql);
foreach ([['surcharge', -8000.0], ['discount', 5000.0]] as [$kind0, $pd]) {
    $kind = $kind0 . ' ' . $vmode;
    $bid = 2099000000 + ($kind0 === 'surcharge' ? 61 : 62) + ($vmode === 'exclusive' ? 10 : 0);
    $ref = 'RPS' . random_int(100000, 999999);
    $setup = [
        $modeSql,
        "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                number_of_guests, adult_guests, child_guests, status, payment_status, total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate,
                total_with_vat, amount_paid, amount_due, package_total, rate_plan_id, rate_plan_label, rate_plan_discount, created_at)
         VALUES ({$bid}, '{$ref}', {$roomId}, 'RPS Guest', 'rps@example.invalid', '000', '{$far}', '{$far3}', 3, 2, 2, 0, 'confirmed', 'unpaid',
                {$totalAmount}, {$vatAmt}, {$levyAmt}, 1, 17.5, {$twv}, 0, {$twv}, 30000, 1, 'Festival Season', {$pd}, '2000-01-01 00:00:00')",
        "INSERT INTO booking_packages (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost) VALUES ({$bid}, 0, 'Bed and Breakfast', 'per_stay', 30000, 1, 30000)",
        "INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, added_by)
         VALUES ({$bid}, 'food', 'RPS Dinner', 1, 22978.72, 22978.72, 17.5, 4021.28, 27000, NOW(), NULL)",
    ];
    $r = rps_page('admin/booking-details.php', ['id' => (string)$bid], $setup);
    $rows = rps_breakdown($r['html']);
    $rb = rps_row($rows, 'Room base'); $pk = rps_row($rows, 'Bed and Breakfast'); $ex = rps_row($rows, 'Extras'); $st = rps_row($rows, 'Subtotal');
    $lv = rps_row($rows, '+ Tourism Levy'); $vt = rps_row($rows, '+ VAT'); $tt = rps_row($rows, 'Total');
    $d = json_encode($rows) . ' ' . ($r['raw'] ?? '');
    ok("[$kind] booking details: page rendered and rolled back", $rows !== [] && !empty($r['rolled_back']) && empty($r['leak']), $d);
    $expSub = round($twv + 27000 - ($vatAmt + 4021.28) - $levyAmt, 2);
    ok("[$kind] Subtotal (excl. VAT and levy) = folio total - VAT - levy, extras included", $st !== null && abs(rps_num($st[1]) - $expSub) <= BALANCE_TOLERANCE && stripos($st[2], 'excl. VAT and levy') !== false, $d);
    ok("[$kind] Subtotal + Tourism levy + VAT = Total, and Total is the unchanged folio total (booking + extras)", $st && $lv && $vt && $tt && abs(rps_num($lv[1]) - $levyAmt) <= BALANCE_TOLERANCE && $levyAmt > 0
        && abs(rps_num($st[1]) + rps_num($lv[1]) + rps_num($vt[1]) - rps_num($tt[1])) <= BALANCE_TOLERANCE && abs(rps_num($tt[1]) - round($twv + 27000, 2)) <= BALANCE_TOLERANCE, $d);
    ok("[$kind] package row shows its net (30,000 / 1.175 = 25,531.91), extras show 22,978.72 (excl. VAT)", $pk !== null && abs(rps_num($pk[1]) - $pkg['net']) <= BALANCE_TOLERANCE && abs($pkg['net'] - 25531.91) <= BALANCE_TOLERANCE && $ex !== null && abs(rps_num($ex[1]) - 22978.72) <= BALANCE_TOLERANCE, $d);
    ok("[$kind] Room base + package + Extras = Subtotal exactly (same basis)", $rb && $pk && $ex && $st && abs(rps_num($rb[1]) + rps_num($pk[1]) + rps_num($ex[1]) - rps_num($st[1])) <= BALANCE_TOLERANCE, $d);
    ok("[$kind] Room base = total_amount - levy - package net (room net)", $rb !== null && abs(rps_num($rb[1]) - round($totalAmount - $levyAmt - $pkg['net'], 2)) <= BALANCE_TOLERANCE, $d);
    if ($kind0 === 'surcharge') {
        $sr = rps_row($rows, 'Festival Season surcharge');
        ok('[' . $kind . '] own row "+24,000.00" (8,000 x 3 nights) saying it is already in the room base', $sr !== null && strpos($sr[1], '+') === 0 && abs(rps_num($sr[1]) - 24000) <= BALANCE_TOLERANCE && stripos($sr[0], 'already in the room base') !== false, $d);
        ok('[' . $kind . '] no discount row', rps_row($rows, 'Festival Season discount') === null, $d);
        ok('[' . $kind . '] rate plan line in the stay table shows the surcharge per night, already in the rate', strpos($r['html'], 'surcharge +') !== false && strpos($r['html'], '8,000.00/night (already in the room rate)') !== false, $d);
        ok('[' . $kind . '] the total popup data carries the surcharge note', strpos($r['html'], 'Surcharge already in the room rate') !== false, $d);
    } else {
        $dr = rps_row($rows, 'Festival Season discount');
        ok('[' . $kind . '] row "-15,000.00" (5,000 x 3) says it is already taken off the room base, not subtracted again', $dr !== null && strpos($dr[1], '-') === 0 && abs(abs(rps_num($dr[1])) - 15000) <= BALANCE_TOLERANCE && stripos($dr[0], 'already taken off the room base') !== false, $d);
        ok('[' . $kind . '] no surcharge row', rps_row($rows, 'Festival Season surcharge') === null, $d);
    }
}
}
$_SITE_SETTINGS['vat_pricing_mode'] = 'inclusive';

// ---- 2. end-of-day pricing card on a date with only these bookings ----
$day = '2001-03-15';
$mkB = function (int $id, string $ref, ?float $pd, int $nights, float $pkgTotal, string $deleted = 'NULL') use ($roomId, $day): string {
    $out = date('Y-m-d', strtotime($day . ' +' . $nights . ' day'));
    $pdSql = $pd === null ? 'NULL' : (string)$pd;
    $rp = $pd === null ? 'NULL' : '1';
    $lbl = $pd === null ? 'NULL' : "'Festival Season'";
    return "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
            number_of_guests, adult_guests, child_guests, status, payment_status, total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate,
            total_with_vat, amount_paid, amount_due, package_total, rate_plan_id, rate_plan_label, rate_plan_discount, created_at, deleted_at)
        VALUES ({$id}, '{$ref}', {$roomId}, 'RPS EOD', 'rps@example.invalid', '000', '{$day}', '{$out}', {$nights}, 2, 2, 0, 'confirmed', 'unpaid',
            100000, 0, 0, 0, 0, 100000, 0, 100000, {$pkgTotal}, {$rp}, {$lbl}, {$pdSql}, '{$day} 10:00:00', {$deleted})";
};
$e = 2099000070;
$setup = [
    $mkB($e + 1, 'RPSE1', 5000.0, 2, 0.0),                 // discount 5,000 x 2 = 10,000
    $mkB($e + 2, 'RPSE2', -8000.0, 3, 0.0),                // surcharge 8,000 x 3 = 24,000 (never netted against the discount)
    $mkB($e + 3, 'RPSE3', null, 1, 30000.0),               // one package, live
    $mkB($e + 4, 'RPSE4', null, 1, 0.0, "'{$day} 12:00:00'"), // deleted booking: its packages must not count
    "INSERT INTO booking_packages (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost) VALUES ({$e}+3, 0, 'Bed and Breakfast', 'per_stay', 30000, 1, 30000)",
    "INSERT INTO booking_packages (booking_id, package_id, package_name, price_type, price_amount, quantity, total_cost) VALUES ({$e}+4, 0, 'Ghost Package', 'per_stay', 1000, 1, 1000), ({$e}+4, 0, 'Ghost Package', 'per_stay', 1000, 1, 1000)",
];
$r = rps_page('admin/end-of-day-report.php', ['date' => $day], $setup);
$h = $r['html'];
$kvv = function (string $label) use ($h): ?string {
    return preg_match('#<li><span>' . preg_quote($label, '#') . '</span><strong[^>]*>(.*?)</strong></li>#s', $h, $m) ? rps_txt($m[1]) : null;
};
$disc = $kvv('Total discounts given'); $sur = $kvv('Surcharges added'); $pkgRev = $kvv('Package add-on revenue'); $top = $kvv('Top package'); $rpb = $kvv('Rate-plan bookings');
$d = json_encode([$rpb, $disc, $sur, $pkgRev, $top]) . ' ' . ($r['raw'] ?? '');
ok('EOD: page rendered and rolled back', $h !== '' && !empty($r['rolled_back']) && empty($r['leak']), $d);
ok('EOD: Total discounts given counts discounts only (10,000), not netted with surcharges', $disc !== null && strpos($disc, '10,000') !== false && $disc[0] !== '-', $d);
ok('EOD: Surcharges added is its own figure (24,000) with a helper saying it is already in the room rates', $sur !== null && strpos($sur, '24,000') !== false && stripos($sur, 'already in the room rates') !== false, $d);
ok('EOD: packages from a deleted booking are not counted (1 package; top package is not the deleted one)', $pkgRev !== null && strpos($pkgRev, '1 package on bookings made today') !== false && $top !== null && strpos($top, 'Bed and Breakfast') !== false && strpos($h, 'Ghost Package') === false, $d);
ok('EOD: rate-plan bookings 2, with a helper', $rpb !== null && strpos($rpb, '2') === 0 && stripos($rpb, 'bookings made today with a rate plan') !== false, $d);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
