<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Payment limits test (owner, 2026-10-20): a payment form never invites an overpayment.
 *   - nothing owed  -> no payment is recorded on any path;
 *   - more than owed -> refused unless the "guest paid more than owed" tick is posted; with it the extra is
 *     issued as a credit note;
 *   - exactly the balance -> fine.
 *   - a fully paid account is never sent an INVOICE (its receipt instead).
 * Usage (project root): php scripts/tests/payment_limits.php
 * Every scenario runs in scripts/tests/page_harness.php (outer transaction that never commits). Guests have no
 * email address, so nothing is emailed.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php'; // pl_reserve() reserves a booking id on this connection
$harness = $root . '/scripts/tests/page_harness.php';
// Document PDFs this run writes under invoices/ (receipts/, credit-notes/ too) and quotations/ are removed at the end;
// a file that existed before the run is never touched. Retried briefly: a synced folder can hold a new file open.
function pl_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'quotations'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$plPdfBefore = pl_pdf_set($root);
register_shutdown_function(function () use ($root, $plPdfBefore) {
    foreach (array_diff_key(pl_pdf_set($root), $plPdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function pl_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [], 'grep' => $opts['grep'] ?? [],
        // opts.ref: the seeded booking's id reaches the POST as the harness {bid} placeholder (no explicit ids)
        'vars' => isset($opts['ref']) ? ['bid' => "SELECT id FROM bookings WHERE booking_reference = " . pl_q($opts['ref'])] : []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    return $res;
}
function pl_child(string $scenario, array $params = [], array $opts = []): array {
    return pl_run('scripts/tests/payment_limits_child.php', ['scenario' => $scenario, 'params' => json_encode($params)], $opts);
}
function pl_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
// No explicit ids: a random huge id moved the live AUTO_INCREMENT even inside a rolled-back run. Rows are found by
// their reference; the one page that reads the id from GET uses an id reserved below the counter (pl_reserve).
function pl_ref(string $prefix): string { return $prefix . random_int(10000, 99999); }
function pl_bq(string $ref): string { return "(SELECT id FROM bookings WHERE booking_reference = " . pl_q($ref) . ")"; }
function pl_booking_sql(string $ref, string $status = 'confirmed', ?int $id = null): string {
    $in = date('Y-m-d', strtotime('+400 day')); $out = date('Y-m-d', strtotime('+401 day'));
    return "INSERT INTO bookings (" . ($id === null ? '' : 'id, ') . "booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (" . ($id === null ? '' : "$id, ") . pl_q($ref) . ", (SELECT id FROM rooms ORDER BY id LIMIT 1), 'LIMITS TEST', '', '+265000000009', 'Malawi', 2, 2, 0, 50, " . pl_q($in) . ", " . pl_q($out) . ", 1, 100, 0, 0, 0, 0, 0, 100, 'limits test', " . pl_q($status) . ", 'unpaid', 0, 'double', " . pl_q(bin2hex(random_bytes(16))) . ", NOW())";
}
/** Inserts the booking with no id and rolls back: the counter is past that id, so the harness may name it safely. */
function pl_reserve(string $ref): int {
    global $pdo;
    $pdo->beginTransaction();
    try { $pdo->exec(pl_booking_sql($ref)); return (int)$pdo->lastInsertId(); } finally { $pdo->rollBack(); }
}
function pl_paid_sql(string $ref, float $amount): string {
    return "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES (" . pl_q('PLT' . $ref) . ", 'room', " . pl_bq($ref) . ", " . pl_q($ref) . ", CURDATE(), $amount, 0, 0, $amount, 'cash', 'partial_payment', 'completed', 'completed', " . pl_q('RPLT' . $ref) . ", NOW())";
}
function pl_counts(string $ref): array {
    $bid = pl_bq($ref);
    return ["SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total FROM payments WHERE booking_type = 'room' AND booking_id = $bid AND COALESCE(payment_type,'') <> 'refund'",
            "SELECT COUNT(*) AS n, COALESCE(SUM(original_amount),0) AS amt FROM credit_notes WHERE booking_id = $bid AND booking_type = 'room'"];
}
$today = date('Y-m-d');
$addPost = function (string $bid, string $amt, array $x = []) use ($today) {
    return array_merge(['booking_type' => 'room', 'booking_id' => $bid, 'payment_amount' => $amt, 'payment_date' => $today, 'payment_method' => 'cash', 'payment_status' => 'completed', 'transaction_reference' => '', 'notes' => '', 'cc_emails' => ''], $x);
};

echo "== payment-add.php (room) ==\n";
$ref = pl_ref('PLR'); $bid = '{bid}';
$r = pl_run('admin/payment-add.php', $addPost($bid, '150'), ['setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref, 'grep' => ['Error: That is more than the balance due']]);
ok('over the balance, no tick: refused (no payment, no credit note)', ($r['verify'][0][0]['n'] ?? 9) == 0 && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode($r['verify'] ?? $r));
ok('  ... with the plain message', !empty($r['found']['Error: That is more than the balance due']), json_encode($r['found'] ?? []));
$ref = pl_ref('PLR'); $bid = '{bid}';
$r = pl_run('admin/payment-add.php', $addPost($bid, '150', ['confirm_overpay' => '1']), ['setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('over the balance WITH the tick: recorded and the extra 50 becomes a credit note', ($r['verify'][0][0]['n'] ?? 0) >= 1 && abs((float)($r['verify'][1][0]['amt'] ?? 0) - 50) < 0.01, json_encode($r['verify'] ?? $r));
$ref = pl_ref('PLR'); $bid = '{bid}';
$r = pl_run('admin/payment-add.php', $addPost($bid, '100'), ['setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('exactly the balance: recorded, no credit note', ($r['verify'][0][0]['n'] ?? 0) == 1 && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode($r['verify'] ?? $r));
$ref = pl_ref('PLR'); $bid = '{bid}';
$r = pl_run('admin/payment-add.php', $addPost($bid, '10'), ['setup' => [pl_booking_sql($ref), pl_paid_sql($ref, 100.0)], 'verify' => pl_counts($ref), 'ref' => $ref, 'grep' => ['Error: Nothing is owed on this booking. Use a credit note or refund instead.']]);
ok('nothing owed: no payment recorded', ($r['verify'][0][0]['n'] ?? 9) == 1 && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode($r['verify'] ?? $r));
ok('  ... "Nothing is owed on this booking. Use a credit note or refund instead."', !empty($r['found']['Error: Nothing is owed on this booking. Use a credit note or refund instead.']), json_encode($r['found'] ?? []));
$ref = pl_ref('PLR'); $bid = '{bid}';
$r2 = pl_run('admin/payment-add.php', $addPost($bid, '10', ['confirm_overpay' => '1']), ['setup' => [pl_booking_sql($ref), pl_paid_sql($ref, 100.0)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('nothing owed: the tick does not unlock it either', ($r2['verify'][0][0]['n'] ?? 9) == 1, json_encode($r2['verify'] ?? $r2));

echo "== bookings.php Payments & balance (consolidation_record) ==\n";
$cr = function (string $bid, string $amt, array $x = []) use ($today) {
    return array_merge(['action' => 'consolidation_record', 'id' => $bid, 'amount' => $amt, 'payment_method' => 'cash', 'payment_type' => 'partial_payment', 'payment_date' => $today], $x);
};
$ref = pl_ref('PLC'); $bid = '{bid}';
$r = pl_run('admin/bookings.php', $cr($bid, '25'), ['ajax' => true, 'setup' => [pl_booking_sql($ref), pl_paid_sql($ref, 100.0)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('consolidation, nothing owed: refused with the plain message', empty($r['json']['success']) && strpos((string)($r['json']['message'] ?? ''), 'Nothing is owed on this booking') !== false && ($r['verify'][0][0]['n'] ?? 9) == 1, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
$ref = pl_ref('PLC'); $bid = '{bid}';
$r = pl_run('admin/bookings.php', $cr($bid, '130'), ['ajax' => true, 'setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('consolidation, over the balance without the tick: refused', empty($r['json']['success']) && strpos((string)($r['json']['message'] ?? ''), 'Tick') !== false && ($r['verify'][0][0]['n'] ?? 9) == 0, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
$ref = pl_ref('PLC'); $bid = '{bid}';
$r = pl_run('admin/bookings.php', $cr($bid, '130', ['confirm_overpay' => '1']), ['ajax' => true, 'setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('consolidation, over WITH the tick: recorded + credit note of 30', ($r['verify'][0][0]['n'] ?? 0) == 1 && abs((float)($r['verify'][1][0]['amt'] ?? 0) - 30) < 0.01, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
$ref = pl_ref('PLC'); $bid = '{bid}';
$r = pl_run('admin/bookings.php', $cr($bid, '100'), ['ajax' => true, 'setup' => [pl_booking_sql($ref)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('consolidation, exactly the balance: recorded, no credit note', ($r['verify'][0][0]['n'] ?? 0) == 1 && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));

echo "== Mark as Paid (bookings list + booking page) on a settled booking ==\n";
$ref = pl_ref('PLM'); $bid = '{bid}';
$r = pl_run('admin/bookings.php', ['action' => 'update_payment', 'id' => $bid, 'payment_status' => 'paid'], ['ajax' => true, 'setup' => [pl_booking_sql($ref), pl_paid_sql($ref, 100.0)], 'verify' => pl_counts($ref), 'ref' => $ref]);
ok('bookings list "mark paid" when nothing owed: no second payment', ($r['verify'][0][0]['n'] ?? 9) == 1, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
$ref = pl_ref('PLM'); $bid = pl_reserve($ref);
$r = pl_run('admin/booking-details.php', ['update_payment' => '1', 'payment_status' => 'paid'], ['get' => ['id' => $bid], 'setup' => [pl_booking_sql($ref, 'confirmed', $bid), pl_paid_sql($ref, 100.0)], 'verify' => pl_counts($ref)]);
ok('booking page "mark paid" when nothing owed: no second payment', ($r['verify'][0][0]['n'] ?? 9) == 1, json_encode([$r['verify'] ?? $r]));

echo "== Record payment helper (conference / gym / event) ==\n";
foreach (['conference', 'gym', 'event'] as $t) {
    $r = pl_child('account', ['type' => $t]);
    $j = $r['json'] ?? [];
    ok("$t: exact balance ok", !empty($j['exact_ok']) && empty($j['exact_cn']), json_encode($j ?: $r));
    ok("$t: over the balance without the tick is refused", ($j['over_refused'] ?? '') !== '' && strpos($j['over_refused'], 'Tick') !== false, json_encode($j));
    ok("$t: over WITH the tick records it and issues a credit note", !empty($j['over_ok']) && !empty($j['over_cn']), json_encode($j));
    ok("$t: nothing owed is refused (even with the tick)", strpos((string)($j['nothing_owed'] ?? ''), 'Nothing is owed on this booking') !== false, json_encode($j));
    ok("$t: a settled account refuses an INVOICE (receipt instead)", ($j['invoice_msg'] ?? '') === 'This booking is fully paid. Send the receipt instead.', json_encode($j));
}

echo "== API payments create ==\n";
$r = pl_child('api', ['mode' => 'nothing_owed']);
ok('API: nothing owed -> 422 "Nothing is owed on this booking..."', ($r['json']['api_code'] ?? 0) == 422 && strpos((string)($r['json']['api_error'] ?? ''), 'Nothing is owed on this booking') !== false, json_encode($r['json'] ?? $r));
$r = pl_child('api', ['mode' => 'over']);
ok('API: over the balance without allow_overpayment -> 422', ($r['json']['api_code'] ?? 0) == 422 && strpos((string)($r['json']['api_error'] ?? ''), 'Tick') !== false, json_encode($r['json'] ?? $r));
$r = pl_child('api', ['mode' => 'over_allowed'], ['verify' => ["SELECT COUNT(*) AS n FROM payments WHERE booking_reference LIKE 'PLA%'", "SELECT COUNT(*) AS n, COALESCE(SUM(original_amount),0) AS amt FROM credit_notes WHERE booking_reference LIKE 'PLA%'"]]);
ok('API: allow_overpayment true -> recorded, extra 20 as a credit note', ($r['verify'][0][0]['n'] ?? 0) >= 1 && abs((float)($r['verify'][1][0]['amt'] ?? 0) - 20) < 0.01, json_encode($r['verify'] ?? $r));
$r = pl_child('api', ['mode' => 'exact'], ['verify' => ["SELECT COUNT(*) AS n FROM payments WHERE booking_reference LIKE 'PLA%'", "SELECT COUNT(*) AS n FROM credit_notes WHERE booking_reference LIKE 'PLA%'"]]);
ok('API: exact balance -> recorded, no credit note', ($r['verify'][0][0]['n'] ?? 0) == 1 && ($r['verify'][1][0]['n'] ?? 9) == 0, json_encode($r['verify'] ?? $r));

echo "== Room invoices refused when fully paid ==\n";
$r = pl_child('room_invoice');
$j = $r['json'] ?? [];
ok('room: invoice on a fully paid booking refused with the receipt message', ($j['paid_msg'] ?? '') === 'This booking is fully paid. Send the receipt instead.', json_encode($j ?: $r));
ok('room: a booking with a balance owed is NOT refused by this rule', ($j['owed_refusal'] ?? 'x') === '', json_encode($j));
ok('doc state: owed = invoice, paid = receipt, nothing = quote', ($j['states'] ?? []) === ['invoice', 'receipt', 'quote'], json_encode($j));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
