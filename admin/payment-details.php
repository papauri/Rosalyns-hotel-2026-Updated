<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once 'includes/finance-schema.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$conferenceFields = finance_conference_fields($pdo);
$paymentTransactionColumn = finance_payment_transaction_column($pdo);

// Get payment ID
$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$paymentId) {
    $_SESSION['alert'] = ['type' => 'error', 'message' => 'Payment ID is required'];
    header('Location: payments.php');
    exit;
}

// Get payment details
$stmt = $pdo->prepare("
    SELECT
        p.*,
        CASE
            WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.booking_reference, ')')
            WHEN p.booking_type = 'conference' THEN CONCAT(ci.{$conferenceFields['company']}, ' (', ci.{$conferenceFields['reference']}, ')')
            WHEN p.booking_type = 'restaurant' THEN CONCAT('Restaurant order ', so.reference, COALESCE(CONCAT(' - ', NULLIF(so.customer_name, '')), ''))
            ELSE 'Unknown'
        END as booking_description,
        CASE
            WHEN p.booking_type = 'room' THEN b.booking_reference
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
            WHEN p.booking_type = 'restaurant' THEN so.reference
            ELSE NULL
        END as booking_reference,
        CASE
            WHEN p.booking_type = 'room' THEN b.guest_name
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['contact_name']}
            WHEN p.booking_type = 'restaurant' THEN so.customer_name
            ELSE NULL
        END as customer_name,
        CASE
            WHEN p.booking_type = 'room' THEN b.guest_email
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
            ELSE NULL
        END as customer_email,
        CASE
            WHEN p.booking_type = 'room' THEN b.guest_phone
            WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['phone']}
            ELSE NULL
        END as customer_phone,
        p.{$paymentTransactionColumn} as transaction_reference_value
    FROM payments p
    LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
    LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
    LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    $_SESSION['alert'] = ['type' => 'info', 'message' => 'Payment not found. It may have been deleted or does not exist.'];
    header('Location: payments.php');
    exit;
}

// Get booking details
$bookingDetails = null;
if ($payment['booking_type'] === 'room') {
    $bookingStmt = $pdo->prepare("
        SELECT
            b.*,
            r.name as room_name,
            r.price_per_night
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ");
    $bookingStmt->execute([$payment['booking_id']]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

    if ($booking) {
        $bookingDetails = [
            'type' => 'room',
            'id' => (int)$booking['id'],
            'reference' => $booking['booking_reference'],
            'room' => [
                'id' => (int)$booking['room_id'],
                'name' => $booking['room_name'],
                'price_per_night' => (float)$booking['price_per_night']
            ],
            'guest' => [
                'name' => $booking['guest_name'],
                'email' => $booking['guest_email'],
                'phone' => $booking['guest_phone']
            ],
            'dates' => [
                'check_in' => $booking['check_in_date'],
                'check_out' => $booking['check_out_date'],
                'nights' => (int)$booking['number_of_nights']
            ],
            'amounts' => [
                'total_amount' => (float)$booking['total_amount'],
                'amount_paid' => (float)$booking['amount_paid'],
                'amount_due' => (float)$booking['amount_due'],
                'vat_rate' => (float)$booking['vat_rate'],
                'vat_amount' => (float)$booking['vat_amount'],
                'total_with_vat' => (float)$booking['total_with_vat']
            ],
            'status' => $booking['status']
        ];
    }
} elseif ($payment['booking_type'] === 'conference') {
    $confStmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
    $confStmt->execute([$payment['booking_id']]);
    $enquiry = $confStmt->fetch(PDO::FETCH_ASSOC);

    if ($enquiry) {
        $bookingDetails = [
            'type' => 'conference',
            'id' => (int)$enquiry['id'],
            'reference' => $enquiry[$conferenceFields['reference']] ?? '',
            'organization' => [
                'name' => $enquiry[$conferenceFields['company']] ?? '',
                'contact_person' => $enquiry[$conferenceFields['contact_name']] ?? '',
                'email' => $enquiry[$conferenceFields['email']] ?? '',
                'phone' => $enquiry[$conferenceFields['phone']] ?? ''
            ],
            'event' => [
                'type' => $enquiry['event_type'],
                'start_date' => $enquiry[$conferenceFields['start_date']] ?? null,
                'end_date' => $enquiry[$conferenceFields['end_date']] ?? null,
                'expected_attendees' => (int)($enquiry[$conferenceFields['expected_attendees']] ?? 0)
            ],
            'amounts' => [
                'total_amount' => (float)$enquiry['total_amount'],
                'amount_paid' => (float)$enquiry['amount_paid'],
                'amount_due' => (float)$enquiry['amount_due'],
                'vat_rate' => (float)$enquiry['vat_rate'],
                'vat_amount' => (float)$enquiry['vat_amount'],
                'total_with_vat' => (float)$enquiry['total_with_vat'],
                'deposit_required' => (float)$enquiry['deposit_required'],
                'deposit_amount' => (float)$enquiry['deposit_amount'],
                'deposit_paid' => (float)$enquiry['deposit_paid']
            ],
            'status' => $enquiry['status']
        ];
    }
} elseif ($payment['booking_type'] === 'restaurant') {
    $orderStmt = $pdo->prepare("SELECT * FROM stock_orders WHERE id = ?");
    $orderStmt->execute([$payment['booking_id']]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $bookingDetails = [
            'type' => 'restaurant',
            'id' => (int)$order['id'],
            'reference' => $order['reference'],
            'customer' => [
                'name' => $order['customer_name'] ?: 'Walk-in / POS',
                'table_number' => $order['table_number'] ?? '',
            ],
            'amounts' => [
                'total_amount' => (float)$order['total_amount'],
                'amount_paid' => $order['status'] === 'paid' ? (float)$order['total_amount'] : 0.0,
                'amount_due' => $order['status'] === 'paid' ? 0.0 : (float)$order['total_amount'],
                'total_cost' => (float)$order['total_cost'],
            ],
            'status' => $order['status']
        ];
    }
} elseif ($payment['booking_type'] === 'gym') {
    try {
        $gymStmt = $pdo->prepare("SELECT * FROM gym_inquiries WHERE id = ?");
        $gymStmt->execute([$payment['booking_id']]);
        if ($gi = $gymStmt->fetch(PDO::FETCH_ASSOC)) {
            $bookingDetails = [
                'type' => 'gym',
                'id' => (int)$gi['id'],
                'reference' => $gi['reference_number'],
                'person' => [
                    'label' => 'Member',
                    'name' => $gi['name'],
                    'email' => $gi['email'],
                    'phone' => $gi['phone'],
                ],
                'detail_rows' => array_filter([
                    'Membership' => $gi['membership_type'] ?: null,
                ]),
                'amounts' => [
                    'total_amount' => (float)($gi['total_amount'] ?? 0),
                    'amount_paid' => (float)($gi['amount_paid'] ?? 0),
                    'amount_due' => (float)($gi['amount_due'] ?? 0),
                    'vat_rate' => (float)($gi['vat_rate'] ?? 0),
                    'vat_amount' => (float)($gi['vat_amount'] ?? 0),
                ],
                'status' => $gi['status']
            ];
        }
    } catch (Throwable $e) { /* table pending — card simply not shown */ }
} elseif ($payment['booking_type'] === 'event') {
    try {
        $evStmt = $pdo->prepare("SELECT ei.*, e.title AS event_title, e.event_date FROM event_inquiries ei LEFT JOIN events e ON e.id = ei.event_id WHERE ei.id = ?");
        $evStmt->execute([$payment['booking_id']]);
        if ($ei = $evStmt->fetch(PDO::FETCH_ASSOC)) {
            $bookingDetails = [
                'type' => 'event',
                'id' => (int)$ei['id'],
                'reference' => $ei['reference_number'],
                'person' => [
                    'label' => 'Attendee',
                    'name' => $ei['name'],
                    'email' => $ei['email'],
                    'phone' => $ei['phone'],
                ],
                'detail_rows' => array_filter([
                    'Event' => $ei['event_title'] ?: null,
                    'Event Date' => !empty($ei['event_date']) ? date('M j, Y', strtotime($ei['event_date'])) : null,
                    'Attendees' => (int)($ei['guests'] ?? 0) ?: null,
                ]),
                'amounts' => [
                    'total_amount' => (float)($ei['total_amount'] ?? 0),
                    'amount_paid' => (float)($ei['amount_paid'] ?? 0),
                    'amount_due' => (float)($ei['amount_due'] ?? 0),
                    'vat_rate' => (float)($ei['vat_rate'] ?? 0),
                    'vat_amount' => (float)($ei['vat_amount'] ?? 0),
                ],
                'status' => $ei['status']
            ];
        }
    } catch (Throwable $e) { /* table pending — card simply not shown */ }
}

// Get other payments for this booking
$otherPaymentsStmt = $pdo->prepare("
    SELECT * FROM payments
    WHERE booking_type = ? AND booking_id = ? AND id != ? AND deleted_at IS NULL
    ORDER BY payment_date DESC, created_at DESC
");
$otherPaymentsStmt->execute([$payment['booking_type'], $payment['booking_id'], $paymentId]);
$otherPayments = $otherPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate payment summary for this booking
$paymentSummaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END), 0) as pending_amount,
        COUNT(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN 1 END) as completed_payments,
        COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_payments
    FROM payments
    WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL
");
$paymentSummaryStmt->execute([$payment['booking_type'], $payment['booking_id']]);
$paymentSummary = $paymentSummaryStmt->fetch(PDO::FETCH_ASSOC);

// Get booking total amount from booking details
$bookingTotalAmount = 0;
if ($bookingDetails && isset($bookingDetails['amounts']['total_with_vat'])) {
    $bookingTotalAmount = $bookingDetails['amounts']['total_with_vat'];
} elseif ($bookingDetails && isset($bookingDetails['amounts']['total_amount'])) {
    $bookingTotalAmount = $bookingDetails['amounts']['total_amount'];
}

// Calculate due amount
$dueAmount = $bookingTotalAmount - $paymentSummary['total_paid'];

// Refunds issued AGAINST this specific payment (when this is the original)
$refundsAgainstStmt = $pdo->prepare("
    SELECT id, payment_reference, payment_date, refund_amount, refund_reason, refund_status, refund_notes, total_amount, created_at
    FROM payments
    WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
    ORDER BY created_at DESC
");
$refundsAgainstStmt->execute([$paymentId]);
$refundsAgainst = $refundsAgainstStmt->fetchAll(PDO::FETCH_ASSOC);
$totalRefundedHere = 0.0;
foreach ($refundsAgainst as $r) {
    if (in_array($r['refund_status'], ['completed', 'processing', 'pending'], true)) {
        $totalRefundedHere += (float)($r['refund_amount'] ?: $r['total_amount']);
    }
}

// If THIS payment is itself a refund, fetch the original
$originalPayment = null;
if (($payment['payment_type'] ?? '') === 'refund' && !empty($payment['original_payment_id'])) {
    $opStmt = $pdo->prepare("SELECT id, payment_reference, payment_date, total_amount, payment_method, payment_status FROM payments WHERE id = ? AND deleted_at IS NULL");
    $opStmt->execute([(int)$payment['original_payment_id']]);
    $originalPayment = $opStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details | <?php echo htmlspecialchars($site_name); ?> Admin</title>

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
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">
                    Payment <?php echo htmlspecialchars($payment['payment_reference']); ?>
                </h1>
                <p class="acct-page-header__subtitle">
                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?> ·
                    <?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?> ·
                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?> ·
                    <span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)$payment['payment_status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_status']))); ?></span>
                    <?php if (($payment['payment_type'] ?? '') === 'refund'): ?>
                        <span class="acct-pill acct-pill--danger">Refund</span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($payment['payment_status'] !== 'completed'): ?>
                    <a href="payment-add.php?edit=<?php echo $paymentId; ?>" class="acct-quick-action acct-quick-action--accent">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                <?php endif; ?>
                <?php if (in_array($payment['payment_status'], ['completed', 'paid'], true) && ($payment['payment_type'] ?? '') !== 'refund'): ?>
                    <a href="payment-refund.php?id=<?php echo $paymentId; ?>" class="acct-quick-action">
                        <i class="fas fa-undo"></i> Refund
                    </a>
                <?php endif; ?>
                <?php if (in_array($payment['payment_status'], ['completed', 'paid'], true) && ($payment['payment_type'] ?? '') !== 'refund'): ?>
                    <?php if (($payment['booking_type'] ?? '') === 'restaurant' && !empty($payment['booking_id'])): ?>
                        <button type="button" class="acct-quick-action" onclick="pdOpenReceiptModal(<?php echo (int)$payment['booking_id']; ?>, 'order')">
                            <i class="fas fa-paper-plane"></i> Send Receipt
                        </button>
                    <?php else: ?>
                        <button type="button" class="acct-quick-action" onclick="pdOpenReceiptModal(<?php echo $paymentId; ?>, 'payment')">
                            <i class="fas fa-paper-plane"></i> Send Receipt
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="invoices.php?search=<?php echo urlencode($payment['payment_reference']); ?>" class="acct-quick-action">
                    <i class="fas fa-file-invoice"></i> Invoice
                </a>
                <a href="payments.php" class="acct-quick-action" onclick="if(history.length>1){history.back();return false;}">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Compact KPI strip replaces the bulky payment-summary-card -->
        <?php
        $paymentPercentage = $bookingTotalAmount > 0 ? ($paymentSummary['total_paid'] / $bookingTotalAmount) * 100 : 0;
        $paymentStatusText = $dueAmount <= 0 ? 'Fully Paid' : ($paymentSummary['total_paid'] > 0 ? 'Partially Paid' : 'Unpaid');
        ?>
        <div class="acct-kpis">
            <div class="acct-kpi acct-kpi--revenue">
                <div class="acct-kpi__label">This Payment</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)$payment['total_amount'], 0); ?></div>
                <div class="acct-kpi__meta">
                    Subtotal <?php echo $currency_symbol . number_format((float)$payment['payment_amount'], 0); ?>
                    <?php if ((float)$payment['vat_amount'] > 0): ?>
                        · VAT <?php echo $currency_symbol . number_format((float)$payment['vat_amount'], 0); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="acct-kpi acct-kpi--cash">
                <div class="acct-kpi__label">Booking Total</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)$bookingTotalAmount, 0); ?></div>
                <div class="acct-kpi__meta">
                    Paid <?php echo $currency_symbol . number_format((float)$paymentSummary['total_paid'], 0); ?> of total
                </div>
            </div>
            <div class="acct-kpi acct-kpi--receivables">
                <div class="acct-kpi__label">Outstanding</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format(max(0, (float)$dueAmount), 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo htmlspecialchars($paymentStatusText); ?> · <?php echo number_format($paymentPercentage, 0); ?>% complete
                </div>
            </div>
            <div class="acct-kpi acct-kpi--vat">
                <div class="acct-kpi__label">Refunded From This</div>
                <div class="acct-kpi__value"><?php echo $currency_symbol . number_format($totalRefundedHere, 0); ?></div>
                <div class="acct-kpi__meta">
                    <?php echo count($refundsAgainst); ?> refund<?php echo count($refundsAgainst) === 1 ? '' : 's'; ?>
                </div>
            </div>
        </div>

        <?php if ($originalPayment): ?>
            <div class="acct-error" style="margin-top: 16px; background: var(--finance-info-bg, #eff6ff); border-color: var(--finance-info-border, #bfdbfe); color: var(--finance-info, #1d4ed8);">
                <i class="fas fa-rotate-left"></i> This is a refund of original payment
                <a class="acct-link" href="payment-details.php?id=<?php echo (int)$originalPayment['id']; ?>"><strong><?php echo htmlspecialchars($originalPayment['payment_reference']); ?></strong></a>
                · <?php echo $currency_symbol . number_format((float)$originalPayment['total_amount'], 0); ?>
                · <?php echo date('M j, Y', strtotime($originalPayment['payment_date'])); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($refundsAgainst)): ?>
            <div class="acct-panel" style="margin-top: 18px;">
                <div class="acct-panel__head">
                    <h3 class="acct-panel__title"><i class="fas fa-rotate-left"></i> &nbsp;Refunds Against This Payment</h3>
                    <span class="acct-panel__sub"><?php echo count($refundsAgainst); ?> total · <?php echo $currency_symbol . number_format($totalRefundedHere, 0); ?> refunded</span>
                </div>
                <div class="acct-table-wrap">
                    <table class="acct-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th class="num">Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($refundsAgainst as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['payment_reference']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($r['payment_date'])); ?></td>
                                    <td class="num"><strong><?php echo $currency_symbol . number_format((float)($r['refund_amount'] ?: $r['total_amount']), 0); ?></strong></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($r['refund_reason'] ?? '—')))); ?></td>
                                    <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)($r['refund_status'] ?? 'pending')); ?>"><?php echo htmlspecialchars(ucfirst((string)($r['refund_status'] ?? 'pending'))); ?></span></td>
                                    <td><a class="acct-link" href="payment-details.php?id=<?php echo (int)$r['id']; ?>">View →</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="details-grid">
            <!-- Payment Information -->
            <div class="detail-card">
                <h3><i class="fas fa-money-bill-wave"></i> Payment Information</h3>

                <div class="detail-row">
                    <span class="detail-label">Payment Reference</span>
                    <span class="detail-value"><?php echo htmlspecialchars($payment['payment_reference']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Payment Date</span>
                    <span class="detail-value"><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">
                        <span class="badge badge-<?php echo $payment['payment_status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_status'])); ?>
                        </span>
                    </span>
                </div>

                <?php if (!empty($payment['transaction_reference_value'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Transaction Reference</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['transaction_reference_value']); ?></span>
                    </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="detail-label">Processed By</span>
                    <span class="detail-value"><?php echo htmlspecialchars($payment['processed_by'] ?? 'System'); ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Created</span>
                    <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($payment['created_at'])); ?></span>
                </div>

                <?php if ($payment['updated_at'] !== $payment['created_at']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Last Updated</span>
                        <span class="detail-value"><?php echo date('F j, Y g:i A', strtotime($payment['updated_at'])); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($payment['notes']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Notes</span>
                        <span class="detail-value" style="text-align: left; font-weight: 400;">
                            <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Amount Breakdown -->
            <div class="detail-card">
                <h3><i class="fas fa-calculator"></i> Amount Breakdown</h3>

                <div class="detail-row">
                    <span class="detail-label">Subtotal (excl. VAT)</span>
                    <span class="detail-value"><?php echo $currency_symbol; ?><?php echo number_format($payment['payment_amount'], 2); ?></span>
                </div>

                <?php if ($payment['vat_amount'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">VAT Rate</span>
                        <span class="detail-value"><?php echo number_format($payment['vat_rate'], 2); ?>%</span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">VAT Amount</span>
                        <span class="detail-value"><?php echo $currency_symbol; ?><?php echo number_format($payment['vat_amount'], 2); ?></span>
                    </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="detail-label">Total Amount</span>
                    <span class="detail-value large"><?php echo $currency_symbol; ?><?php echo number_format($payment['total_amount'], 2); ?></span>
                </div>

                <!-- Receipt Information -->
                <div class="receipt-preview <?php echo $payment['receipt_number'] ? 'has-receipt' : ''; ?>">
                    <?php if ($payment['receipt_number']): ?>
                        <i class="fas fa-receipt" style="font-size: 32px; color: var(--navy); margin-bottom: 12px;"></i>
                        <div class="receipt-number"><?php echo htmlspecialchars($payment['receipt_number']); ?></div>
                        <p style="color: #666;">Receipt Generated</p>
                    <?php else: ?>
                        <i class="fas fa-clock" style="font-size: 32px; color: #999; margin-bottom: 12px;"></i>
                        <p style="color: #999;">No receipt generated</p>
                        <p style="font-size: 12px; color: #999;">Receipt will be generated when payment is completed</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Source record information — labelled by what the payment actually is -->
        <?php if ($bookingDetails):
            // Per-type vocabulary: a gym payment is a membership, an event payment
            // an event booking, a restaurant payment an order — not a room booking.
            $pd_type_labels = [
                'room'       => ['heading' => 'Booking Information',       'title' => 'Room Booking',      'noun' => 'Booking'],
                'conference' => ['heading' => 'Booking Information',       'title' => 'Conference Booking', 'noun' => 'Booking'],
                'restaurant' => ['heading' => 'Order Information',         'title' => 'Restaurant Order',  'noun' => 'Order'],
                'gym'        => ['heading' => 'Membership Information',    'title' => 'Gym Membership',    'noun' => 'Membership'],
                'event'      => ['heading' => 'Event Booking Information', 'title' => 'Event Booking',     'noun' => 'Event Booking'],
            ];
            $pd_labels = $pd_type_labels[$bookingDetails['type']] ?? ['heading' => 'Record Information', 'title' => ucfirst($bookingDetails['type']), 'noun' => 'Record'];
        ?>
            <div class="detail-card" style="margin-bottom: 24px;">
                <h3><i class="fas fa-calendar-check"></i> <?php echo $pd_labels['heading']; ?></h3>

                <div class="booking-summary">
                    <h4><?php echo $pd_labels['title']; ?></h4>

                    <?php if ($bookingDetails['type'] === 'room'): ?>
                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['reference']); ?></p>
                        <p><strong>Room:</strong> <?php echo htmlspecialchars($bookingDetails['room']['name']); ?></p>
                        <p><strong>Guest:</strong> <?php echo htmlspecialchars($bookingDetails['guest']['name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['guest']['email']); ?></p>
                        <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['dates']['check_in'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['dates']['check_out'])); ?> (<?php echo $bookingDetails['dates']['nights']; ?> nights)</p>
                        <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_amount'], 0); ?></p>
                        <p><strong>Amount Paid:</strong> <span style="color: #28a745;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_paid'], 0); ?></span></p>
                        <p><strong>Amount Due:</strong> <span style="color: <?php echo $bookingDetails['amounts']['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_due'], 0); ?></span></p>
                        <?php if ($bookingDetails['amounts']['vat_amount'] > 0): ?>
                            <p><strong>VAT:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['vat_amount'], 0); ?> (<?php echo $bookingDetails['amounts']['vat_rate']; ?>%)</p>
                        <?php endif; ?>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php elseif ($bookingDetails['type'] === 'conference'): ?>
                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['reference']); ?></p>
                        <p><strong>Organization:</strong> <?php echo htmlspecialchars($bookingDetails['organization']['name']); ?></p>
                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($bookingDetails['organization']['contact_person']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['organization']['email']); ?></p>
                        <p><strong>Event Type:</strong> <?php echo htmlspecialchars($bookingDetails['event']['type']); ?></p>
                        <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['event']['start_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['event']['end_date'])); ?></p>
                        <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_amount'], 0); ?></p>
                        <p><strong>Amount Paid:</strong> <span style="color: #28a745;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_paid'], 0); ?></span></p>
                        <p><strong>Amount Due:</strong> <span style="color: <?php echo $bookingDetails['amounts']['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_due'], 0); ?></span></p>
                        <?php if ($bookingDetails['amounts']['deposit_required'] > 0): ?>
                            <p><strong>Deposit Required:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['deposit_required'], 0); ?> (Paid: <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['deposit_paid'], 0); ?>)</p>
                        <?php endif; ?>
                        <?php if ($bookingDetails['amounts']['vat_amount'] > 0): ?>
                            <p><strong>VAT:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['vat_amount'], 0); ?> (<?php echo $bookingDetails['amounts']['vat_rate']; ?>%)</p>
                        <?php endif; ?>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php elseif (in_array($bookingDetails['type'], ['gym', 'event'], true)): ?>
                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['reference']); ?></p>
                        <p><strong><?php echo htmlspecialchars($bookingDetails['person']['label']); ?>:</strong> <?php echo htmlspecialchars($bookingDetails['person']['name']); ?></p>
                        <?php if (!empty($bookingDetails['person']['email'])): ?>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['person']['email']); ?></p>
                        <?php endif; ?>
                        <?php foreach ($bookingDetails['detail_rows'] as $pd_dl => $pd_dv): ?>
                            <p><strong><?php echo htmlspecialchars($pd_dl); ?>:</strong> <?php echo htmlspecialchars((string)$pd_dv); ?></p>
                        <?php endforeach; ?>
                        <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_amount'], 0); ?></p>
                        <p><strong>Amount Paid:</strong> <span style="color: #28a745;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_paid'], 0); ?></span></p>
                        <p><strong>Amount Due:</strong> <span style="color: <?php echo $bookingDetails['amounts']['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['amount_due'], 0); ?></span></p>
                        <?php if ($bookingDetails['amounts']['vat_amount'] > 0): ?>
                            <p><strong>VAT:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['vat_amount'], 0); ?> (<?php echo $bookingDetails['amounts']['vat_rate']; ?>%)</p>
                        <?php endif; ?>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php else: ?>
                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['reference']); ?></p>
                        <p><strong>Customer:</strong> <?php echo htmlspecialchars($bookingDetails['customer']['name']); ?></p>
                        <?php if (!empty($bookingDetails['customer']['table_number'])): ?>
                            <p><strong>Table:</strong> <?php echo htmlspecialchars($bookingDetails['customer']['table_number']); ?></p>
                        <?php endif; ?>
                        <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_amount'], 0); ?></p>
                        <p><strong>Estimated Stock Cost:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_cost'], 0); ?></p>
                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php endif; ?>
                </div>

                <?php
                // Preset-aware source link: restaurant payments go to the orders
                // console only when the stock module is on (POS-only presets keep
                // the till); gym/event payments go to their inquiry pages.
                $pd_source_href = match ($bookingDetails['type']) {
                    'room'       => 'booking-details.php?id=' . $bookingDetails['id'],
                    'restaurant' => (function_exists('moduleEnabled') && moduleEnabled('stock')) ? 'stock-orders.php' : 'pos.php',
                    'gym'        => 'gym-inquiries.php',
                    'event'      => 'events-inquiries.php',
                    default      => 'conference-management.php',
                };
                ?>
                <a href="<?php echo htmlspecialchars($pd_source_href); ?>" class="btn-primary" style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                    <i class="fas fa-external-link-alt"></i> View Full <?php echo $pd_labels['noun']; ?> Details
                </a>
            </div>
        <?php endif; ?>

        <!-- Other Payments for this record -->
        <?php if (!empty($otherPayments)): ?>
            <div class="detail-card">
                <h3><i class="fas fa-list"></i> Other Payments for this <?php echo isset($pd_labels) ? $pd_labels['noun'] : 'Record'; ?></h3>

                <div class="other-payments">
                    <?php foreach ($otherPayments as $otherPayment): ?>
                        <div class="payment-item">
                            <div class="payment-item-info">
                                <div class="payment-item-ref"><?php echo htmlspecialchars($otherPayment['payment_reference']); ?></div>
                                <div class="payment-item-date"><?php echo date('M j, Y', strtotime($otherPayment['payment_date'])); ?></div>
                            </div>
                            <div class="payment-item-amount">
                                <?php echo $currency_symbol; ?><?php echo number_format($otherPayment['total_amount'], 0); ?>
                                <span class="badge badge-<?php echo $otherPayment['payment_status']; ?>" style="margin-left: 8px;">
                                    <?php echo ucfirst(str_replace('_', ' ', $otherPayment['payment_status'])); ?>
                                </span>
                            </div>
                            <a href="payment-details.php?id=<?php echo $otherPayment['id']; ?>" class="btn-secondary" style="padding: 6px 12px; font-size: 12px; margin-left: 12px;">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Send Receipt Modal -->
    <div class="modal-overlay" id="pdReceiptModal" aria-hidden="true" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:14px;width:min(96vw,500px);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div style="background:linear-gradient(135deg,#1d6a3e,#22c55e);color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-paper-plane"></i></div>
                    <div>
                        <div style="font-weight:700;font-size:15px;">Send Receipt</div>
                        <div style="font-size:12px;opacity:.85;" id="pdReceiptRef"><?php echo htmlspecialchars($payment['payment_reference'] ?? ''); ?></div>
                    </div>
                </div>
                <button type="button" onclick="pdCloseReceiptModal()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;opacity:.8;">&times;</button>
            </div>
            <div style="padding:20px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">Email</label>
                        <input type="email" id="pdReceiptEmail" placeholder="guest@example.com" value="<?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?>" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:7px;padding:8px 10px;font-size:13px;margin-bottom:6px;">
                        <button type="button" id="pdReceiptEmailBtn" onclick="pdSendReceipt('email')" style="width:100%;padding:9px;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fas fa-envelope"></i> Send email</button>
                        <div id="pdReceiptEmailStatus" style="font-size:11px;margin-top:5px;min-height:14px;"></div>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#6c757d;display:block;margin-bottom:4px;">WhatsApp</label>
                        <input type="tel" id="pdReceiptPhone" placeholder="+265 999 123 456" value="<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)($payment['customer_phone'] ?? ''))); ?>" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:7px;padding:8px 10px;font-size:13px;margin-bottom:6px;">
                        <button type="button" id="pdReceiptWhatsAppBtn" onclick="pdSendReceipt('whatsapp')" style="width:100%;padding:9px;background:#1d6a3e;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><i class="fab fa-whatsapp"></i> Send WhatsApp</button>
                        <div id="pdReceiptWhatsAppStatus" style="font-size:11px;margin-top:5px;min-height:14px;"></div>
                    </div>
                </div>
            </div>
            <div style="padding:12px 20px 18px;display:flex;gap:8px;justify-content:flex-end;">
                <?php if (($payment['booking_type'] ?? '') === 'restaurant' && !empty($payment['booking_id'])): ?>
                    <a href="stock-receipt.php?id=<?php echo (int)$payment['booking_id']; ?>&print=1" target="_blank" rel="noopener" style="padding:8px 14px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-weight:600;color:#374151;text-decoration:none;display:flex;align-items:center;gap:5px;"><i class="fas fa-print"></i> Print</a>
                <?php endif; ?>
                <button type="button" onclick="pdCloseReceiptModal()" style="padding:8px 18px;background:#374151;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">Done</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const pdCsrfToken = <?php echo json_encode(generateCsrfToken()); ?>;
        let pdReceiptId = 0;
        let pdReceiptMode = 'payment'; // 'payment' | 'order'

        function pdOpenReceiptModal(id, mode) {
            pdReceiptId = id;
            pdReceiptMode = mode || 'payment';
            document.getElementById('pdReceiptEmailStatus').textContent = '';
            document.getElementById('pdReceiptWhatsAppStatus').textContent = '';
            ['pdReceiptEmailBtn', 'pdReceiptWhatsAppBtn'].forEach(function (bid) {
                const b = document.getElementById(bid);
                if (b) { b.disabled = false; b.style.opacity = '1'; }
            });
            const modal = document.getElementById('pdReceiptModal');
            if (modal) { modal.style.display = 'flex'; modal.setAttribute('aria-hidden', 'false'); }
        }

        function pdCloseReceiptModal() {
            const modal = document.getElementById('pdReceiptModal');
            if (modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
        }

        async function pdSendReceipt(channel) {
            if (!pdReceiptId) return;
            const isEmail = channel === 'email';
            const recipient = (isEmail
                ? document.getElementById('pdReceiptEmail').value
                : document.getElementById('pdReceiptPhone').value
            ).trim();
            if (!recipient) {
                const sid = isEmail ? 'pdReceiptEmailStatus' : 'pdReceiptWhatsAppStatus';
                document.getElementById(sid).innerHTML = '<span style="color:#dc2626;">Enter a ' + (isEmail ? 'email address' : 'phone number') + '.</span>';
                return;
            }

            const btnId = isEmail ? 'pdReceiptEmailBtn' : 'pdReceiptWhatsAppBtn';
            const statusId = isEmail ? 'pdReceiptEmailStatus' : 'pdReceiptWhatsAppStatus';
            const btn = document.getElementById(btnId);
            const statusEl = document.getElementById(statusId);
            btn.disabled = true;
            btn.style.opacity = '0.6';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
            statusEl.style.color = '#6c757d';

            try {
                let url, fd;
                if (pdReceiptMode === 'order') {
                    // Restaurant/POS order — use stock-receipt.php
                    fd = new FormData();
                    fd.append('csrf_token', pdCsrfToken);
                    fd.append('action', isEmail ? 'email_receipt' : 'whatsapp_receipt');
                    fd.append('order_id', String(pdReceiptId));
                    fd.append('recipient', recipient);
                    url = 'stock-receipt.php?id=' + pdReceiptId;
                } else {
                    // Hotel / conference payment — use ajax-receipt.php
                    fd = new FormData();
                    fd.append('csrf_token', pdCsrfToken);
                    fd.append('payment_id', String(pdReceiptId));
                    fd.append('action', isEmail ? 'email' : 'whatsapp');
                    fd.append('recipient', recipient);
                    url = 'ajax-receipt.php';
                }

                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                const j = await r.json();

                if (j.ok) {
                    if (!isEmail && j.url) {
                        window.open(j.url, '_blank', 'noopener');
                    }
                    statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> ' + (j.message || 'Sent');
                    statusEl.style.color = '#16a34a';
                } else {
                    statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> ' + (j.error || 'Failed');
                    statusEl.style.color = '#dc2626';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            } catch (err) {
                statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#dc2626;"></i> Network error';
                statusEl.style.color = '#dc2626';
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        }

        // Close on overlay click
        document.getElementById('pdReceiptModal').addEventListener('click', function (e) {
            if (e.target === this) pdCloseReceiptModal();
        });

        // Expose to inline onclick handlers
        window.pdOpenReceiptModal = pdOpenReceiptModal;
        window.pdCloseReceiptModal = pdCloseReceiptModal;
        window.pdSendReceipt = pdSendReceipt;

        <?php if (!empty($_GET['new_payment'])): ?>
        // Auto-open receipt modal when redirected here after a new payment
        document.addEventListener('DOMContentLoaded', function () {
            pdOpenReceiptModal(
                <?php echo ($payment['booking_type'] === 'restaurant' && !empty($payment['booking_id'])) ? (int)$payment['booking_id'] : $paymentId; ?>,
                '<?php echo ($payment['booking_type'] === 'restaurant' && !empty($payment['booking_id'])) ? 'order' : 'payment'; ?>'
            );
        });
        <?php endif; ?>
    }());
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

