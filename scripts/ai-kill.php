<?php
/**
 * The Assistant's command-line switch (.claude/AI_ASSISTANT_PLAN.md "Kill switches" 5), for when the portal
 * itself cannot be reached.
 *
 *   php scripts/ai-kill.php off      switch the Assistant off for everyone (setting + module row)
 *   php scripts/ai-kill.php on       switch it back on (also lifts an automatic pause)
 *   php scripts/ai-kill.php status   show whether it is on, off or paused
 *
 * Switching off never deletes chats, memory, keys or settings; switching on resumes where it was.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/ai/ai-core.php';

$cmd = strtolower((string)($argv[1] ?? 'status'));
if (!in_array($cmd, ['on', 'off', 'status'], true)) {
    echo "Usage: php scripts/ai-kill.php on|off|status\n";
    exit(2);
}
if (!rh_ai_schema_ready($pdo)) {
    echo "The database update for the Assistant has not been run - it is off.\n";
    exit($cmd === 'on' ? 1 : 0);
}
if ($cmd === 'status') {
    echo rh_ai_enabled($pdo) ? "The Assistant is ON.\n" : 'The Assistant is OFF. ' . rh_ai_off_reason($pdo) . "\n";
    exit(0);
}
rh_ai_set_master($cmd === 'on', $pdo);
if (function_exists('rh_log_event')) {
    rh_log_event('assistant', 'warning', 'Assistant switched ' . $cmd . ' from the command line (scripts/ai-kill.php)');
}
echo 'The Assistant is now ' . ($cmd === 'on' ? 'ON' : 'OFF') . ".\n";
if ($cmd === 'on' && rh_ai_active_provider() === '') {
    echo "Note: no models are chosen yet, so only quick local answers will work. Finish Assistant Settings.\n";
}
exit(0);
