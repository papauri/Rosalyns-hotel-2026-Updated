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

// Any PHP warning/notice (e.g. "Array to string conversion") is a failure, not noise.
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { bad('PHP notice/warning', "$msg at $file:$line"); return true; });
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
    ['refund_status' => 'pending,processing'],
    ['refund_status' => 'processing, pending,pending', 'status' => 'completed'],
    ['refund_status' => "pending,{$evil},processing"],
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
$r = rh_fin_invoices_where(['type' => 'gym', 'scope_types' => ['room'], 'status' => 'paid', 'search' => ' A1 '], $conf);
check(strpos($r['sql'], 'WHERE p.deleted_at IS NULL AND p.booking_type = ? AND p.payment_status = ? AND (') === 0, 'invoices: type, status, then search');
eq(array_slice($r['params'], 0, 2), ['gym', 'paid'], 'invoices: type and status params first');
eq(array_values(array_unique(array_slice($r['params'], 2))), ['%A1%'], 'invoices: search term trimmed, all %term%');
eq(count($r['params']), 2 + 11, 'invoices search binds 11 params');
foreach (['p.invoice_number', 'p.payment_reference', 'p.booking_reference', "COALESCE(b.guest_name, '')", "COALESCE(b.guest_email, '')", "COALESCE(ci.organization_name, '')", "COALESCE(ci.contact_email, '')", "COALESCE(gi.name, '')", "COALESCE(gi.email, '')", "COALESCE(ei.name, '')", "COALESCE(ei.email, '')"] as $col) {
    check(strpos($r['sql'], $col . " LIKE ? ESCAPE '!'") !== false, "invoices search covers $col");
}
$from = rh_fin_invoices_from();
foreach (["bookings b ON p.booking_type = 'room' AND p.booking_id = b.id", "conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id", "gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id", "event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id"] as $j) {
    check(strpos($from, $j) !== false, 'invoices card joins on the primary key: ' . explode(' ', $j)[0]);
}

// ---- LIKE wildcards typed by staff match literally; every LIKE carries the escape
eq(rh_fin_like_param('50%_x!'), '%50!%!_x!!%', 'like param escapes % _ and the escape char');
$r = rh_fin_payments_where(['search' => '10%'], 'transaction_reference', $conf);
check(substr_count($r['sql'], ' LIKE ?') === substr_count($r['sql'], " LIKE ? ESCAPE '!'") && substr_count($r['sql'], ' LIKE ?') === 27, 'payments: every LIKE has ESCAPE (27)');
eq($r['params'][0], '%10!%%', 'payments: % in search is literal');
$r = rh_fin_invoices_where(['search' => 'a_b'], $conf);
check(substr_count($r['sql'], ' LIKE ?') === substr_count($r['sql'], " LIKE ? ESCAPE '!'") && substr_count($r['sql'], ' LIKE ?') === 11, 'invoices: every LIKE has ESCAPE (11)');
eq($r['params'][0], '%a!_b%', 'invoices: _ in search is literal');

// ---- array-valued query params are ignored, never "Array" or (int)array === 1
$g = ['s' => ['x'], 'n' => ['5'], 't' => ' room ', 'i' => '42', 'bad' => '4x', 'f' => 3];
eq(rh_fin_get_str($g, 's'), '', 'get_str: array ignored');
eq(rh_fin_get_str($g, 's', 'all'), 'all', 'get_str: array gives default');
eq(rh_fin_get_str($g, 'missing', 'all'), 'all', 'get_str: missing gives default');
eq(rh_fin_get_str($g, 't'), 'room', 'get_str: trimmed text');
eq(rh_fin_get_str($g, 'f'), '3', 'get_str: number as text');
eq(rh_fin_get_int($g, 'n'), 0, 'get_int: array is 0, not 1');
eq(rh_fin_get_int($g, 'i'), 42, 'get_int: digits');
eq(rh_fin_get_int($g, 'bad', 7), 7, 'get_int: junk gives default');
eq(rh_fin_payments_where(['booking_type' => ['room'], 'booking_id' => ['9'], 'search' => ['x'], 'status' => ['paid'], 'payment_method' => ['cash'], 'refund_status' => ['pending']], 'transaction_reference', $conf), ['sql' => 'WHERE p.deleted_at IS NULL', 'params' => []], 'payments builder ignores array inputs');
eq(rh_fin_invoices_where(['type' => ['room'], 'status' => ['paid'], 'search' => ['x']], $conf), ['sql' => 'WHERE p.deleted_at IS NULL', 'params' => []], 'invoices builder ignores array inputs');
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
check(strpos($pay, 'All payments, not affected by filters') !== false, 'payments: Today card says it ignores the filters');
check(strpos($pay, 'rh_receivable_rows($pdo, rh_receivable_mods(), $outstandingFailed)') !== false && strpos($pay, 'FROM bookings WHERE deleted_at IS NULL AND amount_due > 0.01') === false, 'payments: outstanding view uses the shared receivables rule');
check(preg_match('/\(int\)\$_GET|\(string\)\(\$_GET|\? \$_GET\[/', $pay) === 0, 'payments: no raw casts of query params');
$inv = (string)file_get_contents($root . '/admin/invoices.php');
check(substr_count($inv, "{\$invCardWhere}") === 2 && substr_count($inv, "\$sql .= ' ' . \$invFilter['sql'];") === 1, 'invoices: list and both card queries use the shared WHERE');
check(strpos($inv, "\$invCardWhere = 'WHERE id IN (SELECT p.id ' . rh_fin_invoices_from() . ' ' . \$invFilter['sql'] . ')';") !== false, 'invoices: cards select their rows through the same joins as the search');
check(strpos($inv, "], \$conferenceFields);") !== false, 'invoices: search gets the conference column names');
check(strpos($inv, "'Error: ' . \$e->getMessage()") === false && strpos($inv, "rh_log_event('admin/invoices', 'error', 'Invoice action failed'") !== false, 'invoices: action errors from the database are logged, not shown');
check(strpos($inv, 'href="?filter_status=pending"') === false && strpos($inv, 'href="payments.php?balance=outstanding" class="acct-link">view accounts owing</a>') !== false, 'invoices: Outstanding card opens the accounts it adds up');
check(strpos($inv, '&amp;filter_status=pending">View') === false && strpos($inv, 'payments.php?balance=outstanding&amp;booking_type=') !== false, "invoices: by-source links open that source's accounts owing");
check(substr_count($inv, 'All accounts owing, not affected by filters') === 2, 'invoices: Outstanding card and aging panel say they ignore the filters');
check(preg_match('/\$_GET\[\'(filter_type|filter_status|search|scope)\'\]/', $inv) === 0, 'invoices: filter params read through rh_fin_get_str');
check(strpos($inv, "FROM payments\n        WHERE deleted_at IS NULL") === false, 'invoices: unfiltered card WHERE is gone');
check(strpos($inv, "'Error fetching invoices: ' . \$e->getMessage()") === false, 'invoices: database error no longer shown on screen');
$rcp = (string)file_get_contents($root . '/admin/receipts.php');
check(strpos($rcp, "'p.payment_date <= ?'") === false && strpos($rcp, "rh_fin_date_conditions('p.payment_date'") !== false, 'receipts: whole-day To date via the shared helper');

// ---- Pending / Partial card link: 'pending_partial' lists exactly the card's rows
$r = rh_fin_payments_where(['status' => 'pending_partial', 'booking_type' => 'room'], 'transaction_reference', $conf);
eq($r, ['sql' => "WHERE p.deleted_at IS NULL AND p.booking_type = ? AND p.payment_status IN ('pending','partial') AND COALESCE(p.payment_type, '') <> 'refund'", 'params' => ['room']], 'payments: pending_partial = the card rule (pending or partial, no refunds), other filters kept');
check(strpos($pay, 'href="?status=pending"') === false && strpos($pay, "in_array(\$status, ['pending', 'partial'], true) ? \$status : 'pending_partial'") !== false, 'payments: Pending / Partial card opens what it counts, with the same filters');
check(strpos($pay, '<option value="pending_partial"') !== false, 'payments: status box can show "Pending or partial"');

// ---- receipts: escaped search + text-only params
check(strpos($rcp, "rh_fin_like_any(['p.receipt_number', 'p.payment_reference', 'p.booking_reference', 'p.notes'])") !== false && strpos($rcp, 'rh_fin_like_param($search)') !== false, 'receipts: search uses the escaped LIKE helpers');
check(strpos($rcp, "\$like = '%' . \$input['search'] . '%';") === false, 'receipts: old unescaped LIKE is gone');
check(preg_match('/\(int\)\(\$_GET|\(string\)\(\$_GET|\$_GET\[\'(type|status|search|scope|page)\'\]/', $rcp) === 0, 'receipts: query params read through rh_fin_get_str / rh_fin_get_int');

// ---- refund status set (Dashboard "Refunds to approve" = pending + processing, every source)
eq(rh_fin_refund_statuses('pending,processing'), ['pending', 'processing'], 'refund set: two statuses kept');
eq(rh_fin_refund_statuses(' Processing , pending,pending'), ['pending', 'processing'], 'refund set: trimmed, deduped, lifecycle order');
eq(rh_fin_refund_statuses("pending,{$evil}"), ['pending'], 'refund set: unknown entries dropped');
eq(rh_fin_refund_statuses($evil), [], 'refund set: junk gives no filter');
eq(rh_fin_refund_statuses(['pending']), [], 'refund set: array ignored');
eq(rh_fin_payments_where(['refund_status' => 'processing,pending'], 'transaction_reference', $conf), ['sql' => "WHERE p.deleted_at IS NULL AND p.payment_type = 'refund' AND p.refund_status IN (?,?)", 'params' => ['pending', 'processing']], 'payments: refund set is one bound IN');
eq(rh_fin_payments_where(['refund_status' => 'pending'], 'transaction_reference', $conf), ['sql' => "WHERE p.deleted_at IS NULL AND p.payment_type = 'refund' AND p.refund_status = ?", 'params' => ['pending']], 'payments: single refund status unchanged');
eq(rh_fin_payments_where(['refund_status' => "x,{$evil}"], 'transaction_reference', $conf), ['sql' => 'WHERE p.deleted_at IS NULL', 'params' => []], 'payments: junk refund set adds no clause');
check(strpos($pay, "\$refundStatusList = rh_fin_refund_statuses(rh_fin_get_str(\$_GET, 'refund_status'));") !== false, 'payments: page reads the refund set through the whitelist');
check(strpos($pay, "\$scopeActive         = \$bookingType === '' && !\$scopeAll && \$refundStatus === ''") !== false, 'payments: refund queue is not module-scoped (matches the Dashboard count)');
check(strpos($pay, "array_merge(\$_GET, ['refund_status' => null, 'page' => null])") !== false && strpos($pay, 'name="refund_status"') !== false, 'payments: refund notice has Clear; the filter form keeps the set');

// ---- accounts owing: no '#' links; links only to users who can open the page
check(strpos($pay, "'link' => '#'") === false, "payments: outstanding rows never link to '#'");
check(strpos($pay, "\$viewUrl = (\$oid > 0 && \$src !== '') ? \$meta['link'] . \$oid : '';") !== false && strpos($pay, "<?php if (\$viewUrl !== ''): ?>") !== false, 'payments: rows that cannot be pinned to one account show no link');
check(strpos($inv, "\$invCanPayments   = hasPermission((int)(\$user['id'] ?? 0), 'payments');") !== false, 'invoices: Payments access checked once');
check(preg_match('/<\?php if \(\$invCanPayments\): \?> ·\s*<a href="payments\.php\?balance=outstanding"/u', $inv) === 1, 'invoices: "view accounts owing" only for Payments access');
check(preg_match('/<\?php if \(\$invCanPayments\): \?>\s*<a class="acct-link" href="payments\.php\?balance=outstanding&amp;booking_type=/', $inv) === 1, 'invoices: by-source View only for Payments access');
check(preg_match('/<\?php if \(\$invCanPayments\): \?>\s*<a href="payments\.php" class="acct-quick-action">/', $inv) === 1 && preg_match('/<\?php if \(\$invCanPaymentAdd\): \?>\s*<a href="payment-add\.php"/', $inv) === 1 && preg_match('/<\?php if \(\$invCanAccounting\): \?>\s*<a href="accounting-dashboard\.php"/', $inv) === 1, 'invoices: header links follow their page permissions');

// ---- Dashboard deep links (2026-11-11 LATER): payments ?q= alias, reports ?type= + range=today
eq(rh_fin_search_term(['q' => 'RH-123']), 'RH-123', 'payments: ?q= is the search when search_text is empty');
eq(rh_fin_search_term(['q' => 'old', 'search_text' => 'new']), 'new', 'payments: search_text wins over q');
eq(rh_fin_search_term(['q' => ['x']]), '', 'payments: array q ignored');
check(strpos($pay, '$searchText = rh_fin_search_term($_GET);') !== false, 'payments: page reads search through the q alias');
$tabs = ['overview', 'revenue', 'vat', 'aging', 'bookings', 'occupancy', 'guests', 'conference', 'fnb', 'stock', 'staff', 'voids'];
eq(rh_fin_reports_preset(['type' => 'accounting', 'range' => 'today'], $tabs, true, '2026-11-11'), ['tab' => 'fnb', 'range_today' => true], 'reports: Dashboard link -> POS tab, today');
eq(rh_fin_reports_preset(['type' => 'accounting'], $tabs, false, '2026-11-11'), ['tab' => 'revenue', 'range_today' => false], 'reports: accounting without POS -> Revenue');
eq(rh_fin_reports_preset(['type' => 'VAT'], $tabs, true, '2026-11-11')['tab'], 'vat', 'reports: a known tab name is taken (any case)');
eq(rh_fin_reports_preset(['type' => $evil], $tabs, true, '2026-11-11')['tab'], '', 'reports: unknown type ignored');
eq(rh_fin_reports_preset(['type' => ['fnb']], $tabs, true, '2026-11-11')['tab'], '', 'reports: array type ignored');
eq(rh_fin_reports_preset(['tab' => 'vat', 'type' => 'accounting'], $tabs, true, '2026-11-11')['tab'], '', 'reports: an explicit tab wins over type');
eq(rh_fin_reports_preset(['range' => 'today', 'start_date' => '2026-11-01'], $tabs, true, '2026-11-11')['range_today'], false, 'reports: explicit dates win over range=today');
eq(rh_fin_reports_preset(['range' => 'week'], $tabs, true, '2026-11-11')['range_today'], false, 'reports: other range values ignored');
$rep = (string)file_get_contents($root . '/admin/reports.php');
check(strpos($rep, '$rh_report_preset = rh_fin_reports_preset($_GET, $valid_tabs, $mod_pos, date(\'Y-m-d\'));') !== false
    && strpos($rep, '$rh_report_preset = ') < strpos($rep, 'if (!in_array($active_tab, $valid_tabs))'), 'reports: preset applied before the tab whitelist and module gate');
check(strpos($rep, '<?php if ($rh_report_preset_on): ?>') !== false && strpos($rep, '<a href="?tab=<?php echo htmlspecialchars($active_tab); ?>"') !== false, 'reports: notice with Clear');

// ---- Accounts owing: unknown sources show their own type name, not the restaurant "Open tab" badge
$known = ['restaurant' => ['label' => 'Open tab', 'badge' => 'restaurant', 'link' => 'payments.php?booking_type=restaurant&booking_id=']];
eq(rh_fin_outstanding_src_meta('restaurant', $known)['label'], 'Open tab', 'owing: restaurant keeps Open tab');
$gs = rh_fin_outstanding_src_meta('gym_shop', $known);
check($gs['label'] === 'Gym shop' && $gs['badge'] === 'neutral' && $gs['link'] === 'payments.php?booking_type=gym_shop&booking_id=', 'owing: unknown source -> its own name, neutral badge, its own ledger rows', json_encode($gs));
eq(rh_fin_outstanding_src_meta('', $known)['label'], 'Other', 'owing: empty source -> Other');
check(strpos(rh_fin_outstanding_src_meta("a&b=c", $known)['link'], 'booking_type=a%26b%3Dc') !== false, 'owing: source is URL-encoded in the link');
check(strpos($pay, '$meta = rh_fin_outstanding_src_meta($src, $srcMeta);') !== false && strpos($pay, "\$meta = \$srcMeta[\$src] ?? ['label' => 'Open tab'") === false, 'payments: owing list uses the helper');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
