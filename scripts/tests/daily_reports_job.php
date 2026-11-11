<?php
/**
 * Pure (no-DB, no e-mail) tests: the Daily Operations Report on the scheduler timer (owner 2026-11-11
 * "the daily station reports should be on a timer").
 *  1. Job registration + default (toggle on, 60 min, bcc global, before the backup, after the stock sweep),
 *     master switch off = only the backup runs.
 *  2. Dedupe key = the trading date, shared by the job and the CLI; fits automated_email_log columns.
 *  3. Ended-day choice: across midnight and with a late-closing station, every dated section = the ended day.
 *  4. No recipients / bad test address -> skip with a plain note (never the real recipients in test mode).
 *  5. Recipients = the End of Day report list with its fallbacks (one helper, also used by end-of-day-send.php).
 *  6. Wiring: one build/send path, CLI claims the same row, --force logged, budget before claim.
 *
 *   php scripts/tests/daily_reports_job.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$root = dirname(__DIR__, 2);
require_once $root . '/includes/auto-scheduler.php';       // defines functions only
require_once $root . '/includes/daily-reports-send.php';   // pure at include
require_once $root . '/admin/includes/pos-shift-totals.php'; // pure helpers only at include

$P = 0; $F = 0;
function dr_check(bool $c, string $label, $detail = ''): void
{
    global $P, $F;
    if ($c) { $P++; echo "  [PASS] $label\n"; return; }
    $F++; echo "  [FAIL] $label" . ($detail !== '' ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function dr_src(string $rel): string
{
    return str_replace("\r\n", "\n", (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel));
}

echo "--- 1. Job registration ---\n";
$jobs = rh_scheduler_jobs();
$j = $jobs['daily_reports'] ?? [];
dr_check(($j['label'] ?? '') === 'Daily station reports email' && ($j['fn'] ?? '') === 'rh_job_daily_reports', 'job registered with its label and function', $j);
dr_check(($j['toggle'] ?? '') === 'automated_job_daily_reports' && ($j['toggle_default'] ?? '') === '1' && ($j['interval'] ?? 0) === 60
    && ($j['legacy'] ?? true) === false && ($j['bcc'] ?? '') === 'global', 'toggle, default on, every 60 min, not legacy, bcc global', $j);
dr_check((rh_auto_defaults()['automated_job_daily_reports'] ?? null) === '1', 'default in rh_auto_defaults()');
$keys = array_keys($jobs);
$pos = array_flip($keys);
dr_check(end($keys) === 'nightly_backup' && $pos['daily_reports'] === $pos['stock_expiry_sweep'] + 1, 'placed after the stock sweep and before the backup (backup stays last)', $keys);
$sch = dr_src('includes/auto-scheduler.php');
dr_check(substr_count($sch, "\$opts['only_jobs'] = ['nightly_backup'];") === 2, 'master switch off: only the backup runs (the report waits like the other email jobs)');
$jobsSrc = dr_src('includes/auto-email-jobs.php');
dr_check(strpos($jobsSrc, 'function rh_job_daily_reports(PDO $pdo, array $o = []): array') !== false, 'job function exists with the scheduler signature');
dr_check(strpos(dr_src('admin/automated-emails.php'), 'Daily station reports email sends the bookings, restaurant/till, accounting and stock reports once a day') !== false
    && strpos(dr_src('admin/automated-emails.php'), 'foreach ($jobs as $jobName => $job)') !== false, 'Automated Emails screen lists the job (registry loop) with a helper line');

echo "\n--- 2. Dedupe key = trading date ---\n";
$k = rh_daily_reports_key('2026-11-11');
dr_check($k === ['job' => 'daily_reports', 'type' => 'trading_day', 'id' => 20261111, 'stage' => 'day:2026-11-11'], 'key: job / trading_day / 20261111 / day:2026-11-11', $k);
$kt = rh_daily_reports_key('2026-11-11', true);
dr_check($kt['stage'] === 'test:day:2026-11-11' && $kt['id'] === 20261111, 'test mode uses a test: stage, so a test never blocks the real send', $kt);
dr_check(strlen($kt['stage']) <= 40 && strlen($kt['type']) <= 30 && strlen($kt['job']) <= 60, 'fits automated_email_log (stage 40, account_type 30, job 60)');
dr_check(rh_daily_reports_key('2026-11-12') !== $k, 'a new trading day is a new key');

$tz = new DateTimeZone('Africa/Blantyre');
$win6 = static function (string $d) use ($tz): array { return ['trading_end' => (new DateTimeImmutable($d . ' 06:00:00', $tz))->modify('+1 day')]; };
$one = [['email' => 'gm@example.com', 'name' => '']];
$pA = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 06:05:00', $tz), $one, '', false, $win6);
$pB = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 23:50:00', $tz), $one, '', false, $win6);
dr_check($pA['key'] === $pB['key'] && $pA['key']['stage'] === 'day:2026-11-11', 'every run during the 12th claims the same row (the 11th): re-runs and parallel loads send once', [$pA['key'], $pB['key']]);

echo "\n--- 3. Ended trading day (all dated sections) ---\n";
$dates = static fn(array $p): array => [$p['date'], $p['start'], $p['end'], $p['station_start'], $p['station_end']];
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 00:05:00', $tz), $one, '', false, $win6);
dr_check($dates($p) === array_fill(0, 5, '2026-11-10') && $p['action'] === 'send', 'just after midnight: never the new empty 12th; the 11th still trades until 06:00, so the ended day is the 10th', $dates($p));
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 05:59:59', $tz), $one, '', false, $win6);
dr_check($p['date'] === '2026-11-10', 'one second before opening: the 11th has not ended', $p['date']);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 06:00:00', $tz), $one, '', false, $win6);
dr_check($dates($p) === array_fill(0, 5, '2026-11-11'), 'at the 06:00 opening: bookings, station, accounting all = the 11th', $dates($p));
// Late-closing bar (10:00 - 03:00 +1) beside the kitchen (06:00 - 22:00): the union trading day ends at the
// earliest next opening (kitchen 06:00), so a bar closing at 03:00 never makes the day "end" early or late.
$open = ['kitchen' => '06:00', 'bar' => '10:00'];
$winUnion = static function (string $d) use ($tz, $open): array {
    $ends = [];
    foreach ($open as $o) {
        $ends[] = (new DateTimeImmutable($d . ' ' . $o . ':00', $tz))->modify('+1 day');
    }
    return ['trading_end' => min($ends)];
};
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 03:30:00', $tz), $one, '', false, $winUnion);
dr_check($p['date'] === '2026-11-10', 'late bar closed at 03:00: the 11th is still not ended at 03:30 (next opening 06:00)', $p['date']);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 06:30:00', $tz), $one, '', false, $winUnion);
dr_check($p['date'] === '2026-11-11', 'late bar: the 11th ends at the kitchen opening on the 12th', $p['date']);
$barOnly = static function (string $d) use ($tz): array { return ['trading_end' => (new DateTimeImmutable($d . ' 10:00:00', $tz))->modify('+1 day')]; };
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 09:00:00', $tz), $one, '', false, $barOnly);
$p2 = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 10:00:00', $tz), $one, '', false, $barOnly);
dr_check($p['date'] === '2026-11-10' && $p2['date'] === '2026-11-11', 'bar-only hotel (10:00 - 03:00): the 11th is sent from the 10:00 opening on the 12th', [$p['date'], $p2['date']]);

echo "\n--- 4. No recipients / test mode ---\n";
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), [], '', false, $win6);
dr_check($p['action'] === 'skip' && strpos($p['note'], 'End of Day report recipients') !== false && $p['key']['stage'] === 'day:2026-11-11', 'no recipients: skip with a plain note (no error)', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), $one, 'qa@example.com', true, $win6);
dr_check($p['action'] === 'send' && $p['recipients'] === [['email' => 'qa@example.com', 'name' => '']] && $p['subject_prefix'] === '[TEST] ' && $p['key']['stage'] === 'test:day:2026-11-11', 'test mode: only the test address, [TEST] subject, test: stage', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), $one, 'not-an-email', true, $win6);
dr_check($p['action'] === 'skip' && $p['recipients'] === [], 'test mode with a bad test address fails closed (never the real recipients)', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), [], 'qa@example.com', true, $win6);
dr_check($p['action'] === 'send' && $p['recipients'][0]['email'] === 'qa@example.com', 'test mode sends to the test address even with no real recipients');
$skipAt = strpos($jobsSrc, "rh_auto_log_skip(\$pdo, \$k['job']");
$budgetAt = strpos($jobsSrc, 'if (!rh_scheduler_budget_ok()) {', (int)$skipAt);
$claimAt = strpos($jobsSrc, "rh_auto_log_claim(\$pdo, \$k['job']");
$sendAt = strpos($jobsSrc, 'rh_daily_reports_build_and_send($plan[\'recipients\']');
dr_check($skipAt !== false && $budgetAt !== false && $claimAt !== false && $sendAt !== false && $skipAt < $budgetAt && $budgetAt < $claimAt && $claimAt < $sendAt,
    'job order: skip (logged) -> budget -> claim -> build/send');

echo "\n--- 5. Recipients (End of Day list + fallbacks) ---\n";
$mk = static fn(array $s): callable => static fn(string $key): string => (string)($s[$key] ?? '');
$r = rh_eod_report_recipients($mk(['eod_report_recipients' => 'a@x.com; b@y.com c@z.com, bad']), $mk(['email_admin_email' => 'admin@h.com']));
dr_check(array_column($r, 'email') === ['a@x.com', 'b@y.com', 'c@z.com'], 'eod_report_recipients split on , ; and spaces, bad entries dropped', $r);
$r = rh_eod_report_recipients($mk([]), $mk(['email_admin_email' => 'admin@h.com', 'email_from_email' => 'from@h.com']));
dr_check(array_column($r, 'email') === ['admin@h.com'], 'fallback 1: admin email (email_settings)', $r);
$r = rh_eod_report_recipients($mk([]), $mk(['email_from_email' => 'from@h.com']));
dr_check(array_column($r, 'email') === ['from@h.com'], 'fallback 2: from email', $r);
$r = rh_eod_report_recipients($mk(['eod_report_recipients' => 'nonsense']), $mk(['email_admin_email' => 'admin@h.com']));
dr_check($r === [], 'a set but unusable list does not fall back (same as the End of Day button)', $r);
$eod = dr_src('admin/api/end-of-day-send.php');
dr_check(strpos($eod, '$recipients = rh_eod_report_recipients();') !== false && strpos($eod, 'recipientsCsv') === false
    && strpos($eod, 'No admin recipient configured. Add an email to site setting "eod_report_recipients" or "email_admin_email".') !== false, 'End of Day e-mail uses the same helper; its error message is unchanged');

echo "\n--- 6. Wiring ---\n";
$cli = dr_src('scripts/daily_reports.php');
$send = dr_src('includes/daily-reports-send.php');
dr_check(substr_count($send, 'rh_report_section(') === 4 && strpos($cli, 'rh_report_section(') === false
    && strpos($cli, 'rh_daily_reports_build_and_send(') !== false && strpos($jobsSrc, 'rh_daily_reports_build_and_send(') !== false, 'one build + send path: CLI and job both call rh_daily_reports_build_and_send');
dr_check(strpos($cli, "if (!\$stationExplicit) {\n    require_once __DIR__ . '/../includes/auto-scheduler.php';\n    \$key = rh_daily_reports_key(\$stationStart);") !== false
    && strpos($cli, "rh_auto_log_claim(\$pdoLog, \$key['job'], \$key['type'], \$key['id'], \$key['stage']") !== false, 'CLI default run claims the same trading-day row as the job');
dr_check(strpos($cli, "rh_log_event('daily_reports', 'warning', 'Daily report sent again with --force") !== false && strpos($cli, 'use --force to send again') !== false, 'CLI: already sent -> nothing sent (exit 0) unless --force, which is logged');
dr_check(strpos($cli, "rh_auto_log_finish(\$pdoLog, \$claimKey['job']") !== false, 'CLI records sent / failed on the claimed row');
dr_check(strpos($send, "if (\$echo) {\n                fwrite(STDERR") !== false && strpos($send, "PHP_SAPI === 'cli'") !== false, 'STDERR / progress lines only on the CLI (web runs never touch STDERR)');
$rb = dr_src('includes/report-builders.php');
dr_check(strpos($rb, "if (!function_exists('rh_table_exists')) {") !== false && strpos($rb, "if (!function_exists('rh_column_exists')) {") !== false,
    'report builders guard the helpers procurement-schema.php also defines (no redeclare when the timer runs after a stock page)');
dr_check(strpos($send, 'require_once __DIR__ . \'/report-builders.php\';') > strpos($send, 'function rh_daily_reports_build_and_send('), 'builders / mailer load only inside the send function (including the file connects to nothing)');

echo "\n=== daily_reports_job: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
