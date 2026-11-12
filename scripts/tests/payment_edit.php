<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Edit payment + quick pay (owner 2026-11-12). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/payment_edit.php
 * Every scenario runs in scripts/tests/page_harness.php (one outer transaction that never commits), so the test
 * bookings and payments it creates are gone afterwards; receipt PDFs it writes are deleted at the end. Guests have no
 * email address and the edit's email box is unticked, so nothing is emailed.
 *   - full -> part payment: booking due / status, VAT split, payment_type, audit rows, History step with the reason;
 *   - refused: no reason, zero, above what was owed, future date, nothing changed, below what was refunded,
 *     till / refund / pending rows, and without the payment_edit permission (server-side);
 *   - unticked email box: no send, an email_send_log 'not_sent' row; receipt marked "Corrected receipt";
 *   - group member: only that member's figures change;
 *   - quick pay (Bookings dialog -> payment-add.php): a part payment records exactly the typed amount as a part
 *     payment and returns to the list; more than owed is refused; the old one-click "paid" request records nothing.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
$harness = $root . '/scripts/tests/page_harness.php';
function pe_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'quotations'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$pdfBefore = pe_pdf_set($root);
register_shutdown_function(function () use ($root, $pdfBefore) {
    foreach (array_diff_key(pe_pdf_set($root), $pdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function pe_run(string $page, array $post = [], array $opts = []): array {
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
function pe_child(string $scenario): array {
    $r = pe_run('scripts/tests/payment_edit_child.php', ['scenario' => $scenario]);
    if (!isset($r['json'])) { echo "child $scenario output: " . substr((string)($r['raw'] ?? json_encode($r)), -800) . "\n"; }
    return $r['json'] ?? [];
}
$near = static function ($a, $b): bool { return abs((float)$a - (float)$b) <= 0.011; };

echo "== permission tiers (pure) ==\n";
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/finance-account-sync.php'; // rh_payment_api_edit_refusal (pure)
$all = getAllPermissions();
ok('payment_edit exists in Finance, not a page', isset($all['payment_edit']) && $all['payment_edit']['category'] === 'Finance' && $all['payment_edit']['page'] === '');
ok('payment_edit is grantable (not administrators-only)', !in_array('payment_edit', rh_admin_only_permissions(), true));
foreach (['manager', 'accountant', 'receptionist'] as $role) {
    ok("$role: off by default", rh_permission_resolve($role, false, 'payment_edit', null, getDefaultPermissionsForRole($role), [], [], 1) === false);
    ok("$role: on when granted in Permissions", rh_permission_resolve($role, false, 'payment_edit', true, getDefaultPermissionsForRole($role), [], [], 1) === true);
}
ok('administrator has payment_edit', rh_permission_resolve('admin', false, 'payment_edit', null, [], [], [], 1) === true);
ok('a super admin can switch it off for an administrator', rh_permission_resolve('admin', false, 'payment_edit', null, [], ['payment_edit' => true], [], 1) === false);
ok('super admin has payment_edit', rh_permission_resolve('admin', true, 'payment_edit', null, [], [], [], 1) === true);

echo "== edit: full -> part payment ==\n";
$m = pe_child('main');
$exVat = (float)($m['expected_vat']['vat'] ?? -1);
ok('setup: booking paid in full', ($m['before']['payment_status'] ?? '') === 'paid' && $near($m['before']['amount_due'] ?? -1, 0), json_encode($m['before'] ?? null));
ok('no reason refused', stripos((string)($m['no_reason'] ?? ''), 'reason') !== false, (string)($m['no_reason'] ?? ''));
ok('zero refused', stripos((string)($m['zero'] ?? ''), 'more than zero') !== false, (string)($m['zero'] ?? ''));
ok('above what was owed refused', stripos((string)($m['above'] ?? ''), 'more than the booking owed') !== false, (string)($m['above'] ?? ''));
ok('future date refused', stripos((string)($m['future'] ?? ''), 'not in the future') !== false, (string)($m['future'] ?? ''));
ok('nothing changed refused', stripos((string)($m['nothing'] ?? ''), 'Nothing was changed') !== false, (string)($m['nothing'] ?? ''));
ok('refusals saved nothing', $near($m['unchanged_after_refusals']['total_amount'] ?? 0, 1165));
ok('payment now 500, part payment, mobile money', $near($m['pay_after']['total_amount'] ?? 0, 500) && ($m['pay_after']['payment_type'] ?? '') === 'partial_payment'
    && ($m['pay_after']['payment_method'] ?? '') === 'mobile_money', json_encode($m['pay_after'] ?? null));
ok('VAT split recomputed pro rata (net + VAT = gross)', $near($m['pay_after']['vat_amount'] ?? -1, $exVat) && $exVat > 60
    && $near((float)($m['pay_after']['payment_amount'] ?? 0) + (float)($m['pay_after']['vat_amount'] ?? 0), 500), json_encode($m['pay_after'] ?? null));
ok('booking owes 665 and reads partial', $near($m['bk_after']['amount_due'] ?? -1, 665) && ($m['bk_after']['payment_status'] ?? '') === 'partial'
    && $near($m['bk_after']['amount_paid'] ?? -1, 500), json_encode($m['bk_after'] ?? null));
ok('result: owed before this payment 1165, due 0 -> 665', $near($m['res']['owed_without'] ?? 0, 1165) && $near($m['res']['due_before'] ?? -1, 0) && $near($m['res']['due_after'] ?? -1, 665));
$aud = [];
foreach ($m['audit'] ?? [] as $a) { $aud[(string)$a['field_name']] = $a; }
ok('audit: amount 1165 -> 500', isset($aud['amount']) && $near($aud['amount']['old_value'], 1165) && $near($aud['amount']['new_value'], 500), json_encode($m['audit'] ?? null));
ok('audit: type full -> partial, method cash -> mobile money', ($aud['payment_type']['old_value'] ?? '') === 'full_payment' && ($aud['payment_type']['new_value'] ?? '') === 'partial_payment'
    && ($aud['payment_method']['new_value'] ?? '') === 'mobile_money');
ok('audit: the reason row', isset($aud['']) && ($aud['']['new_value'] ?? '') === 'Reason: guest paid part only');
ok('History: "Payment ... changed" step with the reason and the type', !empty($m['hist_changed']) && !empty($m['hist_reason']) && !empty($m['hist_type']));
ok('email box unticked: not_sent row, kind receipt, for this booking', ($m['not_sent']['status'] ?? '') === 'not_sent' && ($m['not_sent']['email_kind'] ?? '') === 'receipt',
    json_encode($m['not_sent'] ?? null));
ok('email box unticked: nothing sent', (int)($m['sent_rows'] ?? -1) === 0 && empty($m['receipt_emailed']['receipt_emailed_at']));
ok('receipt keeps its number and is marked corrected', ($m['corrected_event']['event_type'] ?? '') === 'corrected' && !empty($m['ctx_corrected']) && !empty($m['pdf_has_corrected']),
    json_encode($m['corrected_event'] ?? null));
ok('below what was refunded refused', stripos((string)($m['below_refund'] ?? ''), 'already refunded') !== false, (string)($m['below_refund'] ?? ''));
ok('edited down to the refunded amount: payment reads refunded', ($m['at_refund_status']['payment_status'] ?? '') === 'refunded', json_encode($m['at_refund_status'] ?? null));
ok('till payment refused', stripos((string)($m['refusal_restaurant'] ?? ''), 'Till') !== false);
ok('refund row refused', stripos((string)($m['refusal_refund'] ?? ''), 'refund') !== false);
ok('credit-note payment refused (change the credit note instead)', stripos((string)($m['refusal_credit_note'] ?? ''), 'credit note') !== false);
ok('pending row refused (payment form instead)', stripos((string)($m['refusal_pending'] ?? ''), 'pending') !== false);
ok('without payment_edit: no permission', ($m['perm_accountant'] ?? null) === false && ($m['perm_admin'] ?? null) === true);
ok('accountant WITH the payment_edit grant can edit', ($m['perm_granted'] ?? null) === true && $near($m['perm_granted_total']['total_amount'] ?? 0, 600), json_encode([$m['perm_granted'] ?? null, $m['perm_granted_total'] ?? null, $m['perm_granted_err'] ?? '']));

echo "== API: received payment amount / method / date (pure rule) ==\n";
$rec = ['payment_status' => 'completed', 'total_amount' => '1165.00', 'payment_method' => 'cash', 'payment_date' => '2026-11-10'];
ok('API amount change on a received payment refused', stripos((string)rh_payment_api_edit_refusal($rec, ['payment_amount' => 500]), 'Edit payments') !== false);
ok('API method / date change refused', rh_payment_api_edit_refusal($rec, ['payment_method' => 'mobile_money']) !== null && rh_payment_api_edit_refusal($rec, ['payment_date' => '2026-11-11']) !== null);
ok('API same values and other fields still allowed', rh_payment_api_edit_refusal($rec, ['payment_amount' => '1165', 'payment_method' => 'cash', 'payment_date' => '2026-11-10', 'notes' => 'x', 'transaction_reference' => 'T1']) === null);
ok('API pending payment amount still editable', rh_payment_api_edit_refusal(['payment_status' => 'pending'] + $rec, ['payment_amount' => 500]) === null);
ok('without payment_edit: refused server-side, nothing saved', stripos((string)($m['perm_denied'] ?? ''), 'permission') !== false && $near($m['perm_unchanged']['total_amount'] ?? 0, 1165),
    (string)($m['perm_denied'] ?? ''));

echo "== edit: group member ==\n";
$g = pe_child('group');
ok('member: 400 paid, 765 owed, partial', $near($g['member_after']['amount_paid'] ?? -1, 400) && $near($g['member_after']['amount_due'] ?? -1, 765)
    && ($g['member_after']['payment_status'] ?? '') === 'partial', json_encode($g['member_after'] ?? null));
ok('lead booking unchanged', ($g['lead_after'] ?? null) == ($g['lead_before'] ?? 'x') && ($g['lead_after']['payment_status'] ?? '') === 'paid', json_encode($g));
ok('group paid total = 1165 + 400', $near($g['group_paid'] ?? 0, 1565));

echo "== quick pay (Bookings dialog -> payment-add.php) ==\n";
$ref = 'PEQP' . random_int(10000, 99999);
$in = date('Y-m-d', strtotime('+400 day')); $outd = date('Y-m-d', strtotime('+401 day'));
$seed = "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
    child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
    vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, amount_due, created_at)
    VALUES ('$ref',(SELECT id FROM rooms ORDER BY id LIMIT 1),'QUICK PAY TEST','','+265000000009','Malawi',2,2,0,50,'$in','$outd',1,1000,0,0,0,16.5,165,1165,'quick pay test','confirmed','unpaid',0,'double','" . bin2hex(random_bytes(16)) . "',1165,NOW())";
$bq = "(SELECT id FROM bookings WHERE booking_reference = '$ref')";
$post = ['booking_type' => 'room', 'booking_id' => '{bid}', 'payment_status' => 'completed', 'payment_date' => date('Y-m-d'),
    'payment_method' => 'cash', 'payment_amount' => '400.00', 'return_to' => 'bookings', 'client_uuid' => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff))];
$q = pe_run('admin/payment-add.php', $post, ['setup' => [$seed], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '$ref'"],
    'verify' => ["SELECT total_amount, payment_type, payment_method, payment_status FROM payments WHERE booking_type = 'room' AND booking_id = $bq AND deleted_at IS NULL",
                 "SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = $bq"]]);
$qp = $q['verify'][0] ?? [];
ok('part payment recorded: exactly 400, part payment, cash, completed', is_array($qp) && count($qp) === 1 && $near($qp[0]['total_amount'] ?? 0, 400)
    && ($qp[0]['payment_type'] ?? '') === 'partial_payment' && ($qp[0]['payment_method'] ?? '') === 'cash' && ($qp[0]['payment_status'] ?? '') === 'completed', json_encode($q['verify'] ?? $q));
ok('booking owes 765 after the part payment', $near($q['verify'][1][0]['amount_due'] ?? -1, 765) && ($q['verify'][1][0]['payment_status'] ?? '') === 'partial', json_encode($q['verify'][1] ?? null));
$post['payment_amount'] = '1165.00';
$post['client_uuid'] = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
$q2 = pe_run('admin/payment-add.php', $post, ['setup' => [str_replace($ref, $ref . 'F', $seed)], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref}F'"],
    'verify' => ["SELECT total_amount, payment_type FROM payments WHERE booking_type = 'room' AND booking_id = (SELECT id FROM bookings WHERE booking_reference = '{$ref}F') AND deleted_at IS NULL"]]);
ok('full balance recorded as a full payment', $near($q2['verify'][0][0]['total_amount'] ?? 0, 1165) && ($q2['verify'][0][0]['payment_type'] ?? '') === 'full_payment', json_encode($q2['verify'] ?? $q2));
$post['payment_amount'] = '2000.00';
$post['client_uuid'] = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
$q3 = pe_run('admin/payment-add.php', $post, ['setup' => [str_replace($ref, $ref . 'O', $seed)], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref}O'"],
    'verify' => ["SELECT COUNT(*) c FROM payments WHERE booking_type = 'room' AND booking_id = (SELECT id FROM bookings WHERE booking_reference = '{$ref}O')"]]);
ok('more than owed (no overpay tick) refused, nothing recorded', (int)($q3['verify'][0][0]['c'] ?? -1) === 0, json_encode($q3['verify'] ?? $q3));

echo "== old one-click door (a page loaded before the change) ==\n";
$q4 = pe_run('admin/bookings.php', ['action' => 'update_payment', 'id' => '{bid}', 'payment_status' => 'paid'], ['ajax' => true,
    'setup' => [str_replace($ref, $ref . 'M', $seed)], 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '{$ref}M'"],
    'verify' => ["SELECT COUNT(*) c FROM payments WHERE booking_type = 'room' AND booking_id = (SELECT id FROM bookings WHERE booking_reference = '{$ref}M')"]]);
ok('bookings list update_payment "paid" on an owing booking records nothing', (int)($q4['verify'][0][0]['c'] ?? -1) === 0
    && stripos(json_encode($q4['json'] ?? ''), 'Nothing was recorded') !== false, json_encode([$q4['json'] ?? $q4['raw'] ?? '', $q4['verify'] ?? '']));

echo "\nPASS $pass  FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
