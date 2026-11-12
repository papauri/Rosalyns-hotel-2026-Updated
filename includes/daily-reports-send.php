<?php

/**
 * Daily Operations Report e-mail (bookings, station-day, accounting, stock-health CSVs): ONE build + send path
 * for scripts/daily_reports.php (CLI) and the scheduler job 'daily_reports' (includes/auto-email-jobs.php,
 * owner 2026-11-11 "the daily station reports should be on a timer").
 *
 * The planning helpers (recipients parse, dedupe key, ended-day plan) are pure when given their callables:
 * scripts/tests/daily_reports_job.php. The report builders and mailer load only inside
 * rh_daily_reports_build_and_send(), so including this file never connects to anything.
 */

if (!function_exists('rh_daily_reports_parse_recipients')) {

    /** Split "a@x, b@y; c@z" into valid addresses. @return list<array{email:string, name:string}> */
    function rh_daily_reports_parse_recipients(string $csv): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', $csv) ?: [] as $em) {
            $em = trim((string)$em);
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email' => $em, 'name' => ''];
            }
        }
        return $out;
    }

    /**
     * End of Day report recipients (also the daily report timer's). `eod_report_recipients` lives in
     * site_settings, but `email_admin_email` and `email_from_email` live in email_settings - and getSetting()
     * only reads site_settings, so the two fallbacks are read through getEmailSetting().
     * The callables are for tests: $site(key) / $email(key) return the setting as a string.
     * @return list<array{email:string, name:string}>
     */
    function rh_eod_report_recipients(?callable $site = null, ?callable $email = null): array
    {
        $site = $site ?? static fn(string $k): string => function_exists('getSetting') ? (string)getSetting($k, '') : '';
        $email = $email ?? static fn(string $k): string => function_exists('getEmailSetting') ? (string)getEmailSetting($k, '') : '';
        $csv = (string)$site('eod_report_recipients');
        if (!$csv) {
            $csv = (string)($email('email_admin_email') ?: $email('email_from_email'));
        }
        return rh_daily_reports_parse_recipients($csv);
    }

    /**
     * Visible copy (CC) on the daily reports email (owner 2026-11-12: the MSP mailbox, editable on Automated
     * Emails, setting automated_job_daily_reports_cc). Valid addresses only, no repeats, never one already in To.
     * $raw is for tests; null = read the setting (its default lives in rh_auto_defaults()).
     * @param list<array{email:string}> $recipients
     * @return list<string>
     */
    function rh_daily_reports_cc(array $recipients, ?string $raw = null): array
    {
        if ($raw === null) {
            if (!function_exists('rh_auto_setting')) {
                require_once __DIR__ . '/auto-scheduler.php';
            }
            $raw = function_exists('getSetting') ? rh_auto_setting('automated_job_daily_reports_cc') : '';
        }
        $to = array_map('strtolower', array_column($recipients, 'email'));
        $out = [];
        foreach (rh_daily_reports_parse_recipients($raw) as $r) {
            $lc = strtolower($r['email']);
            if (!in_array($lc, $to, true) && !in_array($lc, array_map('strtolower', $out), true)) {
                $out[] = $r['email'];
            }
        }
        return $out;
    }

    /**
     * CC of the daily reports email (owner B1 2026-11-13): the daily reports copy list (automated_job_daily_reports_cc,
     * Automated Emails) PLUS the End of Day copy list (eod_report_cc_emails, Hotel Settings > Payments & documents),
     * valid, no repeats, never a To address. Also the CC of the clicked End of Day Email (with the box's extras).
     * $jobCc / $eodCc are for tests; null = read the setting.
     * @param list<array{email:string}> $recipients
     * @return list<string>
     */
    function rh_daily_reports_cc_all(array $recipients, ?string $jobCc = null, ?string $eodCc = null): array
    {
        if ($eodCc === null) {
            $eodCc = function_exists('getSetting') ? (string)getSetting('eod_report_cc_emails', '') : '';
        }
        if ($jobCc === null) {
            if (!function_exists('rh_auto_setting')) {
                require_once __DIR__ . '/auto-scheduler.php';
            }
            $jobCc = function_exists('getSetting') ? rh_auto_setting('automated_job_daily_reports_cc') : '';
        }
        return rh_daily_reports_cc($recipients, $jobCc . ',' . $eodCc);
    }

    /** The test recipient (Automated Emails > Test recipient), '' when test mode is off. */
    function rh_report_test_recipient(): string
    {
        if (!function_exists('getSetting')) {
            return '';
        }
        if (!function_exists('rh_auto_setting')) {
            require_once __DIR__ . '/auto-scheduler.php';
        }
        return trim(rh_auto_setting('automated_email_test_recipient'));
    }

    /**
     * Pure: where a report e-mail really goes (owner B3 2026-11-13). Test mode off ($redirect ''): unchanged. Test
     * mode on: ONLY the test address, subject [TEST], no CC (the mailer also drops the hidden staff copy); a test
     * address that is not an email refuses the send (fail closed, never the real people).
     * @return array{action:string, recipients:array, cc:array, subject:string, test:bool, note:string}
     */
    function rh_report_route(array $recipients, array $cc, string $subject, string $redirect): array
    {
        $redirect = trim($redirect);
        if ($redirect === '') {
            return ['action' => 'send', 'recipients' => $recipients, 'cc' => array_values($cc), 'subject' => $subject, 'test' => false, 'note' => ''];
        }
        if (!filter_var($redirect, FILTER_VALIDATE_EMAIL)) {
            return ['action' => 'refuse', 'recipients' => [], 'cc' => [], 'subject' => $subject, 'test' => true,
                'note' => 'Test mode is on but the test address (Automated Emails > Test recipient) is not a valid email, so nothing was sent.'];
        }
        return ['action' => 'send', 'recipients' => [['email' => $redirect, 'name' => '']], 'cc' => [],
            'subject' => strpos($subject, '[TEST]') === 0 ? $subject : '[TEST] ' . $subject, 'test' => true,
            'note' => 'Test mode: sent only to ' . $redirect . ' (no copies).'];
    }

    /**
     * Run $send with the automated test context set to $redirect (report-mailer.php then sends ONLY to it, [TEST],
     * no CC / hidden copies; sendEmail() does the same through rh_automated_email_adjust). The previous context is
     * restored afterwards. $redirect '' runs $send unchanged.
     */
    function rh_report_with_test_ctx(string $redirect, callable $send)
    {
        if (trim($redirect) === '') {
            return $send();
        }
        $had = array_key_exists('rh_automated_ctx', $GLOBALS);
        $prev = $GLOBALS['rh_automated_ctx'] ?? null;
        $GLOBALS['rh_automated_ctx'] = ['redirect' => trim($redirect), 'bcc' => false];
        try {
            return $send();
        } finally {
            if ($had) {
                $GLOBALS['rh_automated_ctx'] = $prev;
            } else {
                unset($GLOBALS['rh_automated_ctx']);
            }
        }
    }

    /**
     * automated_email_log key for one trading day: the scheduler job and the CLI claim the SAME row, so a re-run,
     * two page loads at once, or a manual CLI run (no cron at either hotel) never send the same day twice.
     * @return array{job:string, type:string, id:int, stage:string}
     */
    function rh_daily_reports_key(string $tradingDate, bool $test = false): array
    {
        return [
            'job' => 'daily_reports',
            'type' => 'trading_day',
            'id' => (int)str_replace('-', '', $tradingDate),
            'stage' => ($test ? 'test:' : '') . 'day:' . $tradingDate,
        ];
    }

    /**
     * What the timer does now (pure with $windowFor): every dated section = the last trading day that has ENDED
     * (opening -> next opening, rh_pos_last_ended_trading_date), so a run just after midnight never sends the
     * new, empty day. Test mode (automated_email_test_recipient set): everything goes to that address, [TEST]
     * subject, 'test:' stage; an unusable test address fails closed (skip, never the real recipients).
     * @param list<array{email:string, name?:string}> $recipients
     * @return array{action:string, date:string, start:string, end:string, station_start:string, station_end:string,
     *               key:array, recipients:array, subject_prefix:string, note:string}
     */
    function rh_daily_reports_auto_plan(DateTimeImmutable $now, array $recipients, string $redirect = '', bool $testMode = false, ?callable $windowFor = null): array
    {
        require_once __DIR__ . '/../admin/includes/pos-shift-totals.php'; // pure helpers only at include
        $day = rh_pos_last_ended_trading_date($now, $windowFor);
        $plan = [
            'action' => 'send', 'date' => $day, 'start' => $day, 'end' => $day, 'station_start' => $day, 'station_end' => $day,
            'key' => rh_daily_reports_key($day, $testMode), 'recipients' => $recipients, 'subject_prefix' => '', 'note' => '',
        ];
        if ($testMode) {
            $redirect = trim($redirect);
            if (!filter_var($redirect, FILTER_VALIDATE_EMAIL)) {
                return ['action' => 'skip', 'recipients' => [], 'note' => 'Test mode is on but the test address is not a valid email, so nothing was sent.'] + $plan;
            }
            $plan['recipients'] = [['email' => $redirect, 'name' => '']];
            $plan['subject_prefix'] = '[TEST] ';
        }
        if (!$plan['recipients']) {
            return ['action' => 'skip', 'note' => 'No one to send it to: add an address under "End-of-day report recipients" (Hotel Settings > Payments & documents), or set the staff copy email in Email Settings.'] + $plan;
        }
        return $plan;
    }

    /**
     * Build the four sections and send the e-mail. Each section on its own: one that fails is logged
     * (rh_log_event), named in the e-mail as "could not be loaded", and the rest are still sent
     * (includes/report-sections.php). Figures come from the unchanged builders in includes/report-builders.php.
     * $opts: echo (bool, CLI progress lines + STDERR), subject_prefix (string), cc (list of visible-copy addresses),
     * eod (['date' => trading day, 'by' => label]: also attach that day's End of Day PDF + headline lines, owner B1).
     * @return array{sections:array, result:array, any_ok:bool, subject:string}
     */
    function rh_daily_reports_build_and_send(array $recipients, string $start, string $end, string $stationStart, string $stationEnd, bool $stationExplicit, array $opts = []): array
    {
        require_once __DIR__ . '/report-builders.php';
        require_once __DIR__ . '/report-mailer.php';
        require_once __DIR__ . '/report-sections.php';
        require_once __DIR__ . '/station-hours.php';
        require_once __DIR__ . '/../admin/includes/pos-shift-totals.php';
        $echo = !empty($opts['echo']) && PHP_SAPI === 'cli';

        $logSection = static function (string $name, Throwable $e) use ($echo): void {
            if (function_exists('rh_log_event')) {
                rh_log_event('daily_reports', 'error', 'Daily report section could not be built: ' . $name, ['error' => $e->getMessage()]);
            }
            if ($echo) {
                fwrite(STDERR, "  ! {$name}: " . $e->getMessage() . "\n");
            }
        };

        $sections = [
            'bookings'   => rh_report_section('bookings', static fn() => buildBookingsReport($start, $end), $logSection),
            'station'    => rh_report_section('station-day', static fn() => buildStationDayReport($stationStart, $stationEnd), $logSection),
            'accounting' => rh_report_section('accounting', static fn() => buildAccountingSummary($start, $end), $logSection),
            'stock'      => rh_report_section('stock-health', static fn() => buildStockHealthReport(), $logSection),
        ];
        $bookings   = $sections['bookings']['result'];
        $station    = $sections['station']['result'];
        $accounting = $sections['accounting']['result'];
        $stock      = $sections['stock']['result'];

        if ($echo) {
            if ($bookings)   { printf("  • bookings        : %5d rows, %d bytes\n", $bookings['rows'], strlen($bookings['csv'])); }
            if ($station)    { printf("  • station-day     : %5d rows, %d bytes (trading day %s)\n", $station['rows'], strlen($station['csv']), $stationStart === $stationEnd ? $stationStart : "{$stationStart} to {$stationEnd}"); }
            if ($accounting) { printf("  • accounting      :   ---  rows, %d bytes (outstanding %s)\n", strlen($accounting['csv']), number_format($accounting['outstanding'] ?? 0, 2)); }
            if ($stock) {
                printf("  • stock-health    : %5d rows, %d bytes (low %d, expiring %d, value %s)\n",
                    $stock['rows'], strlen($stock['csv']), $stock['low_count'], $stock['expiring'], number_format($stock['total_value'], 2));
            }
        }

        $attachments = [];
        foreach ($sections as $sec) {
            if ($sec['ok'] && is_array($sec['result'])) {
                $attachments[] = ['filename' => $sec['result']['filename'], 'content' => $sec['result']['csv'], 'mime' => 'text/csv'];
            }
        }
        $siteName = function_exists('getSetting') ? (getSetting('site_name', 'Hotel') ?: 'Hotel') : 'Hotel';

        // End of Day report for the trading day that ended (owner B1 2026-11-13): its PDF (the same shared data and
        // builder as the PDF download) attached once, the headline lines in the body, and "The day in brief" when the
        // AI lead's hook is there. Its own section: if it fails, the CSVs still go and the e-mail says so.
        $eodSec = null;
        $eodDate = (string)($opts['eod']['date'] ?? '');
        if ($eodDate !== '') {
            $eodSec = rh_report_section('end-of-day', static function () use ($eodDate, $siteName, $opts): array {
                require_once __DIR__ . '/../admin/includes/eod-report-data.php';
                require_once __DIR__ . '/eod-pdf-builder.php';
                $pdo = $GLOBALS['pdo'] ?? null;
                if (!($pdo instanceof PDO)) {
                    throw new RuntimeException('no database connection');
                }
                $cur = function_exists('getSetting') ? ((string)getSetting('currency_symbol') ?: 'MWK ') : 'MWK ';
                $d = rh_eod_report_data($pdo, $eodDate, $cur);
                try {
                    $brief = rh_eod_ai_brief($pdo, $eodDate, rh_eod_headline_facts($d)); // AI can never stop the e-mail
                } catch (Throwable $e) {
                    error_log('EOD day in brief (timed): ' . $e->getMessage());
                    $brief = null;
                }
                return [
                    'pdf' => buildEodPdf($d, $siteName, $cur, (string)($opts['eod']['by'] ?? 'System (scheduler)')),
                    'lines' => rh_eod_headline_lines($d, $cur),
                    'brief' => $brief,
                ];
            }, $logSection);
            if ($eodSec['ok'] && is_array($eodSec['result'])) {
                $attachments[] = ['filename' => 'eod-report-' . $eodDate . '.pdf', 'content' => $eodSec['result']['pdf'], 'mime' => 'application/pdf'];
            }
        }

        /* ---------------- email body ---------------- */
        $subject = (string)($opts['subject_prefix'] ?? '')
            . sprintf('[%s] Daily Operations Report — %s', $siteName, $start === $end ? $start : "{$start} to {$end}");
        $stationDays = $stationStart === $stationEnd ? $stationStart : "{$stationStart} to {$stationEnd}";

        $html  = '<h2 style="margin:0 0 12px 0;">Daily Operations Report</h2>';
        $html .= '<p><strong>Period:</strong> ' . htmlspecialchars($start === $end ? $start : "{$start} to {$end}") . '<br>';
        $html .= '<strong>Restaurant / till (station report):</strong> trading day ' . htmlspecialchars($stationDays)
            . ($station ? ' (' . htmlspecialchars((string)($station['window_label'] ?? '')) . ')' : '')
            . ($stationExplicit ? '' : ', the last trading day that has ended') . '<br>';
        $html .= '<strong>Generated:</strong> ' . date('d M Y H:i:s') . '</p>';
        if ($eodSec !== null) {
            $html .= '<h3>End of Day — ' . htmlspecialchars(date('l, j F Y', (int)strtotime($eodDate))) . '</h3>';
            if ($eodSec['ok']) {
                $html .= rh_eod_brief_html($eodSec['result']['brief'] ?? null);
                $html .= '<ul>';
                foreach ((array)$eodSec['result']['lines'] as $ln) {
                    $html .= '<li>' . htmlspecialchars((string)$ln) . '</li>';
                }
                $html .= '</ul><p style="color:#666;font-size:12px;">The full End of Day report is attached as a PDF. Figures as at '
                    . htmlspecialchars(date('H:i')) . '; later changes show on the End of Day page.</p>';
            } else {
                $html .= '<p>' . htmlspecialchars(rh_report_failed_line('End of Day report')) . '</p>';
            }
        }
        $html .= '<h3>Attachments</h3><ul>';
        if ($eodSec !== null && $eodSec['ok']) {
            $html .= '<li><strong>eod-report-' . htmlspecialchars($eodDate) . '.pdf</strong> — the End of Day report (same as the PDF button on the End of Day page).</li>';
        }
        $html .= $bookings
            ? '<li><strong>' . htmlspecialchars($bookings['filename']) . '</strong> — ' . (int)$bookings['rows'] . ' bookings.</li>'
            : '<li>' . htmlspecialchars(rh_report_failed_line('Bookings report')) . '</li>';
        $html .= $station
            ? '<li><strong>' . htmlspecialchars($station['filename']) . '</strong> — kitchen / bar / coffee bar item performance with summary, orders and sales as on the till Z (paid at the till + charged to room).</li>'
            : '<li>' . htmlspecialchars(rh_report_failed_line('Station report')) . '</li>';
        $html .= $accounting
            ? '<li><strong>' . htmlspecialchars($accounting['filename']) . '</strong> — revenue by booking type, payment method, outstanding receivables, POS shift closes.</li>'
            : '<li>' . htmlspecialchars(rh_report_failed_line('Accounting summary')) . '</li>';
        $html .= $stock
            ? '<li><strong>' . htmlspecialchars($stock['filename']) . '</strong> — low stock (' . (int)$stock['low_count'] . '), expiring batches (' . (int)$stock['expiring'] . '), stock value snapshot.</li>'
            : '<li>' . htmlspecialchars(rh_report_failed_line('Stock health report')) . '</li>';
        $html .= '</ul>';
        $html .= '<p style="color:#666;font-size:12px;">This is an automated report. CSVs are UTF-8.</p>';

        /* ---------------- send ---------------- */
        $emailCtx = function_exists('rh_email_context') ? rh_email_context(['kind' => 'daily_report']) : null; // System Logs > Emails sent
        try {
            $result = sendReportEmail($recipients, $subject, $html, $attachments, '', array_values((array)($opts['cc'] ?? [])));
        } finally {
            $emailCtx = null;
        }

        return [
            'sections' => $sections,
            'eod' => $eodSec,
            'result' => $result,
            'any_ok' => (bool)array_filter($sections, static fn(array $sec): bool => $sec['ok']) || ($eodSec !== null && $eodSec['ok']),
            'subject' => $subject,
        ];
    }
}
