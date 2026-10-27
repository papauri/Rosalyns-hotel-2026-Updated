<?php
/**
 * Nightly: the Assistant's fixed question set (.claude/AI_ASSISTANT_PLAN.md "Local eval set",
 * "Continuous train & test + completion guardrail", Phase 4b).
 *
 *   php scripts/ai-eval.php           the day's check: the 5 key questions, or the full set (includes/ai/evals.json +
 *                                     regression questions from fixed faults) when it has not run for 7 days;
 *                                     on the Fast model; counts as the day's run for the completion guardrail
 *   php scripts/ai-eval.php --smoke   only the 5 key questions (below the threshold the Assistant pauses itself)
 *
 * Answers come from the hotel's own data at run time; every run is logged to ai_eval_runs with the pass rate
 * and tokens per answer. Optional: the day's check runs on the first admin page load of the day when it has not
 * run for 24 hours, so cron is not needed. Exits straight away, without any network call, when the Assistant
 * is off.
 *
 * Cron (nightly, e.g. 03:00):  0 3 * * *  php /path/to/scripts/ai-eval.php
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
@set_time_limit(0);
if (!in_array('--smoke', $argv ?? [], true)) {
    $res = rh_ai_daily_eval($pdo);
    rh_ai_job_record($pdo, 'eval', (bool)$res['ok'], (string)$res['note']);
    echo $res['note'] . "\n";
    exit($res['ok'] ? 0 : 1);
}
$run = rh_ai_eval_run($pdo, 'smoke');
if (!$run['ran']) {
    echo $run['why'] . "\n";
    exit(0);
}
foreach ($run['details'] as $id => $d) {
    echo str_pad(strtoupper($d['status']), 5) . ' ' . $id . ($d['why'] !== '' ? ' - ' . $d['why'] : '') . "\n";
}
echo $run['passed'] . ' of ' . $run['run'] . ' passed, ' . $run['skipped'] . ' skipped, ' . $run['tokens_per_answer'] . " tokens per answer.\n";
exit($run['failed'] ? 1 : 0);
