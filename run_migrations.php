<?php
/**
 * CLI migration runner — runs each migration in a subprocess so each gets a
 * clean PHP process and its own DB connection. Safe to re-run at any time
 * since every migration is idempotent.
 *
 * Usage: php run_migrations.php
 */
if (PHP_SAPI !== 'cli') {
    die("Run this from the command line: php run_migrations.php\n");
}

$migrationsDir = __DIR__ . '/admin/migrations';
$files = glob($migrationsDir . '/*.php');
natsort($files);
$files = array_values($files);
$total = count($files);

echo PHP_EOL;
echo '╔══════════════════════════════════════════════════════════╗' . PHP_EOL;
echo '║          Rosalyn\'s Hotel — Migration Runner              ║' . PHP_EOL;
echo '╚══════════════════════════════════════════════════════════╝' . PHP_EOL . PHP_EOL;

$errors = [];

foreach ($files as $i => $file) {
    $name = basename($file);
    $num  = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
    echo "── [{$num}/{$total}] {$name}" . PHP_EOL;

    $escapedFile = escapeshellarg($file);
    $output = [];
    $exitCode = 0;
    exec("php {$escapedFile} 2>&1", $output, $exitCode);

    foreach ($output as $line) {
        echo "   " . $line . PHP_EOL;
    }

    if ($exitCode !== 0) {
        $errors[$name] = "Exit code {$exitCode}";
        echo "   ✗ FAILED (exit {$exitCode})" . PHP_EOL;
    }

    echo PHP_EOL;
}

echo '══════════════════════════════════════════════════════════' . PHP_EOL;
if (empty($errors)) {
    echo "  ✓ All {$total} migrations completed successfully." . PHP_EOL;
} else {
    $ok = $total - count($errors);
    echo "  Completed {$ok}/{$total}. " . count($errors) . " error(s):" . PHP_EOL;
    foreach ($errors as $f => $msg) {
        echo "    - {$f}: {$msg}" . PHP_EOL;
    }
}
echo '══════════════════════════════════════════════════════════' . PHP_EOL . PHP_EOL;
