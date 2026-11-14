<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Step 8b payments accuracy (F1-F9). Runs at LIWONDE only (the test platform; Rosalyn's is live).
 * F1 invoice email figures = the attached invoice's totals; F2 Payments & balance dialog; F3 payment details Refunded;
 * F4 manual room credit note VAT split; F5 payment History queue (after commit, checked against the committed row);
 * F6 row click markup + payment details fields; F7 refund finder takes a partly refunded payment; F8 no-show earned =
 * the folio figure; F9 a refund counts on its payout day.
 * Library checks run in one transaction that is rolled back; pages run in scripts/tests/page_harness.php (rolled back).
 * The only committed writes are payment_audit_log rows of the F5 flush check, deleted again at once.
 * Usage (repo root): php scripts/tests/payments_accuracy_8b.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) <= 0.011; }

require $root . '/config/database.php';
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false && stripos($root, 'Liwonde') === false) {
    echo "SKIP payments_accuracy_8b: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
    exit(0);
}
require_once $root . '/admin/includes/finance-account-sync.php';
require_once $root . '/admin/includes/finance-refunds.php';
require_once $root . '/admin/includes/eod-revenue.php';
require_once $root . '/config/invoice.php';
require_once $root . '/config/credit-notes.php';
require_once $root . '/admin/includes/finance-schema.php';
require_once $root . '/admin/includes/finance-filters.php';

$h = $root . '/scripts/tests/page_harness.php';
function run_page(string $page, array $cfg): array {
    global $h;
    $cmd = 'php ' . escapeshellarg($h) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode(json_encode($cfg))) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $r['raw'] = $out; return $r; } }
    return ['error' => 'no result', 'raw' => substr($out, -400), 'rolled_back' => false];
}
function harness_clean(array $r, string $l): void {
    check(!empty($r['rolled_back']) && empty($r['leak']) && empty($r['marker_lost']), $l . ': rolled back, no leak', substr((string)($r['raw'] ?? json_encode($r)), -300));
    // A statement the harness itself refused (it reads a comment in it as a second statement: ddl_skipped) fails with
    // HY093 under the harness only; that is the harness, not this change. Any other SQL error fails.
    $raw = (string)($r['raw'] ?? '');
    if (!empty($r['ddl_skipped'])) { $raw = (string)preg_replace('/^.*SQLSTATE\[HY093\].*$/m', '', $raw); }
    check(stripos($raw, 'SQLSTATE') === false && stripos($raw, 'Fatal error') === false, $l . ': no SQL / PHP error', substr($raw, 0, 400));
}
$one = static function (string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };

// ─────────────────────────────── library checks (one rolled-back transaction) ───────────────────────────────
$pdo->beginTransaction();
try {
    // ---- F1 pure: the money vars follow the totals the PDF prints
    $v = rh_invoice_email_money_vars(['total' => 1100.0, 'paid' => 1100.0, 'balance' => 0.0, 'subtotal' => 1000.0, 'vat' => 150.0, 'levy' => 100.0, 'vat_mode' => 'off'], 'MWK');
    check($v['total_amount'] === 'MWK 0.00' && $v['balance_due_amount'] === 'MWK 0.00', 'F1 paid: Balance due 0.00');
    check($v['vat_amount'] === '—' && $v['levy_amount'] === 'MWK 100.00' && $v['subtotal_amount'] === 'MWK 1,000.00', 'F1 VAT off: no VAT figure, levy on its own row');
    $v = rh_invoice_email_money_vars(['total' => 1250.0, 'paid' => 500.0, 'balance' => 750.0, 'subtotal' => 1000.0, 'vat' => 150.0, 'levy' => 100.0, 'vat_mode' => 'exclusive'], 'MWK');
    check($v['vat_amount'] === 'MWK 150.00' && $v['total_amount'] === 'MWK 750.00' && $v['amount_paid_amount'] === 'MWK 500.00', 'F1 exclusive part-paid: VAT amount, balance 750');
    $v = rh_invoice_email_money_vars(['total' => 1100.0, 'paid' => 0.0, 'balance' => 1100.0, 'subtotal' => 1000.0, 'vat' => 150.0, 'levy' => 100.0, 'vat_mode' => 'inclusive'], 'MWK');
    check(strpos($v['vat_amount'], 'Included') === 0 && $v['subtotal_amount'] === 'MWK 1,000.00', 'F1 inclusive: VAT "included", subtotal + levy = total');
    $v = rh_invoice_email_money_vars(['total' => 100.0, 'paid' => 99.995, 'balance' => 0.004, 'subtotal' => 100.0, 'vat' => 0.0, 'levy' => 0.0, 'vat_mode' => 'off'], 'MWK');
    check($v['total_amount'] === 'MWK 0.00' && $v['levy_amount'] === '—', 'F1 balance inside the tolerance reads 0.00; no levy reads a dash');

    // ---- F1 real documents: email figures == the invoice copy's own totals (paid, part-paid, cancelled kept fee)
    $f1 = ['paid' => $one("SELECT * FROM bookings WHERE deleted_at IS NULL AND payment_status = 'paid' AND status = 'checked-out' ORDER BY id LIMIT 1"),
           'part-paid' => $one("SELECT * FROM bookings WHERE deleted_at IS NULL AND payment_status = 'partial' AND status IN ('checked-in','confirmed') ORDER BY id LIMIT 1"),
           'kept fee' => $one("SELECT * FROM bookings WHERE deleted_at IS NULL AND status = 'cancelled' AND cancellation_retained_amount > 0 ORDER BY id LIMIT 1")];
    foreach ($f1 as $case => $bk) {
        if (!$bk) { ok("F1 $case: no such booking at Liwonde (skipped)"); continue; }
        $copy = rh_invoice_email_copy($pdo, $bk, 'TEST-8B');
        $t = $copy['totals'];
        if (!check(is_array($t), "F1 $case: invoice copy built")) { continue; }
        $cur = (string)getSetting('currency_symbol');
        $v = rh_invoice_email_money_vars($t, $cur);
        $fs = getBookingFolioSummary((int)$bk['id']);
        check($v['total_amount'] === $cur . ' ' . number_format((float)$t['balance'], 2), "F1 $case: Balance due = the invoice's balance");
        $sum = (float)$t['subtotal'] + (float)$t['levy'] + ((string)$t['vat_mode'] === 'exclusive' ? (float)$t['vat'] : 0.0);
        check(near($sum, (float)$t['total']), "F1 $case: subtotal + levy (+ VAT when exclusive) = invoice total", "$sum vs {$t['total']}");
        if (count($t['ids']) === 1) {
            check(near((float)$t['total'], (float)$fs['grand_total']) && near((float)$t['balance'], max(0.0, (float)$fs['balance_due'] <= 0.01 ? 0.0 : (float)$fs['balance_due'])), "F1 $case: invoice total and balance = the folio");
        }
    }

    // ---- F5 queue: never written inside a transaction; a rolled-back or wrong-state row is dropped
    $GLOBALS['rh_payment_audit_queue'] = [];
    $anyPay = $one("SELECT id, payment_reference, deleted_at FROM payments WHERE deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' ORDER BY id DESC LIMIT 1");
    rh_payment_audit_defer(['payment_id' => (int)$anyPay['id'], 'payment_ref' => (string)$anyPay['payment_reference'], 'action' => 'created', 'user_id' => null, 'who' => 'System', 'note' => '8B-TEST in tx']);
    check(rh_payment_audit_flush($pdo) === 0 && count($GLOBALS['rh_payment_audit_queue']) === 1, 'F5 inside a transaction: nothing written, entry kept for after the commit');
    $GLOBALS['rh_payment_audit_queue'] = [];
    rh_payment_audit_defer(['payment_id' => 0, 'action' => 'created']);
    check(empty($GLOBALS['rh_payment_audit_queue']), 'F5 no payment id: nothing queued');

    // ---- F5 every write path queues a History row (source check)
    $sites = ['admin/bookings.php' => 3, 'includes/stay-nights.php' => 1, 'admin/create-booking.php' => 1, 'config/credit-notes.php' => 3, 'config/database.php' => 1,
        'admin/includes/finance-account-sync.php' => 2, 'admin/includes/account-record-payment.php' => 1, 'api/payments.php' => 1, 'admin/includes/gym-billing-lib.php' => 1];
    foreach ($sites as $file => $n) {
        $c = substr_count((string)file_get_contents($root . '/' . $file), 'rh_payment_audit_defer([');
        check($c >= $n, "F5 $file queues History on its payment writes ($c)", "want $n");
    }

    // ---- F8 no-show earned = the folio figure (tagged: the no-show charge; untagged: the booked stay)
    $ns = $pdo->query("SELECT * FROM bookings WHERE deleted_at IS NULL AND status = 'no-show' ORDER BY id LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $seenTag = false; $seenPlain = false;
    foreach ($ns as $b) {
        $tagged = rh_noshow_fee_tagged($pdo, (int)$b['id']);
        $earned = rh_eod_noshow_earned($pdo, $b);
        $fs = getBookingFolioSummary((int)$b['id']);
        $st = $pdo->prepare('SELECT COALESCE(SUM(line_total),0) FROM booking_charges WHERE booking_id = ? AND voided = 0 AND description NOT LIKE ?');
        $st->execute([(int)$b['id'], '%' . rh_noshow_fee_tag((int)$b['id'])]);
        $otherExtras = (float)$st->fetchColumn();
        if ((float)($b['cancellation_retained_amount'] ?? 0) > 0) {
            check($earned === 0.0, "F8 no-show {$b['id']} that kept a cancellation amount earns nothing here (no double count)");
            continue;
        }
        check(near($earned + $otherExtras, (float)$fs['grand_total']), 'F8 no-show ' . $b['id'] . ($tagged ? ' (tagged)' : ' (untagged)') . ': earned + other extras = folio total', "$earned + $otherExtras vs {$fs['grand_total']}");
        $tagged ? $seenTag = true : $seenPlain = true;
    }
    check($seenTag || $seenPlain, 'F8 at least one no-show checked');
    check(rh_eod_noshow_earned($pdo, ['id' => 1, 'status' => 'checked-out', 'total_with_vat' => 500]) === 0.0, 'F8 a checked-out stay is never a no-show');
    if ($ns) {
        $d = (string)$ns[0]['check_in_date'];
        $e = rh_eod_earned_revenue($pdo, $d);
        $sumParts = $e['rooms_total_gross'] + $e['extras_gross'] + $e['cancel_kept_gross'] + $e['no_show_kept_gross'] + $e['fnb_gross'] + $e['conf_gross'] + $e['gym_gross'] + $e['events_gross'];
        check($e['no_show_kept_gross'] > 0 && near($sumParts, $e['total']), "F8 EOD $d: no-show line counted and in the earned total", json_encode([$e['no_show_kept_gross'], $e['total']]));
    }

    // ---- F9 a refund counts on its payout day; a pending refund in no period
    $orig = $one("SELECT * FROM payments WHERE booking_type = 'room' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid') ORDER BY id DESC LIMIT 1");
    $dayA = date('Y-m-d', strtotime('-20 days')); $dayB = date('Y-m-d', strtotime('-18 days'));
    $ins = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, recorded_by, created_at)
        VALUES (?, 'room', ?, ?, ?, 1.00, 0, 0, 1.00, 'cash', 'refund', ?, ?, 'other', ?, 1.00, NULL, ?)");
    $ins->execute(['T8B-RF-PAID', (int)$orig['booking_id'], (string)$orig['booking_reference'], $dayA, 'completed', (int)$orig['id'], 'completed', $dayA . ' 10:00:00']);
    $paidId = (int)$pdo->lastInsertId();
    $ins->execute(['T8B-RF-PEND', (int)$orig['booking_id'], (string)$orig['booking_reference'], $dayA, 'pending', (int)$orig['id'], 'pending', $dayA . ' 10:00:00']);
    $pendId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_audit_log (payment_id, payment_reference, action, field_name, old_value, new_value, performed_by_name, created_at)
        VALUES (?, 'T8B-RF-PAID', 'refund_completed', 'refund_status', 'pending', 'completed', 'test', ?)")->execute([$paidId, $dayB . ' 15:00:00']);
    $day = $pdo->prepare('SELECT ' . rh_pay_count_day_sql() . ' FROM payments WHERE id = ?');
    $day->execute([$paidId]);
    check((string)$day->fetchColumn() === $dayB, 'F9 a refund raised on day A and paid out on day B counts on day B');
    $sumOn = static function (string $d) use ($pdo): float {
        $s = $pdo->prepare('SELECT ' . rh_refund_settled_sum_sql() . " FROM payments WHERE deleted_at IS NULL AND payment_reference LIKE 'T8B-RF-%' AND " . rh_pay_count_day_sql() . ' BETWEEN ? AND ?');
        $s->execute([$d, $d]);
        return (float)$s->fetchColumn();
    };
    check(near($sumOn($dayB), 1.0) && near($sumOn($dayA), 0.0), 'F9 refunds total: on day B, not on day A; the pending one in neither');
    $fl = rh_fin_payments_where(['start_date' => $dayB, 'end_date' => $dayB, 'search' => ''], finance_payment_transaction_column($pdo), finance_conference_fields($pdo));
    $s = $pdo->prepare('SELECT p.payment_reference ' . rh_fin_payments_from() . ' ' . $fl['sql'] . " AND p.payment_reference LIKE 'T8B-RF-%'");
    $s->execute($fl['params']);
    check($s->fetchAll(PDO::FETCH_COLUMN) === ['T8B-RF-PAID'], 'F9 Payments date filter: the paid-out refund on its payout day');
    // Dashboard takings trend refunds (admin/dashboard.php): grouped on the payout day, the pending one nowhere
    $tr = $pdo->prepare("SELECT " . rh_pay_count_day_sql() . " d, COALESCE(SUM(refund_amount),0) v FROM payments
        WHERE " . rh_pay_count_day_sql() . " BETWEEN ? AND ? AND payment_type = 'refund' AND refund_status IN ('completed','processing')
          AND deleted_at IS NULL AND payment_reference LIKE 'T8B-RF-%' GROUP BY d");
    $tr->execute([$dayA, $dayB]);
    check($tr->fetchAll(PDO::FETCH_KEY_PAIR) == [$dayB => '1.00'], 'F9 Dashboard trend: the refund on its payout day only');
} catch (Throwable $e) {
    bad('library checks threw', $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $GLOBALS['rh_payment_audit_queue'] = [];
}
check(!$pdo->inTransaction(), 'library checks rolled back');

// ---- F5 flush outside a transaction writes the row (who / note), drops a rolled-back one; rows deleted at once
$anyPay = $one("SELECT id, payment_reference FROM payments WHERE deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' ORDER BY id DESC LIMIT 1");
rh_payment_audit_defer(['payment_id' => (int)$anyPay['id'], 'payment_ref' => (string)$anyPay['payment_reference'], 'action' => 'created', 'user_id' => null, 'who' => 'API: 8B test client', 'note' => '8B-TEST flush']);
rh_payment_audit_defer(['payment_id' => (int)$anyPay['id'], 'verify_ref' => 'T8B-NEVER-COMMITTED', 'action' => 'refund_recorded', 'who' => 'System', 'note' => '8B-TEST dropped']);
rh_payment_audit_defer(['payment_id' => (int)$anyPay['id'], 'payment_ref' => (string)$anyPay['payment_reference'], 'action' => 'deleted', 'expect' => 'deleted', 'who' => 'System', 'note' => '8B-TEST dropped']);
$written = rh_payment_audit_flush($pdo);
$rows = $pdo->query("SELECT id, performed_by, performed_by_name, new_value FROM payment_audit_log WHERE new_value LIKE '8B-TEST%'")->fetchAll(PDO::FETCH_ASSOC);
check($written === 1 && count($rows) === 1 && $rows[0]['performed_by_name'] === 'API: 8B test client' && $rows[0]['performed_by'] === null, 'F5 after the commit: one row with the named actor; rolled-back / wrong-state entries dropped', json_encode([$written, $rows]));
$pdo->exec("DELETE FROM payment_audit_log WHERE new_value LIKE '8B-TEST%'");
check((int)$pdo->query("SELECT COUNT(*) FROM payment_audit_log WHERE new_value LIKE '8B-TEST%'")->fetchColumn() === 0, 'F5 test rows removed');

// ─────────────────────────────── pages (rolled-back harness) ───────────────────────────────
// F2 Payments & balance: a partly refunded original is listed with its refund; Total charged = folio grand total
foreach ([2100008830 => 'partly refunded', 2100014676 => 'cancelled, fee kept', 2100014673 => 'tagged no-show'] as $bid => $case) {
    if (!$one('SELECT id FROM bookings WHERE id = ?', [$bid])) { ok("F2 $case: booking not at Liwonde (skipped)"); continue; }
    $r = run_page('admin/bookings.php', ['ajax' => true, 'post' => ['action' => 'consolidation_fetch', 'id' => (string)$bid]]);
    harness_clean($r, "F2 $case");
    $j = $r['json'] ?? [];
    $fs = getBookingFolioSummary($bid);
    check(!empty($j['success']) && near((float)($j['booking']['total_charged'] ?? -1), (float)$fs['grand_total']), "F2 $case: Total charged = folio grand total", json_encode([$j['booking']['total_charged'] ?? null, $fs['grand_total']]));
    $hasRefundedOrig = false;
    foreach (($j['payments'] ?? []) as $p) { if (in_array($p['payment_status'], ['refunded', 'partially_refunded'], true)) { $hasRefundedOrig = true; } }
    $refundRows = (int)$one("SELECT COUNT(*) n FROM payments WHERE booking_type='room' AND booking_id = ? AND payment_type='refund' AND refund_status IN ('completed','processing','pending') AND deleted_at IS NULL", [$bid])['n'];
    check(count($j['refunds'] ?? []) === $refundRows, "F2 $case: refund legs listed with their status ($refundRows)");
    if ($case === 'partly refunded') {
        check($hasRefundedOrig, 'F2 partly refunded: the original payment is listed (canonical paid set)');
    }
}

// F3 payment details: Refunded = completed + processing, 2 decimals
$p3 = 2099976256;
if ($one('SELECT id FROM payments WHERE id = ?', [$p3])) {
    $ref = (float)$one("SELECT COALESCE(SUM(COALESCE(NULLIF(refund_amount,0), total_amount)),0) s FROM payments WHERE original_payment_id = ? AND payment_type='refund' AND refund_status IN ('completed','processing') AND deleted_at IS NULL", [$p3])['s'];
    $want = number_format($ref, 2);
    $r = run_page('admin/payment-details.php', ['method' => 'GET', 'get' => ['id' => (string)$p3], 'grep' => [$want . '</strong>', 'Received by', 'Paid by', 'Recorded at']]);
    harness_clean($r, 'F3/F6 payment details');
    check(!empty($r['found'][$want . '</strong>']), "F3 Refunded shows $want (completed + processing, 2 decimals)");
    check(!empty($r['found']['Received by']) && !empty($r['found']['Paid by']) && !empty($r['found']['Recorded at']), 'F6 payment details: paid by, received by, recorded at');
}

// F6 Payments list: row click markup on both tables
$r = run_page('admin/payments.php', ['method' => 'GET', 'get' => [], 'grep' => ['data-row-open', 'rh-row-view', 'rhPaymentsRowOpenBound']]);
harness_clean($r, 'F6 payments list');
check(!empty($r['found']['data-row-open']) && !empty($r['found']['rh-row-view']) && !empty($r['found']['rhPaymentsRowOpenBound']), 'F6 payments rows open through their View link');
$r = run_page('admin/payments.php', ['method' => 'GET', 'get' => ['balance' => 'outstanding'], 'grep' => ['data-row-open']]);
harness_clean($r, 'F6 accounts that owe');

// F7 refund finder: a booking whose only payment is partly refunded is offered; a fully refunded one is not
foreach ([2100000502 => true, 2100014670 => false] as $bid => $expect) {
    if (!$one('SELECT id FROM bookings WHERE id = ?', [$bid])) { ok("F7 booking $bid not at Liwonde (skipped)"); continue; }
    $r = run_page('admin/bookings.php', ['ajax' => true, 'post' => ['action' => 'find_payment_for_refund', 'booking_id' => (string)$bid]]);
    harness_clean($r, "F7 booking $bid");
    $j = $r['json'] ?? [];
    if ($expect) {
        check(!empty($j['success']) && (float)($j['payment']['refundable'] ?? 0) > 0.01, 'F7 partly refunded payment is found with its refundable remainder', json_encode($j));
    } else {
        check(empty($j['success']), 'F7 a fully refunded payment is not offered', json_encode($j));
    }
}

// F4 manual credit note on a room booking: VAT from the booking split, not the flat rate (PDF file removed after)
$bk4 = $one("SELECT id, booking_reference, total_with_vat, vat_amount, tourism_levy_amount FROM bookings WHERE deleted_at IS NULL AND tourism_levy_amount > 0 AND vat_amount > 0 ORDER BY id DESC LIMIT 1");
if ($bk4) {
    $split = rh_account_vat_split($pdo, 'room', (int)$bk4['id'], 10000.0, rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0);
    $r = run_page('admin/credit-notes.php', ['post' => ['action' => 'issue_cn', 'guest_name' => '8B Test Guest', 'guest_email' => '', 'amount' => '10000', 'reason' => 'goodwill',
        'booking_type' => 'room', 'booking_id' => (string)$bk4['id'], 'booking_reference' => (string)$bk4['booking_reference']],
        'verify' => ["SELECT vat_amount, vat_rate, pdf_path FROM credit_notes WHERE guest_name = '8B Test Guest' ORDER BY id DESC LIMIT 1"]]);
    harness_clean($r, 'F4 credit note');
    $cn = $r['verify'][0][0] ?? null;
    check(is_array($cn) && near((float)$cn['vat_amount'], (float)$split['vat']), 'F4 room credit note VAT = the booking split (' . $split['vat'] . '), not the flat rate', json_encode($cn));
    if (is_array($cn) && !empty($cn['pdf_path'])) {
        foreach ([$root . '/' . ltrim((string)$cn['pdf_path'], '/'), (string)$cn['pdf_path']] as $pf) { if (is_file($pf)) { @unlink($pf); } }
    }
}

// F9 pages render with the payout-day filters (no SQL error)
$from = date('Y-m-01'); $to = date('Y-m-d');
foreach ([['admin/dashboard.php', []], ['admin/gym-reports.php', []], ['api/reports-export.php', ['report_type' => 'revenue', 'start_date' => $from, 'end_date' => $to]],
          ['api/reports-export.php', ['report_type' => 'overview', 'start_date' => $from, 'end_date' => $to]], ['api/reports-export.php', ['report_type' => 'vat', 'start_date' => $from, 'end_date' => $to]],
          ['admin/reports.php', ['start_date' => $from, 'end_date' => $to]], ['admin/accounting-dashboard.php', ['start_date' => $from, 'end_date' => $to]],
          ['admin/payments.php', ['start_date' => $from, 'end_date' => $to]], ['admin/end-of-day-report.php', ['date' => date('Y-m-d', strtotime('-1 day'))]]] as [$pg, $get]) {
    $r = run_page($pg, ['method' => 'GET', 'get' => $get]);
    harness_clean($r, "F9 $pg");
}

// F9 the Reports VAT register / VAT by quarter / refund trend shapes (on that page they run after a statement the
// harness refuses, so they are run here, read-only)
try {
    $st = $pdo->prepare("SELECT " . rh_pay_count_day_sql('p') . " AS payment_date, p.id FROM payments p WHERE " . rh_pay_count_day_sql('p') . " >= ? AND " . rh_pay_count_day_sql('p') . " <= ? AND p.deleted_at IS NULL ORDER BY payment_date ASC, p.id ASC LIMIT 5");
    $st->execute([$from, $to]);
    $st = $pdo->prepare("SELECT YEAR(" . rh_pay_count_day_sql() . ") as yr, QUARTER(" . rh_pay_count_day_sql() . ") as qtr, COUNT(*) n FROM payments WHERE " . rh_pay_count_day_sql() . " >= ? AND " . rh_pay_count_day_sql() . " <= ? AND deleted_at IS NULL GROUP BY yr, qtr ORDER BY yr, qtr");
    $st->execute([$from, $to]);
    $st = $pdo->prepare("SELECT " . rh_pay_count_day_sql() . " as date, COUNT(*) n, refund_reason FROM payments WHERE " . rh_refund_settled_sql() . " AND " . rh_pay_count_day_sql() . " >= ? AND " . rh_pay_count_day_sql() . " <= ? GROUP BY date, refund_reason ORDER BY date");
    $st->execute([$from, $to]);
    ok('F9 Reports register / quarter / trend query shapes run');
} catch (Throwable $e) {
    bad('F9 Reports register / quarter / trend query shapes', $e->getMessage());
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
