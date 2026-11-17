<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Payments / Accounting > Add charge (group bookings Ship B2, owner D-R4b). Run from the TEST hotel's root only (Liwonde):
 *   php scripts/tests/group_charge_add_test.php
 * 1. Direct calls (admin/includes/charge-add.php), each case in ONE transaction that is rolled back, on made-up rooms:
 *    single open room; single checked-out room with / without correct_stay_after_checkout (owed up, status kept,
 *    payment_status paid -> partial, issued invoice rebuilt under the SAME number); group split and one line on a mostly
 *    checked-out group; reason required; no-show / cancelled / deleted refused; replayed and cross-path confirm refused;
 *    History rows hold the reason and owed old -> new.
 * 2. The page (scripts/tests/page_harness.php, one outer transaction that never commits): preview JSON, a stale confirm
 *    refused, no permission refused, a receptionist with Edit Booking but not the after-check-out permission refused.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/admin/includes/charge-add.php';
$harness = $root . '/scripts/tests/page_harness.php';

function gca_pdf_set(string $root): array {
    $set = [];
    foreach (['invoices', 'credit-notes', 'receipts'] as $d) {
        if (!is_dir($root . '/' . $d)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') { $set[$f->getPathname()] = true; }
        }
    }
    return $set;
}
$gcaPdfBefore = gca_pdf_set($root);
register_shutdown_function(function () use ($root, $gcaPdfBefore) { // only PDFs this run wrote; files from before are never touched
    foreach (array_diff_key(gca_pdf_set($root), $gcaPdfBefore) as $f => $_) {
        for ($i = 0; $i < 10 && is_file($f) && !@unlink($f); $i++) { usleep(300000); clearstatcache(true, $f); }
    }
});

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= 0.011;
function gca_q(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
function gca_ref(string $p): string { return $p . random_int(10000, 99999); }
/** A one-night 100.00 room (no VAT, no levy, no email address). */
function gca_booking_sql(string $ref, string $status): string {
    $in = date('Y-m-d', strtotime('-20 day')); $out = date('Y-m-d', strtotime('-19 day'));
    return "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, amount_due, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
        VALUES (" . gca_q($ref) . ", (SELECT id FROM rooms ORDER BY id LIMIT 1), 'CHARGE TEST', '', '+265000000008', 'Malawi', 2, 2, 0, 50, " . gca_q($in) . ", " . gca_q($out)
        . ", 1, 100, 0, 0, 0, 0, 0, 100, 100, 'charge add test', " . gca_q($status) . ", 'unpaid', 0, 'double', " . gca_q(bin2hex(random_bytes(16))) . ", NOW())";
}
function gca_bq(string $ref): string { return "(SELECT id FROM bookings WHERE booking_reference = " . gca_q($ref) . ")"; }
function gca_paid_sql(string $ref, float $amount, string $invNo = ''): string {
    $inv = $invNo !== '' ? ", 1, " . gca_q($invNo) . ", " . gca_q('invoices/' . $invNo . '.pdf') : ", 0, NULL, NULL";
    return "INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, status, receipt_number, invoice_generated, invoice_number, invoice_path, created_at)
        SELECT " . gca_q('GCA' . $ref) . ", 'room', id, booking_reference, CURDATE(), $amount, 0, 0, $amount, 'cash', 'full_payment', 'completed', 'completed', "
        . gca_q('RGCA' . $ref) . "$inv, NOW() FROM bookings WHERE booking_reference = " . gca_q($ref);
}
function gca_id(string $ref): int {
    global $pdo;
    $st = $pdo->prepare('SELECT id FROM bookings WHERE booking_reference = ?');
    $st->execute([$ref]);
    return (int)$st->fetchColumn();
}
function gca_row(int $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT status, amount_due, amount_paid, payment_status, deleted_at FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function gca_charges(int $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT description, line_total FROM booking_charges WHERE booking_id = ? AND COALESCE(voided, 0) = 0');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function gca_hist(int $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT action, description, old_value, new_value, metadata FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** Run $fn inside one transaction that is always rolled back. */
function gca_case(callable $fn): void {
    global $pdo;
    $pdo->beginTransaction();
    try { $fn(); } catch (Throwable $e) { ok('case threw: ' . $e->getMessage(), false); }
    finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
}
function gca_in(array $x): array {
    $r = rh_charge_add_input($x + ['charge_type' => 'service', 'description' => 'Late checkout 15:00', 'quantity' => '1', 'unit_price' => '30', 'reason' => 'Guest left at 15:00', 'scope' => 'room']);
    return $r['in'] + ['_error' => $r['error']];
}
$admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$user = ['id' => (int)$admin['id'], 'username' => (string)$admin['username'], 'full_name' => (string)$admin['full_name'], 'role' => 'admin'];

echo "== input ==\n";
ok('reason required', rh_charge_add_input(['booking_id' => '5', 'scope' => 'room', 'charge_type' => 'service', 'description' => 'X', 'quantity' => '1', 'unit_price' => '10', 'reason' => ' '])['error'] !== '');
ok('description required', rh_charge_add_input(['booking_id' => '5', 'scope' => 'room', 'charge_type' => 'service', 'description' => '', 'quantity' => '1', 'unit_price' => '10', 'reason' => 'r'])['error'] !== '');
ok('unknown scope refused', rh_charge_add_input(['booking_id' => '5', 'scope' => 'all', 'charge_type' => 'service', 'description' => 'X', 'quantity' => '1', 'unit_price' => '10', 'reason' => 'r'])['error'] !== '');
ok('zero total refused', rh_charge_add_input(['booking_id' => '5', 'scope' => 'room', 'charge_type' => 'service', 'description' => 'X', 'quantity' => '1', 'unit_price' => '0', 'reason' => 'r'])['error'] !== '');
ok('good input accepted', rh_charge_add_input(['booking_id' => '5', 'scope' => 'room', 'charge_type' => 'service', 'description' => 'X', 'quantity' => '2', 'unit_price' => '12.50', 'reason' => 'r'])['error'] === '');

echo "== single room, open ==\n";
gca_case(function () use ($pdo, $user, $near) {
    $ref = gca_ref('GCO');
    $pdo->exec(gca_booking_sql($ref, 'confirmed'));
    $pdo->exec(gca_paid_sql($ref, 40));
    $id = gca_id($ref);
    recalculateBookingFinancials($id);
    $in = gca_in(['booking_id' => (string)$id, 'quantity' => '2', 'unit_price' => '12.50']);
    $p = rh_charge_add_preview($pdo, $in, false);
    ok('preview: one row, owed 60 -> 85', !empty($p['success']) && count($p['rows']) === 1 && $near($p['rows'][0]['owed_now'], 60) && $near($p['rows'][0]['owed_after'], 85), json_encode($p));
    $r = rh_charge_add_post($pdo, $in, $user, false, (string)$p['sig']);
    $b = gca_row($id);
    ok('post: added, owes 85, still confirmed', !empty($r['success']) && $near($b['amount_due'], 85) && $b['status'] === 'confirmed', json_encode([$r, $b]));
    $c = gca_charges($id);
    ok('  ... one folio line of 25.00 (VAT-inclusive, no levy)', count($c) === 1 && $near($c[0]['line_total'], 25), json_encode($c));
    $h = array_values(array_filter(gca_hist($id), static fn($x) => $x['action'] === 'Charge added'));
    ok('  ... History: reason, owed old -> new', count($h) === 1 && strpos($h[0]['description'], 'Reason: Guest left at 15:00') !== false
        && strpos((string)$h[0]['old_value'], '60.00') !== false && strpos((string)$h[0]['new_value'], '85.00') !== false
        && (json_decode((string)$h[0]['metadata'], true)['reason'] ?? '') === 'Guest left at 15:00', json_encode($h));
    $r2 = rh_charge_add_post($pdo, $in, $user, false, 'not-the-sig');
    ok('a wrong sig posts nothing', empty($r2['success']) && count(gca_charges($id)) === 1, json_encode($r2));
});

echo "== single room, checked out (paid) ==\n";
gca_case(function () use ($pdo, $user, $near) {
    $ref = gca_ref('GCC');
    $inv = 'TESTGCA-' . random_int(100000, 999999);
    $pdo->exec(gca_booking_sql($ref, 'checked-out'));
    $pdo->exec(gca_paid_sql($ref, 100, $inv));
    $id = gca_id($ref);
    recalculateBookingFinancials($id);
    $b0 = gca_row($id);
    ok('setup: paid, owes 0', $near($b0['amount_due'], 0) && $b0['payment_status'] === 'paid', json_encode($b0));
    $in = gca_in(['booking_id' => (string)$id]);
    $p = rh_charge_add_preview($pdo, $in, false);
    ok('without correct_stay_after_checkout: preview refused', empty($p['success']) && stripos($p['message'], 'Correct Stay After Check-out') !== false, json_encode($p));
    $sigNo = rh_charge_add_single_sig($id, 'checked-out', false, 30.0);
    $r = rh_charge_add_post($pdo, $in, $user, false, $sigNo);
    ok('  ... and the post refused, nothing added', empty($r['success']) && gca_charges($id) === [], json_encode($r));
    $p = rh_charge_add_preview($pdo, $in, true);
    ok('with the permission: preview row marked checked out, owed 0 -> 30', !empty($p['success']) && !empty($p['rows'][0]['checked_out']) && $near($p['rows'][0]['owed_after'], 30), json_encode($p));
    $r = rh_charge_add_post($pdo, $in, $user, true, (string)$p['sig']);
    $b = gca_row($id);
    ok('post: owes 30, stays checked-out', !empty($r['success']) && $near($b['amount_due'], 30) && $b['status'] === 'checked-out', json_encode([$r, $b]));
    ok('  ... payment_status paid -> partial', $b['payment_status'] === 'partial', (string)$b['payment_status']);
    $no = $pdo->query("SELECT invoice_number FROM payments WHERE booking_type = 'room' AND booking_id = $id")->fetchColumn();
    ok('  ... invoice rebuilt under the same number', $no === $inv && strpos((string)$r['invoice_notice'], $inv) !== false, json_encode([$no, $r['invoice_notice']]));
    $h = array_values(array_filter(gca_hist($id), static fn($x) => $x['action'] === 'Charge added'));
    ok('  ... History says after check-out, with the reason', count($h) === 1 && strpos($h[0]['description'], 'after check-out') !== false && strpos($h[0]['description'], 'Reason:') !== false, json_encode($h));
    // The room checks out between preview and confirm: an open-room sig no longer matches.
    $pdo->exec("UPDATE bookings SET status = 'checked-in' WHERE id = $id");
    $pOpen = rh_charge_add_preview($pdo, $in, false);
    $pdo->exec("UPDATE bookings SET status = 'checked-out' WHERE id = $id");
    $r = rh_charge_add_post($pdo, $in, $user, true, (string)$pOpen['sig']);
    ok('status changed since the preview: refused', empty($r['success']) && count(gca_charges($id)) === 1, json_encode($r));
});

echo "== refused rooms ==\n";
foreach (['no-show', 'cancelled', 'expired'] as $st) {
    gca_case(function () use ($pdo, $user, $st) {
        $ref = gca_ref('GCR');
        $pdo->exec(gca_booking_sql($ref, $st));
        $id = gca_id($ref);
        $in = gca_in(['booking_id' => (string)$id]);
        $p = rh_charge_add_preview($pdo, $in, true);
        $r = rh_charge_add_post($pdo, $in, $user, true, rh_charge_add_single_sig($id, $st, true, 30.0));
        ok("$st: preview and post refused, nothing added", empty($p['success']) && empty($r['success']) && gca_charges($id) === [], json_encode([$p['message'], $r['message']]));
    });
}
gca_case(function () use ($pdo, $user) {
    $ref = gca_ref('GCD');
    $pdo->exec(gca_booking_sql($ref, 'confirmed'));
    $id = gca_id($ref);
    $pdo->exec("UPDATE bookings SET deleted_at = NOW() WHERE id = $id");
    $in = gca_in(['booking_id' => (string)$id]);
    $p = rh_charge_add_preview($pdo, $in, true);
    $r = rh_charge_add_post($pdo, $in, $user, true, rh_charge_add_single_sig($id, 'confirmed', true, 30.0));
    ok('deleted: preview and post refused, nothing added', empty($p['success']) && empty($r['success']) && gca_charges($id) === [], json_encode([$p['message'], $r['message']]));
});

echo "== group, mostly checked out ==\n";
$gcaGroup = function () use ($pdo): array {
    $p = gca_ref('GCG');
    $refs = ['lead' => $p . 'L', 'a' => $p . 'A', 'b' => $p . 'B', 'c' => $p . 'C'];
    $pdo->exec(gca_booking_sql($refs['lead'], 'checked-out'));
    $pdo->exec(gca_booking_sql($refs['a'], 'checked-out'));
    $pdo->exec(gca_booking_sql($refs['b'], 'checked-in'));
    $pdo->exec(gca_booking_sql($refs['c'], 'cancelled'));
    $lead = gca_id($refs['lead']);
    $pdo->exec("UPDATE bookings SET primary_booking_id = $lead WHERE booking_reference IN (" . gca_q($refs['a']) . ', ' . gca_q($refs['b']) . ', ' . gca_q($refs['c']) . ')');
    foreach (['lead', 'a', 'b'] as $k) { $pdo->exec(gca_paid_sql($refs[$k], 100)); }
    $ids = [];
    foreach ($refs as $k => $r) { $ids[$k] = gca_id($r); recalculateBookingFinancials($ids[$k]); }
    return $ids;
};
gca_case(function () use ($pdo, $user, $near, $gcaGroup) {
    $ids = $gcaGroup();
    $in = gca_in(['booking_id' => (string)$ids['a'], 'scope' => 'group_split', 'unit_price' => '90.01', 'description' => 'Boat trip']);
    $p = rh_charge_add_preview($pdo, $in, false);
    ok('split without the permission: only the checked-in room', !empty($p['success']) && count($p['rows']) === 1 && $p['rows'][0]['booking_id'] === $ids['b'] && count($p['skipped']) === 3, json_encode($p));
    $p = rh_charge_add_preview($pdo, $in, true);
    $byId = array_column($p['rows'], null, 'booking_id');
    ok('split with the permission: 3 rooms, cancelled skipped, sums to 90.01', !empty($p['success']) && count($p['rows']) === 3 && !isset($byId[$ids['c']])
        && $near(array_sum(array_column($p['rows'], 'amount')), 90.01) && !empty($byId[$ids['lead']]['checked_out']) && empty($byId[$ids['b']]['checked_out']), json_encode($p));
    $r = rh_charge_add_post($pdo, $in, $user, true, (string)$p['sig']);
    ok('split posted', !empty($r['success']) && count($r['charges']) === 3, json_encode($r));
    foreach (['lead', 'a', 'b'] as $k) {
        $b = gca_row($ids[$k]);
        $amt = (float)$byId[$ids[$k]]['amount'];
        ok("  ... $k owes its share, status kept, partial", $near($b['amount_due'], $amt) && $b['status'] === ($k === 'b' ? 'checked-in' : 'checked-out') && $b['payment_status'] === 'partial', json_encode($b));
        $h = array_values(array_filter(gca_hist($ids[$k]), static fn($x) => $x['action'] === 'Group charge added'));
        $meta = json_decode((string)($h[0]['metadata'] ?? ''), true) ?: [];
        ok("  ... $k History with the reason, owed 0 -> share", count($h) === 1 && ($meta['reason'] ?? '') === 'Guest left at 15:00' && $near($meta['owed_before'] ?? -1, 0) && $near($meta['owed_after'] ?? -1, $amt), json_encode($h));
    }
    ok('  ... cancelled room untouched', gca_charges($ids['c']) === []);
});
gca_case(function () use ($pdo, $user, $near, $gcaGroup) {
    $ids = $gcaGroup();
    $in = gca_in(['booking_id' => (string)$ids['b'], 'scope' => 'group_line', 'unit_price' => '50', 'description' => 'Damaged lamp']);
    $p = rh_charge_add_preview($pdo, $in, false);
    ok('one line without the permission: refused (the lead has checked out)', empty($p['success']), json_encode($p));
    $p = rh_charge_add_preview($pdo, $in, true);
    ok('one line with the permission: on the lead', !empty($p['success']) && count($p['rows']) === 1 && $p['rows'][0]['booking_id'] === $ids['lead'], json_encode($p));
    $r = rh_charge_add_post($pdo, $in, $user, true, (string)$p['sig']);
    $b = gca_row($ids['lead']);
    ok('  ... posted: lead owes 50, still checked-out', !empty($r['success']) && $near($b['amount_due'], 50) && $b['status'] === 'checked-out', json_encode([$r, $b]));
});

echo "== nonce and paths ==\n";
$store = [];
$bind = rh_charge_add_bind(gca_in(['booking_id' => '7']), 'sigA');
$n = rh_charge_add_nonce_issue($store, 7, $bind);
ok('nonce: first confirm accepted', rh_charge_add_nonce_take($store, 7, $n, $bind) === '');
ok('nonce: replay refused', rh_charge_add_nonce_take($store, 7, $n, $bind) !== '');
$n = rh_charge_add_nonce_issue($store, 7, $bind);
ok('nonce: changed details refused', rh_charge_add_nonce_take($store, 7, $n, rh_charge_add_bind(gca_in(['booking_id' => '7', 'unit_price' => '31']), 'sigA')) !== '');
ok('  ... and used up', rh_charge_add_nonce_take($store, 7, $n, $bind) !== '');
gca_case(function () use ($pdo, $user, $gcaGroup) {
    $p = gca_ref('GCX');
    $pdo->exec(gca_booking_sql($p . 'L', 'confirmed'));
    $pdo->exec(gca_booking_sql($p . 'A', 'checked-in'));
    $lead = gca_id($p . 'L'); $a = gca_id($p . 'A');
    $pdo->exec("UPDATE bookings SET primary_booking_id = $lead WHERE id = $a");
    $in = gca_in(['booking_id' => (string)$a, 'scope' => 'group_split', 'unit_price' => '20', 'description' => 'Boat trip']);
    $ours = rh_charge_add_preview($pdo, $in, false);
    $theirs = rh_group_charge_plan(rh_group_charge_rooms($pdo, $a), 'split', 'Boat trip', 1.0, 20.0);
    ok('same rooms, different path: the sigs differ', !empty($ours['sig']) && !empty($theirs['sig']) && $ours['sig'] !== $theirs['sig']);
    $r = rh_charge_add_post($pdo, $in, $user, false, (string)$theirs['sig']);
    ok('Booking Details sig on Add charge: refused, nothing added', empty($r['success']) && gca_charges($a) === [] && gca_charges($lead) === [], json_encode($r));
    $r = rh_group_charge_post($pdo, $a, 'split', 'service', 'Boat trip', 1.0, 20.0, $user, (string)$ours['sig']);
    ok('Add charge sig on Booking Details: refused, nothing added', empty($r['success']) && gca_charges($a) === [] && gca_charges($lead) === [], json_encode($r));
    $r = rh_group_charge_post($pdo, $a, 'split', 'service', 'Boat trip', 1.0, 20.0, $user, (string)$theirs['sig']);
    ok('Booking Details own sig still posts (unchanged)', !empty($r['success']) && count(gca_charges($a)) === 1 && count(gca_charges($lead)) === 1, json_encode($r));
});

echo "== the page ==\n";
function gca_run(array $post, array $opts = []): array {
    global $harness;
    $cfg = base64_encode(json_encode(['post' => $post, 'get' => $opts['get'] ?? [], 'ajax' => true, 'verify' => $opts['verify'] ?? [], 'setup' => $opts['setup'] ?? [],
        'grep' => $opts['grep'] ?? [], 'method' => $opts['method'] ?? 'POST', 'as_username' => $opts['as_username'] ?? '',
        'vars' => isset($opts['ref']) ? ['bid' => 'SELECT id FROM bookings WHERE booking_reference = ' . gca_q($opts['ref'])] : []]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' admin/charge-add.php ' . escapeshellarg($cfg) . ' 2>&1');
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -800), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
$pagePost = static fn(string $action, array $x = []): array => array_merge(['gca_action' => $action, 'booking_id' => '{bid}', 'scope' => 'room', 'charge_type' => 'service',
    'description' => 'Late checkout', 'quantity' => '1', 'unit_price' => '30', 'reason' => 'Left at 15:00'], $x);
$chargesSql = static fn(string $ref): string => 'SELECT COUNT(*) AS n FROM booking_charges WHERE booking_id = ' . gca_bq($ref);

$ref = gca_ref('GCP');
$r = gca_run($pagePost('preview'), ['setup' => [gca_booking_sql($ref, 'confirmed')], 'ref' => $ref, 'verify' => [$chargesSql($ref)]]);
ok('page preview: JSON with the row, a sig and a nonce, nothing written', !empty($r['json']['success']) && count($r['json']['rows'] ?? []) === 1 && !empty($r['json']['nonce'])
    && (int)($r['verify'][0][0]['n'] ?? 9) === 0, json_encode($r['json'] ?? $r));

$ref = gca_ref('GCP');
$r = gca_run($pagePost('confirm', ['sig' => 'x', 'nonce' => 'y']), ['setup' => [gca_booking_sql($ref, 'confirmed')], 'ref' => $ref, 'verify' => [$chargesSql($ref)]]);
ok('page confirm without a current preview: refused, nothing added', empty($r['json']['success']) && (int)($r['verify'][0][0]['n'] ?? 9) === 0, json_encode($r['json'] ?? $r));

$ref = gca_ref('GCP');
$r = gca_run($pagePost('preview', ['reason' => '']), ['setup' => [gca_booking_sql($ref, 'confirmed')], 'ref' => $ref]);
ok('page preview without a reason: refused', empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? ''), 'reason') !== false, json_encode($r['json'] ?? $r));

$staff = $pdo->query("SELECT id FROM admin_users WHERE username = 'test.staff' AND is_active = 1")->fetchColumn();
if ($staff) {
    $staff = (int)$staff;
    $grant = static fn(string $k, int $g): string => "INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES ($staff, '$k', $g, NULL) ON DUPLICATE KEY UPDATE is_granted = $g";
    $ref = gca_ref('GCP');
    $r = gca_run($pagePost('preview'), ['as_username' => 'test.staff', 'setup' => [gca_booking_sql($ref, 'confirmed'), $grant('edit_booking', 0)], 'ref' => $ref]);
    ok('receptionist without Edit Booking: no preview', empty($r['json']['success']), json_encode($r['json'] ?? substr((string)($r['raw'] ?? ''), -200)));
    $ref = gca_ref('GCP');
    $r = gca_run($pagePost('preview'), ['as_username' => 'test.staff', 'setup' => [gca_booking_sql($ref, 'checked-out'), $grant('edit_booking', 1), $grant('correct_stay_after_checkout', 0)], 'ref' => $ref]);
    ok('receptionist with Edit Booking, checked-out room: refused (needs the after-check-out permission)', empty($r['json']['success'])
        && stripos((string)($r['json']['message'] ?? ''), 'Correct Stay After Check-out') !== false, json_encode($r['json'] ?? $r));
    $ref = gca_ref('GCP');
    $r = gca_run($pagePost('preview'), ['as_username' => 'test.staff', 'setup' => [gca_booking_sql($ref, 'checked-out'), $grant('edit_booking', 1), $grant('correct_stay_after_checkout', 1)], 'ref' => $ref]);
    ok('  ... with it granted: preview allowed', !empty($r['json']['success']), json_encode($r['json'] ?? $r));
} else {
    echo "[SKIP] test.staff not found: receptionist cases skipped\n";
}

$r = gca_run([], ['method' => 'GET', 'grep' => ['Find the booking', 'Reason (kept in the booking history)', 'id="gca-preview-btn"']]);
ok('page renders the form', !empty($r['found']['Find the booking']) && !empty($r['found']['Reason (kept in the booking history)']) && !empty($r['found']['id="gca-preview-btn"']), json_encode($r['found'] ?? $r));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
