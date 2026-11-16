<?php
/**
 * Stock name check: build the name test's answer list from the owner's crate list (spec D13, Fable 5).
 * Usage: php scripts/ai-stock-truth.php "<path to the crate list CSV>"
 *
 * Reads the CSV only (never changes it) and keeps names, units and the owner's answers (crate size, or Y = already the price of
 * one) in storage/ai/stock_truth.json. storage/ is never committed, and no cost or value goes into the list. No database, no
 * model call. A list with nothing filled in is refused: the name test then reports "not run".
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../includes/ai/ai-stock-kinds.php';

$path = (string)($argv[1] ?? '');
if ($path === '' || !is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Give the path of the crate list CSV.\n");
    exit(2);
}
$r = rh_ai_sk_truth_from_csv((string)file_get_contents($path));
if (!$r['ok']) {
    echo 'Not kept: ' . $r['error'] . " The name test reports \"not run\" until the list is filled in.\n";
    exit(1);
}
if (!rh_ai_sk_truth_save($r['rows'])) {
    fwrite(STDERR, 'Could not write ' . rh_ai_sk_truth_path() . "\n");
    exit(1);
}
$n = count(array_filter($r['rows'], static fn($x) => $x['n'] !== null));
echo 'Kept ' . count($r['rows']) . ' names (' . $n . ' crate sizes, ' . (count($r['rows']) - $n) . " prices of one) in storage/ai/stock_truth.json.\n";
