<?php
/**
 * Daily: refresh the Assistant's model lists from each provider that has a saved key, so newer
 * models show up in Assistant Settings ("new - set price"). Chosen models are never changed.
 *
 * Exits straight away, without any network call, when the Assistant is off
 * (rh_ai_enabled() is fail-closed: setting, module row and migration must all be in place).
 *
 * Cron (optional, daily, e.g. 04:30):  30 4 * * *  php /path/to/scripts/ai-model-refresh.php
 * (Without cron the daily check on the first admin page load of the day does the same, once per day.)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/ai/ai-catalog.php';

$run = rh_ai_model_refresh_run($pdo);
if (!$run['ran']) {
    echo "Assistant is off - nothing to do.\n";
    exit(0);
}
if (!$run['results']) {
    echo "No provider keys saved - nothing to refresh.\n";
    exit(0);
}
$failed = 0;
foreach ($run['results'] as $provider => $r) {
    echo $provider . ': ' . ($r['ok'] ? 'OK' : 'FAILED') . ' - ' . $r['message'] . "\n";
    if (!$r['ok']) {
        $failed++;
        if (function_exists('rh_log_event')) {
            rh_log_event('ai_model_refresh', 'warning', 'Assistant model list refresh failed', ['provider' => $provider, 'message' => $r['message']]);
        }
    }
}
exit($failed ? 1 : 0);
