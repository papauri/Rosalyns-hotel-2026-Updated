<?php
/**
 * Daily reports CLI.
 *
 *   php scripts/daily_reports.php --recipients=a@x.com,b@y.com --start=YYYY-MM-DD --end=YYYY-MM-DD
 *
 * Defaults: today (single day), recipients env REPORT_RECIPIENTS (or fail).
 * Always sends FOUR attachments: bookings, station-day, accounting, stock-health.
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
$bookings    = buildBookingsReport($start, $end);
$station     = buildStationDayReport($start, $end);
$accounting  = buildAccountingSummary($start, $end);
$stock       = buildStockHealthReport();

printf("  • bookings        : %5d rows, %d bytes\n", $bookings['rows'], strlen($bookings['csv']));
printf("  • station-day     : %5d rows, %d bytes\n", $station['rows'],  strlen($station['csv']));
printf("  • accounting      :   ---  rows, %d bytes (outstanding %s)\n", strlen($accounting['csv']), number_format($accounting['outstanding'] ?? 0, 2));
printf("  • stock-health    : %5d rows, %d bytes (low %d, expiring %d, value %s)\n",
    $stock['rows'], strlen($stock['csv']), $stock['low_count'], $stock['expiring'], number_format($stock['total_value'], 2));

$attachments = [
    ['filename' => $bookings['filename'],   'content' => $bookings['csv'],   'mime' => 'text/csv'],
    ['filename' => $station['filename'],    'content' => $station['csv'],    'mime' => 'text/csv'],
    ['filename' => $accounting['filename'], 'content' => $accounting['csv'], 'mime' => 'text/csv'],
    ['filename' => $stock['filename'],      'content' => $stock['csv'],      'mime' => 'text/csv'],
];

/* ---------------- email body ---------------- */
$siteName = function_exists('getSetting') ? (getSetting('site_name', 'Hotel') ?: 'Hotel') : 'Hotel';
$subject = sprintf('[%s] Daily Operations Report — %s', $siteName, $start === $end ? $start : "{$start} to {$end}");

$html  = '<h2 style="margin:0 0 12px 0;">Daily Operations Report</h2>';
$html .= '<p><strong>Period:</strong> ' . htmlspecialchars($start === $end ? $start : "{$start} to {$end}") . '<br>';
$html .= '<strong>Generated:</strong> ' . date('d M Y H:i:s') . '</p>';
$html .= '<h3>Attachments</h3><ul>';
$html .= '<li><strong>' . htmlspecialchars($bookings['filename']) . '</strong> — ' . (int)$bookings['rows'] . ' bookings.</li>';
$html .= '<li><strong>' . htmlspecialchars($station['filename']) . '</strong> — kitchen / bar / coffee bar item performance with summary.</li>';
$html .= '<li><strong>' . htmlspecialchars($accounting['filename']) . '</strong> — revenue by booking type, payment method, outstanding receivables, POS shift closes.</li>';
$html .= '<li><strong>' . htmlspecialchars($stock['filename']) . '</strong> — low stock (' . (int)$stock['low_count'] . '), expiring batches (' . (int)$stock['expiring'] . '), stock value snapshot.</li>';
$html .= '</ul>';
$html .= '<p style="color:#666;font-size:12px;">This is an automated report. CSVs are UTF-8.</p>';

/* ---------------- send ---------------- */
$result = sendReportEmail($recipients, $subject, $html, $attachments);

echo "\nMail send result: " . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";

exit($result['success'] ? 0 : 3);
