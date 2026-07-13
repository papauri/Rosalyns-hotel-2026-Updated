<?php
/**
 * Finance sequence helpers smoke test — runs against the live DB.
 * Usage: php scripts/smoke_test_finance.php
 * Cleans up its own test data on completion.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/finance-sequences.php';

$pass = 0;
$fail = 0;

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "[PASS] $label\n";
}

function fail(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "[FAIL] $label" . ($detail ? ": $detail" : '') . "\n";
}

function assert_true(bool $cond, string $label, string $detail = ''): void {
    if ($cond) ok($label); else fail($label, $detail);
}

// ── 1. DB + table setup ────────────────────────────────────────────────────────
echo "\n=== 1. DB + table setup ===\n";
try {
    $pdo->query('SELECT 1');
    ok('Live DB reachable');
} catch (Throwable $e) {
    fail('Live DB reachable', $e->getMessage());
    exit(1);
}

try {
    finance_ensure_sequence_tables($pdo);
    ok('finance_ensure_sequence_tables() executed');
} catch (Throwable $e) {
    fail('finance_ensure_sequence_tables() executed', $e->getMessage());
}

$tableExists = false;
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'finance_sequences'")->fetchAll(PDO::FETCH_ASSOC);
    $tableExists = count($tableCheck) > 0;
} catch (Throwable $e) {
    $tableExists = false;
}
assert_true($tableExists, 'finance_sequences table exists');

// ── 2. Atomic monotonic numbering ────────────────────────────────────────────
echo "\n=== 2. Atomic monotonic numbering ===\n";
$seqName = 'smoke_test:' . uniqid();
$seqScope = '2026';
$startNumber = 1;
$seenNumbers = [];
$prevNumber = null;
$monotonic = true;
$allInts = true;

for ($i = 0; $i < 5; $i++) {
    $n = finance_next_sequence_number($pdo, $seqName, $seqScope, $startNumber);
    if (!is_int($n)) {
        $allInts = false;
    }
    if (in_array($n, $seenNumbers, true)) {
        fail('Sequence number is unique across calls', "duplicate=$n");
    }
    $seenNumbers[] = $n;
    if ($prevNumber !== null && $n !== $prevNumber + 1) {
        $monotonic = false;
    }
    $prevNumber = $n;
}

assert_true($allInts, 'All returned sequence numbers are int');
assert_true(count($seenNumbers) === count(array_unique($seenNumbers)), 'No duplicate sequence numbers across 5 calls',
    'values=' . implode(',', $seenNumbers));
assert_true($monotonic, 'Sequence numbers strictly increase by 1', 'values=' . implode(',', $seenNumbers));
assert_true($seenNumbers[0] === $startNumber, 'First sequence number equals start number',
    "got={$seenNumbers[0]} expected=$startNumber");

// ── 3. Scope + key-fragment helpers (no DB writes) ───────────────────────────
echo "\n=== 3. Scope + key-fragment helpers (no DB writes) ===\n";
assert_true(
    finance_sequence_scope_from_date('2026-03-01') === '2026',
    'finance_sequence_scope_from_date() extracts year from valid date'
);
assert_true(
    finance_sequence_scope_from_date(null) === date('Y'),
    'finance_sequence_scope_from_date(null) returns current year'
);
assert_true(
    finance_sequence_scope_from_date('garbage') === date('Y'),
    'finance_sequence_scope_from_date(garbage) returns current year'
);

$fragment = finance_sequence_key_fragment('INV/2026 #!');
assert_true(
    preg_match('/^[A-Za-z0-9_-]+$/', $fragment) === 1,
    'finance_sequence_key_fragment() strips to safe characters',
    "got=$fragment"
);
assert_true($fragment !== '', 'finance_sequence_key_fragment() never returns empty', "got=$fragment");

$emptyFragment = finance_sequence_key_fragment('###');
assert_true($emptyFragment === 'default', 'finance_sequence_key_fragment() falls back to "default" for all-stripped input',
    "got=$emptyFragment");

// ── 4. Existence checks return false for guaranteed-absent values (no writes) ─
echo "\n=== 4. Existence checks for absent values ===\n";
$absentReceipt = 'RCP-SMOKE-' . uniqid();
$absentInvoice = 'INV-SMOKE-' . uniqid();

assert_true(
    finance_payment_value_exists($pdo, 'receipt_number', $absentReceipt) === false,
    'finance_payment_value_exists() returns false for guaranteed-absent receipt number'
);
assert_true(
    finance_invoice_number_exists($pdo, $absentInvoice) === false,
    'finance_invoice_number_exists() returns false for guaranteed-absent invoice number'
);

try {
    finance_payment_value_exists($pdo, 'id', 'irrelevant');
    fail('finance_payment_value_exists() throws InvalidArgumentException on unsafe column');
} catch (InvalidArgumentException $e) {
    ok('finance_payment_value_exists() throws InvalidArgumentException on unsafe column');
} catch (Throwable $e) {
    fail('finance_payment_value_exists() throws InvalidArgumentException on unsafe column', get_class($e) . ': ' . $e->getMessage());
}

// ── 5. BALANCE_TOLERANCE money-safety contract ───────────────────────────────
echo "\n=== 5. BALANCE_TOLERANCE money-safety contract ===\n";
assert_true(defined('BALANCE_TOLERANCE'), 'BALANCE_TOLERANCE is defined');
assert_true(defined('BALANCE_TOLERANCE') && BALANCE_TOLERANCE > 0, 'BALANCE_TOLERANCE is greater than zero');
assert_true(
    abs(100.00 - 100.005) < BALANCE_TOLERANCE,
    'Amounts differing by less than tolerance are treated as equal'
);
assert_true(
    abs(100.00 - 100.02) >= BALANCE_TOLERANCE,
    'Amounts differing by more than tolerance are treated as different'
);

// ── 6. Cleanup ────────────────────────────────────────────────────────────────
echo "\n=== 6. Cleanup ===\n";
try {
    $cleanupStmt = $pdo->prepare("DELETE FROM finance_sequences WHERE sequence_name = ?");
    $cleanupStmt->execute([$seqName]);

    $verifyStmt = $pdo->prepare("SELECT COUNT(*) FROM finance_sequences WHERE sequence_name = ?");
    $verifyStmt->execute([$seqName]);
    $remaining = (int)$verifyStmt->fetchColumn();
    assert_true($remaining === 0, 'Throwaway finance_sequences row(s) removed after test', "remaining=$remaining");
} catch (Throwable $e) {
    fail('Throwaway finance_sequences row(s) removed after test', $e->getMessage());
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n========================================\n";
echo "SMOKE TEST RESULTS: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
