<?php
/**
 * Daily reports CLI.
 *
 *   php scripts/daily_reports.php --recipients=a@x.com,b@y.com --start=YYYY-MM-DD --end=YYYY-MM-DD
 *
 * Defaults: bookings / accounting = today (single day); station-day = the last trading day that has ENDED
 * (--date / --start / --end pick the days of the dated reports). Recipients: env REPORT_RECIPIENTS (or fail).
 * Up to four attachments (bookings, station-day, accounting, stock-health): a section that fails is logged and
 * named as "could not be loaded" in the e-mail, and the rest are still sent.
 * Exit codes: 0 sent; 1 every section failed (the e-mail saying so is still attempted); 2 no recipients; 3 send failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

require __DIR__ . '/../includes/report-builders.php';
require __DIR__ . '/../includes/report-mailer.php';

/* ---------------- args ---------------- */
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $arg, $m)) {
        $args[$m[1]] = '1';
    }
}

$today = date('Y-m-d');
$start = $args['start'] ?? ($args['date'] ?? $today);
$end   = $args['end']   ?? ($args['date'] ?? $today);

$recipientStr = $args['recipients'] ?? (string)getenv('REPORT_RECIPIENTS');
$recipients = [];
foreach (preg_split('/[,;]/', $recipientStr) as $email) {
    $email = trim($email);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $recipients[] = ['email' => $email, 'name' => ''];
    }
}
if (!$recipients) {
    fwrite(STDERR, "ERROR: No valid --recipients=email,email provided.\n");
    exit(2);
}

echo "Daily reports — period {$start} to {$end}\n";
echo 'Recipients: ' . implode(', ', array_column($recipients, 'email')) . "\n\n";

/* ---------------- build ---------------- */
// Each section on its own: one that fails is logged (rh_log_event), named in the e-mail as "could not be
// loaded", and the rest are still sent (includes/report-sections.php).
require_once __DIR__ . '/../includes/report-sections.php';
require_once __DIR__ . '/../includes/station-hours.php';
require_once __DIR__ . '/../admin/includes/pos-shift-totals.php';
$logSection = static function (string $name, Throwable $e): void {
    if (function_exists('rh_log_event')) {
        rh_log_event('daily_reports', 'error', 'Daily report section could not be built: ' . $name, ['error' => $e->getMessage()]);
    }
    fwrite(STDERR, "  ! {$name}: " . $e->getMessage() . "\n");
};

// Station report = the trading day that has ENDED (owner 2026-11-10 "count with the day that ended"), so a run
// before opening or during the day never sends an empty or half day. --date / --start / --end still pick days.
$stationExplicit = isset($args['start']) || isset($args['end']) || isset($args['date']);
$stationStart = $start;
$stationEnd = $end;
if (!$stationExplicit) {
    try {
        $stationStart = $stationEnd = rh_pos_last_ended_trading_date(new DateTimeImmutable('now', rh_site_timezone()));
    } catch (Throwable $e) {
        $logSection('station-day (day)', $e);
        $stationStart = $stationEnd = date('Y-m-d', strtotime('-1 day'));
    }
}

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

if ($bookings)   { printf("  • bookings        : %5d rows, %d bytes\n", $bookings['rows'], strlen($bookings['csv'])); }
if ($station)    { printf("  • station-day     : %5d rows, %d bytes (trading day %s)\n", $station['rows'], strlen($station['csv']), $stationStart === $stationEnd ? $stationStart : "{$stationStart} to {$stationEnd}"); }
if ($accounting) { printf("  • accounting      :   ---  rows, %d bytes (outstanding %s)\n", strlen($accounting['csv']), number_format($accounting['outstanding'] ?? 0, 2)); }
if ($stock) {
    printf("  • stock-health    : %5d rows, %d bytes (low %d, expiring %d, value %s)\n",
        $stock['rows'], strlen($stock['csv']), $stock['low_count'], $stock['expiring'], number_format($stock['total_value'], 2));
}

$attachments = [];
foreach ($sections as $sec) {
    if ($sec['ok'] && is_array($sec['result'])) {
        $attachments[] = ['filename' => $sec['result']['filename'], 'content' => $sec['result']['csv'], 'mime' => 'text/csv'];
    }
}

/* ---------------- email body ---------------- */
$siteName = function_exists('getSetting') ? (getSetting('site_name', 'Hotel') ?: 'Hotel') : 'Hotel';
$subject = sprintf('[%s] Daily Operations Report — %s', $siteName, $start === $end ? $start : "{$start} to {$end}");
$stationDays = $stationStart === $stationEnd ? $stationStart : "{$stationStart} to {$stationEnd}";

$html  = '<h2 style="margin:0 0 12px 0;">Daily Operations Report</h2>';
$html .= '<p><strong>Period:</strong> ' . htmlspecialchars($start === $end ? $start : "{$start} to {$end}") . '<br>';
$html .= '<strong>Restaurant / till (station report):</strong> trading day ' . htmlspecialchars($stationDays)
    . ($station ? ' (' . htmlspecialchars((string)($station['window_label'] ?? '')) . ')' : '')
    . ($stationExplicit ? '' : ', the last trading day that has ended') . '<br>';
$html .= '<strong>Generated:</strong> ' . date('d M Y H:i:s') . '</p>';
$html .= '<h3>Attachments</h3><ul>';
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
$result = sendReportEmail($recipients, $subject, $html, $attachments);

echo "\nMail send result: " . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";

if (!array_filter($sections, static fn(array $sec): bool => $sec['ok'])) {
    fwrite(STDERR, "ERROR: every report section failed (see the system log).
");
    exit(1);
}
exit($result['success'] ? 0 : 3);
