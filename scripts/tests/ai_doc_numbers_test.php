<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * The Assistant reads document numbers whole, with or without the hotel letters in front
 * (setting document_number_prefix; Liwonde "LSH": LSH-INV-2026-000001, LSH-RCP-2026-000001, LSH-CN-..., LSH-REF-...,
 * LSH-CONF-INV-..., LSH-RFD-REF-...; Rosalyn's none: INV-2026-000001, RCP2026000001, CN-..., REF-...).
 * Covers: the money question reader (includes/ai/ai-money.php), the claim checker (includes/ai/ai-claims.php) and the
 * fallback claim reader (includes/ai/ai-agents.php rh_ai_claims). Ordinary text is not read as a number.
 *
 * Usage (from the repo root): php scripts/tests/ai_doc_numbers_test.php
 * Pure: no database, no network, no settings read (no $pdo: the Assistant settings fall back to their defaults).
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

if (!defined('BALANCE_TOLERANCE')) {
    define('BALANCE_TOLERANCE', 0.01); // same value as config/database.php (not loaded: no DB here)
}
unset($GLOBALS['pdo']);
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/ai/ai-core.php';
require_once $root . '/includes/ai/ai-money.php';
require_once $root . '/includes/ai/ai-claims.php';
require_once $root . '/includes/ai/ai-agents.php';
check(!isset($GLOBALS['pdo']), 'no database connection is opened by this test');

// The real numbers, from the real formatter (kind, sequence prefix as the generators pass it)
$kinds = [['invoice', 'INV'], ['invoice', 'CONF-INV'], ['invoice', 'GYM-INV'], ['invoice', 'EVT-INV'], ['receipt', 'RCP'], ['credit_note', 'CN'], ['refund', 'REF']];
$numbers = [];
foreach (['LSH', ''] as $hotel) {
    foreach ($kinds as [$kind, $prefix]) {
        $numbers[] = finance_format_document_number($kind, $prefix, '2026', 1, $hotel);
    }
}
$numbers[] = 'LSH-RFD-REF-2026-000001'; // refund receipt label with the hotel letters
$numbers[] = 'RFD-REF-2026-000001';
check(in_array('LSH-INV-2026-000001', $numbers, true) && in_array('LSH-RCP-2026-000001', $numbers, true) && in_array('RCP2026000001', $numbers, true) && in_array('LSH-CONF-INV-2026-000001', $numbers, true),
    'formatter gives the expected shapes', implode(' ', $numbers));

$refs = static function (string $text): array {
    $out = [];
    foreach (rh_ai_claim_extract($text, '2026-11-10') as $c) {
        if ($c['kind'] === 'ref') {
            $out[] = $c['raw'];
        }
    }
    return $out;
};
$strs = static function (string $text): array {
    $out = [];
    foreach (rh_ai_claims($text) as $c) {
        if ($c['type'] === 'str') {
            $out[] = $c['raw'];
        }
    }
    return $out;
};

foreach ($numbers as $n) {
    // Money question: "is <number> fully paid?" reads the whole number, hotel letters included
    $mi = rh_ai_money_read_intent("is $n fully paid?");
    check(is_array($mi) && ($mi['who'] ?? '') === $n, "money question reads $n whole", json_encode($mi));
    $mi = rh_ai_money_read_intent('how much is still owing on ' . strtolower($n) . '?');
    check(is_array($mi) && ($mi['who'] ?? '') === $n, "money question reads lower-case $n whole", json_encode($mi));
    // Claim checker: the reference is one claim, the whole number
    $r = $refs("Invoice $n was issued today.");
    check($r === [$n], "claim checker reads $n as one reference", json_encode($r));
    // Fallback claim reader: the whole number is one string claim, and no digit inside it becomes a figure
    $all = rh_ai_claims("Number $n is settled.");
    check(count($all) === 1 && $all[0]['type'] === 'str' && $all[0]['raw'] === $n, "fallback claim reader reads $n whole", json_encode($all));
}

// A number with the wrong hotel letters is not supported by evidence holding the real one
$ev = json_encode(['invoice' => 'LSH-INV-2026-000001', 'receipt' => 'LSH-RCP-2026-000001']);
check(rh_ai_claim_bad_raws('Invoice LSH-INV-2026-000001 is paid.', $ev) === [], 'a number in the evidence passes');
check(rh_ai_claim_bad_raws('Invoice XYZ-INV-2026-000001 is paid.', $ev) === ['XYZ-INV-2026-000001'], 'wrong hotel letters are caught', json_encode(rh_ai_claim_bad_raws('Invoice XYZ-INV-2026-000001 is paid.', $ev)));
check(rh_ai_claim_bad_raws('Receipt LSH-RCP-2026-000009 is paid.', $ev) === ['LSH-RCP-2026-000009'], 'a receipt number not in the evidence is caught');
check(in_array('LSH-RCP-2026-000009', $strs('Receipt LSH-RCP-2026-000009.'), true), 'fallback reader checks a dashed receipt number');

// Old shapes unchanged
check($refs('Booking LSH2026999999, order ORD-20261028-003, member GYM-0012, receipt RCP2026000001.') === ['LSH2026999999', 'ORD-20261028-003', 'GYM-0012', 'RCP2026000001'], 'booking / order / member / old receipt references unchanged', json_encode($refs('Booking LSH2026999999, order ORD-20261028-003, member GYM-0012, receipt RCP2026000001.')));
$mi = rh_ai_money_read_intent('is LSH2026999999 fully paid?');
check(($mi['who'] ?? '') === 'LSH2026999999', 'a booking reference is still read as a booking reference', json_encode($mi));
check($strs('Booking LSH2026999999 and RCP2026000001') === ['LSH2026999999', 'RCP2026000001'], 'fallback reader: booking reference and old receipt unchanged');

// Ordinary text is not read as a reference
$plain = 'Check-in is at 14:00 on 2026-11-10, Wi-Fi is free, room 12 is ready, the follow-up is booked and MWK 50,000 is due.';
check($refs($plain) === [], 'ordinary text has no reference claim', json_encode($refs($plain)));
check($strs($plain) === ['2026-11-10'], 'fallback reader: ordinary text has only the date as a string', json_encode($strs($plain)));
$money = array_values(array_filter(rh_ai_claim_extract($plain, '2026-11-10'), static fn($c) => $c['kind'] === 'money'));
check(count($money) === 1 && abs((float)$money[0]['value'] - 50000) < BALANCE_TOLERANCE, 'the money figure in ordinary text is still read', json_encode($money));
foreach (['is the check-in fully paid?', 'how much does room 12 still owe?', 'is the walk-in guest fully paid?'] as $q) {
    $mi = rh_ai_money_read_intent($q);
    check(!preg_match('/\d{4}/', (string)($mi['who'] ?? '')), "\"$q\" is not read as a document number", json_encode($mi));
}
$mi = rh_ai_money_read_intent('Is Mr Banda fully paid?');
check(($mi['who'] ?? '') === 'Banda', 'a name is still read as a name', json_encode($mi));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
