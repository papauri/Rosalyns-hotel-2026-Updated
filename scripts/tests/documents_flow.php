<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Documents flow test: what is emailed, and when, for every way money is recorded.
 * Usage (from the project root): php scripts/tests/documents_flow.php
 *
 * Rules under test (owner, 2026-10-20):
 *   RECEIPT   = proof of money received: emailed automatically, exactly once, with a PDF, on every payment path
 *               and every account type, the moment the money is completed/paid (incl. pending -> completed).
 *   INVOICE   = request for payment: never sent as the confirmation of a payment; the final invoice at checkout
 *               goes out only while a balance is owed (paid in full -> a paid STATEMENT instead).
 *
 * Every scenario runs through scripts/tests/page_harness.php: one outer transaction that can never commit and is
 * rolled back, with the SMTP settings pointed (inside that transaction) at scripts/tests/fake-smtp.php, a
 * capture-only server on 127.0.0.1. Nothing is delivered anywhere and nothing is left in the database.
 * Receipt/invoice PDFs written to invoices/ during the run are deleted again at the end.
 */
$root = dirname(__DIR__, 2);
chdir($root);
// Hotel time, like the app (config/database.php): "today" and "yesterday" below must be the same day the page
// under test sees. Left on the CLI default (UTC) the checkout bookings were dated a day behind from 00:00 to
// 02:00 Malawi time and checkout was refused as a late departure.
$tzTest = trim((string)(getenv('HOTEL_TIMEZONE') ?: 'Africa/Blantyre'));
date_default_timezone_set(in_array($tzTest, timezone_identifiers_list(), true) ? $tzTest : 'Africa/Blantyre');
$harness = $root . '/scripts/tests/page_harness.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

// ---- capture-only SMTP server ---------------------------------------------------------------------------------
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
if (!$port) { echo "[FAIL] no free local port for the capture SMTP server\n"; exit(1); }
$spool = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_docs_flow_' . bin2hex(random_bytes(4)) . '.jsonl';
@unlink($spool . '.ready');
$smtp = proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rh_docs_smtp.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/rh_docs_smtp.err', 'a']], $pipes);
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
if (!is_resource($smtp) || !is_file($spool . '.ready')) { echo "[FAIL] capture SMTP server did not start\n"; exit(1); }

// ---- PDF clean-up bookkeeping --------------------------------------------------------------------------------
function dx_files(string $root): array {
    $set = [];
    foreach (['invoices', 'invoices/receipts'] as $d) {
        foreach (glob($root . '/' . $d . '/*') ?: [] as $f) { if (is_file($f)) { $set[$f] = true; } }
    }
    return $set;
}
$filesBefore = dx_files($root);
register_shutdown_function(function () use (&$smtp, $spool, $root, $filesBefore) {
    if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
    @unlink($spool); @unlink($spool . '.ready');
    foreach (array_diff_key(dx_files($root), $filesBefore) as $f => $_) { @unlink($f); }
});

// ---- helpers --------------------------------------------------------------------------------------------------
$spoolPos = 0;
function dx_new_emails(): array {
    global $spool, $spoolPos;
    clearstatcache();
    $out = [];
    if (is_file($spool)) {
        $lines = file($spool, FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($lines, $spoolPos) as $l) { $j = json_decode($l, true); if (is_array($j)) { $out[] = $j; } }
        $spoolPos = count($lines);
    }
    return $out;
}
function dx_kind(array $m): string {
    $s = (string)($m['subject'] ?? '');
    if (preg_match('/receipt/i', $s)) { return 'receipt'; }
    if (preg_match('/quotation|proforma/i', $s)) { return 'quotation'; }
    if (preg_match('/final invoice/i', $s)) { return 'final_invoice'; }
    if (preg_match('/statement/i', $s)) { return 'statement'; }
    if (preg_match('/invoice/i', $s)) { return 'invoice'; }
    return 'other';
}
function dx_kinds(array $emails): array {
    $k = [];
    foreach ($emails as $m) { $k[] = dx_kind($m); }
    sort($k);
    return $k;
}
function dx_count(array $emails, string $kind): int { return count(array_filter($emails, fn($m) => dx_kind($m) === $kind)); }
function dx_has_pdf(array $emails, string $kind): bool {
    foreach ($emails as $m) { if (dx_kind($m) === $kind) { foreach ((array)$m['attachments'] as $a) { if (preg_match('/\.pdf$/i', $a)) { return true; } } } }
    return false;
}
function dx_run(string $page, array $post = [], array $opts = []): array {
    global $harness, $port;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [], 'fake_smtp' => $port]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    $res['emails'] = dx_new_emails();
    return $res;
}
function dx_child(string $scenario, array $params = [], array $opts = []): array {
    return dx_run('scripts/tests/documents_flow_child.php', ['scenario' => $scenario, 'params' => json_encode($params)], $opts);
}
function dx_clean(array $r): bool { return !empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']); }
function dx_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }

$rid = 0;
function dx_next_id(): int { global $rid; return random_int(2000000000, 2100000000) + (++$rid); }
function dx_booking_sql(int $id, string $ref, string $email, string $status = 'confirmed', ?string $in = null, ?string $out = null, int $nights = 1): string {
    $in = $in ?? date('Y-m-d', strtotime('+400 day')); $out = $out ?? date('Y-m-d', strtotime('+401 day'));
    return "INSERT INTO bookings (id, booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES ($id, " . dx_q($ref) . ", (SELECT id FROM rooms ORDER BY id LIMIT 1), 'DOCS TEST', " . dx_q($email) . ", '+265000000009', 'Malawi', 2, 2, 0, 50, " . dx_q($in) . ", " . dx_q($out) . ", " . $nights . ", 100, 0, 0, 0, 0, 0, 100, 'documents test', " . dx_q($status) . ", 'unpaid', 0, 'double', " . dx_q(bin2hex(random_bytes(16))) . ", NOW())";
}
function dx_payment_sql(int $id, int $bookingId, string $ref, string $status, float $amount, ?string $receipt = null): string {
    return "INSERT INTO payments (id, payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES ($id, " . dx_q('PDXT' . $id) . ", 'room', $bookingId, " . dx_q($ref) . ", CURDATE(), $amount, 0, 0, $amount, 'cash', 'partial_payment', " . dx_q($status) . ", " . dx_q($status === 'completed' ? 'completed' : 'pending') . ", " . ($receipt === null ? 'NULL' : dx_q($receipt)) . ", NOW())";
}
function dx_conf_sql(int $id, string $ref, string $email): string {
    return "INSERT INTO conference_inquiries (id, inquiry_reference, conference_room_id, company_name, contact_person, email, phone, event_date, start_time, end_time, number_of_attendees, status, total_amount, total_with_vat, amount_due, created_at)
        VALUES ($id, " . dx_q($ref) . ", 0, 'DOCS CO', 'DOCS PERSON', " . dx_q($email) . ", '+265000000009', " . dx_q(date('Y-m-d', strtotime('+400 day'))) . ", '09:00:00', '17:00:00', 10, 'confirmed', 100, 100, 100, NOW())";
}
$verifyPay = ["SELECT payment_status, receipt_number IS NOT NULL AS has_receipt_no, receipt_emailed_at IS NOT NULL AS emailed, receipt_email_count AS cnt FROM payments WHERE booking_reference LIKE 'DX%' ORDER BY id"];
$mail = 'docs-test@example.invalid';
$today = date('Y-m-d');

// ======================================================================================================================
echo "== ROOM: admin/payment-add.php ==\n";
$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$post = ['booking_type' => 'room', 'booking_id' => $bid, 'payment_amount' => '40', 'payment_date' => $today, 'payment_method' => 'cash', 'payment_status' => 'completed', 'transaction_reference' => '', 'notes' => '', 'cc_emails' => ''];
$v = ["SELECT payment_status, receipt_number IS NOT NULL AS has_receipt_no, receipt_emailed_at IS NOT NULL AS emailed, receipt_email_count AS cnt FROM payments WHERE booking_id = $bid ORDER BY id"];
$r = dx_run('admin/payment-add.php', $post, ['setup' => [dx_booking_sql($bid, $ref, $mail)], 'verify' => $v]);
ok('completed room payment: saved with a receipt number', ($r['verify'][0][0]['has_receipt_no'] ?? 0) == 1, json_encode($r['verify'][0] ?? $r));
ok('completed room payment: exactly ONE receipt emailed', dx_count($r['emails'], 'receipt') === 1, json_encode(dx_kinds($r['emails'])));
ok('completed room payment: the receipt carries a PDF', dx_has_pdf($r['emails'], 'receipt'));
ok('completed room payment: NO invoice/quotation emailed with the payment', dx_count($r['emails'], 'invoice') + dx_count($r['emails'], 'final_invoice') + dx_count($r['emails'], 'statement') + dx_count($r['emails'], 'quotation') === 0, json_encode(dx_kinds($r['emails'])));
ok('completed room payment: marked emailed once (count = 1)', ($r['verify'][0][0]['emailed'] ?? 0) == 1 && ($r['verify'][0][0]['cnt'] ?? 0) == 1, json_encode($r['verify'][0] ?? []));
ok('  rolled back', dx_clean($r), json_encode($r));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$r = dx_run('admin/payment-add.php', array_merge($post, ['booking_id' => $bid, 'payment_status' => 'pending']), ['setup' => [dx_booking_sql($bid, $ref, $mail)], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_id = $bid"]]);
ok('pending room payment: saved, but NO email (nothing received yet)', count($r['emails']) === 0 && ($r['verify'][0][0]['payment_status'] ?? '') === 'pending', json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000; $pid = dx_next_id();
$post3 = array_merge($post, ['booking_id' => $bid, 'payment_status' => 'completed']);
$v3 = ["SELECT payment_status, receipt_number IS NOT NULL AS has_receipt_no, receipt_emailed_at IS NOT NULL AS emailed, receipt_email_count AS cnt FROM payments WHERE id = $pid"];
$r = dx_run('admin/payment-add.php', $post3, ['get' => ['edit' => $pid], 'setup' => [dx_booking_sql($bid, $ref, $mail), dx_payment_sql($pid, $bid, $ref, 'pending', 40.0)], 'verify' => $v3]);
ok('pending -> completed (admin edit): exactly ONE receipt emailed on that transition', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));
ok('pending -> completed (admin edit): receipt number issued and marked emailed', ($r['verify'][0][0]['has_receipt_no'] ?? 0) == 1 && ($r['verify'][0][0]['emailed'] ?? 0) == 1);

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000; $pid = dx_next_id();
$r = dx_run('admin/payment-add.php', array_merge($post3, ['booking_id' => $bid]), ['get' => ['edit' => $pid], 'setup' => [dx_booking_sql($bid, $ref, $mail), dx_payment_sql($pid, $bid, $ref, 'completed', 40.0, 'RDXOLD' . $pid)], 'verify' => ["SELECT receipt_email_count AS cnt FROM payments WHERE id = $pid"]]);
$r2 = $r;
ok('editing an already-completed payment that was never receipted sends its receipt once, not twice', dx_count($r['emails'], 'receipt') <= 1, json_encode(dx_kinds($r['emails'])));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$r = dx_run('admin/payment-add.php', array_merge($post, ['booking_id' => $bid]), ['setup' => [dx_booking_sql($bid, $ref, '')], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_id = $bid"]]);
ok('room payment, guest has no email: payment still saved, nothing emailed, not marked emailed', count($r['emails']) === 0 && ($r['verify'][0][0]['payment_status'] ?? '') === 'completed' && ($r['verify'][0][0]['emailed'] ?? 1) == 0, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));

echo "== CONFERENCE: admin/payment-add.php ==\n";
$cid = dx_next_id(); $cref = 'DXCF' . $cid % 100000;
$r = dx_run('admin/payment-add.php', ['booking_type' => 'conference', 'booking_id' => $cid, 'payment_amount' => '40', 'payment_date' => $today, 'payment_method' => 'cash', 'payment_status' => 'completed', 'transaction_reference' => '', 'notes' => '', 'cc_emails' => ''],
    ['setup' => [dx_conf_sql($cid, $cref, $mail)], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_type = 'conference' AND booking_id = $cid"]]);
ok('conference payment: exactly ONE receipt emailed (was an invoice before)', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));
ok('conference payment: the receipt carries a PDF', dx_has_pdf($r['emails'], 'receipt'));
ok('conference payment: NO invoice emailed with the payment', dx_count($r['emails'], 'invoice') === 0);

echo "== ROOM: booking details / bookings list / consolidation ==\n";
$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$r = dx_run('admin/booking-details.php', ['update_payment' => '1', 'payment_status' => 'paid'], ['get' => ['id' => $bid], 'setup' => [dx_booking_sql($bid, $ref, $mail)], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_id = $bid"]]);
ok('booking page "mark paid": one receipt, no invoice', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['raw'] ?? '']));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$r = dx_run('admin/bookings.php', ['action' => 'update_payment', 'id' => $bid, 'payment_status' => 'paid'], ['setup' => [dx_booking_sql($bid, $ref, $mail)], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_id = $bid"]]);
ok('bookings list "mark paid": one receipt, no invoice', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['raw'] ?? '']));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$r = dx_run('admin/bookings.php', ['action' => 'consolidation_record', 'id' => $bid, 'amount' => '25', 'payment_method' => 'cash', 'payment_type' => 'partial_payment', 'payment_date' => $today], ['ajax' => true, 'setup' => [dx_booking_sql($bid, $ref, $mail)], 'verify' => ["SELECT payment_status, receipt_emailed_at IS NOT NULL AS emailed FROM payments WHERE booking_id = $bid"]]);
ok('manual consolidation payment: one receipt, no invoice', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['json'] ?? ($r['raw'] ?? '')]));

echo "== CHECKOUT: final invoice only while a balance is owed ==\n";
$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000;
$yest = date('Y-m-d', strtotime('-1 day'));
$r = dx_run('admin/bookings.php', ['action' => 'checkout', 'id' => $bid, 'confirm_checkout_with_balance' => '1'], ['setup' => [dx_booking_sql($bid, $ref, $mail, 'checked-in', $yest, $today)], 'verify' => ["SELECT status, amount_due, final_invoice_generated FROM bookings WHERE id = $bid"]]);
ok('checkout WITH a balance owed: a Final Invoice is emailed (a request for the balance)', dx_count($r['emails'], 'final_invoice') === 1 && dx_count($r['emails'], 'statement') === 0, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['raw'] ?? '']));
ok('checkout WITH a balance owed: the invoice carries a PDF', dx_has_pdf($r['emails'], 'final_invoice'));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000; $pid = dx_next_id();
$r = dx_run('admin/bookings.php', ['action' => 'checkout', 'id' => $bid], ['setup' => [dx_booking_sql($bid, $ref, $mail, 'checked-in', $yest, $today), dx_payment_sql($pid, $bid, $ref, 'completed', 100.0, 'RDXPAID' . $pid)], 'verify' => ["SELECT status, amount_due, final_invoice_generated FROM bookings WHERE id = $bid"]]);
ok('checkout PAID IN FULL: NO final invoice (the guest already holds the receipts)', dx_count($r['emails'], 'final_invoice') === 0 && dx_count($r['emails'], 'invoice') === 0, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['raw'] ?? '']));
ok('checkout PAID IN FULL: a paid-in-full stay STATEMENT is sent instead, with the PDF', dx_count($r['emails'], 'statement') === 1 && dx_has_pdf($r['emails'], 'statement'), json_encode(dx_kinds($r['emails'])));

$bid = dx_next_id(); $ref = 'DXRM' . $bid % 100000; $pid = dx_next_id();
$r = dx_run('admin/bookings.php', ['action' => 'checkout_settle', 'id' => $bid, 'settlement_action' => 'refund', 'payment_method' => 'cash', 'notes' => 'documents test'], ['ajax' => true,
    'setup' => [dx_booking_sql($bid, $ref, $mail, 'checked-in', date('Y-m-d', strtotime('-2 day')), date('Y-m-d', strtotime('+2 day')), 4), dx_payment_sql($pid, $bid, $ref, 'completed', 100.0, 'RDXPAID' . $pid)],
    'verify' => ["SELECT COUNT(*) AS refunds, COALESCE(SUM(total_amount),0) AS refunded FROM payments WHERE booking_id = $bid AND payment_type = 'refund'"]]);
ok('early checkout that returns money: the guest gets a REFUND notice (not a receipt, not an invoice)', ($r['verify'][0][0]['refunds'] ?? 0) >= 1 && count(array_filter($r['emails'], fn($m) => preg_match('/refund/i', (string)$m['subject']))) >= 1 && dx_count($r['emails'], 'receipt') === 0, json_encode([array_column($r['emails'], 'subject'), $r['verify'][0] ?? $r, $r['json'] ?? ($r['raw'] ?? '')]));

echo "== GYM / EVENT / CONFERENCE: shared Record payment (account-record-payment.php) ==\n";
foreach (['gym', 'event', 'conference'] as $t) {
    $r = dx_child('account', ['type' => $t, 'email' => $mail, 'want_invoice' => 0]);
    ok("$t payment: ONE receipt emailed with a PDF, no invoice", dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1 && dx_has_pdf($r['emails'], 'receipt'), json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
    ok("  ($t) rolled back", dx_clean($r), json_encode($r));
}
$r = dx_child('account', ['type' => 'gym', 'email' => $mail, 'amount' => '40', 'want_invoice' => 1]);
ok('part-payment + "also email an invoice" with a balance left: receipt AND the requested invoice', dx_count($r['emails'], 'receipt') === 1 && dx_count($r['emails'], 'invoice') === 1, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
$r = dx_child('account', ['type' => 'event', 'email' => $mail, 'want_invoice' => 1]);
ok('payment that settles the account + "also email an invoice": receipt only, no invoice (nothing is owed)', dx_count($r['emails'], 'receipt') === 1 && dx_count($r['emails'], 'invoice') === 0 && strpos((string)($r['json']['message'] ?? ''), 'No invoice sent') !== false, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
$r = dx_child('account', ['type' => 'gym', 'email' => '', 'want_invoice' => 0]);
ok('gym payment, customer has no email: nothing emailed and staff are told', count($r['emails']) === 0 && strpos((string)($r['json']['message'] ?? ''), 'Receipt not emailed: guest has no email address') !== false, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));

echo "== ONCE ONLY / NOT YET RECEIVED / WHY NOT ==\n";
$r = dx_child('dedupe');
ok('three triggers for the same payment: ONE email; later ones say already sent and stay silent', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1 && ($r['json']['first'] ?? '') === 'sent' && ($r['json']['second'] ?? '') === 'already_sent' && ($r['json']['third'] ?? '') === 'already_sent' && ($r['json']['third_notice'] ?? 'x') === '', json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
$r = dx_child('pending_no_receipt');
ok('a pending payment gets no receipt', count($r['emails']) === 0 && ($r['json']['reason'] ?? '') === 'not_collected', json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
$expect = [
    'no_email'     => ['no_email', 'guest has no email address'],
    'bad_email'    => ['bad_email', 'email address is not valid'],
    'template_off' => ['template_off', 'switched off in Booking Settings'],
    'no_smtp'      => ['no_smtp', 'email sending is not set up'],
];
foreach ($expect as $why => [$reason, $phrase]) {
    $r = dx_child('reason', ['why' => $why]);
    ok("receipt cannot be sent ($why): nothing emailed, reason '$reason', staff told", count($r['emails']) === 0 && ($r['json']['reason'] ?? '') === $reason && strpos((string)($r['json']['notice'] ?? ''), 'Receipt not emailed') !== false && strpos((string)($r['json']['notice'] ?? ''), $phrase) !== false, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
}

echo "== RESTAURANT / TILL ==\n";
$r = dx_child('restaurant', ['email' => 1]);
ok('till sale with a customer email: the receipt is queued and goes out once', ($r['json']['queued'] ?? false) === true && dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
$r = dx_child('restaurant', ['email' => 0]);
ok('walk-in till sale (no email): nothing queued, nothing sent', ($r['json']['queued'] ?? true) === false && count($r['emails']) === 0, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));

$r = dx_child('restaurant_split', ['email' => 1]);
ok('split bill, order with a customer email: BOTH legs queue a receipt, one email per leg, never twice', ($r['json']['queued'] ?? 0) === 2 && dx_count($r['emails'], 'receipt') === 2 && count($r['emails']) === 2, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));
ok('  split legs rolled back', dx_clean($r), json_encode($r));
$r = dx_child('restaurant_split', ['email' => 0]);
ok('split bill, walk-in (no email): nothing queued, nothing sent', ($r['json']['queued'] ?? 1) === 0 && count($r['emails']) === 0, json_encode([dx_kinds($r['emails']), $r['json'] ?? $r['raw'] ?? '']));

echo "== CHECKOUT DOCUMENT TITLE: statement vs invoice ==\n";
$r = dx_child('statement_pdf', ['paid' => 1]);
$j = $r['json'] ?? [];
ok('paid in full: the document is titled "Stay Statement - paid in full" with a Statement No. and NO "Balance due" wording', !empty($j['has_stmt_title']) && !empty($j['has_stmt_no']) && empty($j['has_balance_due']) && empty($j['has_invoice_no']) && empty($j['has_invoice_title']), json_encode($j));
ok('paid in full: the PDF is built, keeps the invoice number as its file name (traceability) and its title is the statement', !empty($j['pdf_ok']) && !empty($j['pdf_file_is_invoice_no']) && !empty($j['pdf_title_statement']), json_encode($j));
$r = dx_child('statement_pdf', ['paid' => 0]);
$j = $r['json'] ?? [];
ok('balance owed: stays an INVOICE (Invoice No., Balance due, no statement title)', empty($j['has_stmt_title']) && !empty($j['has_invoice_no']) && !empty($j['has_balance_due']) && !empty($j['has_invoice_title']), json_encode($j));
ok('balance owed: the PDF title is not the statement', !empty($j['pdf_ok']) && empty($j['pdf_title_statement']), json_encode($j));
ok('  statement/invoice docs rolled back', dx_clean($r), json_encode($r));
echo "== API: api/payments.php ==\n";
$apiV = ["SELECT p.payment_status, p.receipt_number IS NOT NULL AS has_receipt_no, p.receipt_emailed_at IS NOT NULL AS emailed, p.receipt_email_count AS cnt FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.guest_name = 'DOCS TEST'"];
$r = dx_child('api_create', ['status' => 'completed'], ['verify' => $apiV]);
ok('API create (completed): ONE receipt emailed with a PDF, no invoice', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1 && dx_has_pdf($r['emails'], 'receipt') && ($r['verify'][0][0]['cnt'] ?? 0) == 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));
$r = dx_child('api_create', ['status' => 'pending'], ['verify' => $apiV]);
ok('API create (pending): no email', count($r['emails']) === 0 && ($r['verify'][0][0]['payment_status'] ?? '') === 'pending', json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r]));
$r = dx_child('api_update', [], ['verify' => $apiV]);
ok('API update pending -> completed: ONE receipt emailed on that transition', dx_count($r['emails'], 'receipt') === 1 && count($r['emails']) === 1 && ($r['verify'][0][0]['payment_status'] ?? '') === 'completed' && ($r['verify'][0][0]['cnt'] ?? 0) == 1, json_encode([dx_kinds($r['emails']), $r['verify'][0] ?? $r, $r['raw'] ?? '']));
ok('  API runs rolled back', dx_clean($r), json_encode($r));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
