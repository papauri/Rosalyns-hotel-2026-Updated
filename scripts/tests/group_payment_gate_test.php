<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Group bookings Ship B1 (owner 2026-11-17). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/group_payment_gate_test.php
 *   - pure: bookingAllowsAction('record_payment') lets a no-show / cancelled / tagged-expired room take a payment ONLY
 *     while its real amount_due is open (the receivables rule); untagged expired, settled and deleted rooms refused;
 *     checked-out and confirmed unchanged;
 *   - pages (scripts/tests/page_harness.php: one outer transaction that never commits, guests have no email address):
 *     a no-show room takes a "this room only" payment from Payments > Add Payment and from Payments & balance, stays
 *     no-show and ends owing 0; a settled no-show is refused; overpay without the tick still refused;
 *   - admin/api/search-bookings.php: deleted rooms left out, older owing rooms in the recent list, group label.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/admin/includes/booking-lifecycle.php';
$harness = $root . '/scripts/tests/page_harness.php';
function gpg_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'credit-notes', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$gpgPdfBefore = gpg_pdf_set($root);
register_shutdown_function(function () use ($root, $gpgPdfBefore) { // only PDFs this run wrote; files from before are never touched
    foreach (array_diff_key(gpg_pdf_set($root), $gpgPdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

echo "== pure: bookingAllowsAction record_payment ==\n";
$allow = static function (array $b): bool { return !empty(bookingAllowsAction($b, 'record_payment')['allowed']); };
$why = static function (array $b): string { return (string)bookingAllowsAction($b, 'record_payment')['reason']; };
ok('no-show owing -> allowed', $allow(['status' => 'no-show', 'amount_due' => 129000]));
ok('no-show owing 0 -> refused', !$allow(['status' => 'no-show', 'amount_due' => 0]));
ok('  ... plain wording', $why(['status' => 'no-show', 'amount_due' => 0]) === 'This no-show booking owes nothing. No payment can be recorded.', $why(['status' => 'no-show', 'amount_due' => 0]));
ok('no-show within tolerance -> refused', !$allow(['status' => 'no-show', 'amount_due' => BALANCE_TOLERANCE / 2]));
ok('no-show, amount_due missing -> refused (no total - paid fallback)', !$allow(['status' => 'no-show', 'total_amount' => 500, 'amount_paid' => 0]));
ok('no-show, amount_due null -> refused', !$allow(['status' => 'no-show', 'amount_due' => null, 'total_amount' => 500]));
ok('cancelled owing -> allowed', $allow(['status' => 'cancelled', 'amount_due' => 50]));
ok('cancelled 0 -> refused', !$allow(['status' => 'cancelled', 'amount_due' => 0, 'total_amount' => 900, 'amount_paid' => 0]));
ok('expired untagged owing -> refused', !$allow(['status' => 'expired', 'amount_due' => 100, 'noshow_fee_tagged' => false]));
ok('expired, no id and no tag flag -> refused', !$allow(['status' => 'expired', 'amount_due' => 100]));
ok('expired tagged owing -> allowed', $allow(['status' => 'expired', 'amount_due' => 100, 'noshow_fee_tagged' => true]));
ok('expired tagged owing 0 -> refused', !$allow(['status' => 'expired', 'amount_due' => 0, 'noshow_fee_tagged' => true]));
ok('checked-out owing -> allowed (unchanged)', $allow(['status' => 'checked-out', 'amount_due' => 10]));
ok('checked-out settled -> refused (unchanged)', !$allow(['status' => 'checked-out', 'amount_due' => 0]));
ok('confirmed -> allowed (unchanged)', $allow(['status' => 'confirmed', 'amount_due' => 0]) && $allow(['status' => 'confirmed', 'amount_due' => 300]));
ok('deleted room owing -> refused', !$allow(['status' => 'no-show', 'amount_due' => 100, 'deleted_at' => '2026-11-01 10:00:00']));

// ---- pages ------------------------------------------------------------------------------------------------------
function gpg_run(string $page, array $post = [], array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => !empty($opts['ajax']), 'verify' => $opts['verify'] ?? [],
        'setup' => $opts['setup'] ?? [], 'grep' => $opts['grep'] ?? [],
        'vars' => isset($opts['ref']) ? ['bid' => "SELECT id FROM bookings WHERE booking_reference = " . gpg_q($opts['ref'])] : []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
function gpg_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
function gpg_ref(string $prefix): string { return $prefix . random_int(10000, 99999); }
function gpg_bq(string $ref): string { return "(SELECT id FROM bookings WHERE booking_reference = " . gpg_q($ref) . ")"; }
/** A one-night 100.00 room (no VAT, no levy, no email address). */
function gpg_booking_sql(string $ref, string $status): string {
    $in = date('Y-m-d', strtotime('-20 day')); $out = date('Y-m-d', strtotime('-19 day'));
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (" . gpg_q($ref) . ", (SELECT id FROM rooms ORDER BY id LIMIT 1), 'GATE TEST', '', '+265000000009', 'Malawi', 2, 2, 0, 50, " . gpg_q($in) . ", " . gpg_q($out)
        . ", 1, 100, 0, 0, 0, 0, 0, 100, 'gate test', " . gpg_q($status) . ", 'unpaid', 0, 'double', " . gpg_q(bin2hex(random_bytes(16))) . ", NOW())";
}
function gpg_paid_sql(string $ref, float $amount): string {
    return "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, created_at)
        VALUES (" . gpg_q('GPT' . $ref) . ", 'room', " . gpg_bq($ref) . ", " . gpg_q($ref) . ", CURDATE(), $amount, 0, 0, $amount, 'cash', 'partial_payment', 'completed', 'completed', " . gpg_q('RGPT' . $ref) . ", NOW())";
}
function gpg_state(string $ref): array {
    $bid = gpg_bq($ref);
    return ["SELECT status, amount_due, amount_paid FROM bookings WHERE id = $bid",
            "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total FROM payments WHERE booking_type = 'room' AND booking_id = $bid AND COALESCE(payment_type,'') <> 'refund'"];
}
$today = date('Y-m-d');
$addPost = function (string $amt, array $x = []) use ($today) {
    return array_merge(['booking_type' => 'room', 'booking_id' => '{bid}', 'payment_amount' => $amt, 'payment_date' => $today, 'payment_method' => 'cash',
        'payment_status' => 'completed', 'transaction_reference' => '', 'notes' => '', 'cc_emails' => ''], $x);
};
$near = static function ($a, $b): bool { return abs((float)$a - (float)$b) <= 0.011; };

echo "== Payments > Add Payment on a no-show room ==\n";
$ref = gpg_ref('GPG');
$r = gpg_run('admin/payment-add.php', $addPost('100'), ['setup' => [gpg_booking_sql($ref, 'no-show')], 'verify' => gpg_state($ref), 'ref' => $ref]);
$st = $r['verify'][0][0] ?? [];
ok('no-show owing 100 (stored amount_due never set): payment recorded', (int)($r['verify'][1][0]['n'] ?? 0) === 1 && $near($r['verify'][1][0]['total'] ?? 0, 100), json_encode($r['verify'] ?? $r));
ok('  ... status stays no-show', ($st['status'] ?? '') === 'no-show', json_encode($st));
ok('  ... amount_due -> 0', $near($st['amount_due'] ?? -1, 0) && $near($st['amount_paid'] ?? 0, 100), json_encode($st));

$ref = gpg_ref('GPG');
$r = gpg_run('admin/payment-add.php', $addPost('10'), ['setup' => [gpg_booking_sql($ref, 'no-show'), gpg_paid_sql($ref, 100.0)], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['This no-show booking owes nothing. No payment can be recorded.']]);
ok('settled no-show: refused, nothing recorded', (int)($r['verify'][1][0]['n'] ?? 9) === 1, json_encode($r['verify'] ?? $r));
ok('  ... with the plain message', !empty($r['found']['This no-show booking owes nothing. No payment can be recorded.']), json_encode($r['found'] ?? []));

$ref = gpg_ref('GPG');
$r = gpg_run('admin/payment-add.php', $addPost('150'), ['setup' => [gpg_booking_sql($ref, 'no-show')], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['A payment on it cannot be more than that. Nothing was recorded.']]);
ok('no-show, more than owed without the tick: refused (overpay guard)', (int)($r['verify'][1][0]['n'] ?? 9) === 0 && !empty($r['found']['A payment on it cannot be more than that. Nothing was recorded.']),
    json_encode([$r['verify'] ?? $r, $r['found'] ?? []]));

$ref = gpg_ref('GPG');
$r = gpg_run('admin/payment-add.php', $addPost('150', ['confirm_overpay' => '1']), ['setup' => [gpg_booking_sql($ref, 'no-show')], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['This no-show booking owes ', 'A payment on it cannot be more than that. Nothing was recorded.']]);
ok('no-show owing 100, 150 WITH the overpay tick: still refused (no credit left without a credit note)', (int)($r['verify'][1][0]['n'] ?? 9) === 0 && !empty($r['found']['This no-show booking owes ']) && !empty($r['found']['A payment on it cannot be more than that. Nothing was recorded.']),
    json_encode([$r['verify'] ?? $r, $r['found'] ?? []]));

$ref = gpg_ref('GPG');
$r = gpg_run('admin/payment-add.php', $addPost('100'), ['setup' => [gpg_booking_sql($ref, 'expired')], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['Payments cannot be recorded against an expired booking.']]);
ok('untagged expired: refused', (int)($r['verify'][1][0]['n'] ?? 9) === 0 && !empty($r['found']['Payments cannot be recorded against an expired booking.']),
    json_encode([$r['verify'] ?? $r, $r['found'] ?? []]));

// Tagged expired (group expiry charge [noshow-fee:<id>] on the folio): takes a payment while it owes, capped at what it owes.
$gpgTagSql = static function (string $ref, float $amt): string {
    return "INSERT INTO booking_charges (booking_id, charge_type, description, quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total, posted_at, added_by, voided)
        SELECT id, 'other', CONCAT('Expiry charge (gate test) [noshow-fee:', id, ']'), 1, $amt, $amt, 0, 0, $amt, NOW(), NULL, 0 FROM bookings WHERE booking_reference = " . gpg_q($ref);
};
$ref = gpg_ref('GPX');
$r = gpg_run('admin/payment-add.php', $addPost('10'), ['setup' => [gpg_booking_sql($ref, 'expired'), $gpgTagSql($ref, 100.0)], 'verify' => gpg_state($ref), 'ref' => $ref]);
$st = $r['verify'][0][0] ?? [];
ok('tagged expired owing: payment recorded', (int)($r['verify'][1][0]['n'] ?? 0) === 1 && $near($r['verify'][1][0]['total'] ?? 0, 10), json_encode($r['verify'] ?? $r));
ok('  ... status stays expired', ($st['status'] ?? '') === 'expired', json_encode($st));

$ref = gpg_ref('GPX');
$r = gpg_run('admin/payment-add.php', $addPost('999999', ['confirm_overpay' => '1']), ['setup' => [gpg_booking_sql($ref, 'expired'), $gpgTagSql($ref, 100.0)], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['This expired booking owes ', 'A payment on it cannot be more than that. Nothing was recorded.']]);
ok('tagged expired, more than owed WITH the tick: refused (capped)', (int)($r['verify'][1][0]['n'] ?? 9) === 0 && !empty($r['found']['A payment on it cannot be more than that. Nothing was recorded.']),
    json_encode([$r['verify'] ?? $r, $r['found'] ?? []]));

$ref = gpg_ref('GPX');
$r = gpg_run('admin/payment-add.php', $addPost('10'), ['setup' => [gpg_booking_sql($ref, 'expired'), $gpgTagSql($ref, 100.0), gpg_paid_sql($ref, 999999.0)], 'verify' => gpg_state($ref), 'ref' => $ref,
    'grep' => ['This expired booking owes nothing. No payment can be recorded.']]);
ok('settled tagged expired: refused, nothing recorded', (int)($r['verify'][1][0]['n'] ?? 9) === 1 && !empty($r['found']['This expired booking owes nothing. No payment can be recorded.']),
    json_encode([$r['verify'] ?? $r, $r['found'] ?? []]));

echo "== Payments & balance (bookings.php) on a no-show room ==\n";
$ref = gpg_ref('GPC');
$r = gpg_run('admin/bookings.php', ['action' => 'consolidation_record', 'id' => '{bid}', 'amount' => '60', 'payment_method' => 'cash', 'payment_type' => 'partial_payment', 'payment_date' => $today],
    ['ajax' => true, 'setup' => [gpg_booking_sql($ref, 'no-show')], 'verify' => gpg_state($ref), 'ref' => $ref]);
$st = $r['verify'][0][0] ?? [];
ok('no-show part payment recorded', !empty($r['json']['success']) && (int)($r['verify'][1][0]['n'] ?? 0) === 1, json_encode([$r['json'] ?? $r['raw'] ?? '', $r['verify'] ?? '']));
ok('  ... still no-show, owes 40', ($st['status'] ?? '') === 'no-show' && $near($st['amount_due'] ?? 0, 40), json_encode($st));

echo "== search-bookings ==\n";
$p = gpg_ref('GPS');
$live = $p . 'L'; $dead = $p . 'D'; $mem = $p . 'M';
$r = gpg_run('admin/api/search-bookings.php', [], ['get' => ['type' => 'room', 'q' => $p], 'setup' => [
    gpg_booking_sql($live, 'confirmed'), gpg_booking_sql($dead, 'no-show'), gpg_booking_sql($mem, 'confirmed'),
    "UPDATE bookings SET deleted_at = NOW() WHERE booking_reference = " . gpg_q($dead),
    "SET @gpg_lead := " . gpg_bq($live),
    "UPDATE bookings SET primary_booking_id = @gpg_lead WHERE booking_reference = " . gpg_q($mem),
]]);
$rows = [];
foreach ((array)($r['json']['bookings'] ?? []) as $b) { $rows[(string)$b['booking_reference']] = $b; }
ok('search: live rooms found', isset($rows[$live], $rows[$mem]), json_encode(array_keys($rows) ?: ($r['raw'] ?? $r)));
ok('search: deleted room left out', !isset($rows[$dead]), json_encode(array_keys($rows)));
ok('group label: member = "Room 2 of 2, group <lead ref>"', ($rows[$mem]['group_label'] ?? '') === 'Room 2 of 2, group ' . $live, (string)($rows[$mem]['group_label'] ?? ''));
ok('group label: lead = "Room 1 of 2, group <lead ref>"', ($rows[$live]['group_label'] ?? '') === 'Room 1 of 2, group ' . $live, (string)($rows[$live]['group_label'] ?? ''));

// Deleted lead: the label names the first room not deleted (lead first, then by id), never the deleted lead.
$p = gpg_ref('GPR');
$root = $p . 'R'; $m1 = $p . 'A'; $m2 = $p . 'B';
$r = gpg_run('admin/api/search-bookings.php', [], ['get' => ['type' => 'room', 'q' => $p], 'setup' => [
    gpg_booking_sql($root, 'confirmed'), gpg_booking_sql($m1, 'confirmed'), gpg_booking_sql($m2, 'confirmed'),
    "SET @gpg_root := " . gpg_bq($root),
    "UPDATE bookings SET primary_booking_id = @gpg_root WHERE booking_reference IN (" . gpg_q($m1) . ", " . gpg_q($m2) . ")",
    "UPDATE bookings SET deleted_at = NOW() WHERE booking_reference = " . gpg_q($root),
]]);
$rows = [];
foreach ((array)($r['json']['bookings'] ?? []) as $b) { $rows[(string)$b['booking_reference']] = $b; }
ok('deleted lead: label names the first live room', ($rows[$m2]['group_label'] ?? '') === 'Room 2 of 2, group ' . $m1, (string)($rows[$m2]['group_label'] ?? json_encode(array_keys($rows) ?: ($r['raw'] ?? $r))));
ok('  ... and on that room itself', ($rows[$m1]['group_label'] ?? '') === 'Room 1 of 2, group ' . $m1, (string)($rows[$m1]['group_label'] ?? ''));

$old = gpg_ref('GPO'); $oldPaid = gpg_ref('GPP');
$r = gpg_run('admin/api/search-bookings.php', [], ['get' => ['type' => 'room', 'recent' => '1'], 'setup' => [
    gpg_booking_sql($old, 'no-show'), gpg_booking_sql($oldPaid, 'confirmed'),
    "UPDATE bookings SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY), amount_due = 100 WHERE booking_reference = " . gpg_q($old),
    "UPDATE bookings SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY), amount_due = 0 WHERE booking_reference = " . gpg_q($oldPaid),
]]);
$refs = array_map(static fn($b) => (string)$b['booking_reference'], (array)($r['json']['bookings'] ?? []));
ok('recent list: a 60-day-old room that still owes is listed', in_array($old, $refs, true), json_encode($refs ?: ($r['raw'] ?? $r)));
ok('recent list: a 60-day-old settled room is not', !in_array($oldPaid, $refs, true), json_encode($refs));
ok('recent list: within the limits (10 recent + 10 older owing)', count($refs) <= 20, (string)count($refs));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
