<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once 'includes/finance-schema.php';
require_once '../config/credit-notes.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$conferenceFields = finance_conference_fields($pdo);
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');

// Get date filters - support "all" for no date filtering
$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';
$startDateInput = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
$endDateInput = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';

// Validate and sanitize date inputs
$startDate = $showAll ? '2000-01-01' : date('Y-m-01');
$endDate = $showAll ? '2099-12-31' : date('Y-m-t');

if (!$showAll && $startDateInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateInput) && strtotime($startDateInput)) {
    $startDate = $startDateInput;
}
if (!$showAll && $endDateInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateInput) && strtotime($endDateInput)) {
    $endDate = $endDateInput;
}

// Ensure end date is not before start date
if (strtotime($endDate) < strtotime($startDate)) {
    $endDate = $startDate;
}

$financialSummary = [
    'total_payments' => 0,
    'total_collected' => 0,
    'total_collected_excl_vat' => 0,
    'total_vat_collected' => 0,
    'total_pending' => 0,
    'total_refunds_issued' => 0,
    'total_refunded' => 0,
    'total_cancelled' => 0,
    'pending_refunds' => 0,
    'completed_refunds' => 0,
];
$roomSummary = ['total_bookings_with_payments' => 0, 'room_collected' => 0, 'room_vat_collected' => 0, 'total_room_outstanding' => 0];
$confSummary = ['total_conferences_with_payments' => 0, 'conf_collected' => 0, 'conf_vat_collected' => 0, 'total_conf_outstanding' => 0];
$restaurantSummary = ['total_restaurant_orders_with_payments' => 0, 'restaurant_collected' => 0, 'restaurant_vat_collected' => 0];
$paymentMethods = [];
$refundReasons = [];
$recentPayments = [];
$outstandingSummary = [];
$complianceSummary = [
    'completed_sales' => 0,
    'missing_receipts' => 0,
    'generated_invoices_missing_numbers' => 0,
    'mra_pending_or_unsubmitted' => 0,
    'paid_pos_without_ledger' => 0,
];
$mraColumnsAvailable = false;
$vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
$vatRate = getSetting('vat_rate');
$vatNumber = getSetting('vat_number');
$vatPricingMode = getSetting('vat_pricing_mode', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
$vatSettingsMessage = '';
$vatSettingsError = '';

// Module flags
$mod_bookings   = function_exists('moduleEnabled') && moduleEnabled('bookings');
$mod_pos        = function_exists('moduleEnabled') && moduleEnabled('pos');
$mod_stock      = function_exists('moduleEnabled') && moduleEnabled('stock');
$mod_conference = function_exists('moduleEnabled') && moduleEnabled('conference');
$mod_gym        = function_exists('moduleEnabled') && moduleEnabled('gym');
// Events has no dedicated module toggle (no "events" key in enabled_modules) —
// it's gated by its own legacy setting instead, same as it always has been.
$mod_events     = function_exists('isEventsEnabled') && isEventsEnabled();

// Preset-aware copy: hotels talk about "guests", every other business about
// "customers". Used in headings/tooltips/help text only — never in queries.
$acct_party = $mod_bookings ? 'guest' : 'customer';
// Billing surface per preset: invoices/quotations belong to businesses that
// bill named clients in advance (rooms, conference, gym, events); credit
// notes to accounts-receivable businesses (rooms, conference). Till-only
// presets (supermarket, retail, bar) settle at the POS — receipts + refunds.
$acct_billing = function_exists('rh_module_key_enabled') && rh_module_key_enabled('billing');
$acct_ar      = function_exists('rh_module_key_enabled') && rh_module_key_enabled('advance_booking');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vat_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $vatSettingsError = 'Security token invalid. Please refresh and try again.';
    } else {
        try {
            $vatEnabledValue = (string)(($_POST['vat_enabled'] ?? '0') === '1' ? '1' : '0');
            $vatRateInput = trim((string)($_POST['vat_rate'] ?? '0'));
            $vatNumberValue = trim((string)($_POST['vat_number'] ?? ''));

            if ($vatRateInput === '' || !is_numeric($vatRateInput)) {
                throw new Exception('VAT rate must be a valid number.');
            }

            $vatRateValue = round((float)$vatRateInput, 2);
            if ($vatRateValue < 0 || $vatRateValue > 100) {
                throw new Exception('VAT rate must be between 0 and 100.');
            }

            if (strlen($vatNumberValue) > 120) {
                throw new Exception('VAT number is too long.');
            }

            $vatPricingModeValue = ($_POST['vat_pricing_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';

            $savedEnabled = updateSetting('vat_enabled', $vatEnabledValue);
            $savedRate = updateSetting('vat_rate', (string)$vatRateValue);
            $savedNumber = updateSetting('vat_number', $vatNumberValue);
            $savedMode = updateSetting('vat_pricing_mode', $vatPricingModeValue);

            if (!$savedEnabled || !$savedRate || !$savedNumber || !$savedMode) {
                throw new Exception('Unable to save VAT settings right now.');
            }

            if (function_exists('rh_log_event')) {
                rh_log_event('admin/' . basename(__FILE__, '.php'), 'info', 'VAT settings updated from accounting dashboard', [
                    'user' => $user['username'] ?? '',
                    'user_id' => $user['id'] ?? null,
                    'vat_enabled' => $vatEnabledValue,
                    'vat_rate' => $vatRateValue,
                    'vat_number_set' => $vatNumberValue !== '',
                ]);
            }

            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate = getSetting('vat_rate');
            $vatNumber = getSetting('vat_number');
            $vatPricingMode = getSetting('vat_pricing_mode', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
            $vatSettingsMessage = 'VAT settings updated successfully.';
        } catch (Throwable $e) {
            $vatSettingsError = $e->getMessage();
        }
    }
}

// Fetch accounting statistics
try {
    // Overall financial summary with gross/net revenue calculation
    $financialStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_payments,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_collected,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN payment_amount ELSE 0 END), 0) as total_collected_excl_vat,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as total_vat_collected,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending', 'partial') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_pending,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN refund_amount ELSE 0 END), 0) as total_refunds_issued,
            COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN total_amount ELSE 0 END), 0) as total_refunded,
            COALESCE(SUM(CASE WHEN payment_status = 'cancelled' THEN total_amount ELSE 0 END), 0) as total_cancelled,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status = 'pending' THEN refund_amount ELSE 0 END), 0) as pending_refunds,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status = 'completed' THEN refund_amount ELSE 0 END), 0) as completed_refunds
        FROM payments
        WHERE payment_date BETWEEN ? AND ?
          AND deleted_at IS NULL
    ");
    $financialStmt->execute([$startDate, $endDate]);
    $financialSummary = $financialStmt->fetch(PDO::FETCH_ASSOC);

    if ($mod_bookings) {
        // Room bookings financial summary
        $roomStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT p.booking_id) as total_bookings_with_payments,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as room_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN p.vat_amount ELSE 0 END), 0)
                    as room_vat_collected,
                (
                    SELECT COALESCE(SUM(b2.amount_due), 0)
                    FROM bookings b2
                    WHERE b2.id IN (
                        SELECT DISTINCT p2.booking_id FROM payments p2
                        WHERE p2.booking_type = 'room'
                        AND p2.payment_date BETWEEN ? AND ?
                        AND p2.deleted_at IS NULL
                    )
                    AND b2.status IN ('pending', 'confirmed', 'checked-in')
                ) as total_room_outstanding
            FROM payments p
            WHERE p.booking_type = 'room'
            AND p.payment_date BETWEEN ? AND ?
            AND p.deleted_at IS NULL
        ");
        $roomStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $roomSummary = $roomStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($mod_conference) {
        // Conference bookings financial summary
        $confStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT p.booking_id) as total_conferences_with_payments,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as conf_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN p.vat_amount ELSE 0 END), 0)
                    as conf_vat_collected,
                (
                    SELECT COALESCE(SUM(ci2.amount_due), 0)
                    FROM conference_inquiries ci2
                    WHERE ci2.id IN (
                        SELECT DISTINCT p2.booking_id FROM payments p2
                        WHERE p2.booking_type = 'conference'
                        AND p2.payment_date BETWEEN ? AND ?
                        AND p2.deleted_at IS NULL
                    )
                    AND ci2.status NOT IN ('cancelled', 'rejected', 'expired')
                ) as total_conf_outstanding
            FROM payments p
            WHERE p.booking_type = 'conference'
            AND p.payment_date BETWEEN ? AND ?
            AND p.deleted_at IS NULL
        ");
        $confStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $confSummary = $confStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($mod_gym) {
        // Gym membership financial summary
        $gymStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT p.booking_id) as total_gym_with_payments,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as gym_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN p.vat_amount ELSE 0 END), 0)
                    as gym_vat_collected,
                (
                    SELECT COALESCE(SUM(gi2.amount_due), 0)
                    FROM gym_inquiries gi2
                    WHERE gi2.id IN (
                        SELECT DISTINCT p2.booking_id FROM payments p2
                        WHERE p2.booking_type = 'gym'
                        AND p2.payment_date BETWEEN ? AND ?
                        AND p2.deleted_at IS NULL
                    )
                    AND gi2.status NOT IN ('cancelled')
                ) as total_gym_outstanding
            FROM payments p
            WHERE p.booking_type = 'gym'
            AND p.payment_date BETWEEN ? AND ?
            AND p.deleted_at IS NULL
        ");
        $gymStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $gymSummary = $gymStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($mod_events) {
        // Event booking financial summary
        $eventsStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT p.booking_id) as total_events_with_payments,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as events_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN p.vat_amount ELSE 0 END), 0)
                    as events_vat_collected,
                (
                    SELECT COALESCE(SUM(ei2.amount_due), 0)
                    FROM event_inquiries ei2
                    WHERE ei2.id IN (
                        SELECT DISTINCT p2.booking_id FROM payments p2
                        WHERE p2.booking_type = 'event'
                        AND p2.payment_date BETWEEN ? AND ?
                        AND p2.deleted_at IS NULL
                    )
                    AND ei2.status NOT IN ('cancelled')
                ) as total_events_outstanding
            FROM payments p
            WHERE p.booking_type = 'event'
            AND p.payment_date BETWEEN ? AND ?
            AND p.deleted_at IS NULL
        ");
        $eventsStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $eventsSummary = $eventsStmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($mod_pos) {
        // Restaurant/POS financial summary synced from stock orders into payments
        $restaurantStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN COALESCE(p.payment_type, '') != 'refund' THEN p.booking_id ELSE NULL END) as total_restaurant_orders_with_payments,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as restaurant_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_status IN ('completed','processing') THEN p.vat_amount ELSE 0 END), 0)
                    as restaurant_vat_collected
            FROM payments p
            WHERE p.booking_type = 'restaurant'
            AND p.payment_date BETWEEN ? AND ?
            AND p.deleted_at IS NULL
        ");
        $restaurantStmt->execute([$startDate, $endDate]);
        $restaurantSummary = $restaurantStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Payment method breakdown
    $methodStmt = $pdo->prepare("
        SELECT
            payment_method,
            COUNT(*) as count,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total
        FROM payments
        WHERE payment_date BETWEEN ? AND ?
          AND deleted_at IS NULL
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $methodStmt->execute([$startDate, $endDate]);
    $paymentMethods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Refund breakdown by reason
    $refundReasonStmt = $pdo->prepare("
        SELECT
            refund_reason,
            COUNT(*) as count,
            COALESCE(SUM(refund_amount), 0) as total_amount
        FROM payments
        WHERE payment_type = 'refund'
          AND payment_date BETWEEN ? AND ?
          AND deleted_at IS NULL
        GROUP BY refund_reason
        ORDER BY total_amount DESC
    ");
    $refundReasonStmt->execute([$startDate, $endDate]);
    $refundReasons = $refundReasonStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent payments in selected date range (last 20)
    $recentStmt = $pdo->prepare("
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.booking_reference, ')')
                WHEN p.booking_type = 'conference' THEN CONCAT(ci.{$conferenceFields['company']}, ' (', ci.{$conferenceFields['reference']}, ')')
                WHEN p.booking_type = 'restaurant' THEN CONCAT('Restaurant order ', so.reference, COALESCE(CONCAT(' - ', NULLIF(so.customer_name, '')), ''))
                WHEN p.booking_type = 'gym' THEN CONCAT(gi.name, ' (', gi.reference_number, ')')
                WHEN p.booking_type = 'event' THEN CONCAT(ei.name, ' (', ei.reference_number, ')')
                ELSE 'Unknown'
            END as booking_description
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id
                WHERE p.deleted_at IS NULL
                    AND p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date DESC, p.created_at DESC
        LIMIT 20
    ");
    $recentStmt->execute([$startDate, $endDate]);
    $recentPayments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Outstanding payments summary (filtered by enabled modules)
    $outstandingParts = [];
    if ($mod_bookings) {
        $outstandingParts[] = "SELECT 'room' as type, COUNT(*) as count, SUM(amount_due) as total_outstanding FROM bookings WHERE amount_due > 0 AND status IN ('pending', 'confirmed', 'checked-in')";
    }
    if ($mod_conference) {
        $outstandingParts[] = "SELECT 'conference' as type, COUNT(*) as count, SUM(amount_due) as total_outstanding FROM conference_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled', 'rejected', 'expired')";
    }
    if ($mod_gym) {
        $outstandingParts[] = "SELECT 'gym' as type, COUNT(*) as count, SUM(amount_due) as total_outstanding FROM gym_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')";
    }
    if ($mod_events) {
        $outstandingParts[] = "SELECT 'event' as type, COUNT(*) as count, SUM(amount_due) as total_outstanding FROM event_inquiries WHERE amount_due > 0 AND status NOT IN ('cancelled')";
    }
    if (!empty($outstandingParts)) {
        $outstandingStmt = $pdo->query(implode(' UNION ALL ', $outstandingParts));
        $outstandingSummary = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ====================================================================
    // Comprehensive analytics: POS by order type, daily trend, COGS, totals
    // ====================================================================

    if ($mod_pos) {
        // POS revenue split by station / order_type
        $posByTypeStmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(order_type, ''), 'walk_in') AS order_type,
                COUNT(CASE WHEN status IN ('paid','completed') THEN 1 END) AS order_count,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS gross_revenue,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost ELSE 0 END), 0) AS cogs,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total_amount ELSE 0 END), 0) AS voided_amount,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END), 0) AS voided_count
            FROM stock_orders
            WHERE created_at BETWEEN ? AND ?
            GROUP BY order_type
            ORDER BY gross_revenue DESC
        ");
        $posByTypeStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $posByType = $posByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

        $posTotalsStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END), 0) AS gross_revenue,
                COALESCE(SUM(CASE WHEN status IN ('paid','completed') THEN total_cost ELSE 0 END), 0) AS cogs
            FROM stock_orders
            WHERE created_at BETWEEN ? AND ?
        ");
        $posTotalsStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $posTotals = $posTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['gross_revenue' => 0, 'cogs' => 0];
    }

    // Inventory shrinkage — real stock losses NOT captured in COGS: wastage,
    // negative stock-count variance, expired batches and recalls. Valued at the
    // weighted cost recorded on each adjustment. This is a direct hit to margin.
    $stock_shrinkage = ['wastage' => 0.0, 'variance' => 0.0, 'expiry' => 0.0, 'recall' => 0.0, 'total' => 0.0];
    try {
        $shrStmt = $pdo->prepare("
            SELECT source_type, COALESCE(SUM(ABS(quantity_change) * cost_at_time), 0) AS loss
            FROM stock_adjustments
            WHERE quantity_change < 0
              AND source_type IN ('wastage','variance','expiry','recall')
              AND created_at BETWEEN ? AND ?
            GROUP BY source_type
        ");
        $shrStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        foreach ($shrStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stock_shrinkage[$r['source_type']] = (float)$r['loss'];
        }
        $stock_shrinkage['total'] = $stock_shrinkage['wastage'] + $stock_shrinkage['variance']
            + $stock_shrinkage['expiry'] + $stock_shrinkage['recall'];
    } catch (Throwable $e) {
        // Older adjustments enums may lack 'variance' — non-fatal.
    }

    // Folio F&B revenue memo — food/drink/minibar/room-service charged to a room
    // booking is collected under booking_type='room', so it is BURIED inside Room
    // revenue in the source table above. This accrual-based breakout gives the
    // accounts team F&B-department visibility WITHOUT altering the payment-based
    // totals (so nothing is double-counted).
    $folio_fnb = ['food' => 0.0, 'drink' => 0.0, 'other' => 0.0, 'total' => 0.0];
    try {
        $ffStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN charge_type = 'food' THEN line_total ELSE 0 END), 0) AS food,
                COALESCE(SUM(CASE WHEN charge_type = 'drink' THEN line_total ELSE 0 END), 0) AS drink,
                COALESCE(SUM(CASE WHEN charge_type IN ('minibar','room_service','breakfast') THEN line_total ELSE 0 END), 0) AS other
            FROM booking_charges
            WHERE voided = 0
              AND charge_type IN ('food','drink','minibar','room_service','breakfast')
              AND posted_at BETWEEN ? AND ?
        ");
        $ffStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        if ($ff = $ffStmt->fetch(PDO::FETCH_ASSOC)) {
            $folio_fnb['food']  = (float)$ff['food'];
            $folio_fnb['drink'] = (float)$ff['drink'];
            $folio_fnb['other'] = (float)$ff['other'];
            $folio_fnb['total'] = $folio_fnb['food'] + $folio_fnb['drink'] + $folio_fnb['other'];
        }
    } catch (Throwable $e) {
        // non-fatal
    }

    // Daily revenue trend (last 14 days within the selected range, capped to range)
    $trendStartCandidate = max(strtotime($startDate), strtotime('-13 days', strtotime($endDate)));
    $trendStart = date('Y-m-d', $trendStartCandidate);
    $trendStmt = $pdo->prepare("
        SELECT
            DATE(payment_date) AS day,
            COALESCE(SUM(CASE WHEN booking_type = 'room' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS room_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'conference' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS conf_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'restaurant' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'gym' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS gym_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'event' AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS events_rev,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN refund_amount ELSE 0 END), 0) AS refunds,
            COUNT(*) AS txn_count
        FROM payments
        WHERE deleted_at IS NULL
          AND payment_date BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY day DESC
    ");
    $trendStmt->execute([$trendStart, $endDate]);
    $dailyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentColumns = finance_table_columns($pdo, 'payments');
    $mraColumnsAvailable = isset($paymentColumns['mra_status']);
    $mraPendingSql = $mraColumnsAvailable
        ? "SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' AND mra_status NOT IN ('accepted','not_required') THEN 1 ELSE 0 END)"
        : "0";
    $complianceStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS completed_sales,
            SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' AND (receipt_number IS NULL OR receipt_number = '') THEN 1 ELSE 0 END) AS missing_receipts,
            SUM(CASE WHEN invoice_generated = 1 AND (invoice_number IS NULL OR invoice_number = '') THEN 1 ELSE 0 END) AS generated_invoices_missing_numbers,
            {$mraPendingSql} AS mra_pending_or_unsubmitted
        FROM payments
        WHERE deleted_at IS NULL
          AND payment_date BETWEEN ? AND ?
    ");
    $complianceStmt->execute([$startDate, $endDate]);
    $complianceSummary = array_merge($complianceSummary, $complianceStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $posLedgerGapStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM stock_orders so
        LEFT JOIN payments p ON p.booking_type = 'restaurant'
            AND p.booking_id = so.id
            AND COALESCE(p.payment_type, '') != 'refund'
            AND p.deleted_at IS NULL
        WHERE so.status = 'paid'
          AND so.created_at BETWEEN ? AND ?
          AND p.id IS NULL
    ");
    $posLedgerGapStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    $complianceSummary['paid_pos_without_ledger'] = (int)$posLedgerGapStmt->fetchColumn();
} catch (Throwable $e) {
    $error = "Unable to load accounting data.";
}

// ── Quotation pipeline stats ──────────────────────────────────────────────────
$quotationStats = [
    'total'         => 0,
    'sent'          => 0,
    'accepted'      => 0,
    'expired'       => 0,
    'declined'      => 0,
    'total_value'   => 0.0,
    'sent_value'    => 0.0,
    'accepted_value' => 0.0,
];
try {
    $qtStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                        AS total,
            SUM(status = 'sent')                            AS sent,
            SUM(status = 'accepted')                        AS accepted,
            SUM(status = 'expired')                         AS expired,
            SUM(status = 'declined')                        AS declined,
            COALESCE(SUM(total_amount), 0)                  AS total_value,
            COALESCE(SUM(CASE WHEN status = 'sent'     THEN total_amount ELSE 0 END), 0) AS sent_value,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END), 0) AS accepted_value
        FROM quotations
        WHERE sent_at BETWEEN ? AND ?
    ");
    $qtStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    $qtRow = $qtStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($qtRow as $k => $v) {
        $quotationStats[$k] = isset($quotationStats[$k]) ? (is_float($quotationStats[$k]) ? (float)$v : (int)$v) : $v;
    }
} catch (Throwable $e) {
    // Non-fatal — quotations table may not exist yet
}

$quotationExpiredDeclinedCount = (int)$quotationStats['expired'] + (int)$quotationStats['declined'];
$quotationConversionRate = (int)$quotationStats['total'] > 0
    ? (int)round(((int)$quotationStats['accepted'] / (int)$quotationStats['total']) * 100)
    : 0;

// ── Credit Note stats ─────────────────────────────────────────────────────────
$cnStats = ['count_issued' => 0, 'total_issued' => 0.0, 'total_redeemed' => 0.0, 'total_outstanding' => 0.0];
try {
    if (function_exists('checkExpiredCreditNotes')) {
        checkExpiredCreditNotes($pdo);
    }
    $cnStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                                       AS count_issued,
            COALESCE(SUM(original_amount), 0)                                              AS total_issued,
            COALESCE(SUM(amount_used), 0)                                                  AS total_redeemed,
            COALESCE(SUM(CASE WHEN status IN ('active','partially_applied') THEN balance ELSE 0 END), 0) AS total_outstanding
        FROM credit_notes
        WHERE DATE(issued_at) BETWEEN ? AND ?
    ");
    $cnStmt->execute([$startDate, $endDate]);
    $cnRow = $cnStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($cnRow as $k => $v) {
        $cnStats[$k] = isset($cnStats[$k]) && is_float($cnStats[$k]) ? (float)$v : (is_int($cnStats[$k]) ? (int)$v : $v);
    }
} catch (Throwable $e) {
    // credit_notes table may not exist yet — non-fatal
}

// Safe defaults for new analytics blocks (in case the try { } above failed
// before the new queries were reached).
if (!isset($posByType)) {
    $posByType = [];
}
if (!isset($posTotals)) {
    $posTotals = ['gross_revenue' => 0, 'cogs' => 0];
}
if (!isset($dailyTrend)) {
    $dailyTrend = [];
}
if (!isset($stock_shrinkage)) {
    $stock_shrinkage = ['wastage' => 0.0, 'variance' => 0.0, 'expiry' => 0.0, 'recall' => 0.0, 'total' => 0.0];
}
if (!isset($folio_fnb)) {
    $folio_fnb = ['food' => 0.0, 'drink' => 0.0, 'other' => 0.0, 'total' => 0.0];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Dashboard | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content finance-page">
        <?php
        // -----------------------------------------------------------------
        // Pre-computed values used in the redesigned layout.
        // -----------------------------------------------------------------
        $cat_revenue_gross = (float)($financialSummary['total_collected'] ?? 0);
        $cat_revenue_net   = $cat_revenue_gross - (float)($financialSummary['total_refunds_issued'] ?? 0);
        $cat_revenue_room  = (float)($roomSummary['room_collected'] ?? 0);
        $cat_revenue_conf  = (float)($confSummary['conf_collected'] ?? 0);
        $cat_revenue_fnb   = (float)($restaurantSummary['restaurant_collected'] ?? 0);
        $cat_revenue_gym   = (float)($gymSummary['gym_collected'] ?? 0);
        $cat_revenue_events = (float)($eventsSummary['events_collected'] ?? 0);
        $cat_recv_total = 0;
        $cat_recv_count = 0;
        foreach ($outstandingSummary as $o) {
            $cat_recv_total += (float)$o['total_outstanding'];
            $cat_recv_count += (int)$o['count'];
        }
        $cat_pending          = (float)($financialSummary['total_pending'] ?? 0);
        $cat_vat              = (float)($financialSummary['total_vat_collected'] ?? 0);
        $cat_refunds          = (float)($financialSummary['total_refunds_issued'] ?? 0);
        $cat_pending_refunds  = (float)($financialSummary['pending_refunds'] ?? 0);

        $cat_cash_today = 0;
        try {
            // Refund rows also sit at payment_status='completed' — they must reduce, not inflate, the drawer.
            $cashStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE
                    WHEN COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid') THEN total_amount
                    WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -total_amount
                    ELSE 0 END),0)
                FROM payments
                WHERE payment_method IN ('cash','mobile_money') AND DATE(payment_date)=CURRENT_DATE() AND deleted_at IS NULL");
            $cashStmt->execute();
            $cat_cash_today = (float)$cashStmt->fetchColumn();
        } catch (Throwable $e) { /* ignore */
        }

        // Tourism levy accrued on bookings taken in the period (levy is charged on the
        // booking, not per payment, so it is accrual-based; excludes dead bookings).
        $cat_levy_period = 0.0;
        $levyEnabled = in_array(getSetting('tourism_levy_enabled'), ['1', 1, true, 'true', 'on'], true);
        if ($levyEnabled && $mod_bookings) {
            try {
                $levyStmt = $pdo->prepare("SELECT COALESCE(SUM(tourism_levy_amount),0) FROM bookings WHERE status NOT IN ('cancelled','expired','no-show') AND DATE(created_at) BETWEEN ? AND ?");
                $levyStmt->execute([$startDate, $endDate]);
                $cat_levy_period = (float)$levyStmt->fetchColumn();
            } catch (Throwable $e) { /* ignore */
            }
        }

        $cat_cash_period = 0.0;
        $cat_mobile_period = 0.0;
        foreach ($paymentMethods as $methodRow) {
            $methodKey = strtolower((string)($methodRow['payment_method'] ?? ''));
            $methodTotal = (float)($methodRow['total'] ?? 0);
            if ($methodKey === 'cash') {
                $cat_cash_period += $methodTotal;
            }
            if ($methodKey === 'mobile_money') {
                $cat_mobile_period += $methodTotal;
            }
        }

        $cat_voids_value = 0;
        $cat_voids_count = 0;
        if ($mod_pos) {
            try {
                $vStmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM stock_orders WHERE status='voided' AND voided_at BETWEEN ? AND ?");
                $vStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $vrow = $vStmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'v' => 0];
                $cat_voids_count = (int)$vrow['c'];
                $cat_voids_value = (float)$vrow['v'];
            } catch (Throwable $e) { /* ignore */
            }
        }

        // Source totals for the Revenue by Source table.
        $source_total_gross = $cat_revenue_room + $cat_revenue_conf + $cat_revenue_fnb + $cat_revenue_gym + $cat_revenue_events;
        $pos_gross   = (float)($posTotals['gross_revenue'] ?? 0);
        $pos_cogs    = (float)($posTotals['cogs'] ?? 0);
        $pos_margin  = $pos_gross - $pos_cogs;
        $pos_margin_pct = $pos_gross > 0 ? ($pos_margin / $pos_gross) * 100 : 0;

        $order_type_labels = [
            'walk_in'      => 'Walk-in / Dine-in',
            'room_service' => 'Room Service (folio)',
            'takeaway'     => 'Takeaway',
            'delivery'     => 'Delivery',
            'pos'          => 'POS Till',
        ];
        ?>

        <!-- Page header -->
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">Accounting Dashboard</h1>
                <?php
                    $_acct_categories = [];
                    if ($mod_bookings) { $_acct_categories[] = 'rooms'; }
                    if ($mod_conference) { $_acct_categories[] = 'conferences'; }
                    if ($mod_gym) { $_acct_categories[] = 'gym'; }
                    if ($mod_events) { $_acct_categories[] = 'events'; }
                    if ($mod_pos) { $_acct_categories[] = htmlspecialchars(rh_pos_category_label()); }
                    if ($mod_bookings && $mod_pos) { $_acct_categories[] = 'room service'; }
                ?>
                <p class="acct-page-header__subtitle">
                    Live financial overview across <?php echo implode(', ', $_acct_categories) ?: 'this installation'; ?> —
                    <strong>
                        <?php echo $showAll
                            ? 'All-time'
                            : 'period ' . htmlspecialchars(date('M j, Y', strtotime($startDate))) . ' &rarr; ' . htmlspecialchars(date('M j, Y', strtotime($endDate))); ?>
                    </strong>.
                </p>
            </div>

            <form method="GET" class="acct-filter-form">
                <label class="acct-filter-field">
                    <span>From</span>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($showAll ? '' : $startDate); ?>">
                </label>
                <label class="acct-filter-field">
                    <span>To</span>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($showAll ? '' : $endDate); ?>">
                </label>
                <button type="submit" class="acct-btn acct-btn--primary">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <a href="accounting-dashboard.php?show_all=1" class="acct-btn acct-btn--ghost">
                    <i class="fas fa-infinity"></i> All time
                </a>
                <a href="accounting-dashboard.php" class="acct-btn acct-btn--ghost">Reset</a>
            </form>
        </div>

        <!-- Quick action bar -->
        <div class="acct-quick-actions">
            <a href="payments.php" class="acct-quick-action" title="View all individual payment records across all booking types">
                <i class="fas fa-list"></i> All Payments
            </a>
            <a href="payment-add.php" class="acct-quick-action acct-quick-action--accent" title="Manually record a new payment against a booking or invoice">
                <i class="fas fa-plus"></i> Record Payment
            </a>
            <?php if ($acct_billing): ?>
            <a href="invoices.php" class="acct-quick-action" title="View, search, and download <?php echo $acct_party; ?> and client invoices">
                <i class="fas fa-file-invoice-dollar"></i> Invoices
            </a>
            <a href="quotations.php" class="acct-quick-action" title="View all quotations issued, track status, download PDFs">
                <i class="fas fa-file-contract"></i> Quotations
            </a>
            <?php endif; ?>
            <a href="reports.php" class="acct-quick-action" title="Detailed financial reports — P&amp;L, revenue by source, VAT register, occupancy, and more">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="#vat-settings" class="acct-quick-action" title="VAT (Value Added Tax) Settings — enable or disable VAT, set the tax rate, and configure your VAT registration number directly in accounting">
                <i class="fas fa-percent"></i> VAT Settings
            </a>
        </div>

        <section class="acct-panel acct-panel--vat" id="vat-settings">
            <header class="acct-panel__head acct-panel__head--vat">
                <div class="acct-panel__head-title-row">
                    <h2 class="acct-panel__title"><i class="fas fa-percent"></i> VAT Settings</h2>
                    <span class="vat-status-badge <?php echo $vatEnabled ? 'vat-status-badge--on' : 'vat-status-badge--off'; ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo $vatEnabled ? 'VAT Enabled' : 'VAT Disabled'; ?>
                    </span>
                </div>
                <p class="acct-panel__sub">Tax configuration affects all future invoices, payments, and MRA reporting. Changes cannot be undone automatically.</p>
            </header>

            <?php if ($vatSettingsMessage): ?>
                <div class="vat-result-banner vat-result-banner--success">
                    <i class="fas fa-check-circle"></i>
                    <div><strong>Saved.</strong> <?php echo htmlspecialchars($vatSettingsMessage); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($vatSettingsError): ?>
                <div class="vat-result-banner vat-result-banner--error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($vatSettingsError); ?></div>
                </div>
            <?php endif; ?>

            <!-- Read-only summary (default locked state) -->
            <div class="vat-locked-view" id="vatLockedView">
                <div class="vat-current-grid">
                    <div class="vat-current-item">
                        <span class="vat-current-item__label">Status</span>
                        <span class="vat-current-item__value <?php echo $vatEnabled ? 'vat-current-item__value--on' : 'vat-current-item__value--off'; ?>">
                            <?php echo $vatEnabled ? '<i class="fas fa-toggle-on"></i> Enabled' : '<i class="fas fa-toggle-off"></i> Disabled'; ?>
                        </span>
                    </div>
                    <div class="vat-current-item">
                        <span class="vat-current-item__label">Rate</span>
                        <span class="vat-current-item__value"><?php echo htmlspecialchars((string)$vatRate); ?>%</span>
                    </div>
                    <div class="vat-current-item">
                        <span class="vat-current-item__label">VAT Registration No.</span>
                        <span class="vat-current-item__value">
                            <?php echo $vatNumber ? htmlspecialchars((string)$vatNumber) : '<em style="color:var(--finance-muted)">Not set</em>'; ?>
                        </span>
                    </div>
                </div>
                <div class="vat-unlock-row">
                    <button type="button" class="acct-btn acct-btn--unlock" id="vatUnlockBtn">
                        <i class="fas fa-lock-open"></i> Unlock to Edit
                    </button>
                    <p class="vat-unlock-hint"><i class="fas fa-triangle-exclamation"></i> Editing VAT settings affects all future invoices, tax calculations, and MRA reports. Proceed with caution.</p>
                </div>
            </div>

            <!-- Edit form (hidden until unlocked) -->
            <div class="vat-edit-view" id="vatEditView" hidden>
                <div class="vat-warning-banner">
                    <i class="fas fa-triangle-exclamation vat-warning-banner__icon"></i>
                    <div class="vat-warning-banner__body">
                        <strong>Caution — tax-critical change</strong>
                        <ul>
                            <li>Changing the VAT rate affects all new payments going forward — existing invoices are not recalculated.</li>
                            <li>Disabling VAT will stop tax being applied to all new transactions immediately.</li>
                            <li>Your VAT registration number must match your MRA certificate exactly.</li>
                            <li>Consult your accountant before making changes mid-period.</li>
                        </ul>
                    </div>
                </div>

                <form method="POST" class="vat-edit-form" action="accounting-dashboard.php<?php echo $showAll ? '?show_all=1' : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="save_vat_settings" value="1">

                    <div class="vat-edit-fields">
                        <div class="vat-field-group">
                            <label class="vat-field-group__label" for="vat_enabled">VAT Status</label>
                            <select class="vat-field-group__control" id="vat_enabled" name="vat_enabled">
                                <option value="1" <?php echo $vatEnabled ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo !$vatEnabled ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="vat-field-group">
                            <label class="vat-field-group__label" for="vat_pricing_mode">Pricing Mode</label>
                            <select class="vat-field-group__control" id="vat_pricing_mode" name="vat_pricing_mode">
                                <option value="exclusive" <?php echo $vatPricingMode !== 'inclusive' ? 'selected' : ''; ?>>VAT added on top of prices</option>
                                <option value="inclusive" <?php echo $vatPricingMode === 'inclusive' ? 'selected' : ''; ?>>Prices already include VAT</option>
                            </select>
                            <small style="color:#7a6f63;font-size:.74rem;display:block;margin-top:4px;">
                                Inclusive: totals equal your listed prices and documents show only the VAT rate, never an amount.
                            </small>
                        </div>
                        <div class="vat-field-group">
                            <label class="vat-field-group__label" for="vat_rate">VAT Rate (%)</label>
                            <input class="vat-field-group__control" type="number" id="vat_rate" name="vat_rate"
                                min="0" max="100" step="0.01"
                                value="<?php echo htmlspecialchars((string)$vatRate); ?>" required>
                        </div>
                        <div class="vat-field-group vat-field-group--wide">
                            <label class="vat-field-group__label" for="vat_number">VAT Registration Number</label>
                            <input class="vat-field-group__control" type="text" id="vat_number" name="vat_number"
                                maxlength="120"
                                value="<?php echo htmlspecialchars((string)$vatNumber); ?>"
                                placeholder="Enter your MRA VAT registration number">
                        </div>
                    </div>

                    <div class="vat-edit-actions">
                        <button type="button" class="acct-btn acct-btn--ghost" id="vatCancelBtn">
                            <i class="fas fa-xmark"></i> Cancel
                        </button>
                        <button type="submit" class="acct-btn acct-btn--save-vat" id="vatSaveBtn">
                            <i class="fas fa-save"></i> Save VAT Settings
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- VAT unlock confirmation modal -->
        <div class="modal-overlay" id="vatConfirmModal-overlay" data-modal-overlay aria-hidden="true"></div>
        <div class="modal-overlay vat-confirm-modal" id="vatConfirmModal" role="dialog" aria-modal="true" aria-labelledby="vatConfirmTitle" data-modal data-close-on-escape="true" data-close-on-overlay="false">
            <div class="modal-container vat-confirm-modal__container">
                <div class="vat-confirm-modal__icon"><i class="fas fa-shield-halved"></i></div>
                <h3 class="vat-confirm-modal__title" id="vatConfirmTitle">Unlock VAT Settings?</h3>
                <p class="vat-confirm-modal__body">
                    VAT settings control how tax is calculated across all bookings, POS transactions, and invoices.
                    Incorrect values can cause compliance issues with the MRA.
                </p>
                <p class="vat-confirm-modal__body">
                    <strong>Are you sure you want to unlock and edit these settings?</strong>
                </p>
                <div class="vat-confirm-modal__actions">
                    <button type="button" class="acct-btn acct-btn--ghost" id="vatConfirmCancel">
                        <i class="fas fa-xmark"></i> No, keep locked
                    </button>
                    <button type="button" class="acct-btn acct-btn--unlock-confirm" id="vatConfirmYes">
                        <i class="fas fa-lock-open"></i> Yes, unlock to edit
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="acct-error">
                <i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- KPI strip — 4 headline numbers only (no card overload) -->
        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue acct-kpi--interactive js-acct-insight-trigger" title="Net Revenue — total money collected after deducting refunds. This is what the business actually kept." role="button" tabindex="0" data-insight-key="net-revenue" data-insight-title="Net Revenue Breakdown" aria-label="Open net revenue breakdown">
                <div class="acct-kpi__label">Net Revenue</div>
                <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format($cat_revenue_net, 2); ?></div>
                <div class="acct-kpi__meta">
                    <span>Gross <?php echo $currency_symbol . number_format($cat_revenue_gross, 2); ?></span>
                    <span>Refunds &minus;<?php echo $currency_symbol . number_format($cat_refunds, 2); ?></span>
                </div>
                <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
            <div class="acct-kpi acct-kpi--receivables acct-kpi--interactive js-acct-insight-trigger" title="Receivables — money that <?php echo $acct_party; ?>s/clients owe but have not yet paid. These are open invoices or partial balances that still need collection." role="button" tabindex="0" data-insight-key="receivables" data-insight-title="Receivables Follow-up" aria-label="Open receivables follow-up">
                <div class="acct-kpi__label">Receivables</div>
                <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format($cat_recv_total, 2); ?></div>
                <div class="acct-kpi__meta">
                    <span><?php echo (int)$cat_recv_count; ?> open invoices</span>
                    <span>Pending <?php echo $currency_symbol . number_format($cat_pending, 2); ?></span>
                </div>
                <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
            <div class="acct-kpi acct-kpi--cash acct-kpi--interactive js-acct-insight-trigger" title="Cash Position (Today) — the total of all cash and mobile money payments received today. Does not include card, bank transfer, or credit." role="button" tabindex="0" data-insight-key="cash-today" data-insight-title="Cash Position Detail" aria-label="Open cash position detail">
                <div class="acct-kpi__label">Cash Position (Today)</div>
                <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format($cat_cash_today, 2); ?></div>
                <div class="acct-kpi__meta">
                    <span>Cash + Mobile Money</span>
                    <a href="payments.php?date=<?php echo $today; ?>">Today's payments &rarr;</a>
                </div>
                <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
            <div class="acct-kpi acct-kpi--vat acct-kpi--interactive js-acct-insight-trigger" title="VAT Collected — Value Added Tax (VAT) is the tax portion collected on top of the sale price. This amount must be reported and paid to the tax authority (MRA). It is not the business&#39;s income." role="button" tabindex="0" data-insight-key="vat-collected" data-insight-title="VAT Compliance Snapshot" aria-label="Open VAT compliance snapshot">
                <div class="acct-kpi__label">VAT Collected</div>
                <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format($cat_vat, 2); ?></div>
                <div class="acct-kpi__meta">
                    <span><?php echo $vatEnabled ? 'Enabled @ ' . htmlspecialchars($vatRate) . '%' : 'Disabled'; ?></span>
                    <?php if ($vatEnabled && $vatNumber): ?><span>VAT&nbsp;# <?php echo htmlspecialchars($vatNumber); ?></span><?php endif; ?>
                    <?php if (!empty($levyEnabled)): ?><span title="Tourism levy accrued on bookings taken in this period — remit to the Malawi Tourism Council">Tourism levy <?php echo $currency_symbol . number_format($cat_levy_period, 2); ?></span><?php endif; ?>
                </div>
                <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
            </div>
        </div>

        <?php if ($acct_billing): // quotations register belongs to billing businesses (rooms/conference/gym/events) — hidden for till-only presets, matching the nav/page gate ?>
        <!-- Quotation Pipeline panel -->
        <section class="acct-panel" id="quotation-pipeline">
            <header class="acct-panel__head">
                <h2 class="acct-panel__title"><i class="fas fa-file-contract" style="color:#B18247;"></i> Quotation Pipeline</h2>
                <p class="acct-panel__sub">Quotations issued in the selected period — track conversion from quoted to accepted bookings.</p>
                <a href="quotations.php" style="margin-top:6px;display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#8A775F;text-decoration:none;font-weight:500;">
                    View all quotations <i class="fas fa-arrow-right"></i>
                </a>
            </header>
            <div style="display:flex;flex-wrap:wrap;gap:14px;padding:0 0 20px;">
                <div class="acct-kpi acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:120px;" title="Total quotations issued in this period" role="button" tabindex="0" data-insight-key="quotation-total" data-insight-title="Quotation Volume Overview" aria-label="Open quotation volume overview">
                    <div class="acct-kpi__label">Total Issued</div>
                    <div class="acct-kpi__value" style="font-size:1.4rem;"><?php echo (int)$quotationStats['total']; ?></div>
                    <div class="acct-kpi__meta"><span><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['total_value'], 2); ?> quoted</span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
                <div class="acct-kpi acct-kpi--receivables acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:120px;" title="Quotations sent and awaiting response" role="button" tabindex="0" data-insight-key="quotation-sent" data-insight-title="Open Quotations Follow-up" aria-label="Open sent quotations follow-up">
                    <div class="acct-kpi__label">Active / Sent</div>
                    <div class="acct-kpi__value" style="font-size:1.4rem;color:#2F4F78;"><?php echo (int)$quotationStats['sent']; ?></div>
                    <div class="acct-kpi__meta"><span><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['sent_value'], 2); ?> outstanding</span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
                <div class="acct-kpi acct-kpi--cash acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:120px;" title="Quotations accepted by the <?php echo $acct_party; ?> — indicates conversion" role="button" tabindex="0" data-insight-key="quotation-accepted" data-insight-title="Accepted Quotations Performance" aria-label="Open accepted quotations performance">
                    <div class="acct-kpi__label">Accepted</div>
                    <div class="acct-kpi__value" style="font-size:1.4rem;color:#155724;"><?php echo (int)$quotationStats['accepted']; ?></div>
                    <div class="acct-kpi__meta"><span><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['accepted_value'], 2); ?></span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
                <div class="acct-kpi acct-kpi--vat acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:120px;" title="Quotations that passed their validity date without a response" role="button" tabindex="0" data-insight-key="quotation-expired-declined" data-insight-title="Expired &amp; Declined Quotations" aria-label="Open expired and declined quotations detail">
                    <div class="acct-kpi__label">Expired / Declined</div>
                    <div class="acct-kpi__value" style="font-size:1.4rem;color:#888;"><?php echo $quotationExpiredDeclinedCount; ?></div>
                    <div class="acct-kpi__meta">
                        <?php if ((int)$quotationStats['total'] > 0): ?>
                            <span>Conversion <?php echo $quotationConversionRate; ?>%</span>
                        <?php else: ?><span>No data</span><?php endif; ?>
                    </div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
            </div>
        </section>
        <?php endif; // quotation pipeline (billing businesses) ?>

        <?php if ($acct_ar): // credit notes are an accounts-receivable tool (rooms/conference) — till businesses refund at the POS instead ?>
        <!-- Credit Note Summary panel -->
        <section class="acct-panel" id="credit-note-summary">
            <header class="acct-panel__head">
                <h2 class="acct-panel__title"><i class="fas fa-file-invoice" style="color:#8A775F;"></i> Credit Notes</h2>
                <p class="acct-panel__sub">Credit notes issued in the selected period — track outstanding liability and redemption rate.</p>
                <a href="credit-notes.php" style="margin-top:6px;display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#8A775F;text-decoration:none;font-weight:500;">
                    Manage credit notes <i class="fas fa-arrow-right"></i>
                </a>
            </header>
            <div style="display:flex;flex-wrap:wrap;gap:14px;padding:0 0 20px;">
                <div class="acct-kpi acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:140px;" title="Total number and face value of credit notes issued in this period" role="button" tabindex="0" data-insight-key="cn-issued" data-insight-title="Credit Notes Issued" aria-label="Open credit notes issued details">
                    <div class="acct-kpi__label">CN Issued</div>
                    <div class="acct-kpi__value" style="font-size:1.4rem;"><?php echo (int)$cnStats['count_issued']; ?></div>
                    <div class="acct-kpi__meta"><span><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format((float)$cnStats['total_issued'], 2); ?> face value</span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
                <div class="acct-kpi acct-kpi--cash acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:140px;" title="Total value of credit notes redeemed against bookings" role="button" tabindex="0" data-insight-key="cn-redeemed" data-insight-title="Credit Notes Redeemed" aria-label="Open credit notes redeemed details">
                    <div class="acct-kpi__label">CN Redeemed</div>
                    <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format((float)$cnStats['total_redeemed'], 2); ?></div>
                    <div class="acct-kpi__meta"><span>Applied to bookings</span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
                <div class="acct-kpi acct-kpi--receivables acct-kpi--interactive js-acct-insight-trigger" style="flex:1;min-width:140px;" title="Outstanding credit note liability — the value <?php echo $acct_party; ?>s can still redeem" role="button" tabindex="0" data-insight-key="cn-outstanding" data-insight-title="Credit Notes Outstanding Liability" aria-label="Open credit notes outstanding liability details">
                    <div class="acct-kpi__label">CN Outstanding</div>
                    <div class="acct-kpi__value"><?php echo '<span class="acct-kpi__currency">' . $currency_symbol . '</span>' . number_format((float)$cnStats['total_outstanding'], 2); ?></div>
                    <div class="acct-kpi__meta"><span>Unredeemed liability</span></div>
                    <div class="acct-kpi__hint"><i class="fas fa-table-list"></i> Open detail</div>
                </div>
            </div>
        </section>
        <?php endif; // credit note summary (accounts-receivable businesses) ?>

        <section class="acct-panel">
            <header class="acct-panel__head">
                <h2 class="acct-panel__title"><i class="fas fa-shield-halved"></i> Accounting Compliance Checks</h2>
                <p class="acct-panel__sub">Flags receipt, invoice, POS ledger, and MRA-readiness gaps for the selected period.</p>
            </header>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Check</th>
                            <th class="num">Count</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $periodPaymentsLink = 'payments.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
                        $complianceRows = [
                            [
                                'key' => 'compliance-completed-sales',
                                'insight_title' => 'Completed Sales Coverage',
                                'label' => 'Completed sales',
                                'count' => (int)($complianceSummary['completed_sales'] ?? 0),
                                'warn' => false,
                                'action_link' => $periodPaymentsLink . '&payment_status=completed',
                                'action_label' => 'Open payments ledger',
                            ],
                            [
                                'key' => 'compliance-missing-receipts',
                                'insight_title' => 'Receipt Number Compliance',
                                'label' => 'Completed sales missing receipt number',
                                'count' => (int)($complianceSummary['missing_receipts'] ?? 0),
                                'warn' => true,
                                'action_link' => $periodPaymentsLink,
                                'action_label' => 'Review affected payments',
                            ],
                            [
                                'key' => 'compliance-missing-invoice-numbers',
                                'insight_title' => 'Invoice Number Integrity',
                                'label' => 'Generated invoices missing invoice number',
                                'count' => (int)($complianceSummary['generated_invoices_missing_numbers'] ?? 0),
                                'warn' => true,
                                'action_link' => $acct_billing ? 'invoices.php' : 'payments.php',
                                'action_label' => $acct_billing ? 'Open invoices workspace' : 'Open payments ledger',
                            ],
                        ];
                        // POS-ledger reconciliation only applies when a till exists
                        if ($mod_pos) {
                            $complianceRows[] = [
                                'key' => 'compliance-pos-ledger-gap',
                                'insight_title' => 'POS to Payments Ledger Gap',
                                'label' => 'Paid POS orders missing payments ledger row',
                                'count' => (int)($complianceSummary['paid_pos_without_ledger'] ?? 0),
                                'warn' => true,
                                'action_link' => $mod_stock ? 'stock-orders.php' : 'pos.php',
                                'action_label' => 'Open POS orders',
                            ];
                        }
                        $complianceRows = array_merge($complianceRows, [
                            [
                                'key' => 'compliance-mra-pending',
                                'insight_title' => $mraColumnsAvailable ? 'MRA Submission Readiness' : 'MRA Fields Installation Check',
                                'label' => $mraColumnsAvailable ? 'MRA pending/unsubmitted sales' : 'MRA readiness fields not installed',
                                'count' => $mraColumnsAvailable ? (int)($complianceSummary['mra_pending_or_unsubmitted'] ?? 0) : 1,
                                'warn' => true,
                                'action_link' => $mraColumnsAvailable ? ('reports.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate)) : 'booking-settings.php#invoice-settings',
                                'action_label' => $mraColumnsAvailable ? 'Open MRA-focused reports' : 'Open settings & install fields',
                            ],
                        ]);
                        foreach ($complianceRows as $row):
                            $hasGap = $row['warn'] && (int)$row['count'] > 0;
                        ?>
                            <tr>
                                <td>
                                    <button
                                        type="button"
                                        class="acct-link acct-link--button js-acct-insight-trigger"
                                        data-insight-key="<?php echo htmlspecialchars($row['key']); ?>"
                                        data-insight-title="<?php echo htmlspecialchars($row['insight_title']); ?>">
                                        <?php echo htmlspecialchars($row['label']); ?>
                                    </button>
                                </td>
                                <td class="num"><strong><?php echo number_format((int)$row['count']); ?></strong></td>
                                <td>
                                    <?php if ($hasGap): ?>
                                        <span class="acct-pill acct-pill--danger">Review required</span>
                                    <?php else: ?>
                                        <span class="acct-pill acct-pill--paid">Clear</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($row['action_link']); ?>" class="acct-link">
                                        <?php echo htmlspecialchars($row['action_label']); ?> &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="modal-overlay" id="acctInsightModal-overlay" data-modal-overlay aria-hidden="true"></div>
        <div
            class="modal-overlay modal-lg acct-insight-modal"
            id="acctInsightModal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="acctInsightTitle"
            data-modal
            data-close-on-escape="true"
            data-close-on-overlay="true">
            <div class="modal-container acct-insight-modal__container">
                <div class="modal-header acct-insight-modal__header">
                    <h3 class="modal-title" id="acctInsightTitle">Accounting Insight</h3>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close insight modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body acct-insight-modal__body" id="acctInsightBody"></div>
                <div class="modal-footer acct-insight-modal__footer">
                    <button type="button" class="acct-btn acct-btn--ghost" data-modal-close>Close</button>
                </div>
            </div>
        </div>

        <template id="acct-insight-template-net-revenue">
            <p class="acct-insight-intro">Net revenue is gross collection minus refunds. It shows the money the business actually retained.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Gross collected</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format($cat_revenue_gross, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Refunds issued</td>
                        <td class="num">&minus;<?php echo $currency_symbol . number_format($cat_refunds, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Net retained revenue</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format($cat_revenue_net, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>VAT collected in period</td>
                        <td class="num"><?php echo $currency_symbol . number_format($cat_vat, 2); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="reports.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn acct-btn--primary">Open financial reports</a>
                <a href="payments.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn acct-btn--ghost">Open payment ledger</a>
            </div>
        </template>

        <template id="acct-insight-template-receivables">
            <p class="acct-insight-intro">Receivables are outstanding balances still owed by <?php echo $acct_party; ?>s or clients and should guide collection priorities.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Follow-up Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total receivables</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format($cat_recv_total, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Open invoices / balances</td>
                        <td class="num"><?php echo number_format((int)$cat_recv_count); ?></td>
                    </tr>
                    <tr>
                        <td>Pending (payments table)</td>
                        <td class="num"><?php echo $currency_symbol . number_format($cat_pending, 2); ?></td>
                    </tr>
                    <?php if ($mod_bookings): ?>
                    <tr>
                        <td>Room balances outstanding</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)($roomSummary['total_room_outstanding'] ?? 0), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($mod_conference): ?>
                    <tr>
                        <td>Conference balances outstanding</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)($confSummary['total_conf_outstanding'] ?? 0), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($mod_gym): ?>
                    <tr>
                        <td>Gym balances outstanding</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)($gymSummary['total_gym_outstanding'] ?? 0), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($mod_events): ?>
                    <tr>
                        <td>Event balances outstanding</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)($eventsSummary['total_events_outstanding'] ?? 0), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="acct-insight-note">Direction: start with oldest/highest balances, then update payment records so receivables age and risk are always visible.</div>
            <div class="acct-insight-actions">
                <?php if ($acct_billing): ?><a href="invoices.php" class="acct-btn acct-btn--primary">Open invoices</a><?php endif; ?>
                <a href="payments.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn <?php echo $acct_billing ? 'acct-btn--ghost' : 'acct-btn--primary'; ?>">Open payments</a>
            </div>
        </template>

        <template id="acct-insight-template-cash-today">
            <p class="acct-insight-intro">Cash position combines cash and mobile money entries and helps finance teams reconcile tills and settlement channels.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Cash Snapshot</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Cash + Mobile Money (today)</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format($cat_cash_today, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Cash in selected period</td>
                        <td class="num"><?php echo $currency_symbol . number_format($cat_cash_period, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Mobile money in selected period</td>
                        <td class="num"><?php echo $currency_symbol . number_format($cat_mobile_period, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Pending refunds (to settle)</td>
                        <td class="num"><?php echo $currency_symbol . number_format($cat_pending_refunds, 2); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="payments.php?date=<?php echo urlencode($today); ?>" class="acct-btn acct-btn--primary">Open today's payments</a>
                <?php if ($mod_pos): ?><a href="pos-accounting.php" class="acct-btn acct-btn--ghost">Open POS accounting</a><?php endif; ?>
            </div>
        </template>

        <template id="acct-insight-template-vat-collected">
            <p class="acct-insight-intro">VAT collected is tax held on behalf of MRA. Keep these figures aligned with configuration and submission status.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>VAT Control Point</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>VAT status</td>
                        <td class="num"><?php echo $vatEnabled ? 'Enabled' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <td>Configured VAT rate</td>
                        <td class="num"><?php echo htmlspecialchars((string)$vatRate); ?>%</td>
                    </tr>
                    <tr>
                        <td>VAT registration number</td>
                        <td class="num"><?php echo $vatNumber !== '' ? htmlspecialchars((string)$vatNumber) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <td>VAT collected in selected period</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format($cat_vat, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="#vat-settings" class="acct-btn acct-btn--primary">Open VAT settings</a>
                <a href="reports.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn acct-btn--ghost">Open reporting</a>
            </div>
        </template>

        <template id="acct-insight-template-quotation-total">
            <p class="acct-insight-intro">This is the full quotation volume created during the selected period and acts as the pipeline baseline.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Quotation Volume Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total quotations issued</td>
                        <td class="num"><strong><?php echo number_format((int)$quotationStats['total']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Total quoted value</td>
                        <td class="num"><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['total_value'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Average quote value</td>
                        <td class="num"><?php echo (int)$quotationStats['total'] > 0 ? $currency_symbol . ' ' . number_format((float)$quotationStats['total_value'] / (int)$quotationStats['total'], 2) : $currency_symbol . '0.00'; ?></td>
                    </tr>
                    <tr>
                        <td>Conversion rate</td>
                        <td class="num"><?php echo $quotationConversionRate; ?>%</td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="quotations.php" class="acct-btn acct-btn--primary">Open quotations workspace</a>
            </div>
        </template>

        <template id="acct-insight-template-quotation-sent">
            <p class="acct-insight-intro">Sent quotations are active opportunities awaiting client response and should drive follow-up cadence.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Open Pipeline Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Active / sent quotations</td>
                        <td class="num"><strong><?php echo number_format((int)$quotationStats['sent']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Outstanding open value</td>
                        <td class="num"><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['sent_value'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Share of total quotations</td>
                        <td class="num"><?php echo (int)$quotationStats['total'] > 0 ? number_format(((int)$quotationStats['sent'] / (int)$quotationStats['total']) * 100, 1) . '%' : '0.0%'; ?></td>
                    </tr>
                    <tr>
                        <td>Recommended next step</td>
                        <td class="num">Prioritize oldest sent quotes</td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="quotations.php?status=sent" class="acct-btn acct-btn--primary">Open sent quotations</a>
                <a href="quotations.php" class="acct-btn acct-btn--ghost">Open all quotations</a>
            </div>
        </template>

        <template id="acct-insight-template-quotation-accepted">
            <p class="acct-insight-intro">Accepted quotations indicate conversion into confirmed business and should be reconciled against fulfillment and billing.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Acceptance Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Accepted quotations</td>
                        <td class="num"><strong><?php echo number_format((int)$quotationStats['accepted']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Accepted quotation value</td>
                        <td class="num"><?php echo $currency_symbol . ' ' . number_format((float)$quotationStats['accepted_value'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Acceptance ratio</td>
                        <td class="num"><?php echo (int)$quotationStats['total'] > 0 ? number_format(((int)$quotationStats['accepted'] / (int)$quotationStats['total']) * 100, 1) . '%' : '0.0%'; ?></td>
                    </tr>
                    <tr>
                        <td>Average accepted value</td>
                        <td class="num"><?php echo (int)$quotationStats['accepted'] > 0 ? $currency_symbol . ' ' . number_format((float)$quotationStats['accepted_value'] / (int)$quotationStats['accepted'], 2) : $currency_symbol . '0.00'; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="quotations.php?status=accepted" class="acct-btn acct-btn--primary">Open accepted quotations</a>
                <a href="payments.php?booking_type=conference" class="acct-btn acct-btn--ghost">Open conference payments</a>
            </div>
        </template>

        <template id="acct-insight-template-quotation-expired-declined">
            <p class="acct-insight-intro">Expired and declined quotations highlight pipeline leakage and where offer quality or response timing may need improvement.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Leakage Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Expired quotations</td>
                        <td class="num"><?php echo number_format((int)$quotationStats['expired']); ?></td>
                    </tr>
                    <tr>
                        <td>Declined quotations</td>
                        <td class="num"><?php echo number_format((int)$quotationStats['declined']); ?></td>
                    </tr>
                    <tr>
                        <td>Total expired + declined</td>
                        <td class="num"><strong><?php echo number_format($quotationExpiredDeclinedCount); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Leakage share of total</td>
                        <td class="num"><?php echo (int)$quotationStats['total'] > 0 ? number_format(($quotationExpiredDeclinedCount / (int)$quotationStats['total']) * 100, 1) . '%' : '0.0%'; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="quotations.php?status=expired" class="acct-btn acct-btn--primary">Open expired quotations</a>
                <a href="quotations.php?status=declined" class="acct-btn acct-btn--ghost">Open declined quotations</a>
            </div>
        </template>

        <template id="acct-insight-template-cn-issued">
            <p class="acct-insight-intro">Issued credit notes represent total liability created for <?php echo $acct_party; ?>s in the selected period.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Credit Note Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Credit notes issued</td>
                        <td class="num"><strong><?php echo number_format((int)$cnStats['count_issued']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Total face value issued</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)$cnStats['total_issued'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Total redeemed so far</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)$cnStats['total_redeemed'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="credit-notes.php" class="acct-btn acct-btn--primary">Open credit notes</a>
            </div>
        </template>

        <template id="acct-insight-template-cn-redeemed">
            <p class="acct-insight-intro">Redeemed credit notes show how much previously issued liability has already been consumed against bookings.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Redemption Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total redeemed</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$cnStats['total_redeemed'], 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Total issued</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)$cnStats['total_issued'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Redemption rate</td>
                        <td class="num"><?php echo ((float)$cnStats['total_issued'] > 0) ? number_format(((float)$cnStats['total_redeemed'] / (float)$cnStats['total_issued']) * 100, 1) . '%' : '0.0%'; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="credit-notes.php" class="acct-btn acct-btn--primary">Open redemption records</a>
            </div>
        </template>

        <template id="acct-insight-template-cn-outstanding">
            <p class="acct-insight-intro">Outstanding credit notes are unredeemed liability and should be monitored to avoid balance surprises.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Outstanding Liability View</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Outstanding liability</td>
                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$cnStats['total_outstanding'], 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Issued value baseline</td>
                        <td class="num"><?php echo $currency_symbol . number_format((float)$cnStats['total_issued'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Unredeemed ratio</td>
                        <td class="num"><?php echo ((float)$cnStats['total_issued'] > 0) ? number_format(((float)$cnStats['total_outstanding'] / (float)$cnStats['total_issued']) * 100, 1) . '%' : '0.0%'; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="credit-notes.php" class="acct-btn acct-btn--primary">Open outstanding credit notes</a>
            </div>
        </template>

        <template id="acct-insight-template-compliance-completed-sales">
            <p class="acct-insight-intro">This is the baseline count of paid/completed sales in period and anchors every other compliance gap ratio.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Coverage Metric</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Completed sales (selected period)</td>
                        <td class="num"><strong><?php echo number_format((int)($complianceSummary['completed_sales'] ?? 0)); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Missing receipt numbers</td>
                        <td class="num"><?php echo number_format((int)($complianceSummary['missing_receipts'] ?? 0)); ?></td>
                    </tr>
                    <tr>
                        <td>Missing invoice numbers</td>
                        <td class="num"><?php echo number_format((int)($complianceSummary['generated_invoices_missing_numbers'] ?? 0)); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="<?php echo htmlspecialchars($periodPaymentsLink); ?>" class="acct-btn acct-btn--primary">Open payments ledger</a>
            </div>
        </template>

        <template id="acct-insight-template-compliance-missing-receipts">
            <p class="acct-insight-intro">Every completed sale should have a receipt number for audit trail and customer proof of payment.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Receipt Integrity</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Sales missing receipt number</td>
                        <td class="num"><strong><?php echo number_format((int)($complianceSummary['missing_receipts'] ?? 0)); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Completed sales baseline</td>
                        <td class="num"><?php echo number_format((int)($complianceSummary['completed_sales'] ?? 0)); ?></td>
                    </tr>
                    <tr>
                        <td>Recommended next step</td>
                        <td class="num">Backfill receipt references</td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="<?php echo htmlspecialchars($periodPaymentsLink); ?>" class="acct-btn acct-btn--primary">Review affected payments</a>
            </div>
        </template>

        <template id="acct-insight-template-compliance-missing-invoice-numbers">
            <p class="acct-insight-intro">Generated invoices without invoice numbers break traceability for accounting and statutory reporting.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>Invoice Integrity</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Generated invoices missing numbers</td>
                        <td class="num"><strong><?php echo number_format((int)($complianceSummary['generated_invoices_missing_numbers'] ?? 0)); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Impact</td>
                        <td class="num">Cannot fully reconcile invoice trail</td>
                    </tr>
                    <tr>
                        <td>Recommended next step</td>
                        <td class="num">Re-generate or patch invoice IDs</td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="<?php echo $acct_billing ? 'invoices.php' : 'payments.php'; ?>" class="acct-btn acct-btn--primary"><?php echo $acct_billing ? 'Open invoices workspace' : 'Open payments ledger'; ?></a>
            </div>
        </template>

        <template id="acct-insight-template-compliance-pos-ledger-gap">
            <p class="acct-insight-intro">Paid POS orders must have a corresponding payments ledger row to keep restaurant revenue and finance books aligned.</p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>POS Ledger Alignment</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Paid POS orders missing ledger row</td>
                        <td class="num"><strong><?php echo number_format((int)($complianceSummary['paid_pos_without_ledger'] ?? 0)); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Risk</td>
                        <td class="num">Revenue may be understated in finance reports</td>
                    </tr>
                    <tr>
                        <td>Recommended next step</td>
                        <td class="num">Re-sync missing POS payments</td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <a href="<?php echo $mod_stock ? 'stock-orders.php' : 'pos.php'; ?>" class="acct-btn acct-btn--primary">Open POS orders</a>
                <a href="payments.php?booking_type=restaurant&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn acct-btn--ghost">Open <?php echo isRestaurantEnabled() ? 'restaurant' : 'POS'; ?> payments</a>
            </div>
        </template>

        <template id="acct-insight-template-compliance-mra-pending">
            <p class="acct-insight-intro">
                <?php if ($mraColumnsAvailable): ?>
                    MRA-ready fields are installed. This check tracks completed sales that still need MRA submission work.
                <?php else: ?>
                    MRA submission fields are not installed yet, so statutory submission tracking cannot run in this dashboard.
                <?php endif; ?>
            </p>
            <table class="acct-insight-table">
                <thead>
                    <tr>
                        <th>MRA Readiness Check</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo $mraColumnsAvailable ? 'Pending or unsubmitted sales' : 'Readiness field status'; ?></td>
                        <td class="num"><strong><?php echo number_format($mraColumnsAvailable ? (int)($complianceSummary['mra_pending_or_unsubmitted'] ?? 0) : 1); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Columns available</td>
                        <td class="num"><?php echo $mraColumnsAvailable ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <td>Recommended next step</td>
                        <td class="num"><?php echo $mraColumnsAvailable ? 'Process pending MRA submissions' : 'Install MRA tracking fields'; ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="acct-insight-actions">
                <?php if ($mraColumnsAvailable): ?>
                    <a href="reports.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="acct-btn acct-btn--primary">Open MRA reporting</a>
                <?php else: ?>
                    <a href="booking-settings.php#invoice-settings" class="acct-btn acct-btn--primary">Open invoice settings</a>
                <?php endif; ?>
            </div>
        </template>

        <!-- Revenue by Source — comprehensive table tying together rooms, conference, POS -->
        <section class="acct-panel">
            <header class="acct-panel__head">
                <h2 class="acct-panel__title"><i class="fas fa-coins"></i> Revenue by Source</h2>
                <p class="acct-panel__sub">Each line is wired to the originating system (bookings, conference inquiries, POS / stock orders).</p>
            </header>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th class="num" title="Number of individual payment transactions for this source">Transactions</th>
                            <th class="num" title="Gross Revenue — total amount received before deducting refunds or VAT">Gross</th>
                            <th class="num" title="VAT (Value Added Tax) — the tax portion collected within this revenue. Not the hotel\'s income — must be remitted to MRA.">VAT</th>
                            <th class="num" title="Share of total gross revenue from all sources combined">% of Gross</th>
                            <th>Drill-down</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = [];
                        if ($mod_bookings) {
                            $rows[] = [
                                'label'    => 'Rooms (bookings)',
                                'icon'     => 'fa-bed',
                                'count'    => (int)($roomSummary['total_bookings_with_payments'] ?? 0),
                                'gross'    => $cat_revenue_room,
                                'vat'      => (float)($roomSummary['room_vat_collected'] ?? 0),
                                'link'     => 'payments.php?booking_type=room',
                                'link_lbl' => 'Room payments',
                            ];
                        }
                        if ($mod_conference) {
                            $rows[] = [
                                'label'    => 'Conferences &amp; events',
                                'icon'     => 'fa-briefcase',
                                'count'    => (int)($confSummary['total_conferences_with_payments'] ?? 0),
                                'gross'    => $cat_revenue_conf,
                                'vat'      => (float)($confSummary['conf_vat_collected'] ?? 0),
                                'link'     => 'payments.php?booking_type=conference',
                                'link_lbl' => 'Conference payments',
                            ];
                        }
                        if ($mod_pos) {
                            $rows[] = [
                                'label'    => rh_pos_category_label(),
                                'icon'     => isRestaurantEnabled() ? 'fa-utensils' : 'fa-cash-register',
                                'count'    => (int)($restaurantSummary['total_restaurant_orders_with_payments'] ?? 0),
                                'gross'    => $cat_revenue_fnb,
                                'vat'      => (float)($restaurantSummary['restaurant_vat_collected'] ?? 0),
                                'link'     => $mod_stock ? 'stock-orders.php' : 'pos.php',
                                'link_lbl' => 'POS orders',
                            ];
                        }
                        if ($mod_gym) {
                            $rows[] = [
                                'label'    => 'Gym memberships',
                                'icon'     => 'fa-dumbbell',
                                'count'    => (int)($gymSummary['total_gym_with_payments'] ?? 0),
                                'gross'    => $cat_revenue_gym,
                                'vat'      => (float)($gymSummary['gym_vat_collected'] ?? 0),
                                'link'     => 'payments.php?booking_type=gym',
                                'link_lbl' => 'Gym payments',
                            ];
                        }
                        if ($mod_events) {
                            $rows[] = [
                                'label'    => 'Event bookings',
                                'icon'     => 'fa-calendar-check',
                                'count'    => (int)($eventsSummary['total_events_with_payments'] ?? 0),
                                'gross'    => $cat_revenue_events,
                                'vat'      => (float)($eventsSummary['events_vat_collected'] ?? 0),
                                'link'     => 'payments.php?booking_type=event',
                                'link_lbl' => 'Event payments',
                            ];
                        }
                        foreach ($rows as $r):
                            $pct = $source_total_gross > 0 ? ($r['gross'] / $source_total_gross) * 100 : 0;
                        ?>
                            <tr>
                                <td><span class="acct-row-label"><i class="fas <?php echo $r['icon']; ?>"></i> <?php echo $r['label']; ?></span></td>
                                <td class="num"><?php echo number_format($r['count']); ?></td>
                                <td class="num"><strong><?php echo $currency_symbol . number_format($r['gross'], 2); ?></strong></td>
                                <td class="num"><?php echo $currency_symbol . number_format($r['vat'], 2); ?></td>
                                <td class="num">
                                    <span class="acct-bar"><span class="acct-bar__fill" style="width: <?php echo number_format($pct, 1); ?>%"></span></span>
                                    <small><?php echo number_format($pct, 1); ?>%</small>
                                </td>
                                <td><a href="<?php echo htmlspecialchars($r['link']); ?>" class="acct-link"><?php echo $r['link_lbl']; ?> &rarr;</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="num"><?php echo number_format(array_sum(array_column($rows, 'count'))); ?></th>
                            <th class="num"><?php echo $currency_symbol . number_format($source_total_gross, 2); ?></th>
                            <th class="num"><?php echo $currency_symbol . number_format($cat_vat, 2); ?></th>
                            <th class="num">100%</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- POS Performance — gross margin from stock_orders.total_cost -->
        <?php if ($mod_pos && (!empty($posByType) || $pos_gross > 0)): ?>
            <section class="acct-panel">
                <header class="acct-panel__head">
                    <h2 class="acct-panel__title"><i class="fas fa-cash-register"></i> POS Performance &amp; Gross Margin</h2>
                    <p class="acct-panel__sub">
                        Pulled from <code>stock_orders</code>. COGS uses recorded recipe cost at order time.
                        Total margin: <strong><?php echo $currency_symbol . number_format($pos_margin, 2); ?></strong>
                        (<?php echo number_format($pos_margin_pct, 1); ?>%) on <?php echo $currency_symbol . number_format($pos_gross, 2); ?> gross.
                        <?php if ($stock_shrinkage['total'] > 0): ?>
                            &nbsp;·&nbsp; Stock losses (shrinkage): <strong style="color:#a25048;"><?php echo $currency_symbol . number_format($stock_shrinkage['total'], 2); ?></strong>
                            → net F&amp;B contribution <strong><?php echo $currency_symbol . number_format($pos_margin - $stock_shrinkage['total'], 2); ?></strong>.
                        <?php endif; ?>
                    </p>
                </header>
                <?php if ($stock_shrinkage['total'] > 0): ?>
                    <div class="acct-panel__sub" style="padding:0 16px 8px;font-size:.85rem;color:#8a8172;">
                        Shrinkage breakdown —
                        Wastage: <?php echo $currency_symbol . number_format($stock_shrinkage['wastage'], 2); ?> ·
                        Count variance: <?php echo $currency_symbol . number_format($stock_shrinkage['variance'], 2); ?> ·
                        Expiry: <?php echo $currency_symbol . number_format($stock_shrinkage['expiry'], 2); ?> ·
                        Recall: <?php echo $currency_symbol . number_format($stock_shrinkage['recall'], 2); ?>
                    </div>
                <?php endif; ?>
                <?php if ($folio_fnb['total'] > 0): ?>
                    <div class="acct-panel__sub" style="padding:0 16px 12px;font-size:.85rem;color:#8a8172;">
                        <i class="fas fa-circle-info"></i> <strong>Folio F&amp;B (room service / minibar):</strong>
                        <?php echo $currency_symbol . number_format($folio_fnb['total'], 2); ?> accrued
                        (food <?php echo $currency_symbol . number_format($folio_fnb['food'], 2); ?>,
                        drink <?php echo $currency_symbol . number_format($folio_fnb['drink'], 2); ?>,
                        other <?php echo $currency_symbol . number_format($folio_fnb['other'], 2); ?>).
                        Charged to room bookings, so it is included within <em>Room</em> revenue above — shown here for F&amp;B-department visibility, not added on top.
                    </div>
                <?php endif; ?>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Order Type</th>
                                <th class="num" title="Number of paid or completed orders">Orders</th>
                                <th class="num" title="Gross Revenue — total value of completed sales before any costs">Gross</th>
                                <th class="num" title="COGS (Cost of Goods Sold) — the actual food and drink ingredient cost for items sold, recorded at the time of the order">COGS</th>
                                <th class="num" title="Gross Profit — Revenue minus COGS. This is what remains after covering the cost of making the food or drinks.">Margin</th>
                                <th class="num" title="Gross Profit Margin — Profit as a percentage of revenue. E.g. 65% means for every 100 in revenue, 65 is profit before overheads.">Margin %</th>
                                <th class="num" title="Voided orders — orders that were cancelled or reversed after being placed. Shows count and total value.">Voids</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($posByType)): ?>
                                <tr>
                                    <td colspan="7" class="acct-empty">No POS activity in this period.</td>
                                </tr>
                                <?php else: foreach ($posByType as $ot):
                                    $g = (float)$ot['gross_revenue'];
                                    $c = (float)$ot['cogs'];
                                    $m = $g - $c;
                                    $mpct = $g > 0 ? ($m / $g) * 100 : 0;
                                    $label = $order_type_labels[$ot['order_type']] ?? ucwords(str_replace('_', ' ', (string)$ot['order_type']));
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($label); ?></td>
                                        <td class="num"><?php echo number_format((int)$ot['order_count']); ?></td>
                                        <td class="num"><?php echo $currency_symbol . number_format($g, 2); ?></td>
                                        <td class="num"><?php echo $currency_symbol . number_format($c, 2); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format($m, 2); ?></strong></td>
                                        <td class="num"><?php echo number_format($mpct, 1); ?>%</td>
                                        <td class="num">
                                            <?php if ((int)$ot['voided_count'] > 0): ?>
                                                <span class="acct-pill acct-pill--danger"><?php echo (int)$ot['voided_count']; ?> · <?php echo $currency_symbol . number_format((float)$ot['voided_amount'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="acct-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Two-up: Payment methods + Outstanding receivables -->
        <div class="acct-grid acct-grid--2">
            <section class="acct-panel">
                <header class="acct-panel__head">
                    <h2 class="acct-panel__title"><i class="fas fa-credit-card"></i> Payment Methods</h2>
                    <p class="acct-panel__sub">Where the money came in during this period.</p>
                </header>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="num" title="Number of payments received via this method">Count</th>
                                <th class="num" title="Total amount collected via this payment method in the period">Total</th>
                                <th class="num" title="Payment mix — what percentage of all revenue came in through this method">% Mix</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pmTotal = 0;
                            foreach ($paymentMethods as $pm) {
                                $pmTotal += (float)$pm['total'];
                            }
                            if (empty($paymentMethods)):
                            ?>
                                <tr>
                                    <td colspan="4" class="acct-empty">No payments in this period.</td>
                                </tr>
                                <?php else: foreach ($paymentMethods as $method):
                                    $mPct = $pmTotal > 0 ? ((float)$method['total'] / $pmTotal) * 100 : 0;
                                    $icon = 'fa-money-bill';
                                    switch ($method['payment_method']) {
                                        case 'cash':
                                            $icon = 'fa-money-bill-wave';
                                            break;
                                        case 'bank_transfer':
                                            $icon = 'fa-building-columns';
                                            break;
                                        case 'credit_card':
                                        case 'debit_card':
                                            $icon = 'fa-credit-card';
                                            break;
                                        case 'mobile_money':
                                            $icon = 'fa-mobile-screen';
                                            break;
                                        case 'cheque':
                                            $icon = 'fa-file-invoice-dollar';
                                            break;
                                    }
                                ?>
                                    <tr>
                                        <td><i class="fas <?php echo $icon; ?>"></i> <?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?></td>
                                        <td class="num"><?php echo (int)$method['count']; ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$method['total'], 2); ?></strong></td>
                                        <td class="num">
                                            <span class="acct-bar"><span class="acct-bar__fill" style="width: <?php echo number_format($mPct, 1); ?>%"></span></span>
                                            <small><?php echo number_format($mPct, 1); ?>%</small>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="acct-panel">
                <header class="acct-panel__head">
                    <h2 class="acct-panel__title"><i class="fas fa-triangle-exclamation"></i> Outstanding Receivables</h2>
                    <p class="acct-panel__sub">Booking balances still owing — chase these first.</p>
                </header>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th class="num">Open</th>
                                <th class="num">Amount Due</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasOutstanding = false;
                            foreach ($outstandingSummary as $os):
                                if ((float)$os['total_outstanding'] <= 0) continue;
                                $hasOutstanding = true;
                                $linkBase = $os['type'] === 'room' ? 'bookings.php?payment_status=unpaid' : 'conference-management.php?payment_status=pending';
                            ?>
                                <tr>
                                    <td><strong><?php echo ucfirst($os['type']); ?> bookings</strong></td>
                                    <td class="num"><?php echo (int)$os['count']; ?></td>
                                    <td class="num"><strong><?php echo $currency_symbol . number_format((float)$os['total_outstanding'], 2); ?></strong></td>
                                    <td><a href="<?php echo htmlspecialchars($linkBase); ?>" class="acct-link">View &rarr;</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$hasOutstanding): ?>
                                <tr>
                                    <td colspan="4" class="acct-empty acct-empty--good"><i class="fas fa-check-circle"></i> All booking balances settled.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Daily revenue trend — last 14 days within the date range -->
        <?php if (!empty($dailyTrend)): ?>
            <section class="acct-panel">
                <header class="acct-panel__head">
                    <h2 class="acct-panel__title"><i class="fas fa-chart-line"></i> Daily Revenue Trend</h2>
                    <p class="acct-panel__sub">Last 14 days within the selected range. Each row is a calendar day.</p>
                </header>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <?php if ($mod_bookings): ?><th class="num" title="Room booking payments received on this day">Rooms</th><?php endif; ?>
                                <?php if ($mod_conference): ?><th class="num" title="Conference and events payments received on this day">Conference</th><?php endif; ?>
                                <?php if ($mod_pos): ?><th class="num" title="<?php echo isRestaurantEnabled() ? 'Food &amp; Beverage (F&amp;B) — restaurant and bar sales via the POS system' : 'Sales recorded through the POS/till system'; ?>"><?php echo htmlspecialchars(rh_pos_short_label()); ?></th><?php endif; ?>
                                <?php if ($mod_gym): ?><th class="num" title="Gym membership payments received on this day">Gym</th><?php endif; ?>
                                <?php if ($mod_events): ?><th class="num" title="Event booking payments received on this day">Events</th><?php endif; ?>
                                <th class="num" title="Refunds issued on this day (subtracted from Net Total)">Refunds</th>
                                <th class="num" title="Net Total — all revenue sources combined, minus refunds">Net Total</th>
                                <th class="num" title="Transactions — number of individual payment records on this day">Txns</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $maxNet = 0;
                            foreach ($dailyTrend as $d) {
                                $net = (float)$d['room_rev'] + (float)$d['conf_rev'] + (float)$d['fnb_rev'] + (float)$d['gym_rev'] + (float)$d['events_rev'] - (float)$d['refunds'];
                                if ($net > $maxNet) $maxNet = $net;
                            }
                            foreach ($dailyTrend as $d):
                                $net = (float)$d['room_rev'] + (float)$d['conf_rev'] + (float)$d['fnb_rev'] + (float)$d['gym_rev'] + (float)$d['events_rev'] - (float)$d['refunds'];
                                $netPct = $maxNet > 0 ? ($net / $maxNet) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars(date('D, M j', strtotime($d['day']))); ?></strong>
                                        <small class="acct-muted"><?php echo htmlspecialchars(date('Y', strtotime($d['day']))); ?></small>
                                    </td>
                                    <?php if ($mod_bookings): ?><td class="num"><?php echo $currency_symbol . number_format((float)$d['room_rev'], 2); ?></td><?php endif; ?>
                                    <?php if ($mod_conference): ?><td class="num"><?php echo $currency_symbol . number_format((float)$d['conf_rev'], 2); ?></td><?php endif; ?>
                                    <?php if ($mod_pos): ?><td class="num"><?php echo $currency_symbol . number_format((float)$d['fnb_rev'], 2); ?></td><?php endif; ?>
                                    <?php if ($mod_gym): ?><td class="num"><?php echo $currency_symbol . number_format((float)$d['gym_rev'], 2); ?></td><?php endif; ?>
                                    <?php if ($mod_events): ?><td class="num"><?php echo $currency_symbol . number_format((float)$d['events_rev'], 2); ?></td><?php endif; ?>
                                    <td class="num">
                                        <?php if ((float)$d['refunds'] > 0): ?>
                                            <span class="acct-muted">&minus;<?php echo $currency_symbol . number_format((float)$d['refunds'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="acct-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <strong><?php echo $currency_symbol . number_format($net, 2); ?></strong>
                                        <span class="acct-bar acct-bar--inline"><span class="acct-bar__fill" style="width: <?php echo number_format($netPct, 1); ?>%"></span></span>
                                    </td>
                                    <td class="num"><?php echo (int)$d['txn_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Refund breakdown -->
        <?php if (!empty($refundReasons)): ?>
            <section class="acct-panel">
                <header class="acct-panel__head">
                    <h2 class="acct-panel__title"><i class="fas fa-undo"></i> Refunds by Reason</h2>
                    <p class="acct-panel__sub">
                        Total refunds issued: <strong><?php echo $currency_symbol . number_format($cat_refunds, 2); ?></strong>
                        <?php if ($cat_pending_refunds > 0): ?>
                            · <span class="acct-muted">Pending <?php echo $currency_symbol . number_format($cat_pending_refunds, 2); ?></span>
                        <?php endif; ?>
                    </p>
                </header>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Reason</th>
                                <th class="num">Count</th>
                                <th class="num">Total</th>
                                <th class="num">% of Refunds</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalRefundAmount = array_sum(array_column($refundReasons, 'total_amount'));
                            $reasonLabels = [
                                'early_checkout'        => 'Early Checkout',
                                'late_checkout_charge'  => 'Late Checkout Charge',
                                'cancellation'          => 'Cancellation',
                                'service_issue'         => 'Service Issue',
                                'overpayment'           => 'Overpayment',
                                'other'                 => 'Other'
                            ];
                            foreach ($refundReasons as $reason):
                                $percentage = $totalRefundAmount > 0 ? ((float)$reason['total_amount'] / $totalRefundAmount) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($reasonLabels[$reason['refund_reason']] ?? ucfirst(str_replace('_', ' ', (string)$reason['refund_reason']))); ?></td>
                                    <td class="num"><?php echo (int)$reason['count']; ?></td>
                                    <td class="num"><strong><?php echo $currency_symbol . number_format((float)$reason['total_amount'], 2); ?></strong></td>
                                    <td class="num">
                                        <span class="acct-bar"><span class="acct-bar__fill acct-bar__fill--danger" style="width: <?php echo number_format($percentage, 1); ?>%"></span></span>
                                        <small><?php echo number_format($percentage, 1); ?>%</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Recent payments -->
        <section class="acct-panel">
            <header class="acct-panel__head">
                <h2 class="acct-panel__title"><i class="fas fa-receipt"></i> Recent Payments</h2>
                <p class="acct-panel__sub">Most recent 20 transactions across all sources.</p>
            </header>
            <div class="acct-table-wrap">
                <table class="acct-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Booking</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th class="num">Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                            <tr>
                                <td colspan="8" class="acct-empty"><i class="fas fa-inbox"></i> No payments recorded yet.</td>
                            </tr>
                            <?php else: foreach ($recentPayments as $payment): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['booking_description']); ?></td>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($payment['booking_type']); ?>"><?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars(date('M j, Y', strtotime($payment['payment_date']))); ?>
                                        <small class="acct-muted"><?php echo htmlspecialchars(date('H:i', strtotime($payment['payment_date']))); ?></small>
                                    </td>
                                    <td class="num">
                                        <strong><?php echo $currency_symbol . number_format((float)$payment['total_amount'], 2); ?></strong>
                                        <?php if ((float)$payment['vat_amount'] > 0): ?><small class="acct-muted">incl. VAT</small><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars($payment['payment_status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_status']))); ?></span></td>
                                    <td><a href="payment-details.php?id=<?php echo (int)$payment['id']; ?>" class="acct-link"><i class="fas fa-eye"></i></a></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($recentPayments) >= 20): ?>
                <div class="acct-panel__foot">
                    <a href="payments.php" class="acct-btn acct-btn--primary">View all payments <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        </section>

        <script>
            (function() {
                var unlockBtn = document.getElementById('vatUnlockBtn');
                var cancelBtn = document.getElementById('vatCancelBtn');
                var confirmYes = document.getElementById('vatConfirmYes');
                var confirmNo = document.getElementById('vatConfirmCancel');
                var lockedView = document.getElementById('vatLockedView');
                var editView = document.getElementById('vatEditView');
                var modal = document.getElementById('vatConfirmModal');
                var overlay = document.getElementById('vatConfirmModal-overlay');
                var insightModalId = 'acctInsightModal';

                function openModal() {
                    if (!modal) return;
                    modal.classList.add('active');
                    if (overlay) overlay.classList.add('active');
                    document.body.classList.add('modal-open');
                    var firstBtn = modal.querySelector('button');
                    if (firstBtn) setTimeout(function() {
                        firstBtn.focus();
                    }, 80);
                }

                function closeModal() {
                    if (!modal) return;
                    modal.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    document.body.classList.remove('modal-open');
                }

                function showEditForm() {
                    if (lockedView) lockedView.hidden = true;
                    if (editView) editView.hidden = false;
                    if (editView) editView.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }

                function showLockedView() {
                    if (editView) editView.hidden = true;
                    if (lockedView) lockedView.hidden = false;
                }

                if (unlockBtn) unlockBtn.addEventListener('click', openModal);
                if (confirmYes) confirmYes.addEventListener('click', function() {
                    closeModal();
                    showEditForm();
                });
                if (confirmNo) confirmNo.addEventListener('click', closeModal);
                if (cancelBtn) cancelBtn.addEventListener('click', showLockedView);

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal && modal.classList.contains('active')) closeModal();
                });

                window.__openAccountingDashboardInsight = function(triggerEl) {
                    var insightKey = triggerEl ? triggerEl.getAttribute('data-insight-key') : '';
                    if (!insightKey) return;

                    var insightTemplate = document.getElementById('acct-insight-template-' + insightKey);
                    var insightBody = document.getElementById('acctInsightBody');
                    var insightTitle = document.getElementById('acctInsightTitle');
                    var insightModal = document.getElementById(insightModalId);
                    var insightOverlay = document.getElementById(insightModalId + '-overlay');
                    if (!insightTemplate || !insightBody || !insightTitle || !insightModal) return;

                    insightTitle.textContent = triggerEl.getAttribute('data-insight-title') || 'Accounting Insight';
                    insightBody.innerHTML = insightTemplate.innerHTML;

                    if (window.Modal && typeof window.Modal.syncModalTableLabels === 'function') {
                        window.Modal.syncModalTableLabels(insightModal);
                    }

                    if (window.Modal && typeof window.Modal.open === 'function') {
                        window.Modal.open(insightModalId);
                        return;
                    }

                    insightModal.classList.add('active');
                    if (insightOverlay) insightOverlay.classList.add('active');
                    document.body.classList.add('modal-open');
                };

                if (!window.__accountingDashboardInsightHandlersBound) {
                    document.addEventListener('click', function(e) {
                        var trigger = e.target.closest('.js-acct-insight-trigger');
                        if (!trigger) return;

                        var nestedLink = e.target.closest('a');
                        if (nestedLink && trigger.contains(nestedLink)) {
                            return;
                        }

                        e.preventDefault();
                        if (typeof window.__openAccountingDashboardInsight === 'function') {
                            window.__openAccountingDashboardInsight(trigger);
                        }
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key !== 'Enter' && e.key !== ' ') return;
                        var trigger = e.target && e.target.closest ? e.target.closest('.js-acct-insight-trigger') : null;
                        if (!trigger) return;

                        e.preventDefault();
                        if (typeof window.__openAccountingDashboardInsight === 'function') {
                            window.__openAccountingDashboardInsight(trigger);
                        }
                    });

                    window.__accountingDashboardInsightHandlersBound = true;
                }

                // If there was a save error, re-open the form so admin can fix it
                <?php if ($vatSettingsError): ?>
                    if (editView) editView.hidden = false;
                    if (lockedView) lockedView.hidden = true;
                <?php endif; ?>
            })();
        </script>

        <?php require_once 'includes/admin-footer.php'; ?>

