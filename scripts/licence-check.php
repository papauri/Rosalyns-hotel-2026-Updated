<?php

/**
 * Force a licence check from the command line (includes/licence.php) and print the result.
 * Prints no secrets (no key, no token). Safe to run from cron; exits 0 when the answer was accepted, 1 otherwise.
 *
 *   php scripts/licence-check.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/licence.php';

if (!rh_licence_enabled()) {
    echo "Licensing is not switched on for this site (no licence key in the program or no LICENCE_TENANT in .env). Nothing was checked.\n";
    exit(0);
}

$r = rh_licence_check_now();
$st = rh_licence_status(true);
echo '[' . date('Y-m-d H:i:s') . '] licence check: ' . ($r['ok'] ? 'accepted' : 'not accepted') . "\n";
echo 'status: ' . $st['status'] . ($st['reason'] !== 'ok' ? ' (' . $st['reason'] . ')' : '') . "\n";
if (!empty($st['dates']['paid_until'])) {
    echo 'paid until: ' . $st['dates']['paid_until'] . ', read-only from: ' . $st['dates']['read_only_from'] . ', locked from: ' . $st['dates']['suspended_from'] . "\n";
}
if ($r['error'] !== '') {
    echo 'problem: ' . $r['error'] . "\n";
}
exit($r['ok'] ? 0 : 1);
