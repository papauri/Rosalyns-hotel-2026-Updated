<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Group bookings plan Ship A (owner 2026-11-17). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/group_stay_correction_test.php
 * Every scenario runs in scripts/tests/page_harness.php (one outer transaction that never commits): the checked-out
 * fixture group, payments, refunds and credit notes it makes are gone afterwards. No PDF is generated, nothing is emailed.
 *   - A4 group table: every room of the group, all statuses (no-show kept with its paid amount, deleted left out), each
 *     row = that room's own getBookingFolioSummary, group totals = sum of the rows;
 *   - A8 correct a checked-out room: early departure lowers the bill and the owed figure, money back is a refund or a
 *     credit note (never credit_balance); late departure adds nights at the booked rate (or a changed rate with a
 *     reason); a clash needs the tick; snapshot guard; status guards; permission; stale figures;
 *   - booking-details.php: preview as admin, refused without correct_stay_after_checkout;
 *   - A9: bookings.php checkout stamps checkout_completed_at.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
$harness = $root . '/scripts/tests/page_harness.php';
function gst_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'credit-notes', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = (int)$f->getMTime(); }
        }
    }
    return $set;
}
$pdfBefore = gst_pdf_set($root);
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function gst_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = ['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [],
        'vars' => $opts['vars'] ?? []];
    if (!empty($opts['as_username'])) { $cfg['as_username'] = $opts['as_username']; }
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
function gst_child(string $scenario): array {
    $r = gst_run('scripts/tests/group_stay_correction_child.php', ['scenario' => $scenario]);
    if (!isset($r['json'])) { echo "child $scenario output: " . substr((string)($r['raw'] ?? json_encode($r)), -1500) . "\n"; }
    return $r['json'] ?? [];
}
$near = static function ($a, $b): bool { return abs((float)$a - (float)$b) <= 0.011; };
$has = static function ($hay, string $needle): bool { return stripos((string)$hay, $needle) !== false; };

echo "== permission (pure) ==\n";
require_once $root . '/admin/includes/permissions.php';
$K = 'correct_stay_after_checkout';
ok('permission is defined', isset(getAllPermissions()[$K]));
ok('manager has it by default', in_array($K, getDefaultPermissionsForRole('manager'), true));
ok('administrator has it', in_array($K, getDefaultPermissionsForRole('admin'), true));
$others = array_diff(array_keys(getAllRoles()), ['admin', 'manager']);
ok('no other role has it by default', !array_filter($others, static function ($r) use ($K) { return in_array($K, getDefaultPermissionsForRole($r), true); }));
ok('both actions need it', rhBookingActionPermission('preview_stay_correction') === $K && rhBookingActionPermission('stay_correction_apply') === $K);

echo "== A4 group table ==\n";
$g = gst_child('group_table');
ok('root found from a member room', ($g['root'] ?? 0) === ($g['expect_root'] ?? -1), json_encode([$g['root'] ?? null, $g['expect_root'] ?? null]));
ok('every room in, all statuses, deleted room left out', ($g['ids'] ?? []) === ($g['expect_ids'] ?? [1]) && !empty($g['deleted_out']), json_encode([$g['ids'] ?? null, $g['expect_ids'] ?? null]));
ok('each row = that room\'s own getBookingFolioSummary (billed, paid, owed, credit)', !empty($g['rows_match']), json_encode($g['mismatch'] ?? []));
$tt = $g['totals'] ?? [];
$sm = $g['sum'] ?? [];
ok('group totals = sum of the rooms', $near($tt['billed'] ?? 0, $sm['billed'] ?? -1) && $near($tt['paid'] ?? 0, $sm['paid'] ?? -1) && $near($tt['owed'] ?? 0, $sm['owed'] ?? -1)
    && $near($tt['credit'] ?? 0, $sm['credit'] ?? -1) && $near($tt['refunds'] ?? 0, $sm['refunds'] ?? -1), json_encode([$tt, $sm]));
ok('ICTAm shape: billed 3 x 3,495, paid 3 x 3,000, owed 3 x 495, net position 1,485', $near($tt['billed'] ?? 0, 10485) && $near($tt['paid'] ?? 0, 9000)
    && $near($tt['owed'] ?? 0, 1485) && $near($tt['net'] ?? 0, 1485), json_encode($tt));
ok('no-show room kept with its paid amount', ($g['noshow']['status'] ?? '') === 'no-show' && $near($g['noshow']['paid'] ?? 0, 3000), json_encode($g['noshow'] ?? []));
ok('row shows booked rate x nights', $has($g['rate_compact'] ?? '', '1,165') && $has($g['rate_compact'] ?? '', '3'), (string)($g['rate_compact'] ?? ''));
ok('same table from the lead room', !empty($g['from_root_same']));
ok('non-lead room preview names the group invoice number', in_array($g['group_inv_expect'] ?? '#', (array)($g['group_inv_nums'] ?? []), true), json_encode($g['group_inv_nums'] ?? null));

foreach (['early_credit' => 'credit note', 'early_refund' => 'refund'] as $sc => $label) {
    echo "== A8 early departure, money back by $label ==\n";
    $e = gst_child($sc);
    $q = $e['q'] ?? [];
    ok("$label: quote 3 -> 2 nights, bill 3,495 -> 2,330, owed 495 -> 0, 670 goes back", !empty($q['ok']) && (int)($q['new_nights'] ?? 0) === 2
        && $near($q['old_bill'] ?? 0, 3495) && $near($q['new_bill'] ?? 0, 2330) && $near($q['old_due'] ?? 0, 495) && $near($q['new_due'] ?? -1, 0)
        && $near($q['money_back'] ?? 0, 670), json_encode($q));
    ok("$label: refused without the permission", $has($e['no_perm'] ?? '', 'permission'), (string)($e['no_perm'] ?? ''));
    ok("$label: refused without a reason", $has($e['no_reason'] ?? '', 'reason'), (string)($e['no_reason'] ?? ''));
    ok("$label: refused until refund or credit note is chosen", $has($e['no_mode'] ?? '', 'refund or credit note'), (string)($e['no_mode'] ?? ''));
    ok("$label: refund needs refund_payment", $has($e['refund_no_perm'] ?? '', 'permission to refund'), (string)($e['refund_no_perm'] ?? ''));
    ok("$label: refused when the figures changed", $has($e['stale'] ?? '', 'figures changed'), (string)($e['stale'] ?? ''));
    ok("$label: refusals changed nothing", (int)($e['unchanged']['number_of_nights'] ?? 0) === 3 && $near($e['unchanged']['total_with_vat'] ?? 0, 3495), json_encode($e['unchanged'] ?? []));
    $row = $e['row'] ?? [];
    ok("$label: saved: 2 nights, bill 2,330, still checked out", (int)($row['number_of_nights'] ?? 0) === 2 && $near($row['total_with_vat'] ?? 0, 2330)
        && ($row['status'] ?? '') === 'checked-out', json_encode($row));
    ok("$label: never left as credit_balance; paid 2,330, owed 0", $near($row['credit_balance'] ?? -1, 0) && $near($e['fs']['amount_paid'] ?? 0, 2330)
        && $near($e['fs']['balance_due'] ?? -1, 0), json_encode([$row, $e['fs'] ?? []]));
    $rr = $e['refund_rows'] ?? [];
    if ($sc === 'early_refund') {
        ok('refund: 670 refunded to the original payment', $near($e['apply']['refund_total'] ?? 0, 670) && $near($rr['s'] ?? 0, 670) && ($rr['m'] ?? '') === 'bank_transfer', json_encode([$e['apply'] ?? [], $rr]));
        ok('refund: group table shows it under Refunds', $near($e['table_root']['refunds'] ?? 0, 670) && $near($e['table_root']['cn'] ?? -1, 0), json_encode($e['table_root'] ?? []));
    } else {
        ok('credit note: one credit note, legs are credit_note', (int)($e['apply']['credit_notes'] ?? 0) === 1 && $near($rr['s'] ?? 0, 670) && ($rr['m'] ?? '') === 'credit_note', json_encode([$e['apply'] ?? [], $rr]));
        ok('credit note: not counted as a refund paid out', $near($e['table_root']['refunds'] ?? -1, 0) && $near($e['table_root']['cn'] ?? 0, 670), json_encode($e['table_root'] ?? []));
    }
    ok("$label: History entry with old -> new and the reason", ($e['timeline']['action'] ?? '') === 'Stay corrected after check-out'
        && $has($e['timeline']['description'] ?? '', 'guest left a day early') && $has($e['timeline']['description'] ?? '', '-> '), json_encode($e['timeline'] ?? []));
    ok("$label: audit + date-adjustment log rows", ($e['audit'] ?? '') === 'stay_corrected_after_checkout' && !empty($e['date_log']['new_check_out_date']), json_encode([$e['audit'] ?? '', $e['date_log'] ?? []]));
    ok("$label: group table row = 2,330 billed, nothing owed", $near($e['table_root']['billed'] ?? 0, 2330) && $near($e['table_root']['owed'] ?? -1, 0));
}

echo "== A8 late departure ==\n";
$l = gst_child('late');
$qr = $l['q_rate'] ?? [];
ok('changed rate: 3 nights at 1,165 + 1 at 1,000 = 4,495, owed 1,495', !empty($qr['ok']) && !empty($qr['rate_changed']) && $near($qr['new_bill'] ?? 0, 4495)
    && $near($qr['new_due'] ?? 0, 1495) && $has($qr['rate_line_new'] ?? '', '1,000'), json_encode($qr));
ok('changed rate needs a rate reason', $has($l['rate_no_reason'] ?? '', 'rate reason'), (string)($l['rate_no_reason'] ?? ''));
ok('changed rate saved', $near($l['m2_after']['total_with_vat'] ?? 0, 4495) && (int)($l['m2_after']['number_of_nights'] ?? 0) === 4, json_encode($l['m2_after'] ?? []));
$q = $l['q'] ?? [];
ok('extra night at the booked rate: 4 x 1,165 = 4,660, owed 1,660', !empty($q['ok']) && (int)($q['extra_nights'] ?? 0) === 1 && $near($q['new_bill'] ?? 0, 4660)
    && $near($q['new_due'] ?? 0, 1660) && $near($q['money_back'] ?? -1, 0), json_encode($q));
if ((int)($l['ir'] ?? 0) > 0) {
    // The test DB mirrors real stays, so the room may hold other bookings too: the fixture's must be among them.
    ok('clash with the other booking on the room is a warning', in_array($l['other_ref'] ?? 'x', array_column($q['clashes'] ?? [], 'ref'), true), json_encode($q['clashes'] ?? []));
    ok('clash needs the "I understand" tick', $has($l['no_ack'] ?? '', 'I understand'), (string)($l['no_ack'] ?? ''));
} else {
    echo "[SKIP] no active individual room to test the clash\n";
}
ok('late departure saved with the tick', (int)($l['after']['number_of_nights'] ?? 0) === 4 && $near($l['after']['amount_due'] ?? 0, 1660) && $near($l['after']['credit_balance'] ?? -1, 0), json_encode($l['after'] ?? []));
ok('the stay cannot end after today', $has($l['future'] ?? '', 'cannot end after today'), (string)($l['future'] ?? ''));

echo "== A8 guards ==\n";
$gd = gst_child('guards');
if (!empty($gd['has_snap_col'])) {
    $msg = 'This booking has a cancellation policy; unused nights on it can be corrected after the next update.';
    ok('snapshot: cutting nights at the end refused', ($gd['cut'] ?? '') === $msg, (string)($gd['cut'] ?? ''));
    ok('snapshot: cutting nights at the start refused', ($gd['cut_late_arrival'] ?? '') === $msg, (string)($gd['cut_late_arrival'] ?? ''));
    ok('snapshot: adding a night allowed', !empty($gd['add_ok']));
    ok('snapshot: moving the dates (same nights) allowed', !empty($gd['move_ok']));
} else {
    echo "[SKIP] cancellation_policy_snapshot column not present\n";
}
ok('no snapshot: cut allowed, no automatic fee (2 x 1,165)', !empty($gd['nosnap_cut']['ok']) && $near($gd['nosnap_cut']['new_room_total'] ?? 0, 2330), json_encode($gd['nosnap_cut'] ?? []));
ok('no-show refused', $has($gd['noshow'] ?? '', 'Only a checked-out stay'), (string)($gd['noshow'] ?? ''));
ok('cancelled refused', $has($gd['cancelled'] ?? '', 'Only a checked-out stay'), (string)($gd['cancelled'] ?? ''));
ok('deleted refused', $has($gd['deleted'] ?? '', 'deleted'), (string)($gd['deleted'] ?? ''));
ok('unchanged dates refused', $has($gd['same'] ?? '', 'same as now'), (string)($gd['same'] ?? ''));

echo "== booking-details.php (page) ==\n";
$roomId = (int)$pdo->query("SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ref = 'GSPG' . random_int(100000, 999999);
$in = date('Y-m-d', strtotime('-6 day'));
$out = date('Y-m-d', strtotime('-3 day'));
$setup = ["INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
        child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
        vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
    VALUES ('$ref', $roomId, 'GROUP STAY TEST', '', '+265000000009', 'Malawi', 1, 1, 0, 50, '$in', '$out', 3, 3000, 0, 0, 0, 16.5, 495, 3495,
        'group stay correction test', 'checked-out', 'unpaid', 0, 'single', '" . bin2hex(random_bytes(16)) . "', NOW())"];
$vars = ['bid' => "SELECT id FROM bookings WHERE booking_reference = '$ref'"];
$post = ['action' => 'preview_stay_correction', 'new_check_in' => $in, 'new_check_out' => date('Y-m-d', strtotime('-4 day'))];
$p = gst_run('admin/booking-details.php', $post, ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'get' => ['id' => '{bid}']]);
ok('preview as admin: 2 nights, bill 2,330', !empty($p['json']['success']) && $near($p['json']['quote']['new_bill'] ?? 0, 2330), json_encode($p['json'] ?? ($p['raw'] ?? '')));
$staff = null;
foreach ($pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin', 'manager') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    if (!hasPermission((int)$u['id'], $K)) { $staff = (string)$u['username']; break; }
}
if ($staff !== null) {
    $p2 = gst_run('admin/booking-details.php', ['action' => 'stay_correction_apply'] + $post + ['reason' => 'x test', 'expected_new_bill' => 2330, 'expected_money_back' => 0],
        ['ajax' => true, 'setup' => $setup, 'vars' => $vars, 'get' => ['id' => '{bid}'], 'as_username' => $staff,
         'verify' => ["SELECT number_of_nights FROM bookings WHERE booking_reference = '$ref'"]]);
    ok('save refused without correct_stay_after_checkout', empty($p2['json']['success']) && $has($p2['json']['message'] ?? '', 'permission')
        && (int)($p2['verify'][0][0]['number_of_nights'] ?? 0) === 3, json_encode([$p2['json'] ?? ($p2['raw'] ?? ''), $p2['verify'] ?? null]));
} else {
    echo "[SKIP] no active non-admin, non-manager user without the permission\n";
}

echo "== A9 checkout stamps checkout_completed_at ==\n";
$ref9 = 'GSCO' . random_int(100000, 999999);
$today = date('Y-m-d');
$in9 = date('Y-m-d', strtotime('-2 day'));
$setup9 = ["INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
        child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
        vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, final_invoice_generated, final_invoice_number, created_at)
    VALUES ('$ref9', $roomId, 'GROUP STAY TEST', '', '+265000000009', 'Malawi', 1, 1, 0, 50, '$in9', '$today', 2, 2000, 0, 0, 0, 16.5, 330, 2330,
        'group stay correction test', 'checked-in', 'unpaid', 0, 'single', '" . bin2hex(random_bytes(16)) . "', 1, 'ZZ-GSCT-TEST', NOW())"];
$c = gst_run('admin/bookings.php', ['action' => 'checkout', 'id' => '{bid}', 'confirm_checkout_with_balance' => '1'],
    ['setup' => $setup9, 'vars' => ['bid' => "SELECT id FROM bookings WHERE booking_reference = '$ref9'"],
     'verify' => ["SELECT status, checkout_completed_at IS NOT NULL AS stamped FROM bookings WHERE booking_reference = '$ref9'"]]);
$v = $c['verify'][0][0] ?? [];
ok('checkout sets status and checkout_completed_at', ($v['status'] ?? '') === 'checked-out' && (int)($v['stamped'] ?? 0) === 1, json_encode([$v, $c['raw'] ?? '']));

$changed = array_diff_assoc(gst_pdf_set($root), $pdfBefore);
ok('no PDF written or overwritten', !$changed, implode(', ', array_keys($changed)));
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
