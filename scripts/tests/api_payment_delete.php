<?php
/**
 * API payment delete test (.claude/plans/linked-data-cleanup.md, Accounting F1).
 * Usage: php scripts/tests/api_payment_delete.php
 *
 * Runs DELETE /api/payments/{id} through the real endpoint code, one child process per case
 * (scripts/tests/api_payment_delete_child.php): received, receipted, credit-note and restaurant
 * payments are refused; only an unreceipted pending draft is deleted, with an audit row and a
 * booking timeline line. Each child removes everything it made; a leak fails the test.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

chdir(dirname(__DIR__, 2));
$pass = 0; $fail = 0;
function check(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "[PASS] $l\n"; } else { $fail++; echo "[FAIL] $l" . ($d !== '' ? ": $d" : '') . "\n"; } }

function run_case(string $case): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg('scripts/tests/api_payment_delete_child.php') . ' ' . escapeshellarg($case) . ' 2>&1', $out);
    for ($i = count($out) - 1; $i >= 0; $i--) {
        $j = json_decode($out[$i], true);
        if (is_array($j)) {
            return $j;
        }
    }
    return ['code' => 0, 'message' => implode(' | ', array_slice($out, -3)), 'state' => [], 'leak' => true];
}

$refused = [
    'completed'  => 'A received (completed) payment is refused',
    'receipted'  => 'A pending payment that already has a receipt number is refused',
    'creditnote' => 'A credit-note payment is refused',
    'restaurant' => 'A restaurant payment is refused',
];
foreach ($refused as $case => $label) {
    $r = run_case($case);
    check($r['code'] === 409 && empty($r['state']['deleted']), $label, json_encode($r));
    check(empty($r['leak']), "  ($case) nothing left behind");
}

$r = run_case('draft');
check($r['code'] === 200 && !empty($r['state']['deleted']), 'An unreceipted pending draft is soft-deleted', json_encode($r));
check(($r['state']['audit'] ?? 0) === 1, 'Payment audit log row written');
check(($r['state']['timeline'] ?? 0) === 1, 'Booking timeline line written');
check(empty($r['leak']), '  (draft) test booking and payment removed again');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
