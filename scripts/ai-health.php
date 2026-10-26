<?php
/**
 * Daily: the Assistant's health check (.claude/AI_ASSISTANT_PLAN.md "Self-healing", Phase 4b).
 * A test call to the provider in use, every read tool's self-test with safe sample arguments, and the
 * monthly budget (reaching it pauses the Assistant and tells the people who manage Assistant Settings).
 * Results show on Assistant Settings.
 *
 * Optional: the same step runs on the first admin page load of the day when it has not run for 24 hours,
 * so cron is not needed. Exits straight away, without any network call, when the Assistant is off.
 *
 * Cron (daily, e.g. 05:00):  0 5 * * *  php /path/to/scripts/ai-health.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/ai/ai-selfheal.php';

if (!rh_ai_enabled($pdo)) {
    echo "Assistant is off - nothing to do.\n";
    exit(0);
}
$r = rh_ai_health_run($pdo);
rh_ai_job_record($pdo, 'health', (bool)$r['ok'], (string)$r['note']);
echo 'Health: ' . $r['note'] . "\n";
exit($r['ok'] ? 0 : 1);
