<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Add / take off nights + pay-later final invoice email (owner 2026-11-13). Run from the TEST hotel's root only
 * (Liwonde):   php scripts/tests/stay_nights.php
 * Every scenario runs in scripts/tests/page_harness.php (one outer transaction that never commits), so the test
 * bookings, payments, refunds and credit notes it creates are gone afterwards; PDFs it writes are deleted at the end.
 * Test guests have no email address (the pay-later invoice test uses an @example.invalid address and must NOT send).
 *   - quote / apply +1 and -1 at the booked rate, History old -> new, refusals (no reason, stale figures, no figures);
 *   - paid stay -1 night: money back by refund (in full, completed) or credit note, amount retyped, permission;
 *   - nights already stayed cannot be taken off; only in-house stays;
 *   - bookings.php stay_nights_quote / stay_nights_apply / extend_stay (History) / admin_update_checkout_date (L1);
 *   - L2: pay-later booking final invoice not emailed unless ticked; checkout_assess says pay_later.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
$harness = $root . '/scripts/tests/page_harness.php';
function sn_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'credit-notes', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$pdfBefore = sn_pdf_set($root);
register_shutdown_function(function () use ($root, $pdfBefore) {
    foreach (array_diff_key(sn_pdf_set($root), $pdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function sn_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [],
        'vars' => $opts['vars'] ?? []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
function sn_child(string $scenario): array {
    $r = sn_run('scripts/tests/stay_nights_child.php', ['scenario' => $scenario]);
    if (!isset($r['json'])) { echo "child $scenario output: " . substr((string)($r['raw'] ?? json_encode($r)), -1200) . "\n"; }
    return $r['json'] ?? [];
}
$near = static function ($a, $b): bool { return abs((float)$a - (float)$b) <= 0.011; };
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$in2 = date('Y-m-d', strtotime('+2 day'));

echo "== permissions (pure) ==\n";
require_once $root . '/admin/includes/permissions.php';
foreach (['extend_stay', 'stay_nights_quote', 'stay_nights_apply'] as $a) {
    ok("$a needs checkout_guest", rhBookingActionPermission($a) === 'checkout_guest');
}

echo "== +1 / -1 on an unpaid stay ==\n";
$a = sn_child('add_unpaid');
$qa = $a['q_add'] ?? [];
ok('quote +1: ok, 3 nights, new check-out +2 days', !empty($qa['ok']) && (int)($qa['new_nights'] ?? 0) === 3 && ($qa['new_check_out'] ?? '') === $in2, json_encode($qa));
ok('quote +1: booked rate 1,165 x 3 = 3,495, +1,165', $near($qa['new_total'] ?? 0, 3495) && $near($qa['total_delta'] ?? 0, 1165) && $near($qa['per_night'] ?? 0, 1165));
ok('quote +1: balance 2,330 -> 3,495, nothing goes back', $near($qa['old_due'] ?? 0, 2330) && $near($qa['new_due'] ?? 0, 3495) && $near($qa['money_back'] ?? -1, 0));
ok('refused without a reason', stripos($a['no_reason'] ?? '', 'reason') !== false, $a['no_reason'] ?? '');
ok('refused when the figures changed since review', stripos($a['stale'] ?? '', 'figures changed') !== false, $a['stale'] ?? '');
ok('refused without reviewed figures', stripos($a['no_figures'] ?? '', 'Review') !== false, $a['no_figures'] ?? '');
ok('refusals changed nothing', ($a['unchanged']['check_out_date'] ?? '') === $tomorrow && (int)($a['unchanged']['number_of_nights'] ?? 0) === 2);
$aa = $a['after_add'] ?? [];
ok('+1 saved: check-out, 3 nights, total 3,495, due 3,495', ($aa['check_out_date'] ?? '') === $in2 && (int)($aa['number_of_nights'] ?? 0) === 3 && $near($aa['total_with_vat'] ?? 0, 3495) && $near($aa['amount_due'] ?? 0, 3495), json_encode($aa));
ok('+1 saved: VAT stays inside the gross (net + VAT = total)', $near((float)($aa['total_amount'] ?? 0) + (float)($aa['vat_amount'] ?? 0), 3495), json_encode($aa));
ok('History: audit stay_nights_changed old -> new', ($a['audit']['action'] ?? '') === 'stay_nights_changed' && strpos((string)($a['audit']['old_values'] ?? ''), $tomorrow) !== false && strpos((string)($a['audit']['new_values'] ?? ''), $in2) !== false, json_encode($a['audit'] ?? []));
ok('History: timeline "Nights added" with the reason', ($a['timeline']['action'] ?? '') === 'Nights added' && strpos((string)($a['timeline']['description'] ?? ''), 'company LPO') !== false);
ok('quote -1: back to 2 nights, 2,330', !empty($a['q_minus']['ok']) && $near($a['q_minus']['new_total'] ?? 0, 2330) && $near($a['q_minus']['money_back'] ?? -1, 0));
ok('quote -2 (to today) refused: nights already stayed / leaving today = Check out', empty($a['q_minus_to_today']['ok']) && stripos((string)($a['q_minus_to_today']['error'] ?? ''), 'Check out') !== false);
ok('quote 0 refused', empty($a['q_zero']['ok']));
$am = $a['after_minus'] ?? [];
ok('-1 unpaid: only the balance goes down, no refund, no credit', $near($am['total_with_vat'] ?? 0, 2330) && $near($am['amount_due'] ?? 0, 2330) && $near($am['credit_balance'] ?? -1, 0) && (int)($a['refund_rows'] ?? -1) === 0, json_encode($am));

foreach (['paid_refund' => 'refund', 'paid_credit' => 'credit note'] as $scn => $label) {
    echo "== paid stay -1 night: $label ==\n";
    $p = sn_child($scn);
    ok("$label: quote says 1,165 goes back", $near($p['q']['money_back'] ?? 0, 1165) && $near($p['q']['new_total'] ?? 0, 2330), json_encode($p['q'] ?? []));
    ok("$label: refused without a refund/credit choice", stripos($p['no_mode'] ?? '', 'refund or credit note') !== false, $p['no_mode'] ?? '');
    ok("$label: refund refused without refund_payment", stripos($p['refund_no_perm'] ?? '', 'permission') !== false, $p['refund_no_perm'] ?? '');
    ok("$label: refused when the typed amount differs", stripos($p['typed_wrong'] ?? '', 'Type the amount') !== false, $p['typed_wrong'] ?? '');
    ok("$label: refusals changed nothing", $near($p['unchanged']['total_with_vat'] ?? 0, 3495) && $near($p['unchanged']['amount_paid'] ?? 0, 3495));
    $af = $p['after'] ?? [];
    ok("$label: bill 2,330, paid 2,330, nothing due, no credit left on the booking", $near($af['total_with_vat'] ?? 0, 2330) && $near($af['amount_paid'] ?? 0, 2330) && $near($af['amount_due'] ?? -1, 0) && $near($af['credit_balance'] ?? -1, 0) && ($af['payment_status'] ?? '') === 'paid', json_encode($af));
    ok("$label: refund legs total 1,165, completed", $near($p['refunds']['s'] ?? 0, 1165) && ($p['refunds']['st'] ?? '') === 'completed', json_encode($p['refunds'] ?? []));
    if ($scn === 'paid_credit') {
        ok('credit note issued for 1,165', $near($p['cn']['amount'] ?? 0, 1165) && ($p['cn']['reason'] ?? '') === 'early_checkout', json_encode($p['cn'] ?? []));
    } else {
        ok('refund: no credit note', empty($p['res']['credit_note']) && $near($p['res']['refund_total'] ?? 0, 1165));
    }
    ok("$label: History stay_nights_changed", ($p['audit_action'] ?? '') === 'stay_nights_changed');
}

echo "== discount removed by the change ==\n";
$pd = sn_child('plan_dropped');
$pq = $pd['q'] ?? [];
ok('-1 night under a dropped minimum-stay discount: plan_note set, bill goes UP 3,495 -> 4,330 (+835)', !empty($pq['ok']) && trim((string)($pq['plan_note'] ?? '')) !== ''
    && $near($pq['new_total'] ?? 0, 4330) && $near($pq['total_delta'] ?? 0, 835), json_encode($pq));
$bkJs = (string)file_get_contents($root . '/admin/bookings.php');
ok('review card: with a removed discount it shows the signed bill change, not "nights x rate"', strpos($bkJs, "the bill goes up by ' + snMoney(d)") !== false
    && strpos($bkJs, 'because the discount no longer applies to the new stay') !== false);

echo "== only in-house ==\n";
$n = sn_child('not_in_house');
ok('confirmed (not checked in) refused', empty($n['q']['ok']) && stripos((string)($n['q']['error'] ?? ''), 'checked in') !== false);

echo "== L2: pay-later final invoice ==\n";
$f = sn_child('final_invoice_pay_later');
ok('invoice generated, not emailed (skipped)', !empty($f['inv']['success']) && empty($f['inv']['email_sent']) && !empty($f['inv']['email_skipped']), json_encode($f['inv'] ?? []));
ok('booking: final invoice generated, never marked sent', (int)($f['row']['final_invoice_generated'] ?? 0) === 1 && empty($f['row']['final_invoice_sent_at']));
ok('email_send_log not_sent row, nothing sent', ($f['not_sent']['status'] ?? '') === 'not_sent' && (int)($f['sent_rows'] ?? -1) === 0, json_encode($f['not_sent'] ?? []));
ok('History: "Final invoice not emailed"', ($f['timeline'] ?? '') === 'Final invoice not emailed');

echo "== bookings.php (rolled back) ==\n";
$pick = sn_child('pick_room');
$roomSql = (string)(int)($pick['room_id'] ?? 0);
ok('a room type with a free room was found for the page tests', (int)$roomSql > 0);
$mk = static function (string $ref, int $outDays, string $extra = '') use ($roomSql): string {
    $in = date('Y-m-d', strtotime('-1 day')); $out = date('Y-m-d', strtotime('+' . $outDays . ' day')); $n = $outDays + 1;
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests, child_price_multiplier,
        check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent, vat_rate, vat_amount, total_with_vat, amount_due,
        special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at{$extra})
        VALUES ('{$ref}', {$roomSql}, 'STAY NIGHTS PAGE', '', '+265000000009', 'Malawi', 1, 1, 0, 50, '{$in}', '{$out}', {$n}, " . (1000 * $n) . ", 0, 0, 0, 16.5, " . (165 * $n) . ", " . (1165 * $n) . ", " . (1165 * $n) . ",
        'stay nights page test', 'checked-in', 'unpaid', 0, 'single', '" . bin2hex(random_bytes(16)) . "', NOW()" . ($extra !== '' ? ", 1, 'TEST CO (LPO)'" : '') . ")";
};
$ref1 = 'SNPG' . random_int(10000, 99999);
$r = sn_run('admin/bookings.php', ['action' => 'stay_nights_quote', 'booking_id' => '{bid}', 'delta' => '1'], ['ajax' => true, 'setup' => [$mk($ref1, 1)],
    'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref1}'"]]);
$jq = $r['json']['quote'] ?? [];
ok('page quote: success, 3,495, availability checked or reported', !empty($r['json']['success']) && $near($jq['new_total'] ?? 0, 3495) && array_key_exists('available', $jq), json_encode($r['json'] ?? $r['raw'] ?? ''));

$ref2 = 'SNPG' . random_int(10000, 99999);
$r = sn_run('admin/bookings.php', ['action' => 'stay_nights_apply', 'booking_id' => '{bid}', 'delta' => '1', 'expected_new_total' => '3495', 'expected_total_delta' => '1165', 'reason' => 'page test'],
    ['ajax' => true, 'setup' => [$mk($ref2, 1)], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref2}'"],
     'verify' => ["SELECT check_out_date, number_of_nights, total_with_vat FROM bookings WHERE booking_reference = '{$ref2}'"]]);
$v = $r['verify'][0][0] ?? [];
ok('page apply +1: saved', !empty($r['json']['success']) && ($v['check_out_date'] ?? '') === $in2 && (int)($v['number_of_nights'] ?? 0) === 3, json_encode([$r['json'] ?? $r['raw'] ?? '', $v]));

$ref3 = 'SNPG' . random_int(10000, 99999);
$r = sn_run('admin/bookings.php', ['action' => 'extend_stay', 'booking_id' => '{bid}', 'new_checkout' => $in2],
    ['ajax' => true, 'setup' => [$mk($ref3, 1)], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref3}'"],
     'verify' => ["SELECT a.action, a.old_values, a.new_values FROM booking_audit_log a JOIN bookings b ON b.id = a.booking_id WHERE b.booking_reference = '{$ref3}' ORDER BY a.id DESC LIMIT 1",
                  "SELECT t.action FROM booking_timeline_logs t JOIN bookings b ON b.id = t.booking_id WHERE b.booking_reference = '{$ref3}' ORDER BY t.id DESC LIMIT 1"]]);
$v = $r['verify'][0][0] ?? [];
ok('extend_stay: History old -> new (audit + timeline)', !empty($r['json']['success']) && ($v['action'] ?? '') === 'stay_extended' && strpos((string)($v['new_values'] ?? ''), $in2) !== false
    && (($r['verify'][1][0]['action'] ?? '') === 'Stay extended'), json_encode([$r['json'] ?? $r['raw'] ?? '', $v]));

$ref4 = 'SNPG' . random_int(10000, 99999);
$r = sn_run('admin/bookings.php', ['action' => 'admin_update_checkout_date', 'booking_id' => '{bid}', 'new_checkout' => $tomorrow, 'reason' => 'L1 test'],
    ['ajax' => true, 'setup' => [$mk($ref4, 2),
        "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, status, created_at)
         SELECT 'SNPP" . random_int(100000, 999999) . "', 'room', id, booking_reference, CURDATE(), 3000, 16.5, 495, 3495, 'cash', 'full_payment', 'completed', 'completed', NOW() FROM bookings WHERE booking_reference = '{$ref4}'"],
     'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref4}'"],
     'verify' => ["SELECT check_out_date, total_with_vat FROM bookings WHERE booking_reference = '{$ref4}'"]]);
$v = $r['verify'][0][0] ?? [];
ok('L1: shortening a paid stay by date correction is refused, nothing changed', isset($r['json']['success']) && $r['json']['success'] === false && stripos((string)($r['json']['message'] ?? ''), 'Nothing was changed') !== false
    && ($v['check_out_date'] ?? '') === $in2 && $near($v['total_with_vat'] ?? 0, 3495), json_encode([$r['json'] ?? $r['raw'] ?? '', $v]));

$ref5 = 'SNPG' . random_int(10000, 99999);
$r = sn_run('admin/bookings.php', ['action' => 'checkout_assess', 'id' => '{bid}'],
    ['ajax' => true, 'setup' => [$mk($ref5, 0, ', checkin_without_payment, checkin_arrangement_note'), "UPDATE bookings SET guest_email = 'stay.nights@example.invalid' WHERE booking_reference = '{$ref5}'"],
     'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref5}'"]]);
ok('checkout_assess: pay_later + guest email flagged (unticked box shown)', !empty($r['json']['success']) && !empty($r['json']['pay_later']) && !empty($r['json']['guest_email_on_file'])
    && ($r['json']['pay_later_note'] ?? '') === 'TEST CO (LPO)', json_encode($r['json'] ?? $r['raw'] ?? ''));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
