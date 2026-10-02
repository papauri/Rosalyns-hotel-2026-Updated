<?php

/**
 * Render every automated email (payment reminders 1-3 with a SAMPLE invoice PDF, quotation expiry,
 * pre-arrival, post-stay review, gym renewal) with SAMPLE data and send each one to a test address.
 *
 * Safe by design: CLI only; creates no DB records; the automated send context forces the recipient,
 * so a real guest can never receive these. Refuses any address except the default unless --allow-any.
 *
 * Usage:
 *   php scripts/auto-emails-dry-run.php --to=johnpaulchirwa@gmail.com [--only=payment_reminder_1,gym_membership_renewal]
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const RH_AUTO_TEST_DEFAULT = 'johnpaulchirwa@gmail.com';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/auto-email-jobs.php';

$to = RH_AUTO_TEST_DEFAULT;
$only = [];
$allowAny = in_array('--allow-any', $argv ?? [], true);
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--to=') === 0) {
        $to = trim(substr($arg, 5));
    } elseif (strpos($arg, '--only=') === 0) {
        $only = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    }
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL) || (strcasecmp($to, RH_AUTO_TEST_DEFAULT) !== 0 && !$allowAny)) {
    fwrite(STDERR, 'Refusing recipient. Only ' . RH_AUTO_TEST_DEFAULT . " is allowed unless --allow-any is given.\n");
    exit(2);
}

$sent = 0;
$failed = 0;
foreach (rh_auto_sample_keys() as $key => $label) {
    if ($only && !in_array($key, $only, true)) {
        continue;
    }
    $r = rh_auto_send_sample($key, $to);
    if (!empty($r['success'])) {
        $sent++;
        echo "[$key] sent to $to" . (!empty($r['preview']) ? ' (dev-mode preview only)' : '') . "\n";
    } else {
        $failed++;
        echo "[$key] FAILED: " . ($r['message'] ?? 'unknown') . "\n";
    }
}
echo "Done. sent=$sent failed=$failed\n";
exit($failed > 0 ? 1 : 0);
