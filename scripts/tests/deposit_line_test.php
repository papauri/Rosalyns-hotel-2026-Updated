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
 * returns nothing. Nothing is written to the database and no email is sent.
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

echo "\ndeposit_line_test: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
