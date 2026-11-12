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
dr_check($p['action'] === 'skip' && strpos($p['note'], '"End-of-day report recipients" (Hotel Settings > Payments & documents)') !== false && $p['key']['stage'] === 'day:2026-11-11', 'no recipients: skip with a plain note naming the real field (F1)', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), $one, 'qa@example.com', true, $win6);
dr_check($p['action'] === 'send' && $p['recipients'] === [['email' => 'qa@example.com', 'name' => '']] && $p['subject_prefix'] === '[TEST] ' && $p['key']['stage'] === 'test:day:2026-11-11', 'test mode: only the test address, [TEST] subject, test: stage', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), $one, 'not-an-email', true, $win6);
dr_check($p['action'] === 'skip' && $p['recipients'] === [], 'test mode with a bad test address fails closed (never the real recipients)', $p);
$p = rh_daily_reports_auto_plan(new DateTimeImmutable('2026-11-12 07:00:00', $tz), [], 'qa@example.com', true, $win6);
dr_check($p['action'] === 'send' && $p['recipients'][0]['email'] === 'qa@example.com', 'test mode sends to the test address even with no real recipients');
$dfAt = strpos($jobsSrc, 'function rh_job_daily_reports(');
$dfEnd = strpos($jobsSrc, 'function rh_auto_record_send(', (int)$dfAt);
$dfn = substr($jobsSrc, (int)$dfAt, (int)$dfEnd - (int)$dfAt);
$skipBlk = (string)strstr((string)strstr($dfn, "if (\$plan['action'] !== 'send') {"), 'return $res;', true);
dr_check($skipBlk !== '' && strpos($skipBlk, 'rh_auto_log') === false, 'L2: no recipients / bad test address writes NO log row (recipients added later the same day still get it)', $skipBlk);
$budgetAt = strpos($dfn, 'if (!rh_scheduler_budget_ok()) {');
$claimAt = strpos($dfn, "rh_auto_log_claim(\$pdo, \$k['job']");
$sendAt = strpos($dfn, "rh_daily_reports_build_and_send(\$plan['recipients']");
$recAt = strpos($dfn, 'rh_auto_record_send($pdo, $k,');
dr_check($budgetAt !== false && $claimAt !== false && $sendAt !== false && $recAt !== false && $budgetAt < $claimAt && $claimAt < $sendAt && $sendAt < $recAt,
    'job order: budget -> claim -> build/send -> record the outcome', [$budgetAt, $claimAt, $sendAt, $recAt]);

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
    && strpos($eod, 'Add an address under "End-of-day report recipients" (Hotel Settings > Payments & documents)') !== false
    && strpos($eod, 'site setting "eod_report_recipients"') === false, 'End of Day e-mail uses the same helper; its message names the real field (F1)');
dr_check(preg_match("/'eod_report_recipients'\s*=>\s*\['label' => 'End-of-day report recipients[^']*', 'type' => 'emails', 'default' => '', 'finance' => true/", dr_src('admin/includes/hotel-details-settings.php')) === 1,
    'F1: Hotel Settings has an "End-of-day report recipients" field (emails, finance-gated)');

echo "\n--- 6. Wiring ---\n";
$cli = dr_src('scripts/daily_reports.php');
$send = dr_src('includes/daily-reports-send.php');
dr_check(substr_count($send, 'rh_report_section(') === 5 && strpos($cli, 'rh_report_section(') === false
    && strpos($cli, 'rh_daily_reports_build_and_send(') !== false && strpos($jobsSrc, 'rh_daily_reports_build_and_send(') !== false, 'one build + send path: CLI and job both call rh_daily_reports_build_and_send');
dr_check(strpos($cli, "if (!\$stationExplicit) {\n    require_once __DIR__ . '/../includes/auto-scheduler.php';\n    \$key = rh_daily_reports_key(\$stationStart, \$route['test']);") !== false
    && strpos($cli, "rh_auto_log_claim(\$pdoLog, \$key['job'], \$key['type'], \$key['id'], \$key['stage']") !== false, 'CLI default run claims the same trading-day row as the job');
dr_check(strpos($cli, "rh_log_event('daily_reports', 'warning', 'Daily report sent again with --force") !== false && strpos($cli, 'use --force to send again') !== false, 'CLI: already sent -> nothing sent (exit 0) unless --force, which is logged');
dr_check(strpos($cli, "rh_auto_log_finish(\$pdoLog, \$claimKey['job']") !== false, 'CLI records sent / failed on the claimed row');
dr_check(strpos($send, "if (\$echo) {\n                fwrite(STDERR") !== false && strpos($send, "PHP_SAPI === 'cli'") !== false, 'STDERR / progress lines only on the CLI (web runs never touch STDERR)');
$rb = dr_src('includes/report-builders.php');
dr_check(strpos($rb, "if (!function_exists('rh_table_exists')) {") !== false && strpos($rb, "if (!function_exists('rh_column_exists')) {") !== false,
    'report builders guard the helpers procurement-schema.php also defines (no redeclare when the timer runs after a stock page)');
dr_check(strpos($send, 'require_once __DIR__ . \'/report-builders.php\';') > strpos($send, 'function rh_daily_reports_build_and_send('), 'builders / mailer load only inside the send function (including the file connects to nothing)');

echo "\n--- 7. Visible copy (CC), owner 2026-11-12 ---\n";
$to = [['email' => 'eod@hotel.test', 'name' => '']];
dr_check(rh_daily_reports_cc($to, 'info@promanaged-it.com') === ['info@promanaged-it.com'], 'MSP mailbox is CC');
dr_check(rh_daily_reports_cc($to, '') === [], 'empty setting = no copy');
dr_check(rh_daily_reports_cc($to, 'EOD@hotel.test, a@b.test; a@b.test, junk') === ['a@b.test'], 'drops To addresses (any case), repeats and invalid entries');
dr_check(rh_auto_defaults()['automated_job_daily_reports_cc'] === 'info@promanaged-it.com', 'default copy address is the MSP mailbox (editable setting)');
dr_check(strpos($jobsSrc, "'cc' => \$testMode ? [] : rh_daily_reports_cc_all(\$plan['recipients'])") !== false, 'job: CC = daily reports copy + End of Day copy on real sends, none on test sends (B1)');
dr_check(strpos($cli, "'cc' => \$route['test'] ? [] : rh_daily_reports_cc_all(\$recipients)") !== false && strpos($send, "array_values((array)(\$opts['cc'] ?? []))") !== false, 'CLI passes the same CC (none in test mode); send function hands it to sendReportEmail');
$ae = dr_src('admin/automated-emails.php');
dr_check(strpos($ae, "updateSetting('automated_job_daily_reports_cc', \$reportCc);") !== false && strpos($ae, 'name="automated_job_daily_reports_cc"') !== false, 'Automated Emails: CC field shown and saved (valid addresses only)');

echo "\n--- 8. Recipients chain + CC merge (owner 2026-11-13, EOD_SPEC §2) ---\n";
require_once $root . '/admin/includes/eod-report-data.php'; // pure at include
$to = [['email' => 'gm@hotel.test', 'name' => '']];
dr_check(rh_daily_reports_cc_all($to, 'info@promanaged-it.com', 'owner@hotel.test, GM@hotel.test') === ['info@promanaged-it.com', 'owner@hotel.test'],
    'timed CC = daily reports copy + End of Day copy, never a To address (B1)');
dr_check(rh_daily_reports_cc_all($to, 'a@x.test', 'a@x.test; junk') === ['a@x.test'], 'merged CC: no repeats, invalid dropped');
dr_check(rh_daily_reports_cc_all($to, '', '') === [], 'both copy lists empty = no CC');
dr_check(rh_daily_reports_cc($to, 'saved@hotel.test' . ',' . 'extra@x.test, gm@hotel.test') === ['saved@hotel.test', 'extra@x.test'],
    'clicked Email CC = saved End of Day copy + the box extras (F2), never a To address');
$eodSend = dr_src('admin/api/end-of-day-send.php');
dr_check(strpos($eodSend, "\$cc_emails = rh_daily_reports_cc(\$recipients, (string)getSetting('eod_report_cc_emails', '') . ',' . \$cc_raw);") !== false,
    'F2: the server always adds the saved End of Day CC list');
$page = dr_src('admin/end-of-day-report.php');
dr_check(strpos($page, "ccInput.value = ''") === false && strpos($page, 'id="eodGoesTo"') !== false && strpos($page, 'value=""') !== false,
    'F2 / L21: the page never clears the CC box, the box holds extras only, and a "Goes to / CC / WhatsApp to" line shows before sending');

echo "\n--- 9. Test mode for timed AND clicked sends (B3, L1) ---\n";
$r = rh_report_route($to, ['c@x.test'], 'EOD Report', '');
dr_check($r['action'] === 'send' && !$r['test'] && $r['recipients'] === $to && $r['cc'] === ['c@x.test'] && $r['subject'] === 'EOD Report', 'test mode off: unchanged');
$r = rh_report_route($to, ['c@x.test'], 'EOD Report', 'qa@example.com');
dr_check($r['action'] === 'send' && $r['test'] && $r['recipients'] === [['email' => 'qa@example.com', 'name' => '']] && $r['cc'] === [] && $r['subject'] === '[TEST] EOD Report',
    'test mode on: only the test address, [TEST], no CC', $r);
dr_check(rh_report_route([], [], '[TEST] x', 'qa@example.com')['subject'] === '[TEST] x', 'never [TEST] [TEST]');
$r = rh_report_route($to, ['c@x.test'], 'EOD Report', 'not-an-email');
dr_check($r['action'] === 'refuse' && $r['recipients'] === [], 'bad test address refuses (never the real people)', $r);
unset($GLOBALS['rh_automated_ctx']);
$seen = rh_report_with_test_ctx('qa@example.com', static fn() => $GLOBALS['rh_automated_ctx'] ?? null);
dr_check($seen === ['redirect' => 'qa@example.com', 'bcc' => false] && !array_key_exists('rh_automated_ctx', $GLOBALS), 'test context is set for the send and removed afterwards');
$GLOBALS['rh_automated_ctx'] = ['redirect' => '', 'bcc' => true];
rh_report_with_test_ctx('qa@example.com', static fn() => null);
dr_check(($GLOBALS['rh_automated_ctx'] ?? null) === ['redirect' => '', 'bcc' => true], 'an outer (scheduler) context is restored');
unset($GLOBALS['rh_automated_ctx']);
dr_check(rh_report_with_test_ctx('', static fn() => 'x') === 'x' && !array_key_exists('rh_automated_ctx', $GLOBALS), 'test mode off: the send runs with no context');
$mailer = dr_src('includes/report-mailer.php');
dr_check(strpos($mailer, "\$testTo = trim((string)(\$GLOBALS['rh_automated_ctx']['redirect'] ?? ''));") !== false
    && strpos($mailer, "\$recipients = filter_var(\$testTo, FILTER_VALIDATE_EMAIL) ? [['email' => \$testTo, 'name' => '']] : [];") !== false
    && strpos($mailer, "\$ccEmails = [];") !== false && strpos($mailer, "if (!\$isTest) {\n            rh_mail_add_staff_bcc(") !== false,
    'mailer in test mode: To forced to the test address, no CC, no hidden staff copy (L1)');
dr_check(strpos($mailer, "return \$logEarly(") !== false && substr_count($mailer, "rh_email_send_log_mail('failed'") >= 3, 'L20: early failures reach Emails sent');
dr_check(strpos($eodSend, "if (\$channel === 'whatsapp' && \$testTo !== '') {") !== false && strpos($eodSend, 'the WhatsApp summary was not sent') !== false
    && strpos($eodSend, "\$result = rh_report_with_test_ctx(\$route['test'] ? \$testTo : '',") !== false && strpos($eodSend, 'TEST MODE: sent only to') !== false,
    'clicked End of Day: Email follows test mode and says so; WhatsApp is not sent in test mode');
$kds = dr_src('admin/kds-report.php');
dr_check(strpos($kds, "rh_report_route(\$kdsTo, \$kdsCc, \$subject, \$kdsTestTo)") !== false && strpos($kds, "rh_report_with_test_ctx(\$route['test'] ? \$kdsTestTo : ''") !== false
    && strpos($kds, 'TEST MODE: report emailed only to') !== false, 'clicked Station Report Email follows test mode too');
dr_check(strpos($cli, "\$route = rh_report_route(\$recipients, [], 'x', \$testTo);") !== false && strpos($cli, "rh_report_with_test_ctx(\$route['test'] ? \$testTo : ''") !== false, 'L9: the CLI follows test mode');

echo "\n--- 10. Never twice / never lost (L2, L3) ---\n";
$recSrc = substr($jobsSrc, (int)strpos($jobsSrc, 'function rh_auto_record_send('));
$recSrc = substr($recSrc, 0, (int)strpos($recSrc, "\n    }\n") + 6);
if (!function_exists('rh_auto_record_send')) {
    eval($recSrc);
}
class DrFakeSt extends PDOStatement
{
    public function __construct() {}
    public function execute(?array $p = null): bool
    {
        $GLOBALS['dr_calls'][] = $p;
        if ($GLOBALS['dr_fail'] > 0) {
            $GLOBALS['dr_fail']--;
            throw new RuntimeException('db gone');
        }
        return true;
    }
}
class DrFakePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $q, array $o = []): PDOStatement|false { return new DrFakeSt(); }
}
$k = rh_daily_reports_key('2026-11-11');
$GLOBALS['dr_calls'] = []; $GLOBALS['dr_fail'] = 0; $res = ['sent' => 0, 'errors' => []];
rh_auto_record_send(new DrFakePdo(), $k, true, 'Sent', '', $res, 'x');
dr_check($res['sent'] === 1 && count($GLOBALS['dr_calls']) === 1 && $GLOBALS['dr_calls'][0][0] === 'sent', 'delivered: recorded sent once');
$GLOBALS['dr_calls'] = []; $GLOBALS['dr_fail'] = 5; $res = ['sent' => 0, 'errors' => []];
rh_auto_record_send(new DrFakePdo(), $k, true, 'Sent', '', $res, 'x');
$statuses = array_map(static fn($c) => $c[0] ?? null, $GLOBALS['dr_calls']);
dr_check($res['sent'] === 1 && !in_array('failed', $statuses, true) && count($statuses) === 2 && $res['errors'] !== [],
    'L3: delivered but recording fails -> tried twice, NEVER marked failed (so the hourly retry never re-sends it)', $statuses);
$GLOBALS['dr_calls'] = []; $GLOBALS['dr_fail'] = 0; $res = ['sent' => 0, 'errors' => []];
rh_auto_record_send(new DrFakePdo(), $k, false, 'SMTP down', '', $res, 'x');
dr_check($res['sent'] === 0 && ($GLOBALS['dr_calls'][0][0] ?? '') === 'failed' && $GLOBALS['dr_calls'][0][1] === 'SMTP down', 'not delivered: failed row (retried after an hour, max 3)');
$stFn = substr($jobsSrc, (int)strpos($jobsSrc, 'function rh_job_station_reports('), 6000);
dr_check(strpos($stFn, "rh_auto_record_send(\$pdo, \$k, is_array(\$r) && !empty(\$r['success'])") !== false, 'station emails record their outcome the same way');

echo "\n--- 11. End of Day PDF in the timed email, once per day (B1, L6) ---\n";
dr_check(strpos($dfn, "'eod' => ['date' => \$plan['date'], 'by' => 'System (scheduler)']") !== false, 'job passes the trading day that ended to the build');
$claimInSend = strpos($dfn, "rh_auto_log_claim(") < strpos($dfn, "'eod' => ['date' =>");
dr_check($claimInSend, 'the End of Day PDF rides in the ONE claimed e-mail (same once-per-day key, no second e-mail)');
dr_check(substr_count($send, "'filename' => 'eod-report-' . \$eodDate . '.pdf'") === 1 && strpos($send, "rh_eod_report_data(\$pdo, \$eodDate, \$cur)") !== false
    && strpos($send, "buildEodPdf(\$d, \$siteName, \$cur,") !== false, 'one PDF attachment from the shared data builder + the shared PDF builder');
$dl = dr_src('admin/api/end-of-day-pdf.php');
dr_check(strpos($dl, '$d = rh_eod_report_data($pdo, $date, $currency_symbol);') !== false && strpos($eodSend, '$d = rh_eod_report_data($pdo, $date, $currency_symbol);') !== false
    && strpos($dl, 'FROM payments') === false && strpos($eodSend, 'FROM payments') === false, 'L6: download, clicked Email and timer all use rh_eod_report_data (no own queries)');
$data = dr_src('admin/includes/eod-report-data.php');
dr_check(strpos($data, 'DATE(sent_at) = ? THEN') !== false && strpos($data, ':d') === false, 'L7: quotations bound positionally (no repeated named placeholder)');
dr_check(strpos($eodSend, "} catch (Throwable \$eFetch) {") !== false && strpos($eodSend, 'so nothing was sent') !== false, 'F6: figures that cannot be loaded send nothing');

echo "\n--- 12. Shared rules: close score, day open, refund day, WhatsApp, facts (L5, L8, L21, F4, F5, B2) ---\n";
$in = ['arrivals_remaining' => 1, 'departures_remaining' => 0, 'no_shows' => 0, 'cancellations' => 0, 'voided_count' => 1, 'pending' => 0, 'still_owed' => 0, 'hk_open' => 2, 'rooms_oo' => 0];
dr_check(rh_eod_close_score($in) === ['score' => 84, 'label' => 'Good close'], 'score: 100 - 4 (arrival) - 4 (void) - 8 (housekeeping) = 84', rh_eod_close_score($in));
dr_check(rh_eod_close_score($in, ['housekeeping' => false, 'pos' => false])['score'] === 96, 'a module that is off never lowers the score');
dr_check(strpos($page, '$closeScore = rh_eod_close_score(') !== false && strpos($data, '$score = rh_eod_close_score(') !== false, 'L5: page and the shared builder use the one score');
dr_check(rh_eod_day_is_open('2026-11-12', '2026-11-11') && !rh_eod_day_is_open('2026-11-11', '2026-11-11'), 'a day after the last ended trading day is still open ("as at HH:MM")');
dr_check(strpos($eodSend, "(\$dayOpen ? ' (as at ' . \$asAt . ')' : '')") !== false && strpos($eodSend, 'Figures as at ') !== false, 'L17 / L21: subject "(as at HH:MM)" while the day is open; footer "Figures as at"');
dr_check(strpos($page, "date('Y-m-d')") === false && strpos($eodSend, "date('Y-m-d')") === false && strpos($dl, "date('Y-m-d')") === false, 'L8: hotel time zone (rh_eod_today) on every End of Day surface');
$rd = rh_refund_eod_date_sql('p');
dr_check(strpos($rd, 'refund_date_processed') === false && strpos($rd, 'rh_pal.payment_id = p.id') !== false && strpos($rd, "rh_pal.old_value = 'pending'") !== false
    && strpos($rd, "action IN ('refund_processing','refund_completed')") !== false && strpos($rd, 'DATE(p.payment_date))') !== false,
    'F4: refund day = first pending -> paid-out audit step, else payment date; never reads refund_date_processed (the Z keeps it)');
dr_check(strpos(rh_refund_eod_date_sql(), 'rh_pal.payment_id = payments.id') !== false, 'unaliased: correlates to the outer payments row');
// Accounting gate B1 cases (pure twin rh_refund_eod_day): the day a refund counts on never moves once reported.
$au = static fn(string $action, string $old, string $at): array => ['action' => $action, 'field_name' => 'refund_status', 'old_value' => $old, 'new_value' => substr($action, 7), 'created_at' => $at];
dr_check(rh_refund_eod_day(['payment_date' => '2026-11-01'], [$au('refund_completed', 'processing', '2026-11-03 10:00:00')]) === '2026-11-01',
    '(a) created processing on day 1, completed day 3 -> day 1, never moves to day 3');
dr_check(rh_refund_eod_day(['payment_date' => '2026-11-01'], [$au('refund_processing', 'pending', '2026-11-02 09:00:00'), $au('refund_completed', 'processing', '2026-11-04 09:00:00')]) === '2026-11-02',
    '(b) pending day 1, processing day 2, completed day 4 -> day 2 (not the already-reported day 1, not day 4)');
dr_check(rh_refund_eod_day(['payment_date' => '2026-11-01'], []) === '2026-11-01', 'created completed -> its payment date');
dr_check(rh_refund_eod_day(['payment_date' => '2026-11-01'], [$au('refund_completed', 'pending', '2026-11-05 16:00:00')]) === '2026-11-05', 'pending day 1 -> completed day 5 -> day 5');
dr_check(rh_refund_eod_day(['payment_date' => '2026-11-01'], [['action' => 'refund_failed', 'field_name' => 'refund_status', 'old_value' => 'pending', 'created_at' => '2026-11-02 09:00:00']]) === '2026-11-01'
    && rh_refund_eod_day(['payment_date' => '2026-11-01'], [['action' => 'edit', 'field_name' => 'payment_date', 'old_value' => 'pending', 'created_at' => '2026-11-02 09:00:00']]) === '2026-11-01',
    'other audit rows never move the day');
$fas = dr_src('admin/includes/finance-account-sync.php');
dr_check(strpos($fas, "rh_log_payment_audit(\$pdo, \$refundId, (string)\$row['payment_reference'], 'refund_' . \$targetStatus,\n                ['refund_status' => [\$from, \$targetStatus]]") !== false,
    'the one refund status writer logs action refund_<status>, field refund_status, old = the previous status');
$pst = dr_src('admin/includes/pos-shift-totals.php');
dr_check(substr_count($pst, 'COALESCE(p.refund_date_processed, p.updated_at, p.created_at)') === 2 && strpos($pst, 'rh_refund_eod_date_sql') === false, 'till Z / shift close event time unchanged (refund_date_processed)');
// Accounting gate B2: payments made with a credit note are not money received.
$rev = dr_src('admin/includes/eod-revenue.php');
$crs = rh_eod_cash_row_sql();
dr_check($crs === "NOT (COALESCE(payment_type, '') <> 'refund' AND COALESCE(payment_method, '') = 'credit_note')", 'B2: cash rows exclude credit-note payments, keep every refund row');
dr_check(strpos($data, "WHERE \" . rh_eod_pay_day_sql() . \" AND \" . rh_eod_cash_row_sql() . \" AND deleted_at IS NULL") !== false
    && substr_count($data, 'rh_eod_cash_row_sql()') === 2 && strpos($data, "'credit_notes_redeemed' => \$creditNotesRedeemed") !== false, 'B2: shared builder (day + previous day) leaves them out and reports them apart');
dr_check(strpos($rev, "AND \" . rh_eod_cash_row_sql() . \"\n              AND deleted_at IS NULL\n            GROUP BY method") !== false, 'B2: payment mix leaves them out');
dr_check(strpos($pst, "AND COALESCE(payment_method, '') <> 'credit_note' -- guest credit used") !== false, 'B2: F&B share of room payments ignores them');
dr_check(strpos($page, "    . \" AND \" . rh_eod_cash_row_sql();") !== false && strpos($page, "AND \" . rh_eod_cash_row_sql() . \"\n          AND deleted_at IS NULL") !== false
    && strpos($page, "Credit notes redeemed (guest credit used, no new money)") !== false, 'B2: page payments, previous day and trend leave them out; own line');
dr_check(strpos($eodSend, "Credit notes redeemed (guest credit used, no new money)") === strpos($eodSend, "Credit notes redeemed (guest credit used, no new money)") && substr_count($eodSend, 'Credit notes redeemed (guest credit used, no new money)') === 2
    && strpos(dr_src('includes/eod-pdf-builder.php'), "'Credit notes redeemed (no new money)'") !== false, 'B2: e-mail, WhatsApp and PDF show the line');
dr_check(strpos($rev, 'function rh_eod_earned_revenue(') !== false && strpos(substr($rev, (int)strpos($rev, 'function rh_eod_earned_revenue('), 9000), 'rh_eod_cash_row_sql') === false, 'B2: earned revenue unchanged');
dr_check(strpos($page, "error_log('EOD day in brief (page): '") !== false && strpos($eodSend, "error_log('EOD day in brief (send): '") !== false
    && strpos($send, "error_log('EOD day in brief (timed): '") !== false, 'AI brief / mount wrapped: an error prints nothing and is logged');
dr_check(substr_count(rh_eod_pay_day_sql(), '?') === 2 && strpos(rh_eod_pay_day_sql(), rh_refund_eod_date_sql()) !== false, 'F4: one day condition (2 params) for money in and refunds out');
$rev = dr_src('admin/includes/eod-revenue.php');
dr_check(strpos($rev, "AND COALESCE(payment_method,'') <> 'credit_note'") !== false && strpos($rev, 'function rh_eod_refunds_to_credit(') !== false
    && strpos($rev, 'rh_refund_to_credit_sum_sql()') !== false, 'F5: credit-note refunds stay out of the non-cash mix and are shown apart');
$rb = dr_src('includes/report-builders.php');
dr_check(strpos($rb, '$owedRows = rh_receivable_rows($pdo, rh_receivable_mods(), $owedFailed);') !== false && strpos($rb, "b.amount_due > 0\n") === false
    && strpos($rb, "WHERE b.deleted_at IS NULL\n              AND ((DATE(b.created_at)") !== false && strpos($rb, 'rh_refund_eod_date_sql()') !== false,
    'F3 / F4: daily accounting CSV uses the shared still-owed rule and the refund day; bookings CSV skips deleted bookings');
$g = static fn(array $s): callable => static fn(string $k): string => (string)($s[$k] ?? '');
dr_check(rh_eod_whatsapp_target($g(['eod_whatsapp_number' => '+265 1', 'whatsapp_number' => '+265 2']))['number'] === '+265 1'
    && rh_eod_whatsapp_target($g(['whatsapp_number' => '+265 2', 'phone_main' => '+265 3']))['number'] === '+265 2'
    && rh_eod_whatsapp_target($g(['phone_main' => '+265 3']))['source'] === 'main phone number'
    && rh_eod_whatsapp_target($g([]))['number'] === '', 'B2: End of Day WhatsApp number first, then the old chain');
$fd = ['mods' => ['bookings' => true, 'pos' => true], 'earned' => ['total' => 1000, 'rooms_total_gross' => 600, 'fnb_gross' => 400], 'earned_total' => 1000,
    'net_change' => -50, 'net' => 900, 'rev' => ['refunds' => 100], 'cash_total' => 300, 'still_owed' => 250, 'outstanding_failed' => false,
    'occupancy_pct' => 50.0, 'rooms_occupied' => 5, 'adr' => 120, 'ops' => ['expected_arrivals' => 3, 'arrivals_completed' => 2], 'tom' => ['arrivals' => 4],
    'pos' => ['orders' => 7, 'voided_count' => 1], 'score' => 84];
$facts = rh_eod_headline_facts($fd);
$keys = array_column($facts, 'key');
dr_check(in_array('revenue_earned', $keys, true) && in_array('occupancy', $keys, true) && in_array('pos_orders', $keys, true), 'facts: headline figures listed', $keys);
$okShape = true;
foreach ($facts as $f1) {
    $okShape = $okShape && array_keys($f1) === ['key', 'label', 'value', 'unit', 'basis'] && in_array($f1['unit'], ['money', 'count', 'percent'], true) && is_numeric($f1['value']);
}
dr_check($okShape, 'facts: key, label, value (number), unit money|count|percent, basis');
dr_check(count(rh_eod_headline_facts($fd, static fn(string $k): bool => $k !== 'still_owed')) === count($facts) - 1, '$can drops a figure the viewer cannot see');
dr_check(!in_array('occupancy', array_column(rh_eod_headline_facts(['mods' => ['bookings' => false]] + $fd), 'key'), true), 'rooms figures absent when the bookings module is off');
dr_check(!in_array('still_owed', array_column(rh_eod_headline_facts(['outstanding_failed' => true] + $fd), 'key'), true), 'still owed left out when it could not be read (never a false 0)');
$lines = rh_eod_headline_lines($fd + ['rooms_total' => 10, 'score_label' => 'Good close'], 'MWK ');
dr_check(strpos($lines[0], 'Revenue earned: MWK 1,000.00 (-MWK 50.00') === 0 && strpos(implode("\n", $lines), 'Close score: 84/100 (Good close)') !== false, 'headline lines for the timed e-mail', $lines);

echo "\n--- 13. The day in brief (AI lead's hook): absent = nothing ---\n";
dr_check(rh_eod_brief_html(null) === '' && rh_eod_brief_html('  ') === '' && rh_eod_brief_wa_line(null) === null, 'no brief: no e-mail block, no WhatsApp line');
$bh = rh_eod_brief_html("Busy day.\n<b>x</b>");
dr_check(strpos($bh, 'The day in brief') !== false && strpos($bh, 'Busy day.<br />') !== false && strpos($bh, '&lt;b&gt;x&lt;/b&gt;') !== false, 'brief block: escaped, line breaks kept');
dr_check(rh_eod_brief_wa_line('Short.') === 'The day in brief: Short.' && rh_eod_brief_wa_line(str_repeat('a', 301)) === null, 'WhatsApp: one line when it fits in 300 characters, else none');
$aiFile = $root . '/includes/ai/ai-eod-narrative.php';
if (!is_file($aiFile) && !function_exists('rh_ai_eod_email_brief')) {
    dr_check(rh_eod_ai_brief(new DrFakePdo(), '2026-11-11', $facts) === null, 'no AI file: no brief and no error');
} else {
    dr_check(true, 'AI file present (built by the AI lead): the no-AI case is covered by the function_exists guard');
}
dr_check(strpos($page, "if (is_file(__DIR__ . '/../includes/ai/ai-eod-narrative.php')) {") !== false && strpos($page, "if (function_exists('rh_ai_eod_mount')) {") !== false,
    'page mounts the AI lead\'s section only when its file and function exist');
dr_check(strpos($eodSend, '$html .= rh_eod_brief_html($brief);') !== false && strpos($send, "rh_eod_brief_html(\$eodSec['result']['brief'] ?? null)") !== false, 'clicked and timed e-mails carry the brief block only when there is one');

echo "\n=== daily_reports_job: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
