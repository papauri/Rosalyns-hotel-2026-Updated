<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Reports audit (owner 2026-11-18): the Payment Status Mix gives EVERY status a line (part refunded, failed, any new
 * one), so the lines add up to the total; Reports money received follows the End of Day cash rule (a payment made WITH
 * a credit note is not money received); the Overview CSV status breakdown uses the report period.
 * 1. pure helper cases (no database); 2. page / export source checks; 3. the page's and export's own SQL (read from the
 * source) run at LIWONDE only (the test platform; Rosalyn's is live) on far-past fixture rows inside ONE transaction
 * that is rolled back. Nothing is committed.
 * Usage (repo root): php scripts/tests/reports_status_mix_test.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) <= 0.011; }

require_once $root . '/admin/includes/reports-status-mix.php';
$tol = 0.01;

// ---- 1. pure helper
$rows = [
    ['payment_status' => 'pending', 'count' => 2, 'total_amount' => '40.00'],
    ['payment_status' => 'completed', 'count' => 3, 'total_amount' => '100.00'],
    ['payment_status' => 'paid', 'count' => 1, 'total_amount' => '10.00'],
    ['payment_status' => 'credit_note', 'count' => 1, 'total_amount' => '70.00'],
    ['payment_status' => 'partially_refunded', 'count' => 2, 'total_amount' => '2251500.00'],
    ['payment_status' => 'refunded', 'count' => 1, 'total_amount' => '50.00'],
    ['payment_status' => 'failed', 'count' => 1, 'total_amount' => '30.00'],
    ['payment_status' => 'cancelled', 'count' => 1, 'total_amount' => '5.00'],
    ['payment_status' => 'on_hold', 'count' => 1, 'total_amount' => '1.00'],
];
$mix = rh_reports_status_mix($rows, $tol);
$labels = array_column($mix['lines'], 'label', 'key');
check(count($mix['lines']) === 8 + 1 - 1, 'one line per status present ("paid" shares the Received line)', json_encode(array_keys($labels)));
check(($labels['partially_refunded'] ?? '') === 'Received, part refunded', 'part refunded has its line, Payments list words');
check(($labels['failed'] ?? '') === 'Failed', 'failed has its line');
check(($labels['credit_note'] ?? '') === 'Paid from credit note', 'credit-note payments read "Paid from credit note"');
check(($labels['completed'] ?? '') === 'Received', 'completed + paid read "Received"');
check(($labels['on_hold'] ?? '') !== '', 'an unknown status still gets a line, never skipped');
check(array_keys($labels)[count($labels) - 1] === 'on_hold', 'unknown statuses come after the known ones');
$sumCount = array_sum(array_column($mix['lines'], 'count'));
$sumTotal = array_sum(array_column($mix['lines'], 'total'));
check($sumCount === $mix['count'] && $mix['count'] === 13, 'line counts add up to the total count', "$sumCount / {$mix['count']}");
check(near($sumTotal, $mix['total']) && near($mix['total'], 2251806.00), 'line amounts add up to the total amount', "$sumTotal / {$mix['total']}");
check(near($mix['received_total'], 110 + 2251500 + 50), 'received total = Received + part refunded + refunded (no credit note, pending, failed)', (string)$mix['received_total']);
$pills = array_column($mix['lines'], 'pill', 'key');
check($pills['partially_refunded'] === 'refunded' && $pills['failed'] === 'danger' && $pills['on_hold'] === '', 'pills use existing colour classes only');
$empty = rh_reports_status_mix([], $tol);
check($empty['lines'] === [] && $empty['count'] === 0 && $empty['total'] === 0.0, 'no rows: no lines, zero total');

// ---- 2. source checks
$rep = (string)file_get_contents($root . '/admin/reports.php');
$exp = (string)file_get_contents($root . '/api/reports-export.php');
$grab = static function (string $src, string $re): string { return preg_match($re, $src, $m) ? $m[1] : ''; };
$sqlRevType = $grab($rep, '/\$revenueByTypeStmt = \$pdo->prepare\((".*?")\);/s');
$sqlStatus = $grab($rep, '/\$statusStmt = \$pdo->prepare\((".*?")\);/s');
$sqlMethods = $grab($rep, '/\$paymentMethodsStmt = \$pdo->prepare\((".*?")\);/s');
$sqlDaily = $grab($rep, '/\$dailyRevenueStmt = \$pdo->prepare\((".*?")\);/s');
$sqlPrior = $grab($rep, '/\$priorRevStmt = \$pdo->prepare\((".*?")\);/s');
$sqlMonthly = $grab($rep, '/\$monthlyRevenueStmt = \$pdo->prepare\((".*?")\);/s');
$sqlVatDay = $grab($rep, '/\$vatCollectedStmt = \$pdo->prepare\((".*?")\);/s');
foreach (['revenue by type (Gross Revenue)' => $sqlRevType, 'payment methods' => $sqlMethods, 'daily trend' => $sqlDaily, 'prior period' => $sqlPrior, 'monthly' => $sqlMonthly, 'VAT collection revenue' => $sqlVatDay] as $n => $s) {
    check($s !== '' && strpos($s, '{$rhCashRow}') !== false, "Reports $n leaves credit-note payments out (cash-row rule)");
}
check(strpos($rep, '$statusLabels') === false && strpos($rep, '$statusMix[\'lines\']') !== false, 'Reports mix renders from the helper lines (no fixed status list)');
check(strpos($rep, 'if ($pct < 1) continue;') === false, 'Reports mix bar no longer drops small slices');
$sqlExpStatus = $grab($exp, '/\$statusQuery = (".*?");/s');
check($sqlExpStatus !== '' && strpos($sqlExpStatus, '$date_filter') !== false, 'export status breakdown uses the report period');
check(preg_match('/\$statusStmt = \$pdo->prepare\(\$statusQuery\);\s*\$statusStmt->execute\(\[\$start_date, \$end_date\]\);/', $exp) === 1, 'export status query is prepared with the period');
check(substr_count($exp, "fputcsv(\$output, ['Status', 'Count', 'Total Amount']);") === 1, 'export status columns unchanged');
check(strpos($exp, '$exportMix = rh_reports_status_mix(') !== false && strpos($exp, "fputcsv(\$output, ['Total', \$exportMix['count'], ") !== false, 'export status: payment lines + Total from the page helper (refund rows outside the Total)');
check(strpos($rep, '$netRevenue     = $grossRevenue - $totalRefunds - $totalVatCounted;') !== false, 'Net Revenue takes off only the VAT on payments Gross counts');
$sqlExpMethods = $grab($exp, '/\$methodsQuery = (".*?");/s');
check($sqlExpMethods !== '' && strpos($sqlExpMethods, "COALESCE(payment_method, '') <> 'credit_note'") !== false, 'export methods leave credit-note rows out (End of Day rule)');

// ---- 3. the page's and export's own SQL on fixture rows (Liwonde only, rolled back)
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require $root . '/config/database.php';
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false && stripos($root, 'Liwonde') === false) {
    echo "SKIP DB part: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
} else {
    require_once $root . '/admin/includes/eod-revenue.php';
    $rhCashRow = rh_eod_cash_row_sql();
    $D1 = '2001-04-10'; $D2 = '2001-04-20';
    $pre = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE DATE(payment_date) IN (?, ?)");
    $pre->execute([$D1, $D2]);
    if ((int)$pre->fetchColumn() > 0) {
        bad('fixture days are empty', 'real rows on the far-past fixture days; not running');
    } else {
        $pdo->beginTransaction();
        try {
            $orig = $pdo->query("SELECT id, booking_id, booking_reference FROM payments WHERE booking_type = 'room' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, recorded_by, created_at)
                VALUES (?, 'room', ?, ?, ?, ?, 10, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)");
            $fx = [ // ref, day, amount, method, type, status, refund_status
                ['TRSM-OK', $D1, 100, 'cash', 'full_payment', 'completed', null],
                ['TRSM-PR', $D1, 200, 'bank_transfer', 'full_payment', 'partially_refunded', null],
                ['TRSM-RF', $D1, 50, 'cash', 'full_payment', 'refunded', null],
                ['TRSM-FA', $D1, 30, 'cash', 'full_payment', 'failed', null],
                ['TRSM-PE', $D1, 40, 'cash', 'full_payment', 'pending', null],
                ['TRSM-CN', $D1, 70, 'credit_note', 'full_payment', 'completed', null],
                ['TRSM-TOCN', $D1, 20, 'credit_note', 'refund', 'completed', 'completed'],
                ['TRSM-LATER', $D2, 999, 'cash', 'full_payment', 'completed', null],
            ];
            foreach ($fx as [$ref, $day, $amt, $meth, $type, $st, $rs]) {
                $isRf = $type === 'refund';
                // VAT on every fixture row = 10% of its amount (the exact split does not matter, only which rows count)
                $ins->execute([$ref, (int)$orig['booking_id'], (string)$orig['booking_reference'], $day, $amt, round($amt * 0.1, 2), $amt, $meth, $type, $st,
                    $isRf ? (int)$orig['id'] : null, $isRf ? 'other' : null, $rs, $isRf ? $amt : null, $day . ' 10:00:00']);
            }
            $run = static function (string $expr, array $params) use ($pdo, $rhCashRow, $D1) {
                $date_filter = "AND " . rh_pay_count_day_sql() . " >= ? AND " . rh_pay_count_day_sql() . " <= ?";
                $cashRow = $rhCashRow; // the export's name for the same rule
                $sql = eval('return ' . $expr . ';');
                $st = $pdo->prepare($sql);
                $st->execute($params);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            };
            // Page mix (refund rows left out): every status a line, lines = total
            $pm = rh_reports_status_mix($run($sqlStatus, [$D1, $D1]), (float)BALANCE_TOLERANCE);
            $pl = array_column($pm['lines'], 'total', 'label');
            check(isset($pl['Received, part refunded']) && near((float)$pl['Received, part refunded'], 200), 'page mix: part-refunded payment on its own line');
            check(isset($pl['Failed']) && near((float)$pl['Failed'], 30), 'page mix: failed payment on its own line');
            check(isset($pl['Paid from credit note']) && near((float)$pl['Paid from credit note'], 70), 'page mix: credit-note payment on its own line');
            check($pm['count'] === 6 && near($pm['total'], 490), 'page mix: 6 payments, lines add up to 490', json_encode([$pm['count'], $pm['total']]));
            // Gross Revenue: the credit-note payment is not money received
            $gross = 0.0; $txns = 0;
            foreach ($run($sqlRevType, [$D1, $D1]) as $r) { $gross += (float)$r['total_revenue']; $txns += (int)$r['count']; }
            check(near($gross, 350) && $txns === 3, 'Gross Revenue = 350 (100 + 200 + 50), the 70 paid from a credit note left out', "$gross / $txns");
            check(near($gross, $pm['received_total']), 'Gross Revenue = the mix\'s three Received lines');
            // Net Revenue VAT: only the VAT on payments Gross counts (35) less refund VAT (2) = 33; the 7 on the credit-note payment stays out
            $vAll = 0.0; $vCounted = 0.0;
            foreach ($run($sqlRevType, [$D1, $D1]) as $r) { $vAll += (float)$r['total_vat']; $vCounted += (float)$r['total_vat_counted']; }
            check(near($vAll, 40) && near($vCounted, 33) && near($vAll - $vCounted, 7), 'VAT: collected 40 = counted 33 + credit-note 7', json_encode([$vAll, $vCounted]));
            check(near($gross - $vCounted, 317), 'Net Revenue (before refunds) = 350 - 33, not 350 - 40');
            $vd = $run($sqlVatDay, [$D1, $D1]);
            $vdC = array_sum(array_map('floatval', array_column($vd, 'vat_counted')));
            $vdN = array_sum(array_map('floatval', array_column($vd, 'vat_credit_note')));
            $vdR = array_sum(array_map('floatval', array_column($vd, 'total_revenue')));
            check(near($vdC, 33) && near($vdN, 7) && near($vdR, 350), 'VAT Collection rows: counted 33 beside revenue 350, credit-note VAT 7 apart', json_encode([$vdC, $vdN, $vdR]));
            // Export method breakdown: no credit-note line (neither the payment nor the refund moved into a credit note)
            $em = $run($sqlExpMethods, [$D1, $D1]);
            $emT = 0.0; $emCn = false;
            foreach ($em as $r) { $emT += (float)$r['total_amount']; $emCn = $emCn || $r['payment_method'] === 'credit_note'; }
            check(near($emT, 350) && !$emCn, 'export methods: 350, no "Credit note" line', json_encode([$emT, $emCn]));
            $mt = 0.0; $hasCn = false;
            foreach ($run($sqlMethods, [$D1, $D1]) as $r) { $mt += (float)$r['total_amount']; $hasCn = $hasCn || $r['payment_method'] === 'credit_note'; }
            check(near($mt, 350) && !$hasCn, 'payment methods add up to Gross Revenue, no credit-note method', (string)$mt);
            $dt = array_sum(array_map('floatval', array_column($run($sqlDaily, [$D1, $D1]), 'daily_revenue')));
            check(near($dt, 350), 'daily trend = 350', (string)$dt);
            $pr = $run($sqlPrior, [$D1, $D1])[0] ?? [];
            check(near((float)($pr['revenue'] ?? 0), 350), 'prior-period figure uses the same rule');
            $mo = array_sum(array_map('floatval', array_column($run($sqlMonthly, [$D1, $D1]), 'monthly_revenue')));
            check(near($mo, 330), 'monthly net = 350 received - 20 refunded into a credit note (End of Day rule)', (string)$mo);
            // Export status breakdown: only the period's rows
            $ex = $run($sqlExpStatus, [$D1, $D1]);
            $exPayCount = 0; $exPayTotal = 0.0;
            foreach ($ex as $r) { if ($r['row_kind'] === 'payment') { $exPayCount += (int)$r['count']; $exPayTotal += (float)$r['total_amount']; } }
            check($exPayCount === 6 && near($exPayTotal, 490), 'export status breakdown: the period only (the later 999 left out), payment lines = the page mix', json_encode([$exPayCount, $exPayTotal]));
            $exAll = $run($sqlExpStatus, [$D1, $D2]);
            $n2 = 0; foreach ($exAll as $r) { if ($r['row_kind'] === 'payment') { $n2 += (int)$r['count']; } }
            check($n2 === 7, 'export status breakdown: a wider period takes the later payment in');
        } catch (Throwable $e) {
            bad('DB part', $e->getMessage());
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
        $post = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'TRSM-%'");
        $post->execute();
        check((int)$post->fetchColumn() === 0, 'nothing left behind');
    }
}

echo "\nreports_status_mix_test: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
