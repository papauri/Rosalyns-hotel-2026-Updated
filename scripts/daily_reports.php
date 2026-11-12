<?php
/**
 * Daily reports CLI: manual runs only. There is no cron at either hotel (owner 2026-11-12); the daily email goes
 * out by itself from the in-app timer (scheduler job 'daily_reports', Settings > Automated Emails).
 *
 *   php scripts/daily_reports.php --recipients=a@x.com,b@y.com --start=YYYY-MM-DD --end=YYYY-MM-DD [--force]
 *
 * Defaults (= the timer, owner 2026-11-13): every dated section = the last trading day that has ENDED, plus that
 * day's End of Day PDF (--date / --start / --end pick the days of the dated reports). Recipients: env
 * REPORT_RECIPIENTS (or fail); test mode (Automated Emails > Test recipient set) sends ONLY to the test address.
 * Up to four attachments (bookings, station-day, accounting, stock-health): a section that fails is logged and
 * named as "could not be loaded" in the e-mail, and the rest are still sent.
 * The build + send is shared with the scheduler job 'daily_reports' (includes/daily-reports-send.php). A default
 * run (no --date / --start / --end) claims the same once-per-trading-day row as the scheduler, so a manual run
 * and the timer never both send one day; --force sends again anyway (logged).
 * Exit codes: 0 sent (or already sent for that trading day); 1 every section failed (the e-mail saying so is
 * still attempted); 2 no recipients; 3 send failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

require __DIR__ . '/../includes/report-builders.php';
require __DIR__ . '/../includes/report-mailer.php';
require_once __DIR__ . '/../includes/daily-reports-send.php';

/* ---------------- args ---------------- */
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $arg, $m)) {
        $args[$m[1]] = '1';
    }
}

$force = isset($args['force']);

require_once __DIR__ . '/../includes/station-hours.php';
require_once __DIR__ . '/../admin/includes/pos-shift-totals.php';

// Default run = exactly what the timer sends (L9, owner 2026-11-13): EVERY dated section is the trading day that has
// ENDED (opening -> next opening), never today's half day, plus that day's End of Day PDF. --date / --start / --end
// pick days by hand (a resend: no claim, no End of Day PDF unless one single day is picked).
$stationExplicit = isset($args['start']) || isset($args['end']) || isset($args['date']);
$endedDay = date('Y-m-d', strtotime('-1 day'));
try {
    $endedDay = rh_pos_last_ended_trading_date(new DateTimeImmutable('now', rh_site_timezone()));
} catch (Throwable $e) {
    if (function_exists('rh_log_event')) {
        rh_log_event('daily_reports', 'error', 'Daily report section could not be built: station-day (day)', ['error' => $e->getMessage()]);
    }
    fwrite(STDERR, '  ! station-day (day): ' . $e->getMessage() . "\n");
}
$start = $args['start'] ?? ($args['date'] ?? $endedDay);
$end   = $args['end']   ?? ($args['date'] ?? $endedDay);
$stationStart = $start;
$stationEnd = $end;

$recipients = rh_daily_reports_parse_recipients($args['recipients'] ?? (string)getenv('REPORT_RECIPIENTS'));
// Test mode (Automated Emails > Test recipient set): ONLY the test address, [TEST], no copies, 'test:' claim (B3).
$testTo = rh_report_test_recipient();
$route = rh_report_route($recipients, [], 'x', $testTo);
if ($route['action'] !== 'send') {
    fwrite(STDERR, 'ERROR: ' . $route['note'] . "\n");
    exit(2);
}
$recipients = $route['recipients'];
if (!$recipients) {
    fwrite(STDERR, "ERROR: No valid --recipients=email,email provided.\n");
    exit(2);
}

echo "Daily reports — period {$start} to {$end}" . ($route['test'] ? ' (TEST MODE)' : '') . "\n";
echo 'Recipients: ' . implode(', ', array_column($recipients, 'email')) . "\n\n";

/* ---------------- once per trading day (shared with the scheduler) ---------------- */
// Only a default run claims (it sends the same day the timer would). A run with explicit dates is a hand-picked resend.
$claimKey = null;
$pdoLog = $GLOBALS['pdo'] ?? null;
if (!$stationExplicit) {
    require_once __DIR__ . '/../includes/auto-scheduler.php';
    $key = rh_daily_reports_key($stationStart, $route['test']);
    if ($pdoLog instanceof PDO && rh_auto_log_table_ready($pdoLog)) {
        $emails = implode(', ', array_column($recipients, 'email'));
        if (rh_auto_log_claim($pdoLog, $key['job'], $key['type'], $key['id'], $key['stage'], $emails, 'Daily Operations Report (CLI) - trading day ' . $stationStart)) {
            $claimKey = $key;
        } elseif ($force) {
            if (function_exists('rh_log_event')) {
                rh_log_event('daily_reports', 'warning', 'Daily report sent again with --force for trading day ' . $stationStart, ['recipients' => $emails]);
            }
            echo "--force: trading day {$stationStart} was already sent; sending again.\n";
        } else {
            echo "Trading day {$stationStart} has already been sent (by the timer or an earlier run). Nothing sent; use --force to send again.\n";
            exit(0);
        }
    } else {
        fwrite(STDERR, "WARNING: automated_email_log is not available; sending without the once-per-day check.\n");
    }
}

/* ---------------- build + send ---------------- */
$out = rh_report_with_test_ctx($route['test'] ? $testTo : '', static fn(): array => rh_daily_reports_build_and_send(
    $recipients, $start, $end, $stationStart, $stationEnd, $stationExplicit,
    ['echo' => true, 'cc' => $route['test'] ? [] : rh_daily_reports_cc_all($recipients),
     'subject_prefix' => $route['test'] ? '[TEST] ' : '',
     'eod' => $start === $end ? ['date' => $start, 'by' => 'System (command line)'] : []]));
$result = $out['result'];

if ($claimKey !== null) {
    try {
        rh_auto_log_finish($pdoLog, $claimKey['job'], $claimKey['type'], $claimKey['id'], $claimKey['stage'],
            !empty($result['success']) ? 'sent' : 'failed', !empty($result['success']) ? '' : (string)($result['message'] ?? 'send failed'));
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARNING: could not record the send: ' . $e->getMessage() . "\n");
    }
}

echo "\nMail send result: " . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";

if (!$out['any_ok']) {
    fwrite(STDERR, "ERROR: every report section failed (see the system log).\n");
    exit(1);
}
exit($result['success'] ? 0 : 3);
