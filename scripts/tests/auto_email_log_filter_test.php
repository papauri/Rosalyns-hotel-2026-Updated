<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Automated Emails > Recent activity filters + the pager's opt-in "All" (owner 2026-11-25 "add filters then").
 * NO DATABASE: only admin/includes/auto-email-log-filter.php and admin/includes/log-pager.php (pure helpers) are
 * loaded, plus source checks on admin/automated-emails.php.
 * Usage (repo root): php scripts/tests/auto_email_log_filter_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/admin/includes/log-pager.php';
require_once $root . '/admin/includes/auto-email-log-filter.php';
check(!isset($GLOBALS['pdo']) && !function_exists('getSetting'), 'the helpers load without the database or settings');

$jobs = ['overdue_payment_reminders', 'daily_reports', 'arrivals_chase'];

/* ── parsing ── */
$f = rh_ael_parse([], $jobs);
check($f['job'] === '' && $f['status'] === '' && $f['from'] === '' && $f['to'] === '' && $f['q'] === '' && !$f['active'], 'no filters: nothing active');
check(rh_ael_parse(['ae_job' => 'daily_reports'], $jobs)['job'] === 'daily_reports', 'a job in the log is kept');
check(rh_ael_parse(['ae_job' => 'nightly_backup'], $jobs)['job'] === '', 'a job not in the log is dropped');
check(rh_ael_parse(['ae_job' => "daily_reports' OR 1=1 -- "], $jobs)['job'] === '', 'an injection-y job is dropped');
check(rh_ael_parse(['ae_job' => ['daily_reports']], $jobs)['job'] === '', 'an array job is dropped');
foreach (['sent', 'failed', 'skipped', 'sending'] as $s) {
    check(rh_ael_parse(['ae_status' => $s], $jobs)['status'] === $s, 'result "' . $s . '" kept');
}
foreach (['SENT', 'deleted', "sent' --", '', '0'] as $s) {
    check(rh_ael_parse(['ae_status' => $s], $jobs)['status'] === '', 'result "' . $s . '" dropped');
}
foreach (['2026-02-30', '2026-13-01', '26-01-01', '2026-1-5', 'yesterday', "2026-01-01' OR '1'='1", '2026-01-01 00:00:00'] as $d) {
    check(rh_ael_parse(['ae_from' => $d], $jobs)['from'] === '', 'bad date "' . $d . '" dropped');
}
check(rh_ael_parse(['ae_from' => ['2026-01-01']], $jobs)['from'] === '', 'an array date is dropped');
$f = rh_ael_parse(['ae_from' => '2026-11-20', 'ae_to' => '2026-11-01'], $jobs);
check($f['from'] === '2026-11-01' && $f['to'] === '2026-11-20', 'From after To are swapped');
check(rh_ael_parse(['ae_from' => '2024-02-29'], $jobs)['from'] === '2024-02-29', 'a leap day is a real date');
$f = rh_ael_parse(['ae_q' => '  ' . str_repeat('x', 300) . '  '], $jobs);
check(strlen($f['q']) === RH_AEL_Q_MAX && $f['active'], 'search trimmed and cut to ' . RH_AEL_Q_MAX);
check(rh_ael_parse(['ae_q' => ['a']], $jobs)['q'] === '', 'an array search is dropped');

/* ── WHERE: user text only ever in the bound args ── */
$w = rh_ael_where(rh_ael_parse([], $jobs));
check($w['sql'] === '' && $w['args'] === [], 'no filters: no WHERE');
$evil = "x' OR 1=1; DROP TABLE automated_email_log; --";
$f = rh_ael_parse(['ae_job' => 'daily_reports', 'ae_status' => 'failed', 'ae_from' => '2026-11-01', 'ae_to' => '2026-11-30', 'ae_q' => $evil], $jobs);
$w = rh_ael_where($f);
check(strpos($w['sql'], 'DROP') === false && strpos($w['sql'], "'x") === false && strpos($w['sql'], '1=1') === false, 'injection text never in the SQL', $w['sql']);
check(substr_count($w['sql'], '?') === count($w['args']), 'one bound value per placeholder (' . count($w['args']) . ')');
check($w['args'][0] === 'daily_reports' && $w['args'][1] === 'failed', 'job and result bound in order');
check($w['args'][2] === '2026-11-01 00:00:00' && $w['args'][3] === '2026-12-01 00:00:00', 'dates half-open: To covers the whole day (month end)');
check(strpos($w['sql'], 'l.sent_at >= ?') !== false && strpos($w['sql'], 'l.sent_at < ?') !== false, 'dates compare on sent_at');
$w = rh_ael_where(rh_ael_parse(['ae_to' => '2026-12-31'], $jobs));
check($w['args'] === ['2027-01-01 00:00:00'], 'To on the last day of the year rolls into the next year');
$w = rh_ael_where(rh_ael_parse(['ae_q' => '50%_off!'], $jobs));
check(in_array('%50!%!_off!!%', $w['args'], true) && strpos($w['sql'], "ESCAPE '!'") !== false, '% _ ! typed by staff match literally');
$w = rh_ael_where(rh_ael_parse(['ae_q' => 'guest@example.invalid'], $jobs));
check(strpos($w['sql'], 'l.recipient LIKE') !== false && strpos($w['sql'], 'b.booking_reference LIKE') !== false
    && strpos($w['sql'], 'l.subject LIKE') !== false && strpos($w['sql'], 'l.stage LIKE') !== false, 'search covers recipient, subject, stage and booking reference');
check(strpos($w['sql'], 'account_id') === false, 'text search does not compare the account ID');
$w = rh_ael_where(rh_ael_parse(['ae_q' => '#1234'], $jobs));
check(strpos($w['sql'], 'l.account_id = ?') !== false && end($w['args']) === 1234, '"#1234" also matches account ID 1234');
$w = rh_ael_where(rh_ael_parse(['ae_q' => '99999999999'], $jobs));
check(strpos($w['sql'], 'account_id') === false, 'an 11-digit number is not used as an ID');
[$cnt, $rows, $args] = rh_ael_sql(rh_ael_parse(['ae_status' => 'sent'], $jobs));
check(strpos($cnt, 'LEFT JOIN bookings b') !== false && strpos($rows, 'LEFT JOIN bookings b') !== false, 'count and rows use the same join');
check(substr($cnt, strpos($cnt, ' WHERE')) === substr($rows, strpos($rows, ' WHERE'), strlen(substr($cnt, strpos($cnt, ' WHERE')))), 'count and rows use the same WHERE');
check(substr($rows, -18) === 'ORDER BY l.id DESC' && $args === ['sent'], 'rows newest first, args returned');

/* ── paging with the opt-in All ── */
$p = rh_lp_parse([], 'ae', RH_AEL_ALL_MAX);
check($p['size'] === 10 && $p['page'] === 1 && !$p['all'], 'default 10 per page');
foreach (['0', '-1', 'abc', ['2']] as $bad) {
    check(rh_lp_parse(['ae_page' => $bad], 'ae', RH_AEL_ALL_MAX)['page'] === 1, 'bad page ' . json_encode($bad) . ' -> 1');
}
$p = rh_lp_clamp(rh_lp_parse(['ae_page' => '999', 'ae_size' => '25'], 'ae', RH_AEL_ALL_MAX), 60);
check($p['page'] === 3 && $p['offset'] === 50 && !$p['more'], 'past the end -> the last page');
$p = rh_lp_clamp(rh_lp_parse(['ae_size' => 'all', 'ae_page' => '4'], 'ae', RH_AEL_ALL_MAX), 137);
check($p['all'] && $p['size'] === 137 && $p['page'] === 1 && $p['offset'] === 0 && $p['pages'] === 1 && !$p['more'], 'All, 137 rows: one page of 137');
check(rh_lp_limit_sql($p) === ' LIMIT 138 OFFSET 0', 'All fetches the counted rows + 1');
$p = rh_lp_clamp(rh_lp_parse(['ae_size' => 'ALL'], 'ae', RH_AEL_ALL_MAX), 0);
check($p['all'] && $p['size'] === 1 && $p['pages'] === 1, 'All with no rows: size 1, one page');
$p = rh_lp_clamp(rh_lp_parse(['ae_size' => 'all'], 'ae', RH_AEL_ALL_MAX), RH_AEL_ALL_MAX + 1);
check(!$p['all'] && $p['all_fallback'] && $p['size'] === 100 && $p['pages'] === 6, 'All above the cap falls back to 100 per page');
$p = rh_lp_clamp(rh_lp_parse(['ae_size' => 'all'], 'ae', RH_AEL_ALL_MAX), -1);
check(!$p['all'] && $p['all_fallback'] && $p['size'] === 100, 'All when the count fails falls back to 100 per page');
check(rh_lp_parse(['ae_size' => 'all'], 'ae')['size'] === 10 && !rh_lp_parse(['ae_size' => 'all'], 'ae')['all'], 'All is ignored where a list has not opted in');
check(rh_lp_parse(['ae_size' => ['all']], 'ae', RH_AEL_ALL_MAX)['size'] === 10, 'an array size -> 10');

$get = ['ae_size' => 'all', 'ae_status' => 'sent', 'ae_q' => '<b>x</b>'];
$p = rh_lp_clamp(rh_lp_parse($get, 'ae', RH_AEL_ALL_MAX), 30);
$html = rh_lp_render('automated-emails.php', $get, $p, 30, 'ae-activity', 'Activity pages');
check(strpos($html, '<option value="all" selected>All</option>') !== false, 'the picker offers All and marks it');
check(substr_count($html, ' selected') === 1, 'only one size is selected');
check(strpos($html, '<b>x</b>') === false && strpos($html, '&lt;b&gt;x&lt;/b&gt;') !== false, 'kept filters are escaped in the pager');
check(strpos($html, 'name="ae_status" value="sent"') !== false, 'the picker keeps the other filters');
check(rh_lp_url('automated-emails.php', $get, $p, 1, 'ae-activity') === 'automated-emails.php?ae_size=all&ae_status=sent&ae_q=%3Cb%3Ex%3C%2Fb%3E#ae-activity', 'links carry ae_size=all');
$p = rh_lp_clamp(rh_lp_parse(['ae_size' => 'all'], 'ae', RH_AEL_ALL_MAX), 900);
$html = rh_lp_render('automated-emails.php', ['ae_size' => 'all'], $p, 100, 'ae-activity');
check(strpos($html, 'All shows up to 500 rows') !== false && strpos($html, '<option value="100" selected>') !== false, 'the fallback says so and shows 100 selected');
$plain = rh_lp_render('system-logs.php', [], rh_lp_clamp(rh_lp_parse([], 'ev'), 30), 10, 'log-events');
check(strpos($plain, 'value="all"') === false, 'System Logs pagers (not opted in) offer no All');

check(rh_ael_count_line(['total' => 0]) === 'No results', 'count line: none');
check(rh_ael_count_line(['total' => 1]) === '1 result', 'count line: one');
check(rh_ael_count_line(['total' => 1234]) === '1,234 results', 'count line: many');
check(rh_ael_count_line(['total' => 10000, 'capped' => true]) === '10,000+ results', 'count line: capped');
check(rh_ael_count_line(['unknown' => true]) === 'Results could not be counted just now', 'count line: count failed');

/* ── page source ── */
$src = (string)file_get_contents($root . '/admin/automated-emails.php');
check(strpos($src, 'LIMIT 100') === false && strpos($src, 'last 100') === false, 'the fixed last-100 list is gone');
check(strpos($src, "rh_lp_fetch(\$pdo, \$_GET, 'ae'") !== false, 'the list pages with the shared pager');
check(preg_match('/<form method="get"[^>]*class="ae-filters"/', $src) === 1, 'filters are a GET form');
check(preg_match('/<form method="get"(?:(?!<\/form>).)*csrf_token/s', $src) === 0, 'the filter form carries no CSRF token');
check(strpos($src, 'data-disable-auto-pagination') !== false, 'the client pager does not page it again');
check(substr_count(substr($src, strpos($src, 'id="ae-activity"')), 'class="help" data-tip=') >= 6, 'every column has a "?" helper');
check(strpos($src, "rhCanLinkTo((int)(\$user['id'] ?? 0), 'booking-details.php')") !== false, 'booking links only for people who may open bookings');

echo "\n" . ($fail === 0 ? "ALL PASS ($pass)" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
