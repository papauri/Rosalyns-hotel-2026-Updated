<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * The Assistant's whole daily run without anyone signing in: privacy-vault sweep, model list, review and learning,
 * health checks, the question-set check, card audit, budget notices, weekly digest and retention - the same function
 * the first admin page load of the day uses (rh_ai_daily_run), so a quiet hotel still gets its day counted.
 *
 *   php scripts/ai_daily.php            the day's run (does nothing if it already ran today)
 *   php scripts/ai_daily.php --force    run every step now whatever its last run
 *
 * Nothing schedules this by itself and the Assistant does not assume a scheduler exists. To use it, add one
 * (cPanel Cron Jobs, or Windows Task Scheduler) to run once a day, for example 03:00:
 *   0 3 * * *  php /path/to/scripts/ai_daily.php
 * Exits straight away, without any network call, when the Assistant is off. Exit code 1 when a step failed.
 */
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-selfheal.php';

if (!rh_ai_enabled($pdo)) {
    echo "Assistant is off - nothing to do.\n";
    exit(0);
}
@set_time_limit(0);
$res = rh_ai_daily_run($pdo, in_array('--force', $argv ?? [], true));
echo 'Daily run: ' . $res['status'] . "\n";
$bad = 0;
foreach ($res['jobs'] as $job => $r) {
    $ok = !empty($r['ok']) || !array_key_exists('ok', $r);
    $bad += $ok ? 0 : 1;
    echo '  ' . str_pad($job, 10) . ($ok ? 'ok   ' : 'FAIL ') . (string)($r['note'] ?? '') . "\n";
}
exit($bad || $res['status'] === 'error' ? 1 : 0);
