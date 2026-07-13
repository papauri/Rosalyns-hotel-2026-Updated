<?php
/**
 * scripts/guest_lifecycle_emails.php
 *
 * Guest communication lifecycle sweep: pre-arrival reminder + post-stay
 * review request. Settings live on Admin → Booking Settings (Guest
 * communication emails card; booking_prearrival_reminder_enabled /
 * booking_prearrival_reminder_days / booking_poststay_review_enabled /
 * booking_poststay_review_days). Idempotent via guest_communication_log —
 * one email per booking per stage, ever (admin/includes/guest-lifecycle-lib.php).
 *
 * Run from cron once daily:
 *   10 8 * * *  /usr/bin/php /path/to/scripts/guest_lifecycle_emails.php >> /path/to/logs/guest-lifecycle.log 2>&1
 *
 * Windows Task Scheduler equivalent:
 *   Program/script:  C:\path\to\php.exe
 *   Arguments:        C:\path\to\scripts\guest_lifecycle_emails.php --quiet
 *   Trigger:          Daily, e.g. 08:10
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
require_once __DIR__ . '/../admin/includes/guest-lifecycle-lib.php';

// Prevent overlapping runs (cron + manual trigger) from double-processing.
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rh_guest_lifecycle.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) echo "Another guest lifecycle run is in progress — exiting.\n";
    exit(0);
}

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) echo $line . "\n";
};

$say('[' . date('Y-m-d H:i:s') . '] Guest lifecycle email sweep starting...');

/** @var PDO $pdo */
$hadErrors = false;

$preArrival = guest_run_prearrival_reminders($pdo);
if ($preArrival['disabled']) {
    $say('Pre-arrival reminders disabled (module off or booking_prearrival_reminder_enabled=0) — nothing to do.');
} else {
    $say("Pre-arrival — due: {$preArrival['checked']}  sent: {$preArrival['sent']}  skipped: {$preArrival['skipped']}");
    foreach ($preArrival['errors'] as $err) {
        echo "  ERR (pre-arrival) {$err}\n";
    }
    if (!empty($preArrival['errors'])) $hadErrors = true;
}

$postStay = guest_run_poststay_review_requests($pdo);
if ($postStay['disabled']) {
    $say('Post-stay review requests disabled (module off or booking_poststay_review_enabled=0) — nothing to do.');
} else {
    $say("Post-stay  — due: {$postStay['checked']}  sent: {$postStay['sent']}  skipped: {$postStay['skipped']}");
    foreach ($postStay['errors'] as $err) {
        echo "  ERR (post-stay) {$err}\n";
    }
    if (!empty($postStay['errors'])) $hadErrors = true;
}

$say('[' . date('Y-m-d H:i:s') . '] Done.');
flock($lock, LOCK_UN);
exit($hadErrors ? 1 : 0);
