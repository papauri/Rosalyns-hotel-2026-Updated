<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Deposit line and paid/due figures on gym, event and conference invoices (owner 2026-11-26: "i want them fixed").
 * A row without amount_paid / amount_due must not be shown as paid in full.
 * Usage (from the project root): php scripts/tests/deposit_line_test.php
 *
 * deposit_required / deposit_paid on gym_inquiries and event_inquiries are 0/1 flags; the money is deposit_amount.
 * The invoices printed the flag as money ("Deposit paid MWK 1.00"). Rule under test (same as the conference fix
 * in 823e9bd and the payment snapshot): a deposit line only when the flag is on AND deposit_amount > 0; "Deposit
 * required" = deposit_amount; "Deposit paid" = min(amount_paid, deposit_amount), never the deposit_paid column.
 *
 * Read-only: the builders are called with made-up rows whose id matches no payment, so the one SELECT on payments
 * returns nothing. The email-vs-PDF block writes one made-up payment per type inside a transaction it rolls back.
 * No email is sent. Run at Liwonde only (Rosalyn's is live).
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
require_once $root . '/config/invoice.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

/** The money figure printed after a totals label, or null when the label is not on the document. */
function figure_after(string $html, string $label): ?string {
    if (!preg_match('/' . preg_quote($label, '/') . '.{0,600}?MWK\s*([\d,]+\.\d{2})/s', $html, $m)) {
        return null;
    }
    return $m[1];
}

$noPaymentId = 999999999;
$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type IN ('gym','event','conference') AND booking_id = ?");
$st->execute([$noPaymentId]);
ok('made-up id has no payments', (int)$st->fetchColumn() === 0);

$base = [
    'id' => $noPaymentId,
    'name' => 'Test Member',
    'email' => 'member@example.invalid',
    'phone' => '000',
    'reference_number' => 'TEST-DEP-1',
    'total_amount' => 20000,
    'total_with_vat' => 20000,
    'vat_rate' => 0,
    'vat_amount' => 0,
    'amount_due' => 0,
    // gym
    'membership_type' => 'Monthly',
    'preferred_date' => '2026-12-01',
    // event
    'event_title' => 'Test Evening',
    'event_date' => '2026-12-01',
    'guests' => 2,
    // conference
    'inquiry_reference' => 'TEST-CONF-1',
    'company_name' => 'Test Co',
    'contact_person' => 'Test Contact',
    'room_name' => 'Test Room',
    'event_type' => 'Workshop',
    'end_date' => null,
    'start_time' => '09:00:00',
    'end_time' => '17:00:00',
    'number_of_attendees' => 10,
    'av_equipment' => '',
];

$cases = [
    'flag on, no amount' => ['deposit_required' => 1, 'deposit_amount' => 0, 'deposit_paid' => 1, 'amount_paid' => 3000],
    'flag on, amount null' => ['deposit_required' => 1, 'deposit_amount' => null, 'deposit_paid' => 1, 'amount_paid' => 3000],
    'flag off, real amount' => ['deposit_required' => 0, 'deposit_amount' => 5000, 'deposit_paid' => 0, 'amount_paid' => 3000],
    'flag on, real amount, part paid' => ['deposit_required' => 1, 'deposit_amount' => 5000, 'deposit_paid' => 1, 'amount_paid' => 3000],
    'snapshot money under deposit_paid' => ['deposit_required' => 1, 'deposit_amount' => 5000, 'deposit_paid' => 4000, 'amount_paid' => 3000],
    'paid above the deposit' => ['deposit_required' => 1, 'deposit_amount' => 5000, 'deposit_paid' => 1, 'amount_paid' => 8000],
    'nothing paid yet' => ['deposit_required' => 1, 'deposit_amount' => 5000, 'deposit_paid' => 0, 'amount_paid' => 0],
];
$expect = [
    'flag on, no amount' => null,
    'flag on, amount null' => null,
    'flag off, real amount' => null,
    'flag on, real amount, part paid' => ['5,000.00', '3,000.00'],
    'snapshot money under deposit_paid' => ['5,000.00', '3,000.00'],
    'paid above the deposit' => ['5,000.00', '5,000.00'],
    'nothing paid yet' => ['5,000.00', '0.00'],
];

$builders = [
    'gym' => 'buildGymInvoiceHTML',
    'event' => 'buildEventInvoiceHTML',
    'conference' => 'buildConferenceInvoiceHTML',
];
foreach ($builders as $type => $fn) {
    foreach ($cases as $label => $over) {
        $row = array_merge($base, $over);
        $html = $fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK');
        $req = figure_after($html, 'Deposit required');
        $paid = figure_after($html, 'Deposit paid');
        $want = $expect[$label];
        if ($want === null) {
            ok("$type invoice, $label: no deposit lines", $req === null && $paid === null, "required=" . var_export($req, true) . " paid=" . var_export($paid, true));
        } else {
            ok("$type invoice, $label: deposit required = {$want[0]}", $req === $want[0], 'got ' . var_export($req, true));
            ok("$type invoice, $label: deposit paid = {$want[1]}", $paid === $want[1], 'got ' . var_export($paid, true));
        }
        ok("$type invoice, $label: no flag printed as money", $req !== '1.00' && $paid !== '1.00' && $paid !== '4,000.00');
    }

    // A row without amount_paid / amount_due is never "paid in full" (it used to count the whole bill as paid).
    $row = array_merge($base, ['deposit_required' => 1, 'deposit_amount' => 5000, 'deposit_paid' => 1]);
    unset($row['amount_paid'], $row['amount_due']);
    $html = $fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK');
    ok("$type invoice, no amount_paid/amount_due: amount paid = 0.00 (no payments)", figure_after($html, 'Amount paid') === '0.00', 'got ' . var_export(figure_after($html, 'Amount paid'), true));
    ok("$type invoice, no amount_paid/amount_due: balance due = 20,000.00", preg_match('/Balance due(\s|&nbsp;)*MWK\s*20,000\.00/i', strip_tags($html)) === 1);
    ok("$type invoice, no amount_paid/amount_due: not marked paid in full", stripos($html, 'Paid in full') === false);
    ok("$type invoice, no amount_paid/amount_due: deposit paid = 0.00", figure_after($html, 'Deposit paid') === '0.00', 'got ' . var_export(figure_after($html, 'Deposit paid'), true));

    $row = array_merge($base, ['deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0, 'amount_paid' => 3000]);
    unset($row['amount_due']);
    $html = $fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK');
    ok("$type invoice, amount_paid 3000, no amount_due: balance due = 17,000.00", preg_match('/Balance due(\s|&nbsp;)*MWK\s*17,000\.00/i', strip_tags($html)) === 1);
    ok("$type invoice, amount_paid 3000, no amount_due: not marked paid in full", stripos($html, 'Paid in full') === false);

    $row = array_merge($base, ['deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0, 'amount_paid' => 20000, 'amount_due' => 0]);
    $html = $fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK');
    ok("$type invoice, really paid in full: still says Paid in full", stripos($html, 'Paid in full') !== false);
}

// ---------------------------------------------------------------------------------------------------------------
// The invoice EMAIL shows the attached PDF's figures (fault 2026-11-26): both read rh_flat_invoice_figures().
// One made-up completed payment per type is written inside a transaction that is rolled back at the end.
// ---------------------------------------------------------------------------------------------------------------
$recBy = (int)$pdo->query("SELECT id FROM admin_users ORDER BY id LIMIT 1")->fetchColumn();
$pdo->beginTransaction();
try {
    $payIns = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, total_amount, payment_method, payment_status, recorded_by)
                             VALUES (?, ?, ?, ?, ?, 7000, 7000, 'cash', 'completed', ?)");
    foreach ($builders as $type => $fn) {
        $payIns->execute(['DEPTEST' . random_int(100000, 999999), $type, $noPaymentId, 'TEST-DEP-1', date('Y-m-d'), $recBy ?: null]);

        // amount_paid / amount_due missing: the helper falls back to the ledger, the PDF prints the same figure.
        $row = array_merge($base, ['deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0]);
        unset($row['amount_paid'], $row['amount_due']);
        $fig = rh_flat_invoice_figures($pdo, $type, $row);
        $html = $fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK');
        ok("$type email figures: paid from the ledger = 7,000.00", abs($fig['amount_paid'] - 7000) < 0.01, 'got ' . $fig['amount_paid']);
        ok("$type email figures: due = 13,000.00", abs($fig['amount_due'] - 13000) < 0.01, 'got ' . $fig['amount_due']);
        ok("$type email paid = PDF Amount paid", figure_after($html, 'Amount paid') === number_format($fig['amount_paid'], 2), 'pdf ' . var_export(figure_after($html, 'Amount paid'), true));
        ok("$type email due = PDF Balance due", preg_match('/Balance due(\s|&nbsp;)*MWK\s*' . preg_quote(number_format($fig['amount_due'], 2), '/') . '/i', strip_tags($html)) === 1);

        // No locked total_with_vat: the gross is the current VAT mode's gross of total_amount, as on the PDF.
        $row2 = array_merge($row, ['total_with_vat' => 0, 'amount_paid' => 0]);
        $fig2 = rh_flat_invoice_figures($pdo, $type, $row2);
        $vp = vat_components(20000.0);
        ok("$type email figures: no locked total uses the VAT gross", abs($fig2['total'] - (float)$vp['total']) < 0.01 && abs($fig2['amount_due'] - (float)$vp['total']) < 0.01, 'got ' . $fig2['total'] . ' / ' . $fig2['amount_due']);
    }
} finally {
    $pdo->rollBack();
}
$st->execute([$noPaymentId]);
ok('rolled back: the made-up id has no payments left', (int)$st->fetchColumn() === 0);

// Source checks: the three invoice emails read the shared helper and send a plain-text subject.
$src = file_get_contents($root . '/config/invoice.php');
foreach (['sendConferenceInvoiceEmailToClient' => 'conference', 'sendGymInvoiceEmailToClient' => 'gym', 'sendEventInvoiceEmailToClient' => 'event'] as $fnName => $type) {
    $start = strpos($src, 'function ' . $fnName . '(');
    $end = $start === false ? false : strpos($src, "\nfunction ", $start + 10);
    $body = ($start === false) ? '' : substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    ok("$fnName found", $body !== '');
    ok("$fnName uses rh_flat_invoice_figures('$type')", strpos($body, "rh_flat_invoice_figures(\$pdo, '$type'") !== false);
    ok("$fnName has no 'paid = 0' fallback", strpos($body, ': 0.0;') === false);
    ok("$fnName has no leftover own figures (totalAmount/grossTotal/ciAmountPaid)", preg_match('/\$(totalAmount|grossTotal|ciAmountPaid)\b/', $body) === 0);
    ok("$fnName due and paid come from the helper", preg_match("/\\\$(amountDue|ciAmountDue)\s*=\s*\\\$(fig|ciFig)\['amount_due'\]/", $body) === 1);
    ok("$fnName subject is rh_email_plain", preg_match('/\$subject\s*=\s*rh_email_plain\(/', $body) === 1);
    ok("$fnName subject is not htmlspecialchars", preg_match('/\$subject\s*=\s*[^;]*htmlspecialchars/', $body) === 0);
    if ($type !== 'conference') {
        $totPos = strpos($body, 'rh_flat_invoice_email_total_row($fig,');
        ok("$fnName prints the total line from the helper", $totPos !== false);
        ok("$fnName total line sits above paid / due", $totPos !== false && $totPos < (int)strpos($body, 'Paid so far') && $totPos < (int)strpos($body, 'Balance due: '));
    } else {
        // Conference (fault 2026-11-26): the total line came from rh_conf_gross() at 0 decimals, not the PDF's figure.
        $totPos = strpos($body, 'rh_flat_invoice_email_total_row($ciFig,');
        $duePos = strpos($body, 'Balance due: ');
        ok("$fnName prints the total line from the helper", $totPos !== false);
        ok("$fnName total line sits above the balance due", $totPos !== false && $duePos !== false && $totPos < $duePos);
        ok("$fnName has no rh_conf_gross / 0-decimal total of its own", strpos($body, 'rh_conf_gross(') === false && strpos($body, 'Invoice total:') === false);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Gym / event invoice EMAIL total line = the attached PDF's total row (fault 2026-11-26: the emails had no total).
// A VAT-bearing row (20,000 + 3,000 VAT = 23,000) so the total cannot match the subtotal by accident. Read-only: the
// made-up id has no payments, amount_paid is given.
// ---------------------------------------------------------------------------------------------------------------
$vatOn = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
$totLabel = $vatOn ? 'Total (incl. VAT)' : 'Total';
echo "  (VAT at this hotel: " . ($vatOn ? 'on' : 'off') . ", mode " . (function_exists('vat_mode') ? vat_mode() : (string)getSetting('vat_mode', '')) . ")\n";
foreach (['gym' => 'buildGymInvoiceHTML', 'event' => 'buildEventInvoiceHTML', 'conference' => 'buildConferenceInvoiceHTML'] as $type => $fn) {
    $row = array_merge($base, ['deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0,
        'total_amount' => 20000, 'vat_rate' => 15, 'vat_amount' => 3000, 'total_with_vat' => 23000, 'amount_paid' => 5000, 'amount_due' => 18000]);
    $fig = rh_flat_invoice_figures($pdo, $type, $row);
    $pdf = strip_tags($fn($row, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK'));
    $line = rh_flat_invoice_email_total_row($fig, 'MWK');
    $pdfTot = preg_match('/' . preg_quote($totLabel, '/') . '(?:\s|&nbsp;)*MWK\s*([\d,]+\.\d{2})/', $pdf, $pm) ? $pm[1] : null;
    $mailTot = preg_match('/' . preg_quote($totLabel, '/') . ':(?:\s|&nbsp;)*MWK\s*([\d,]+\.\d{2})/', strip_tags($line), $mm) ? $mm[1] : null;
    ok("$type email total line: locked total 23,000.00", $mailTot === '23,000.00', 'got ' . var_export($mailTot, true));
    ok("$type email total line = PDF '$totLabel' row", $pdfTot !== null && $mailTot === $pdfTot, 'pdf ' . var_export($pdfTot, true) . ' email ' . var_export($mailTot, true));
    ok("$type email total line: label follows the VAT setting", strpos($line, $totLabel . ':') !== false && ($vatOn || strpos($line, 'incl. VAT') === false));
    ok("$type email total line: styled like the conference total", strpos($line, 'color: #8B7355; font-size: 18px;') !== false);
}
ok('email total line: currency escaped', strpos(rh_flat_invoice_email_total_row(['total' => 1], '<b>'), '<b>') === false);
// Conference: a locked total with cents shows the cents, as on the PDF (the old line printed rh_conf_gross at 0 dp: 1,235).
$cRow = array_merge($base, ['deposit_required' => 0, 'deposit_amount' => 0, 'deposit_paid' => 0,
    'total_amount' => 1059.71, 'vat_rate' => 16.5, 'vat_amount' => 174.85, 'total_with_vat' => 1234.56, 'amount_paid' => 0, 'amount_due' => 1234.56]);
$cFig = rh_flat_invoice_figures($pdo, 'conference', $cRow);
$cPdf = strip_tags(buildConferenceInvoiceHTML($cRow, 'TEST-INV-1', 'Test Hotel', 'hotel@example.invalid', '000', 'Somewhere', 'MWK'));
$cLine = strip_tags(rh_flat_invoice_email_total_row($cFig, 'MWK'));
ok('conference email total line keeps the cents: 1,234.56 (was 1,235)', strpos($cLine, 'MWK 1,234.56') !== false, $cLine);
ok('conference email total line = PDF total with cents', preg_match('/' . preg_quote($totLabel, '/') . '(?:\s|&nbsp;)*MWK\s*1,234\.56/', $cPdf) === 1);

echo "\ndeposit_line_test: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
