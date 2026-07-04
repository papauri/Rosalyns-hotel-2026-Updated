<?php
/**
 * scripts/gym_membership_reminders.php
 *
 * Email active gym members whose membership expires within the admin-chosen
 * reminder window (Gym Members page → Renewal Reminders strip; settings
 * gym_reminder_enabled / gym_reminder_days). Idempotent via gym_reminder_log
 * — one reminder per member per expiry date, ever.
 *
 * Run from cron once daily:
 *   15 8 * * *  /usr/bin/php /path/to/scripts/gym_membership_reminders.php >> /path/to/logs/gym-reminders.log 2>&1
 *
 * Flags: --quiet  only prints on error (cron-friendly).
 *
 * CLI-only by default. Refuses to run from a web request unless ?web=1 with
 * a valid admin/manager session is supplied (same guard as the other sweeps).
 */

declare(strict_types=1);

$quiet = in_array('--quiet', $argv ?? [], true);

if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $__webRole = $_SESSION['admin_role'] ?? '';
    if (
        empty($_SESSION['admin_user_id']) ||
        !in_array($__webRole, ['admin', 'manager'], true) ||
        ($_GET['web'] ?? '') !== '1'
    ) {
        http_response_code(403);
        echo 'Forbidden — CLI only.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    $quiet = false;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../admin/includes/gym-reminders-lib.php';
require_once __DIR__ . '/../admin/includes/gym-checkin-lib.php';

// Prevent overlapping runs (cron + manual button) from double-processing.
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_gym_reminders.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) echo "Another reminder run is in progress — exiting.\n";
    exit(0);
}

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) echo $line . "\n";
};

$say('[' . date('Y-m-d H:i:s') . '] Gym membership reminder sweep starting...');

/** @var PDO $pdo */
// End-of-day attendance sweep first: auto-check-out anyone still marked
// "in gym" from a previous day (forgot to scan out) at the configured
// closing time — keeps durations honest and next-day scans seamless.
$staleClosed = gym_auto_checkout_stale($pdo);
if ($staleClosed > 0) {
    $say("Auto-checked-out $staleClosed stale visit(s) from previous days.");
}

$result = gym_run_expiry_reminders($pdo);

if ($result['pending_migration']) {
    echo "gym_reminder_log table missing — run admin/migrations/2026_07_04_gym_reminder_log.sql first.\n";
    flock($lock, LOCK_UN);
    exit(1);
}
if ($result['disabled']) {
    $say('Reminders disabled (module off or gym_reminder_enabled=0) — nothing to do.');
    flock($lock, LOCK_UN);
    exit(0);
}

$say("Due within window: {$result['checked']}  sent: {$result['sent']}  skipped(already reminded): {$result['skipped']}");
foreach ($result['errors'] as $err) {
    echo "  ERR {$err}\n";
}

$say('[' . date('Y-m-d H:i:s') . '] Done.');
flock($lock, LOCK_UN);
exit(empty($result['errors']) ? 0 : 1);
