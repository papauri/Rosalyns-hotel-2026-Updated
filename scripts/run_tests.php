<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Run every test suite. Usage (from the project root): php scripts/run_tests.php
 * All suites roll back their writes (smoke tests clean up after themselves).
 */
$root = dirname(__DIR__);
chdir($root);
$suites = [
    'Booking smoke'         => 'scripts/smoke_test_booking.php',
    'Finance smoke'         => 'scripts/smoke_test_finance.php',
    'POS/KDS smoke'         => 'scripts/smoke_test_pos_kds.php',
    'Settings pages'        => 'scripts/tests/settings_pages.php',
    'Hotel details section' => 'scripts/tests/hotel_details.php',
    'User timezone display' => 'scripts/tests/user_timezone.php',
];
$failed = 0;
foreach ($suites as $name => $file) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $pass = preg_match_all('/^PASS\b/m', $text);
    $fail = preg_match_all('/^FAIL\b/m', $text);
    if (preg_match('/(\d+) passed, (\d+) failed/', $text, $m)) {
        [$pass, $fail] = [(int)$m[1], (int)$m[2]];
    } elseif (preg_match('/PASS=(\d+) FAIL=(\d+)/', $text, $m)) {
        [$pass, $fail] = [(int)$m[1], (int)$m[2]];
    }
    $ok = $code === 0 && $fail === 0 && $pass > 0;
    $failed += $ok ? 0 : 1;
    printf("%-24s %s  (%d passed, %d failed)\n", $name, $ok ? 'OK  ' : 'FAIL', $pass, $fail);
    if (!$ok) {
        foreach (array_slice(array_filter($out, fn($l) => preg_match('/FAIL|Fatal|Error/', $l)), 0, 5) as $l) {
            echo "    $l\n";
        }
    }
}
echo $failed ? "\n$failed suite(s) failed.\n" : "\nAll suites passed.\n";
exit($failed ? 1 : 0);
