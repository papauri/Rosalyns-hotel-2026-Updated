<?php

/**
 * Comprehensive Hotel Reports & Analytics
 * Provides multi-tab reporting: Overview, Revenue, Bookings, Occupancy, Guests, Conference
 * With error handling, CSV export, and date filtering
 */

// Include admin initialization (PHP-only, no HTML output)
require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/includes/finance-schema.php';

// Get date range from query parameters or default to current month
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$active_tab = $_GET['tab'] ?? 'overview';

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}
// Ensure start is not after end
if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

// Module flags — a tab whose module is disabled for this installation's
// business preset should not be reachable, whether via the tab nav or a
// direct ?tab= URL (mirrors the gating already applied on
// accounting-dashboard.php / end-of-day-report.php / dashboard.php).
$mod_bookings   = function_exists('moduleEnabled') && moduleEnabled('bookings');
$mod_pos        = function_exists('moduleEnabled') && moduleEnabled('pos');
$mod_stock      = function_exists('moduleEnabled') && moduleEnabled('stock');
$mod_conference = function_exists('moduleEnabled') && moduleEnabled('conference');
$mod_gym        = function_exists('moduleEnabled') && moduleEnabled('gym');
// Events has no dedicated module toggle — gated by its own legacy setting.
$mod_events     = function_exists('isEventsEnabled') && isEventsEnabled();

// Tabs that are always available regardless of module state (cross-cutting
// financial views) vs tabs that belong entirely to one module.
$tab_module_map = [
    'bookings'  => $mod_bookings,
    'occupancy' => $mod_bookings,
    'guests'    => $mod_bookings,
    'conference' => ($mod_conference || $mod_gym || $mod_events),
    'fnb'       => $mod_pos,
    'stock'     => $mod_stock,
    'staff'     => $mod_pos,
    'voids'     => $mod_pos,
];

// Sanitize tab
$valid_tabs = ['overview', 'revenue', 'vat', 'aging', 'bookings', 'occupancy', 'guests', 'conference', 'fnb', 'stock', 'staff', 'voids'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'overview';
}
// A tab tied to a disabled module isn't just hidden from the tab nav below —
// direct URL access falls back to Overview too.
if (isset($tab_module_map[$active_tab]) && !$tab_module_map[$active_tab]) {
    $active_tab = 'overview';
}

// Get currency symbol and VAT settings
$currency_symbol = getSetting('currency_symbol');
$vatEnabled = in_array(getSetting('vat_enabled'), ['1', 'true', 'on']);
$vatRate = (float)getSetting('vat_rate', 0);
$conferenceFields = finance_conference_fields($pdo);

// Initialize all data arrays with defaults
$statusData = [];
$revenueByType = [];
$paymentMethods = [];
$outstandingPayments = [];
$dailyRevenue = [];
$monthlyRevenue = [];
$vatCollected = [];
$topClients = [];
$bookingStatusData = [];
$roomBookingStats = [];
$occupancyData = [];
$guestCountryData = [];
$repeatGuests = [];
$conferenceStats = [];
$conferenceRoomStats = [];
$recentBookings = [];
$reviewStats = [];
$gymInquiryStats = [];
$gymRevenueTrend = [];
$eventInquiryStats = [];
$eventRevenueTrend = [];
$refundReasons = [];
$refundStatuses = [];
$refundTrends = [];

$totalRevenue = 0;
$totalVatCollected = 0;
$totalTransactions = 0;
$totalOutstanding = 0;
$totalBookings = 0;
$avgStayLength = 0;
$avgRevenuePerBooking = 0;
$cancellationRate = 0;
$totalStatusCount = 0;
$totalRefunds = 0;
$pendingRefunds = 0;
$completedRefunds = 0;
$error = null;

$adr = 0;
$revpar = 0;
$noShowRate = 0;
$adrData = ['total_room_revenue' => 0, 'total_nights_sold' => 0];
$noShowData = ['total_confirmed' => 0, 'no_shows' => 0];
$forecastData = ['upcoming_bookings' => 0, 'forecast_revenue' => 0, 'upcoming_nights' => 0];
$cancelData = ['total' => 0, 'cancelled' => 0];
$monthlyAdr = [];
$totalRoomNightsAvailable = 0;
$totalRoomInventory = 0;
$overallOccupancyRate = 0;
$overallOccupancy = ['total_nights_booked' => 0, 'total_bookings' => 0, 'total_guests' => 0, 'total_adults' => 0, 'total_children' => 0, 'avg_guests_per_booking' => 0];

$statusLabels = [
    'pending' => 'Pending',
    'partial' => 'Partial Payment',
    'paid' => 'Paid',
    'completed' => 'Completed',
    'refunded' => 'Refunded',
    'cancelled' => 'Cancelled'
];

// New analytics defaults
$priorRevenue = 0.0;
$priorTxns = 0;
$priorBookings = 0;
$priorStartDate = '';
$priorEndDate = '';
$periodDays = 1;
$totalCreditNotesIssued = 0.0;
$totalCreditNotesRedeemed = 0.0;
$deferredRevenue = 0.0;
$deferredBookings = 0;
$vatByQuarter = [];
$quoteFunnel = [];
$quoteTotalValue = 0.0;
$quoteConvRate = 0.0;
$roomTypeAdr = [];

/**
 * Returns an HTML delta badge comparing current vs prior period.
 * @param float $current
 * @param float $prior
 * @return string
 */
function rh_reports_delta(float $current, float $prior): string
{
    if ($prior == 0) {
        return $current > 0 ? '<span class="rh-delta rh-delta--up">New</span>' : '';
    }
    $pct = round((($current - $prior) / $prior) * 100, 1);
    $cls  = $pct >= 0 ? 'rh-delta--up' : 'rh-delta--down';
    $icon = $pct >= 0 ? '↑' : '↓';
    return '<span class="rh-delta ' . $cls . '">' . $icon . ' ' . abs($pct) . '%</span>';
}

try {
    // ============================================
    // OVERVIEW TAB QUERIES
    // ============================================

    // 1. Payment Status Overview (date-filtered)
    $statusStmt = $pdo->prepare("
        SELECT payment_status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount
        FROM payments WHERE deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY payment_status
    ");
    $statusStmt->execute([$start_date, $end_date]);
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $statusData[$row['payment_status']] = $row;
        $totalStatusCount += $row['count'];
    }

    // 2. Revenue by Booking Type (date filtered)
    $revenueByTypeStmt = $pdo->prepare("
        SELECT booking_type, COUNT(*) as count,
               COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_revenue,
               COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount
                                 WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -vat_amount
                                 ELSE 0 END), 0) as total_vat
        FROM payments
        WHERE (payment_status IN ('completed', 'paid') OR payment_type = 'refund') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY booking_type
    ");
    $revenueByTypeStmt->execute([$start_date, $end_date]);
    $revenueByType = $revenueByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($revenueByType as $revenue) {
        $totalRevenue += $revenue['total_revenue'];
        $totalVatCollected += $revenue['total_vat'];
        $totalTransactions += $revenue['count'];
    }

    // 3. Outstanding Payments (all-time — show all unpaid regardless of date filter)
    $outstandingStmt = $pdo->prepare("
        SELECT p.*,
            CASE WHEN p.booking_type = 'room' THEN b.booking_reference
                 WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
                 WHEN p.booking_type = 'gym' THEN gi.reference_number
                 WHEN p.booking_type = 'event' THEN ei.reference_number
            END as ref_number,
            CASE WHEN p.booking_type = 'room' THEN b.guest_name
                 WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
                 WHEN p.booking_type = 'gym' THEN gi.name
                 WHEN p.booking_type = 'event' THEN ei.name
            END as client_name,
            DATEDIFF(CURDATE(), p.payment_date) as days_overdue
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
        WHERE p.payment_status IN ('pending', 'partial') AND p.deleted_at IS NULL
          AND p.payment_date <= ?
        ORDER BY p.payment_date ASC
    ");
    $outstandingStmt->execute([$end_date]);
    $outstandingPayments = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($outstandingPayments as $payment) {
        $totalOutstanding += $payment['total_amount'];
    }

    // ============================================
    // REVENUE TAB QUERIES
    // ============================================

    // 4. Payment Method Breakdown
    $paymentMethodsStmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY payment_method ORDER BY total_amount DESC
    ");
    $paymentMethodsStmt->execute([$start_date, $end_date]);
    $paymentMethods = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Daily Revenue Trend
    $dailyRevenueStmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COUNT(*) as transaction_count,
               COALESCE(SUM(total_amount), 0) as daily_revenue,
               COALESCE(SUM(vat_amount), 0) as daily_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY DATE(payment_date) ORDER BY date ASC
    ");
    $dailyRevenueStmt->execute([$start_date, $end_date]);
    $dailyRevenue = $dailyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Monthly Revenue Trend
    $monthlyRevenueStmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               DATE_FORMAT(payment_date, '%b %Y') as month_label,
               COUNT(*) as transaction_count,
               COALESCE(SUM(total_amount), 0) as monthly_revenue,
               COALESCE(SUM(vat_amount), 0) as monthly_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY month, month_label ORDER BY month ASC
    ");
    $monthlyRevenueStmt->execute([$start_date, $end_date]);
    $monthlyRevenue = $monthlyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. VAT Collected
    $vatCollectedStmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COUNT(*) as transaction_count,
               COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount
                                 WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -vat_amount
                                 ELSE 0 END), 0) as vat_collected,
               COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_revenue
        FROM payments
        WHERE (payment_status IN ('completed', 'paid') OR payment_type = 'refund') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY DATE(payment_date) ORDER BY date ASC
    ");
    $vatCollectedStmt->execute([$start_date, $end_date]);
    $vatCollected = $vatCollectedStmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Top Clients by Revenue
    $topClientsStmt = $pdo->prepare("
        SELECT
            CASE WHEN p.booking_type = 'room' THEN b.guest_name
                 WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
                 WHEN p.booking_type = 'gym' THEN gi.name
                 WHEN p.booking_type = 'event' THEN ei.name
            END as client_name,
            CASE WHEN p.booking_type = 'room' THEN b.guest_email
                 WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
                 WHEN p.booking_type = 'gym' THEN gi.email
                 WHEN p.booking_type = 'event' THEN ei.email
            END as client_email,
            p.booking_type, COUNT(*) as transaction_count,
            COALESCE(SUM(p.total_amount), 0) as total_spent
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
        WHERE p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' AND p.deleted_at IS NULL
        AND p.payment_date >= ? AND p.payment_date <= ?
        GROUP BY client_name, client_email, p.booking_type
        ORDER BY total_spent DESC LIMIT 10
    ");
    $topClientsStmt->execute([$start_date, $end_date]);
    $topClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // REFUND ANALYSIS QUERIES
    // ============================================

    // 9. Refund Breakdown by Reason
    $refundReasonStmt = $pdo->prepare("
        SELECT refund_reason, COUNT(*) as count,
               COALESCE(SUM(refund_amount), 0) as total_amount
        FROM payments
        WHERE payment_type = 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY refund_reason
        ORDER BY total_amount DESC
    ");
    $refundReasonStmt->execute([$start_date, $end_date]);
    $refundReasons = $refundReasonStmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Refund Status Breakdown
    $refundStatusStmt = $pdo->prepare("
        SELECT refund_status, COUNT(*) as count,
               COALESCE(SUM(refund_amount), 0) as total_amount
        FROM payments
        WHERE payment_type = 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY refund_status
        ORDER BY refund_status ASC
    ");
    $refundStatusStmt->execute([$start_date, $end_date]);
    $refundStatuses = $refundStatusStmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Refund Trends by Date
    $refundTrendStmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COUNT(*) as count,
               COALESCE(SUM(refund_amount), 0) as daily_refunds,
               refund_reason
        FROM payments
        WHERE payment_type = 'refund' AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY DATE(payment_date), refund_reason
        ORDER BY date ASC
    ");
    $refundTrendStmt->execute([$start_date, $end_date]);
    $refundTrends = $refundTrendStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate refund totals
    foreach ($refundReasons as $reason) {
        $totalRefunds += $reason['total_amount'];
    }
    foreach ($refundStatuses as $status) {
        if ($status['refund_status'] === 'pending') {
            $pendingRefunds = $status['total_amount'];
        } elseif ($status['refund_status'] === 'completed') {
            $completedRefunds = $status['total_amount'];
        }
    }

    // ============================================
    // BOOKINGS TAB QUERIES
    // ============================================

    // 9. Booking Status Breakdown
    $bookingStatusStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status ORDER BY count DESC
    ");
    $bookingStatusStmt->execute([$start_date, $end_date]);
    $bookingStatusData = $bookingStatusStmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Room-level Booking Stats
    $roomBookingStmt = $pdo->prepare("
        SELECT r.name as room_name, r.price_per_night,
               COUNT(b.id) as booking_count,
               COALESCE(SUM(b.number_of_nights), 0) as total_nights,
               COALESCE(SUM(b.total_amount), 0) as total_revenue,
               COALESCE(AVG(b.number_of_nights), 0) as avg_stay
        FROM rooms r
        LEFT JOIN bookings b ON r.id = b.room_id
            AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
            AND b.status NOT IN ('cancelled')
        WHERE r.is_active = 1
        GROUP BY r.id, r.name, r.price_per_night
        ORDER BY booking_count DESC
    ");
    $roomBookingStmt->execute([$start_date, $end_date]);
    $roomBookingStats = $roomBookingStmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Booking Summary Metrics
    $bookingSummaryStmt = $pdo->prepare("
        SELECT COUNT(*) as total_bookings,
               COALESCE(AVG(number_of_nights), 0) as avg_stay_length,
               COALESCE(SUM(total_amount), 0) as total_booking_value,
               COALESCE(AVG(total_amount), 0) as avg_booking_value,
               COALESCE(SUM(number_of_guests), 0) as total_guests,
               COALESCE(SUM(COALESCE(adult_guests, GREATEST(number_of_guests - COALESCE(child_guests, 0), 1))), 0) as total_adults,
               COALESCE(SUM(COALESCE(child_guests, 0)), 0) as total_children,
               COALESCE(SUM(COALESCE(child_supplement_total, 0)), 0) as total_child_revenue
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
    ");
    $bookingSummaryStmt->execute([$start_date, $end_date]);
    $bookingSummary = $bookingSummaryStmt->fetch(PDO::FETCH_ASSOC);
    $totalBookings = $bookingSummary['total_bookings'];
    $avgStayLength = round($bookingSummary['avg_stay_length'], 1);
    $avgRevenuePerBooking = $bookingSummary['avg_booking_value'];

    // 12. Cancellation Rate
    $cancelStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $cancelStmt->execute([$start_date, $end_date]);
    $cancelData = $cancelStmt->fetch(PDO::FETCH_ASSOC);
    $cancellationRate = $cancelData['total'] > 0 ? round(($cancelData['cancelled'] / $cancelData['total']) * 100, 1) : 0;

    // 13. Recent Bookings
    $recentBookingsStmt = $pdo->prepare("
        SELECT b.*, r.name as room_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        ORDER BY b.created_at DESC LIMIT 15
    ");
    $recentBookingsStmt->execute([$start_date, $end_date]);
    $recentBookings = $recentBookingsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // OCCUPANCY TAB QUERIES
    // ============================================

    // 14. Occupancy Data by Room Type
    $occupancyStmt = $pdo->prepare("
        SELECT r.name as room_name, r.total_rooms,
               COUNT(DISTINCT b.id) as bookings,
               COALESCE(SUM(b.number_of_nights), 0) as nights_booked,
               COALESCE(SUM(b.number_of_guests), 0) as total_guests,
               COALESCE(SUM(COALESCE(b.adult_guests, GREATEST(b.number_of_guests - COALESCE(b.child_guests, 0), 1))), 0) as total_adults,
               COALESCE(SUM(COALESCE(b.child_guests, 0)), 0) as total_children
        FROM rooms r
        LEFT JOIN bookings b ON r.id = b.room_id
            AND b.check_in_date <= ? AND b.check_out_date >= ?
            AND b.status IN ('confirmed', 'checked-in', 'checked-out')
        WHERE r.is_active = 1
        GROUP BY r.id, r.name, r.total_rooms
        ORDER BY nights_booked DESC
    ");
    $occupancyStmt->execute([$end_date, $start_date]);
    $occupancyData = $occupancyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate days in period for occupancy rate
    $daysInPeriod = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);

    // 15. Overall occupancy metrics
    $overallOccupancyStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT b.id) as total_bookings,
            COALESCE(SUM(b.number_of_nights), 0) as total_nights_booked,
            COALESCE(SUM(b.number_of_guests), 0) as total_guests,
            COALESCE(SUM(COALESCE(b.adult_guests, GREATEST(b.number_of_guests - COALESCE(b.child_guests, 0), 1))), 0) as total_adults,
            COALESCE(SUM(COALESCE(b.child_guests, 0)), 0) as total_children,
            COALESCE(AVG(b.number_of_guests), 0) as avg_guests_per_booking
        FROM bookings b
        WHERE b.check_in_date <= ? AND b.check_out_date >= ?
        AND b.status IN ('confirmed', 'checked-in', 'checked-out')
    ");
    $overallOccupancyStmt->execute([$end_date, $start_date]);
    $overallOccupancy = $overallOccupancyStmt->fetch(PDO::FETCH_ASSOC);

    // Total room inventory
    $totalRoomsStmt = $pdo->query("SELECT COUNT(*) as total FROM individual_rooms WHERE status NOT IN ('out_of_order','maintenance')");
    $totalRoomInventory = $totalRoomsStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalRoomNightsAvailable = $totalRoomInventory * $daysInPeriod;
    $overallOccupancyRate = $totalRoomNightsAvailable > 0
        ? round(($overallOccupancy['total_nights_booked'] / $totalRoomNightsAvailable) * 100, 1)
        : 0;

    // ============================================
    // GUESTS TAB QUERIES
    // ============================================

    // 16. Guest Country Distribution
    $guestCountryStmt = $pdo->prepare("
        SELECT COALESCE(guest_country, 'Not Specified') as country,
               COUNT(*) as booking_count,
               COALESCE(SUM(total_amount), 0) as total_spent,
               COALESCE(SUM(number_of_guests), 0) as total_guests,
               COALESCE(SUM(COALESCE(adult_guests, GREATEST(number_of_guests - COALESCE(child_guests, 0), 1))), 0) as total_adults,
               COALESCE(SUM(COALESCE(child_guests, 0)), 0) as total_children
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
        GROUP BY country ORDER BY booking_count DESC LIMIT 15
    ");
    $guestCountryStmt->execute([$start_date, $end_date]);
    $guestCountryData = $guestCountryStmt->fetchAll(PDO::FETCH_ASSOC);

    // 17. Repeat Guests
    $repeatGuestsStmt = $pdo->prepare("
        SELECT b.guest_name, b.guest_email, b.guest_country,
               COUNT(b.id) as booking_count,
               COALESCE(SUM(b.total_amount), 0) as total_spent,
               MIN(b.check_in_date) as first_visit,
               MAX(b.check_in_date) as last_visit
        FROM bookings b
        WHERE b.status NOT IN ('cancelled')
        AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND b.guest_email IN (
            SELECT guest_email FROM bookings
            WHERE status NOT IN ('cancelled')
            GROUP BY guest_email
            HAVING COUNT(*) > 1
        )
        GROUP BY b.guest_name, b.guest_email, b.guest_country
        ORDER BY booking_count DESC LIMIT 15
    ");
    $repeatGuestsStmt->execute([$start_date, $end_date]);
    $repeatGuests = $repeatGuestsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 18. Guest Metrics
    $guestMetricsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT guest_email) as unique_guests,
               COUNT(*) as total_bookings,
               COALESCE(AVG(total_amount), 0) as avg_spend
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
    ");
    $guestMetricsStmt->execute([$start_date, $end_date]);
    $guestMetrics = $guestMetricsStmt->fetch(PDO::FETCH_ASSOC);

    // 19. Review Stats
    $reviewStatsStmt = $pdo->prepare("
        SELECT COUNT(*) as total_reviews,
               COALESCE(AVG(rating), 0) as avg_rating,
               SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive_reviews,
               SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative_reviews
        FROM reviews
        WHERE status = 'approved'
        AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $reviewStatsStmt->execute([$start_date, $end_date]);
    $reviewStats = $reviewStatsStmt->fetch(PDO::FETCH_ASSOC);

    // ============================================
    // CONFERENCE TAB QUERIES
    // ============================================

    // 20. Conference Inquiry Stats
    $conferenceStatsStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value,
               COALESCE(SUM(amount_paid), 0) as total_paid,
               COALESCE(AVG({$conferenceFields['expected_attendees']}), 0) as avg_attendees
        FROM conference_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $conferenceStatsStmt->execute([$start_date, $end_date]);
    $conferenceStats = $conferenceStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 21. Conference Room Utilization
    $conferenceRoomStmt = $pdo->prepare("
        SELECT cr.name as room_name, cr.capacity,
               COUNT(ci.id) as total_events,
               COALESCE(SUM(ci.total_amount), 0) as total_revenue,
               COALESCE(AVG(ci.{$conferenceFields['expected_attendees']}), 0) as avg_attendees
        FROM conference_rooms cr
        LEFT JOIN conference_inquiries ci ON cr.id = ci.conference_room_id
            AND ci.created_at >= ? AND ci.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
            AND ci.status NOT IN ('cancelled')
        WHERE cr.is_active = 1
        GROUP BY cr.id, cr.name, cr.capacity
        ORDER BY total_events DESC
    ");
    $conferenceRoomStmt->execute([$start_date, $end_date]);
    $conferenceRoomStats = $conferenceRoomStmt->fetchAll(PDO::FETCH_ASSOC);

    // 22. Gym Inquiry Stats
    $gymStatsStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value,
               COALESCE(SUM(amount_paid), 0) as total_paid
        FROM gym_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $gymStatsStmt->execute([$start_date, $end_date]);
    $gymInquiryStats = $gymStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 22a. Gym revenue trend over the selected period
    $gymTrendStmt = $pdo->prepare("
        SELECT DATE(created_at) as day,
               COUNT(*) as bookings,
               COALESCE(SUM(total_amount), 0) as revenue
        FROM gym_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $gymTrendStmt->execute([$start_date, $end_date]);
    $gymRevenueTrend = $gymTrendStmt->fetchAll(PDO::FETCH_ASSOC);

    // 22b. Event Booking Stats
    $eventStatsStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value,
               COALESCE(SUM(amount_paid), 0) as total_paid
        FROM event_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $eventStatsStmt->execute([$start_date, $end_date]);
    $eventInquiryStats = $eventStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 22c. Event revenue trend over the selected period
    $eventTrendStmt = $pdo->prepare("
        SELECT DATE(created_at) as day,
               COUNT(*) as bookings,
               COALESCE(SUM(total_amount), 0) as revenue
        FROM event_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $eventTrendStmt->execute([$start_date, $end_date]);
    $eventRevenueTrend = $eventTrendStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // ADVANCED HOTEL KPI METRICS
    // ============================================

    // 23. ADR (Average Daily Rate) = Total Room Revenue / Number of Room Nights Sold
    $adrStmt = $pdo->prepare("
        SELECT COALESCE(SUM(b.total_amount), 0) as total_room_revenue,
               COALESCE(SUM(b.number_of_nights), 0) as total_nights_sold
        FROM bookings b
        WHERE b.status IN ('confirmed', 'checked-in', 'checked-out')
        AND b.check_in_date <= ? AND b.check_out_date >= ?
    ");
    $adrStmt->execute([$end_date, $start_date]);
    $adrData = $adrStmt->fetch(PDO::FETCH_ASSOC);
    $adr = $adrData['total_nights_sold'] > 0
        ? round($adrData['total_room_revenue'] / $adrData['total_nights_sold'], 0)
        : 0;

    // 24. RevPAR (Revenue Per Available Room) = Total Room Revenue / Total Available Room Nights
    $revpar = $totalRoomNightsAvailable > 0
        ? round($adrData['total_room_revenue'] / $totalRoomNightsAvailable, 0)
        : 0;

    // 25. No-Show Rate
    $noShowStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_confirmed,
            SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) as no_shows
        FROM bookings
        WHERE status IN ('confirmed', 'checked-in', 'checked-out', 'no-show')
        AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $noShowStmt->execute([$start_date, $end_date]);
    $noShowData = $noShowStmt->fetch(PDO::FETCH_ASSOC);
    $noShowRate = $noShowData['total_confirmed'] > 0
        ? round(($noShowData['no_shows'] / $noShowData['total_confirmed']) * 100, 1)
        : 0;

    // 26. Revenue Forecast (from future confirmed bookings)
    $forecastStmt = $pdo->query("
        SELECT COUNT(*) as upcoming_bookings,
               COALESCE(SUM(total_amount), 0) as forecast_revenue,
               COALESCE(SUM(number_of_nights), 0) as upcoming_nights
        FROM bookings
        WHERE status IN ('confirmed', 'tentative')
        AND check_in_date > CURDATE()
    ");
    $forecastData = $forecastStmt->fetch(PDO::FETCH_ASSOC);

    // 27. Monthly ADR trend
    $monthlyAdrStmt = $pdo->prepare("
        SELECT DATE_FORMAT(b.created_at, '%Y-%m') as month,
               DATE_FORMAT(b.created_at, '%b %Y') as month_label,
               COALESCE(SUM(b.total_amount), 0) as revenue,
               COALESCE(SUM(b.number_of_nights), 0) as nights_sold,
               CASE WHEN SUM(b.number_of_nights) > 0
                    THEN ROUND(SUM(b.total_amount) / SUM(b.number_of_nights), 0)
                    ELSE 0 END as adr
        FROM bookings b
        WHERE b.status IN ('confirmed', 'checked-in', 'checked-out')
        AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY month, month_label
        ORDER BY month ASC
    ");
    $monthlyAdrStmt->execute([$start_date, $end_date]);
    $monthlyAdr = $monthlyAdrStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // P&L / GROSS PROFIT CALCULATIONS
    // ============================================

    // COGS from F&B stock orders
    $plCogsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_cost), 0) as total_cogs,
               COALESCE(SUM(total_amount), 0) as fnb_gross
        FROM stock_orders
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('voided','cancelled')
    ");
    $plCogsStmt->execute([$start_date, $end_date]);
    $plCogs = $plCogsStmt->fetch(PDO::FETCH_ASSOC);

    // Revenue split for P&L — all three segments use the payments table so open
    // tabs (placed/preparing) are never included in reported revenue.
    $roomsRevPl = 0.0;
    $confRevPl = 0.0;
    $fnbRevPl = 0.0;
    $gymRevPl = 0.0;
    $eventsRevPl = 0.0;
    foreach ($revenueByType as $rt) {
        if ($rt['booking_type'] === 'room')        $roomsRevPl = (float)$rt['total_revenue'];
        if ($rt['booking_type'] === 'conference')  $confRevPl  = (float)$rt['total_revenue'];
        if ($rt['booking_type'] === 'restaurant')  $fnbRevPl   = (float)$rt['total_revenue'];
        if ($rt['booking_type'] === 'gym')         $gymRevPl   = (float)$rt['total_revenue'];
        if ($rt['booking_type'] === 'event')       $eventsRevPl = (float)$rt['total_revenue'];
    }
    $grossRevenue   = $roomsRevPl + $confRevPl + $fnbRevPl + $gymRevPl + $eventsRevPl;
    $totalCogs      = (float)($plCogs['total_cogs'] ?? 0);
    $grossProfit    = $grossRevenue - $totalCogs;
    $netRevenue     = $grossRevenue - $totalRefunds - $totalVatCollected;
    $grossMarginPct = $grossRevenue > 0 ? round(($grossProfit / $grossRevenue) * 100, 1) : 0.0;

    // ============================================
    // VAT REGISTER TAB QUERIES
    // ============================================

    $vatRegisterStmt = $pdo->prepare("
        SELECT p.payment_date, p.payment_reference, p.booking_type, p.payment_method,
               p.payment_amount, p.vat_rate, p.vat_amount, p.total_amount,
               COALESCE(b.guest_name, ci.{$conferenceFields['contact_name']}, so.customer_name, gi.name, ei.name, 'N/A') AS client_name
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
        WHERE p.payment_date >= ? AND p.payment_date <= DATE_ADD(?, INTERVAL 1 DAY)
        AND COALESCE(p.payment_type, '') != 'refund'
        AND p.deleted_at IS NULL
        AND p.vat_amount > 0
        ORDER BY p.payment_date ASC
        LIMIT 500
    ");
    $vatRegisterStmt->execute([$start_date, $end_date]);
    $vatRegister = $vatRegisterStmt->fetchAll(PDO::FETCH_ASSOC);
    $vatRegisterTotal = array_sum(array_column($vatRegister, 'vat_amount'));
    $vatRegisterGross = array_sum(array_column($vatRegister, 'total_amount'));

    $vatByTypeStmt = $pdo->prepare("
        SELECT booking_type, COUNT(*) AS count,
               SUM(vat_amount) AS total_vat, SUM(total_amount) AS total_revenue
        FROM payments
        WHERE payment_date >= ? AND payment_date <= DATE_ADD(?, INTERVAL 1 DAY)
        AND COALESCE(payment_type, '') != 'refund' AND deleted_at IS NULL AND vat_amount > 0
        GROUP BY booking_type ORDER BY total_vat DESC
    ");
    $vatByTypeStmt->execute([$start_date, $end_date]);
    $vatByType = $vatByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // AGING & AR TAB QUERIES
    // ============================================

    $agingDetailStmt = $pdo->prepare("
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
    $agingDetailStmt->execute();
    $agingDetail = $agingDetailStmt->fetchAll(PDO::FETCH_ASSOC);

    $agingBuckets = [
        '0-30'  => ['count' => 0, 'amount' => 0.0],
        '31-60' => ['count' => 0, 'amount' => 0.0],
        '61-90' => ['count' => 0, 'amount' => 0.0],
        '90+'   => ['count' => 0, 'amount' => 0.0],
    ];
    foreach ($agingDetail as $ag) {
        $d  = (int)$ag['days_outstanding'];
        $bk = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
        $agingBuckets[$bk]['count']++;
        $agingBuckets[$bk]['amount'] += (float)$ag['total_amount'];
    }
    $totalAgingAmount = array_sum(array_column($agingBuckets, 'amount'));

    // ============================================
    // PERIOD-OVER-PERIOD COMPARISON
    // ============================================
    $periodDays     = max(1, round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
    $priorEndDate   = date('Y-m-d', strtotime($start_date) - 86400);
    $priorStartDate = date('Y-m-d', strtotime($priorEndDate) - ($periodDays - 1) * 86400);

    $priorRevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as revenue,
               COALESCE(SUM(vat_amount), 0) as vat,
               COUNT(*) as txns
        FROM payments
        WHERE payment_status IN ('confirmed','paid','completed')
          AND COALESCE(payment_type,'') != 'refund'
          AND deleted_at IS NULL
          AND payment_date >= ? AND payment_date <= ?
    ");
    $priorRevStmt->execute([$priorStartDate, $priorEndDate]);
    $priorRevData = $priorRevStmt->fetch(PDO::FETCH_ASSOC);
    $priorRevenue = (float)($priorRevData['revenue'] ?? 0);
    $priorTxns    = (int)($priorRevData['txns'] ?? 0);

    $priorBookingStmt = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
          AND status NOT IN ('cancelled')
    ");
    $priorBookingStmt->execute([$priorStartDate, $priorEndDate]);
    $priorBookings = (int)$priorBookingStmt->fetchColumn();

    // ============================================
    // CREDIT NOTES
    // ============================================
    $cnData = ['total_issued' => 0, 'total_redeemed' => 0, 'count' => 0];
    try {
        $cnStmt = $pdo->prepare("
            SELECT COALESCE(SUM(original_amount), 0) as total_issued,
                   COALESCE(SUM(amount_used), 0) as total_redeemed,
                   COUNT(*) as count
            FROM credit_notes
            WHERE issued_at >= ? AND issued_at <= DATE_ADD(?, INTERVAL 1 DAY)
              AND status != 'voided'
        ");
        $cnStmt->execute([$start_date, $end_date]);
        $cnData = $cnStmt->fetch(PDO::FETCH_ASSOC) ?: $cnData;
    } catch (Throwable $cnEx) { /* credit_notes table may not exist yet */
    }
    $totalCreditNotesIssued   = (float)($cnData['total_issued'] ?? 0);
    $totalCreditNotesRedeemed = (float)($cnData['total_redeemed'] ?? 0);

    // ============================================
    // DEFERRED REVENUE (deposits on future bookings)
    // ============================================
    $deferredRevenue  = 0.0;
    $deferredBookings = 0;
    try {
        $deferredStmt = $pdo->query("
            SELECT COALESCE(SUM(p.total_amount), 0) as deferred_revenue,
                   COUNT(DISTINCT b.id) as future_bookings
            FROM payments p
            JOIN bookings b ON p.booking_id = b.id AND p.booking_type = 'room'
            WHERE p.payment_status IN ('paid','partial','pending')
              AND b.check_in_date > CURDATE()
              AND b.status IN ('confirmed','tentative')
              AND p.deleted_at IS NULL
        ");
        $dr = $deferredStmt->fetch(PDO::FETCH_ASSOC);
        $deferredRevenue  = (float)($dr['deferred_revenue'] ?? 0);
        $deferredBookings = (int)($dr['future_bookings'] ?? 0);
    } catch (Throwable $drEx) { /* safe fallback */
    }

    // ============================================
    // VAT BY QUARTER (for MRA filing)
    // ============================================
    $vatByQuarter = [];
    try {
        $vatByQStmt = $pdo->prepare("
            SELECT YEAR(payment_date) as yr, QUARTER(payment_date) as qtr,
                   COUNT(*) as txns,
                   SUM(vat_amount) as vat,
                   SUM(total_amount) as gross,
                   SUM(total_amount - vat_amount) as net_ex_vat
            FROM payments
            WHERE payment_date >= ? AND payment_date <= DATE_ADD(?, INTERVAL 1 DAY)
              AND COALESCE(payment_type,'') != 'refund'
              AND deleted_at IS NULL AND vat_amount > 0
            GROUP BY YEAR(payment_date), QUARTER(payment_date)
            ORDER BY yr ASC, qtr ASC
        ");
        $vatByQStmt->execute([$start_date, $end_date]);
        $vatByQuarter = $vatByQStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $vqEx) { /* safe fallback */
    }

    // ============================================
    // QUOTATION CONVERSION FUNNEL
    // ============================================
    $quoteFunnel      = [];
    $quoteTotalValue  = 0.0;
    $quoteConvRate    = 0.0;
    try {
        $qfStmt = $pdo->prepare("
            SELECT status, COUNT(*) as count,
                   COALESCE(SUM(total_amount), 0) as total_value
            FROM quotations
            WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
            GROUP BY status
        ");
        $qfStmt->execute([$start_date, $end_date]);
        $quoteFunnel = $qfStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($quoteFunnel as $qf) {
            $quoteTotalValue += (float)$qf['total_value'];
        }
        $qAccepted = array_sum(array_column(array_filter($quoteFunnel, fn($q) => $q['status'] === 'accepted'), 'count'));
        $qTotal    = array_sum(array_column($quoteFunnel, 'count'));
        $quoteConvRate = $qTotal > 0 ? round(($qAccepted / $qTotal) * 100, 1) : 0.0;
    } catch (Throwable $qfEx) { /* quotations table may not exist */
    }

    // ============================================
    // PER ROOM TYPE ADR / RevPAR
    // ============================================
    $roomTypeAdr = [];
    try {
        $rtAdrStmt = $pdo->prepare("
            SELECT r.name as room_name, r.total_rooms,
                   COALESCE(SUM(b.total_amount), 0) as revenue,
                   COALESCE(SUM(b.number_of_nights), 0) as nights_sold,
                   CASE WHEN SUM(b.number_of_nights) > 0
                        THEN ROUND(SUM(b.total_amount) / SUM(b.number_of_nights), 0)
                        ELSE 0 END as adr
            FROM rooms r
            LEFT JOIN bookings b ON r.id = b.room_id
                AND b.check_in_date <= ? AND b.check_out_date >= ?
                AND b.status IN ('confirmed','checked-in','checked-out')
            WHERE r.is_active = 1
            GROUP BY r.id, r.name, r.total_rooms
            ORDER BY revenue DESC
        ");
        $rtAdrStmt->execute([$end_date, $start_date]);
        $roomTypeAdr = $rtAdrStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $rtEx) { /* safe fallback */
    }
} catch (PDOException $e) {
    $error = "Unable to load report data. Please try again.";
    error_log("Reports error: " . $e->getMessage());
    $plCogs = ['total_cogs' => 0, 'fnb_gross' => 0];
    $roomsRevPl = $confRevPl = $fnbRevPl = $gymRevPl = $eventsRevPl = $grossRevenue = $totalCogs = 0.0;
    $grossProfit = $netRevenue = $grossMarginPct = 0.0;
    $vatRegister = $vatByType = [];
    $vatRegisterTotal = $vatRegisterGross = 0.0;
    $agingDetail = [];
    $totalAgingAmount = 0.0;
    $agingBuckets = ['0-30' => ['count' => 0, 'amount' => 0.0], '31-60' => ['count' => 0, 'amount' => 0.0], '61-90' => ['count' => 0, 'amount' => 0.0], '90+' => ['count' => 0, 'amount' => 0.0]];
    $priorRevenue = $priorTxns = $priorBookings = 0;
    $priorStartDate = $priorEndDate = '';
    $periodDays = 1;
    $totalCreditNotesIssued = $totalCreditNotesRedeemed = 0.0;
    $deferredRevenue = 0.0;
    $deferredBookings = 0;
    $vatByQuarter = [];
    $quoteFunnel = [];
    $quoteTotalValue = 0.0;
    $quoteConvRate = 0.0;
    $roomTypeAdr = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="reports-container finance-page">

        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title"><i class="fas fa-chart-line"></i> Reports &amp; Analytics</h1>
                <p class="acct-page-header__subtitle">Central accounting intelligence — P&amp;L, VAT, aging, and operational KPIs</p>
            </div>
            <div class="acct-quick-actions">
                <a href="payments.php" class="acct-quick-action"><i class="fas fa-money-bill-wave"></i> Payments</a>
                <?php if (function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing')): ?>
                <a href="invoices.php" class="acct-quick-action"><i class="fas fa-file-invoice"></i> Invoices</a>
                <?php endif; ?>
                <a href="accounting-dashboard.php" class="acct-quick-action"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <button type="button" class="acct-quick-action" onclick="exportToCSV()"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <!-- Report Tabs -->
        <div class="report-tabs">
            <?php
            $tabs = [
                'overview'   => ['icon' => 'fa-tachometer-alt',  'label' => 'P&L Overview',   'title' => 'Profit & Loss Overview — Gross Revenue, Gross Profit, VAT, and Outstanding balances at a glance'],
                'revenue'    => ['icon' => 'fa-dollar-sign',     'label' => 'Revenue',          'title' => 'Detailed revenue breakdown by source, payment method, and date'],
                'vat'        => ['icon' => 'fa-percent',         'label' => 'VAT Register',     'title' => 'VAT (Value Added Tax) Register — full record of tax collected, required for MRA reporting'],
                'aging'      => ['icon' => 'fa-hourglass-half',  'label' => 'Aging &amp; AR',   'title' => 'Aging & Accounts Receivable — AR = money owed to the hotel. Aging shows how long each balance has been outstanding.'],
                'bookings'   => ['icon' => 'fa-calendar-check',  'label' => 'Bookings',         'title' => 'Room booking statistics, cancellation rates, and average booking value'],
                'occupancy'  => ['icon' => 'fa-bed',             'label' => 'Occupancy',        'title' => 'Room occupancy — what percentage of available room nights were sold'],
                'guests'     => ['icon' => 'fa-users',           'label' => 'Guests',           'title' => 'Guest analysis — new vs returning guests, nationality, and spending patterns'],
                'conference' => ['icon' => 'fa-briefcase',       'label' => 'Conference',       'title' => 'Conference and events revenue, inquiry conversion, and booking performance'],
                'fnb'        => ['icon' => isRestaurantEnabled() ? 'fa-utensils' : 'fa-cash-register', 'label' => htmlspecialchars(rh_pos_category_label()), 'title' => isRestaurantEnabled() ? 'Food & Beverage (F&B) — restaurant and bar sales through the Point of Sale (POS) system' : 'Sales recorded through the Point of Sale (POS) / till system'],
                'stock'      => ['icon' => 'fa-boxes-stacked',   'label' => 'Stock',            'title' => 'Stock and inventory — usage, wastage, and cost of goods consumed'],
                'staff'      => ['icon' => 'fa-user-clock',      'label' => 'Staff',            'title' => 'Staff activity — orders processed, shift performance, and productivity'],
                'voids'      => ['icon' => 'fa-ban',             'label' => 'Voids',            'title' => 'Voided orders and payments — items cancelled after being placed'],
            ];
            foreach ($tabs as $tab_key => $tab_info):
                if (isset($tab_module_map[$tab_key]) && !$tab_module_map[$tab_key]) continue;
            ?>
                <a href="?tab=<?php echo $tab_key; ?>&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>"
                    class="report-tab <?php echo $active_tab === $tab_key ? 'active' : ''; ?>"
                    title="<?php echo htmlspecialchars($tab_info['title'] ?? ''); ?>">
                    <i class="fas <?php echo $tab_info['icon']; ?>"></i> <?php echo $tab_info['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Date Filter -->
        <div class="date-filter">
            <form method="GET" action="">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <button type="submit" class="btn-filter btn-filter-primary">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <button type="button" class="btn-filter btn-filter-secondary" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </form>
            <div class="quick-filters">
                <span>Quick:</span>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('today')">Today</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('week')">This Week</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('month')">This Month</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('quarter')">This Quarter</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('year')">This Year</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('all')">All Time</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="report-section">
                <p style="color: #dc3545; text-align: center; padding: 20px;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!$error): ?>

            <!-- ============================================ -->
            <!-- P&L OVERVIEW TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="tab-overview">

                <!-- Accountant Help Panel -->
                <details class="rh-help-panel" <?php echo isset($_GET['help']) ? 'open' : ''; ?>>
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> How to read this report — P&amp;L Overview</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>📊 Gross Revenue vs Net Revenue</h4>
                                <p><strong>Gross Revenue</strong> = all money collected from rooms, conference, and <?php echo htmlspecialchars(rh_pos_short_label()); ?> before any deductions. <strong>Net Revenue</strong> = Gross minus refunds and VAT. Net is the money the business actually keeps, excluding tax obligations.</p>
                            </div>
                            <div>
                                <h4>📉 Gross Profit &amp; Margin</h4>
                                <p><strong>Gross Profit</strong> = Revenue minus <?php echo htmlspecialchars(rh_pos_short_label()); ?> Cost of Goods (COGS). It does <em>not</em> include staff wages, utilities, or depreciation — those come later in a full P&amp;L. A margin above 60% is healthy for a business with <?php echo htmlspecialchars(rh_pos_short_label()); ?>.</p>
                            </div>
                            <div>
                                <h4>🏨 ADR &amp; RevPAR</h4>
                                <p><strong>ADR</strong> (Average Daily Rate) = revenue ÷ rooms sold. <strong>RevPAR</strong> (Revenue Per Available Room) = revenue ÷ <em>all</em> rooms including empty ones. RevPAR closing towards ADR means better occupancy. African boutique benchmark: MWK 15,000–40,000 RevPAR.</p>
                            </div>
                            <div>
                                <h4>⚠️ Outstanding &amp; Aging</h4>
                                <p>Outstanding = money owed but not yet paid. Go to the <strong>Aging &amp; AR</strong> tab to see how long each balance has been outstanding. Anything over 60 days needs urgent follow-up; over 90 days consider a formal notice or write-off.</p>
                            </div>
                            <div>
                                <h4>🔁 Credit Notes</h4>
                                <p>Credit notes reduce reported revenue. If a guest paid but you issued a credit note for a service failure, the credit note is a liability until used. <strong>Issued</strong> = total value created. <strong>Redeemed</strong> = used against a future payment.</p>
                            </div>
                            <div>
                                <h4>📅 Prior Period Comparison</h4>
                                <p>The <span class="rh-delta rh-delta--up">↑ %</span> badges compare this period to the immediately preceding period of the same length. A green badge means growth; red means decline.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- P&L KPI Row -->
                <?php
                    $_rpt_categories = [];
                    if ($mod_bookings) { $_rpt_categories[] = 'Rooms'; }
                    if ($mod_conference) { $_rpt_categories[] = 'Conference'; }
                    if ($mod_gym) { $_rpt_categories[] = 'Gym'; }
                    if ($mod_events) { $_rpt_categories[] = 'Events'; }
                    if ($mod_pos) { $_rpt_categories[] = rh_pos_short_label(); }
                ?>
                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--revenue">
                        <div class="acct-kpi__label">Gross Revenue</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($grossRevenue, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo htmlspecialchars(implode(' + ', $_rpt_categories) ?: 'Revenue'); ?> <?php echo rh_reports_delta($grossRevenue, $priorRevenue); ?></div>
                    </div>
                    <div class="acct-kpi acct-kpi--cash">
                        <div class="acct-kpi__label">Gross Profit</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($grossProfit, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo $grossMarginPct; ?>% margin · After <?php echo htmlspecialchars(rh_pos_short_label()); ?> COGS</div>
                    </div>
                    <div class="acct-kpi acct-kpi--vat">
                        <div class="acct-kpi__label">VAT Collected</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></div>
                        <div class="acct-kpi__sub">At <?php echo $vatRate; ?>% · Payable to MRA</div>
                    </div>
                    <div class="acct-kpi acct-kpi--receivables">
                        <div class="acct-kpi__label">Outstanding</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalOutstanding, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo count($outstandingPayments); ?> pending · <a href="?tab=aging&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="acct-link">View aging →</a></div>
                    </div>
                </div>

                <?php if ($deferredRevenue > 0): ?>
                    <!-- Deferred Revenue Notice -->
                    <div class="rh-alert rh-alert--info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Deferred Revenue Notice:</strong>
                            <?php echo $currency_symbol . ' ' . number_format($deferredRevenue, 2); ?> has been collected across <?php echo number_format($deferredBookings); ?> future booking<?php echo $deferredBookings != 1 ? 's' : ''; ?> (check-in date after today). Under accrual accounting, this is a <em>liability</em> (Deferred Revenue) until the stay occurs — it should not be recognised as earned income yet. Review in the <a href="?tab=bookings&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="acct-link">Bookings tab</a>.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- P&L Breakdown + Hotel KPIs side by side -->
                <div class="acct-grid acct-grid--2">

                    <!-- P&L Statement -->
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-calculator"></i> P&amp;L Summary</h2>
                        <table class="acct-table">
                            <thead>
                                <tr>
                                    <th>Line Item</th>
                                    <th style="text-align:right">Amount (<?php echo htmlspecialchars($currency_symbol); ?>)</th>
                                    <th style="text-align:right; color: var(--color-text-secondary); font-size:0.8em">vs Prior Period</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mod_bookings): ?>
                                <tr>
                                    <td>Room Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($roomsRevPl, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($mod_conference): ?>
                                <tr>
                                    <td>Conference Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($confRevPl, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($mod_pos): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(rh_pos_short_label()); ?> Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($fnbRevPl, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($mod_gym): ?>
                                <tr>
                                    <td>Gym Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($gymRevPl, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($mod_events): ?>
                                <tr>
                                    <td>Event Booking Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($eventsRevPl, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <tr style="font-weight:600; border-top: 2px solid var(--color-lux-clay-50)">
                                    <td>= Total Gross Revenue</td>
                                    <td style="text-align:right"><?php echo number_format($grossRevenue, 2); ?></td>
                                    <td style="text-align:right"><?php echo rh_reports_delta($grossRevenue, $priorRevenue); ?></td>
                                </tr>
                                <?php if ($mod_pos): ?>
                                <tr style="color: var(--color-text-secondary)">
                                    <td title="<?php echo isRestaurantEnabled() ? 'COGS (Cost of Goods Sold) — the actual cost of food and drink ingredients used to make F&B items sold through the restaurant.' : 'COGS (Cost of Goods Sold) — the actual cost of stock/ingredients used to fulfil POS/till sales.'; ?>">– <?php echo htmlspecialchars(rh_pos_short_label()); ?> COGS</td>
                                    <td style="text-align:right">(<?php echo number_format($totalCogs, 2); ?>)</td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php endif; ?>
                                <tr style="font-weight:600; color: var(--color-lux-gold)">
                                    <td>= Gross Profit</td>
                                    <td style="text-align:right"><?php echo number_format($grossProfit, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <tr style="color: var(--color-text-secondary)">
                                    <td>– Refunds Issued</td>
                                    <td style="text-align:right">(<?php echo number_format($totalRefunds, 2); ?>)</td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php if ($totalCreditNotesIssued > 0): ?>
                                    <tr style="color: var(--color-text-secondary)">
                                        <td title="Credit Notes issued in this period reduce gross revenue. They represent value promised to guests but not yet paid in cash.">– Credit Notes Issued</td>
                                        <td style="text-align:right">(<?php echo number_format($totalCreditNotesIssued, 2); ?>)</td>
                                        <td style="text-align:right"><span class="acct-muted" style="font-size:0.8em"><?php echo number_format($totalCreditNotesRedeemed, 2); ?> redeemed</span></td>
                                    </tr>
                                <?php endif; ?>
                                <tr style="color: var(--color-text-secondary)">
                                    <td>– VAT Collected</td>
                                    <td style="text-align:right">(<?php echo number_format($totalVatCollected, 2); ?>)</td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <tr style="font-weight:700; border-top: 2px solid var(--color-lux-clay-50)">
                                    <td>= Net Revenue (ex-VAT, ex-refunds)</td>
                                    <td style="text-align:right; color: <?php echo $netRevenue >= 0 ? 'var(--color-lux-gold)' : '#c82333'; ?>"><?php echo number_format($netRevenue, 2); ?></td>
                                    <td style="text-align:right"></td>
                                </tr>
                                <?php if ($deferredRevenue > 0): ?>
                                    <tr style="color: var(--color-text-secondary); border-top: 1px dashed var(--color-lux-clay-50)">
                                        <td title="Deferred Revenue — payments collected for stays that have not yet occurred. Under accrual accounting these are liabilities, not earned income.">⚠ Deferred Revenue (unearned)</td>
                                        <td style="text-align:right; font-style:italic"><?php echo number_format($deferredRevenue, 2); ?></td>
                                        <td style="text-align:right"><span class="acct-muted" style="font-size:0.8em"><?php echo $deferredBookings; ?> future stays</span></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Hotel KPIs -->
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-hotel"></i> Hotel KPIs</h2>
                        <p class="acct-muted" style="margin-bottom:10px; font-size:0.8em">Comparing <?php echo htmlspecialchars($start_date); ?> – <?php echo htmlspecialchars($end_date); ?> vs prior <?php echo $periodDays; ?> days (<?php echo htmlspecialchars($priorStartDate); ?> – <?php echo htmlspecialchars($priorEndDate); ?>)</p>
                        <table class="acct-table">
                            <tbody>
                                <?php if ($mod_bookings): ?>
                                <tr>
                                    <td>Occupancy Rate</td>
                                    <td style="text-align:right; font-weight:600"><?php echo $overallOccupancyRate; ?>%</td>
                                </tr>
                                <tr>
                                    <td title="ADR (Average Daily Rate) — total room revenue divided by number of rooms sold.">ADR (Avg Daily Rate)</td>
                                    <td style="text-align:right; font-weight:600"><?php echo $currency_symbol . ' ' . number_format($adr); ?></td>
                                </tr>
                                <tr>
                                    <td title="RevPAR (Revenue Per Available Room) — total room revenue divided by ALL available room nights. Lower than ADR means some rooms sat empty.">RevPAR</td>
                                    <td style="text-align:right; font-weight:600"><?php echo $currency_symbol . ' ' . number_format($revpar); ?></td>
                                </tr>
                                <tr>
                                    <td>Avg Stay Length</td>
                                    <td style="text-align:right"><?php echo $avgStayLength; ?> nights</td>
                                </tr>
                                <tr>
                                    <td>Avg Booking Value</td>
                                    <td style="text-align:right"><?php echo $currency_symbol . ' ' . number_format($avgRevenuePerBooking, 2); ?></td>
                                </tr>
                                <tr>
                                    <td>Cancellation Rate</td>
                                    <td style="text-align:right; color: #c82333"><?php echo $cancellationRate; ?>%</td>
                                </tr>
                                <tr>
                                    <td>No-Show Rate</td>
                                    <td style="text-align:right; color: #c82333"><?php echo $noShowRate; ?>%</td>
                                </tr>
                                <tr>
                                    <td>Total Bookings (period)</td>
                                    <td style="text-align:right"><?php echo number_format($totalBookings); ?> <?php echo rh_reports_delta((float)$totalBookings, (float)$priorBookings); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Total Transactions</td>
                                    <td style="text-align:right"><?php echo number_format($totalTransactions); ?> <?php echo rh_reports_delta((float)$totalTransactions, (float)$priorTxns); ?></td>
                                </tr>
                                <?php if ($totalCreditNotesIssued > 0): ?>
                                    <tr>
                                        <td title="Credit notes issued in this period.">Credit Notes Issued</td>
                                        <td style="text-align:right; color: #c82333"><?php echo $currency_symbol . ' ' . number_format($totalCreditNotesIssued, 2); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($mod_bookings): ?>
                                <tr style="font-weight:600">
                                    <td>Revenue Forecast (upcoming)</td>
                                    <td style="text-align:right; color: var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($forecastData['forecast_revenue']); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Revenue by Source + Payment Methods side by side -->
                <div class="acct-grid acct-grid--2">
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-chart-pie"></i> Revenue by Source</h2>
                        <?php if (empty($revenueByType)): ?>
                            <p class="acct-empty">No revenue data for this period.</p>
                        <?php else: ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Source</th>
                                        <th title="Transactions — number of individual payment records">Txns</th>
                                        <th>Revenue</th><?php if ($vatEnabled): ?><th title="VAT (Value Added Tax) — the tax portion collected. Must be remitted to MRA.">VAT</th><?php endif; ?><th>Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($revenueByType as $rev): ?>
                                        <tr>
                                            <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($rev['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($rev['booking_type'])); ?></span></td>
                                            <td><?php echo number_format($rev['count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($rev['total_revenue'], 2); ?></td>
                                            <?php if ($vatEnabled): ?><td><?php echo $currency_symbol . ' ' . number_format($rev['total_vat'], 2); ?></td><?php endif; ?>
                                            <td><?php echo $grossRevenue > 0 ? number_format(($rev['total_revenue'] / $grossRevenue) * 100, 1) : '0.0'; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-credit-card"></i> Payment Methods</h2>
                        <?php if (empty($paymentMethods)): ?>
                            <p class="acct-empty">No payment data for this period.</p>
                        <?php else: ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Txns</th>
                                        <th>Total</th>
                                        <th>Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentMethods as $method): $mLabel = ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mLabel); ?></td>
                                            <td><?php echo number_format($method['count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($method['total_amount'], 2); ?></td>
                                            <td>
                                                <div class="acct-bar">
                                                    <div class="acct-bar__fill" style="width: <?php echo $totalRevenue > 0 ? min(100, round(($method['total_amount'] / $totalRevenue) * 100)) : 0; ?>%"></div>
                                                    <span class="acct-bar__label"><?php echo $totalRevenue > 0 ? number_format(($method['total_amount'] / $totalRevenue) * 100, 1) : '0.0'; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Status + Outstanding -->
                <div class="acct-grid acct-grid--2">
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-tasks"></i> Payment Status Mix</h2>
                        <?php if (empty($statusData)): ?>
                            <p class="acct-empty">No payment data.</p>
                        <?php else: ?>
                            <?php if ($totalStatusCount > 0): ?>
                                <div class="acct-bar" style="height: 18px; margin-bottom: 16px; border-radius: 9px; overflow: hidden; display: flex; gap: 0">
                                    <?php foreach ($statusLabels as $status => $label): ?>
                                        <?php if (!isset($statusData[$status])) continue;
                                        $pct = ($statusData[$status]['count'] / $totalStatusCount) * 100;
                                        if ($pct < 1) continue; ?>
                                        <div style="width: <?php echo $pct; ?>%; height: 100%;" title="<?php echo htmlspecialchars($label); ?>: <?php echo number_format($pct, 1); ?>%" class="acct-pill acct-pill--<?php echo htmlspecialchars($status); ?>" style="border-radius:0;"></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Count</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statusLabels as $status => $label): ?>
                                        <?php if (!isset($statusData[$status])) continue; ?>
                                        <tr>
                                            <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($label); ?></span></td>
                                            <td><?php echo number_format($statusData[$status]['count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($statusData[$status]['total_amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-exclamation-triangle"></i> Top Outstanding Balances</h2>
                        <?php if (empty($outstandingPayments)): ?>
                            <p class="acct-empty acct-empty--good"><i class="fas fa-check-circle"></i> No outstanding payments.</p>
                        <?php else: ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Client</th>
                                        <th>Amount</th>
                                        <th>Days</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($outstandingPayments, 0, 10) as $p): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($p['payment_reference']); ?></td>
                                            <td class="acct-muted"><?php echo htmlspecialchars($p['client_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($p['total_amount'], 2); ?></td>
                                            <td class="<?php echo $p['days_overdue'] > 60 ? 'acct-danger' : ''; ?>"><?php echo max(0, $p['days_overdue']); ?>d</td>
                                            <td><a href="payments.php?search=<?php echo urlencode($p['payment_reference']); ?>" class="acct-link">→</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($outstandingPayments) > 10): ?><p class="acct-muted" style="text-align:center; margin-top:8px">+<?php echo count($outstandingPayments) - 10; ?> more — <a href="?tab=aging&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="acct-link">View all in Aging tab →</a></p><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- REVENUE TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'revenue' ? 'active' : ''; ?>" id="tab-revenue">

                <!-- Accountant Help Panel -->
                <details class="rh-help-panel">
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> How to read the Revenue Report</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>📈 Revenue vs Net Revenue</h4>
                                <p><strong>Gross Revenue</strong> = all money collected in the period. <strong>Net Revenue</strong> = Gross minus refunds. Use Net for any profitability analysis — Gross inflates the picture if refunds are high.</p>
                            </div>
                            <div>
                                <h4>📅 Month-on-Month Growth (MoM%)</h4>
                                <p>The <strong>MoM%</strong> column in the monthly table shows the % change from the previous month. Consistent 5–15% monthly growth is healthy for a boutique hotel. Negative months warrant investigation.</p>
                            </div>
                            <div>
                                <h4>💳 Payment Method Mix</h4>
                                <p>A high cash proportion increases cash-handling risk. Mobile money and card payments leave an audit trail. If mobile money is low, consider promoting it to guests for faster payment.</p>
                            </div>
                            <div>
                                <h4>📋 Quotation Pipeline</h4>
                                <p>Quotations sent but not accepted yet are <em>potential revenue</em>. Track conversion rate — industry average is 20–35% for hotel quotes. Expired quotes are lost revenue; consider follow-up workflows.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--revenue">
                        <div class="acct-kpi__label">Gross Revenue</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo number_format($totalTransactions); ?> transactions</div>
                    </div>
                    <div class="acct-kpi acct-kpi--cash">
                        <div class="acct-kpi__label">Net Revenue</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalRevenue - $totalRefunds, 2); ?></div>
                        <div class="acct-kpi__sub">After refunds of <?php echo $currency_symbol . ' ' . number_format($totalRefunds, 2); ?></div>
                    </div>
                    <div class="acct-kpi acct-kpi--vat">
                        <div class="acct-kpi__label">VAT Collected</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></div>
                        <div class="acct-kpi__sub"><a href="?tab=vat&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" class="acct-link">Full VAT Register →</a></div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Avg per Transaction</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . ($totalTransactions > 0 ? number_format($totalRevenue / $totalTransactions, 2) : '0.00'); ?></div>
                        <div class="acct-kpi__sub">ADR: <?php echo $currency_symbol . ' ' . number_format($adr); ?> · RevPAR: <?php echo $currency_symbol . ' ' . number_format($revpar); ?></div>
                    </div>
                </div>

                <!-- Payment Method Breakdown -->
                <div class="report-section">
                    <h2><i class="fas fa-credit-card"></i> Payment Method Breakdown</h2>
                    <?php if (empty($paymentMethods)): ?>
                        <div class="empty-state"><i class="fas fa-credit-card"></i>
                            <p>No payment data for this period</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th>Transactions</th>
                                    <th>Total Amount</th>
                                    <th>Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <tr>
                                        <td><i class="fas fa-<?php echo $method['payment_method'] === 'cash' ? 'money-bill' : ($method['payment_method'] === 'bank_transfer' ? 'university' : ($method['payment_method'] === 'mobile_money' ? 'mobile-alt' : ($method['payment_method'] === 'credit_card' ? 'credit-card' : 'wallet'))); ?>"></i> <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($method['payment_method']))); ?></td>
                                        <td><?php echo number_format($method['count']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($method['total_amount'], 2); ?></td>
                                        <td><?php echo $totalRevenue > 0 ? number_format(($method['total_amount'] / $totalRevenue) * 100, 1) : '0.0'; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="two-col">
                    <!-- Daily Revenue -->
                    <div class="report-section">
                        <h2><i class="fas fa-chart-line"></i> Daily Revenue Trend</h2>
                        <?php if (empty($dailyRevenue)): ?>
                            <div class="empty-state"><i class="fas fa-chart-line"></i>
                                <p>No daily data</p>
                            </div>
                        <?php else: ?>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Txns</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailyRevenue as $day): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($day['date'])); ?></td>
                                            <td><?php echo number_format($day['transaction_count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($day['daily_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Monthly Revenue -->
                    <div class="report-section">
                        <h2><i class="fas fa-calendar-alt"></i> Monthly Revenue</h2>
                        <?php if (empty($monthlyRevenue)): ?>
                            <div class="empty-state"><i class="fas fa-calendar-alt"></i>
                                <p>No monthly data</p>
                            </div>
                        <?php else: ?>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Txns</th>
                                        <th>Revenue</th>
                                        <th title="Month-on-Month growth: % change vs the previous month. Positive = growing, Negative = declining.">MoM %</th>
                                        <?php if ($vatEnabled): ?><th>VAT</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $prevMonthRev = 0.0;
                                    foreach ($monthlyRevenue as $mIdx => $m):
                                        $curRev = (float)$m['monthly_revenue'];
                                        $momPct = '';
                                        if ($mIdx > 0 && $prevMonthRev > 0) {
                                            $chg = round((($curRev - $prevMonthRev) / $prevMonthRev) * 100, 1);
                                            $momCls = $chg >= 0 ? 'rh-delta rh-delta--up' : 'rh-delta rh-delta--down';
                                            $momPct = '<span class="' . $momCls . '">' . ($chg >= 0 ? '↑' : '↓') . ' ' . abs($chg) . '%</span>';
                                        } elseif ($mIdx === 0) {
                                            $momPct = '<span class="acct-muted" style="font-size:0.8em">—</span>';
                                        }
                                        $prevMonthRev = $curRev;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($m['month_label']); ?></td>
                                            <td><?php echo number_format($m['transaction_count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($m['monthly_revenue'], 2); ?></td>
                                            <td><?php echo $momPct; ?></td>
                                            <?php if ($vatEnabled): ?><td><?php echo $currency_symbol . ' ' . number_format($m['monthly_vat'], 2); ?></td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Clients -->
                <div class="report-section">
                    <h2><i class="fas fa-trophy"></i> Top Clients by Revenue</h2>
                    <?php if (empty($topClients)): ?>
                        <div class="empty-state"><i class="fas fa-trophy"></i>
                            <p>No client data for this period</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Client</th>
                                    <th>Email</th>
                                    <th>Type</th>
                                    <th>Transactions</th>
                                    <th>Total Spent</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topClients as $i => $client): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($client['client_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($client['client_email'] ?? ''); ?></td>
                                        <td><span class="badge-sm badge-<?php echo htmlspecialchars($client['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($client['booking_type'])); ?></span></td>
                                        <td><?php echo number_format($client['transaction_count']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($client['total_spent'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- VAT Report -->
                <?php if ($vatEnabled): ?>
                    <div class="report-section">
                        <h2><i class="fas fa-percent"></i> VAT Collection Report</h2>
                        <?php if (empty($vatCollected)): ?>
                            <div class="empty-state"><i class="fas fa-percent"></i>
                                <p>No VAT data for this period</p>
                            </div>
                        <?php else: ?>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th>VAT Collected</th>
                                        <th>Total Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vatCollected as $vat): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($vat['date'])); ?></td>
                                            <td><?php echo number_format($vat['transaction_count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($vat['vat_collected'], 2); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($vat['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total</td>
                                        <td><?php echo number_format($totalTransactions); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Refund Analysis -->
                <div class="report-section">
                    <h2><i class="fas fa-undo"></i> Refund Analysis</h2>

                    <!-- Refund Summary Cards -->
                    <div class="summary-cards" style="margin-bottom: 20px;">
                        <div class="summary-card" style="border-left: 4px solid #dc3545;">
                            <h3>Total Refunds</h3>
                            <div class="value">-<?php echo $currency_symbol . ' ' . number_format($totalRefunds, 2); ?></div>
                            <div class="subtitle">Issued in period</div>
                        </div>
                        <div class="summary-card" style="border-left: 4px solid #ffc107;">
                            <h3>Pending Refunds</h3>
                            <div class="value"><?php echo $currency_symbol . ' ' . number_format($pendingRefunds, 2); ?></div>
                            <div class="subtitle">Awaiting processing</div>
                        </div>
                        <div class="summary-card" style="border-left: 4px solid #28a745;">
                            <h3>Completed Refunds</h3>
                            <div class="value"><?php echo $currency_symbol . ' ' . number_format($completedRefunds, 2); ?></div>
                            <div class="subtitle">Successfully processed</div>
                        </div>
                        <div class="summary-card" style="border-left: 4px solid #17a2b8;">
                            <h3>Net Revenue</h3>
                            <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalRevenue - $totalRefunds, 2); ?></div>
                            <div class="subtitle">After refunds</div>
                        </div>
                    </div>

                    <?php if (!empty($refundReasons)): ?>
                        <!-- Refund Breakdown by Reason -->
                        <h3 style="margin-top: 24px; margin-bottom: 12px; font-size: 15px;">Refunds by Reason</h3>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Reason</th>
                                    <th>Count</th>
                                    <th>Total Amount</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $reasonLabels = [
                                    'early_checkout' => 'Early Checkout',
                                    'late_checkout_charge' => 'Late Checkout Charge',
                                    'cancellation' => 'Cancellation',
                                    'service_issue' => 'Service Issue',
                                    'overpayment' => 'Overpayment',
                                    'other' => 'Other'
                                ];
                                foreach ($refundReasons as $reason):
                                    $percentage = $totalRefunds > 0 ? round(($reason['total_amount'] / $totalRefunds) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <span class="refund-reason-badge refund-reason-<?php echo $reason['refund_reason']; ?>">
                                                <?php echo $reasonLabels[$reason['refund_reason']] ?? ucfirst(str_replace('_', ' ', $reason['refund_reason'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($reason['count']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($reason['total_amount'], 2); ?></td>
                                        <td><?php echo $percentage; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($refundStatuses)): ?>
                        <!-- Refund Status Breakdown -->
                        <h3 style="margin-top: 24px; margin-bottom: 12px; font-size: 15px;">Refunds by Status</h3>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Count</th>
                                    <th>Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $statusLabels = [
                                    'pending' => 'Pending',
                                    'processing' => 'Processing',
                                    'completed' => 'Completed',
                                    'failed' => 'Failed'
                                ];
                                $statusColors = [
                                    'pending' => '#ffc107',
                                    'processing' => '#17a2b8',
                                    'completed' => '#28a745',
                                    'failed' => '#dc3545'
                                ];
                                foreach ($refundStatuses as $status): ?>
                                    <tr>
                                        <td>
                                            <span class="badge-sm" style="background: <?php echo $statusColors[$status['refund_status']] ?? '#6c757d'; ?>; color: white;">
                                                <?php echo $statusLabels[$status['refund_status']] ?? ucfirst($status['refund_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($status['count']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($status['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (empty($refundReasons) && empty($refundStatuses)): ?>
                        <div class="empty-state"><i class="fas fa-undo"></i>
                            <p>No refund data for this period</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Monthly ADR Trend -->
                <div class="report-section">
                    <h2><i class="fas fa-chart-line"></i> Monthly ADR Trend</h2>
                    <?php if (empty($monthlyAdr)): ?>
                        <div class="empty-state"><i class="fas fa-chart-line"></i>
                            <p>No ADR data for this period</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Revenue</th>
                                    <th>Nights Sold</th>
                                    <th>ADR</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyAdr as $ma): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ma['month_label']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($ma['revenue'], 2); ?></td>
                                        <td><?php echo number_format($ma['nights_sold']); ?></td>
                                        <td><strong><?php echo $currency_symbol . ' ' . number_format($ma['adr']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php if (!empty($quoteFunnel)): ?>
                    <!-- Quotation Conversion Funnel -->
                    <div class="report-section">
                        <h2><i class="fas fa-funnel-dollar"></i> Quotation Pipeline
                            <span class="acct-muted" style="font-size:0.75em; font-weight:400; margin-left:8px">Conversion rate: <?php echo $quoteConvRate; ?>%</span>
                        </h2>
                        <?php
                        $qStatusLabels = [
                            'draft'    => ['label' => 'Draft',    'cls' => 'acct-pill--pending'],
                            'sent'     => ['label' => 'Sent',     'cls' => 'acct-pill--room'],
                            'accepted' => ['label' => 'Accepted', 'cls' => 'acct-pill--paid'],
                            'declined' => ['label' => 'Declined', 'cls' => 'acct-pill--refunded'],
                            'expired'  => ['label' => 'Expired',  'cls' => 'acct-pill--cancelled'],
                        ];
                        $quoteTotalCount = array_sum(array_column($quoteFunnel, 'count'));
                        ?>
                        <div class="rh-funnel-row">
                            <?php foreach ($quoteFunnel as $qf):
                                $sl     = $qStatusLabels[$qf['status']] ?? ['label' => ucfirst($qf['status']), 'cls' => ''];
                                $qPct   = $quoteTotalCount > 0 ? round(($qf['count'] / $quoteTotalCount) * 100, 1) : 0;
                            ?>
                                <div class="rh-funnel-step">
                                    <div class="rh-funnel-step__count"><?php echo number_format($qf['count']); ?></div>
                                    <div class="rh-funnel-step__label"><span class="acct-pill <?php echo htmlspecialchars($sl['cls']); ?>"><?php echo htmlspecialchars($sl['label']); ?></span></div>
                                    <div class="rh-funnel-step__value"><?php echo $currency_symbol . ' ' . number_format($qf['total_value'], 0); ?></div>
                                    <div class="rh-funnel-step__pct"><?php echo $qPct; ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="acct-muted" style="font-size:0.8em; margin-top:8px"><i class="fas fa-info-circle"></i> Accepted quotations represent confirmed revenue converted from proposals. Total pipeline value: <?php echo $currency_symbol . ' ' . number_format($quoteTotalValue, 2); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================ -->
            <!-- VAT REGISTER TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'vat' ? 'active' : ''; ?>" id="tab-vat">

                <!-- MRA Filing Help -->
                <details class="rh-help-panel">
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> VAT Filing Guide — How to use this for MRA submissions</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>🏛️ What to submit to MRA</h4>
                                <p>Your <strong>VAT Output Tax</strong> for a period = the "VAT Collected" figure on this page. You report this on your VAT return as Output Tax (Tax on Sales). MRA expects quarterly submissions unless you are on monthly filing.</p>
                            </div>
                            <div>
                                <h4>📋 Input Tax (VAT on expenses)</h4>
                                <p>If the hotel has VAT-registered suppliers (e.g. food wholesalers, linen suppliers), the VAT on <em>their</em> invoices is your Input Tax. Net VAT payable = Output Tax − Input Tax. Keep all supplier VAT invoices as evidence.</p>
                            </div>
                            <div>
                                <h4>📅 MRA Quarter Periods</h4>
                                <p><strong>Q1:</strong> Jan–Mar · <strong>Q2:</strong> Apr–Jun · <strong>Q3:</strong> Jul–Sep · <strong>Q4:</strong> Oct–Dec. VAT returns are due within 30 days after each quarter ends. Use the Quarterly Breakdown table below to get each quarter's liability.</p>
                            </div>
                            <div>
                                <h4>⬇️ How to use this register</h4>
                                <p>Click <strong>Export for Filing</strong> to download a CSV of all VAT-bearing transactions. This can be submitted as supporting documentation alongside your MRA VAT return form. Keep exported copies for 7 years.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--vat">
                        <div class="acct-kpi__label">Total VAT Collected</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($vatRegisterTotal, 2); ?></div>
                        <div class="acct-kpi__sub">At <?php echo $vatRate; ?>% · Tax-inclusive revenue</div>
                    </div>
                    <div class="acct-kpi acct-kpi--revenue">
                        <div class="acct-kpi__label">Gross Revenue (VAT incl.)</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo count($vatRegister); ?> VAT-bearing transactions</div>
                    </div>
                    <div class="acct-kpi acct-kpi--cash">
                        <div class="acct-kpi__label">Net (ex-VAT)</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross - $vatRegisterTotal, 2); ?></div>
                        <div class="acct-kpi__sub">Revenue net of VAT</div>
                    </div>
                    <div class="acct-kpi acct-kpi--receivables">
                        <div class="acct-kpi__label">Effective VAT Rate</div>
                        <div class="acct-kpi__value"><?php echo $vatRegisterGross > 0 ? number_format(($vatRegisterTotal / $vatRegisterGross) * 100, 2) : '0.00'; ?>%</div>
                        <div class="acct-kpi__sub">VAT as % of total revenue</div>
                    </div>
                </div>

                <!-- VAT by Source -->
                <div class="acct-grid acct-grid--2">
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-chart-pie"></i> VAT by Revenue Source</h2>
                        <?php if (empty($vatByType)): ?>
                            <p class="acct-empty">No VAT data for this period.</p>
                        <?php else: ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Source</th>
                                        <th>Txns</th>
                                        <th>Gross Revenue</th>
                                        <th>VAT Amount</th>
                                        <th>Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vatByType as $vbt): ?>
                                        <tr>
                                            <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($vbt['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($vbt['booking_type'])); ?></span></td>
                                            <td><?php echo number_format($vbt['count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($vbt['total_revenue'], 2); ?></td>
                                            <td style="color: var(--color-lux-gold); font-weight: 600"><?php echo $currency_symbol . ' ' . number_format($vbt['total_vat'], 2); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($vbt['total_revenue'] - $vbt['total_vat'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="font-weight:700">
                                        <td>Total</td>
                                        <td><?php echo count($vatRegister); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross, 2); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vatRegisterTotal, 2); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross - $vatRegisterTotal, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Monthly VAT from existing data -->
                    <div class="acct-panel">
                        <h2 class="acct-panel__title"><i class="fas fa-calendar-alt"></i> Monthly VAT Summary</h2>
                        <?php if (empty($monthlyRevenue)): ?>
                            <p class="acct-empty">No monthly data for this period.</p>
                        <?php else: ?>
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Txns</th>
                                        <th>Revenue</th>
                                        <th>VAT Collected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlyRevenue as $m): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($m['month_label']); ?></td>
                                            <td><?php echo number_format($m['transaction_count']); ?></td>
                                            <td><?php echo $currency_symbol . ' ' . number_format($m['monthly_revenue'], 2); ?></td>
                                            <td style="color: var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($m['monthly_vat'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="font-weight:700">
                                        <td>Total</td>
                                        <td><?php echo number_format($totalTransactions); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Full VAT Register — transaction detail -->
                <?php if (!empty($vatByQuarter)): ?>
                    <div class="acct-panel" style="margin-bottom: 16px">
                        <h2 class="acct-panel__title"><i class="fas fa-calendar-check"></i> VAT by Quarter (MRA Filing Periods)</h2>
                        <p class="acct-muted" style="margin-bottom:12px; font-size:0.85em">Use these quarterly totals to complete your MRA Output VAT figures. Net MRA payable = Output VAT (below) − Input VAT on your supplier invoices.</p>
                        <table class="acct-table">
                            <thead>
                                <tr>
                                    <th>Quarter</th>
                                    <th>Period</th>
                                    <th>Transactions</th>
                                    <th>Gross (incl. VAT)</th>
                                    <th>Net (ex-VAT)</th>
                                    <th style="color:var(--color-lux-gold)">VAT Output Tax</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vatByQuarter as $vq):
                                    $qMonths = [1 => 'Jan–Mar', 2 => 'Apr–Jun', 3 => 'Jul–Sep', 4 => 'Oct–Dec'];
                                ?>
                                    <tr>
                                        <td><strong>Q<?php echo (int)$vq['qtr']; ?> <?php echo (int)$vq['yr']; ?></strong></td>
                                        <td class="acct-muted"><?php echo $qMonths[(int)$vq['qtr']] ?? ''; ?></td>
                                        <td><?php echo number_format($vq['txns']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vq['gross'], 2); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vq['net_ex_vat'], 2); ?></td>
                                        <td style="font-weight:700; color:var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($vq['vat'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:700">
                                    <td colspan="2">Total</td>
                                    <td><?php echo number_format(array_sum(array_column($vatByQuarter, 'txns'))); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format(array_sum(array_column($vatByQuarter, 'gross')), 2); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format(array_sum(array_column($vatByQuarter, 'net_ex_vat')), 2); ?></td>
                                    <td style="color:var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format(array_sum(array_column($vatByQuarter, 'vat')), 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="acct-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
                        <h2 class="acct-panel__title" style="margin-bottom: 0"><i class="fas fa-list"></i> Transaction VAT Register</h2>
                        <button class="acct-btn" onclick="exportToCSV()"><i class="fas fa-download"></i> Export for Filing</button>
                    </div>
                    <?php if (empty($vatRegister)): ?>
                        <p class="acct-empty">No VAT-bearing transactions in this period.</p>
                    <?php else: ?>
                        <table class="acct-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Client</th>
                                    <th>Type</th>
                                    <th>Method</th>
                                    <th>Rate</th>
                                    <th>Net</th>
                                    <th>VAT</th>
                                    <th>Gross</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vatRegister as $vr): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($vr['payment_date'])); ?></td>
                                        <td><a href="payments.php?search=<?php echo urlencode($vr['payment_reference']); ?>" class="acct-link"><?php echo htmlspecialchars($vr['payment_reference']); ?></a></td>
                                        <td class="acct-muted"><?php echo htmlspecialchars($vr['client_name']); ?></td>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($vr['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($vr['booking_type'])); ?></span></td>
                                        <td class="acct-muted"><?php echo htmlspecialchars(str_replace('_', ' ', $vr['payment_method'])); ?></td>
                                        <td><?php echo htmlspecialchars($vr['vat_rate']); ?>%</td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($vr['payment_amount'], 2); ?></td>
                                        <td style="color: var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($vr['vat_amount'], 2); ?></td>
                                        <td style="font-weight:600"><?php echo $currency_symbol . ' ' . number_format($vr['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:700">
                                    <td colspan="6">Total</td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross - $vatRegisterTotal, 2); ?></td>
                                    <td style="color: var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($vatRegisterTotal, 2); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($vatRegisterGross, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- AGING & AR TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'aging' ? 'active' : ''; ?>" id="tab-aging">

                <!-- Accountant Help Panel -->
                <details class="rh-help-panel">
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> Aging &amp; AR — What this means and how to act on it</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>📌 What is Accounts Receivable (AR)?</h4>
                                <p>AR = money that has been earned (a service delivered) but not yet collected in cash. On the balance sheet, AR is a current asset. The goal is to convert it to cash as quickly as possible.</p>
                            </div>
                            <div>
                                <h4>⏳ What is Aging?</h4>
                                <p>Aging tells you <em>how long</em> each unpaid balance has been outstanding. Older balances are harder to collect. Most hotels target DSO (Days Sales Outstanding) below 30 days.</p>
                            </div>
                            <div>
                                <h4>🟡 31–60 Days — Follow Up</h4>
                                <p>Send a polite payment reminder. Phone or WhatsApp the client. Confirm the invoice was received. Offer a payment plan if the amount is large. Document every contact attempt.</p>
                            </div>
                            <div>
                                <h4>🔴 90+ Days — Urgent</h4>
                                <p>This is high-risk territory. Consider: (1) formal demand letter, (2) stopping future credit, (3) referring to a debt collector, (4) write-off provision. Any amount over 90 days should be reviewed at management level.</p>
                            </div>
                            <div>
                                <h4>✏️ Bad Debt Write-off</h4>
                                <p>If a debt is genuinely uncollectable, write it off — remove it from AR and record as a Bad Debt Expense. This keeps your AR figure realistic. In Malawi, bad debts may be deductible for income tax purposes with proper documentation.</p>
                            </div>
                            <div>
                                <h4>📊 Healthy AR Benchmark</h4>
                                <p>For a hotel: 70%+ of AR should be in the 0–30 day bucket. If 90+ days represents more than 20% of total AR, tighten credit terms and review your collection process.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--receivables">
                        <div class="acct-kpi__label">Total Outstanding AR</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalAgingAmount, 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo count($agingDetail); ?> unpaid accounts</div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Current (0–30 days)</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($agingBuckets['0-30']['amount'], 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo $agingBuckets['0-30']['count']; ?> accounts</div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">31–90 Days Overdue</div>
                        <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($agingBuckets['31-60']['amount'] + $agingBuckets['61-90']['amount'], 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo $agingBuckets['31-60']['count'] + $agingBuckets['61-90']['count']; ?> accounts</div>
                    </div>
                    <div class="acct-kpi acct-kpi--receivables">
                        <div class="acct-kpi__label">90+ Days (Critical)</div>
                        <div class="acct-kpi__value" style="color: #c82333"><?php echo $currency_symbol . ' ' . number_format($agingBuckets['90+']['amount'], 2); ?></div>
                        <div class="acct-kpi__sub"><?php echo $agingBuckets['90+']['count']; ?> accounts — immediate action</div>
                    </div>
                </div>

                <!-- Aging Buckets Summary -->
                <div class="acct-panel">
                    <h2 class="acct-panel__title"><i class="fas fa-hourglass-half"></i> Receivables Aging Summary</h2>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Age Bucket</th>
                                <th>Accounts</th>
                                <th>Amount Outstanding</th>
                                <th>% of Total AR</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $bucketLabels = ['0-30' => '0–30 Days', '31-60' => '31–60 Days', '61-90' => '61–90 Days', '90+' => '90+ Days (Critical)'];
                            foreach ($agingBuckets as $bk => $bd):
                                $pct = $totalAgingAmount > 0 ? round(($bd['amount'] / $totalAgingAmount) * 100, 1) : 0;
                                $isDanger = $bk === '90+' && $bd['count'] > 0;
                            ?>
                                <tr class="<?php echo $isDanger ? 'acct-danger-row' : ''; ?>">
                                    <td><?php echo htmlspecialchars($bucketLabels[$bk]); ?></td>
                                    <td><?php echo $bd['count']; ?></td>
                                    <td style="font-weight: 600; <?php echo $isDanger ? 'color: #c82333' : ''; ?>"><?php echo $currency_symbol . ' ' . number_format($bd['amount'], 2); ?></td>
                                    <td>
                                        <div class="acct-bar">
                                            <div class="acct-bar__fill <?php echo $isDanger ? 'acct-bar__fill--danger' : ''; ?>" style="width: <?php echo $pct; ?>%"></div>
                                            <span class="acct-bar__label"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td><a href="payments.php?status=<?php echo urlencode($bk === '0-30' ? 'pending' : 'pending'); ?>" class="acct-link">Chase →</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700">
                                <td>Total</td>
                                <td><?php echo count($agingDetail); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($totalAgingAmount, 2); ?></td>
                                <td>100%</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Detailed AR Ledger -->
                <div class="acct-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
                        <h2 class="acct-panel__title" style="margin-bottom: 0"><i class="fas fa-list-alt"></i> Outstanding Accounts Ledger</h2>
                        <button class="acct-btn" onclick="exportToCSV()"><i class="fas fa-download"></i> Export AR Ledger</button>
                    </div>
                    <?php if (empty($agingDetail)): ?>
                        <p class="acct-empty acct-empty--good"><i class="fas fa-check-circle"></i> No outstanding balances — all accounts settled.</p>
                    <?php else: ?>
                        <table class="acct-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Client</th>
                                    <th>Type</th>
                                    <th>Method</th>
                                    <th>Invoice Date</th>
                                    <th>Days O/S</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agingDetail as $ag):
                                    $d = (int)$ag['days_outstanding'];
                                    $bk = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
                                ?>
                                    <tr>
                                        <td><a href="payments.php?search=<?php echo urlencode($ag['payment_reference']); ?>" class="acct-link"><?php echo htmlspecialchars($ag['payment_reference']); ?></a></td>
                                        <td><?php echo htmlspecialchars($ag['client_name']); ?></td>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($ag['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($ag['booking_type'])); ?></span></td>
                                        <td class="acct-muted"><?php echo htmlspecialchars(str_replace('_', ' ', $ag['payment_method'])); ?></td>
                                        <td class="acct-muted"><?php echo date('d M Y', strtotime($ag['payment_date'])); ?></td>
                                        <td class="<?php echo $bk === '90+' ? 'acct-danger' : ($bk !== '0-30' ? 'acct-warn' : ''); ?>"><?php echo $d; ?>d</td>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($ag['payment_status']); ?>"><?php echo ucfirst(htmlspecialchars($ag['payment_status'])); ?></span></td>
                                        <td style="font-weight:600"><?php echo $currency_symbol . ' ' . number_format($ag['total_amount'], 2); ?></td>
                                        <td><a href="payment-add.php?booking_type=<?php echo urlencode($ag['booking_type']); ?>" class="acct-link">Record →</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- BOOKINGS TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'bookings' ? 'active' : ''; ?>" id="tab-bookings">

                <!-- Accountant Help Panel -->
                <details class="rh-help-panel">
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> Bookings Report — What each metric means</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>📋 Booking vs Payment</h4>
                                <p>A <strong>booking</strong> is a reservation record. A <strong>payment</strong> is money collected. They don't always match — a booking may have partial payment or zero payment (tentative). Always cross-reference with the Payments and Aging tabs for cash reality.</p>
                            </div>
                            <div>
                                <h4>❌ Cancellation Rate</h4>
                                <p>Industry benchmark: &lt;10% is excellent, 15–25% is average. High rates indicate: advance booking window too short, weak deposit policy, or price sensitivity. Consider enforcing a non-refundable deposit for bookings made more than 14 days ahead.</p>
                            </div>
                            <div>
                                <h4>📏 Average Stay Length</h4>
                                <p>Longer stays reduce turnover costs (cleaning, check-in/out). If ADR is healthy, incentivise 3+ night stays with package deals. Weekend vs weekday patterns are useful for staffing decisions.</p>
                            </div>
                            <div>
                                <h4>💡 No-Show Rate</h4>
                                <p>No-shows without prepayment = lost revenue. Consider: requiring full prepayment for stays under 3 nights, or a 1-night deposit. If no-shows are above 5%, review your confirmation reminder process (WhatsApp/SMS reminders).</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--revenue">
                        <div class="acct-kpi__label">Total Bookings</div>
                        <div class="acct-kpi__value"><?php echo number_format($totalBookings); ?></div>
                        <div class="acct-kpi__sub">Non-cancelled · Avg value <?php echo $currency_symbol . ' ' . number_format($avgRevenuePerBooking, 2); ?></div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Total Guests</div>
                        <div class="acct-kpi__value"><?php echo number_format($bookingSummary['total_guests'] ?? 0); ?></div>
                        <div class="acct-kpi__sub"><?php echo number_format($bookingSummary['total_adults'] ?? 0); ?> adults · <?php echo number_format($bookingSummary['total_children'] ?? 0); ?> children</div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Avg Stay Length</div>
                        <div class="acct-kpi__value"><?php echo $avgStayLength; ?> nights</div>
                        <div class="acct-kpi__sub">Child supplement revenue: <?php echo $currency_symbol . ' ' . number_format($bookingSummary['total_child_revenue'] ?? 0, 2); ?></div>
                    </div>
                    <div class="acct-kpi acct-kpi--receivables">
                        <div class="acct-kpi__label">Cancellation Rate</div>
                        <div class="acct-kpi__value"><?php echo $cancellationRate; ?>%</div>
                        <div class="acct-kpi__sub"><?php echo $cancelData['cancelled'] ?? 0; ?> of <?php echo $cancelData['total'] ?? 0; ?> bookings</div>
                    </div>
                </div>

                <!-- Booking Status Breakdown -->
                <div class="acct-panel">
                    <h2 class="acct-panel__title"><i class="fas fa-chart-bar"></i> Booking Status Breakdown</h2>
                    <?php if (empty($bookingStatusData)): ?>
                        <p class="acct-empty">No booking data for this period.</p>
                    <?php else: ?>
                        <table class="acct-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Count</th>
                                    <th>Total Value</th>
                                    <th>Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalBkVal = array_sum(array_column($bookingStatusData, 'total_value'));
                                foreach ($bookingStatusData as $bs):
                                    $bsPct = $totalBkVal > 0 ? round(($bs['total_value'] / $totalBkVal) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($bs['status']); ?>"><?php echo ucfirst(str_replace('-', ' ', htmlspecialchars($bs['status']))); ?></span></td>
                                        <td><?php echo number_format($bs['count']); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($bs['total_value'], 2); ?></td>
                                        <td>
                                            <div class="acct-bar">
                                                <div class="acct-bar__fill" style="width: <?php echo $bsPct; ?>%"></div>
                                                <span class="acct-bar__label"><?php echo $bsPct; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Room-level Stats -->
                <div class="report-section">
                    <h2><i class="fas fa-bed"></i> Bookings by Room Type</h2>
                    <?php if (empty($roomBookingStats)): ?>
                        <div class="empty-state"><i class="fas fa-bed"></i>
                            <p>No room data available</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Room Type</th>
                                    <th>Price/Night</th>
                                    <th>Bookings</th>
                                    <th>Total Nights</th>
                                    <th>Avg Stay</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $totalRoomRevenue = 0;
                                foreach ($roomBookingStats as $room): $totalRoomRevenue += $room['total_revenue']; ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($room['room_name']); ?></strong></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($room['price_per_night'], 2); ?></td>
                                        <td><?php echo number_format($room['booking_count']); ?></td>
                                        <td><?php echo number_format($room['total_nights']); ?></td>
                                        <td><?php echo number_format($room['avg_stay'], 1); ?></td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($room['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5"><strong>Total</strong></td>
                                    <td><strong><?php echo $currency_symbol . ' ' . number_format($totalRoomRevenue, 2); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Recent Bookings -->
                <div class="report-section">
                    <h2><i class="fas fa-clock"></i> Recent Bookings</h2>
                    <?php if (empty($recentBookings)): ?>
                        <div class="empty-state"><i class="fas fa-calendar"></i>
                            <p>No recent bookings</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Check-in</th>
                                    <th>Nights</th>
                                    <th>Guests</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBookings as $bk): ?>
                                    <?php
                                    $bkChild = (int)($bk['child_guests'] ?? 0);
                                    $bkAdult = (int)($bk['adult_guests'] ?? max(1, ((int)$bk['number_of_guests']) - $bkChild));
                                    ?>
                                    <tr>
                                        <td><a href="booking-details.php?id=<?php echo (int)$bk['id']; ?>"><?php echo htmlspecialchars($bk['booking_reference']); ?></a></td>
                                        <td><?php echo htmlspecialchars($bk['guest_name']); ?></td>
                                        <td><?php echo htmlspecialchars($bk['room_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M j', strtotime($bk['check_in_date'])); ?></td>
                                        <td><?php echo $bk['number_of_nights']; ?></td>
                                        <td>
                                            <?php echo $bkAdult; ?>A<?php if ($bkChild > 0): ?> + <?php echo $bkChild; ?>C<?php endif; ?>
                                        </td>
                                        <td><?php echo $currency_symbol . ' ' . number_format($bk['total_amount'], 2); ?></td>
                                        <td><span class="badge-sm badge-<?php echo htmlspecialchars($bk['status']); ?>"><?php echo ucfirst(str_replace('-', ' ', htmlspecialchars($bk['status']))); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- OCCUPANCY TAB -->
            <!-- ============================================ -->
            <div class="tab-content <?php echo $active_tab === 'occupancy' ? 'active' : ''; ?>" id="tab-occupancy">

                <!-- Accountant Help Panel -->
                <details class="rh-help-panel">
                    <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> Occupancy Report — ADR, RevPAR and room performance</summary>
                    <div class="rh-help-panel__body">
                        <div class="rh-help-panel__grid">
                            <div>
                                <h4>🏨 Occupancy Rate</h4>
                                <p>Occupancy % = Room Nights Sold ÷ Room Nights Available × 100. A 70%+ rate is strong for a boutique African hotel. Weekend occupancy above 85% with weekdays below 50% indicates a leisure-heavy mix — consider corporate packages to smooth weekday revenue.</p>
                            </div>
                            <div>
                                <h4>💰 ADR (Average Daily Rate)</h4>
                                <p>ADR = Total Room Revenue ÷ Rooms Sold. It measures your <em>pricing power</em>. Rising ADR with stable or rising occupancy is the best outcome. Rising ADR with falling occupancy may signal price resistance.</p>
                            </div>
                            <div>
                                <h4>📐 RevPAR (Revenue Per Available Room)</h4>
                                <p>RevPAR = ADR × Occupancy Rate. This is the single most important KPI for hotel investors and lenders. African boutique hotel benchmark: MWK 15,000–40,000 per night. If RevPAR is far below ADR, rooms are sitting empty at peak times.</p>
                            </div>
                            <div>
                                <h4>📊 Per Room Type Performance</h4>
                                <p>The table below breaks ADR and RevPAR by room type. Low-performing room types may need a rate review, improved photography, or a promotional package. High-performing types are candidates for upsell messaging.</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="acct-kpis">
                    <div class="acct-kpi acct-kpi--revenue">
                        <div class="acct-kpi__label">Occupancy Rate</div>
                        <div class="acct-kpi__value"><?php echo $overallOccupancyRate; ?>%</div>
                        <div class="acct-kpi__sub"><?php echo round($daysInPeriod); ?>-day period</div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Room-Nights Booked</div>
                        <div class="acct-kpi__value"><?php echo number_format($overallOccupancy['total_nights_booked']); ?></div>
                        <div class="acct-kpi__sub">of <?php echo number_format($totalRoomNightsAvailable); ?> available</div>
                    </div>
                    <div class="acct-kpi">
                        <div class="acct-kpi__label">Room Inventory</div>
                        <div class="acct-kpi__value"><?php echo number_format($totalRoomInventory); ?></div>
                        <div class="acct-kpi__sub">Active rooms</div>
                    </div>
                    <div class="acct-kpi acct-kpi--cash">
                        <div class="acct-kpi__label">Guests Served</div>
                        <div class="acct-kpi__value"><?php echo number_format($overallOccupancy['total_guests']); ?></div>
                        <div class="acct-kpi__sub">Avg <?php echo number_format($overallOccupancy['avg_guests_per_booking'], 1); ?> per booking</div>
                    </div>
                </div>

                <!-- Overall Occupancy Bar -->
                <div class="acct-panel">
                    <h2 class="acct-panel__title"><i class="fas fa-hotel"></i> Overall Occupancy Rate</h2>
                    <div style="margin-bottom: 8px; font-size: 13px; color: var(--color-text-secondary);">
                        <?php echo number_format($overallOccupancy['total_nights_booked']); ?> room-nights booked out of <?php echo number_format($totalRoomNightsAvailable); ?> available
                    </div>
                    <div class="acct-bar" style="height: 22px; border-radius: 11px;">
                        <div class="acct-bar__fill" style="width: <?php echo min(100, $overallOccupancyRate); ?>%; height: 100%;"></div>
                        <span class="acct-bar__label"><?php echo $overallOccupancyRate; ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Occupancy by Room Type -->
            <div class="acct-panel" style="margin-top: 16px">
                <h2 class="acct-panel__title"><i class="fas fa-bed"></i> Occupancy by Room Type</h2>
                <?php if (empty($occupancyData)): ?>
                    <p class="acct-empty">No occupancy data for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Room Type</th>
                                <th>Rooms</th>
                                <th>Bookings</th>
                                <th>Nights Booked</th>
                                <th>Guests</th>
                                <th>Occupancy Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($occupancyData as $occ):
                                $roomAvail = $occ['total_rooms'] * $daysInPeriod;
                                $roomOccRate = $roomAvail > 0 ? round(($occ['nights_booked'] / $roomAvail) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($occ['room_name']); ?></strong></td>
                                    <td><?php echo $occ['total_rooms']; ?></td>
                                    <td><?php echo number_format($occ['bookings']); ?></td>
                                    <td><?php echo number_format($occ['nights_booked']); ?></td>
                                    <td><?php echo number_format($occ['total_guests']); ?></td>
                                    <td>
                                        <div class="acct-bar">
                                            <div class="acct-bar__fill" style="width: <?php echo min(100, $roomOccRate); ?>%"></div>
                                            <span class="acct-bar__label"><?php echo $roomOccRate; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if (!empty($roomTypeAdr)): ?>
                <!-- Per Room Type ADR / RevPAR -->
                <div class="acct-panel" style="margin-top: 16px">
                    <h2 class="acct-panel__title"><i class="fas fa-chart-bar"></i> ADR &amp; RevPAR by Room Type</h2>
                    <p class="acct-muted" style="margin-bottom:12px; font-size:0.85em">ADR = Revenue ÷ Nights Sold. RevPAR = Revenue ÷ (Rooms × Days in Period). RevPAR below 40% of ADR means significant empty nights.</p>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Room Type</th>
                                <th>Revenue</th>
                                <th>Nights Sold</th>
                                <th title="Average Daily Rate — revenue per room-night sold">ADR</th>
                                <th title="Revenue Per Available Room — revenue per room-night available (including empty nights)">RevPAR</th>
                                <th>RevPAR / ADR</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomTypeAdr as $rt):
                                $rtAvailNights = $rt['total_rooms'] * $daysInPeriod;
                                $rtRevpar = $rtAvailNights > 0 ? round((float)$rt['revenue'] / $rtAvailNights, 0) : 0;
                                $rtRatio  = (float)$rt['adr'] > 0 ? round(($rtRevpar / (float)$rt['adr']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($rt['room_name']); ?></strong></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format((float)$rt['revenue'], 0); ?></td>
                                    <td><?php echo number_format((int)$rt['nights_sold']); ?></td>
                                    <td style="font-weight:600"><?php echo $currency_symbol . ' ' . number_format((float)$rt['adr'], 0); ?></td>
                                    <td style="font-weight:600; color: var(--color-lux-gold)"><?php echo $currency_symbol . ' ' . number_format($rtRevpar, 0); ?></td>
                                    <td>
                                        <div class="acct-bar">
                                            <div class="acct-bar__fill <?php echo $rtRatio < 40 ? 'acct-bar__fill--danger' : ''; ?>" style="width: <?php echo min(100, $rtRatio); ?>%"></div>
                                            <span class="acct-bar__label"><?php echo $rtRatio; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- GUESTS TAB -->
    <!-- ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'guests' ? 'active' : ''; ?>" id="tab-guests">

        <!-- Accountant Help Panel -->
        <details class="rh-help-panel">
            <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> Guests Report — Understanding your customer base</summary>
            <div class="rh-help-panel__body">
                <div class="rh-help-panel__grid">
                    <div>
                        <h4>👥 Unique Guests vs Total Bookings</h4>
                        <p>Unique guests = distinct email addresses. If unique guests is much lower than total bookings, you have strong repeat business — excellent for lifetime value and marketing ROI.</p>
                    </div>
                    <div>
                        <h4>🔁 Repeat Guest Rate</h4>
                        <p>Repeat guests are 5–7× cheaper to acquire than new ones. A high repeat rate means strong satisfaction and loyalty. Target: 25%+ of bookings from repeat guests. Track this monthly.</p>
                    </div>
                    <div>
                        <h4>🌍 Nationality Mix</h4>
                        <p>Domestic vs international split informs marketing spend. High international share = invest in OTA presence and USD pricing. High domestic = invest in local corporate accounts and weekend packages. Seasonal shifts indicate market sensitivity.</p>
                    </div>
                    <div>
                        <h4>⭐ Reviews &amp; Satisfaction</h4>
                        <p>Average rating below 4.0 / 5.0 requires management attention. For every 1-star increase in TripAdvisor/Google rating, hotels typically see 5–9% revenue uplift. Negative reviews should be actioned within 24 hours.</p>
                    </div>
                </div>
            </div>
        </details>

        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Unique Guests</div>
                <div class="acct-kpi__value"><?php echo number_format($guestMetrics['unique_guests'] ?? 0); ?></div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Avg Spend per Guest</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($guestMetrics['avg_spend'] ?? 0, 2); ?></div>
            </div>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Repeat Guests</div>
                <div class="acct-kpi__value"><?php echo count($repeatGuests); ?></div>
            </div>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Avg Rating</div>
                <div class="acct-kpi__value"><?php echo number_format($reviewStats['avg_rating'] ?? 0, 1); ?> <i class="fas fa-star" style="color: var(--color-lux-gold); font-size: 0.7em"></i></div>
                <div class="acct-kpi__sub"><?php echo ($reviewStats['total_reviews'] ?? 0); ?> review(s)</div>
            </div>
        </div>

        <div class="acct-grid acct-grid--2">
            <!-- Country Distribution -->
            <div class="acct-panel">
                <h2 class="acct-panel__title"><i class="fas fa-globe-africa"></i> Guest Origin Countries</h2>
                <?php if (empty($guestCountryData)): ?>
                    <p class="acct-empty">No guest data for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Country</th>
                                <th>Bookings</th>
                                <th>Guests</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($guestCountryData as $gc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($gc['country']); ?></td>
                                    <td><?php echo number_format($gc['booking_count']); ?></td>
                                    <td><?php echo number_format($gc['total_guests']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($gc['total_spent'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Review Summary -->
            <div class="report-section">
                <h2><i class="fas fa-star"></i> Guest Reviews Summary</h2>
                <?php if (($reviewStats['total_reviews'] ?? 0) == 0): ?>
                    <p class="acct-empty">No approved reviews for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <tbody>
                            <tr>
                                <td>Total Reviews</td>
                                <td style="text-align:right; font-weight:600"><?php echo $reviewStats['total_reviews']; ?></td>
                            </tr>
                            <tr>
                                <td>Average Rating</td>
                                <td style="text-align:right; font-weight:600"><?php echo number_format($reviewStats['avg_rating'], 1); ?>/5</td>
                            </tr>
                            <tr>
                                <td>Positive (4–5 stars)</td>
                                <td style="text-align:right; color: #28a745; font-weight:600"><?php echo $reviewStats['positive_reviews']; ?></td>
                            </tr>
                            <tr>
                                <td>Negative (1–2 stars)</td>
                                <td style="text-align:right; color: #c82333; font-weight:600"><?php echo $reviewStats['negative_reviews']; ?></td>
                            </tr>
                            <tr>
                                <td>Satisfaction Rate</td>
                                <td style="text-align:right; font-weight:600"><?php echo $reviewStats['total_reviews'] > 0 ? number_format(($reviewStats['positive_reviews'] / $reviewStats['total_reviews']) * 100, 0) : 0; ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Repeat Guests -->
        <div class="acct-panel">
            <h2 class="acct-panel__title"><i class="fas fa-redo"></i> Repeat Guests (Loyalty Analysis)</h2>
            <?php if (empty($repeatGuests)): ?>
                <p class="acct-empty">No repeat guests found in this period.</p>
            <?php else: ?>
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Email</th>
                            <th>Country</th>
                            <th>Stays</th>
                            <th>Total Spent</th>
                            <th>First Visit</th>
                            <th>Last Visit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repeatGuests as $rg): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($rg['guest_name']); ?></strong></td>
                                <td class="acct-muted"><?php echo htmlspecialchars($rg['guest_email']); ?></td>
                                <td class="acct-muted"><?php echo htmlspecialchars($rg['guest_country'] ?? 'N/A'); ?></td>
                                <td style="font-weight:600; color: var(--color-lux-gold)"><?php echo $rg['booking_count']; ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($rg['total_spent'], 2); ?></td>
                                <td class="acct-muted"><?php echo date('d M Y', strtotime($rg['first_visit'])); ?></td>
                                <td class="acct-muted"><?php echo date('d M Y', strtotime($rg['last_visit'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- CONFERENCE TAB -->
    <!-- ============================================ -->
    <div class="tab-content <?php echo $active_tab === 'conference' ? 'active' : ''; ?>" id="tab-conference">

        <!-- Accountant Help Panel -->
        <details class="rh-help-panel">
            <summary class="rh-help-panel__toggle"><i class="fas fa-circle-question"></i> Conference &amp; Events — Revenue recognition and pipeline</summary>
            <div class="rh-help-panel__body">
                <div class="rh-help-panel__grid">
                    <div>
                        <h4>📋 Inquiry to Revenue Pipeline</h4>
                        <p>Not all inquiries become revenue. Track the conversion rate (confirmed ÷ total inquiries). A rate below 40% suggests pricing, response time, or capacity issues. Each confirmed event should have a signed contract and deposit before resources are reserved.</p>
                    </div>
                    <div>
                        <h4>💳 Deposit vs Full Payment</h4>
                        <p><strong>Amount Paid</strong> vs <strong>Total Value</strong> shows the deposit coverage. If &quot;amount paid&quot; is far below &quot;total value&quot;, the hotel is carrying event credit risk. Require 50%+ deposit on all events above MWK 500,000.</p>
                    </div>
                    <div>
                        <h4>📅 Revenue Recognition</h4>
                        <p>Conference revenue should be recognised on the <em>date of the event</em>, not the date of deposit. Deposits received before the event date are Deferred Revenue (a liability) until the event occurs.</p>
                    </div>
                    <div>
                        <h4>🏢 Room Utilisation</h4>
                        <p>The conference room utilisation table shows which rooms generate the most revenue. Low-utilisation rooms are candidates for re-pricing, repackaging, or being offered for alternative uses (filming, training, breakout rooms).</p>
                    </div>
                </div>
            </div>
        </details>

        <?php
            $totalConfEvents = 0;
            $totalConfRevenue = 0;
            $totalConfPaid = 0;
            foreach ($conferenceStats as $cs) {
                $totalConfEvents += $cs['count'];
                $totalConfRevenue += $cs['total_value'];
                $totalConfPaid += $cs['total_paid'];
            }
            $totalGymInquiries = 0;
            $totalGymRevenue = 0;
            $totalGymPaid = 0;
            foreach ($gymInquiryStats as $gi) {
                $totalGymInquiries += $gi['count'];
                $totalGymRevenue += $gi['total_value'];
                $totalGymPaid += $gi['total_paid'];
            }
            $gymOutstanding = $totalGymRevenue - $totalGymPaid;

            $totalEventBookings = 0;
            $totalEventRevenue = 0;
            $totalEventPaid = 0;
            foreach ($eventInquiryStats as $ei) {
                $totalEventBookings += $ei['count'];
                $totalEventRevenue += $ei['total_value'];
                $totalEventPaid += $ei['total_paid'];
            }
            $eventOutstanding = $totalEventRevenue - $totalEventPaid;

            $confCollectionPct = $totalConfRevenue > 0 ? round(($totalConfPaid / $totalConfRevenue) * 100, 0) : 0;
            $confOutstanding = $totalConfRevenue - $totalConfPaid;
        ?>

        <div class="acct-kpis">
            <?php if ($mod_conference): ?>
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Conference Revenue</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalConfRevenue, 2); ?></div>
                <div class="acct-kpi__sub"><?php echo number_format($totalConfEvents); ?> inquiries</div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Amount Collected</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalConfPaid, 2); ?></div>
                <div class="acct-kpi__sub"><?php echo $confCollectionPct; ?>% collection rate</div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Outstanding (Conf.)</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($confOutstanding, 2); ?></div>
                <div class="acct-kpi__sub">Unpaid conference balances</div>
            </div>
            <?php endif; ?>
            <?php if ($mod_gym): ?>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Gym Revenue</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalGymRevenue, 2); ?></div>
                <div class="acct-kpi__sub"><?php echo number_format($totalGymInquiries); ?> inquiries</div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Outstanding (Gym)</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($gymOutstanding, 2); ?></div>
                <div class="acct-kpi__sub">Unpaid gym balances</div>
            </div>
            <?php endif; ?>
            <?php if ($mod_events): ?>
            <div class="acct-kpi">
                <div class="acct-kpi__label">Event Revenue</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($totalEventRevenue, 2); ?></div>
                <div class="acct-kpi__sub"><?php echo number_format($totalEventBookings); ?> bookings</div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Outstanding (Events)</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . ' ' . number_format($eventOutstanding, 2); ?></div>
                <div class="acct-kpi__sub">Unpaid event balances</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="acct-grid acct-grid--2">
            <?php if ($mod_conference): ?>
            <!-- Conference Status -->
            <div class="acct-panel">
                <h2 class="acct-panel__title"><i class="fas fa-briefcase"></i> Conference Inquiry Status</h2>
                <?php if (empty($conferenceStats)): ?>
                    <p class="acct-empty">No conference inquiries for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Value</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conferenceStats as $cs): ?>
                                <tr>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($cs['status']); ?>"><?php echo ucfirst(htmlspecialchars($cs['status'])); ?></span></td>
                                    <td><?php echo number_format($cs['count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($cs['total_value'], 2); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($cs['total_paid'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; // mod_conference ?>

            <?php if ($mod_gym): ?>
            <!-- Gym Inquiry Status -->
            <div class="acct-panel">
                <h2 class="acct-panel__title"><i class="fas fa-dumbbell"></i> Gym Inquiry Status</h2>
                <?php if (empty($gymInquiryStats)): ?>
                    <p class="acct-empty">No gym inquiries for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Value</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gymInquiryStats as $gi): ?>
                                <tr>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($gi['status'] === 'new' ? 'pending' : ($gi['status'] === 'closed' ? 'completed' : $gi['status'])); ?>"><?php echo ucfirst(htmlspecialchars($gi['status'])); ?></span></td>
                                    <td><?php echo number_format($gi['count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($gi['total_value'], 2); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($gi['total_paid'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; // mod_gym ?>

            <?php if ($mod_events): ?>
            <!-- Event Booking Status -->
            <div class="acct-panel">
                <h2 class="acct-panel__title"><i class="fas fa-calendar-check"></i> Event Booking Status</h2>
                <?php if (empty($eventInquiryStats)): ?>
                    <p class="acct-empty">No event bookings for this period.</p>
                <?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Value</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventInquiryStats as $ei): ?>
                                <tr>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($ei['status']); ?>"><?php echo ucfirst(htmlspecialchars($ei['status'])); ?></span></td>
                                    <td><?php echo number_format($ei['count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($ei['total_value'], 2); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($ei['total_paid'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; // mod_events ?>
        </div>

        <?php if ($mod_conference): ?>
        <!-- Conference Room Utilization -->
        <div class="acct-panel">
            <h2 class="acct-panel__title"><i class="fas fa-building"></i> Conference Room Utilization</h2>
            <?php if (empty($conferenceRoomStats)): ?>
                <p class="acct-empty">No conference room data available.</p>
            <?php else: ?>
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Capacity</th>
                            <th>Events</th>
                            <th>Avg Attendees</th>
                            <th>Revenue</th>
                            <th>Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conferenceRoomStats as $cr):
                            $utilizationPct = $cr['capacity'] > 0 && $cr['avg_attendees'] > 0
                                ? round(($cr['avg_attendees'] / $cr['capacity']) * 100, 0) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cr['room_name']); ?></strong></td>
                                <td><?php echo number_format($cr['capacity']); ?></td>
                                <td><?php echo number_format($cr['total_events']); ?></td>
                                <td><?php echo number_format($cr['avg_attendees'], 0); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($cr['total_revenue'], 2); ?></td>
                                <td>
                                    <div class="acct-bar">
                                        <div class="acct-bar__fill" style="width: <?php echo min(100, $utilizationPct); ?>%"></div>
                                        <span class="acct-bar__label"><?php echo $utilizationPct; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; // mod_conference ?>

        <?php if ($mod_gym): ?>
        <!-- Gym Revenue Trend -->
        <div class="acct-panel">
            <h2 class="acct-panel__title"><i class="fas fa-dumbbell"></i> Gym Revenue Trend</h2>
            <?php if (empty($gymRevenueTrend)): ?>
                <p class="acct-empty">No gym bookings in this period.</p>
            <?php else: ?>
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Bookings</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gymRevenueTrend as $gt): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($gt['day'])); ?></td>
                                <td><?php echo number_format($gt['bookings']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($gt['revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; // mod_gym ?>

        <?php if ($mod_events): ?>
        <!-- Event Revenue Trend -->
        <div class="acct-panel">
            <h2 class="acct-panel__title"><i class="fas fa-calendar-check"></i> Event Revenue Trend</h2>
            <?php if (empty($eventRevenueTrend)): ?>
                <p class="acct-empty">No event bookings in this period.</p>
            <?php else: ?>
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Bookings</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventRevenueTrend as $et): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($et['day'])); ?></td>
                                <td><?php echo number_format($et['bookings']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($et['revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; // mod_events ?>
    </div>

<?php endif; ?>

<?php /* New comprehensive tabs: F&B/POS, Stock, Staff, Voids */ require __DIR__ . '/includes/reports-extra-tabs.php'; ?>
</div>

<script>
    function setReportsLoader(visible) {
        document.getElementById('admin-page-loader')?.classList.toggle('is-visible', !!visible);
    }

    function exportToCSV() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const tab = '<?php echo htmlspecialchars($active_tab); ?>';
        const url = '../api/reports-export.php?start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&report_type=' + encodeURIComponent(tab);
        setReportsLoader(true);
        window.open(url, '_blank');
        setTimeout(() => setReportsLoader(false), 700);
    }

    function setDateRange(range) {
        const today = new Date();
        let startDate, endDate;

        switch (range) {
            case 'today':
                startDate = endDate = today.toISOString().split('T')[0];
                break;
            case 'week':
                const weekStart = new Date(today);
                weekStart.setDate(today.getDate() - today.getDay());
                startDate = weekStart.toISOString().split('T')[0];
                endDate = today.toISOString().split('T')[0];
                break;
            case 'month':
                startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                break;
            case 'quarter':
                const qStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                const qEnd = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 + 3, 0);
                startDate = qStart.toISOString().split('T')[0];
                endDate = qEnd.toISOString().split('T')[0];
                break;
            case 'year':
                startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                break;
            case 'all':
                startDate = new Date(today.getFullYear() - 5, 0, 1).toISOString().split('T')[0];
                endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                break;
        }

        document.getElementById('start_date').value = startDate;
        document.getElementById('end_date').value = endDate;
        const form = document.querySelector('.date-filter form');
        setReportsLoader(true);
        form.submit();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelector('.date-filter form')?.addEventListener('submit', () => setReportsLoader(true));

        document.querySelectorAll('.report-tabs .report-tab').forEach(tab => {
            if (tab.classList.contains('active')) tab.setAttribute('aria-current', 'page');
        });

        document.querySelectorAll('.report-table, .rx-table').forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            table.querySelectorAll('tbody tr, tfoot tr').forEach(row => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', headers[index] || 'Value');
                    }
                });
            });
        });

        const interactiveCards = document.querySelectorAll('.summary-card, .status-card, .rx-kpi');
        interactiveCards.forEach(card => {
            card.setAttribute('role', 'button');
            card.setAttribute('tabindex', '0');
            card.setAttribute('aria-pressed', 'false');
            card.setAttribute('title', 'Tap to pin details');
            if (!card.querySelector('.report-card-detail')) {
                const title = card.querySelector('h3, .lbl, .label')?.textContent.trim() || 'Report card';
                const value = card.querySelector('.value, .val, .count')?.textContent.trim() || '';
                const subtitle = card.querySelector('.subtitle, .sub, .amount')?.textContent.trim() || 'Tap again to collapse this card.';
                const detail = document.createElement('div');
                detail.className = 'report-card-detail';
                detail.textContent = [title, value, subtitle].filter(Boolean).join(' · ');
                card.appendChild(detail);
            }
        });

        document.addEventListener('click', event => {
            const card = event.target.closest('.summary-card, .status-card, .rx-kpi');
            if (!card || event.target.closest('a, button, input, select, textarea')) return;
            card.classList.toggle('is-expanded');
            card.setAttribute('aria-pressed', card.classList.contains('is-expanded') ? 'true' : 'false');
        });

        document.addEventListener('keydown', event => {
            if (!['Enter', ' '].includes(event.key)) return;
            const card = event.target.closest('.summary-card, .status-card, .rx-kpi');
            if (!card) return;
            event.preventDefault();
            card.classList.toggle('is-expanded');
            card.setAttribute('aria-pressed', card.classList.contains('is-expanded') ? 'true' : 'false');
        });
    });
</script>

<div id="admin-page-loader" class="admin-page-loader" role="status" aria-label="Loading">
    <div class="admin-page-loader-card">
        <div class="admin-page-loader-spinner"><span></span><span></span><span></span></div>
        <p class="admin-page-loader-title">Loading...</p>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>

