<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Finance list filter rules (admin/includes/finance-filters.php). NO DATABASE.
 * Payments / Invoices / Receipts: placeholders match params over a filter matrix, user text never reaches the SQL,
 * scope vs explicit type, union search fields, whole-day "To" date, bad dates dropped, and (reading the page
 * source as text) the cards, list, count and export of each page are wired to the same builder.
 * Usage (repo root): php scripts/tests/finance_filters.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

require_once $root . '/admin/includes/finance-filters.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');

$conf = ['company' => 'organization_name', 'reference' => 'enquiry_reference', 'email' => 'contact_email', 'phone' => 'contact_phone'];
$evil = "x' OR 1=1 -- ;DROP TABLE payments";

// ---- dates
eq(rh_fin_valid_date('2026-11-12'), '2026-11-12', 'valid date kept');
eq(rh_fin_valid_date('2026-02-30'), '', 'impossible date dropped');
eq(rh_fin_valid_date("2026-11-12' OR 1=1"), '', 'junk date dropped');
eq(rh_fin_valid_date(['2026-11-12']), '', 'array date dropped');
$dp = [];
eq(rh_fin_date_conditions('p.payment_date', '2026-11-01', '2026-11-12', $dp), ['p.payment_date >= ?', 'p.payment_date < DATE_ADD(?, INTERVAL 1 DAY)'], 'To covers the whole day (half-open)');
eq($dp, ['2026-11-01', '2026-11-12'], 'date params follow clause order');
$dp = [];
eq(rh_fin_date_conditions('p.payment_date', 'bad', '2026-13-01', $dp), [], 'bad dates add no clause');
eq($dp, [], 'bad dates add no param');

// ---- payments: matrix of filter inputs
$types = ['', 'room', 'gym'];
$scopes = [[], ['restaurant', 'gym', 'event']];
$searches = ['', 'Banda', $evil, '0'];
$others = [
    [],
    ['status' => 'completed', 'payment_method' => 'cash'],
    ['refund_status' => 'pending', 'booking_id' => 42],
    ['start_date' => '2026-11-01', 'end_date' => '2026-11-30'],
    ['start_date' => "2026-11-01'--", 'end_date' => 'nope', 'payment_method' => $evil, 'status' => $evil],
];
$matrixOk = true; $injOk = true; $deterministic = true; $n = 0;
foreach ($types as $t) foreach ($scopes as $sc) foreach ($searches as $q) foreach ($others as $o) {
    $f = array_merge(['booking_type' => $t, 'scope_types' => $sc, 'search' => $q], $o);
    $a = rh_fin_payments_where($f, 'transaction_reference', $conf);
    $b = rh_fin_payments_where($f, 'transaction_reference', $conf); // what the cards, count and export get
    $n++;
    if ($a !== $b) { $deterministic = false; }
    if (substr_count($a['sql'], '?') !== count($a['params'])) { $matrixOk = false; bad('payments placeholders', json_encode($f)); }
    if (strpos($a['sql'], 'OR 1=1') !== false || strpos($a['sql'], 'DROP') !== false || strpos($a['sql'], 'Banda') !== false) { $injOk = false; }
    if (strpos($a['sql'], 'WHERE p.deleted_at IS NULL') !== 0) { $matrixOk = false; bad('payments always excludes deleted', $a['sql']); }
}
check($deterministic, "payments builder gives identical SQL/params for cards and list ($n inputs)");
check($matrixOk, "payments: ? count == param count ($n inputs)");
check($injOk, 'payments: user text only ever travels as a bound param');

$r = rh_fin_payments_where(['booking_type' => 'room', 'scope_types' => ['gym', 'event']], 'transaction_reference', $conf);
eq($r, ['sql' => 'WHERE p.deleted_at IS NULL AND p.booking_type = ?', 'params' => ['room']], 'explicit type wins over module scope');
$r = rh_fin_payments_where(['scope_types' => ['restaurant', 'gym', 'event']], 'transaction_reference', $conf);
eq($r, ['sql' => 'WHERE p.deleted_at IS NULL AND p.booking_type IN (?,?,?)', 'params' => ['restaurant', 'gym', 'event']], 'scope gives one placeholder per enabled type');
$r = rh_fin_payments_where(['search' => 'Phiri'], 'transaction_reference', $conf);
foreach (['gi.name', 'gi.reference_number', 'ei.name', 'ei.reference_number', 'CAST(p.total_amount AS CHAR)', 'b.guest_name', 'ci.organization_name', 'so.customer_name', 'p.transaction_reference'] as $col) {
    check(strpos($r['sql'], $col) !== false, "payments search covers $col");
}
eq(count($r['params']), 27, 'payments search binds 27 params');
check(count(array_unique($r['params'])) === 1 && $r['params'][0] === '%Phiri%', 'payments search params are all %term%');
$r = rh_fin_payments_where(['search' => 'x'], 'txn; DROP TABLE x', ['company' => 'a b']);
check(strpos($r['sql'], 'DROP') === false && strpos($r['sql'], 'p.transaction_id') !== false && strpos($r['sql'], 'ci.company_name') !== false, 'bad column names fall back to safe defaults');
$r = rh_fin_payments_where(['end_date' => '2026-11-12'], 'transaction_reference', $conf);
eq($r, ['sql' => 'WHERE p.deleted_at IS NULL AND p.payment_date < DATE_ADD(?, INTERVAL 1 DAY)', 'params' => ['2026-11-12']], 'payments To date is whole-day');
$from = rh_fin_payments_from();
foreach (["bookings b ON p.booking_type = 'room' AND p.booking_id = b.id", "gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id", "event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id"] as $j) {
    check(strpos($from, $j) !== false, 'payments joins on the primary key: ' . explode(' ', $j)[0]);
}

// ---- invoices
$invOk = true; $invInj = true; $m = 0;
foreach (['all', 'room', 'event'] as $t) foreach ([[], ['room', 'restaurant']] as $sc) foreach (['all', 'pending', $evil] as $st) foreach (['', 'INV-2026', $evil] as $q) {
    $f = ['type' => $t, 'scope_types' => $sc, 'status' => $st, 'search' => $q];
    $a = rh_fin_invoices_where($f);
    $m++;
    if ($a !== rh_fin_invoices_where($f) || substr_count($a['sql'], '?') !== count($a['params'])) { $invOk = false; bad('invoices placeholders', json_encode($f)); }
    if (strpos($a['sql'], 'OR 1=1') !== false || strpos($a['sql'], 'INV-2026') !== false) { $invInj = false; }
}
check($invOk, "invoices: identical and ? count == param count ($m inputs)");
check($invInj, 'invoices: user text only ever travels as a bound param');
eq(rh_fin_invoices_where(['type' => 'all', 'status' => 'all', 'search' => '']), ['sql' => 'WHERE p.deleted_at IS NULL', 'params' => []], 'invoices: no filters');
eq(rh_fin_invoices_where(['type' => 'gym', 'scope_types' => ['room'], 'status' => 'paid', 'search' => 'A1']), [
    'sql' => 'WHERE p.deleted_at IS NULL AND p.booking_type = ? AND p.payment_status = ? AND (p.invoice_number LIKE ? OR p.payment_reference LIKE ? OR p.booking_reference LIKE ?)',
    'params' => ['gym', 'paid', '%A1%', '%A1%', '%A1%'],
], 'invoices: type, status and search in list order');
eq(rh_fin_invoices_where(['type' => 'all', 'scope_types' => ['room', 'gym']])['params'], ['room', 'gym'], 'invoices: module scope');

// ---- wiring (page source read as text, nothing executed)
$pay = (string)file_get_contents($root . '/admin/payments.php');
check(strpos($pay, '$analyticsWhere[]') === false && strpos($pay, 'EXISTS (') === false, 'payments: old separate card WHERE is gone');
check(strpos($pay, '$analyticsWhereSql = "WHERE id IN (SELECT p.id " . $payFromSql . \' \' . $payWhereSql . ")";') !== false, 'payments: cards use the list FROM + WHERE');
check(strpos($pay, '$analyticsParams = $payFilterParams;') !== false, 'payments: cards use the list params');
check(strpos($pay, '$countSql = "SELECT COUNT(*) as total " . $payFromSql . \' \' . $payWhereSql;') !== false, 'payments: count uses the list FROM + WHERE');
check(strpos($pay, '$stmt->execute(array_merge($payFilterParams, [$limit, $offset]));') !== false, 'payments: list uses the shared params');
check(strpos($pay, '$exportParams = $payFilterParams;') !== false && strpos($pay, '$where_conditions') === false, 'payments: CSV export uses the shared filter');
check(substr_count($pay, '$analyticsWhereSql') === 5 && substr_count($pay, '->execute($analyticsParams)') === 4, 'payments: all four card queries read the shared filter');
$inv = (string)file_get_contents($root . '/admin/invoices.php');
check(substr_count($inv, "{\$invFilter['sql']}") === 2 && substr_count($inv, "\$sql .= ' ' . \$invFilter['sql'];") === 1, 'invoices: list and both card queries use the shared WHERE');
check(strpos($inv, "FROM payments\n        WHERE deleted_at IS NULL") === false, 'invoices: unfiltered card WHERE is gone');
check(strpos($inv, "'Error fetching invoices: ' . \$e->getMessage()") === false, 'invoices: database error no longer shown on screen');
$rcp = (string)file_get_contents($root . '/admin/receipts.php');
check(strpos($rcp, "'p.payment_date <= ?'") === false && strpos($rcp, "rh_fin_date_conditions('p.payment_date'") !== false, 'receipts: whole-day To date via the shared helper');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
