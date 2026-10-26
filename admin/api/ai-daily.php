<?php
/**
 * Background start of the Assistant's daily checks (.claude/AI_ASSISTANT_PLAN.md Phase 4b, owner add "no cron
 * dependency"). Only used on servers that cannot finish a response early (no fastcgi_finish_request): the
 * first admin page load of the day sends one fire-and-forget POST here with a single-use token (stored hashed,
 * valid 5 minutes) and hangs up. Anything else gets a plain 404. No session; the checks run with
 * ignore_user_abort, log quietly and change only Assistant data.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['token'])) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../../includes/ai/ai-selfheal.php';

try {
    if (!rh_ai_daily_token_take($pdo, (string)$_POST['token'])) {
        http_response_code(404);
        exit;
    }
    @ignore_user_abort(true);
    @set_time_limit(300);
    echo "ok\n";
    rh_ai_daily_run($pdo);
} catch (Throwable $e) {
    error_log('Assistant daily checks (background): ' . $e->getMessage());
}
