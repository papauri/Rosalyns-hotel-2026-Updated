<?php

/**
 * Reports Export API
 * Exports payment reports to CSV format
 */

// Admin session guard — this endpoint is called directly from admin/reports.php
// and is NOT routed through api/index.php, so it needs its own auth check.
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out
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

    case 'fnb':
        exportFnbReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'stock':
        exportStockReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'staff':
        exportStaffReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'voids':
        exportVoidsReport($output, $start_date, $end_date, $currency_symbol);
        break;

    case 'aging':
        exportAgingReport($output, $currency_symbol, $conferenceFields);
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as total_transactions,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as total_revenue,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as total_vat
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as count,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as total_revenue,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -vat_amount ELSE vat_amount END) as total_vat
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as count,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as total_amount
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as transaction_count,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as daily_revenue,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as daily_vat
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(p.payment_type, '') != 'refund' THEN 1 END) as transaction_count,
            SUM(CASE WHEN COALESCE(p.payment_type, '') = 'refund' THEN -p.total_amount ELSE p.total_amount END) as total_spent
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE (p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund'
               OR (p.payment_type = 'refund' AND p.refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as transaction_count,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as vat_collected,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as total_revenue
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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
            COUNT(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN 1 END) as count,
            SUM(CASE WHEN COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as vat_collected,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN -total_amount ELSE total_amount END) as total_revenue
        FROM payments
        WHERE (payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund'
               OR (payment_type = 'refund' AND refund_status IN ('completed','processing')))
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

/**
 * Neutralise spreadsheet formula injection in free-text cells (fputcsv handles quoting/escaping).
 */
function rpx_cell($v)
{
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

/**
 * Write a titled section from a prepared query. Failures degrade to an empty section.
 */
function rpx_section($output, string $title, array $headers, string $sql, array $params, callable $mapRow): void
{
    global $pdo;
    fputcsv($output, [$title]);
    fputcsv($output, $headers);
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, array_map('rpx_cell', $mapRow($row)));
        }
    } catch (Throwable $e) {
        error_log('[reports-export] ' . $e->getMessage());
    }
    fputcsv($output, []);
}

/**
 * Export F&B / POS report (mirrors admin/includes/reports-extra-tabs.php)
 */
function exportFnbReport($output, string $start_date, string $end_date, string $cs): void
{
    $p = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    fputcsv($output, ['F&B / POS Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    rpx_section($output, 'SALES BY STATION', ['Station', 'Orders', 'Items', 'Revenue'],
        "SELECT COALESCE(oi.station, 'kitchen') AS station, COUNT(DISTINCT o.id) AS orders, SUM(oi.quantity) AS items_qty, SUM(oi.line_total) AS revenue
         FROM stock_orders o JOIN stock_order_items oi ON oi.order_id = o.id
         WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('voided','cancelled')
         GROUP BY oi.station ORDER BY revenue DESC", $p,
        function ($r) use ($cs) { return [ucfirst((string)$r['station']), $r['orders'], $r['items_qty'], $cs . ' ' . number_format((float)$r['revenue'], 2)]; });

    rpx_section($output, 'TOP ITEMS', ['Item', 'Type', 'Station', 'Qty', 'Orders', 'Revenue'],
        "SELECT oi.item_name, oi.menu_type, COALESCE(oi.station,'kitchen') AS station, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue, COUNT(DISTINCT o.id) AS orders
         FROM stock_orders o JOIN stock_order_items oi ON oi.order_id = o.id
         WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('voided','cancelled')
         GROUP BY oi.item_name, oi.menu_type, oi.station ORDER BY revenue DESC LIMIT 25", $p,
        function ($r) use ($cs) { return [$r['item_name'], $r['menu_type'], $r['station'], $r['qty'], $r['orders'], $cs . ' ' . number_format((float)$r['revenue'], 2)]; });

    rpx_section($output, 'ORDER TYPES', ['Type', 'Orders', 'Revenue', 'Avg Check'],
        "SELECT order_type, COUNT(*) AS n, SUM(total_amount) AS revenue, AVG(total_amount) AS avg_check
         FROM stock_orders WHERE created_at BETWEEN ? AND ? AND status NOT IN ('voided','cancelled')
         GROUP BY order_type ORDER BY revenue DESC", $p,
        function ($r) use ($cs) { return [ucfirst(str_replace('_', ' ', (string)$r['order_type'])), $r['n'], $cs . ' ' . number_format((float)$r['revenue'], 2), $cs . ' ' . number_format((float)$r['avg_check'], 2)]; });

    rpx_section($output, 'PAYMENTS BY METHOD', ['Method', 'Transactions', 'Total'],
        "SELECT payment_method, COUNT(*) AS n, SUM(total_amount) AS total FROM payments
         WHERE booking_type='restaurant' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund'
           AND payment_status IN ('completed','paid','refunded','partially_refunded') AND created_at BETWEEN ? AND ?
         GROUP BY payment_method ORDER BY total DESC", $p,
        function ($r) use ($cs) { return [ucfirst(str_replace('_', ' ', (string)$r['payment_method'])), $r['n'], $cs . ' ' . number_format((float)$r['total'], 2)]; });
}

/**
 * Export Stock report (mirrors admin/includes/reports-extra-tabs.php)
 */
function exportStockReport($output, string $start_date, string $end_date, string $cs): void
{
    $p = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    fputcsv($output, ['Stock Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    rpx_section($output, 'LOW STOCK (current snapshot)', ['Ingredient', 'Current', 'Minimum', 'Unit'],
        "SELECT name, current_quantity AS current_stock, min_quantity AS min_stock_level, unit FROM stock_ingredients
         WHERE is_archived=0 AND current_quantity <= min_quantity
         ORDER BY (current_quantity / NULLIF(min_quantity,0)) ASC LIMIT 30", [],
        function ($r) { return [$r['name'], $r['current_stock'], $r['min_stock_level'], $r['unit']]; });

    rpx_section($output, 'WASTAGE', ['Ingredient', 'Qty', 'Value'],
        "SELECT i.name AS ingredient, ABS(SUM(sa.quantity_change)) AS qty, ABS(SUM(sa.quantity_change * COALESCE(i.cost_per_unit, 0))) AS value
         FROM stock_adjustments sa JOIN stock_ingredients i ON i.id = sa.ingredient_id
         WHERE sa.created_at BETWEEN ? AND ? AND (sa.source_type IN ('waste','wastage') OR sa.reason LIKE '%wast%' OR sa.reason LIKE '%spoil%')
         GROUP BY i.id ORDER BY value DESC LIMIT 20", $p,
        function ($r) use ($cs) { return [$r['ingredient'], $r['qty'], $cs . ' ' . number_format((float)$r['value'], 2)]; });

    rpx_section($output, 'TOP INGREDIENTS USED', ['Ingredient', 'Qty Used', 'Cost'],
        "SELECT i.name, ABS(SUM(sa.quantity_change)) AS qty, ABS(SUM(sa.quantity_change * COALESCE(i.cost_per_unit, 0))) AS cost
         FROM stock_adjustments sa JOIN stock_ingredients i ON i.id = sa.ingredient_id
         WHERE sa.created_at BETWEEN ? AND ? AND sa.quantity_change < 0
           AND sa.source_type IN ('pos_order','sale','consumption','recipe','room_service')
           AND NOT " . rh_voided_wastage_sql('sa') . "
         GROUP BY i.id ORDER BY cost DESC LIMIT 20", $p,
        function ($r) use ($cs) { return [$r['name'], $r['qty'], $cs . ' ' . number_format((float)$r['cost'], 2)]; });
}

/**
 * Export Staff activity report (mirrors admin/includes/reports-extra-tabs.php)
 */
function exportStaffReport($output, string $start_date, string $end_date, string $cs): void
{
    $p = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    fputcsv($output, ['Staff Activity Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    rpx_section($output, 'POS ACTIVITY BY USER', ['User', 'Role', 'Orders', 'Revenue Handled', 'Voids Caused'],
        "SELECT u.id, u.username, u.full_name, u.role, COUNT(o.id) AS orders_created, SUM(o.total_amount) AS revenue_handled,
                SUM(CASE WHEN o.status='voided' THEN 1 ELSE 0 END) AS voids_caused
         FROM admin_users u LEFT JOIN stock_orders o ON o.created_by = u.id AND o.created_at BETWEEN ? AND ?
         WHERE u.is_active=1 GROUP BY u.id HAVING orders_created > 0 ORDER BY revenue_handled DESC", $p,
        function ($r) use ($cs) { return [($r['full_name'] ?: $r['username']), $r['role'], $r['orders_created'], $cs . ' ' . number_format((float)$r['revenue_handled'], 2), $r['voids_caused']]; });

    rpx_section($output, 'KITCHEN DISPLAY ACTIONS', ['User', 'Action', 'Count'],
        "SELECT user_name, event, COUNT(*) AS n FROM stock_kds_events WHERE created_at BETWEEN ? AND ?
         GROUP BY user_name, event ORDER BY user_name, n DESC", $p,
        function ($r) { return [$r['user_name'], $r['event'], $r['n']]; });

    rpx_section($output, 'VOIDS BY USER', ['User', 'Voids', 'Voided Value'],
        "SELECT u.username, u.full_name, COUNT(*) AS voids, SUM(o.total_amount) AS voided_value
         FROM stock_orders o JOIN admin_users u ON u.id = o.voided_by
         WHERE o.voided_at BETWEEN ? AND ? GROUP BY u.id ORDER BY voids DESC", $p,
        function ($r) use ($cs) { return [($r['full_name'] ?: $r['username']), $r['voids'], $cs . ' ' . number_format((float)$r['voided_value'], 2)]; });

    rpx_section($output, 'RECENT LOGINS', ['User', 'Role', 'Last Login'],
        "SELECT u.username, u.full_name, u.role, u.last_login AS last_login_at FROM admin_users u
         WHERE u.is_active=1 AND u.last_login BETWEEN ? AND ? ORDER BY u.last_login DESC LIMIT 50", $p,
        function ($r) { return [($r['full_name'] ?: $r['username']), $r['role'], $r['last_login_at']]; });
}

/**
 * Export Voids / Refunds report (mirrors admin/includes/reports-extra-tabs.php)
 */
function exportVoidsReport($output, string $start_date, string $end_date, string $cs): void
{
    $p = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    fputcsv($output, ['Voids & Refunds Report']);
    fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);

    rpx_section($output, 'VOIDED ORDERS', ['When', 'Reference', 'Type', 'Created By', 'Voided By', 'Value', 'Reason'],
        "SELECT o.reference, o.total_amount, o.voided_at, o.void_reason, u.username AS voided_by_name, u2.username AS created_by_name, o.order_type
         FROM stock_orders o LEFT JOIN admin_users u ON u.id = o.voided_by LEFT JOIN admin_users u2 ON u2.id = o.created_by
         WHERE o.status='voided' AND o.voided_at BETWEEN ? AND ? ORDER BY o.voided_at DESC LIMIT 200", $p,
        function ($r) use ($cs) { return [$r['voided_at'], $r['reference'], $r['order_type'], $r['created_by_name'], $r['voided_by_name'], $cs . ' ' . number_format((float)$r['total_amount'], 2), $r['void_reason']]; });

    rpx_section($output, 'POS REFUNDS', ['When', 'Reference', 'Amount', 'Reason'],
        "SELECT payment_reference, ABS(total_amount) AS amount, refund_reason, created_at AS refunded_at FROM payments
         WHERE booking_type='restaurant' AND payment_type='refund' AND deleted_at IS NULL
           AND COALESCE(refund_status,'completed') IN ('completed','processing') AND created_at BETWEEN ? AND ?
         ORDER BY created_at DESC LIMIT 100", $p,
        function ($r) use ($cs) { return [$r['refunded_at'], $r['payment_reference'], $cs . ' ' . number_format((float)$r['amount'], 2), $r['refund_reason']]; });
}

/**
 * Export Aging & AR (mirrors the Aging tab in admin/reports.php: same rows, same buckets).
 */
function exportAgingReport($output, string $cs, array $conferenceFields): void
{
    global $pdo;
    $rows = [];
    try {
        $st = $pdo->prepare("
            SELECT p.payment_reference, p.payment_date, p.total_amount, p.payment_status,
                   p.booking_type, p.payment_method,
                   DATEDIFF(CURDATE(), p.payment_date) AS days_outstanding,
                   COALESCE(b.guest_name, ci.{$conferenceFields['contact_name']}, so.customer_name, gi.name, ei.name, 'N/A') AS client_name
            FROM payments p
            LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
            LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
            LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
            LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
            LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
            WHERE p.payment_status IN ('pending', 'partial')
              AND COALESCE(p.payment_type, '') != 'refund'
              AND p.deleted_at IS NULL
            ORDER BY days_outstanding DESC
            LIMIT 200
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[reports-export] aging: ' . $e->getMessage());
    }

    $buckets = ['0-30' => [0, 0.0], '31-60' => [0, 0.0], '61-90' => [0, 0.0], '90+' => [0, 0.0]];
    foreach ($rows as $r) {
        $d = (int)$r['days_outstanding'];
        $k = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
        $buckets[$k][0]++;
        $buckets[$k][1] += (float)$r['total_amount'];
    }

    fputcsv($output, ['Aging & Accounts Receivable', 'As of ' . date('Y-m-d')]);
    fputcsv($output, []);
    fputcsv($output, ['Age (days)', 'Count', 'Amount']);
    $total = 0.0;
    foreach ($buckets as $label => [$n, $amt]) {
        fputcsv($output, [$label, $n, $cs . ' ' . number_format($amt, 2)]);
        $total += $amt;
    }
    fputcsv($output, ['Total', count($rows), $cs . ' ' . number_format($total, 2)]);
    fputcsv($output, []);
    fputcsv($output, ['Reference', 'Client', 'Type', 'Method', 'Status', 'Date', 'Days outstanding', 'Amount']);
    foreach ($rows as $r) {
        fputcsv($output, array_map('rpx_cell', [
            $r['payment_reference'], $r['client_name'], ucfirst((string)$r['booking_type']),
            ucwords(str_replace('_', ' ', (string)$r['payment_method'])), ucfirst((string)$r['payment_status']),
            $r['payment_date'], (int)$r['days_outstanding'], $cs . ' ' . number_format((float)$r['total_amount'], 2),
        ]));
    }
}
