<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Ship M (owner 2026-11-18): guest credit is not a refund and not revenue; VAT is counted once.
 * T2: the pure twin rh_guest_credit_from_rows (no database): opening / issued / used / voided / expired / closing,
 *     owed on a given day, and the identity opening + issued - used - voided - expired = closing.
 * T3: End of Day (rh_eod_report_data), the Reports page SQL, the Overview export and the Accounting Summary on
 *     far-past fixture rows at LIWONDE only (the test platform; Rosalyn's is live), inside ONE transaction that is
 *     rolled back. Raw INSERTs only; nothing is committed.
 * Usage (repo root): php scripts/tests/guest_credit_ship_m.php
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function near(float $a, float $b): bool { return abs($a - $b) <= 0.011; }

require_once $root . '/admin/includes/finance-refunds.php';
require_once $root . '/admin/includes/guest-credit.php';

// ---- T2: pure twin
$notes = [
    ['id' => 1, 'original_amount' => 100000, 'issued_at' => '2026-11-01 09:00:00', 'voided_at' => null, 'expires_at' => null],
    ['id' => 2, 'original_amount' => 50000, 'issued_at' => '2026-11-02 09:00:00', 'voided_at' => '2026-11-04 12:00:00', 'expires_at' => null],
    ['id' => 3, 'original_amount' => 20000, 'issued_at' => '2026-10-20 09:00:00', 'voided_at' => null, 'expires_at' => '2026-11-02'],
];
$apps = [
    ['credit_note_id' => 1, 'amount_applied' => 30000, 'applied_at' => '2026-11-03 10:00:00'],
    ['credit_note_id' => 1, 'amount_applied' => -5000, 'applied_at' => '2026-11-05 10:00:00'],
    ['credit_note_id' => 2, 'amount_applied' => 10000, 'applied_at' => '2026-11-03 11:00:00'],
];
$s = rh_guest_credit_from_rows($notes, $apps, '2026-11-01', '2026-11-05');
check(near($s['opening'], 20000), 'T2 opening 20,000 (note C, issued before the period)', (string)$s['opening']);
check(near($s['issued'], 150000) && $s['issued_count'] === 2, 'T2 issued 150,000 (A + B)', json_encode([$s['issued'], $s['issued_count']]));
check(near($s['used'], 35000), 'T2 used 35,000 (30,000 + 10,000 - 5,000 put back)', (string)$s['used']);
check(near($s['voided'], 40000), 'T2 voided 40,000 (B unused part)', (string)$s['voided']);
check(near($s['expired'], 20000), 'T2 expired unused 20,000 (C, the day after its expiry)', (string)$s['expired']);
check(near($s['closing'], 75000), 'T2 closing 75,000 (A after the put-back)', (string)$s['closing']);
$owed = $s['owed_at'];
check(near($owed('2026-11-02'), 170000), 'T2 owed at 11-02 = 170,000 (C still owed on its expiry day)', (string)$owed('2026-11-02'));
check(near($owed('2026-11-03'), 110000), 'T2 owed at 11-03 = 110,000', (string)$owed('2026-11-03'));
check(near($owed('2026-11-04'), 70000), 'T2 owed at 11-04 = 70,000 (B voided)', (string)$owed('2026-11-04'));
check(near($s['opening'] + $s['issued'] - $s['used'] - $s['voided'] - $s['expired'], $s['closing']), 'T2 identity: opening + issued - used - voided - expired = closing');
$gl = rh_guest_credit_lines($s, true);
check(array_column($gl, 'label') === ['Guest credit at start', 'Guest credit issued', 'Guest credit used', 'Guest credit voided', 'Guest credit expired unused', 'Guest credit still owed'], 'T2 period lines in order with the shared labels');
$dl = rh_guest_credit_lines(['opening' => 0, 'issued' => 5, 'used' => 0, 'voided' => 0, 'expired' => 0, 'closing' => 5], false);
check(array_column($dl, 'key') === ['issued', 'used', 'closing'], 'T2 day lines: no opening, zero voided/expired hidden');
check(rh_guest_credit_lines(['failed' => true], true) === [], 'T2 a failed read gives no lines (never "nothing owed")');

// ---- T3: DB part (Liwonde only, rolled back)
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require $root . '/config/database.php';
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false || stripos($root, 'Liwonde') === false) {
    echo "SKIP DB part: runs at Liwonde (the test platform) only; Rosalyn's is live.\n";
} else {
    require_once $root . '/admin/includes/eod-revenue.php';
    require_once $root . '/admin/includes/eod-report-data.php';
    require_once $root . '/includes/report-builders.php';
    $rhCashRow = rh_eod_cash_row_sql();
    $D = '2001-05-10'; $D2 = '2001-05-12';
    $pre1 = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE DATE(payment_date) BETWEEN '2001-05-09' AND '2001-05-12'");
    $pre1->execute();
    $pre2 = $pdo->query("SELECT COUNT(*) FROM credit_notes WHERE DATE(issued_at) <= '2001-05-12'");
    $pre3 = $pdo->query("SELECT COUNT(*) FROM credit_note_applications WHERE DATE(applied_at) <= '2001-05-12'");
    if ((int)$pre1->fetchColumn() > 0 || (int)$pre2->fetchColumn() > 0 || (int)$pre3->fetchColumn() > 0) {
        bad('fixture days are empty', 'real rows on or before the far-past fixture days; not running');
    } else {
        $rep = (string)file_get_contents($root . '/admin/reports.php');
        $exp = (string)file_get_contents($root . '/api/reports-export.php');
        $grab = static function (string $src, string $re): string { return preg_match($re, $src, $m) ? $m[1] : ''; };
        $sqlRevType = $grab($rep, '/\$revenueByTypeStmt = \$pdo->prepare\((".*?")\);/s');
        $sqlRefReasons = $grab($rep, '/\$refundReasonStmt = \$pdo->prepare\((".*?")\);/s');
        $sqlVatDay = $grab($rep, '/\$vatCollectedStmt = \$pdo->prepare\((".*?")\);/s');
        $sqlExpSummary = $grab($exp, '/\$summaryQuery = (".*?");/s');
        check($sqlRevType !== '' && $sqlRefReasons !== '' && $sqlVatDay !== '' && $sqlExpSummary !== '', 'T3 page and export SQL found in the source');
        check(strpos($exp, "if (\$row['payment_method'] === 'credit_note') {") !== false, 'T3 export status lines skip credit-note refund rows (guest credit, not a refund)');

        $pdo->beginTransaction();
        try {
            $orig = $pdo->query("SELECT id, booking_id, booking_reference FROM payments WHERE booking_type = 'room' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $admin = (int)$pdo->query("SELECT id FROM admin_users ORDER BY id LIMIT 1")->fetchColumn();
            if (!$orig || $admin <= 0) { throw new RuntimeException('no room payment or admin user to hang fixtures on'); }
            $ins = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, original_payment_id, refund_reason, refund_status, refund_amount, credit_note_id, recorded_by, created_at)
                VALUES (?, 'room', ?, ?, ?, ?, 10, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)");
            $pay = static function (string $ref, string $day, float $amt, string $meth, string $type, string $st, ?string $rs) use ($ins, $orig, $pdo): int {
                $isRf = $type === 'refund';
                $ins->execute([$ref, (int)$orig['booking_id'], (string)$orig['booking_reference'], $day, $amt - round($amt * 0.1, 2), round($amt * 0.1, 2), $amt, $meth, $type, $st,
                    $isRf ? (int)$orig['id'] : null, $isRf ? 'other' : null, $rs, $isRf ? $amt : null, $day . ' 10:00:00']);
                return (int)$pdo->lastInsertId();
            };
            // VAT on every row = 10% of its amount (only which rows count matters)
            $pay('TGCM-P1', $D, 1000, 'cash', 'full_payment', 'completed', null);
            $pay('TGCM-P2', $D, 500, 'bank_transfer', 'full_payment', 'completed', null);
            $cnleg = $pay('TGCM-CNLEG', $D, 300, 'credit_note', 'refund', 'completed', 'completed');
            $pay('TGCM-R1', $D, 200, 'cash', 'refund', 'completed', 'completed');
            $pay('TGCM-R2', $D, 80, 'cash', 'refund', 'pending', 'pending');
            $red = $pay('TGCM-RED', $D2, 120, 'credit_note', 'full_payment', 'completed', null);
            $ret = $pay('TGCM-RET', $D2, 20, 'credit_note', 'refund', 'completed', 'completed');

            $cn = $pdo->prepare("INSERT INTO credit_notes (credit_note_number, booking_type, guest_name, original_amount, amount_used, balance, vat_rate, vat_amount,
                    reason, status, issued_by, issued_at, expires_at, voided_at, voided_by, void_reason)
                VALUES (?, ?, 'Ship M fixture', ?, ?, ?, 10, ?, ?, ?, ?, ?, NULL, ?, ?, ?)");
            $cn->execute(['TGCM-CN1', 'room', 300, 100, 200, 30, 'overpayment', 'partially_applied', $admin, $D . ' 10:05:00', null, null, null]);
            $cn1 = (int)$pdo->lastInsertId();
            $cn->execute(['TGCM-CN2', 'goodwill', 50, 0, 0, 5, 'goodwill', 'voided', $admin, $D . ' 11:00:00', $D2 . ' 09:00:00', $admin, 'Ship M fixture']);
            $pdo->prepare("UPDATE payments SET credit_note_id = ? WHERE id = ?")->execute([$cn1, $cnleg]);
            $ap = $pdo->prepare("INSERT INTO credit_note_applications (credit_note_id, payment_id, applied_to_booking_id, applied_to_booking_reference, applied_to_booking_type, amount_applied, applied_by, applied_at)
                VALUES (?, ?, ?, ?, 'room', ?, ?, ?)");
            $ap->execute([$cn1, $red, (int)$orig['booking_id'], (string)$orig['booking_reference'], 120, $admin, $D2 . ' 10:00:00']);
            $ap->execute([$cn1, $ret, (int)$orig['booking_id'], (string)$orig['booking_reference'], -20, $admin, $D2 . ' 10:30:00']);

            // End of Day, day D
            $e = rh_eod_report_data($pdo, $D);
            check(near((float)$e['gross'], 1500) && near((float)$e['rev']['refunds'], 200) && near((float)$e['net'], 1300), 'EOD D: gross 1,500, refunds 200 (only the real payout), net 1,300', json_encode([$e['gross'], $e['rev']['refunds'], $e['net']]));
            check(near((float)$e['rev']['total_vat'], 130), 'EOD D: VAT 130 (150 received - 20 on the real refund; the 300 moved onto a note is not a refund)', (string)$e['rev']['total_vat']);
            check(near((float)$e['cash_total'], 800) && near((float)$e['method_totals']['non_cash'], 500), 'EOD D: cash 800 (1,000 - 200 paid out), non-cash 500', json_encode([$e['cash_total'], $e['method_totals']['non_cash']]));
            $g = $e['guest_credit'];
            check(empty($g['failed']) && near($g['opening'], 0) && near($g['issued'], 350) && $g['issued_count'] === 2 && near($g['closing'], 350), 'EOD D: guest credit issued 350, still owed 350', json_encode($g));
            // End of Day, day D2
            $e2 = rh_eod_report_data($pdo, $D2);
            check(near((float)$e2['gross'], 0) && near((float)$e2['rev']['refunds'], 0) && near((float)$e2['rev']['total_vat'], 0), 'EOD D2: gross 0, refunds 0, VAT 0 (credit-note payment and put-back are not cash)', json_encode([$e2['gross'], $e2['rev']['refunds'], $e2['rev']['total_vat']]));
            check(near((float)$e2['vat_credit_note_info'], 12), 'EOD D2: VAT inside the credit-note payment 12, information only', (string)$e2['vat_credit_note_info']);
            $g2 = $e2['guest_credit'];
            check(near($g2['opening'], 350) && near($g2['used'], 100) && near($g2['voided'], 50) && near($g2['closing'], 200), 'EOD D2: guest credit opening 350, used 100, voided 50, still owed 200', json_encode($g2));
            check(near($g2['opening'] + $g2['issued'] - $g2['used'] - $g2['voided'] - $g2['expired'], $g2['closing']), 'EOD D2: guest credit identity holds');

            // Reports page SQL over D..D2
            $run = static function (string $expr, array $params) use ($pdo, $rhCashRow) {
                $date_filter = "AND " . rh_pay_count_day_sql() . " >= ? AND " . rh_pay_count_day_sql() . " <= ?";
                $cashRow = $rhCashRow;
                $sql = eval('return ' . $expr . ';');
                $st = $pdo->prepare($sql);
                $st->execute($params);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            };
            $gross = 0.0; $vat = 0.0; $info = 0.0;
            foreach ($run($sqlRevType, [$D, $D2]) as $r) { $gross += (float)$r['total_revenue']; $vat += (float)$r['total_vat_counted']; $info += (float)$r['total_vat_credit_note']; }
            $refunds = 0.0;
            foreach ($run($sqlRefReasons, [$D, $D2]) as $r) { $refunds += (float)$r['total_amount']; }
            check(near($gross, 1500), 'Reports: Gross Revenue 1,500', (string)$gross);
            check(near($refunds, 200), 'Reports: Refunds Issued 200 (no credit-note rows, no pending)', (string)$refunds);
            check(near($vat, 130) && near($info, 12), 'Reports: VAT Collected 130, credit-note VAT 12 information only', json_encode([$vat, $info]));
            check(near($gross - $refunds - $vat, 1170), 'Reports: Net Revenue 1,170 (1,500 - 200 - 130)');
            $reg = array_sum(array_map('floatval', array_column($run($sqlVatDay, [$D, $D2]), 'vat_counted')));
            check(near($reg, $vat), 'Reports: VAT Register total = VAT Collected', (string)$reg);
            $gr = rh_guest_credit_summary($pdo, $D, $D2);
            check(near($gr['opening'], 0) && near($gr['issued'], 350) && near($gr['used'], 100) && near($gr['voided'], 50) && near($gr['closing'], 200), 'Reports: guest credit for D..D2 = 0 + 350 - 100 - 50 = 200', json_encode($gr));

            // Overview export summary
            $ex = $run($sqlExpSummary, [$D, $D2])[0] ?? [];
            check(near((float)($ex['total_revenue'] ?? -1), 1300) && near((float)($ex['total_vat'] ?? -1), 130) && (int)($ex['total_transactions'] ?? 0) === 2, 'Export overview: revenue 1,300, VAT 130, 2 transactions', json_encode($ex));

            // Accounting Summary (timed report CSV), day D
            $acc = buildAccountingSummary($D, $D);
            check(near((float)$acc['totals']['gross'], 1300) && near((float)$acc['totals']['vat'], 130), 'Accounting Summary D: TOTAL gross 1,300, VAT 130', json_encode($acc['totals']));
            check(strpos($acc['csv'], 'Guest credit issued') !== false && strpos($acc['csv'], 'Guest credit still owed') !== false, 'Accounting Summary D: guest credit section present');
        } catch (Throwable $ex) {
            bad('DB part', $ex->getMessage());
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
        $post = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_reference LIKE 'TGCM-%'")->fetchColumn()
            + (int)$pdo->query("SELECT COUNT(*) FROM credit_notes WHERE credit_note_number LIKE 'TGCM-%'")->fetchColumn();
        check($post === 0, 'nothing left behind');
    }
}

echo "\nguest_credit_ship_m: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
