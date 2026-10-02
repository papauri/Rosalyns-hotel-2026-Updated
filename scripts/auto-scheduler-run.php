<?php

/**
 * Optional cron entry for the web-triggered scheduler (includes/auto-scheduler.php).
 * Cron is NOT required — the scheduler runs by itself on page loads. If you do have cron,
 * this runs exactly the same jobs under the same lock/interval:
 *
 *   *\/15 * * * *  /usr/bin/php /path/to/scripts/auto-scheduler-run.php --quiet
 *
 * Flags: --force  ignore the master/interval checks (toggles and the lock still apply)
 *        --quiet  print only on error
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/auto-scheduler.php';

$force = in_array('--force', $argv ?? [], true);
$quiet = in_array('--quiet', $argv ?? [], true);

$r = rh_scheduler_maybe_run($force, ['detach' => false]);
if (!$quiet || ($r['status'] ?? '') === 'error') {
    echo '[' . date('Y-m-d H:i:s') . '] scheduler: ' . ($r['status'] ?? '?') . (isset($r['reason']) ? ' (' . $r['reason'] . ')' : '') . "\n";
    foreach (($r['jobs'] ?? []) as $name => $jr) {
        echo '  ' . $name . ': ' . ($jr['status'] ?? '?')
            . (isset($jr['checked']) ? ' checked=' . $jr['checked'] . ' sent=' . ($jr['sent'] ?? 0) . ' skipped=' . ($jr['skipped'] ?? 0) : '')
            . (isset($jr['reason']) ? ' (' . $jr['reason'] . ')' : '') . "\n";
        foreach (($jr['errors'] ?? []) as $err) {
            echo '    ERR ' . $err . "\n";
        }
    }
}
exit(($r['status'] ?? '') === 'error' ? 1 : 0);
