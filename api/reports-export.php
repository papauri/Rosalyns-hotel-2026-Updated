<?php

/**
 * Reports Export API
 * Exports payment reports to CSV format
 */

// Admin session guard — this endpoint is called directly from admin/reports.php
// and is NOT routed through api/index.php, so it needs its own auth check.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden: admin session required.';
    exit;
}

// Role/permission guard — only admin, manager, and accountant roles may export
// financial data; a session alone is not sufficient authorisation.
$_exportRole = $_SESSION['admin_role'] ?? '';
if (!in_array($_exportRole, ['admin', 'manager', 'accountant'], true)) {
    require_once __DIR__ . '/../admin/includes/permissions.php';
    if (!hasPermission((int)$_SESSION['admin_user_id'], 'reports')
        && !hasPermission((int)$_SESSION['admin_user_id'], 'accounting')) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Forbidden: insufficient permissions to export financial reports.';
        exit;
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/includes/finance-schema.php';

// Get dynamic conference field names for compatibility
$conferenceFields = finance_conference_fields($pdo);

// Get report type early for filename
$report_type = (string)($_GET['report_type'] ?? 'overview');

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="hotel-report-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $report_type) . '-' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Get parameters
$start_date = (string)($_GET['start_date'] ?? date('Y-m-01'));
$end_date = (string)($_GET['end_date'] ?? date('Y-m-t'));

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// Get currency symbol
$currency_symbol = (string)getSetting('currency_symbol');

// Create output stream
$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    exit;
}

// Build WHERE clause
$date_filter = "AND payment_date >= ? AND payment_date <= ?";

// ============================================
// EXPORT BASED ON REPORT TYPE
// ============================================

switch ($report_type) {
    case 'revenue':
        exportRevenueReport($output, $start_date, $end_date, $date_filter, $currency_symbol, $conferenceFields);
        break;

    case 'outstanding':
        exportOutstandingReport($output, $currency_symbol, $conferenceFields);
        break;

    case 'vat':
        exportVATReport($output, $start_date, $end_date, $date_filter, $currency_symbol);
        break;

    case 'bookings':
        exportBookingsReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'occupancy':
        exportOccupancyReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'guests':
        exportGuestsReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'conference':
        exportConferenceReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'overview':
    default:
        exportOverviewReport($output, $start_date, $end_date, $date_filter, $currency_symbol, $conferenceFields);
        break;
}

fclose($output);
exit;

/**
 * Export Overview Report
 *
 * @param resource $output
 */
function exportOverviewReport($output, string $start_date, string $end_date, string $date_filter, string $currency_symbol, array $conferenceFields): void
{
    global $pdo;

    // Header
    fputcsv($output, ['Payment Overview Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    // Summary Statistics
    fputcsv($output, ['SUMMARY STATISTICS']);

    $summaryQuery = "
        SELECT
            COUNT(*) as total_transactions,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as total_revenue,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as total_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
        $date_filter
    ";
    $summaryStmt = $pdo->prepare($summaryQuery);
    $summaryStmt->execute([$start_date, $end_date]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    fputcsv($output, ['Total Transactions:', $summary['total_transactions']]);
    fputcsv($output, ['Total Revenue:', $currency_symbol . ' ' . number_format($summary['total_revenue'], 2)]);
    fputcsv($output, ['Total VAT Collected:', $currency_symbol . ' ' . number_format($summary['total_vat'], 2)]);
    fputcsv($output, []);

    // Payment Status Breakdown
    fputcsv($output, ['PAYMENT STATUS BREAKDOWN']);
    fputcsv($output, ['Status', 'Count', 'Total Amount']);

    $statusQuery = "
        SELECT
            payment_status,
            COUNT(*) as count,
            SUM(total_amount) as total_amount
        FROM payments
        WHERE deleted_at IS NULL
        GROUP BY payment_status
        ORDER BY payment_status
    ";
    $statusStmt = $pdo->query($statusQuery);

    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            ucfirst($row['payment_status']),
            $row['count'],
            $currency_symbol . ' ' . number_format($row['total_amount'], 2)
        ]);
    }
    fputcsv($output, []);

    // Revenue by Booking Type
    fputcsv($output, ['REVENUE BY BOOKING TYPE']);
    fputcsv($output, ['Booking Type', 'Transactions', 'Revenue', 'VAT Amount']);

    $revenueQuery = "
        SELECT
            booking_type,
            COUNT(*) as count,
            SUM(total_amount) as total_revenue,
            SUM(vat_amount) as total_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
        $date_filter
        GROUP BY booking_type
    ";
    $revenueStmt = $pdo->prepare($revenueQuery);
    $revenueStmt->execute([$start_date, $end_date]);

    while ($row = $revenueStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            ucfirst($row['booking_type']),
            $row['count'],
            $currency_symbol . ' ' . number_format($row['total_revenue'], 2),
            $currency_symbol . ' ' . number_format($row['total_vat'], 2)
        ]);
    }
    fputcsv($output, []);

    // Payment Method Breakdown
    fputcsv($output, ['PAYMENT METHOD BREAKDOWN']);
    fputcsv($output, ['Payment Method', 'Transactions', 'Total Amount']);

    $methodsQuery = "
        SELECT
            payment_method,
            COUNT(*) as count,
            SUM(total_amount) as total_amount
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
        $date_filter
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ";
    $methodsStmt = $pdo->prepare($methodsQuery);
    $methodsStmt->execute([$start_date, $end_date]);

    while ($row = $methodsStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            ucfirst(str_replace('_', ' ', $row['payment_method'])),
            $row['count'],
            $currency_symbol . ' ' . number_format($row['total_amount'], 2)
        ]);
    }
}

/**
 * Export Revenue Report
 *
 * @param resource $output
 */
function exportRevenueReport($output, string $start_date, string $end_date, string $date_filter, string $currency_symbol, array $conferenceFields): void
{
    global $pdo;

    // Header
    fputcsv($output, ['Revenue Analysis Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    // Daily Revenue
    fputcsv($output, ['DAILY REVENUE']);
    fputcsv($output, ['Date', 'Transactions', 'Revenue', 'VAT Amount']);

    $dailyQuery = "
        SELECT
            DATE(payment_date) as date,
            COUNT(*) as transaction_count,
            SUM(total_amount) as daily_revenue,
            SUM(vat_amount)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as daily_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
        $date_filter
        GROUP BY DATE(payment_date)
        ORDER BY date ASC
    ";
    $dailyStmt = $pdo->prepare($dailyQuery);
    $dailyStmt->execute([$start_date, $end_date]);

    while ($row = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['date'],
            $row['transaction_count'],
            $currency_symbol . ' ' . number_format($row['daily_revenue'], 2),
            $currency_symbol . ' ' . number_format($row['daily_vat'], 2)
        ]);
    }
    fputcsv($output, []);

    // Top Clients
    fputcsv($output, ['TOP CLIENTS BY REVENUE']);
    fputcsv($output, ['Client', 'Booking Type', 'Transactions', 'Total Spent']);

    $clientsQuery = "
        SELECT
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_name
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
            END as client_name,
            p.booking_type,
            COUNT(*) as transaction_count,
            SUM(p.total_amount) as total_spent
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.payment_status IN ('completed', 'paid')
        AND COALESCE(p.payment_type, '') != 'refund'
        AND p.deleted_at IS NULL
        $date_filter
        GROUP BY client_name, p.booking_type
        ORDER BY total_spent DESC
        LIMIT 20
    ";
    $clientsStmt = $pdo->prepare($clientsQuery);
    $clientsStmt->execute([$start_date, $end_date]);

    while ($row = $clientsStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['client_name'],
            ucfirst($row['booking_type']),
            $row['transaction_count'],
            $currency_symbol . ' ' . number_format($row['total_spent'], 2)
        ]);
    }
}

/**
 * Export Outstanding Payments Report
 *
 * @param resource $output
 */
function exportOutstandingReport($output, string $currency_symbol, array $conferenceFields): void
{
    global $pdo;

    // Header
    fputcsv($output, ['Outstanding Payments Report']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    fputcsv($output, [
        'Payment Reference',
        'Booking Reference',
        'Booking Type',
        'Client',
        'Amount Due',
        'VAT Amount',
        'Total Amount',
        'Status',
        'Due Date',
        'Days Overdue'
    ]);

    $query = "
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN b.booking_reference
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
            END as booking_reference,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.guest_email, ')')
                WHEN p.booking_type = 'conference' THEN CONCAT(ci.{$conferenceFields['company']}, ' - ', ci.{$conferenceFields['contact_name']})
            END as client_info,
            DATEDIFF(CURDATE(), p.payment_date) as days_overdue
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.payment_status IN ('pending', 'partial')
        AND p.deleted_at IS NULL
        ORDER BY p.payment_date ASC
    ";
    $stmt = $pdo->query($query);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['payment_reference'],
            $row['booking_reference'],
            ucfirst($row['booking_type']),
            $row['client_info'],
            $currency_symbol . ' ' . number_format($row['payment_amount'], 2),
            $currency_symbol . ' ' . number_format($row['vat_amount'], 2),
            $currency_symbol . ' ' . number_format($row['total_amount'], 2),
            ucfirst($row['payment_status']),
            date('Y-m-d', strtotime($row['payment_date'])),
            $row['days_overdue'] > 0 ? $row['days_overdue'] : 0
        ]);
    }

    fputcsv($output, []);

    // Summary
    $summaryQuery = "
        SELECT
            COUNT(*) as total_outstanding,
            SUM(total_amount) as total_amount
        FROM payments
        WHERE payment_status IN ('pending', 'partial')
        AND deleted_at IS NULL
    ";
    $summaryStmt = $pdo->query($summaryQuery);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Outstanding Payments:', $summary['total_outstanding']]);
    fputcsv($output, ['Total Outstanding Amount:', $currency_symbol . ' ' . number_format($summary['total_amount'], 2)]);
}

/**
 * Export VAT Report
 *
 * @param resource $output
 */
function exportVATReport($output, string $start_date, string $end_date, string $date_filter, string $currency_symbol): void
{
    global $pdo;

    // Header
    fputcsv($output, ['VAT Collection Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, ['VAT Rate:', getSetting('vat_rate') . '%']);
    fputcsv($output, ['VAT Number:', getSetting('vat_number')]);
    fputcsv($output, []);

    // Daily VAT Collection
    fputcsv($output, ['DAILY VAT COLLECTION']);
    fputcsv($output, ['Date', 'Transactions', 'VAT Collected', 'Total Revenue']);

    $dailyQuery = "
        SELECT
            DATE(payment_date) as date,
            COUNT(*) as transaction_count,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as vat_collected,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as total_revenue
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND deleted_at IS NULL
        $date_filter
        GROUP BY DATE(payment_date)
        ORDER BY date ASC
    ";
    $dailyStmt = $pdo->prepare($dailyQuery);
    $dailyStmt->execute([$start_date, $end_date]);

    $totalVat = 0;
    $totalRevenue = 0;

    while ($row = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['date'],
            $row['transaction_count'],
            $currency_symbol . ' ' . number_format($row['vat_collected'], 2),
            $currency_symbol . ' ' . number_format($row['total_revenue'], 2)
        ]);

        $totalVat += $row['vat_collected'];
        $totalRevenue += $row['total_revenue'];
    }

    fputcsv($output, []);
    fputcsv($output, ['TOTALS']);
    fputcsv($output, ['Total VAT Collected:', $currency_symbol . ' ' . number_format($totalVat, 2)]);
    fputcsv($output, ['Total Revenue:', $currency_symbol . ' ' . number_format($totalRevenue, 2)]);
    fputcsv($output, []);

    // VAT by Booking Type
    fputcsv($output, ['VAT BY BOOKING TYPE']);
    fputcsv($output, ['Booking Type', 'Transactions', 'VAT Collected', 'Total Revenue']);

    $typeQuery = "
        SELECT
            booking_type,
            COUNT(*) as count,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as vat_collected,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as total_revenue
        FROM payments
        WHERE payment_status IN ('completed', 'paid')
        AND deleted_at IS NULL
        $date_filter
        GROUP BY booking_type
    ";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute([$start_date, $end_date]);

    while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            ucfirst($row['booking_type']),
            $row['count'],
            $currency_symbol . ' ' . number_format($row['vat_collected'], 2),
            $currency_symbol . ' ' . number_format($row['total_revenue'], 2)
        ]);
    }
}

/**
 * Export Bookings Report
 *
 * @param resource $output
 */
function exportBookingsReport($output, string $start_date, string $end_date, string $currency_symbol): void
{
    global $pdo;

    fputcsv($output, ['Bookings Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    fputcsv($output, ['BOOKING STATUS SUMMARY']);
    fputcsv($output, ['Status', 'Count', 'Total Value']);

    $statusStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_value
        FROM bookings WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status ORDER BY count DESC
    ");
    $statusStmt->execute([$start_date, $end_date]);
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [ucfirst($row['status']), $row['count'], $currency_symbol . ' ' . number_format($row['total_value'], 2)]);
    }
    fputcsv($output, []);

    fputcsv($output, ['BOOKINGS BY ROOM TYPE']);
    fputcsv($output, ['Room', 'Bookings', 'Total Nights', 'Revenue']);

    $roomStmt = $pdo->prepare("
        SELECT r.name, COUNT(b.id) as count, COALESCE(SUM(b.number_of_nights), 0) as nights, COALESCE(SUM(b.total_amount), 0) as revenue
        FROM rooms r LEFT JOIN bookings b ON r.id = b.room_id AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY) AND b.status != 'cancelled'
        WHERE r.is_active = 1 GROUP BY r.id, r.name ORDER BY count DESC
    ");
    $roomStmt->execute([$start_date, $end_date]);
    while ($row = $roomStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['name'], $row['count'], $row['nights'], $currency_symbol . ' ' . number_format($row['revenue'], 2)]);
    }
    fputcsv($output, []);

    fputcsv($output, ['ALL BOOKINGS']);
    fputcsv($output, ['Reference', 'Guest', 'Email', 'Room', 'Check-in', 'Check-out', 'Nights', 'Amount', 'Status']);

    $allStmt = $pdo->prepare("
        SELECT b.*, r.name as room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY) ORDER BY b.created_at DESC
    ");
    $allStmt->execute([$start_date, $end_date]);
    while ($row = $allStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['booking_reference'],
            $row['guest_name'],
            $row['guest_email'],
            $row['room_name'] ?? 'N/A',
            $row['check_in_date'],
            $row['check_out_date'],
            $row['number_of_nights'],
            $currency_symbol . ' ' . number_format($row['total_amount'], 2),
            ucfirst($row['status'])
        ]);
    }
}

/**
 * Export Occupancy Report
 *
 * @param resource $output
 */
function exportOccupancyReport($output, string $start_date, string $end_date, string $currency_symbol): void
{
    global $pdo;

    fputcsv($output, ['Occupancy Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    $daysInPeriod = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);

    fputcsv($output, ['OCCUPANCY BY ROOM TYPE']);
    fputcsv($output, ['Room', 'Total Rooms', 'Bookings', 'Nights Booked', 'Guests', 'Occupancy Rate']);

    $occStmt = $pdo->prepare("
        SELECT r.name, r.total_rooms, COUNT(DISTINCT b.id) as bookings, COALESCE(SUM(b.number_of_nights), 0) as nights,
               COALESCE(SUM(b.number_of_guests), 0) as guests
        FROM rooms r LEFT JOIN bookings b ON r.id = b.room_id AND b.check_in_date <= ? AND b.check_out_date >= ?
            AND b.status IN ('confirmed', 'checked-in', 'checked-out')
        WHERE r.is_active = 1 GROUP BY r.id, r.name, r.total_rooms ORDER BY nights DESC
    ");
    $occStmt->execute([$end_date, $start_date]);
    while ($row = $occStmt->fetch(PDO::FETCH_ASSOC)) {
        $avail = $row['total_rooms'] * $daysInPeriod;
        $rate = $avail > 0 ? round(($row['nights'] / $avail) * 100, 1) : 0;
        fputcsv($output, [$row['name'], $row['total_rooms'], $row['bookings'], $row['nights'], $row['guests'], $rate . '%']);
    }
}

/**
 * Export Guests Report
 *
 * @param resource $output
 */
function exportGuestsReport($output, string $start_date, string $end_date, string $currency_symbol): void
{
    global $pdo;

    fputcsv($output, ['Guest Analytics Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    fputcsv($output, ['GUEST ORIGIN COUNTRIES']);
    fputcsv($output, ['Country', 'Bookings', 'Guests', 'Revenue']);

    $countryStmt = $pdo->prepare("
        SELECT COALESCE(guest_country, 'Not Specified') as country, COUNT(*) as bookings,
               COALESCE(SUM(number_of_guests), 0) as guests, COALESCE(SUM(total_amount), 0) as revenue
        FROM bookings WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY) AND status != 'cancelled'
        GROUP BY country ORDER BY bookings DESC
    ");
    $countryStmt->execute([$start_date, $end_date]);
    while ($row = $countryStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['country'], $row['bookings'], $row['guests'], $currency_symbol . ' ' . number_format($row['revenue'], 2)]);
    }
    fputcsv($output, []);

    fputcsv($output, ['REPEAT GUESTS']);
    fputcsv($output, ['Guest', 'Email', 'Country', 'Bookings', 'Total Spent', 'First Visit', 'Last Visit']);

    $repeatStmt = $pdo->prepare("
        SELECT guest_name, guest_email, guest_country, COUNT(*) as bookings, COALESCE(SUM(total_amount), 0) as spent,
               MIN(check_in_date) as first_visit, MAX(check_in_date) as last_visit
        FROM bookings WHERE status != 'cancelled' AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY guest_name, guest_email, guest_country HAVING bookings > 1 ORDER BY bookings DESC
    ");
    $repeatStmt->execute([$start_date, $end_date]);
    while ($row = $repeatStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['guest_name'],
            $row['guest_email'],
            $row['guest_country'] ?? 'N/A',
            $row['bookings'],
            $currency_symbol . ' ' . number_format($row['spent'], 2),
            $row['first_visit'],
            $row['last_visit']
        ]);
    }
}

/**
 * Export Conference Report
 *
 * @param resource $output
 */
function exportConferenceReport($output, string $start_date, string $end_date, string $currency_symbol): void
{
    global $pdo;

    fputcsv($output, ['Conference & Events Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    fputcsv($output, ['CONFERENCE INQUIRY STATUS']);
    fputcsv($output, ['Status', 'Count', 'Total Value', 'Amount Paid']);

    $confStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as value, COALESCE(SUM(amount_paid), 0) as paid
        FROM conference_inquiries WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $confStmt->execute([$start_date, $end_date]);
    while ($row = $confStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [ucfirst($row['status']), $row['count'], $currency_symbol . ' ' . number_format($row['value'], 2), $currency_symbol . ' ' . number_format($row['paid'], 2)]);
    }
    fputcsv($output, []);

    fputcsv($output, ['CONFERENCE ROOM UTILIZATION']);
    fputcsv($output, ['Room', 'Capacity', 'Events', 'Avg Attendees', 'Revenue']);

    $roomStmt = $pdo->prepare("
        SELECT cr.name, cr.capacity, COUNT(ci.id) as events, COALESCE(AVG(ci.number_of_attendees), 0) as avg_att,
               COALESCE(SUM(ci.total_amount), 0) as revenue
        FROM conference_rooms cr LEFT JOIN conference_inquiries ci ON cr.id = ci.conference_room_id
            AND ci.created_at >= ? AND ci.created_at <= DATE_ADD(?, INTERVAL 1 DAY) AND ci.status != 'cancelled'
        WHERE cr.is_active = 1 GROUP BY cr.id, cr.name, cr.capacity ORDER BY events DESC
    ");
    $roomStmt->execute([$start_date, $end_date]);
    while ($row = $roomStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['name'], $row['capacity'], $row['events'], round($row['avg_att']), $currency_symbol . ' ' . number_format($row['revenue'], 2)]);
    }
    fputcsv($output, []);

    fputcsv($output, ['GYM INQUIRIES']);
    fputcsv($output, ['Status', 'Count']);

    $gymStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count FROM gym_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY) GROUP BY status
    ");
    $gymStmt->execute([$start_date, $end_date]);
    while ($row = $gymStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [ucfirst($row['status']), $row['count']]);
    }
}
