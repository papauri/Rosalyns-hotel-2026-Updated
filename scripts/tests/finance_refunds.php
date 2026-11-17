<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The one refund rule + Payments row wording (admin/includes/finance-refunds.php). NO DATABASE.
 * - refund totals are gross total_amount, counted when refund_status is completed or processing; pending apart;
 * - a received part-payment never reads as a settled bill (rh_pay_row_state), compared with the tolerance;
 * - (reading page source as text) every refund total on Payments, Accounting, Reports and End of Day uses the helper.
 * Usage (repo root): php scripts/tests/finance_refunds.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { bad('PHP notice/warning', "$msg at $file:$line"); return true; });
require_once $root . '/admin/includes/finance-refunds.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'loads without the database');
$tol = 0.01;

// ---- SQL fragments
eq(rh_refund_settled_statuses(), ['completed', 'processing'], 'settled = completed + processing');
eq(rh_refund_settled_sql(), "payment_type = 'refund' AND refund_status IN ('completed','processing')", 'settled condition, no alias');
eq(rh_refund_settled_sql('p'), "p.payment_type = 'refund' AND p.refund_status IN ('completed','processing')", 'settled condition, alias p');
eq(rh_refund_pending_sql('p'), "p.payment_type = 'refund' AND p.refund_status = 'pending'", 'pending condition');
eq(rh_refund_amount_sql(), 'total_amount', 'amount = gross total_amount');
eq(rh_refund_amount_sql("x; DROP TABLE payments"), 'total_amount', 'a bad alias is dropped, never put in the SQL');
check(strpos(rh_refund_settled_sum_sql(), 'refund_amount') === false && strpos(rh_refund_settled_sum_sql(), 'THEN total_amount') !== false, 'settled sum adds total_amount');
check(strpos(rh_refund_pending_sum_sql(), "refund_status = 'pending'") !== false && strpos(rh_refund_pending_sum_sql(), 'processing') === false, 'pending sum is pending only');
check(strpos(rh_refund_settled_count_sql(), 'THEN 1 ELSE 0') !== false && strpos(rh_refund_pending_count_sql(), 'THEN 1 ELSE 0') !== false, 'counts count rows');

// ---- refund lifecycle words
eq(rh_refund_status_label('pending'), 'Awaiting approval', 'pending words');
eq(rh_refund_status_label('processing'), 'Being paid', 'processing words');
eq(rh_refund_status_label('COMPLETED'), 'Paid out', 'completed words (any case)');
eq(rh_refund_status_label('failed'), 'Failed', 'failed words');
eq(rh_refund_status_label(''), 'Unknown', 'blank status');

// ---- row wording
$base = ['payment_type' => 'payment', 'payment_status' => 'completed', 'refund_status' => null, 'account_amount_due' => '50000.00', 'account_status' => 'confirmed', 'account_in_rule' => 1];
$s = rh_pay_row_state($base, $tol, 'MWK');
eq([$s['label'], $s['badge'], $s['note'], $s['tone'], $s['amount_sign']], ['Received', 'completed', 'Booking still owes MWK 50,000', 'owe', ''], 'part-paid: Received + still owes');
$s = rh_pay_row_state(['account_amount_due' => '0.004'] + $base, $tol, 'MWK');
eq([$s['note'], $s['tone']], ['Bill settled', 'ok'], 'a balance inside the tolerance reads settled');
$s = rh_pay_row_state(['account_amount_due' => '0.02'] + $base, $tol, 'MWK');
eq($s['tone'], 'owe', 'a balance just above the tolerance still owes');
$s = rh_pay_row_state(['payment_status' => 'paid', 'account_amount_due' => '0'] + $base, $tol);
eq([$s['label'], $s['note']], ['Received', 'Bill settled'], 'paid reads Received too');
$s = rh_pay_row_state(['account_status' => 'pending', 'account_in_rule' => 0, 'account_unconfirmed' => 1] + $base, $tol, 'MWK');
eq([$s['note'], $s['tone']], ['Not confirmed yet: MWK 50,000 open', 'warn'], 'unconfirmed account: never "still owes", never "settled"');
$s = rh_pay_row_state(['account_status' => 'tentative', 'account_in_rule' => 0, 'account_unconfirmed' => 1] + $base, $tol, 'MWK');
eq($s['note'], 'Not confirmed yet: MWK 50,000 open', 'tentative hold reads not confirmed');
$s = rh_pay_row_state(['account_status' => 'no-show', 'account_in_rule' => 0] + $base, $tol, 'MWK');
eq([$s['note'], $s['tone']], ['No show: MWK 50,000, not counted as owed', 'warn'], 'no-show: named, not "not confirmed"');
$s = rh_pay_row_state(['account_status' => 'expired', 'account_in_rule' => 0] + $base, $tol, 'MWK');
eq($s['note'], 'Expired: MWK 50,000, not counted as owed', 'expired: named, not counted as owed');
$s = rh_pay_row_state(['account_status' => 'cancelled', 'account_in_rule' => 0, 'account_amount_due' => '0'] + $base, $tol);
eq($s['note'], 'Booking cancelled', 'cancelled with nothing owed reads cancelled, not settled');
$s = rh_pay_row_state(['account_status' => 'cancelled', 'account_in_rule' => 1, 'account_amount_due' => '12000'] + $base, $tol, 'MWK');
eq($s['note'], 'Booking still owes MWK 12,000', 'cancelled room keeping its first night still owes it');
$s = rh_pay_row_state(['account_status' => 'deleted', 'account_in_rule' => 0] + $base, $tol);
eq($s['note'], 'Booking deleted', 'deleted booking');
$s = rh_pay_row_state(['account_amount_due' => null, 'account_status' => null, 'account_in_rule' => 0] + $base, $tol);
eq([$s['label'], $s['note']], ['Received', ''], 'till order: Received, no bill note');
$s = rh_pay_row_state(['payment_status' => 'partially_refunded'] + $base, $tol, 'MWK');
eq([$s['label'], $s['badge']], ['Received, part refunded', 'refunded'], 'part refunded original');
$s = rh_pay_row_state(['payment_status' => 'pending'] + $base, $tol, 'MWK');
eq([$s['label'], $s['note']], ['Pending (not received)', 'Booking still owes MWK 50,000'], 'pending record says not received');
$s = rh_pay_row_state(['payment_status' => 'failed'] + $base, $tol, 'MWK');
eq([$s['label'], $s['note']], ['Failed', ''], 'failed: no bill note');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'pending', 'refund_status' => 'processing'] + $base, $tol);
eq([$s['label'], $s['badge'], $s['note'], $s['amount_sign']], ['Refund: Being paid', 'info', 'Counted in Refunds', '-'], 'processing refund: counted, minus sign');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'pending', 'refund_status' => 'pending'] + $base, $tol);
eq([$s['label'], $s['note']], ['Refund: Awaiting approval', 'Not in Refunds until approved'], 'pending refund: not counted');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'completed', 'refund_status' => 'completed'] + $base, $tol);
eq([$s['label'], $s['badge']], ['Refund: Paid out', 'refunded'], 'completed refund');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'cancelled', 'refund_status' => 'failed'] + $base, $tol);
eq($s['note'], 'Not counted', 'failed refund not counted');

$s = rh_pay_row_state(['account_status' => 'new', 'account_in_rule' => 0, 'account_unconfirmed' => 1] + $base, $tol, 'MWK');
eq($s['note'], 'Not confirmed yet: MWK 50,000 open', 'a new gym enquiry with a deposit reads not confirmed (same flag as the deposits line)');

// ---- refunds moved into a credit note (overpayment / store credit): no cash out
eq(rh_refund_to_credit_sql('p'), "p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') AND p.payment_method = 'credit_note'", 'to-credit condition');
check(strpos(rh_refund_to_credit_sum_sql(), "payment_method = 'credit_note' THEN total_amount") !== false, 'to-credit sum adds total_amount');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'completed', 'refund_status' => 'completed', 'payment_method' => 'credit_note'] + $base, $tol);
eq([$s['label'], $s['note'], $s['amount_sign']], ['Refund: Moved to credit note', 'Guest credit, no cash out', '-'], 'credit-note leg never reads "Paid out"');
$s = rh_pay_row_state(['payment_type' => 'refund', 'payment_status' => 'completed', 'refund_status' => 'completed', 'payment_method' => 'cash'] + $base, $tol);
eq($s['label'], 'Refund: Paid out', 'cash refund reads Paid out');
// ---- credit-note REDEMPTION (applyCreditNote / group credit move receiving room): guest credit used, not money received
$s = rh_pay_row_state(['payment_method' => 'credit_note'] + $base, $tol, 'MWK');
check($s['label'] !== 'Received', 'redemption (completed, credit_note) is NOT "Received"');
eq([$s['label'], $s['badge'], $s['note'], $s['tone']], ['Paid from credit note', 'neutral', 'Booking still owes MWK 50,000', 'owe'], 'redemption reads "Paid from credit note", neutral badge, bill note kept');
$s = rh_pay_row_state(['payment_status' => 'paid', 'payment_method' => ' Credit_Note ', 'account_amount_due' => '0'] + $base, $tol);
eq([$s['label'], $s['note']], ['Paid from credit note', 'Bill settled'], 'redemption on an older paid row, method normalised');
eq(rh_pay_row_pill_tone($s['badge']), 'muted', 'redemption pill is neutral (muted), not green');
$s = rh_pay_row_state(['payment_method' => 'cash'] + $base, $tol, 'MWK');
eq($s['label'], 'Received', 'cash payment still reads Received');
$s = rh_pay_row_state(['payment_status' => 'pending', 'payment_method' => 'credit_note'] + $base, $tol, 'MWK');
eq($s['label'], 'Pending (not received)', 'pending credit-note row keeps its pending words');
eq(rh_refund_split_text(477559.57, 477559.57, 'MWK'), 'Paid back in cash / bank: MWK 0 · Moved to credit notes (guest credit, no cash out): MWK 477,560', 'split: all moved to credit notes (Liwonde case)');
eq(rh_refund_split_text(500000, 0, 'MWK'), '', 'split: nothing to credit notes = no line');
eq(rh_refund_split_text(1000, 250.5, '', 2), 'Paid back in cash / bank: 749.50 · Moved to credit notes (guest credit, no cash out): 250.50', 'split: mixed, 2 decimals');
eq(rh_refund_split_text(1000.6, 500.3, '', 0), 'Paid back in cash / bank: 501 · Moved to credit notes (guest credit, no cash out): 500', 'split: parts add up to the shown total (1,001), no rounding gap');
eq(rh_refund_split_text(999.5, 0.4, 'MWK', 0), 'Paid back in cash / bank: MWK 1,000 · Moved to credit notes (guest credit, no cash out): MWK 0', 'split: tiny credit rounds to 0 at whole kwacha, cash = shown total');

// ---- not confirmed yet (receivables.php): outside the owed rule and not closed
require_once $root . '/admin/includes/receivables.php';
$u = rh_unconfirmed_status_sql('conference', 'ci');
check(strpos($u, "ci.status NOT IN ('cancelled','no-show','expired','closed')") !== false && strpos($u, "NOT (ci.status IN ('confirmed','completed'))") !== false, 'unconfirmed conference = open and not confirmed/completed');
$u = rh_unconfirmed_status_sql('room', 'b');
check(strpos($u, 'b.deleted_at IS NULL') === 0 && strpos($u, 'NOT (b.deleted_at IS NULL AND (b.status IN') !== false, 'unconfirmed room = live, open and outside the room owed rule (tentative)');
eq(rh_unconfirmed_status_sql('restaurant', 'so'), '1=0', 'unknown type never matches');

// ---- every refund total on the money pages uses the helper (source as text)
$pages = ['admin/payments.php', 'admin/accounting-dashboard.php', 'admin/reports.php', 'admin/end-of-day-report.php',
          'admin/includes/eod-revenue.php', 'admin/api/end-of-day-pdf.php', 'admin/api/end-of-day-send.php'];
foreach ($pages as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    check(preg_match('/THEN\s+-?refund_amount\b|SUM\(\s*refund_amount\s*\)|-refund_amount\b/', $src) === 0, "$rel: no refund total reads refund_amount");
}
$pay = (string)file_get_contents($root . '/admin/payments.php');
check(strpos($pay, 'rh_refund_settled_sum_sql() . " AS refunds_total') !== false && strpos($pay, 'rh_refund_pending_sum_sql() . " AS refunds_pending_total') !== false, 'payments: Refunds card uses the helper, pending apart');
check(strpos($pay, '$payState = rh_pay_row_state($payment, (float)BALANCE_TOLERANCE') !== false, 'payments: row wording via rh_pay_row_state with BALANCE_TOLERANCE');
check(strpos($pay, "rh_receivable_status_sql('conference', 'ci')") !== false && strpos($pay, 'END as account_in_rule') !== false, 'payments: row note uses the receivables rule, in the list query');
check(strpos($pay, '<span class="ck-kpi__label">Still owed</span>') !== false && strpos($pay, '<span class="ck-kpi__label">Pending / Partial</span>') === false, 'payments: Still owed card replaces Pending / Partial');
check(strpos($pay, '> 0.001') === false, 'payments: no raw 0.001 money comparison');
$rep = (string)file_get_contents($root . '/admin/reports.php');
check(substr_count($rep, 'rh_refund_settled_sql()') >= 2, 'reports: refund reasons and trends count paid-out refunds only');

// ---- G1 (owner 2026-11-18): every payment-ROW status reads like the Payments list ("Received" + the bill's note)
eq(rh_pay_row_pill_tone('completed'), 'ok', 'pill tone: Received is ok');
eq(rh_pay_row_pill_tone('refunded'), 'muted', 'pill tone: refunded / paid-out refund is muted');
eq(rh_pay_row_pill_tone('warning'), 'warn', 'pill tone: refund awaiting approval is warn');
eq(rh_pay_row_pill_tone('info'), 'warn', 'pill tone: refund being paid is warn');
eq(rh_pay_row_pill_tone('failed'), 'alert', 'pill tone: failed is alert');
eq(rh_pay_row_pill_tone('owe'), 'alert', 'pill tone: still-owes note is alert');
eq(rh_pay_row_pill_tone('ok'), 'ok', 'pill tone: bill-settled note is ok');
eq(rh_pay_row_pill_tone('pending'), 'warn', 'pill tone: pending payment is warn');
eq(rh_pay_row_pill_tone('something-new'), 'muted', 'pill tone: unknown is muted');
$cols = rh_pay_row_account_cols_sql();
check(strpos($cols, 'AS account_amount_due') !== false && strpos($cols, 'AS account_status') !== false
    && strpos($cols, 'AS account_in_rule') !== false && strpos($cols, 'AS account_unconfirmed') !== false, 'account columns: all four rh_pay_row_state keys');
check(strpos($cols, rh_receivable_status_sql('room', 'b')) !== false && strpos($cols, rh_receivable_status_sql('gym', 'gi')) !== false
    && strpos($cols, rh_unconfirmed_status_sql('event', 'ei')) !== false, 'account columns: the shared receivables + not-confirmed rules');
check(strpos($cols, "WHEN b.deleted_at IS NOT NULL THEN 'deleted'") !== false && strpos($cols, 'so.') === false, 'account columns: deleted booking named, till order has no bill');
$joins = rh_pay_row_account_joins_sql();
check(strpos($joins, 'LEFT JOIN gym_inquiries gi') !== false && strpos($joins, 'LEFT JOIN event_inquiries ei') !== false && strpos($joins, 'stock_orders') === false, 'account joins: b, ci, gi, ei only');
$pd = (string)file_get_contents($root . '/admin/payment-details.php');
check(strpos($pd, 'rh_pay_row_account_cols_sql()') !== false && strpos($pd, 'rh_pay_row_state(') !== false, 'payment details: status from rh_pay_row_state with the account columns');
check(strpos($pd, "\$pd_pill((string)\$payment['payment_status'])") === false && strpos($pd, "\$pd_pill((string)\$otherPayment['payment_status'])") === false
    && strpos($pd, "ucfirst(str_replace('_', ' ', (string)\$payment['payment_status']))") === false, 'payment details: no raw payment_status words (header, Status row, other payments)');
check(strpos($pd, "\$pd_pill((string)(\$r['refund_status']") === false, 'payment details: refunds-against rows read their refund step, never "Completed"');
$ad = (string)file_get_contents($root . '/admin/accounting-dashboard.php');
check(strpos($ad, '$pstState = rh_pay_row_state($payment, (float)BALANCE_TOLERANCE') !== false && strpos($ad, "ucfirst(str_replace('_', ' ', \$pst))") === false, 'accounting: Recent payments status via rh_pay_row_state');
$bh = (string)file_get_contents($root . '/includes/booking-history.php');
check(strpos($bh, "rh_hist_words((string)(\$p['payment_status']") === false && strpos($bh, 'rh_pay_row_state(') !== false, 'history: Status now via rh_pay_row_state');
check(strpos($rep, "'completed' => 'Completed'") === false && strpos($rep, 'rh_refund_status_label((string)$status[\'refund_status\'])') !== false, 'reports: status mix + refunds by status never say "Completed"');
$rx = (string)file_get_contents($root . '/api/reports-export.php');
check(strpos($rx, "CASE WHEN payment_type = 'refund' THEN 'refund' ELSE 'payment' END AS row_kind") !== false && substr_count($rx, "ucfirst(\$row['payment_status'])") === 1, 'export: status breakdown splits refunds out, words via rh_pay_row_state');
check(strpos($rx, "CASE WHEN payment_method = 'credit_note' THEN 'credit_note' ELSE '' END AS payment_method") !== false, 'export: credit-note redemptions get their own line');
$mixSrc = (string)file_get_contents($root . '/admin/includes/reports-status-mix.php');
check(strpos($rep, "THEN 'credit_note' ELSE payment_status END AS payment_status") !== false && strpos($mixSrc, "'completed', 'credit_note', 'partially_refunded', 'refunded', 'failed'") !== false, 'reports: status mix gives redemptions, part refunded and failed their own lines (reports-status-mix.php)');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
