<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * The "i" popup next to a booking Total: rh_booking_total_breakdown() lines must add up to the shown total.
 * Usage (project root): php scripts/tests/booking_total_breakdown.php
 *   - Pure (no DB writes; VAT mode forced in the in-memory settings cache): VAT inclusive AND exclusive, plain stay,
 *     child charge, manager override (final gross), rate plan discount, group of two rooms, folio extras/paid/balance,
 *     old row (total_with_vat 0), unusable row (degrades with a message).
 *   - Pages (scripts/tests/page_harness.php, rolled back): the Bookings list, the dashboard pending card and the
 *     booking details page carry the button and its data.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function btb_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -600)];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -600)]; } }
    return $res;
}
function btb_num(string $v): float { return (float)preg_replace('/[^0-9.\-]/', '', $v); }
/** Sum the 'add' lines of a breakdown (what the popup adds up) and return [sum, totalLine value, kinds]. */
function btb_sum(array $bd, string $totalLabel = ''): array {
    $sum = 0.0; $total = null;
    foreach ($bd['lines'] as $ln) {
        if ($ln['kind'] === 'add') { $sum += btb_num($ln['value']); }
        if ($ln['kind'] === 'total' && ($totalLabel === '' || $ln['label'] === $totalLabel) && $total === null) { $total = btb_num($ln['value']); }
    }
    return [round($sum, 2), $total];
}
function btb_has(array $bd, string $needle): bool {
    foreach ($bd['lines'] as $ln) { if (stripos($ln['label'], $needle) !== false) { return true; } }
    return false;
}

global $_SITE_SETTINGS;
$roomId = (int)$pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();

$night = 100000.0;
$nights = 3;
$rule = ['mode' => 'percent', 'percent' => 50.0, 'amount' => 0.0, 'free' => 2, 'max' => null];
foreach (['exclusive', 'inclusive'] as $mode) {
    $_SITE_SETTINGS['vat_enabled'] = '1';
    $_SITE_SETTINGS['vat_rate'] = '17.5';
    $_SITE_SETTINGS['vat_pricing_mode'] = $mode;
    $_SITE_SETTINGS['tourism_levy_enabled'] = '1';
    $_SITE_SETTINGS['tourism_levy_percent'] = '1';
    $_SITE_SETTINGS['currency_symbol'] = 'K';
    ok("[$mode] vat_mode is forced", vat_mode() === $mode);

    $mk = function (float $amount, string $basis, float $child, array $extra = []) use ($nights): array {
        $tt = rh_stay_totals($amount, $basis);
        return [
            'number_of_nights' => $nights, 'total_amount' => round($tt['net'] + $tt['levy'], 2), 'vat_amount' => $tt['vat'],
            'tourism_levy_amount' => $tt['levy'], 'tourism_levy_percent' => $tt['levy_rate'], 'vat_rate' => $tt['vat_rate'],
            'total_with_vat' => $tt['total_with_vat'], 'child_supplement_total' => $child,
        ] + $extra;
    };

    // plain stay
    $plain = $mk($night * $nights, 'price', 0.0);
    $bd = rh_booking_total_breakdown($plain);
    [$sum, $tot] = btb_sum($bd);
    ok("[$mode] plain: ok and total equals rh_booking_full_total", $bd['ok'] && abs($tot - rh_booking_full_total($plain)) <= BALANCE_TOLERANCE, json_encode($bd));
    ok("[$mode] plain: lines add up to the total", abs($sum - $tot) <= BALANCE_TOLERANCE, "$sum vs $tot");
    ok("[$mode] plain: levy and VAT lines present, no child line", btb_has($bd, 'Tourism levy (1%)') && btb_has($bd, 'VAT (17.5%)') && !btb_has($bd, 'Child'));
    $vatLine = array_values(array_filter($bd['lines'], fn($l) => strpos($l['label'], 'VAT') === 0))[0] ?? [];
    ok("[$mode] plain: VAT is " . ($mode === 'inclusive' ? 'included (not added)' : 'added'), ($vatLine['kind'] ?? '') === ($mode === 'inclusive' ? 'note' : 'add') && strpos((string)($vatLine['hint'] ?? ''), $mode === 'inclusive' ? 'included' : 'added') !== false);

    // child charge: 3 children, first 2 free -> 1 pays 50% of the rate for each night
    $childSup = rh_child_supplement($rule, 3, $nights, $night);
    $kid = $mk($night * $nights + $childSup, 'price', $childSup, ['child_guests' => 3, 'child_price_multiplier' => 50, 'child_charge_mode' => 'percent', 'free_children' => 2]);
    $bd = rh_booking_total_breakdown($kid);
    [$sum, $tot] = btb_sum($bd);
    $childLine = array_values(array_filter($bd['lines'], fn($l) => $l['label'] === 'Child charge'))[0] ?? [];
    ok("[$mode] child: line present with the rule label", !empty($childLine) && strpos($childLine['hint'], '3 children, first 2 free') !== false, json_encode($childLine));
    ok("[$mode] child: lines add up to the total", abs($sum - $tot) <= BALANCE_TOLERANCE && abs($tot - rh_booking_full_total($kid)) <= BALANCE_TOLERANCE, "$sum vs $tot");

    // manager override = final gross, nothing separate
    $ovr = $mk(250000.0, 'final', 0.0);
    $bd = rh_booking_total_breakdown($ovr);
    [$sum, $tot] = btb_sum($bd);
    ok("[$mode] override: total is the override and lines add up", abs($tot - 250000.0) <= BALANCE_TOLERANCE && abs($sum - $tot) <= BALANCE_TOLERANCE, "$sum vs $tot");

    // rate plan discount shown as a note, not added
    $disc = $mk($night * $nights, 'price', 0.0, ['rate_plan_label' => 'Early bird', 'rate_plan_discount' => 10000]);
    $bd = rh_booking_total_breakdown($disc);
    [$sum, $tot] = btb_sum($bd);
    $dl = array_values(array_filter($bd['lines'], fn($l) => stripos($l['label'], 'Discount') === 0))[0] ?? [];
    ok("[$mode] discount: shown as a note with the plan name, total still adds up", ($dl['kind'] ?? '') === 'note' && strpos($dl['hint'], 'Early bird') !== false && $dl['value'] === '-K 30,000.00' && abs($sum - $tot) <= BALANCE_TOLERANCE, json_encode($dl));

    // group of two rooms (plain + child)
    $bd = rh_booking_total_breakdown([$plain, $kid]);
    [$sum, $tot] = btb_sum($bd);
    ok("[$mode] group: total is the sum of the rooms and lines add up", abs($tot - (rh_booking_full_total($plain) + rh_booking_full_total($kid))) <= BALANCE_TOLERANCE && abs($sum - $tot) <= BALANCE_TOLERANCE && btb_has($bd, '2 rooms'), json_encode($bd));

    // folio: extras, paid, balance due
    $extras = 20000.0; $paid = 150000.0;
    $grand = round(rh_booking_full_total($plain) + $extras, 2);
    $bd = rh_booking_total_breakdown($plain, ['folio' => ['booking_base_amount' => rh_booking_full_total($plain), 'extras_total' => $extras, 'grand_total' => $grand, 'amount_paid' => $paid, 'balance_due' => $grand - $paid, 'credit' => 0]]);
    [$sum, $ft] = btb_sum($bd, 'Folio total');
    ok("[$mode] folio: extras line, folio total = booking total + extras", btb_has($bd, 'Folio extras') && $ft !== null && abs($ft - $grand) <= BALANCE_TOLERANCE, json_encode($bd));
    $bal = array_values(array_filter($bd['lines'], fn($l) => $l['label'] === 'Balance due'))[0] ?? [];
    ok("[$mode] folio: paid and balance due shown and consistent", btb_has($bd, 'Paid') && !empty($bal) && abs(btb_num($bal['value']) - ($grand - $paid)) <= BALANCE_TOLERANCE);

    // cancelled stay: only the retained amount is billed; a step line keeps the lines adding up
    $kept = 40000.0;
    $bd = rh_booking_total_breakdown($plain, ['folio' => ['booking_base_amount' => $kept, 'extras_total' => 0, 'grand_total' => $kept, 'amount_paid' => 0, 'balance_due' => $kept]]);
    $adds = 0.0; foreach ($bd['lines'] as $l) { if ($l['label'] === 'Original total' || $l['label'] === 'Cancellation adjustment') { $adds += btb_num($l['value']); } }
    ok("[$mode] cancelled: original + adjustment = amount kept", btb_has($bd, 'Cancellation adjustment') && abs($adds - $kept) <= BALANCE_TOLERANCE, json_encode($bd));
}

// VAT off with a leftover vat_amount on the row: no VAT line, lines still add up
$_SITE_SETTINGS['vat_enabled'] = '0';
$_SITE_SETTINGS['vat_pricing_mode'] = 'exclusive';
ok('[off] vat_mode is off', vat_mode() === 'off');
$offRow = ['number_of_nights' => 2, 'total_amount' => 202000.0, 'vat_amount' => 35000.0, 'tourism_levy_amount' => 2000.0, 'tourism_levy_percent' => 1, 'total_with_vat' => 202000.0, 'child_supplement_total' => 0];
$bd = rh_booking_total_breakdown($offRow);
[$sum, $tot] = btb_sum($bd);
ok('[off] no VAT line from a leftover vat_amount, lines add up to the total', !btb_has($bd, 'VAT (') && strpos(json_encode($bd), 'VAT included') === false && abs($sum - $tot) <= BALANCE_TOLERANCE && abs($tot - 202000.0) <= BALANCE_TOLERANCE, json_encode($bd));
$_SITE_SETTINGS['vat_enabled'] = '1';

// old row: total_with_vat 0, only total_amount + vat_amount (rh_booking_full_total fallback); no levy/child data
$_SITE_SETTINGS['vat_pricing_mode'] = 'exclusive';
$old = ['total_amount' => 50000.0, 'vat_amount' => 8250.0, 'total_with_vat' => 0];
$bd = rh_booking_total_breakdown($old);
[$sum, $tot] = btb_sum($bd);
ok('old row: shows the parts that exist, adds up, and says it was rebuilt', $bd['ok'] && abs($tot - 58250.0) <= BALANCE_TOLERANCE && abs($sum - $tot) <= BALANCE_TOLERANCE && stripos($bd['note'], 'Older booking') !== false, json_encode($bd));
$none = rh_booking_total_breakdown(['total_amount' => 0, 'vat_amount' => 0, 'total_with_vat' => 0]);
ok('unusable row: not ok with the older-booking message', $none['ok'] === false && $none['note'] === 'Breakdown not available for this older booking.' && $none['lines'] === []);
$btn = rh_booking_total_info_btn(['total_amount' => 0]);
ok('button markup: accessible label, escaped JSON in a data attribute, no inline handler', strpos($btn, 'aria-label="How this total is worked out"') !== false && strpos($btn, 'data-rh-breakdown="') !== false && strpos($btn, 'onclick') === false && strpos($btn, '<script') === false);
$evil = rh_booking_total_info_btn(['total_amount' => 1000, 'vat_amount' => 0, 'total_with_vat' => 1000, 'rate_plan_label' => '"><script>alert(1)</script>', 'rate_plan_discount' => 10, 'number_of_nights' => 1]);
ok('button markup: hostile plan name cannot break out of the attribute', strpos($evil, '<script') === false && substr_count($evil, '"') % 2 === 0);

// ---- pages (rolled back harness runs) ----
if ($roomId <= 0) { ok('skipped page checks: no active room type', true); echo "\n$pass passed, $fail failed\n"; exit($fail > 0 ? 1 : 0); }
$tag = 'BTB' . random_int(100000, 999999);
$far = date('Y-m-d', strtotime('+700 day'));
$far2 = date('Y-m-d', strtotime('+703 day'));
foreach (['exclusive', 'inclusive'] as $mode) {
    $tg = $tag . strtoupper(substr($mode, 0, 1));
    $a = $tg . 'A';
    $baseId = 2099000000 + ($mode === 'exclusive' ? 30 : 40);
    $setup = [
        "INSERT INTO site_settings (setting_key, setting_value) VALUES ('vat_enabled','1'),('vat_rate','17.5'),('vat_pricing_mode','{$mode}'),('tourism_levy_enabled','1'),('tourism_levy_percent','1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights,
                number_of_guests, adult_guests, child_guests, status, payment_status, total_amount, vat_amount, tourism_levy_amount, tourism_levy_percent, vat_rate, total_with_vat, amount_paid, amount_due, created_at)
         VALUES ({$baseId}, '{$a}', {$roomId}, 'BTB Guest', 'btb@example.invalid', '000', '{$far}', '{$far2}', 3, 2, 2, 0, 'pending', 'unpaid',
                303000, 52500, 3000, 1, 17.5, 355500, 0, 355500, '2000-01-01 00:00:00')",
    ];
    $r = btb_run('admin/bookings.php', ['method' => 'GET', 'get' => ['search' => $tg], 'setup' => $setup, 'grep' => ['class="rh-info-btn"', 'data-rh-breakdown=', 'How this total is worked out', 'Total <span class="bk-th-total__sub">(incl. VAT &amp; levy)</span>', '355,500.00']]);
    $f = $r['found'] ?? [];
    ok("[$mode] bookings list: info button, data and aria-label present", !empty($f['class="rh-info-btn"']) && !empty($f['data-rh-breakdown=']) && !empty($f['How this total is worked out']), $r['raw'] ?? '');
    ok("[$mode] bookings list: header reads Total (incl. VAT & levy)", !empty($f['Total <span class="bk-th-total__sub">(incl. VAT &amp; levy)</span>']));
    $r = btb_run('admin/dashboard.php', ['method' => 'GET', 'get' => ['ajax' => 'card_insight', 'card' => 'pending_bookings'], 'setup' => $setup]);
    $cell = null;
    foreach (($r['json']['rows'] ?? []) as $row) { if (($row['reference']['label'] ?? '') === $a) { $cell = $row['total'] ?? null; } }
    $okCell = is_array($cell) && ($cell['type'] ?? '') === 'breakdown' && !empty($cell['breakdown']['ok']);
    $sumCell = 0.0; $totCell = null;
    if ($okCell) { [$sumCell, $totCell] = btb_sum($cell['breakdown']); }
    ok("[$mode] dashboard pending card: total cell carries the breakdown and it adds up to 355,500", $okCell && abs($sumCell - 355500) <= BALANCE_TOLERANCE && abs($totCell - 355500) <= BALANCE_TOLERANCE, json_encode($cell) . ' ' . ($r['raw'] ?? ''));
    $r = btb_run('admin/booking-details.php', ['method' => 'GET', 'get' => ['id' => (string)$baseId], 'setup' => $setup, 'grep' => ['class="rh-info-btn"', 'data-rh-breakdown=', 'Folio total']]);
    ok("[$mode] booking details: info button next to the folio total", !empty($r['found']['class="rh-info-btn"']) && !empty($r['found']['data-rh-breakdown=']) && !empty($r['found']['Folio total']), $r['raw'] ?? '');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
