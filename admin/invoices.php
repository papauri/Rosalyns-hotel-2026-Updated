<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../includes/alert.php';
require_once 'includes/finance-schema.php';
require_once __DIR__ . '/includes/booking-lifecycle.php';
$message = '';
$error = '';
$csrf_token = $csrf_token ?? generateCsrfToken();
$conferenceFields = finance_conference_fields($pdo);

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

            if ($payment['booking_type'] === 'room') {
                $result = generateInvoicePDF($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'conference') {
                $result = generateConferenceInvoicePDF($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'gym') {
                $result = generateGymInvoicePDF($payment['booking_id']);
            } elseif ($payment['booking_type'] === 'event') {
                $result = generateEventInvoicePDF($payment['booking_id']);
            } else {
                throw new Exception('Invoice regeneration is not supported for ' . htmlspecialchars($payment['booking_type'], ENT_QUOTES, 'UTF-8') . ' payments.');
            }

            if ($result) {
                // Update payment record with new invoice path
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

            // Generate credit note number (unique, cryptographically random)
            $year = date('Y');
            do {
                $creditNoteNumber = 'CN-' . $year . '-' . str_pad(random_int(1, 9999999), 7, '0', STR_PAD_LEFT);
                $cnCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_number = ? LIMIT 1");
                $cnCheck->execute([$creditNoteNumber]);
                $cnExists = (int)$cnCheck->fetchColumn() > 0;
            } while ($cnExists);

            // Update payment record with credit note number
            $update_stmt = $pdo->prepare("
                UPDATE payments
                SET invoice_number = ?, invoice_generated = 1
                WHERE id = ?
            ");
            $update_stmt->execute([$creditNoteNumber, $payment_id]);

            $message = 'Credit note generated successfully! Number: ' . $creditNoteNumber;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
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
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_status = $_GET['filter_status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Preset scoping: default list shows invoices for enabled modules only.
// Explicit ?filter_type= and ?scope=all bypass; history is never deleted.
$scopeAll            = (($_GET['scope'] ?? '') === 'all');
$allBookingTypes     = ['room', 'conference', 'restaurant', 'gym', 'event'];
$enabledBookingTypes = function_exists('rh_enabled_booking_types') ? rh_enabled_booking_types() : [];
$scopeActive         = $filter_type === 'all' && !$scopeAll
    && !empty($enabledBookingTypes)
    && count($enabledBookingTypes) < count($allBookingTypes);
$hiddenBookingTypes  = $scopeActive ? array_values(array_diff($allBookingTypes, $enabledBookingTypes)) : [];

// Build query
$where_conditions = ["p.deleted_at IS NULL"];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "p.booking_type = ?";
    $params[] = $filter_type;
} elseif ($scopeActive) {
    $where_conditions[] = "p.booking_type IN (" . implode(',', array_fill(0, count($enabledBookingTypes), '?')) . ")";
    $params = array_merge($params, $enabledBookingTypes);
}

if ($filter_status !== 'all') {
    $where_conditions[] = "p.payment_status = ?";
    $params[] = $filter_status;
}

if (!empty($search)) {
    $where_conditions[] = "(p.invoice_number LIKE ? OR p.payment_reference LIKE ? OR p.booking_reference LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? implode(' AND ', $where_conditions) : '';

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

    if (!empty($where_clause)) {
        $sql .= " WHERE $where_clause";
    }

    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Rows hidden by preset scoping (for the notice above the table)
    $hiddenScopedCount = 0;
    if ($scopeActive && $hiddenBookingTypes !== []) {
        $hPh = implode(',', array_fill(0, count($hiddenBookingTypes), '?'));
        $hStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND booking_type IN ($hPh)");
        $hStmt->execute($hiddenBookingTypes);
        $hiddenScopedCount = (int)$hStmt->fetchColumn();
    }

    // Get statistics
    $stats_stmt = $pdo->query("
        SELECT
            COUNT(*) as total_invoices,
            COUNT(CASE WHEN invoice_generated = 1 THEN 1 END) as invoices_generated,
            COALESCE(SUM(CASE
                WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type,'') <> 'refund' THEN total_amount
                WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -total_amount
                ELSE 0 END), 0) as total_revenue
        FROM payments
        WHERE deleted_at IS NULL
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Rich invoice analytics — outstanding, paid, refunded, MTD, aging buckets
    $invoiceKpiStmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS outstanding_total,
            COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') AND COALESCE(payment_type, '') <> 'refund' THEN 1 ELSE 0 END), 0) AS outstanding_count,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN total_amount ELSE 0 END), 0) AS refunded_total,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN 1 ELSE 0 END), 0) AS refunded_count,
            COALESCE(SUM(CASE WHEN YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE()) AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') <> 'refund' THEN total_amount ELSE 0 END), 0) AS mtd_collected
        FROM payments
        WHERE deleted_at IS NULL
    ");
    $invoiceKpi = $invoiceKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Aging buckets for outstanding invoices (days since record was created = invoice issue date)
    $agingStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 0  AND 30 THEN total_amount ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 0  AND 30 THEN 1 ELSE 0 END)            AS count_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 31 AND 60 THEN total_amount ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 31 AND 60 THEN 1 ELSE 0 END)            AS count_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 61 AND 90 THEN total_amount ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) BETWEEN 61 AND 90 THEN 1 ELSE 0 END)            AS count_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) > 90 THEN total_amount ELSE 0 END)              AS bucket_90p,
            SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) > 90 THEN 1 ELSE 0 END)                         AS count_90p
        FROM payments
        WHERE deleted_at IS NULL
          AND payment_status IN ('pending','partial')
          AND COALESCE(payment_type, '') <> 'refund'
    ");
    $aging = $agingStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'bucket_0_30' => 0,
        'count_0_30' => 0,
        'bucket_31_60' => 0,
        'count_31_60' => 0,
        'bucket_61_90' => 0,
        'count_61_90' => 0,
        'bucket_90p' => 0,
        'count_90p' => 0
    ];

    // Outstanding split by source
    $outstandingBySourceStmt = $pdo->query("
        SELECT booking_type,
               COUNT(*) AS cnt,
               COALESCE(SUM(total_amount), 0) AS amt
        FROM payments
        WHERE deleted_at IS NULL
          AND payment_status IN ('pending','partial')
          AND COALESCE(payment_type, '') <> 'refund'
        GROUP BY booking_type
        ORDER BY amt DESC
    ");
    $outstandingBySource = $outstandingBySourceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching invoices: ' . $e->getMessage();
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
                <a href="payment-add.php" class="acct-quick-action acct-quick-action--accent">
                    <i class="fas fa-plus"></i> New Payment
                </a>
                <a href="payments.php" class="acct-quick-action">
                    <i class="fas fa-coins"></i> Payments Ledger
                </a>
                <a href="accounting-dashboard.php" class="acct-quick-action">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">Paid &amp; Closed</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($invoiceKpi['paid_total'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($invoiceKpi['paid_count'] ?? 0)); ?> invoices · MTD <?php echo $currency_symbol . number_format((float)($invoiceKpi['mtd_collected'] ?? 0), 0); ?>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Outstanding</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($invoiceKpi['outstanding_total'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($invoiceKpi['outstanding_count'] ?? 0)); ?> awaiting ·
                    <a href="?filter_status=pending" class="acct-link">view pending</a>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Generated</div>
                <div class="acct-kpi__value"><?php echo number_format((int)($stats['invoices_generated'] ?? 0)); ?></div>
                <div class="acct-kpi__meta">
                    of <?php echo number_format((int)($stats['total_invoices'] ?? 0)); ?> ·
                    <?php
                    $genPct = ($stats['total_invoices'] ?? 0) > 0
                        ? (((int)$stats['invoices_generated'] / (int)$stats['total_invoices']) * 100)
                        : 0;
                    echo number_format($genPct, 0) . '% coverage';
                    ?>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--vat">
                <div class="acct-kpi__label">Refunded / Credit</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)($invoiceKpi['refunded_total'] ?? 0), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo number_format((int)($invoiceKpi['refunded_count'] ?? 0)); ?> credit notes
                </div>
            </div>
        </div>

        <!-- Aging buckets + outstanding by source -->
        <div class="acct-grid acct-grid--2" style="margin-top: 18px;">
            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Receivables Aging</h3>
                    <span class="acct-panel__sub">Pending / partial only</span>
                </div>
                <div class="acct-table-wrap">
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
                </div>
            </div>

            <div class="acct-panel">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title">Outstanding by Source</h3>
                    <span class="acct-panel__sub">Where the receivables sit</span>
                </div>
                <div class="acct-table-wrap">
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
                                    $label = ['room' => 'Rooms', 'conference' => 'Conferences', 'restaurant' => htmlspecialchars(rh_pos_category_label())][$o['booking_type']] ?? ucfirst((string)$o['booking_type']);
                                ?>
                                    <tr>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$o['booking_type']); ?>"><?php echo $label; ?></span></td>
                                        <td class="num"><?php echo number_format((int)$o['cnt']); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)$o['amt'], 0); ?></strong></td>
                                        <td>
                                            <a class="acct-link" href="?filter_type=<?php echo htmlspecialchars((string)$o['booking_type']); ?>&amp;filter_status=pending">View →</a>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

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
                        <input type="text" name="search" placeholder="Invoice #, Payment #, Booking Ref..." value="<?php echo htmlspecialchars($search); ?>">
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
                <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($scopeQs, ['scope' => 'all']))); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show all history &rarr;</a>
            </div>
        <?php elseif ($scopeAll && $filter_type === 'all'): ?>
            <div style="background:#faf8f4; border:1px solid #e5d9c9; border-radius:10px; padding:10px 14px; margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px; color:#7a6f63;">
                <span><i class="fas fa-clock-rotate-left" style="margin-right:6px;"></i>Showing full invoice history, including records from disabled modules.</span>
                <a href="?<?php echo htmlspecialchars(http_build_query($scopeQs)); ?>" style="color:#8B7355; font-weight:600; text-decoration:none;">Show relevant only &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Invoices Table -->
        <div class="invoices-table">
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
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($invoice['payment_reference']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['booking_reference']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $invoice['booking_type']; ?>">
                                            <?php echo ucfirst($invoice['booking_type']); ?>
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
                                        <span class="badge badge-<?php echo $invoice['payment_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_status'])); ?>
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
                                                <a href="../<?php echo htmlspecialchars($invoice['invoice_path']); ?>"
                                                    target="_blank"
                                                    class="btn-action btn-view"
                                                    title="<?php echo $invoice['payment_type'] === 'refund' ? 'View Credit Note' : 'View Invoice'; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>

                                            <!-- Link to underlying booking -->
                                            <?php if (!empty($invoice['booking_id'])): ?>
                                                <?php if ($invoice['booking_type'] === 'room'): ?>
                                                    <a class="btn-action" href="booking-detail.php?id=<?php echo (int)$invoice['booking_id']; ?>" title="View booking">
                                                        <i class="fas fa-door-open"></i> Booking
                                                    </a>
                                                <?php elseif ($invoice['booking_type'] === 'conference'): ?>
                                                    <a class="btn-action" href="conference-detail.php?id=<?php echo (int)$invoice['booking_id']; ?>" title="View conference inquiry">
                                                        <i class="fas fa-chalkboard"></i> Booking
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- Resend invoice — only for non-refund payments with a generated invoice -->
                                            <?php if ($invoice['invoice_generated'] && $invoice['payment_type'] !== 'refund'): ?>
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
                                            <?php if (in_array($invoice['payment_status'], ['pending', 'partial'], true) && $invoice['payment_type'] !== 'refund'): ?>
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
                                            <?php if (!empty($invoice['customer_email'])): ?>
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
                                            <a class="btn-action" href="<?php echo htmlspecialchars($waHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                                <i class="fab fa-whatsapp"></i> WhatsApp
                                            </a>

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
    <?php require_once 'includes/admin-footer.php'; ?>

