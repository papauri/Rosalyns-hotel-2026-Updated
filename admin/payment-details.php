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
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-finance.css">
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
                <a href="invoices.php?search=<?php echo urlencode($payment['payment_reference']); ?>" class="acct-quick-action">
                    <i class="fas fa-file-invoice"></i> Invoice
                </a>
                <?php if (!empty($payment['customer_phone'])): ?>
                    <?php $waPhone = preg_replace('/[^0-9]/', '', (string)$payment['customer_phone']); ?>
                    <?php if ($waPhone !== ''): ?>
                        <a href="https://wa.me/<?php echo htmlspecialchars($waPhone); ?>?text=<?php echo urlencode('Hello, this is ' . $site_name . ' accounts. Payment reference: ' . ($payment['payment_reference'] ?? '')); ?>" target="_blank" rel="noopener" class="acct-quick-action">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
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
                        <span class="status-badge badge-<?php echo $payment['payment_status']; ?>">
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

        <!-- Booking Information -->
        <?php if ($bookingDetails): ?>
            <div class="detail-card" style="margin-bottom: 24px;">
                <h3><i class="fas fa-calendar-check"></i> Booking Information</h3>

                <div class="booking-summary">
                    <h4><?php echo ucfirst($bookingDetails['type']); ?> Booking</h4>

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
                        <p><strong>Status:</strong> <span class="status-badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
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
                        <p><strong>Status:</strong> <span class="status-badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php else: ?>
                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['reference']); ?></p>
                        <p><strong>Customer:</strong> <?php echo htmlspecialchars($bookingDetails['customer']['name']); ?></p>
                        <?php if (!empty($bookingDetails['customer']['table_number'])): ?>
                            <p><strong>Table:</strong> <?php echo htmlspecialchars($bookingDetails['customer']['table_number']); ?></p>
                        <?php endif; ?>
                        <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_amount'], 0); ?></p>
                        <p><strong>Estimated Stock Cost:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amounts']['total_cost'], 0); ?></p>
                        <p><strong>Status:</strong> <span class="status-badge badge-<?php echo $bookingDetails['status']; ?>"><?php echo ucfirst($bookingDetails['status']); ?></span></p>
                    <?php endif; ?>
                </div>

                <a href="<?php echo $bookingDetails['type'] === 'room' ? 'booking-details.php?id=' . $bookingDetails['id'] : ($bookingDetails['type'] === 'restaurant' ? 'stock-orders.php' : 'conference-management.php'); ?>" class="btn-primary" style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                    <i class="fas fa-external-link-alt"></i> View Full <?php echo $bookingDetails['type'] === 'restaurant' ? 'Order' : 'Booking'; ?> Details
                </a>
            </div>
        <?php endif; ?>

        <!-- Other Payments for this Booking -->
        <?php if (!empty($otherPayments)): ?>
            <div class="detail-card">
                <h3><i class="fas fa-list"></i> Other Payments for this Booking</h3>

                <div class="other-payments">
                    <?php foreach ($otherPayments as $otherPayment): ?>
                        <div class="payment-item">
                            <div class="payment-item-info">
                                <div class="payment-item-ref"><?php echo htmlspecialchars($otherPayment['payment_reference']); ?></div>
                                <div class="payment-item-date"><?php echo date('M j, Y', strtotime($otherPayment['payment_date'])); ?></div>
                            </div>
                            <div class="payment-item-amount">
                                <?php echo $currency_symbol; ?><?php echo number_format($otherPayment['total_amount'], 0); ?>
                                <span class="status-badge badge-<?php echo $otherPayment['payment_status']; ?>" style="margin-left: 8px;">
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

    <?php require_once 'includes/admin-footer.php'; ?>

