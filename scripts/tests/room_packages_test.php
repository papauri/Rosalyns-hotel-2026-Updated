<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Room packages (owner 2026-11-26, .claude/ROOM_PACKAGES_FIX_PLAN.md). Usage (project root): php scripts/tests/room_packages_test.php
 *   Pure: units, older rows saved as quantity 1, the "how it adds up" wording, the requote for fewer nights / more
 *         adults, "offered for this room type", the room-change flag text, the refused-package message, and the
 *         price-basis gross-up in inclusive and exclusive VAT mode.
 *   DB (page_harness: one transaction that never commits, rolled back; Liwonde only), each in inclusive AND exclusive mode:
 *     shorten     5 -> 2 nights: package worked out again, rows updated, History line, levy from the room part only.
 *     extend      5 -> 7 nights end to end through processBookingDateAdjustment().
 *     adults      2 -> 3 adults: a per-person package moves; a per-night-only package does not.
 *     group       the party rule: a second room's adults change reprices the lead row's package (History on both),
 *                 a second room's nights change does not, the lead's own nights change does, a stale quote is refused.
 *     unverified  package_total not equal to its rows: never recalculated, staff told.
 *     room_change a package not offered for the new type needs "I have told the guest"; History says so.
 *     refuse      a switched-off / not-offered package is refused at submit, by name; units saved as quantity.
 *     toggle      admin/packages.php switch on/off writes a System Logs line.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/booking-room-change.php';
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function rp_run(string $page, array $cfg): array {
    global $harness;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . $page . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r + ['raw' => substr((string)$out, -800)]; } }
    return $res;
}
function rp_clean(array $r, string $label): void {
    ok("$label: harness rolled back, nothing left behind", empty($r['leak']) && empty($r['marker_lost']) && !empty($r['rolled_back']), json_encode([$r['error'] ?? null, $r['raw'] ?? null]));
}
function near($a, float $b): bool { return is_numeric($a) && abs((float)$a - $b) <= 0.01; }

echo "== Pure helpers ==\n";
ok('units: per night = nights, per person per night = adults x nights, per stay = 1',
    rh_pkg_units('per_night', 5, 2) === 5 && rh_pkg_units('per_person_per_night', 5, 3) === 15 && rh_pkg_units('per_stay', 5, 3) === 1);
ok('units of an older row saved as quantity 1 come from total / price (250,000 at 50,000 = 5)',
    rh_pkg_row_units(['price_amount' => 50000, 'total_cost' => 250000, 'quantity' => 1]) === 5
    && rh_pkg_row_units(['price_amount' => 30000, 'total_cost' => 45000, 'quantity' => 2]) === 2);
$lbl = rh_pkg_label(['price_type' => 'per_person_per_night', 'price_amount' => 20000, 'total_cost' => 180000, 'quantity' => 1], 3);
ok('label: per person per night reads "× 3 guests × 3 nights = total"', $lbl === 'MWK 20,000.00 per person per night × 3 guests × 3 nights = MWK 180,000.00', $lbl);
$lbl = rh_pkg_label(['price_type' => 'per_night', 'price_amount' => 50000, 'total_cost' => 100000], 2, null, false);
ok('label: per night without the total', $lbl === 'MWK 50,000.00 per night × 2 nights', $lbl);
ok('label: per stay', rh_pkg_label(['price_type' => 'per_stay', 'price_amount' => 30000, 'total_cost' => 30000], 5) === 'MWK 30,000.00 per stay');

$rows = [
    ['id' => 1, 'package_name' => 'Breakfast', 'price_type' => 'per_person_per_night', 'price_amount' => 20000, 'quantity' => 1, 'total_cost' => 200000],
    ['id' => 2, 'package_name' => 'Dinner', 'price_type' => 'per_night', 'price_amount' => 50000, 'quantity' => 5, 'total_cost' => 250000],
    ['id' => 3, 'package_name' => 'Transfer', 'price_type' => 'per_stay', 'price_amount' => 30000, 'quantity' => 1, 'total_cost' => 30000],
];
$q = rh_pkg_requote($rows, 2, 2, null, 'MWK', 5);
ok('requote 5 -> 2 nights: 480,000 -> 210,000 (per stay kept)', near($q['old_total'], 480000) && near($q['new_total'], 210000) && $q['changed'] === true
    && (int)$q['rows'][0]['new_quantity'] === 4 && (int)$q['rows'][1]['new_quantity'] === 2 && empty($q['rows'][2]['changed']), json_encode($q));
$q = rh_pkg_requote($rows, 5, 3, null, 'MWK', 5);
ok('requote 2 -> 3 adults: breakfast 200,000 -> 300,000, dinner unchanged', near($q['new_total'], 580000) && near($q['rows'][0]['new_total'], 300000) && empty($q['rows'][1]['changed']), json_encode($q));
$q = rh_pkg_requote($rows, 5, 2, null, 'MWK', 5);
ok('requote with the same nights and adults changes nothing', $q['changed'] === false && near($q['new_total'], 480000));

ok('offered for: "all" or no catalogue row = any type; room_types = listed ids only',
    rh_package_allowed_for_type(null, 7) && rh_package_allowed_for_type(['applies_to' => 'all'], 7)
    && rh_package_allowed_for_type(['applies_to' => 'room_types', 'room_type_ids' => '[3,7]'], 7)
    && !rh_package_allowed_for_type(['applies_to' => 'room_types', 'room_type_ids' => '[3]'], 7));
$fl = rh_room_change_package_flags([['package_name' => 'Breakfast', 'catalogue' => ['applies_to' => 'room_types', 'room_type_ids' => '[3]']],
    ['package_name' => 'Spa', 'catalogue' => null], ['package_name' => 'Dinner', 'catalogue' => ['applies_to' => 'room_types', 'room_type_ids' => '[4]']]], 7);
ok('room change flags: only the packages not offered for the new type', $fl === ['Breakfast', 'Dinner'], json_encode($fl));
ok('room change flag text', rh_room_change_package_flag_text($fl, 'Deluxe') === 'Breakfast and Dinner are not offered for Deluxe.'
    && rh_room_change_package_flag_text(['Breakfast'], 'Deluxe') === 'Breakfast is not offered for Deluxe.' && rh_room_change_package_flag_text([], 'Deluxe') === '');
ok('room change quote without package rows (pure callers) has no flags',
    (rh_room_change_quote(['number_of_nights' => 2, 'check_in_date' => date('Y-m-d', strtotime('+20 day')), 'check_out_date' => date('Y-m-d', strtotime('+22 day')), 'status' => 'confirmed', 'total_with_vat' => 200000, 'total_amount' => 200000, 'vat_amount' => 0, 'package_total' => 0, 'adult_guests' => 2, 'number_of_guests' => 2],
        ['id' => 1, 'price_per_night' => 100000], ['id' => 2, 'price_per_night' => 120000])['package_flags'] ?? null) === []);
$msg = rh_package_unavailable_message(['Sunset', 'Safari']);
ok('refused-package message names them', strpos($msg, 'Sunset and Safari packages are') !== false, $msg);

global $_SITE_SETTINGS;
$keep = $_SITE_SETTINGS;
foreach (['inclusive', 'exclusive'] as $m) {
    $_SITE_SETTINGS['vat_enabled'] = '1'; $_SITE_SETTINGS['vat_rate'] = '17.5'; $_SITE_SETTINGS['vat_pricing_mode'] = $m;
    $_SITE_SETTINGS['tourism_levy_enabled'] = '1'; $_SITE_SETTINGS['tourism_levy_percent'] = '1';
    $g = rh_stay_totals(210000, 'price', false)['total_with_vat'];
    ok("$m mode: a requoted package on the price basis grosses up to the all-in amount the totals use", near($g, 210000), (string)$g);
    $tt = rh_stay_totals_with_package(410000, 210000, true);
    ok("$m mode: levy is taken from the room part only (200,000 of 410,000)", near($tt['total_with_vat'], 410000) && $tt['levy'] > 0
        && near($tt['levy'], rh_stay_totals_with_package(200000, 0, true)['levy']), json_encode($tt));
}
$_SITE_SETTINGS = $keep;

$vatSeen = [];
foreach (['inclusive', 'exclusive'] as $mode) {
    $run = static function (string $sc) use ($mode): array {
        return rp_run('scripts/tests/room_packages_child.php', ['post' => ['scenario' => $sc, 'mode' => $mode], 'ajax' => false]);
    };
    echo "\n== [$mode] shorten 5 -> 2 nights ==\n";
    $r = $run('shorten'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] shorten");
    ok("[$mode] shorten: the pricing code saw $mode mode", ($j['mode_seen'] ?? '') === $mode, json_encode([$j['mode_seen'] ?? null, $j['vat_on_100k'] ?? null]));
    $vatSeen[$mode] = $j['vat_on_100k'] ?? null;
    ok("[$mode] shorten: before 980,000 (room 500,000 + packages 480,000)", near($j['before']['total'] ?? null, 980000) && near($j['before']['pkg'] ?? null, 480000), json_encode($j ?: $r));
    ok("[$mode] shorten: quote 410,000, package 480,000 -> 210,000", near($j['rp']['new_gross'] ?? null, 410000) && near($j['rp']['package_gross'] ?? null, 210000) && near($j['rp']['package_gross_old'] ?? null, 480000), json_encode($j['rp'] ?? null));
    ok("[$mode] shorten: the staff line shows the package change", stripos((string)($j['line'] ?? ''), 'ackage') !== false, (string)($j['line'] ?? ''));
    ok("[$mode] shorten: saved 410,000 / package 210,000, booked nightly rate unchanged", near($j['after']['total'] ?? null, 410000) && near($j['after']['pkg'] ?? null, 210000) && near($j['after']['per_night'] ?? null, 100000), json_encode($j['after'] ?? null));
    $rw = $j['rows'] ?? [];
    ok("[$mode] shorten: rows saved as units (breakfast 4 = 80,000, dinner 2 = 100,000, transfer 1 = 30,000)",
        (int)($rw[0]['quantity'] ?? 0) === 4 && near($rw[0]['total_cost'] ?? null, 80000) && (int)($rw[1]['quantity'] ?? 0) === 2 && near($rw[1]['total_cost'] ?? null, 100000) && near($rw[2]['total_cost'] ?? null, 30000), json_encode($rw));
    ok("[$mode] shorten: History 'Package recalculated' with old -> new", ($j['history'][0]['action'] ?? '') === 'Package recalculated' && strpos((string)$j['history'][0]['old_value'], '480,000') !== false && strpos((string)$j['history'][0]['new_value'], '210,000') !== false, json_encode($j['history'] ?? null));
    ok("[$mode] shorten: package_total = its rows, levy from the room part only, parts add up", ($j['inv'] ?? []) === ['pkg_eq_rows' => true, 'levy_room_only' => true, 'parts_add_up' => true], json_encode($j['inv'] ?? null));

    echo "\n== [$mode] extend 5 -> 7 nights (processBookingDateAdjustment) ==\n";
    $r = $run('extend'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] extend");
    ok("[$mode] extend: saved", !empty($j['res']['success']), json_encode($j['res'] ?? $r));
    ok("[$mode] extend: 7 nights, package 660,000, total 1,360,000, nightly rate unchanged", (int)($j['after']['nights'] ?? 0) === 7 && near($j['after']['pkg'] ?? null, 660000) && near($j['after']['total'] ?? null, 1360000) && near($j['after']['per_night'] ?? null, 100000), json_encode($j['after'] ?? null));
    ok("[$mode] extend: History line + invariants", ($j['history'][0]['action'] ?? '') === 'Package recalculated' && ($j['inv'] ?? []) === ['pkg_eq_rows' => true, 'levy_room_only' => true, 'parts_add_up' => true], json_encode([$j['history'] ?? null, $j['inv'] ?? null]));

    echo "\n== [$mode] adults 2 -> 3 ==\n";
    $r = $run('adults'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] adults");
    ok("[$mode] adults: breakfast 300,000 + dinner 250,000 = 550,000, total 1,050,000", near($j['after']['pkg'] ?? null, 550000) && near($j['after']['total'] ?? null, 1050000) && near($j['after']['per_night'] ?? null, 100000), json_encode($j['after'] ?? $r));
    ok("[$mode] adults: History + invariants", ($j['history'][0]['action'] ?? '') === 'Package recalculated' && ($j['inv'] ?? []) === ['pkg_eq_rows' => true, 'levy_room_only' => true, 'parts_add_up' => true], json_encode([$j['history'] ?? null, $j['inv'] ?? null]));
    ok("[$mode] adults: a per-night-only package does not move", ($j['per_night_only_changed'] ?? null) === false, json_encode($j['per_night_only_changed'] ?? null));

    echo "\n== [$mode] group: the party rule ==\n";
    $r = $run('group'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] group");
    ok("[$mode] group: before lead 900,000 (party breakfast 20,000 x 4 x 5), second room 300,000", near($j['before']['L'] ?? null, 900000) && near($j['before']['S'] ?? null, 300000), json_encode($j['before'] ?? $r));
    ok("[$mode] group: the second room's adults change is quoted as the lead's package", ($j['rp1']['self'] ?? null) === false && ($j['rp1']['changed'] ?? null) === true && near($j['rp1']['S_new_gross'] ?? null, 300000) && ($j['rp1']['note'] ?? '') !== '', json_encode($j['rp1'] ?? null));
    ok("[$mode] group: lead package 400,000 -> 300,000 (3 adults x 5 nights), lead 800,000, second room unchanged", near($j['step1']['L_pkg'] ?? null, 300000) && near($j['step1']['L'] ?? null, 800000) && near($j['step1']['S'] ?? null, 300000) && near($j['step1']['L_per_night'] ?? null, 100000), json_encode($j['step1'] ?? null));
    ok("[$mode] group: the lead's row saved as 15 units", (int)($j['step1']['L_rows'][0]['quantity'] ?? 0) === 15, json_encode($j['step1']['L_rows'] ?? null));
    ok("[$mode] group: History on the lead ('Package recalculated') and on the second room ('Party package recalculated')",
        ($j['hist_L'][0]['action'] ?? '') === 'Package recalculated' && ($j['hist_S'][0]['action'] ?? '') === 'Party package recalculated', json_encode([$j['hist_L'] ?? null, $j['hist_S'] ?? null]));
    ok("[$mode] group: the second room's nights change leaves the lead's package alone", ($j['rp2_changed'] ?? null) === false && near($j['step2']['L'] ?? null, 800000) && near($j['step2']['S'] ?? null, 200000), json_encode([$j['rp2_changed'] ?? null, $j['step2'] ?? null]));
    ok("[$mode] group: the lead's own 5 -> 4 nights: 20,000 x 3 x 4 = 240,000, lead 640,000", near($j['step3']['L_pkg'] ?? null, 240000) && near($j['step3']['L'] ?? null, 640000), json_encode($j['step3'] ?? null));
    ok("[$mode] group: invariants on both rows", ($j['inv_L'] ?? []) === ['pkg_eq_rows' => true, 'levy_room_only' => true, 'parts_add_up' => true] && ($j['inv_S'] ?? []) === ['pkg_eq_rows' => true, 'levy_room_only' => true, 'parts_add_up' => true], json_encode([$j['inv_L'] ?? null, $j['inv_S'] ?? null]));
    ok("[$mode] group: a stale quote (the lead's package moved meanwhile) is refused", ($j['stale_refused'] ?? null) === true, json_encode($j['stale_refused'] ?? null));

    echo "\n== [$mode] unverified rows ==\n";
    $r = $run('unverified'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] unverified");
    ok("[$mode] unverified: package kept at 250,000, room part repriced (450,000), staff note", ($j['rp']['unverified'] ?? null) === true && ($j['rp']['changed'] ?? null) === false
        && near($j['rp']['package_gross'] ?? null, 250000) && near($j['rp']['new_gross'] ?? null, 450000) && ($j['rp']['note'] ?? '') !== '', json_encode($j['rp'] ?? $r));

    echo "\n== [$mode] room-type change with a package not offered ==\n";
    $r = $run('room_change'); $j = $r['json'] ?? [];
    rp_clean($r, "[$mode] room_change");
    ok("[$mode] room_change: only the type-limited package is flagged; pure callers get none", count($j['flags_B'] ?? []) === 1 && ($j['flags_B'][0] ?? '') === 'Breakfast' && ($j['flags_pure'] ?? null) === [], json_encode($j ?: $r));
    ok("[$mode] room_change: the picker carries the flag text", ($j['options_flag_text'] ?? '') !== '' && ($j['options_flag_text'] ?? '') === ($j['flag_text'] ?? ''), json_encode([$j['options_flag_text'] ?? null, $j['flag_text'] ?? null]));
    ok("[$mode] room_change: refused without 'I have told the guest', nothing changed", ($j['no_ack']['success'] ?? null) === false && strpos((string)($j['no_ack']['message'] ?? ''), 'I have told the guest') !== false && ($j['no_ack']['room_id_kept'] ?? null) === true, json_encode($j['no_ack'] ?? null));
    ok("[$mode] room_change: with it the change is saved, package kept on the bill, History says so", ($j['ack']['success'] ?? null) === true && ($j['ack']['room_id'] ?? null) === true && ($j['ack']['pkg_kept'] ?? null) === true
        && strpos((string)($j['ack_history'] ?? ''), 'staff confirmed the guest was told') !== false, json_encode([$j['ack'] ?? null, $j['ack_history'] ?? null]));
}

// Stay money is all-in in every VAT mode (owner 2026-10-24, rh_stay_totals), so both runs must give the same figures;
// the per-run mode_seen check above proves the exclusive run really ran in exclusive mode.
ok('both modes: same all-in split (stay money does not depend on the VAT mode)', isset($vatSeen['inclusive'], $vatSeen['exclusive']) && abs((float)$vatSeen['inclusive'] - (float)$vatSeen['exclusive']) <= 0.01, json_encode($vatSeen));

echo "\n== Refused at submit (fault C) ==\n";
$r = rp_run('scripts/tests/room_packages_child.php', ['post' => ['scenario' => 'refuse', 'mode' => 'inclusive'], 'ajax' => false]); $j = $r['json'] ?? [];
rp_clean($r, 'refuse');
ok('refuse: the switched-off and not-offered packages come back by name', count($j['missing'] ?? []) === 2 && strpos(implode('|', $j['missing'] ?? []), 'Sunset') !== false && strpos(implode('|', $j['missing'] ?? []), 'Safari') !== false, json_encode($j ?: $r));
ok('refuse: the offered package is saved as 6 units (3 adults x 2 nights) = 120,000', count($j['rows'] ?? []) === 1 && (int)($j['rows'][0]['quantity'] ?? 0) === 6 && near($j['rows'][0]['total'] ?? null, 120000) && near($j['total'] ?? null, 120000), json_encode($j['rows'] ?? null));
ok('refuse: the guest message names them', strpos((string)($j['message'] ?? ''), 'packages are') !== false, (string)($j['message'] ?? ''));

echo "\n== Switching a package on/off is logged (fault D) ==\n";
$tg = 'ZZ RP toggle ' . bin2hex(random_bytes(3));
$r = rp_run('admin/packages.php', [
    'post' => ['action' => 'toggle', 'pkg_id' => '{pid}', 'is_active' => '0'], 'ajax' => false,
    'setup' => ["INSERT INTO room_packages (name, slug, description, short_description, icon, price_type, price_amount, inclusions, applies_to, room_type_ids, is_featured, is_active, sort_order) VALUES ('$tg', '" . strtolower(str_replace(' ', '-', $tg)) . "', '', '', 'fa-box', 'per_stay', 1000, '', 'all', NULL, 0, 1, 0)"],
    'vars' => ['pid' => "SELECT id FROM room_packages WHERE name = '$tg'"],
    'verify' => ["SELECT is_active FROM room_packages WHERE name = '$tg'", "SELECT message, context_json FROM system_event_log WHERE source = 'room_packages' AND message LIKE '%$tg%'"],
]);
rp_clean($r, 'toggle');
ok('toggle: the package is switched off', (int)($r['verify'][0][0]['is_active'] ?? 1) === 0, json_encode($r['verify'] ?? $r));
ok('toggle: System Logs line "Package deactivated: <name>" with old -> new', strpos((string)($r['verify'][1][0]['message'] ?? ''), 'Package deactivated: ' . $tg) !== false
    && strpos((string)($r['verify'][1][0]['context_json'] ?? ''), '"old":"active"') !== false, json_encode($r['verify'][1] ?? null));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
