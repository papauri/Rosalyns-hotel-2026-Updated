<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Optional retention purge for the staff Assistant (.claude/AI_ASSISTANT_PLAN.md Phase 5).
 * Removes Assistant rows older than "Keep chats for" (setting ai_retention_days) from the Assistant's own tables
 * only (rh_ai_retention_tables()); learned memory, faults, settings and every business table are never touched.
 * Does nothing while ai_retention_days is 0. The daily trigger already runs it; this script is for a manual run.
 *
 * Usage: php scripts/ai-retention.php
 */
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/ai/ai-core.php';
if (!rh_ai_enabled($pdo)) {
    echo "Assistant is off - nothing to do.\n";
    exit(0);
}
require_once $root . '/includes/ai/ai-extras.php';
$r = rh_ai_retention_run($pdo);
echo $r['note'] . "\n";
foreach ((array)($r['removed'] ?? []) as $t => $n) {
    echo "  $t: $n\n";
}
exit(!empty($r['ok']) ? 0 : 1);
