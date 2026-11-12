<?php

/**
 * End of Day Report — PDF download (the PDF button on admin/end-of-day-report.php).
 *
 * GET: end-of-day-pdf.php?date=YYYY-MM-DD&csrf=TOKEN
 * Outputs a binary PDF with Content-Disposition: attachment. Figures: rh_eod_report_data() (admin/includes/
 * eod-report-data.php), the same builder as the clicked Email and the timed daily e-mail, so all three PDFs match.
 */

declare(strict_types=1);

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/eod-report-data.php';

/** @var PDO $pdo */
/** @var array $user */

requireApiPermission('reports');

$site_name       = getSetting('site_name') ?: 'Hotel';
$currency_symbol = getSetting('currency_symbol') ?: 'MWK ';

// ---- Auth & CSRF -----------------------------------------------------------
if (empty($_SESSION['admin_user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$csrf = (string)($_GET['csrf'] ?? '');
if (!function_exists('validateCsrfToken') || !validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

// ---- Date (hotel time zone) ------------------------------------------------
$date = (string)($_GET['date'] ?? rh_eod_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = rh_eod_today();
}

// ---- Data + PDF ------------------------------------------------------------
try {
    $d = rh_eod_report_data($pdo, $date, $currency_symbol);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'The End of Day figures could not be loaded, so no PDF was made. Try again in a moment.']);
    exit;
}

require_once __DIR__ . '/../../includes/eod-pdf-builder.php';
$filename = 'eod-report-' . $date . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo buildEodPdf($d, $site_name, $currency_symbol, (string)($user['full_name'] ?? 'Admin'));
exit;
