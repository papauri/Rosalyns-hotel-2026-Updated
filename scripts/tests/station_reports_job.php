<?php
/**
 * Pure (no-DB, no e-mail) tests: timed station report emails (owner 2026-11-12 "time the email on the KDS stations
 * ... timer and permissions ... including CC options").
 *  1. Job registration + defaults (toggle on, 60 min, bcc global, after daily_reports, backup stays last).
 *  2. Dedupe key per station per trading day (test: prefix; fits automated_email_log columns).
 *  3. Each station's OWN last ended trading day (late bar vs kitchen; the real default hours path too).
 *  4. Test mode / no recipients -> skip (no log row), fail closed on a bad test address, no CC on tests.
 *  5. Recipients: "go to" list, empty = End of Day recipients; CC never repeats a To address.
 *  6. Aggregates (moved verbatim from admin/kds-report.php): served-only counts, voids, prep < 6h, top items.
 *  7. Email render + wiring of the job, the Station Report page and the Automated Emails permission gate.
 *
 *   php scripts/tests/station_reports_job.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
date_default_timezone_set('Africa/Blantyre');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/auto-scheduler.php';         // defines functions only
require_once $root . '/includes/daily-reports-send.php';     // pure at include
require_once $root . '/includes/kds-report-data.php';        // pure at include
require_once $root . '/includes/station-hours.php';          // pure without getSetting (default hours)
require_once $root . '/admin/includes/pos-shift-totals.php'; // pure helpers only at include
require_once $root . '/admin/includes/permissions.php';      // function definitions only

$P = 0; $F = 0;
function sr_check(bool $c, string $label, $detail = ''): void
{
    global $P, $F;
    if ($c) { $P++; echo "  [PASS] $label\n"; return; }
    $F++; echo "  [FAIL] $label" . ($detail !== '' ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function sr_src(string $rel): string
{
    return str_replace("\r\n", "\n", (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel));
}

echo "--- 1. Job registration + defaults ---\n";
$jobs = rh_scheduler_jobs();
$j = $jobs['station_reports'] ?? [];
sr_check(($j['label'] ?? '') === 'Station report emails (kitchen / bar / coffee bar)' && ($j['fn'] ?? '') === 'rh_job_station_reports', 'job registered with its label and function', $j);
sr_check(($j['toggle'] ?? '') === 'automated_job_station_reports' && ($j['toggle_default'] ?? '') === '1' && ($j['interval'] ?? 0) === 60
    && ($j['legacy'] ?? true) === false && ($j['bcc'] ?? '') === 'global', 'toggle, default on, every 60 min, not legacy, bcc global', $j);
$keys = array_keys($jobs);
$pos = array_flip($keys);
sr_check(end($keys) === 'nightly_backup' && $pos['station_reports'] === $pos['daily_reports'] + 1 && $pos['daily_reports'] === $pos['stock_expiry_sweep'] + 1,
    'order: stock sweep -> daily reports -> station reports -> backup (last)', $keys);
$d = rh_auto_defaults();
sr_check(($d['automated_job_station_reports'] ?? null) === '1' && ($d['automated_job_station_reports_to'] ?? null) === ''
    && ($d['automated_job_station_reports_cc'] ?? null) === 'info@promanaged-it.com', 'defaults: on, To empty (= End of Day list), CC = MSP mailbox');
$jobsSrc = sr_src('includes/auto-email-jobs.php');
sr_check(strpos($jobsSrc, 'function rh_job_station_reports(PDO $pdo, array $o = []): array') !== false, 'job function exists with the scheduler signature');

echo "\n--- 2. Dedupe key per station per trading day ---\n";
sr_check(rh_station_reports_key('kitchen', '2026-11-11') === ['job' => 'station_reports', 'type' => 'trading_day', 'id' => 20261111, 'stage' => 'kitchen:day:2026-11-11'], 'kitchen key');
$kt = rh_station_reports_key('coffee_bar', '2026-11-11', true);
sr_check($kt['stage'] === 'test:coffee_bar:day:2026-11-11' && $kt['id'] === 20261111, 'test key has the test: prefix', $kt);
sr_check(strlen($kt['stage']) <= 40 && strlen($kt['type']) <= 30 && strlen($kt['job']) <= 60, 'longest stage fits automated_email_log (stage 40, type 30, job 60)', strlen($kt['stage']));
sr_check(rh_station_reports_key('bar', '2026-11-11')['stage'] !== rh_station_reports_key('kitchen', '2026-11-11')['stage'], 'one row per station');
sr_check(rh_station_reports_key('kitchen', '2026-11-11')['job'] !== rh_daily_reports_key('2026-11-11')['job'], 'never collides with the daily reports row');

echo "\n--- 3. Each station's own ended trading day ---\n";
$tz = new DateTimeZone('Africa/Blantyre');
$one = [['email' => 'gm@example.com', 'name' => '']];
$opens = static fn(string $hm): callable => static fn(string $dd): array => ['trading_end' => (new DateTimeImmutable($dd . ' ' . $hm . ':00', $tz))->modify('+1 day')];
$kit = $opens('06:00');
$bar = $opens('11:00'); // late bar 11:00 - 03:00 (+1): its day ends at the NEXT 11:00 opening
$at = static fn(string $t): DateTimeImmutable => new DateTimeImmutable($t, $tz);
$pk = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'kitchen', $one, '', false, $kit);
$pb = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'bar', $one, '', false, $bar);
sr_check($pk['date'] === '2026-11-11' && $pb['date'] === '2026-11-10', '07:00 on the 12th: kitchen sends the 11th; the bar (still in its 11th day until 11:00) sends the 10th', [$pk['date'], $pb['date']]);
$pb2 = rh_station_reports_auto_plan($at('2026-11-12 11:00:00'), 'bar', $one, '', false, $bar);
sr_check($pb2['date'] === '2026-11-11' && $pb2['key']['stage'] === 'bar:day:2026-11-11', 'the bar sends the 11th from its 11:00 opening on the 12th', $pb2['key']);
$p0 = rh_station_reports_auto_plan($at('2026-11-12 00:30:00'), 'kitchen', $one, '', false, $kit);
sr_check($p0['date'] === '2026-11-10', 'just after midnight: never the new, empty day', $p0['date']);
// Real default path (no getSetting loaded): rh_station_window_for_date with the built-in hours (bar opens 11:00).
$r1 = rh_station_reports_auto_plan($at('2026-11-12 10:59:00'), 'bar', $one);
$r2 = rh_station_reports_auto_plan($at('2026-11-12 11:00:00'), 'bar', $one);
$r3 = rh_station_reports_auto_plan($at('2026-11-12 06:00:00'), 'kitchen', $one);
sr_check($r1['date'] === '2026-11-10' && $r2['date'] === '2026-11-11' && $r3['date'] === '2026-11-11', 'default windows: bar day ends at its 11:00 opening, kitchen at 06:00', [$r1['date'], $r2['date'], $r3['date']]);
$w = rh_station_window_for_date('bar', '2026-11-11');
sr_check($w['trading_end'] instanceof DateTimeInterface && $w['trading_end']->format('Y-m-d H:i') === '2026-11-12 11:00', 'rh_station_window_for_date returns trading_end as a DateTimeInterface (next opening)');

echo "\n--- 4. Test mode / no recipients ---\n";
$p = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'kitchen', [], '', false, $kit);
sr_check($p['action'] === 'skip' && strpos($p['note'], 'Station report emails go to') !== false, 'no recipients: skip with a plain note', $p);
$p = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'kitchen', $one, 'qa@example.com', true, $kit);
sr_check($p['action'] === 'send' && $p['recipients'] === [['email' => 'qa@example.com', 'name' => '']] && $p['subject_prefix'] === '[TEST] '
    && $p['key']['stage'] === 'test:kitchen:day:2026-11-11', 'test mode: only the test address, [TEST] subject, test: stage', $p);
$p = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'kitchen', $one, 'not-an-email', true, $kit);
sr_check($p['action'] === 'skip' && $p['recipients'] === [], 'test mode with a bad test address fails closed (never the real recipients)', $p);
$p = rh_station_reports_auto_plan($at('2026-11-12 07:00:00'), 'kitchen', [], 'qa@example.com', true, $kit);
sr_check($p['action'] === 'send' && $p['recipients'][0]['email'] === 'qa@example.com', 'test mode sends to the test address even with no real recipients');

// Job wiring (source): a planned skip writes NO log row; zero tickets -> one skipped row; budget before claim.
$fnAt = strpos($jobsSrc, 'function rh_job_station_reports(');
$fnEnd = strpos($jobsSrc, '/* ── sample sends', (int)$fnAt);
$fn = substr($jobsSrc, (int)$fnAt, (int)$fnEnd - (int)$fnAt);
$skipBlock = (string)strstr((string)strstr($fn, "if (\$plan['action'] !== 'send') {"), 'continue;', true);
sr_check($skipBlock !== '' && strpos($skipBlock, 'rh_auto_log') === false, 'no recipients / bad test address: skipped WITHOUT a log row (recipients added later the same day still get it)', $skipBlock);
$iPlan = strpos($fn, 'rh_station_reports_auto_plan(');
$iBudget = strpos($fn, 'if (!rh_scheduler_budget_ok()) {');
$iFetch = strpos($fn, "rh_kds_report_fetch(\$pdo, \$station, \$w['start_sql'], \$w['end_sql'])");
$iZero = strpos($fn, "if (!\$rows) {\n                rh_auto_log_skip(\$pdo, \$k['job']");
$iClaim = strpos($fn, "rh_auto_log_claim(\$pdo, \$k['job']");
$iSpend = strpos($fn, 'rh_scheduler_budget_spend();');
$iSend = strpos($fn, "sendReportEmail(\$plan['recipients'], \$subject, \$html, [], '', \$cc)");
sr_check($iPlan !== false && $iBudget !== false && $iFetch !== false && $iZero !== false && $iClaim !== false && $iSpend !== false && $iSend !== false
    && $iPlan < $iBudget && $iBudget < $iFetch && $iFetch < $iZero && $iZero < $iClaim && $iClaim < $iSpend && $iSpend < $iSend,
    'order per station: plan -> budget -> fetch -> zero tickets = skipped row -> claim -> spend -> send', [$iPlan, $iBudget, $iFetch, $iZero, $iClaim, $iSpend, $iSend]);
sr_check(strpos($fn, "\$cc = \$testMode ? [] : rh_daily_reports_cc(\$plan['recipients'], rh_auto_setting('automated_job_station_reports_cc'));") !== false, 'CC from its own setting on real sends, none on test sends');
sr_check(strpos($fn, "'failed', \$msg);") !== false && strpos($fn, "'failed', \$e->getMessage());") !== false, 'failed send -> failed row (existing retry rules)');
sr_check(strpos($fn, "if (!rh_auto_module_on('pos')) {") !== false && strpos($fn, 'if (!rh_station_enabled($station)) {') !== false, 'only enabled stations, and nothing when the till (POS) is off');
sr_check(strpos($fn, "sprintf('[%s] Station Report — %s — %s', \$siteName, \$label, \$date)") !== false
    && strpos(sr_src('admin/kds-report.php'), "sprintf('[%s] Station Report — %s — %s', \$site_name, \$STATION_OPTIONS[\$reqStation], \$reqDate)") !== false, 'subject matches the manual Email button');

echo "\n--- 5. Recipients + CC ---\n";
$fb = static fn(): array => [['email' => 'eod@hotel.test', 'name' => '']];
sr_check(rh_station_reports_recipients('', $fb) === $fb() && rh_station_reports_recipients('   ', $fb) === $fb(), 'empty "go to" list = End of Day report recipients');
sr_check(array_column(rh_station_reports_recipients('a@x.com; bad, b@y.com', $fb), 'email') === ['a@x.com', 'b@y.com'], 'own list: valid addresses only, no fallback mixed in');
sr_check(rh_station_reports_recipients('junk', $fb) === [], 'a set but unusable list does not fall back (skip, like the End of Day button)');
sr_check(strpos($fn, "rh_station_reports_recipients(rh_auto_setting('automated_job_station_reports_to'), static fn(): array => rh_eod_report_recipients())") !== false, 'job reads the "go to" setting with the End of Day fallback');
sr_check(rh_daily_reports_cc([['email' => 'chef@hotel.test']], 'CHEF@hotel.test, info@promanaged-it.com') === ['info@promanaged-it.com'], 'CC never repeats a To address');

echo "\n--- 6. Aggregates (verbatim from the Station Report page) ---\n";
$row = static fn(array $o): array => $o + ['station' => 'kitchen', 'kds_status' => 'served', 'order_status' => 'paid', 'quantity' => '1', 'line_total' => '0',
    'fired_at' => null, 'served_at' => null, 'item_name' => 'X', 'reference' => 'ORD-1'];
$rows = [
    $row(['item_name' => 'Burger', 'quantity' => '2', 'line_total' => '20', 'fired_at' => '2026-11-11 10:00:00', 'served_at' => '2026-11-11 10:10:00']),
    $row(['item_name' => 'Steak', 'line_total' => '50', 'fired_at' => '2026-11-11 10:00:00', 'served_at' => '2026-11-11 10:05:00']),
    $row(['item_name' => 'Burger', 'line_total' => '10', 'fired_at' => '2026-11-11 10:00:00', 'served_at' => '2026-11-11 17:00:00']), // 7h: not a prep time
    $row(['item_name' => 'Soup', 'kds_status' => 'pending', 'quantity' => '3', 'line_total' => '30']),                               // not served
    $row(['station' => 'bar', 'item_name' => 'Gin', 'order_status' => 'voided', 'line_total' => '15']),                              // order voided
    $row(['station' => 'bar', 'item_name' => 'Rum', 'kds_status' => 'void', 'line_total' => '5']),                                   // item void
    $row(['station' => 'bar', 'item_name' => 'Wine', 'kds_status' => 'ready', 'order_status' => 'cancelled', 'line_total' => '7']),  // order cancelled
    $row(['station' => 'bar', 'item_name' => 'Mojito', 'line_total' => '25']),
    $row(['station' => '', 'item_name' => 'Water', 'line_total' => '1']),
];
$a = rh_kds_report_aggregate($rows);
sr_check($a['totalItems'] === 5 && (float)$a['totalQty'] === 6.0 && abs($a['totalRevenue'] - 106.0) < 0.001, 'served only: 5 items, qty 6, value 106 (pending soup left out)', $a);
sr_check($a['totalVoid'] === 3 && abs($a['totalVoidValue'] - 27.0) < 0.001, 'voids: item void + order voided + order cancelled = 3 (27)', [$a['totalVoid'], $a['totalVoidValue']]);
sr_check((int)$a['avgPrep'] === 450 && $a['minPrep'] === 300 && $a['maxPrep'] === 600, 'prep fired -> served under 6h only: avg 450s, min 300s, max 600s', [$a['avgPrep'], $a['minPrep'], $a['maxPrep']]);
sr_check($a['byStation']['kitchen']['items'] === 3 && (int)$a['byStation']['kitchen']['avg_seconds'] === 450 && $a['byStation']['bar']['voids'] === 3
    && $a['byStation']['bar']['items'] === 1 && isset($a['byStation']['unknown']), 'by station: kitchen 3 served (avg 450s), bar 1 served + 3 voids, blank station = unknown', $a['byStation']);
sr_check(array_column($a['topItems'], 'name') === ['Steak', 'Burger', 'Mojito', 'Water'] && abs($a['topItems']['Burger']['revenue'] - 30.0) < 0.001, 'top items by value, served only (Burger 20 + 10)', array_column($a['topItems'], 'name'));
$many = [];
for ($i = 1; $i <= 25; $i++) {
    $many[] = $row(['item_name' => 'Item ' . $i, 'line_total' => (string)$i]);
}
$am = rh_kds_report_aggregate($many);
sr_check(count($am['topItems']) === 20 && array_key_first($am['topItems']) === 'Item 25', 'top items capped at 20, highest first');
$e = rh_kds_report_aggregate([]);
sr_check($e['totalItems'] === 0 && $e['avgPrep'] === 0 && $e['topItems'] === [] && $e['byStation'] === [], 'no rows: zeros, nothing listed');
sr_check(rh_fmt_dur(0) === '—' && rh_fmt_dur(45) === '45s' && rh_fmt_dur(450) === '7m 30s' && rh_fmt_dt(null) === '—', 'duration / time formatters unchanged');

echo "\n--- 7. Email render + wiring ---\n";
$rows[] = $row(['item_name' => '<b>x</b>', 'line_total' => '0']);
$html = rh_kds_report_render_email([
    'reqStation' => 'kitchen', 'reqDate' => '2026-11-11', 'STATION_OPTIONS' => ['all' => 'All Stations', 'kitchen' => 'Kitchen'],
    'STATION_LABEL' => ['kitchen' => 'Kitchen', 'bar' => 'Bar', 'coffee_bar' => 'Coffee Bar'], 'rows' => $rows, 'currency_symbol' => 'MWK', 'site_name' => 'Test Hotel',
] + rh_kds_report_aggregate($rows));
sr_check(strpos($html, 'Test Hotel — Station Report') !== false && strpos($html, '<strong>Kitchen</strong>') !== false && strpos($html, 'MWK 106.00') !== false
    && strpos($html, 'Wednesday, 11 Nov 2026') !== false, 'renders the same email body as the manual button (site, station, value, date)');
sr_check(strpos($html, '<b>x</b>') === false && strpos($html, '&lt;b&gt;x&lt;/b&gt;') !== false, 'item names are escaped');
$kr = sr_src('admin/kds-report.php');
sr_check(strpos($kr, 'function rh_fmt_dur') === false && strpos($kr, 'function rh_fmt_dt') === false
    && strpos($kr, "require_once __DIR__ . '/../includes/kds-report-data.php';") !== false, 'Station Report page: helpers come from the shared file (no redeclare when the timer runs on that page)');
sr_check(strpos($kr, 'rh_kds_report_fetch($pdo, $reqStation, $dayStart, $dayEnd)') !== false && strpos($kr, '] = rh_kds_report_aggregate($rows);') !== false
    && strpos($kr, 'rh_kds_report_render_email(compact(') !== false, 'page uses the shared fetch / aggregate / render');
sr_check(strpos($kr, "hasPermission((int)\$_SESSION['admin_user_id'], 'kds_reports')") !== false && strpos($kr, '$result = sendEmail($to, $to, $subject, $html, \'\');') !== false
    && strpos($kr, "if (\$forcedStation) \$reqStation = \$forcedStation;") !== false, 'manual Email unchanged: kds_reports, one address, staff pinned to their station');
$kd = sr_src('includes/kds-report-data.php');
sr_check(strpos($kd, "if (!function_exists('rh_fmt_dur')) {") !== false && strpos($kd, "if (!function_exists('rh_fmt_dt')) {") !== false, 'formatters guarded');

$all = getAllPermissions();
$perm = $all['timed_report_emails'] ?? [];
sr_check(($perm['label'] ?? '') === 'Timed report emails' && ($perm['category'] ?? '') === 'Settings' && ($perm['group'] ?? '') === 'settings' && ($perm['page'] ?? 'x') === '',
    'permission timed_report_emails: Settings, not a page', $perm);
sr_check(!preg_match("/'[a-z0-9_-]+\.php'\s*=>\s*'timed_report_emails'/", sr_src('admin/includes/permissions.php')), 'no page is gated by it');
$mgr = getDefaultPermissionsForRole('manager');
sr_check(in_array('timed_report_emails', $mgr, true) && !in_array('timed_report_emails', getDefaultPermissionsForRole('receptionist'), true), 'default: managers (and administrators), not receptionists');
sr_check(!in_array('timed_report_emails', rh_admin_only_permissions(), true) && !in_array('timed_report_emails', rhStationPermissionKeys(), true), 'not administrators-only, not a station key');
sr_check(rh_permission_resolve('manager', false, 'timed_report_emails', null, $mgr, [], [], 1) === true
    && rh_permission_resolve('manager', false, 'timed_report_emails', false, $mgr, [], [], 1) === false, 'manager has it by default; a saved off row removes it');

$ae = sr_src('admin/automated-emails.php');
sr_check(strpos($ae, "\$canTimedReports = hasPermission((int)\$user['id'], 'timed_report_emails');") !== false && strpos($ae, "\$timedReportJobs = ['daily_reports', 'station_reports'];") !== false, 'Automated Emails checks the permission server-side');
$saveAt = strpos($ae, "if (\$action === 'save') {");
$save = substr($ae, (int)$saveAt, strpos($ae, "} elseif (\$action === 'run_now') {") - (int)$saveAt);
$gate = (string)strstr((string)strstr($save, 'if ($canTimedReports) {'), "            foreach (\$jobs as \$jobName => \$job) {", true);
foreach (['automated_job_daily_reports_cc', 'automated_job_station_reports_to', 'automated_job_station_reports_cc'] as $k) {
    sr_check(substr_count($save, "updateSetting('$k'") === 1 && strpos($gate, "if (\$posted('$k')) {") !== false && strpos($gate, "updateSetting('$k'") !== false, "save: $k written only with the permission and only when posted");
}
sr_check(strpos($save, "if (!\$canTimedReports && in_array(\$jobName, \$timedReportJobs, true)) {\n                    continue;") !== false, 'save: without the permission the report job switches are not written');
foreach (['automated_email_reminder_stages', 'automated_email_test_recipient', 'automated_email_payment_terms_days', 'automated_email_lookback_days', 'automated_email_quotation_days', 'automated_email_interval_minutes'] as $k) {
    sr_check(strpos($save, "if (\$posted('$k')) {") !== false && strpos($save, "if (\$posted('$k')) {") < strpos($save, "updateSetting('$k'"), "save: $k written only when its field was posted");
}
sr_check(strpos($save, 'return isset($_POST[$k]);') !== false, 'posted = isset($_POST[...])');
foreach (['automated_job_daily_reports_cc', 'automated_job_station_reports_to', 'automated_job_station_reports_cc'] as $k) {
    $fAt = strpos($ae, 'name="' . $k . '"');
    $fLine = $fAt === false ? '' : substr($ae, $fAt, (int)strpos($ae, "\n", $fAt) - $fAt);
    sr_check(strpos($fLine, "\$canTimedReports ? '' : ' disabled'") !== false, "field $k shown disabled without the permission");
}
sr_check(strpos($ae, '$jobLocked ? \' disabled\' : \'\'') !== false && strpos($ae, "\$timedNote = 'Needs the Timed report emails permission';") !== false, 'job switches disabled with the note "Needs the Timed report emails permission"');
sr_check(strpos($ae, 'placeholder="empty = End of Day report recipients"') !== false && strpos($ae, 'Copy (CC) on the station report emails') !== false, 'station "go to" and CC fields on the screen');

echo "\n=== station_reports_job: $P passed, $F failed ===\n";
exit($F > 0 ? 1 : 0);
