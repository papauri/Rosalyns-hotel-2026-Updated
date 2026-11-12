<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../includes/alert.php';
require_once 'includes/finance-schema.php';
require_once 'includes/finance-filters.php';
require_once __DIR__ . '/includes/booking-lifecycle.php';
require_once __DIR__ . '/includes/finance-account-sync.php';
require_once __DIR__ . '/includes/receivables.php';
require_once __DIR__ . '/includes/account-receipt-send.php';
require_once __DIR__ . '/../includes/quotation-convert.php'; // "Made from quotation ..." links
$message = '';
$error = '';
$csrf_token = $csrf_token ?? generateCsrfToken();
$conferenceFields = finance_conference_fields($pdo);
// Links to other finance pages show only to users who can open them (page map in admin/includes/permissions.php):
// Invoices access alone does not open the Payments ledger or its per-account owed list, so it gets no link to them.
$invCanPayments   = hasPermission((int)($user['id'] ?? 0), 'payments');
$invCanPaymentAdd = hasPermission((int)($user['id'] ?? 0), 'payment_add');
$invCanAccounting = hasPermission((int)($user['id'] ?? 0), 'accounting');

// ── AJAX mode ────────────────────────────────────────────────────────────────
// Inline email actions (resend_invoice, send_reminder) POST with _ajax=1 so
// they return JSON instead of doing a full page navigation. This prevents the
// admin page loader from getting stuck while SMTP is processing.
$is_ajax = !empty($_POST['_ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Handle invoice actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token invalid. Refresh and try again.');
        }

        $action = $_POST['action'];
        $payment_id = (int)($_POST['payment_id'] ?? 0);

        if ($action === 'resend_invoice' && $payment_id > 0) {
            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }

            // Lifecycle guard
            if ($payment['booking_type'] === 'room') {
                $lcStmt = $pdo->prepare("SELECT status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
                $lcStmt->execute([$payment['booking_id']]);
                $lcRow = $lcStmt->fetch(PDO::FETCH_ASSOC);
                if ($lcRow) {
                    $lcCheck = bookingAllowsAction($lcRow, 'send_invoice');
                    if (!$lcCheck['allowed']) {
                        throw new Exception($lcCheck['reason']);
                    }
                }
            }

            // Resend invoice email based on booking type
            require_once '../config/invoice.php';

            if ($payment['booking_type'] === 'room') {
                $result = sendPaymentInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'conference') {
                $result = sendConferenceInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'gym') {
                $result = sendGymInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'event') {
                $result = sendEventInvoiceEmail($payment['booking_id']);
            } else {
                $result = ['success' => false, 'message' => 'Invoice email is not supported for ' . htmlspecialchars($payment['booking_type'], ENT_QUOTES, 'UTF-8') . ' payments.'];
            }

            if ($result['success']) {
                $message = 'Invoice resent successfully!';
            } else {
                $error = 'Failed to resend invoice: ' . $result['message'];
            }
        }

        if ($action === 'send_reminder' && $payment_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }

            // Lifecycle guard for payment reminders
            if ($payment['booking_type'] === 'room') {
                $lcStmt = $pdo->prepare("SELECT status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
                $lcStmt->execute([$payment['booking_id']]);
                $lcRow = $lcStmt->fetch(PDO::FETCH_ASSOC);
                if ($lcRow) {
                    $lcCheck = bookingAllowsAction($lcRow, 'send_invoice');
                    if (!$lcCheck['allowed']) {
                        throw new Exception($lcCheck['reason']);
                    }
                }
            }

            require_once '../config/invoice.php';

            if ($payment['booking_type'] === 'room') {
                $result = sendPaymentInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'conference') {
                $result = sendConferenceInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'gym') {
                $result = sendGymInvoiceEmail($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'event') {
                $result = sendEventInvoiceEmail($payment['booking_id']);
            } else {
                $result = ['success' => false, 'message' => 'Reminder email is not supported for ' . htmlspecialchars($payment['booking_type'], ENT_QUOTES, 'UTF-8') . ' payments.'];
            }

            if ($result['success']) {
                $message = 'Payment reminder email sent successfully!';
            } else {
                $error = 'Failed to send reminder: ' . $result['message'];
            }
        }
        if ($action === 'regenerate_invoice' && $payment_id > 0) {
            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }

            // Lifecycle guard — allow regenerate on checked-out (final invoice), block on tentative/cancelled/no-show
            if ($payment['booking_type'] === 'room') {
                $lcStmt = $pdo->prepare("SELECT status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
                $lcStmt->execute([$payment['booking_id']]);
                $lcRow = $lcStmt->fetch(PDO::FETCH_ASSOC);
                if ($lcRow) {
                    $lcCheck = bookingAllowsAction($lcRow, 'generate_invoice');
                    if (!$lcCheck['allowed']) {
                        throw new Exception($lcCheck['reason']);
                    }
                }
            }

            // Regenerate invoice based on booking type
            require_once '../config/invoice.php';

            // Regenerating keeps the invoice number already issued on this payment (never
            // burns a new number from the sequence). Refund rows hold a credit-note number there.
            $reuseInvoiceNumber = ($payment['payment_type'] ?? '') !== 'refund' && trim((string)($payment['invoice_number'] ?? '')) !== ''
                ? trim((string)$payment['invoice_number']) : null;
            if ($payment['booking_type'] === 'room') {
                $result = generateInvoicePDF($payment['booking_id'], $reuseInvoiceNumber);
            } elseif ($payment['booking_type'] === 'conference') {
                $result = generateConferenceInvoicePDF($payment['booking_id'], $reuseInvoiceNumber);
            } elseif ($payment['booking_type'] === 'gym') {
                $result = generateGymInvoicePDF($payment['booking_id'], $reuseInvoiceNumber);
            } elseif ($payment['booking_type'] === 'event') {
                $result = generateEventInvoicePDF($payment['booking_id'], $reuseInvoiceNumber);
            } else {
                throw new Exception('Invoice regeneration is not supported for ' . htmlspecialchars($payment['booking_type'], ENT_QUOTES, 'UTF-8') . ' payments.');
            }

            if ($result) {
                // Update payment record with new invoice path
                // invoice_number is UNIQUE: when the account's number already sits on another
                // payment row (one invoice per account), only refresh this row's file path.
                $numTaken = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_number = ? AND id <> ?");
                $numTaken->execute([$result['invoice_number'], $payment_id]);
                if ((int)$numTaken->fetchColumn() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE payments SET invoice_path = ?, invoice_generated = 1 WHERE id = ?");
                    $update_stmt->execute([$result['relative_path'], $payment_id]);
                } else {
                    $update_stmt = $pdo->prepare("
                        UPDATE payments
                        SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
                        WHERE id = ?
                    ");
                    $update_stmt->execute([
                        $result['relative_path'],
                        $result['invoice_number'],
                        $payment_id
                    ]);
                }

                $message = 'Invoice regenerated successfully!';
            } else {
                $error = 'Failed to regenerate invoice';
            }
        }

        if ($action === 'generate_credit_note' && $payment_id > 0) {
            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }

            // Lifecycle guard
            if ($payment['booking_type'] === 'room') {
                $lcStmt = $pdo->prepare("SELECT status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
                $lcStmt->execute([$payment['booking_id']]);
                $lcRow = $lcStmt->fetch(PDO::FETCH_ASSOC);
                if ($lcRow) {
                    $lcCheck = bookingAllowsAction($lcRow, 'generate_credit_note');
                    if (!$lcCheck['allowed']) {
                        throw new Exception($lcCheck['reason']);
                    }
                }
            }

            if ($payment['payment_type'] !== 'refund') {
                throw new Exception('Credit notes can only be generated for refund payments.');
            }

            // Credit note number comes from the credit_notes sequence (never invented), and
            // is only written when the payment has none yet — under a row lock, so a double
            // click returns the same number instead of burning a second one.
            require_once __DIR__ . '/../includes/finance-sequences.php';
            $pdo->beginTransaction();
            try {
                $cnLock = $pdo->prepare("SELECT invoice_number FROM payments WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
                $cnLock->execute([$payment_id]);
                $existingCnNumber = trim((string)($cnLock->fetchColumn() ?: ''));
                if ($existingCnNumber !== '') {
                    $creditNoteNumber = $existingCnNumber;
                } else {
                    $creditNoteNumber = finance_next_credit_note_number($pdo, (string)($payment['payment_date'] ?? date('Y-m-d')));
                    $pdo->prepare("
                        UPDATE payments
                        SET invoice_number = ?, invoice_generated = 1
                        WHERE id = ? AND (invoice_number IS NULL OR invoice_number = '')
                    ")->execute([$creditNoteNumber, $payment_id]);
                }
                $pdo->commit();
            } catch (Throwable $cnEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $cnEx;
            }

            $message = 'Credit note generated successfully! Number: ' . $creditNoteNumber;
        }
    } catch (Throwable $e) {
        // Only this page's own plain Exception messages (not found, lifecycle reason, wrong row type) are shown;
        // database and other system errors get a generic line on screen and the detail in System Logs.
        if (get_class($e) === 'Exception') {
            $error = $e->getMessage();
        } else {
            rh_log_event('admin/invoices', 'error', 'Invoice action failed', [
                'action'     => is_string($_POST['action'] ?? null) ? $_POST['action'] : '',
                'payment_id' => (int)($_POST['payment_id'] ?? 0),
                'error'      => $e->getMessage(),
            ]);
            $error = 'That action could not be completed. Please try again; if it keeps happening, check System Logs.';
        }
    }

    // AJAX: return JSON immediately (no page render)
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($message) {
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            echo json_encode(['success' => false, 'message' => $error ?: 'An unexpected error occurred.']);
        }
        exit;
    }
}

// Get filter parameters
// Text-only reads (an array such as ?search[]=x is ignored): admin/includes/finance-filters.php.
$filter_type = rh_fin_get_str($_GET, 'filter_type', 'all');
$filter_type = $filter_type === '' ? 'all' : $filter_type;
$filter_status = rh_fin_get_str($_GET, 'filter_status', 'all');
$filter_status = $filter_status === '' ? 'all' : $filter_status;
$search = rh_fin_get_str($_GET, 'search');

// Preset scoping: default list shows invoices for enabled modules only.
// Explicit ?filter_type= and ?scope=all bypass; history is never deleted.
$scopeAll            = (rh_fin_get_str($_GET, 'scope') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
$scopeActive         = $filter_type === 'all' && !$scopeAll
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];

// ONE filter set for the cards and the list (admin/includes/finance-filters.php): the paid / refunded /
// generated cards count exactly the invoice rows listed for the same type, status, search and module scope.
// The search reads the customer name/email joins, so the cards select their rows through the same joins
// ($invCardWhere) and keep their own unqualified payment columns.
$invFilter = rh_fin_invoices_where([
    'type'        => $filter_type,
    'scope_types' => $scopeActive ? $enabledBookingTypes : [],
    'status'      => $filter_status,
    'search'      => $search,
], $conferenceFields);
$params = $invFilter['params'];
$invCardWhere = 'WHERE id IN (SELECT p.id ' . rh_fin_invoices_from() . ' ' . $invFilter['sql'] . ')';

// Fetch invoices with payment details
try {
    $sql = "
        SELECT p.*,
               CASE
                   WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', r.name, ')')
                   WHEN p.booking_type = 'conference' THEN CONCAT(ci.{$conferenceFields['company']}, ' (', cr.name, ')')
                   WHEN p.booking_type = 'gym' THEN CONCAT(gi.name, ' (', gi.reference_number, ')')
                   WHEN p.booking_type = 'event' THEN CONCAT(ei.name, ' (', ei.reference_number, ')')
                   ELSE 'Unknown'
               END as customer_name,
               CASE
                   WHEN p.booking_type = 'room' THEN b.guest_email
                   WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
                   WHEN p.booking_type = 'gym' THEN gi.email
                   WHEN p.booking_type = 'event' THEN ei.email
                   ELSE NULL
               END as customer_email,
               CASE
                   WHEN p.booking_type = 'room' THEN b.guest_phone
                   WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['phone']}
                   WHEN p.booking_type = 'gym' THEN gi.phone
                   WHEN p.booking_type = 'event' THEN ei.phone
                   ELSE NULL
               END as customer_phone
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN rooms r ON p.booking_type = 'room' AND b.room_id = r.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN conference_rooms cr ON p.booking_type = 'conference' AND ci.conference_room_id = cr.id
        LEFT JOIN gym_inquiries gi ON p.booking_type = 'gym' AND p.booking_id = gi.id
        LEFT JOIN event_inquiries ei ON p.booking_type = 'event' AND p.booking_id = ei.id";

    $sql .= ' ' . $invFilter['sql'];

    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Invoices made from a quotation (Quotations > Convert to invoice): invoice number -> quotation reference.
    $invFromQuote = [];
    if (rh_qc_columns_present($pdo)) {
        $invNums = array_values(array_unique(array_filter(array_map(static function ($r) {
            return trim((string)($r['invoice_number'] ?? ''));
        }, $invoices))));
        foreach (array_chunk($invNums, 500) as $invChunk) {
            $qPh = implode(',', array_fill(0, count($invChunk), '?'));
            $qSt = $pdo->prepare("SELECT converted_invoice_number, quote_reference FROM quotations WHERE converted_invoice_number IN ($qPh) ORDER BY converted_at ASC");
            $qSt->execute($invChunk);
            foreach ($qSt->fetchAll(PDO::FETCH_ASSOC) as $qRow) {
                $invFromQuote[(string)$qRow['converted_invoice_number']] = $invFromQuote[(string)$qRow['converted_invoice_number']] ?? (string)$qRow['quote_reference'];
            }
        }
    }

    // Invoices made from quotations that no payment row carries yet (nothing paid on them): their own list below the
    // main one. Read-only; not part of any card, total or report. Payment statuses do not apply, so only "All Statuses".
    $invUnpaidConv = [];
    if ($filter_status === 'all') {
        try {
            $invUnpaidConv = rh_qc_unpaid_conversions($pdo, $filter_type === 'all' ? '' : $filter_type, $scopeActive ? $enabledBookingTypes : [], $search);
        } catch (Throwable $e) {
            error_log('[invoices] quotation invoices: ' . $e->getMessage());
        }
    }

    // Rows hidden by preset scoping (for the notice above the table)
    $hiddenScopedCount = 0;
    if ($scopeActive && $hiddenBookingTypes !== []) {
        $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
        $hStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND booking_type IN ($hPh)");
        $hStmt->execute($hiddenBookingTypes);
        $hiddenScopedCount = (int)$hStmt->fetchColumn();
    }

    // Get statistics (same rows as the list)
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_invoices,
            COUNT(CASE WHEN invoice_generated = 1 THEN 1 END) as invoices_generated,
            COALESCE(SUM(CASE
                WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount
                WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -total_amount
                ELSE 0 END), 0) as total_revenue
        FROM payments
        {$invCardWhere}
    ");
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Rich invoice analytics — paid, refunded, MTD over the listed rows (outstanding/aging below are account-level)
    $invoiceKpiStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN total_amount ELSE 0 END), 0) AS refunded_total,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN 1 ELSE 0 END), 0) AS refunded_count,
            COALESCE(SUM(CASE WHEN YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE()) AND payment_status IN ('completed','paid','refunded','partially_refunded') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS mtd_collected
        FROM payments
        {$invCardWhere}
    ");
    $invoiceKpiStmt->execute($params);
    $invoiceKpi = $invoiceKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Outstanding, aging and outstanding-by-source: the accounts' amount_due through the one shared receivables
    // rule (rooms, confirmed conference / event / gym accounts, tabs), the same as Reports, Dashboard and End of Day.
    $rcv = rh_receivable_summary($pdo, rh_receivable_mods());
    $receivablesFailed = !empty($rcv['failed']);
    $invoiceKpi['outstanding_total'] = $rcv['total'];
    $invoiceKpi['outstanding_count'] = $rcv['count'];
    $aging = [
        'bucket_0_30' => $rcv['buckets']['0-30']['amount'], 'count_0_30' => $rcv['buckets']['0-30']['count'],
        'bucket_31_60' => $rcv['buckets']['31-60']['amount'], 'count_31_60' => $rcv['buckets']['31-60']['count'],
        'bucket_61_90' => $rcv['buckets']['61-90']['amount'], 'count_61_90' => $rcv['buckets']['61-90']['count'],
        'bucket_90p' => $rcv['buckets']['90+']['amount'], 'count_90p' => $rcv['buckets']['90+']['count'],
    ];
    $outstandingBySource = [];
    foreach ($rcv['by_type'] as $srcType => $srcRow) {
        $outstandingBySource[] = ['booking_type' => $srcType, 'cnt' => $srcRow['count'], 'amt' => $srcRow['amount']];
    }
    usort($outstandingBySource, static function (array $x, array $y): int { return $y['amt'] <=> $x['amt']; });
} catch (PDOException $e) {
    // Never show the database error on screen; the detail goes to System Logs.
    rh_log_event('admin/invoices', 'error', 'Invoices list could not be loaded', ['error' => $e->getMessage()]);
    $error = 'The invoices could not be loaded. Please try again; if it keeps happening, check System Logs.';
    $invoices = [];
    $stats = ['total_invoices' => 0, 'invoices_generated' => 0, 'total_revenue' => 0];
    $invoiceKpi = ['paid_total' => 0, 'paid_count' => 0, 'outstanding_total' => 0, 'outstanding_count' => 0, 'refunded_total' => 0, 'refunded_count' => 0, 'mtd_collected' => 0];
    $aging = ['bucket_0_30' => 0, 'count_0_30' => 0, 'bucket_31_60' => 0, 'count_31_60' => 0, 'bucket_61_90' => 0, 'count_61_90' => 0, 'bucket_90p' => 0, 'count_90p' => 0];
    $outstandingBySource = [];
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$totalAging = (float)$aging['bucket_0_30'] + (float)$aging['bucket_31_60'] + (float)$aging['bucket_61_90'] + (float)$aging['bucket_90p'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="invoices-container finance-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">Invoices &amp; Credit Notes</h1>
                <p class="acct-page-header__subtitle">
                    <?php echo number_format(count($invoices)); ?> invoice<?php echo count($invoices) === 1 ? '' : 's'; ?> in view ·
                    <?php echo number_format((int)($stats['invoices_generated'] ?? 0)); ?> generated of <?php echo number_format((int)($stats['total_invoices'] ?? 0)); ?> total
                </p>
            </div>
            <div class="acct-page-header__actions">
                <?php if ($invCanPaymentAdd): ?>
                <a href="payment-add.php" class="acct-quick-action acct-quick-action--accent">
                    <i class="fas fa-plus"></i> New Payment
                </a>
                <?php endif; ?>
                <?php if ($invCanPayments): ?>
                <a href="payments.php" class="acct-quick-action">
                    <i class="fas fa-coins"></i> Payments Ledger
                </a>
                <?php endif; ?>
                <?php if ($invCanAccounting): ?>
                <a href="accounting-dashboard.php" class="acct-quick-action">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="ck-kpis ck-kpis--4">
            <?php /* Each card opens the rows behind its figure (data-href, admin-main.js): Paid and Generated jump to the
                     invoice list below (same filters); Outstanding and Refunded open Payments when the user may see it. */
            $invCardRefundsHref = 'payments.php?' . http_build_query(array_filter(['refund_status' => 'completed,processing', 'booking_type' => $filter_type !== 'all' ? $filter_type : ''], static fn($v) => $v !== '')); ?>
            <div class="ck-kpi ck-kpi--good" data-href="#invoiceList" title="Go to the invoice list">
                <span class="ck-kpi__label">Paid &amp; Closed</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format((float)($invoiceKpi['paid_total'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub"><?php echo number_format((int)($invoiceKpi['paid_count'] ?? 0)); ?> invoices · MTD <?php echo $currency_symbol . number_format((float)($invoiceKpi['mtd_collected'] ?? 0), 0); ?></span>
            </div>
            <div class="ck-kpi ck-kpi--warn"<?php if ($invCanPayments): ?> data-href="payments.php?balance=outstanding" title="Open the accounts owing"<?php endif; ?>>
                <span class="ck-kpi__label">Outstanding</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo !empty($receivablesFailed) ? 'n/a' : number_format((float)($invoiceKpi['outstanding_total'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub">                    <?php echo !empty($receivablesFailed) ? 'some accounts unreadable (System Logs)' : number_format((int)($invoiceKpi['outstanding_count'] ?? 0)) . ' awaiting'; ?><?php if ($invCanPayments): ?> ·
                    <a href="payments.php?balance=outstanding" class="acct-link">view accounts owing</a><?php endif; ?>
                </span>
                <span class="ck-kpi__sub" style="margin-top:0">All accounts owing, not affected by filters</span>
            </div>
            <div class="ck-kpi" data-href="#invoiceList" title="Go to the invoice list">
                <span class="ck-kpi__label">Generated</span>
                <span class="ck-kpi__value"><?php echo number_format((int)($stats['invoices_generated'] ?? 0)); ?></span>
                <span class="ck-kpi__sub">                    of <?php echo number_format((int)($stats['total_invoices'] ?? 0)); ?> ·
                    <?php
                    $genPct = ($stats['total_invoices'] ?? 0) > 0
                        ? (((int)$stats['invoices_generated'] / (int)$stats['total_invoices']) * 100)
                        : 0;
                    echo number_format($genPct, 0) . '% coverage';
                    ?>
                </span>
            </div>
            <div class="ck-kpi ck-kpi--info"<?php if ($invCanPayments): ?> data-href="<?php echo htmlspecialchars($invCardRefundsHref); ?>" title="Open these refunds in Payments"<?php endif; ?>>
                <span class="ck-kpi__label">Refunded / Credit</span>
                <span class="ck-kpi__value ck-kpi__value--money"><span class="ck-cur"><?php echo htmlspecialchars($currency_symbol); ?></span><?php echo number_format((float)($invoiceKpi['refunded_total'] ?? 0), 0); ?></span>
                <span class="ck-kpi__sub"><?php echo number_format((int)($invoiceKpi['refunded_count'] ?? 0)); ?> refund<?php echo (int)($invoiceKpi['refunded_count'] ?? 0) === 1 ? '' : 's'; ?> paid or in progress</span>
            </div>
        </div>

        <?php /* Aging and source splits are analysis (also on Reports > Aging), so they sit
                 folded; the filters and the invoice list come straight after the totals. */ ?>
        <details class="acct-breakdown-details" style="margin-top: 18px;">
            <summary class="acct-vat-details__summary"><i class="fas fa-hourglass-half"></i> Receivables aging and outstanding by source</summary>
            <p class="acct-muted" style="margin: 8px 0 0;">All accounts owing, not affected by filters.</p>
        <!-- Aging buckets + outstanding by source -->
        <div class="acct-grid acct-grid--2">
            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Receivables Aging</h3>
                    <span class="acct-panel__sub">Pending / partial only</span>
                </div>
                <div class="acct-table-wrap">
                    <?php if (!empty($receivablesFailed)): ?><p class="acct-empty" style="padding:14px;">Unavailable &mdash; some figures could not be loaded (see System Logs).</p><?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Bucket</th>
                                <th class="num">Invoices</th>
                                <th class="num">Amount</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $buckets = [
                                ['0–30 days',   (int)$aging['count_0_30'],   (float)$aging['bucket_0_30'],   ''],
                                ['31–60 days',  (int)$aging['count_31_60'],  (float)$aging['bucket_31_60'],  ''],
                                ['61–90 days',  (int)$aging['count_61_90'],  (float)$aging['bucket_61_90'],  'acct-pill--pending'],
                                ['90+ days',    (int)$aging['count_90p'],    (float)$aging['bucket_90p'],    'acct-pill--danger'],
                            ];
                            foreach ($buckets as $b):
                                list($label, $cnt, $amt, $danger) = $b;
                                $share = $totalAging > 0 ? ($amt / $totalAging) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($danger === 'acct-pill--danger'): ?>
                                            <span class="acct-pill acct-pill--danger"><?php echo htmlspecialchars($label); ?></span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($label); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?php echo number_format($cnt); ?></td>
                                    <td class="num"><strong><?php echo $currency_symbol . number_format($amt, 0); ?></strong></td>
                                    <td>
                                        <div class="acct-bar">
                                            <div class="acct-bar__fill <?php echo $danger === 'acct-pill--danger' ? 'acct-bar__fill--danger' : ''; ?>" style="width: <?php echo number_format($share, 1); ?>%;"></div>
                                        </div>
                                        <small class="acct-muted"><?php echo number_format($share, 1); ?>%</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th class="num"><?php echo number_format((int)($aging['count_0_30'] + $aging['count_31_60'] + $aging['count_61_90'] + $aging['count_90p'])); ?></th>
                                <th class="num"><?php echo $currency_symbol . number_format($totalAging, 0); ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Outstanding by Source</h3>
                    <span class="acct-panel__sub">Where the receivables sit</span>
                </div>
                <div class="acct-table-wrap">
                    <?php if (!empty($receivablesFailed)): ?><p class="acct-empty" style="padding:14px;">Unavailable &mdash; some figures could not be loaded (see System Logs).</p><?php else: ?>
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th class="num">Invoices</th>
                                <th class="num">Outstanding</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($outstandingBySource)): ?>
                                <tr>
                                    <td colspan="4" class="acct-empty acct-empty--good" style="text-align:center;"><i class="fas fa-check-circle"></i> All clear — no outstanding invoices</td>
                                </tr>
                                <?php else: foreach ($outstandingBySource as $o):
                                    $label = ['room' => 'Rooms', 'conference' => 'Conferences', 'restaurant' => 'Open tabs (unpaid)'][$o['booking_type']] ?? ucfirst((string)$o['booking_type']);
                                ?>
                                    <tr>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$o['booking_type']); ?>"><?php echo $label; ?></span></td>
                                        <td class="num"><?php echo number_format((int)$o['cnt']); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$o['amt'], 0); ?></strong></td>
                                        <td>
                                            <?php if ($invCanPayments): ?>
                                            <a class="acct-link" href="payments.php?balance=outstanding&amp;booking_type=<?php echo urlencode((string)$o['booking_type']); ?>">View →</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </details>

        <!-- Filters -->
        <div class="filters-card" style="margin-top: 18px;">
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="filter-group">
                        <?php $inv_mod_bookings = function_exists('moduleEnabled') && moduleEnabled('bookings');
                              $inv_mod_conf     = function_exists('moduleEnabled') && moduleEnabled('conference');
                              $inv_mod_gym      = function_exists('moduleEnabled') && moduleEnabled('gym');
                              $inv_mod_events   = function_exists('isEventsEnabled') && isEventsEnabled(); ?>
                        <label><?php echo $inv_mod_bookings ? 'Booking Type' : 'Invoice Type'; ?></label>
                        <select name="filter_type">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <?php if ($inv_mod_bookings || $filter_type === 'room'): ?><option value="room" <?php echo $filter_type === 'room' ? 'selected' : ''; ?>>Room Bookings</option><?php endif; ?>
                            <?php if ($inv_mod_conf || $filter_type === 'conference'): ?><option value="conference" <?php echo $filter_type === 'conference' ? 'selected' : ''; ?>>Conference Bookings</option><?php endif; ?>
                            <?php if ($inv_mod_gym || $filter_type === 'gym'): ?><option value="gym" <?php echo $filter_type === 'gym' ? 'selected' : ''; ?>>Gym Memberships</option><?php endif; ?>
                            <?php if ($inv_mod_events || $filter_type === 'event'): ?><option value="event" <?php echo $filter_type === 'event' ? 'selected' : ''; ?>>Event Bookings</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Payment Status</label>
                        <select name="filter_status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="refunded" <?php echo $filter_status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Invoice #, Payment #, Booking Ref, guest name or email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="invoices.php" class="btn-action" style="background: #6c757d; color: white;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <?php
        $scopeQs = $_GET;
        unset($scopeQs['scope']);
        if ($scopeActive && ($hiddenScopedCount ?? 0) > 0): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-filter" style="margin-right:6px;"></i>Showing invoices for your active modules only (<?php echo number_format($hiddenScopedCount); ?> older record<?php echo (int)$hiddenScopedCount === 1 ? '' : 's'; ?> from disabled modules hidden).</span>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $filter_type === 'all'): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full invoice history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#7E684B; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Invoices Table -->
        <div class="invoices-table" id="invoiceList" data-rh-results>
            <?php if (!empty($invoices)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Payment #</th>
                                <th>Booking Ref</th>
                                <th>Type</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Invoice</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?></strong>
                                        <?php $invQuoteRef = $invFromQuote[trim((string)($invoice['invoice_number'] ?? ''))] ?? ''; ?>
                                        <?php if ($invQuoteRef !== ''): ?>
                                            <br><small style="color: #666;" title="A quotation was converted to this invoice with Convert to invoice on the Quotations page.">Linked to quotation <?php echo htmlspecialchars($invQuoteRef); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($invoice['payment_reference']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['booking_reference']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars((string)$invoice['booking_type']); ?>">
                                            <?php echo htmlspecialchars(ucfirst((string)$invoice['booking_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($invoice['customer_name'] ?? 'N/A'); ?>
                                        <?php if ($invoice['customer_email']): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($invoice['customer_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($invoice['payment_date'])); ?>
                                    </td>
                                    <td>
                                        <strong style="color: var(--gold); font-size: 16px;">
                                            <?php echo getSetting('currency_symbol'); ?> <?php echo number_format($invoice['total_amount'], 0); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars((string)$invoice['payment_status']); ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$invoice['payment_status']))); ?>
                                        </span>
                                        <?php if ($invoice['payment_type'] === 'refund'): ?>
                                            <br><small style="color: var(--finance-danger);">Refund</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($invoice['payment_type'] === 'refund'): ?>
                                            <?php if ($invoice['invoice_generated']): ?>
                                                <span class="badge" style="background: #fef2f2; color: #991b1b; border-color: #fecaca;">
                                                    <i class="fas fa-file-invoice"></i> Credit Note
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">
                                                    <i class="fas fa-clock"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($invoice['invoice_generated']): ?>
                                                <span class="badge badge-generated">
                                                    <i class="fas fa-check"></i> Generated
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">
                                                    <i class="fas fa-clock"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- View generated document -->
                                            <?php if ($invoice['invoice_path'] && file_exists(__DIR__ . '/../' . $invoice['invoice_path'])): ?>
                                                <a href="document-download.php?type=invoice&amp;id=<?php echo (int)$invoice['id']; ?>"
                                                    target="_blank"
                                                    class="btn-action btn-view"
                                                    title="<?php echo $invoice['payment_type'] === 'refund' ? 'View Credit Note' : 'View Invoice'; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>

                                            <!-- Link to underlying booking -->
                                            <?php if (!empty($invoice['booking_id'])): ?>
                                                <?php if ($invoice['booking_type'] === 'room'): ?>
                                                    <a class="btn-action" href="booking-details.php?id=<?php echo (int)$invoice['booking_id']; ?>" title="View booking">
                                                        <i class="fas fa-door-open"></i> Booking
                                                    </a>
                                                <?php elseif ($invoice['booking_type'] === 'conference'): ?>
                                                    <a class="btn-action" href="conference-management.php#enquiry-<?php echo (int)$invoice['booking_id']; ?>" title="View conference inquiry">
                                                        <i class="fas fa-chalkboard"></i> Booking
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php
                                            // Right document (owner rule): a fully paid account is offered its RECEIPT, never an invoice.
                                            $invPaidFull = $invoice['payment_type'] !== 'refund' && !empty($invoice['booking_id'])
                                                && rh_account_fully_paid($pdo, (string)$invoice['booking_type'], (int)$invoice['booking_id']);
                                            ?>
                                            <?php if ($invPaidFull): ?>
                                                <button type="button" class="btn-action btn-resend" onclick="rhSendAccountReceipt(<?php echo htmlspecialchars(json_encode((string)$invoice['booking_type']), ENT_QUOTES); ?>, <?php echo (int)$invoice['booking_id']; ?>, <?php echo htmlspecialchars(json_encode((string)$invoice['booking_reference']), ENT_QUOTES); ?>)">
                                                    <i class="fas fa-receipt"></i> Send receipt
                                                </button>
                                            <?php endif; ?>

                                            <!-- Resend invoice — only for non-refund payments with a generated invoice (not once fully paid) -->
                                            <?php if (!$invPaidFull && $invoice['invoice_generated'] && $invoice['payment_type'] !== 'refund'): ?>
                                                <form method="POST" action="invoices.php" class="invoice-ajax-form" data-no-admin-loader="1" style="display: inline;"
                                                    data-confirm-msg="Resend invoice email to the customer?"
                                                    data-confirm-title="Resend Invoice"
                                                    data-confirm-ok="Send"
                                                    data-confirm-icon="fa-paper-plane">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="resend_invoice">
                                                    <input type="hidden" name="_ajax" value="1">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn-action btn-resend">
                                                        <i class="fas fa-paper-plane"></i> Resend
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Payment reminder — only for outstanding, non-refund payments -->
                                            <?php if (!$invPaidFull && in_array($invoice['payment_status'], ['pending', 'partial'], true) && $invoice['payment_type'] !== 'refund'): ?>
                                                <form method="POST" action="invoices.php" class="invoice-ajax-form" data-no-admin-loader="1" style="display: inline;"
                                                    data-confirm-msg="Send a payment reminder email to the customer now?"
                                                    data-confirm-title="Send Payment Reminder"
                                                    data-confirm-ok="Send Reminder"
                                                    data-confirm-icon="fa-bell">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="send_reminder">
                                                    <input type="hidden" name="_ajax" value="1">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn-action btn-resend">
                                                        <i class="fas fa-bell"></i> Reminder
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Direct email with invoice attachment -->
                                            <?php if (!$invPaidFull && !empty($invoice['customer_email'])): ?>
                                                <form method="POST" action="invoices.php" class="invoice-ajax-form" data-no-admin-loader="1" style="display: inline;"
                                                    data-confirm-msg="Send an email with the invoice attached to <?php echo htmlspecialchars($invoice['customer_email'], ENT_QUOTES, 'UTF-8'); ?>?"
                                                    data-confirm-title="Send Invoice Email"
                                                    data-confirm-ok="Send"
                                                    data-confirm-icon="fa-envelope">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="resend_invoice">
                                                    <input type="hidden" name="_ajax" value="1">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn-action">
                                                        <i class="fas fa-envelope"></i> Email
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- WhatsApp — use phone number when available so message goes to the right contact -->
                                            <?php
                                            $waPhone = preg_replace('/[^0-9+]/', '', (string)($invoice['customer_phone'] ?? ''));
                                            $waText = urlencode('Payment reminder for booking ' . $invoice['booking_reference'] . ' · Ref: ' . $invoice['payment_reference'] . ' · Amt: ' . ($currency_symbol ?? '') . number_format((float)$invoice['total_amount'], 0));
                                            $waHref = !empty($waPhone)
                                                ? 'https://wa.me/' . ltrim($waPhone, '+') . '?text=' . $waText
                                                : 'https://wa.me/?text=' . $waText;
                                            ?>
                                            <?php if (!$invPaidFull): ?>
                                            <a class="btn-action" href="<?php echo htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                                <i class="fab fa-whatsapp"></i> WhatsApp
                                            </a>
                                            <?php endif; ?>

                                            <!-- Regenerate invoice — not applicable for refund records (use Credit Note instead) -->
                                            <?php if ($invoice['payment_type'] !== 'refund'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="regenerate_invoice">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn-action btn-regenerate" onclick="return confirm('Regenerate invoice? This will create a new invoice number.');">
                                                        <i class="fas fa-sync"></i> Regenerate
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Credit Note — only for refund payments that haven't had one generated yet -->
                                            <?php if ($invoice['payment_type'] === 'refund' && !$invoice['invoice_generated']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="generate_credit_note">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn-action" style="background: #dc3545; color: white;" onclick="return confirm('Generate credit note for this refund?');">
                                                        <i class="fas fa-file-invoice"></i> Credit Note
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice"></i>
                    <p>No invoices found matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($invUnpaidConv)):
            $invCanOpenQuote = hasPermission((int)($user['id'] ?? 0), 'create_booking');
        ?>
        <!-- Invoices made from quotations with a balance still owed and no payment row carrying their number (not in the list above) -->
        <div class="invoices-table" style="margin-top: 20px;">
            <h2 style="font-size: 1.1rem; margin: 0 0 4px;">Invoices from quotations, balance still owed</h2>
            <p style="margin: 0 0 12px; color: #666; font-size: 13px;">Issued with Convert to invoice on the Quotations page. No payment row carries these invoice numbers yet, so they are not in the list above. They are not counted in the cards, totals or reports on this page.</p>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th title="The invoice number given when the quotation was converted.">Invoice #</th>
                            <th title="The quotation the invoice was made from.">Quotation</th>
                            <th title="The booking or enquiry the invoice is for.">Booking Ref</th>
                            <th>Type</th>
                            <th>Customer</th>
                            <th title="When the quotation was converted to this invoice.">Issued</th>
                            <th title="What is still to be paid on the booking or enquiry today (its full bill less any money received).">Balance owed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invUnpaidConv as $uc): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($uc['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($uc['quote_reference']); ?></td>
                                <td><strong><?php echo htmlspecialchars($uc['ref']); ?></strong></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars($uc['type']); ?>"><?php echo htmlspecialchars(ucfirst($uc['type'])); ?></span></td>
                                <td>
                                    <?php echo htmlspecialchars($uc['name'] !== '' ? $uc['name'] : 'N/A'); ?>
                                    <?php if ($uc['email'] !== ''): ?><br><small style="color: #666;"><?php echo htmlspecialchars($uc['email']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo $uc['converted_at'] !== '' ? date('M j, Y', strtotime($uc['converted_at'])) : '—'; ?></td>
                                <td><strong style="color: var(--gold);"><?php echo htmlspecialchars((string)getSetting('currency_symbol')); ?> <?php echo number_format($uc['due'], 0); ?></strong></td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($invCanOpenQuote): ?>
                                            <a class="btn-action" href="quotations.php?invoice=<?php echo (int)$uc['quotation_id']; ?>" data-no-spa="1" data-no-admin-loader="1" title="Download invoice <?php echo htmlspecialchars($uc['invoice_number'], ENT_QUOTES); ?>"><i class="fas fa-download"></i></a>
                                        <?php endif; ?>
                                        <?php if ($uc['type'] === 'room'): ?>
                                            <a class="btn-action" href="booking-details.php?id=<?php echo (int)$uc['booking_id']; ?>#invoices" title="View booking"><i class="fas fa-calendar-check"></i></a>
                                        <?php else: ?>
                                            <a class="btn-action" href="conference-management.php#enquiry-<?php echo (int)$uc['booking_id']; ?>" title="View conference enquiry"><i class="fas fa-briefcase"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh filters when changed
        document.querySelectorAll('.filter-group select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });
    </script>
    <script src="js/admin-components.js"></script>
    <script>
    (function () {
        'use strict';

        function showToast(msg, type) {
            if (window.Alert && typeof window.Alert.show === 'function') {
                window.Alert.show(msg, type || 'success');
            } else {
                // Alert not yet ready — queue for admin-flash.php to pick up
                try {
                    var q = JSON.parse(sessionStorage.getItem('rh_alert_queue') || '[]');
                    q.push({ msg: msg, type: type || 'success' });
                    sessionStorage.setItem('rh_alert_queue', JSON.stringify(q));
                } catch (e) {}
                window.addEventListener('load', function () {
                    if (window.Alert && typeof window.Alert.show === 'function') {
                        window.Alert.show(msg, type || 'success');
                    }
                }, { once: true });
            }
        }

        function doAjaxSubmit(form, btn) {
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.dataset.ajaxOrigHtml = origHtml;
                btn.disabled = true;
                btn.innerHTML = '<span class="admin-inline-spinner" aria-hidden="true"></span><span>Sending…</span>';
            }

            var fd = new FormData(form);

            fetch(form.getAttribute('action') || window.location.pathname, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) {
                if (!r.ok) throw new Error('Server error (' + r.status + ')');
                return r.json();
            })
            .then(function (data) {
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                showToast(data.message || (data.success ? 'Done.' : 'Failed.'), data.success ? 'success' : 'error');
            })
            .catch(function (err) {
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                showToast('Request failed: ' + err.message, 'error');
            });
        }

        // capture=true: runs BEFORE admin-main.js (which listens in bubbling phase).
        // We fully own .invoice-ajax-form — prevent the default submission, handle
        // confirmation ourselves, then do fetch() instead of a full page navigation.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.classList.contains('invoice-ajax-form')) return;

            e.preventDefault();
            e.stopImmediatePropagation(); // prevent admin-main.js from also handling

            var btn = e.submitter || form.querySelector('button[type="submit"]');
            var confirmMsg = form.dataset.confirmMsg;

            if (confirmMsg) {
                if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                    window.AdminConfirm.request({
                        title:       form.dataset.confirmTitle || 'Confirm',
                        message:     confirmMsg,
                        confirmText: form.dataset.confirmOk   || 'Confirm',
                        cancelText:  'Cancel',
                        tone:        'info',
                        icon:        form.dataset.confirmIcon  || 'fa-check'
                    }).then(function (confirmed) {
                        if (confirmed) doAjaxSubmit(form, btn);
                    });
                } else {
                    // AdminConfirm not loaded yet — native fallback
                    if (window.confirm(confirmMsg)) doAjaxSubmit(form, btn);
                }
            } else {
                doAjaxSubmit(form, btn);
            }
        }, true); // capture phase
    })();
    </script>
    <?php rh_render_send_receipt_script($csrf_token ?? ''); ?>
    <?php require_once 'includes/admin-footer.php'; ?>

