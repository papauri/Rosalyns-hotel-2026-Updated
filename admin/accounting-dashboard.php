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
// Payments ledger filtered to the selected period (used by insight templates and panels)
$periodPaymentsLink = 'payments.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);

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
// One three-way VAT mode: off / inclusive / exclusive (same value the whole system reads).
$vatModeNow = !$vatEnabled ? 'off' : $vatPricingMode;
$canChangeFinanceSettings = hasPermission((int)($user['id'] ?? 0), 'finance_settings');
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
    } elseif (!$canChangeFinanceSettings) {
        $vatSettingsError = 'You do not have permission to change VAT settings.';
    } else {
        try {
            $postedVatMode = (string)($_POST['vat_mode'] ?? '');
            if (!in_array($postedVatMode, ['off', 'inclusive', 'exclusive'], true)) {
                throw new Exception('Choose a VAT mode.');
            }
            $vatEnabledValue = $postedVatMode === 'off' ? '0' : '1';
            // No VAT: the rate field is ignored and the stored rate is left as it was.
            $vatRateInput = $postedVatMode === 'off' ? trim((string)getSetting('vat_rate', '0')) : trim((string)($_POST['vat_rate'] ?? '0'));
            if ($postedVatMode === 'off' && !is_numeric($vatRateInput)) {
                $vatRateInput = '0';
            }
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

            // off keeps the stored pricing mode untouched; the other two set it.
            $vatPricingModeValue = $postedVatMode === 'off'
                ? (getSetting('vat_pricing_mode', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive')
                : $postedVatMode;

            $oldVatMode = $vatModeNow;
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
                    'vat_mode_from' => $oldVatMode,
                    'vat_mode_to' => $postedVatMode,
                    'vat_rate' => $vatRateValue,
                    'vat_number_set' => $vatNumberValue !== '',
                ]);
            }

            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate = getSetting('vat_rate');
            $vatNumber = getSetting('vat_number');
            $vatPricingMode = getSetting('vat_pricing_mode', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
            $vatModeNow = !$vatEnabled ? 'off' : $vatPricingMode;
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
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_collected,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN payment_amount ELSE 0 END), 0) as total_collected_excl_vat,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END), 0)
                as total_vat_collected,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending', 'partial') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_pending,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) as total_refunds_issued,
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
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as room_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
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
                    AND b2.amount_due > 0.01
                    AND (b2.status IN ('pending','confirmed','checked-in','checked-out') OR (b2.status = 'cancelled' AND COALESCE(b2.cancellation_retained_amount,0) > 0))
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
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as conf_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
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
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as gym_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
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
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as events_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
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
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.total_amount ELSE 0 END), 0) as restaurant_collected,
                COALESCE(SUM(CASE WHEN p.payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(p.payment_type, '') != 'refund' THEN p.vat_amount ELSE 0 END), 0)
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
            COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total
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
          AND refund_status IN ('completed','processing')
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
        $outstandingParts[] = "SELECT 'room' as type, COUNT(*) as count, SUM(amount_due) as total_outstanding FROM bookings WHERE amount_due > 0.01 AND (status IN ('pending','confirmed','checked-in','checked-out') OR (status = 'cancelled' AND COALESCE(cancellation_retained_amount,0) > 0))";
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

    // Daily revenue trend (last 14 days within the selected range, capped to range).
    // Anchored on today when the range runs into the future (e.g. "this month"),
    // otherwise the window was mostly days that have not happened yet.
    $trendEnd = min($endDate, date('Y-m-d'));
    if ($trendEnd < $startDate) { $trendEnd = $endDate; }
    $trendStartCandidate = max(strtotime($startDate), strtotime('-13 days', strtotime($trendEnd)));
    $trendStart = date('Y-m-d', $trendStartCandidate);
    $trendStmt = $pdo->prepare("
        SELECT
            DATE(payment_date) AS day,
            COALESCE(SUM(CASE WHEN booking_type = 'room' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS room_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'conference' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS conf_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'restaurant' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS fnb_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'gym' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS gym_rev,
            COALESCE(SUM(CASE WHEN booking_type = 'event' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS events_rev,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN refund_amount ELSE 0 END), 0) AS refunds,
            COUNT(*) AS txn_count
        FROM payments
        WHERE deleted_at IS NULL
          AND DATE(payment_date) BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY day DESC
    ");
    $trendStmt->execute([$trendStart, $trendEnd]);
    $dailyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentColumns = finance_table_columns($pdo, 'payments');
    $mraColumnsAvailable = isset($paymentColumns['mra_status']);
    $mraPendingSql = $mraColumnsAvailable
        ? "SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' AND mra_status NOT IN ('accepted','not_required') THEN 1 ELSE 0 END)"
        : "0";
    $complianceStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN 1 ELSE 0 END) AS completed_sales,
            SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') != 'refund' AND (receipt_number IS NULL OR receipt_number = '') THEN 1 ELSE 0 END) AS missing_receipts,
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
<link rel="stylesheet" href="css/admin-kpi.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-kpi.css'); ?>">
    <link rel="stylesheet" href="css/admin-cockpit.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-cockpit.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content finance-page" data-rh-page-header-normalized="1"><?php /* the cockpit hero is this page's header */ ?>
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
                    WHEN COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded') THEN total_amount
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
                <a href="payments.php?start_date=<?php echo urlencode($today); ?>&end_date=<?php echo urlencode($today); ?>" class="acct-btn acct-btn--primary">Open today's payments</a>
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

        <?php if ($canChangeFinanceSettings): ?>
        <!-- VAT unlock confirmation modal -->
        <div class="modal-overlay" id="vatConfirmModal-overlay" data-modal-overlay aria-hidden="true"></div>
        <div class="modal-overlay vat-confirm-modal" id="vatConfirmModal" role="dialog" aria-modal="true" aria-labelledby="vatConfirmTitle" data-modal data-close-on-escape="true" data-close-on-overlay="false">
            <div class="modal-container vat-confirm-modal__container">
                <div class="modal-header">
                    <h3 class="vat-confirm-modal__title" id="vatConfirmTitle"><i class="fas fa-shield-halved"></i> Unlock VAT Settings?</h3>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body vat-confirm-modal__content">
                    <p class="vat-confirm-modal__body">
                        VAT settings control how tax is calculated across all bookings, POS transactions, and invoices.
                        Incorrect values can cause compliance issues with the MRA.
                    </p>
                    <p class="vat-confirm-modal__body">
                        <strong>Are you sure you want to unlock and edit these settings?</strong>
                    </p>
                </div>
                <div class="modal-footer vat-confirm-modal__actions">
                    <button type="button" class="acct-btn acct-btn--ghost" id="vatConfirmCancel">
                        <i class="fas fa-xmark"></i> No, keep locked
                    </button>
                    <button type="button" class="acct-btn btn-primary" id="vatConfirmYes">
                        <i class="fas fa-lock-open"></i> Yes, unlock to edit
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>


        <?php
        /* =====================================================================
         * Cockpit view-model — read-only; derived from the figures computed
         * above. Nothing here changes a calculation.
         * =================================================================== */
        $ckCur = htmlspecialchars((string)$currency_symbol, ENT_QUOTES, 'UTF-8');
        $ckMoney = static function ($v) use ($ckCur): string {
            return '<span class="ck-cur">' . $ckCur . '</span>' . number_format((float)$v, 2);
        };
        $ckPlain = static function ($v) use ($ckCur): string {
            return $ckCur . number_format((float)$v, 2);
        };
        $ckShort = static function ($v) use ($ckCur): string {
            $v = (float)$v;
            if (abs($v) >= 1000000) { return $ckCur . number_format($v / 1000000, 1) . 'M'; }
            if (abs($v) >= 10000)   { return $ckCur . number_format($v / 1000, 1) . 'k'; }
            return $ckCur . number_format($v, 0);
        };
        $ckH = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $ckTrig = static function (string $key, string $title) use ($ckH): string {
            return 'role="button" tabindex="0" data-insight-key="' . $ckH($key) . '" data-insight-title="' . $ckH($title) . '"';
        };

        $periodLabel = $showAll
            ? 'All time'
            : date('j M Y', strtotime($startDate)) . ' - ' . date('j M Y', strtotime($endDate));

        // Period presets (links into this same GET form)
        $ckPresets = [
            ['This month', 'accounting-dashboard.php', !$showAll && $startDate === date('Y-m-01') && $endDate === date('Y-m-t')],
            ['Today', 'accounting-dashboard.php?start_date=' . $today . '&end_date=' . $today, !$showAll && $startDate === $today && $endDate === $today],
            ['7 days', 'accounting-dashboard.php?start_date=' . date('Y-m-d', strtotime('-6 days')) . '&end_date=' . $today, !$showAll && $startDate === date('Y-m-d', strtotime('-6 days')) && $endDate === $today],
            ['30 days', 'accounting-dashboard.php?start_date=' . date('Y-m-d', strtotime('-29 days')) . '&end_date=' . $today, !$showAll && $startDate === date('Y-m-d', strtotime('-29 days')) && $endDate === $today],
            ['This year', 'accounting-dashboard.php?start_date=' . $thisYear . '-01-01&end_date=' . $thisYear . '-12-31', !$showAll && $startDate === $thisYear . '-01-01' && $endDate === $thisYear . '-12-31'],
            ['All time', 'accounting-dashboard.php?show_all=1', $showAll],
        ];

        // ---- Compliance checks (full list kept so clear ones can be shown folded) ----
        $complianceRows = [
            [
                'key' => 'compliance-completed-sales',
                'insight_title' => 'Completed Sales Coverage',
                'label' => 'Completed sales',
                'count' => (int)($complianceSummary['completed_sales'] ?? 0),
                'warn' => false,
                'action_link' => $periodPaymentsLink . '&status=completed',
                'action_label' => 'Open payments ledger',
            ],
            [
                'key' => 'compliance-missing-receipts',
                'insight_title' => 'Receipt Number Compliance',
                'label' => 'Completed sales missing receipt number',
                'short' => 'Sales missing a receipt number',
                'count' => (int)($complianceSummary['missing_receipts'] ?? 0),
                'warn' => true,
                'action_link' => $periodPaymentsLink,
                'action_label' => 'Review affected payments',
            ],
            [
                'key' => 'compliance-missing-invoice-numbers',
                'insight_title' => 'Invoice Number Integrity',
                'label' => 'Generated invoices missing invoice number',
                'short' => 'Invoices missing a number',
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
                'short' => 'Paid POS orders not in the ledger',
                'count' => (int)($complianceSummary['paid_pos_without_ledger'] ?? 0),
                'warn' => true,
                'action_link' => $mod_stock ? 'stock-orders.php' : 'pos.php',
                'action_label' => 'Open POS orders',
            ];
        }
        $complianceRows[] = [
            'key' => 'compliance-mra-pending',
            'insight_title' => $mraColumnsAvailable ? 'MRA Submission Readiness' : 'MRA Fields Installation Check',
            'label' => $mraColumnsAvailable ? 'MRA pending/unsubmitted sales' : 'MRA readiness fields not installed',
            'short' => $mraColumnsAvailable ? 'Sales not yet sent to MRA' : 'MRA fields not installed',
            'count' => $mraColumnsAvailable ? (int)($complianceSummary['mra_pending_or_unsubmitted'] ?? 0) : 1,
            'warn' => true,
            'action_link' => $mraColumnsAvailable ? ('reports.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate)) : 'booking-settings.php#invoice-settings',
            'action_label' => $mraColumnsAvailable ? 'Open MRA-focused reports' : 'Open settings & install fields',
        ];
        $complianceAll = $complianceRows;
        // Only checks that need attention are listed; a clean period shows one line.
        $complianceRows = array_values(array_filter($complianceRows, static function ($row) {
            return $row['warn'] && (int)$row['count'] > 0;
        }));
        $complianceClear = array_values(array_filter($complianceAll, static function ($row) {
            return $row['warn'] && (int)$row['count'] <= 0;
        }));

        // ---- Needs-attention queue: only non-zero items are shown loudly ----
        $attn = [];
        foreach ($complianceRows as $row) {
            $attn[] = [
                'tone' => 'red', 'icon' => 'fa-shield-halved', 'value' => number_format((int)$row['count']), 'money' => false,
                'label' => $row['short'] ?? $row['label'], 'hint' => '',
                'key' => $row['key'], 'title' => $row['insight_title'],
                'href' => $row['action_link'], 'link' => $row['action_label'],
            ];
        }
        if ($cat_recv_total > 0.01) {
            $attn[] = [
                'tone' => 'red', 'icon' => 'fa-file-invoice-dollar', 'value' => $ckShort($cat_recv_total), 'money' => true,
                'label' => 'Balances owed to us', 'hint' => number_format((int)$cat_recv_count) . ' open - chase these first',
                'key' => 'receivables', 'title' => 'Receivables Follow-up',
                'href' => 'bookings.php?payment_status=unpaid', 'link' => 'Open bookings',
            ];
        }
        if ($cat_pending > 0.01) {
            $attn[] = [
                'tone' => 'amber', 'icon' => 'fa-hourglass-half', 'value' => $ckShort($cat_pending), 'money' => true,
                'label' => 'Payments still pending', 'hint' => 'Recorded but not yet cleared',
                'key' => 'receivables', 'title' => 'Receivables Follow-up',
                'href' => $periodPaymentsLink . '&status=pending', 'link' => 'Review payments',
            ];
        }
        if ($cat_pending_refunds > 0.01) {
            $attn[] = [
                'tone' => 'red', 'icon' => 'fa-rotate-left', 'value' => $ckShort($cat_pending_refunds), 'money' => true,
                'label' => 'Refunds waiting to be settled', 'hint' => '',
                'key' => 'cash-today', 'title' => 'Cash Position Detail',
                'href' => 'payments.php?refund_status=pending', 'link' => 'Approve refunds',
            ];
        }
        if ($acct_billing && (int)$quotationStats['sent'] > 0) {
            $attn[] = [
                'tone' => 'amber', 'icon' => 'fa-file-contract', 'value' => number_format((int)$quotationStats['sent']), 'money' => false,
                'label' => 'Quotations awaiting a reply', 'hint' => $ckPlain($quotationStats['sent_value']) . ' on the table',
                'key' => 'quotation-sent', 'title' => 'Open Quotations Follow-up',
                'href' => 'quotations.php?status=sent', 'link' => 'Follow up',
            ];
        }
        if ($mod_pos && $cat_voids_count > 0) {
            $attn[] = [
                'tone' => 'amber', 'icon' => 'fa-ban', 'value' => number_format($cat_voids_count), 'money' => false,
                'label' => 'Voided POS orders', 'hint' => $ckPlain($cat_voids_value) . ' voided in this period',
                'key' => '', 'title' => '',
                'href' => 'pos-accounting.php', 'link' => 'Open POS accounting',
            ];
        }
        if ($mod_pos && (float)$stock_shrinkage['total'] > 0) {
            $attn[] = [
                'tone' => 'amber', 'icon' => 'fa-box-open', 'value' => $ckShort($stock_shrinkage['total']), 'money' => true,
                'label' => 'Stock lost to wastage and shrinkage', 'hint' => 'Hits margin directly',
                'key' => '', 'title' => '',
                'href' => 'pos-accounting.php', 'link' => 'Open POS accounting',
            ];
        }
        $ckTone = ['red' => 0, 'amber' => 1];
        foreach ($attn as $i => &$a) { $a['_i'] = $i; }
        unset($a);
        usort($attn, static function ($x, $y) use ($ckTone) {
            return [$ckTone[$x['tone']], $x['_i']] <=> [$ckTone[$y['tone']], $y['_i']];
        });
        $attnCount = count($attn);

        // ---- Revenue by source ----
        $srcRows = [];
        if ($mod_bookings) {
            $srcRows[] = ['label' => 'Rooms (bookings)', 'icon' => 'fa-bed', 'count' => (int)($roomSummary['total_bookings_with_payments'] ?? 0), 'gross' => $cat_revenue_room, 'vat' => (float)($roomSummary['room_vat_collected'] ?? 0), 'link' => 'payments.php?booking_type=room', 'link_lbl' => 'Room payments'];
        }
        if ($mod_conference) {
            $srcRows[] = ['label' => 'Conferences & events', 'icon' => 'fa-briefcase', 'count' => (int)($confSummary['total_conferences_with_payments'] ?? 0), 'gross' => $cat_revenue_conf, 'vat' => (float)($confSummary['conf_vat_collected'] ?? 0), 'link' => 'payments.php?booking_type=conference', 'link_lbl' => 'Conference payments'];
        }
        if ($mod_pos) {
            $srcRows[] = ['label' => rh_pos_category_label(), 'icon' => isRestaurantEnabled() ? 'fa-utensils' : 'fa-cash-register', 'count' => (int)($restaurantSummary['total_restaurant_orders_with_payments'] ?? 0), 'gross' => $cat_revenue_fnb, 'vat' => (float)($restaurantSummary['restaurant_vat_collected'] ?? 0), 'link' => $mod_stock ? 'stock-orders.php' : 'pos.php', 'link_lbl' => 'POS orders'];
        }
        if ($mod_gym) {
            $srcRows[] = ['label' => 'Gym memberships', 'icon' => 'fa-dumbbell', 'count' => (int)($gymSummary['total_gym_with_payments'] ?? 0), 'gross' => $cat_revenue_gym, 'vat' => (float)($gymSummary['gym_vat_collected'] ?? 0), 'link' => 'payments.php?booking_type=gym', 'link_lbl' => 'Gym payments'];
        }
        if ($mod_events) {
            $srcRows[] = ['label' => 'Event bookings', 'icon' => 'fa-calendar-check', 'count' => (int)($eventsSummary['total_events_with_payments'] ?? 0), 'gross' => $cat_revenue_events, 'vat' => (float)($eventsSummary['events_vat_collected'] ?? 0), 'link' => 'payments.php?booking_type=event', 'link_lbl' => 'Event payments'];
        }
        $ckPalette = ['#231F1C', '#8F6A35', '#2f6fad', '#3f8f5a', '#c9a227', '#b4632f'];
        $donutStops = [];
        $acc = 0.0;
        foreach ($srcRows as $i => &$r) {
            $r['pct'] = $source_total_gross > 0 ? ($r['gross'] / $source_total_gross) * 100 : 0;
            $r['color'] = $ckPalette[$i % count($ckPalette)];
            if ($r['pct'] > 0) {
                $donutStops[] = $r['color'] . ' ' . number_format($acc, 2, '.', '') . '% ' . number_format($acc + $r['pct'], 2, '.', '') . '%';
                $acc += $r['pct'];
            }
        }
        unset($r);
        $donutCss = $donutStops ? 'conic-gradient(' . implode(', ', $donutStops) . ')' : 'conic-gradient(#ebe4da 0 100%)';
        $srcTxns = array_sum(array_column($srcRows, 'count'));

        // ---- Daily trend (chronological, every day of the window shown) ----
        $trendBars = [];
        if (!empty($dailyTrend)) {
            $byDay = [];
            foreach ($dailyTrend as $d) {
                $byDay[$d['day']] = [
                    'net' => (float)$d['room_rev'] + (float)$d['conf_rev'] + (float)$d['fnb_rev'] + (float)$d['gym_rev'] + (float)$d['events_rev'] - (float)$d['refunds'],
                    'txn' => (int)$d['txn_count'],
                ];
            }
            ksort($byDay);
            // Plot the whole trend window so one busy day is a bar, not the full width.
            $firstDay = isset($trendStart) ? min($trendStart, array_key_first($byDay)) : array_key_first($byDay);
            $lastDay = isset($trendEnd) ? max($trendEnd, array_key_last($byDay)) : array_key_last($byDay);
            for ($i = 0; $i < 31; $i++) {
                $dk = date('Y-m-d', strtotime($firstDay . ' +' . $i . ' day'));
                if ($dk > $lastDay) { break; }
                $trendBars[$dk] = $byDay[$dk] ?? ['net' => 0.0, 'txn' => 0];
            }
        }
        $trendMax = 0.0;
        $trendSum = 0.0;
        foreach ($trendBars as $tb) { $trendMax = max($trendMax, $tb['net']); $trendSum += $tb['net']; }
        $trendMax = max(1.0, $trendMax);
        $trendLastKey = $trendBars ? array_key_last($trendBars) : '';

        // ---- Receivables rows ----
        $recvRows = [];
        foreach ($outstandingSummary as $os) {
            if ((float)$os['total_outstanding'] <= 0) { continue; }
            $recvRows[] = $os;
        }

        // ---- Payment mix ----
        $pmTotal = 0.0;
        foreach ($paymentMethods as $pm) { $pmTotal += (float)$pm['total']; }
        $pmIcons = ['cash' => 'fa-money-bill-wave', 'bank_transfer' => 'fa-building-columns', 'credit_card' => 'fa-credit-card', 'debit_card' => 'fa-credit-card', 'mobile_money' => 'fa-mobile-screen', 'cheque' => 'fa-file-invoice-dollar'];

        // ---- Refund reasons ----
        $totalRefundAmount = array_sum(array_column($refundReasons, 'total_amount'));
        $reasonLabels = [
            'early_checkout' => 'Early Checkout', 'late_checkout_charge' => 'Late Checkout Charge', 'cancellation' => 'Cancellation',
            'service_issue' => 'Service Issue', 'overpayment' => 'Overpayment', 'other' => 'Other',
        ];

        // ---- Panels that depend on modules/data ----
        $showQuotes = $acct_billing && (int)$quotationStats['total'] > 0;
        $showCN = $acct_ar && ((int)$cnStats['count_issued'] > 0 || (float)$cnStats['total_outstanding'] > 0);
        $showPos = $mod_pos && (!empty($posByType) || $pos_gross > 0);
        $qTotal = max(1, (int)$quotationStats['total']);

        // ---- Hero sentence ----
        $ledeBits = [];
        $ledeBits[] = number_format($cat_revenue_net, 2) . ' net revenue';
        if ($cat_recv_total > 0.01) { $ledeBits[] = number_format($cat_recv_total, 2) . ' still owed'; }
        $ledeText = $currency_symbol . ' ' . implode(', ', $ledeBits) . ' - ' . ($attnCount
            ? $attnCount . ' thing' . ($attnCount === 1 ? '' : 's') . ' need' . ($attnCount === 1 ? 's' : '') . ' fixing.'
            : 'nothing needs fixing.');

        // ---- Tabs ----
        $ckTabs = [];
        $ckTabs['payments'] = ['Recent payments', count($recentPayments)];
        $ckTabs['receivables'] = ['Receivables', count($recvRows)];
        $ckTabs['daily'] = ['Daily detail', count($dailyTrend)];
        if (!empty($refundReasons)) { $ckTabs['refunds'] = ['Refunds', count($refundReasons)]; }
        if ($showPos) { $ckTabs['pos'] = ['POS by type', count($posByType)]; }
        $ckTabs['compliance'] = ['Compliance', count($complianceRows)];
        ?>

        <div class="ck ck-acct" data-ck-root>

        <!-- ============ HERO ============ -->
        <header class="ck-hero">
            <div class="ck-hero__intro">
                <p class="ck-eyebrow"><i class="fas fa-scale-balanced"></i> Accounting <span class="ck-dot"></span> <?php echo $ckH($periodLabel); ?></p>
                <h1 class="ck-hero__title">Accounting Dashboard</h1>
                <p class="ck-hero__lede"><?php echo $ckH($ledeText); ?></p>
            </div>
            <nav class="ck-hero__actions" aria-label="Accounting shortcuts">
                <a class="ck-btn ck-btn--primary" href="payment-add.php" title="Manually record a new payment against a booking or invoice"><i class="fas fa-plus"></i><span>Record payment</span></a>
                <a class="ck-btn" href="payments.php" title="View all individual payment records across all booking types"><i class="fas fa-list"></i><span>Payments</span></a>
                <?php if ($acct_billing): ?>
                <a class="ck-btn" href="invoices.php" title="View, search, and download <?php echo $ckH($acct_party); ?> and client invoices"><i class="fas fa-file-invoice-dollar"></i><span>Invoices</span></a>
                <a class="ck-btn" href="quotations.php" title="View all quotations issued, track status, download PDFs"><i class="fas fa-file-contract"></i><span>Quotations</span></a>
                <?php endif; ?>
                <a class="ck-btn" href="reports.php" title="Detailed financial reports - P&amp;L, revenue by source, VAT register, occupancy, and more"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <a class="ck-btn" href="#vat-settings" title="VAT settings - enable or disable VAT, set the tax rate and VAT registration number"><i class="fas fa-percent"></i><span>VAT</span></a>
            </nav>
        </header>

        <!-- Period filter: same GET form as before, plus one-tap presets -->
        <div class="ck-filter">
            <div class="ck-filter__presets" role="group" aria-label="Period presets">
                <?php foreach ($ckPresets as $p): ?>
                    <a class="ck-btn ck-btn--sm<?php echo $p[2] ? ' is-active' : ''; ?>" href="<?php echo $ckH($p[1]); ?>"<?php echo $p[2] ? ' aria-current="true"' : ''; ?>><?php echo $ckH($p[0]); ?></a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="ck-filter__form">
                <label class="ck-field">
                    <span>From</span>
                    <input type="date" name="start_date" value="<?php echo $ckH($showAll ? '' : $startDate); ?>">
                </label>
                <label class="ck-field">
                    <span>To</span>
                    <input type="date" name="end_date" value="<?php echo $ckH($showAll ? '' : $endDate); ?>">
                </label>
                <button type="submit" class="ck-btn ck-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="accounting-dashboard.php" class="ck-btn ck-btn--ghost">Reset</a>
            </form>
        </div>

        <?php if (!empty($error)): ?>
            <div class="ck-empty"><i class="fas fa-triangle-exclamation"></i><p><?php echo $ckH($error); ?></p></div>
        <?php endif; ?>

        <!-- ============ KPI STRIP ============ -->
        <section class="ck-kpis" aria-label="Key figures">
            <div class="ck-kpi ck-kpi--dark js-acct-insight-trigger" title="Net Revenue - total money collected after deducting refunds. This is what the business actually kept." <?php echo $ckTrig('net-revenue', 'Net Revenue Breakdown'); ?> aria-label="Open net revenue breakdown">
                <span class="ck-kpi__icon"><i class="fas fa-coins"></i></span>
                <span class="ck-kpi__label">Net revenue</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $ckMoney($cat_revenue_net); ?></span>
                <span class="ck-meter"><span style="width:<?php echo $cat_revenue_gross > 0 ? max(0, min(100, round($cat_revenue_net / $cat_revenue_gross * 100))) : 0; ?>%"></span></span>
                <span class="ck-kpi__sub">Gross <?php echo $ckPlain($cat_revenue_gross); ?> &minus; refunds <?php echo $ckPlain($cat_refunds); ?></span>
            </div>
            <div class="ck-kpi<?php echo $cat_recv_total > 0.01 ? ' ck-kpi--alert' : ''; ?> js-acct-insight-trigger" title="Receivables - money that <?php echo $ckH($acct_party); ?>s/clients owe but have not yet paid." <?php echo $ckTrig('receivables', 'Receivables Follow-up'); ?> aria-label="Open receivables follow-up">
                <span class="ck-kpi__icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="ck-kpi__label">Owed to us</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $ckMoney($cat_recv_total); ?></span>
                <span class="ck-kpi__sub"><?php echo (int)$cat_recv_count; ?> open invoice<?php echo (int)$cat_recv_count === 1 ? '' : 's'; ?> &middot; pending <?php echo $ckPlain($cat_pending); ?></span>
            </div>
            <div class="ck-kpi js-acct-insight-trigger" title="Cash Position (Today) - cash and mobile money received today. Does not include card, bank transfer or credit." <?php echo $ckTrig('cash-today', 'Cash Position Detail'); ?> aria-label="Open cash position detail">
                <span class="ck-kpi__icon"><i class="fas fa-money-bill-wave"></i></span>
                <span class="ck-kpi__label">Cash today</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $ckMoney($cat_cash_today); ?></span>
                <span class="ck-kpi__sub">Cash + mobile money &middot; <a class="ck-link" href="payments.php?start_date=<?php echo $ckH($today); ?>&amp;end_date=<?php echo $ckH($today); ?>">Today's payments <i class="fas fa-arrow-right"></i></a></span>
            </div>
            <div class="ck-kpi js-acct-insight-trigger" title="VAT Collected - tax held on behalf of MRA. It is not the business's income." <?php echo $ckTrig('vat-collected', 'VAT Compliance Snapshot'); ?> aria-label="Open VAT compliance snapshot">
                <span class="ck-kpi__icon"><i class="fas fa-percent"></i></span>
                <span class="ck-kpi__label">VAT collected</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $ckMoney($cat_vat); ?></span>
                <span class="ck-kpi__sub">
                    <?php echo $vatEnabled ? 'Enabled @ ' . $ckH($vatRate) . '%' : 'Disabled'; ?>
                    <?php if ($vatEnabled && $vatNumber): ?> &middot; VAT&nbsp;# <?php echo $ckH($vatNumber); ?><?php endif; ?>
                    <?php if (!empty($levyEnabled)): ?> &middot; <span title="Tourism levy accrued on bookings taken in this period - remit to the Malawi Tourism Council">Tourism levy <?php echo $ckPlain($cat_levy_period); ?></span><?php endif; ?>
                </span>
            </div>
            <a class="ck-kpi<?php echo $cat_pending_refunds > 0.01 ? ' ck-kpi--warn' : ''; ?>" href="<?php echo $cat_pending_refunds > 0.01 ? 'payments.php?refund_status=pending' : $ckH($periodPaymentsLink); ?>" title="Refunds issued in this period">
                <span class="ck-kpi__icon"><i class="fas fa-rotate-left"></i></span>
                <span class="ck-kpi__label">Refunds</span>
                <span class="ck-kpi__value ck-kpi__value--money"><?php echo $ckMoney($cat_refunds); ?></span>
                <span class="ck-kpi__sub"><?php echo $cat_pending_refunds > 0.01 ? 'Pending ' . $ckPlain($cat_pending_refunds) . ' to settle' : 'None waiting'; ?></span>
            </a>
            <?php if ($mod_pos && $pos_gross > 0): ?>
            <div class="ck-kpi<?php echo $pos_margin_pct < 0 ? ' ck-kpi--alert' : ''; ?>" title="POS gross margin - revenue minus recorded cost of goods">
                <span class="ck-kpi__icon"><i class="fas fa-cash-register"></i></span>
                <span class="ck-kpi__label"><?php echo $ckH(rh_pos_short_label()); ?> margin</span>
                <span class="ck-kpi__value"><?php echo number_format($pos_margin_pct, 1); ?><small>%</small></span>
                <span class="ck-meter ck-meter--good"><span style="width:<?php echo max(0, min(100, round($pos_margin_pct))); ?>%"></span></span>
                <span class="ck-kpi__sub"><?php echo $ckPlain($pos_margin); ?> on <?php echo $ckPlain($pos_gross); ?></span>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============ BENTO ============ -->
        <div class="ck-bento">

            <!-- Needs attention -->
            <section class="ck-panel ck-attn">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Needs attention <?php if ($attnCount): ?><span class="ck-badge"><?php echo (int)$attnCount; ?></span><?php endif; ?></h2>
                        <p class="ck-panel__sub"><?php echo number_format((int)($complianceSummary['completed_sales'] ?? 0)); ?> completed sales checked &middot; most urgent first</p>
                    </div>
                </header>
                <?php if ($attn): ?>
                <ul class="ck-attn__list">
                    <?php foreach ($attn as $i => $a): ?>
                    <li>
                        <div class="ck-attn__item ck-attn__item--<?php echo $ckH($a['tone']); ?><?php echo $i === 0 ? ' is-top' : ''; ?><?php echo $a['key'] !== '' ? ' js-acct-insight-trigger' : ''; ?>"
                            <?php if ($a['key'] !== ''): ?><?php echo $ckTrig($a['key'], $a['title']); ?><?php endif; ?>>
                            <span class="ck-attn__icon"><i class="fas <?php echo $ckH($a['icon']); ?>"></i></span>
                            <span class="ck-attn__text">
                                <strong><?php echo $ckH($a['label']); ?></strong>
                                <small><?php echo $a['hint'] !== '' ? $ckH($a['hint']) . ' &middot; ' : ''; ?><a class="ck-link" href="<?php echo $ckH($a['href']); ?>"><?php echo $ckH($a['link']); ?> <i class="fas fa-arrow-right"></i></a></small>
                            </span>
                            <span class="ck-attn__count<?php echo $a['money'] ? ' ck-attn__count--money' : ''; ?>"><?php echo $a['value']; ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <div class="ck-empty ck-empty--good"><i class="fas fa-circle-check"></i><p><strong>All clear.</strong> All compliance checks pass and nothing is waiting on you.</p></div>
                <?php endif; ?>
                <?php if ($complianceClear): ?>
                <details class="ck-clear">
                    <summary><i class="fas fa-check"></i> <?php echo count($complianceClear); ?> check<?php echo count($complianceClear) === 1 ? '' : 's'; ?> passing</summary>
                    <div class="ck-clear__chips">
                        <?php foreach ($complianceClear as $row): ?>
                            <a href="<?php echo $ckH($row['action_link']); ?>" class="ck-chip ck-chip--muted"><i class="fas fa-check"></i> <?php echo $ckH($row['short'] ?? $row['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
            </section>

            <!-- Daily revenue trend -->
            <section class="ck-panel ck-span-2 ck-money">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Daily revenue</h2>
                        <p class="ck-panel__sub">Net takings by day, last 14 days of the period &middot; <?php echo $ckPlain($trendSum); ?> in total</p>
                    </div>
                    <div class="ck-panel__tools">
                        <button type="button" class="ck-link" data-ck-goto="daily">All days <i class="fas fa-arrow-right"></i></button>
                    </div>
                </header>
                <?php if ($trendBars): ?>
                <div class="ck-money__headline">
                    <span class="ck-money__label" data-ck-trend-label><?php echo $ckH(date('l j M', strtotime($trendLastKey))); ?></span>
                    <span class="ck-money__value" data-ck-trend-value><?php echo $ckMoney($trendBars[$trendLastKey]['net']); ?></span>
                </div>
                <div class="ck-bars ck-bars--n" style="--ck-n:<?php echo count($trendBars); ?>" role="list" aria-label="Net takings per day">
                    <?php foreach ($trendBars as $dk => $tb): $isLast = $dk === $trendLastKey; ?>
                        <button type="button" role="listitem" class="ck-bars__col<?php echo $isLast ? ' is-today is-active' : ''; ?>"
                            data-ck-trend="<?php echo $ckH($ckCur . number_format($tb['net'], 2)); ?>"
                            data-ck-trend-day="<?php echo $ckH(date('l j M', strtotime($dk)) . ' - ' . $tb['txn'] . ' txn' . ($tb['txn'] === 1 ? '' : 's')); ?>"
                            aria-label="<?php echo $ckH(date('l j M', strtotime($dk)) . ': ' . $ckCur . number_format($tb['net'], 2)); ?>">
                            <span class="ck-bars__bar"><span style="height:<?php echo $tb['net'] > 0 ? max(3, round($tb['net'] / $trendMax * 100)) : 0; ?>%"></span></span>
                            <span class="ck-bars__day"><?php echo count($trendBars) <= 8 ? $ckH(date('D', strtotime($dk))) : $ckH(date('j', strtotime($dk))); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="ck-empty"><i class="fas fa-chart-column"></i><p>No payments in the last 14 days of this period.</p></div>
                <?php endif; ?>
            </section>

            <!-- Revenue by source -->
            <section class="ck-panel ck-span-2">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Revenue by source</h2>
                        <p class="ck-panel__sub">Gross collected, each line tied to its originating system.</p>
                    </div>
                    <div class="ck-panel__tools"><a class="ck-link" href="reports.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>">Full report <i class="fas fa-arrow-right"></i></a></div>
                </header>
                <div class="ck-split">
                    <button type="button" class="ck-donut js-acct-insight-trigger" style="--ck-donut:<?php echo $ckH($donutCss); ?>" <?php echo $ckTrig('net-revenue', 'Net Revenue Breakdown'); ?> aria-label="Revenue split by source - open net revenue breakdown">
                        <span class="ck-donut__hole"><strong><?php echo $ckShort($source_total_gross); ?></strong><small>gross</small></span>
                    </button>
                    <div class="ck-table-wrap">
                        <table class="no-card-mobile no-scroll ck-table ck-table--stack">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th class="num" title="Number of individual payment transactions for this source">Txns</th>
                                    <th class="num" title="Gross Revenue - total amount received before deducting refunds or VAT">Gross</th>
                                    <th class="num" title="VAT collected within this revenue. Not income - must be remitted to MRA.">VAT</th>
                                    <th class="num" title="Share of total gross revenue from all sources combined">Share</th>
                                    <th><span class="screen-reader-text" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Drill-down</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($srcRows as $r): ?>
                                <tr>
                                    <td data-label="Source"><span class="ck-legend__dot" style="display:inline-block;background:<?php echo $ckH($r['color']); ?>"></span> <i class="fas <?php echo $ckH($r['icon']); ?>"></i> <?php echo $ckH($r['label']); ?></td>
                                    <td class="num" data-label="Txns"><?php echo number_format($r['count']); ?></td>
                                    <td class="num" data-label="Gross"><strong><?php echo $ckPlain($r['gross']); ?></strong></td>
                                    <td class="num" data-label="VAT"><?php echo $ckPlain($r['vat']); ?></td>
                                    <td class="num" data-label="Share"><span class="ck-meter ck-meter--gold"><span style="width:<?php echo number_format($r['pct'], 1, '.', ''); ?>%"></span></span><?php echo number_format($r['pct'], 1); ?>%</td>
                                    <td><a class="ck-table__cell-link" href="<?php echo $ckH($r['link']); ?>" title="<?php echo $ckH($r['link_lbl']); ?>" aria-label="<?php echo $ckH($r['link_lbl']); ?>"><i class="fas fa-arrow-right"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>Total</th>
                                    <td class="num"><?php echo number_format($srcTxns); ?></td>
                                    <td class="num"><?php echo $ckPlain($source_total_gross); ?></td>
                                    <td class="num"><?php echo $ckPlain($cat_vat); ?></td>
                                    <td class="num">100%</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Payment mix -->
            <section class="ck-panel">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Payment methods</h2>
                        <p class="ck-panel__sub">Where the money came in.</p>
                    </div>
                </header>
                <?php if ($paymentMethods): ?>
                <ul class="ck-levels">
                    <?php foreach ($paymentMethods as $pm):
                        $mPct = $pmTotal > 0 ? ((float)$pm['total'] / $pmTotal) * 100 : 0;
                        $mIcon = $pmIcons[$pm['payment_method']] ?? 'fa-money-bill';
                    ?>
                    <li>
                        <span class="ck-levels__name"><i class="fas <?php echo $ckH($mIcon); ?>"></i> <?php echo $ckH(ucfirst(str_replace('_', ' ', (string)$pm['payment_method']))); ?></span>
                        <span class="ck-levels__qty"><strong><?php echo $ckPlain($pm['total']); ?></strong> &middot; <?php echo number_format($mPct, 1); ?>% &middot; <?php echo (int)$pm['count']; ?></span>
                        <span class="ck-meter ck-meter--gold"><span style="width:<?php echo number_format($mPct, 1, '.', ''); ?>%"></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <div class="ck-empty"><i class="fas fa-credit-card"></i><p>No payments in this period.</p></div>
                <?php endif; ?>
            </section>

            <?php if ($showPos): ?>
            <!-- POS margin -->
            <section class="ck-panel">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title"><?php echo $ckH(rh_pos_short_label()); ?> margin</h2>
                        <p class="ck-panel__sub">Cost recorded at order time.</p>
                    </div>
                    <div class="ck-panel__tools"><button type="button" class="ck-link" data-ck-goto="pos">By order type <i class="fas fa-arrow-right"></i></button></div>
                </header>
                <div class="ck-money__headline">
                    <span class="ck-money__label">Gross margin</span>
                    <span class="ck-money__value"><?php echo $ckMoney($pos_margin); ?> <small style="font-size:.5em;font-weight:500;color:var(--ck-muted)"><?php echo number_format($pos_margin_pct, 1); ?>%</small></span>
                </div>
                <span class="ck-meter ck-meter--good"><span style="width:<?php echo max(0, min(100, round($pos_margin_pct))); ?>%"></span></span>
                <ul class="ck-kv">
                    <li><span>Gross sales</span><strong><?php echo $ckPlain($pos_gross); ?></strong></li>
                    <li><span>Cost of goods (COGS)</span><strong><?php echo $ckPlain($pos_cogs); ?></strong></li>
                    <?php if ($cat_voids_count > 0): ?>
                    <li><span>Voids (<?php echo (int)$cat_voids_count; ?>)</span><strong><?php echo $ckPlain($cat_voids_value); ?></strong></li>
                    <?php endif; ?>
                    <?php if ($stock_shrinkage['total'] > 0): ?>
                    <li><span>Stock losses (shrinkage)</span><strong><?php echo $ckPlain($stock_shrinkage['total']); ?></strong></li>
                    <li class="is-total"><span>Net F&amp;B contribution</span><strong><?php echo $ckPlain($pos_margin - $stock_shrinkage['total']); ?></strong></li>
                    <?php endif; ?>
                </ul>
                <?php if ($stock_shrinkage['total'] > 0 || $folio_fnb['total'] > 0): ?>
                <details class="ck-clear">
                    <summary>Loss &amp; folio detail</summary>
                    <p class="ck-notes" style="margin-top:10px">
                        <?php if ($stock_shrinkage['total'] > 0): ?>
                            <strong>Shrinkage:</strong>
                            wastage <?php echo $ckPlain($stock_shrinkage['wastage']); ?>,
                            count variance <?php echo $ckPlain($stock_shrinkage['variance']); ?>,
                            expiry <?php echo $ckPlain($stock_shrinkage['expiry']); ?>,
                            recall <?php echo $ckPlain($stock_shrinkage['recall']); ?>.
                        <?php endif; ?>
                        <?php if ($folio_fnb['total'] > 0): ?>
                            <br><strong>Folio F&amp;B (room service / minibar):</strong>
                            <?php echo $ckPlain($folio_fnb['total']); ?> accrued
                            (food <?php echo $ckPlain($folio_fnb['food']); ?>,
                            drink <?php echo $ckPlain($folio_fnb['drink']); ?>,
                            other <?php echo $ckPlain($folio_fnb['other']); ?>).
                            Charged to room bookings, so already inside Room revenue &mdash; shown for F&amp;B visibility, not added on top.
                        <?php endif; ?>
                    </p>
                </details>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($showQuotes): ?>
            <!-- Quotation pipeline -->
            <section class="ck-panel" id="quotation-pipeline">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Quotation pipeline</h2>
                        <p class="ck-panel__sub"><?php echo $quotationConversionRate; ?>% converted &middot; <?php echo $ckPlain($quotationStats['total_value']); ?> quoted</p>
                    </div>
                    <div class="ck-panel__tools"><a class="ck-link" href="quotations.php">View all <i class="fas fa-arrow-right"></i></a></div>
                </header>
                <ul class="ck-funnel">
                    <li><div class="ck-funnel__row js-acct-insight-trigger" title="Total quotations issued in this period" <?php echo $ckTrig('quotation-total', 'Quotation Volume Overview'); ?> aria-label="Open quotation volume overview">
                        <span class="ck-funnel__name">Issued<small><?php echo $ckPlain($quotationStats['total_value']); ?> quoted</small></span>
                        <span class="ck-funnel__num"><?php echo (int)$quotationStats['total']; ?></span>
                        <span class="ck-meter"><span style="width:100%"></span></span>
                    </div></li>
                    <li><div class="ck-funnel__row js-acct-insight-trigger" title="Quotations sent and awaiting response" <?php echo $ckTrig('quotation-sent', 'Open Quotations Follow-up'); ?> aria-label="Open sent quotations follow-up">
                        <span class="ck-funnel__name">Active / sent<small><?php echo $ckPlain($quotationStats['sent_value']); ?> outstanding</small></span>
                        <span class="ck-funnel__num"><?php echo (int)$quotationStats['sent']; ?></span>
                        <span class="ck-meter ck-meter--info"><span style="width:<?php echo round((int)$quotationStats['sent'] / $qTotal * 100); ?>%"></span></span>
                    </div></li>
                    <li><div class="ck-funnel__row js-acct-insight-trigger" title="Quotations accepted by the <?php echo $ckH($acct_party); ?>" <?php echo $ckTrig('quotation-accepted', 'Accepted Quotations Performance'); ?> aria-label="Open accepted quotations performance">
                        <span class="ck-funnel__name">Accepted<small><?php echo $ckPlain($quotationStats['accepted_value']); ?></small></span>
                        <span class="ck-funnel__num"><?php echo (int)$quotationStats['accepted']; ?></span>
                        <span class="ck-meter ck-meter--good"><span style="width:<?php echo round((int)$quotationStats['accepted'] / $qTotal * 100); ?>%"></span></span>
                    </div></li>
                    <li><div class="ck-funnel__row js-acct-insight-trigger" title="Quotations that expired or were declined" <?php echo $ckTrig('quotation-expired-declined', 'Expired & Declined Quotations'); ?> aria-label="Open expired and declined quotations detail">
                        <span class="ck-funnel__name">Expired / declined<small>Conversion <?php echo $quotationConversionRate; ?>%</small></span>
                        <span class="ck-funnel__num"><?php echo $quotationExpiredDeclinedCount; ?></span>
                        <span class="ck-meter ck-meter--red"><span style="width:<?php echo round($quotationExpiredDeclinedCount / $qTotal * 100); ?>%"></span></span>
                    </div></li>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($showCN): ?>
            <!-- Credit notes -->
            <section class="ck-panel" id="credit-note-summary">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">Credit notes</h2>
                        <p class="ck-panel__sub">Outstanding liability and redemption.</p>
                    </div>
                    <div class="ck-panel__tools"><a class="ck-link" href="credit-notes.php">Manage <i class="fas fa-arrow-right"></i></a></div>
                </header>
                <div class="ck-money__headline">
                    <span class="ck-money__label">Unredeemed liability</span>
                    <span class="ck-money__value"><?php echo $ckMoney($cnStats['total_outstanding']); ?></span>
                </div>
                <ul class="ck-kv">
                    <li><div class="js-acct-insight-trigger" title="Number and face value of credit notes issued in this period" <?php echo $ckTrig('cn-issued', 'Credit Notes Issued'); ?> aria-label="Open credit notes issued details"><span><i class="fas fa-file-invoice"></i> <?php echo (int)$cnStats['count_issued']; ?> issued</span><strong><?php echo $ckPlain($cnStats['total_issued']); ?></strong></div></li>
                    <li><div class="js-acct-insight-trigger" title="Value of credit notes redeemed against bookings" <?php echo $ckTrig('cn-redeemed', 'Credit Notes Redeemed'); ?> aria-label="Open credit notes redeemed details"><span><i class="fas fa-check"></i> Redeemed</span><strong><?php echo $ckPlain($cnStats['total_redeemed']); ?></strong></div></li>
                    <li><div class="js-acct-insight-trigger" title="Value <?php echo $ckH($acct_party); ?>s can still redeem" <?php echo $ckTrig('cn-outstanding', 'Credit Notes Outstanding Liability'); ?> aria-label="Open credit notes outstanding liability details"><span><i class="fas fa-hourglass-half"></i> Outstanding</span><strong><?php echo $ckPlain($cnStats['total_outstanding']); ?></strong></div></li>
                </ul>
            </section>
            <?php endif; ?>

            <!-- ============ DETAIL TABS ============ -->
            <section class="ck-panel ck-span-3" data-ck-tabs id="acct-detail">
                <header class="ck-panel__head">
                    <div>
                        <h2 class="ck-panel__title">The detail</h2>
                        <p class="ck-panel__sub">Every line behind the numbers above.</p>
                    </div>
                    <div class="ck-tabs" role="tablist">
                        <?php $firstTab = true; foreach ($ckTabs as $key => [$label, $n]): ?>
                            <button type="button" class="ck-tab<?php echo $firstTab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $firstTab ? 'true' : 'false'; ?>" data-ck-tab="<?php echo $ckH($key); ?>">
                                <?php echo $ckH($label); ?> <span class="ck-tab__count"><?php echo (int)$n; ?></span>
                            </button>
                        <?php $firstTab = false; endforeach; ?>
                    </div>
                </header>

                <!-- Recent payments -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="payments">
                    <div class="ck-table-wrap ck-table-wrap--tall">
                        <table class="no-card-mobile no-scroll ck-table ck-table--stack">
                            <thead>
                                <tr>
                                    <th>Reference</th><th>Booking</th><th>Type</th><th>Date</th><th class="num">Amount</th><th>Method</th><th>Status</th><th><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">View</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentPayments)): ?>
                                    <tr><td colspan="8" class="ck-table__empty"><i class="fas fa-inbox"></i> No payments recorded in this period.</td></tr>
                                <?php else: foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo $ckH($payment['payment_reference']); ?></strong></td>
                                        <td data-label="Booking"><?php echo $ckH($payment['booking_description']); ?></td>
                                        <td data-label="Type"><span class="ck-pill ck-pill--muted"><?php echo $ckH(ucfirst((string)$payment['booking_type'])); ?></span></td>
                                        <td data-label="Date"><?php echo $ckH(date('M j, Y', strtotime($payment['payment_date']))); ?> <small><?php echo $ckH(date('H:i', strtotime($payment['payment_date']))); ?></small></td>
                                        <td class="num" data-label="Amount"><strong><?php echo $ckPlain($payment['total_amount']); ?></strong><?php if ((float)$payment['vat_amount'] > 0): ?><small>incl. VAT</small><?php endif; ?></td>
                                        <td data-label="Method"><?php echo $ckH(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td>
                                        <?php
                                        $pst = (string)$payment['payment_status'];
                                        $pstTone = in_array($pst, ['completed', 'paid'], true) ? 'green' : (in_array($pst, ['pending', 'partial'], true) ? 'amber' : (in_array($pst, ['failed', 'cancelled'], true) ? 'red' : 'muted'));
                                        ?>
                                        <td data-label="Status"><span class="ck-pill ck-pill--<?php echo $pstTone; ?>"><?php echo $ckH(ucfirst(str_replace('_', ' ', $pst))); ?></span></td>
                                        <td><a class="ck-table__cell-link" href="payment-details.php?id=<?php echo (int)$payment['id']; ?>" aria-label="View payment <?php echo $ckH($payment['payment_reference']); ?>"><i class="fas fa-eye"></i></a></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($recentPayments) >= 20): ?>
                        <p style="margin:12px 0 0"><a href="payments.php" class="ck-btn ck-btn--primary">View all payments <i class="fas fa-arrow-right"></i></a></p>
                    <?php endif; ?>
                </div>

                <!-- Receivables -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="receivables" hidden>
                    <div class="ck-table-wrap">
                        <table class="no-card-mobile no-scroll ck-table">
                            <thead><tr><th>Source</th><th class="num">Open</th><th class="num">Amount due</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php if (!$recvRows): ?>
                                    <tr><td colspan="4" class="ck-table__empty"><i class="fas fa-check-circle"></i> All booking balances settled.</td></tr>
                                <?php else: foreach ($recvRows as $os):
                                    $linkBase = $os['type'] === 'room' ? 'bookings.php?payment_status=unpaid' : 'conference-management.php?payment_status=pending';
                                ?>
                                    <tr>
                                        <td><strong><?php echo $ckH(ucfirst((string)$os['type'])); ?> bookings</strong></td>
                                        <td class="num"><?php echo (int)$os['count']; ?></td>
                                        <td class="num"><strong><?php echo $ckPlain($os['total_outstanding']); ?></strong></td>
                                        <td><a href="<?php echo $ckH($linkBase); ?>" class="ck-link">View <i class="fas fa-arrow-right"></i></a></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Daily detail -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="daily" hidden>
                    <div class="ck-table-wrap ck-table-wrap--tall">
                        <table class="no-card-mobile no-scroll ck-table ck-table--stack">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <?php if ($mod_bookings): ?><th class="num" title="Room booking payments received on this day">Rooms</th><?php endif; ?>
                                    <?php if ($mod_conference): ?><th class="num" title="Conference and events payments received on this day">Conference</th><?php endif; ?>
                                    <?php if ($mod_pos): ?><th class="num"><?php echo $ckH(rh_pos_short_label()); ?></th><?php endif; ?>
                                    <?php if ($mod_gym): ?><th class="num">Gym</th><?php endif; ?>
                                    <?php if ($mod_events): ?><th class="num">Events</th><?php endif; ?>
                                    <th class="num" title="Refunds issued on this day (subtracted from Net Total)">Refunds</th>
                                    <th class="num" title="Net Total - all revenue sources combined, minus refunds">Net total</th>
                                    <th class="num" title="Number of individual payment records on this day">Txns</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dailyTrend)): ?>
                                    <tr><td colspan="9" class="ck-table__empty">No payments in the last 14 days of this period.</td></tr>
                                <?php else: foreach ($dailyTrend as $d):
                                    $net = (float)$d['room_rev'] + (float)$d['conf_rev'] + (float)$d['fnb_rev'] + (float)$d['gym_rev'] + (float)$d['events_rev'] - (float)$d['refunds'];
                                ?>
                                    <tr>
                                        <td data-label="Date"><strong><?php echo $ckH(date('D, M j', strtotime($d['day']))); ?></strong> <small><?php echo $ckH(date('Y', strtotime($d['day']))); ?></small></td>
                                        <?php if ($mod_bookings): ?><td class="num" data-label="Rooms"><?php echo $ckPlain($d['room_rev']); ?></td><?php endif; ?>
                                        <?php if ($mod_conference): ?><td class="num" data-label="Conference"><?php echo $ckPlain($d['conf_rev']); ?></td><?php endif; ?>
                                        <?php if ($mod_pos): ?><td class="num" data-label="<?php echo $ckH(rh_pos_short_label()); ?>"><?php echo $ckPlain($d['fnb_rev']); ?></td><?php endif; ?>
                                        <?php if ($mod_gym): ?><td class="num" data-label="Gym"><?php echo $ckPlain($d['gym_rev']); ?></td><?php endif; ?>
                                        <?php if ($mod_events): ?><td class="num" data-label="Events"><?php echo $ckPlain($d['events_rev']); ?></td><?php endif; ?>
                                        <td class="num" data-label="Refunds"><?php echo (float)$d['refunds'] > 0 ? '&minus;' . $ckPlain($d['refunds']) : '&mdash;'; ?></td>
                                        <td class="num" data-label="Net total"><strong><?php echo $ckPlain($net); ?></strong></td>
                                        <td class="num" data-label="Txns"><?php echo (int)$d['txn_count']; ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!empty($refundReasons)): ?>
                <!-- Refunds by reason -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="refunds" hidden>
                    <p class="ck-panel__sub" style="margin:0 0 10px">Total refunds issued: <strong><?php echo $ckPlain($cat_refunds); ?></strong><?php if ($cat_pending_refunds > 0): ?> &middot; pending <?php echo $ckPlain($cat_pending_refunds); ?><?php endif; ?></p>
                    <div class="ck-table-wrap">
                        <table class="no-card-mobile no-scroll ck-table">
                            <thead><tr><th>Reason</th><th class="num">Count</th><th class="num">Total</th><th class="num">% of refunds</th></tr></thead>
                            <tbody>
                                <?php foreach ($refundReasons as $reason):
                                    $percentage = $totalRefundAmount > 0 ? ((float)$reason['total_amount'] / $totalRefundAmount) * 100 : 0;
                                ?>
                                    <tr>
                                        <td><?php echo $ckH($reasonLabels[$reason['refund_reason']] ?? ucfirst(str_replace('_', ' ', (string)$reason['refund_reason']))); ?></td>
                                        <td class="num"><?php echo (int)$reason['count']; ?></td>
                                        <td class="num"><strong><?php echo $ckPlain($reason['total_amount']); ?></strong></td>
                                        <td class="num"><span class="ck-meter ck-meter--red"><span style="width:<?php echo number_format($percentage, 1, '.', ''); ?>%"></span></span><?php echo number_format($percentage, 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showPos): ?>
                <!-- POS by order type -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="pos" hidden>
                    <div class="ck-table-wrap">
                        <table class="no-card-mobile no-scroll ck-table ck-table--stack">
                            <thead>
                                <tr>
                                    <th>Order type</th>
                                    <th class="num" title="Number of paid or completed orders">Orders</th>
                                    <th class="num" title="Gross revenue - completed sales before any costs">Gross</th>
                                    <th class="num" title="COGS - ingredient/stock cost of items sold, recorded at order time">COGS</th>
                                    <th class="num" title="Gross profit - revenue minus COGS">Margin</th>
                                    <th class="num" title="Margin as a percentage of revenue">Margin %</th>
                                    <th class="num" title="Voided orders - count and value">Voids</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($posByType)): ?>
                                    <tr><td colspan="7" class="ck-table__empty">No POS activity in this period.</td></tr>
                                <?php else: foreach ($posByType as $ot):
                                    $g = (float)$ot['gross_revenue'];
                                    $c = (float)$ot['cogs'];
                                    $m = $g - $c;
                                    $mpct = $g > 0 ? ($m / $g) * 100 : 0;
                                    $label = $order_type_labels[$ot['order_type']] ?? ucwords(str_replace('_', ' ', (string)$ot['order_type']));
                                ?>
                                    <tr>
                                        <td data-label="Order type"><?php echo $ckH($label); ?></td>
                                        <td class="num" data-label="Orders"><?php echo number_format((int)$ot['order_count']); ?></td>
                                        <td class="num" data-label="Gross"><?php echo $ckPlain($g); ?></td>
                                        <td class="num" data-label="COGS"><?php echo $ckPlain($c); ?></td>
                                        <td class="num" data-label="Margin"><strong><?php echo $ckPlain($m); ?></strong></td>
                                        <td class="num" data-label="Margin %"><?php echo number_format($mpct, 1); ?>%</td>
                                        <td class="num" data-label="Voids">
                                            <?php if ((int)$ot['voided_count'] > 0): ?>
                                                <span class="ck-pill ck-pill--red"><?php echo (int)$ot['voided_count']; ?> &middot; <?php echo $ckPlain($ot['voided_amount']); ?></span>
                                            <?php else: ?>&mdash;<?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Compliance checks -->
                <div class="ck-pane" role="tabpanel" data-ck-pane="compliance" hidden>
                    <p class="ck-panel__sub" style="margin:0 0 10px">Receipt, invoice, POS ledger and MRA-readiness gaps for the selected period.</p>
                    <div class="ck-table-wrap">
                        <table class="no-card-mobile no-scroll ck-table">
                            <thead><tr><th>Check</th><th class="num">Count</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($complianceAll as $row):
                                    $ckFail = $row['warn'] && (int)$row['count'] > 0; ?>
                                    <tr>
                                        <td><button type="button" class="ck-linkbtn js-acct-insight-trigger" data-insight-key="<?php echo $ckH($row['key']); ?>" data-insight-title="<?php echo $ckH($row['insight_title']); ?>"><?php echo $ckH($row['label']); ?></button></td>
                                        <td class="num"><strong><?php echo number_format((int)$row['count']); ?></strong></td>
                                        <td><?php if (!$row['warn']): ?><span class="ck-pill ck-pill--info">Baseline</span><?php elseif ($ckFail): ?><span class="ck-pill ck-pill--red">Review required</span><?php else: ?><span class="ck-pill ck-pill--green">Pass</span><?php endif; ?></td>
                                        <td><a href="<?php echo $ckH($row['action_link']); ?>" class="ck-link"><?php echo $ckH($row['action_label']); ?> <i class="fas fa-arrow-right"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </div><!-- /.ck-bento -->

        <?php /* VAT is set up once and rarely changed, so it sits folded at the foot of the page.
                 It opens after a save (to show the result) or when a link targets #vat-settings. */ ?>
        <details class="ck-panel ck-fold ck-vat" id="vat-settings"<?php echo ($vatSettingsMessage || $vatSettingsError) ? ' open' : ''; ?>>
            <summary>
                <span class="ck-panel__title"><i class="fas fa-percent"></i> VAT settings</span>
                <span class="vat-status-badge <?php echo $vatEnabled ? 'vat-status-badge--on' : 'vat-status-badge--off'; ?>">
                    <?php echo $vatEnabled ? 'Enabled @ ' . htmlspecialchars((string)$vatRate) . '%' : 'Disabled'; ?>
                </span>
            </summary>
        <section class="acct-panel acct-panel--vat">
            <p class="ck-panel__sub">Tax configuration affects all future invoices, payments, and MRA reporting. Changes cannot be undone automatically.</p>

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
                        <span class="vat-current-item__label">VAT mode</span>
                        <span class="vat-current-item__value <?php echo $vatModeNow !== 'off' ? 'vat-current-item__value--on' : 'vat-current-item__value--off'; ?>">
                            <?php
                            $vatModeLabels = ['off' => 'No VAT', 'inclusive' => 'Prices include VAT', 'exclusive' => 'VAT added on top of prices'];
                            echo ($vatModeNow !== 'off' ? '<i class="fas fa-toggle-on"></i> ' : '<i class="fas fa-toggle-off"></i> ') . htmlspecialchars($vatModeLabels[$vatModeNow]);
                            ?>
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
                <?php if ($canChangeFinanceSettings): ?>
                <div class="vat-unlock-row">
                    <button type="button" class="ck-btn" id="vatUnlockBtn">
                        <i class="fas fa-lock-open"></i> Unlock to Edit
                    </button>
                    <p class="vat-unlock-hint"><i class="fas fa-triangle-exclamation"></i> Editing VAT settings affects all future invoices, tax calculations, and MRA reports. Proceed with caution.</p>
                </div>
                <?php else: ?>
                <p class="vat-unlock-hint"><i class="fas fa-lock"></i> Only users with the "Change VAT &amp; refund settings" permission can change VAT.</p>
                <?php endif; ?>
            </div>

            <?php if ($canChangeFinanceSettings): ?>
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
                        <div class="vat-field-group vat-field-group--wide">
                            <span class="vat-field-group__label" id="vatModeLegend">VAT mode</span>
                            <div role="radiogroup" aria-labelledby="vatModeLegend" style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                                <?php
                                $vatModeChoices = [
                                    'off'       => ['No VAT', 'No VAT is applied anywhere. Totals equal listed prices.'],
                                    'inclusive' => ['Prices include VAT', 'Totals equal your listed prices. Documents show only the VAT rate, never an amount.'],
                                    'exclusive' => ['VAT added on top of prices', 'VAT is calculated on top of listed prices and itemised on documents.'],
                                ];
                                foreach ($vatModeChoices as $vmKey => $vmInfo): ?>
                                    <label style="display:flex;gap:10px;align-items:flex-start;min-height:44px;cursor:pointer;">
                                        <input type="radio" name="vat_mode" value="<?php echo $vmKey; ?>" <?php echo $vatModeNow === $vmKey ? 'checked' : ''; ?> style="margin-top:4px;">
                                        <span><strong><?php echo htmlspecialchars($vmInfo[0]); ?></strong><br>
                                        <small style="color:#7a6f63;font-size:.74rem;"><?php echo htmlspecialchars($vmInfo[1]); ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="vat-field-group">
                            <label class="vat-field-group__label" for="vat_rate">VAT Rate (%)</label>
                            <input class="vat-field-group__control" type="number" id="vat_rate" name="vat_rate"
                                min="0" max="100" step="0.01"
                                value="<?php echo htmlspecialchars((string)$vatRate); ?>" <?php echo $vatModeNow === 'off' ? 'disabled' : 'required'; ?>>
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
                        <button type="button" class="ck-btn ck-btn--ghost" id="vatCancelBtn">
                            <i class="fas fa-xmark"></i> Cancel
                        </button>
                        <button type="submit" class="ck-btn ck-btn--primary" id="vatSaveBtn">
                            <i class="fas fa-save"></i> Save VAT Settings
                        </button>
                    </div>
                </form>
                <script>
                    (function () {
                        var rate = document.getElementById('vat_rate');
                        document.querySelectorAll('input[name="vat_mode"]').forEach(function (r) {
                            r.addEventListener('change', function () {
                                if (!rate) return;
                                var off = document.querySelector('input[name="vat_mode"]:checked').value === 'off';
                                rate.disabled = off;
                                rate.required = !off;
                            });
                        });
                    })();
                </script>
            </div>
            <?php endif; ?>
        </section>
        </details>

        </div><!-- /.ck -->

        <script>
            (function() {
                var vatDetails = document.getElementById('vat-settings');
                function openVatDetails() { if (vatDetails && !vatDetails.open) vatDetails.open = true; }
                if (location.hash === '#vat-settings') openVatDetails();
                // Delegated: VAT links also live inside insight-modal markup cloned later.
                document.addEventListener('click', function (e) {
                    if (e.target.closest && e.target.closest('a[href="#vat-settings"]')) openVatDetails();
                });
                window.addEventListener('hashchange', function () {
                    if (location.hash === '#vat-settings') openVatDetails();
                });
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
                        // Let nested links/buttons inside a trigger card activate normally.
                        var inner = e.target.closest('a, button');
                        if (inner && inner !== trigger && trigger.contains(inner)) return;

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

        <script>
            // Cockpit interactions: detail tabs, daily-revenue readout, jump-to-tab links
            (function () {
                'use strict';
                function showTab(panel, key) {
                    panel.querySelectorAll('[data-ck-tab]').forEach(function (t) {
                        var on = t.dataset.ckTab === key;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    panel.querySelectorAll('[data-ck-pane]').forEach(function (pane) {
                        pane.hidden = pane.dataset.ckPane !== key;
                    });
                }
                document.querySelectorAll('[data-ck-tabs]').forEach(function (panel) {
                    panel.querySelectorAll('[data-ck-tab]').forEach(function (tab) {
                        tab.addEventListener('click', function () { showTab(panel, tab.dataset.ckTab); });
                    });
                });
                document.querySelectorAll('[data-ck-goto]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var panel = document.getElementById('acct-detail');
                        if (!panel || !panel.querySelector('[data-ck-pane="' + btn.dataset.ckGoto + '"]')) return;
                        showTab(panel, btn.dataset.ckGoto);
                        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                });
                document.querySelectorAll('.ck-money').forEach(function (box) {
                    var label = box.querySelector('[data-ck-trend-label]');
                    var value = box.querySelector('[data-ck-trend-value]');
                    var cols = box.querySelectorAll('[data-ck-trend]');
                    cols.forEach(function (col) {
                        function show() {
                            cols.forEach(function (c) { c.classList.toggle('is-active', c === col); });
                            if (label) label.textContent = col.dataset.ckTrendDay;
                            if (value) value.textContent = col.dataset.ckTrend;
                        }
                        col.addEventListener('mouseenter', show);
                        col.addEventListener('focus', show);
                        col.addEventListener('click', show);
                    });
                });
            })();
        </script>

    </div><!-- /.content -->

    <?php require_once 'includes/admin-footer.php'; ?>


