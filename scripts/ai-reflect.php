<?php
/**
 * Nightly: the Assistant's review of the day (.claude/AI_ASSISTANT_PLAN.md "Learning loop", Phase 4b).
 * Removes the privacy vault of chats whose owner's session has ended, then turns the day's incidents and
 * thumbs-up answers into short lessons and playbooks (Fast model, tokenised data only), moves notes in and
 * out of use by their score, and writes up problems that need a code fix for the owner.
 *
 * Optional: the same steps run on the first admin page load of the day when they have not run for 24 hours,
 * so cron is not needed. Exits straight away, without any network call, when the Assistant is off.
 *
 * Cron (nightly, e.g. 02:10):  10 2 * * *  php /path/to/scripts/ai-reflect.php
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
$v = rh_ai_vault_sweep($pdo);
rh_ai_job_record($pdo, 'vault', (bool)$v['ok'], (string)$v['note']);
echo 'Vault sweep: ' . $v['note'] . "\n";
$r = rh_ai_reflect_run($pdo);
rh_ai_job_record($pdo, 'reflect', (bool)$r['ok'], (string)$r['note']);
echo 'Review: ' . $r['note'] . "\n";
exit($r['ok'] ? 0 : 1);
