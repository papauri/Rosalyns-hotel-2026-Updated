<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once 'includes/finance-schema.php';
require_once __DIR__ . '/includes/finance-account-sync.php';

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

// ── Settle refund: pending -> processing -> completed / failed ───────────────────
// The only way a refund row changes state. Locks the refund row, validates the
// transition, then resyncs the original payment's status and the account balance
// (room folio, conference/gym/event snapshot, restaurant order) in the SAME transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle_refund') {
    $settleBack = 'payment-details.php?id=' . $paymentId;
    $settlePermKey = isset(getAllPermissions()['refund_payment']) ? 'refund_payment' : 'payment_add';
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Security token invalid. Refresh and try again.'];
    } elseif (!hasPermission((int)$user['id'], $settlePermKey)) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'You do not have permission to settle refunds.'];
    } else {
        $targetStatus = (string)($_POST['new_refund_status'] ?? '');
        $settleNote   = trim((string)($_POST['settle_notes'] ?? ''));
        try {
            if (!in_array($targetStatus, ['processing', 'completed', 'failed'], true)) {
                throw new Exception('Choose a valid refund status.');
            }
            $pdo->beginTransaction();
            // Lock order matches payment-refund.php: order row -> original payment -> refund row.
            if ((string)$payment['booking_type'] === 'restaurant') {
                $pdo->prepare("SELECT id FROM stock_orders WHERE id = ? FOR UPDATE")->execute([(int)$payment['booking_id']]);
            }
            if (!empty($payment['original_payment_id'])) {
                $pdo->prepare("SELECT id FROM payments WHERE id = ? FOR UPDATE")->execute([(int)$payment['original_payment_id']]);
            }
            $lockStmt = $pdo->prepare("SELECT id, booking_type, booking_id, original_payment_id, payment_reference, payment_method, refund_status, refund_reason, COALESCE(refund_amount, total_amount) AS amt FROM payments WHERE id = ? AND payment_type = 'refund' AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->execute([$paymentId]);
            $refundRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$refundRow) {
                throw new Exception('Refund record not found.');
            }
            if ((string)$refundRow['payment_method'] === 'credit_note') {
                throw new Exception('Store-credit refunds are settled when the credit note is issued. Void the credit note to reverse one.');
            }
            $from = (string)$refundRow['refund_status'];
            $allowed = [
                'pending'    => ['processing', 'completed', 'failed'],
                'processing' => ['completed', 'failed'],
            ];
            if (!isset($allowed[$from]) || !in_array($targetStatus, $allowed[$from], true)) {
                throw new Exception('A ' . $from . ' refund cannot be moved to ' . $targetStatus . '.');
            }
            $newPaymentStatus = $targetStatus === 'completed' ? 'completed' : ($targetStatus === 'failed' ? 'cancelled' : 'pending');
            $settleLine = ' [' . date('Y-m-d H:i') . ' ' . ($user['username'] ?? 'staff') . ': ' . $from . ' -> ' . $targetStatus . ($settleNote !== '' ? ' - ' . mb_substr($settleNote, 0, 250) : '') . ']';
            $pdo->prepare("
                UPDATE payments
                SET refund_status = ?, payment_status = ?, status = ?,
                    refund_date_processed = CASE WHEN ? IN ('completed','failed') THEN NOW() ELSE refund_date_processed END,
                    refund_notes = CONCAT(COALESCE(refund_notes, ''), ?), updated_at = NOW()
                WHERE id = ?
            ")->execute([$targetStatus, $newPaymentStatus, $targetStatus === 'completed' ? 'completed' : ($targetStatus === 'failed' ? 'failed' : 'pending'), $targetStatus, $settleLine, $paymentId]);

            rh_apply_refund_side_effects(
                $pdo,
                (string)$refundRow['booking_type'],
                (int)$refundRow['booking_id'],
                !empty($refundRow['original_payment_id']) ? (int)$refundRow['original_payment_id'] : null,
                'Refund ' . $refundRow['payment_reference'] . ' ' . $targetStatus
            );
            $pdo->commit();

            rh_log_event('payment-details', 'info', 'Refund settled', [
                'refund_id' => $paymentId, 'from' => $from, 'to' => $targetStatus,
                'amount' => (float)$refundRow['amt'], 'by' => $user['username'] ?? null,
            ]);
            $_SESSION['alert'] = ['type' => 'success', 'message' => 'Refund ' . $refundRow['payment_reference'] . ' is now ' . $targetStatus . '.'];
        } catch (Throwable $settleEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['alert'] = ['type' => 'error', 'message' => 'Could not settle the refund: ' . $settleEx->getMessage()];
        }
    }
    header('Location: ' . $settleBack);
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

// Payment summary for this account — canonical net-paid rule (originals incl. refunded /
// partially refunded, minus completed + processing refunds) and the account's GROSS bill.
$paymentSummaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN payment_status = 'pending' AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) as pending_amount,
        COUNT(CASE WHEN payment_status IN ('completed', 'paid', 'refunded', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN 1 END) as completed_payments,
        COUNT(CASE WHEN payment_status = 'pending' AND COALESCE(payment_type, '') != 'refund' THEN 1 END) as pending_payments
    FROM payments
    WHERE booking_type = ? AND booking_id = ? AND deleted_at IS NULL
");
$paymentSummaryStmt->execute([$payment['booking_type'], $payment['booking_id']]);
$paymentSummary = $paymentSummaryStmt->fetch(PDO::FETCH_ASSOC);
$paymentSummary['total_paid'] = rh_sum_account_paid($pdo, (string)$payment['booking_type'], (int)$payment['booking_id']);

// Account bill (gross): room = folio grand total; conference/gym/event = locked gross
// (a cancelled account's bill is void); restaurant = order total.
$bookingTotalAmount = 0.0;
if ($payment['booking_type'] === 'room') {
    $folioForTotal = getBookingFolioSummary((int)$payment['booking_id']);
    $bookingTotalAmount = (float)($folioForTotal['grand_total'] ?? 0);
} elseif (in_array($payment['booking_type'], ['conference', 'gym', 'event'], true)) {
    $acctTables = ['conference' => 'conference_inquiries', 'gym' => 'gym_inquiries', 'event' => 'event_inquiries'];
    $acctStmt = $pdo->prepare("SELECT status, total_amount, total_with_vat FROM {$acctTables[$payment['booking_type']]} WHERE id = ?");
    $acctStmt->execute([$payment['booking_id']]);
    if ($acctRow = $acctStmt->fetch(PDO::FETCH_ASSOC)) {
        $bookingTotalAmount = ((string)$acctRow['status'] === 'cancelled') ? 0.0 : rh_account_gross_total($acctRow);
    }
} elseif ($bookingDetails && isset($bookingDetails['amounts']['total_amount'])) {
    $bookingTotalAmount = (float)$bookingDetails['amounts']['total_amount'];
}

// Calculate due amount (within BALANCE_TOLERANCE counts as settled)
$dueAmount = $bookingTotalAmount - $paymentSummary['total_paid'];
if ($dueAmount <= BALANCE_TOLERANCE) {
    $dueAmount = 0.0;
}

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
        <?php
        $pd_is_refund = (($payment['payment_type'] ?? '') === 'refund');
        $pd_status_map = ['completed' => 'ok', 'paid' => 'ok', 'refunded' => 'muted', 'partially_refunded' => 'warn', 'pending' => 'warn', 'failed' => 'alert', 'cancelled' => 'alert'];
        $pd_status_cls = $pd_status_map[(string)$payment['payment_status']] ?? 'muted';
        ?>
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Payment <?php echo htmlspecialchars($payment['payment_reference']); ?></h1>
                    <span class="rh-pill rh-pill--<?php echo $pd_status_cls; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_status']))); ?></span>
                    <?php if ($pd_is_refund): ?>
                        <span class="rh-pill rh-pill--alert">Refund</span>
                    <?php endif; ?>
                </div>
                <p class="rh-page-head__meta">
                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?> &middot;
                    <?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?> &middot;
                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?>
                </p>
            </div>
            <div class="rh-page-head__actions">
                <?php if ($payment['payment_status'] !== 'completed' && !$pd_is_refund): ?>
                    <a href="payment-add.php?edit=<?php echo $paymentId; ?>" class="btn btn--primary btn--sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                <?php endif; ?>
                <?php if (in_array($payment['payment_status'], ['completed', 'paid', 'partially_refunded'], true) && !$pd_is_refund): ?>
                    <a href="payment-refund.php?id=<?php echo $paymentId; ?>" class="btn btn--secondary btn--sm">
                        <i class="fas fa-undo"></i> Refund
                    </a>
                <?php endif; ?>
                <?php if (in_array($payment['payment_status'], ['completed', 'paid'], true) && !$pd_is_refund): ?>
                    <?php if (($payment['booking_type'] ?? '') === 'restaurant' && !empty($payment['booking_id'])): ?>
                        <button type="button" class="btn btn--secondary btn--sm" onclick="pdOpenReceiptModal(<?php echo (int)$payment['booking_id']; ?>, 'order')">
                            <i class="fas fa-paper-plane"></i> Send Receipt
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn--secondary btn--sm" onclick="pdOpenReceiptModal(<?php echo $paymentId; ?>, 'payment')">
                            <i class="fas fa-paper-plane"></i> Send Receipt
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="invoices.php?search=<?php echo urlencode($payment['payment_reference']); ?>" class="btn btn--secondary btn--sm">
                    <i class="fas fa-file-invoice"></i> Invoice
                </a>
                <a href="payments.php" class="btn btn--ghost btn--sm" onclick="if(history.length>1){history.back();return false;}">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </header>


        <?php
        $paymentPercentage = $bookingTotalAmount > 0 ? ($paymentSummary['total_paid'] / $bookingTotalAmount) * 100 : 0;
        $paymentStatusText = $dueAmount <= 0 ? 'Fully Paid' : ($paymentSummary['total_paid'] > 0 ? 'Partially Paid' : 'Unpaid');
        ?>
        <div class="rh-strip" role="group" aria-label="Payment summary">
            <div class="rh-strip__cell">
                <span class="rh-strip__label">This payment</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol . number_format((float)$payment['total_amount'], 0); ?></strong>
                <span class="rh-strip__sub">
                    Subtotal <?php echo $currency_symbol . number_format((float)$payment['payment_amount'], 0); ?>
                    <?php if ((float)$payment['vat_amount'] > 0): ?>
                        &middot; VAT <?php echo $currency_symbol . number_format((float)$payment['vat_amount'], 0); ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Booking total</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol . number_format((float)$bookingTotalAmount, 0); ?></strong>
                <span class="rh-strip__sub">Paid <?php echo $currency_symbol . number_format((float)$paymentSummary['total_paid'], 0); ?> of total</span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Outstanding</span>
                <strong class="rh-strip__value <?php echo $dueAmount > 0 ? 'rh-strip__value--alert' : 'rh-strip__value--ok'; ?>"><?php echo $currency_symbol . number_format(max(0, (float)$dueAmount), 0); ?></strong>
                <span class="rh-strip__sub"><?php echo htmlspecialchars($paymentStatusText); ?> &middot; <?php echo number_format($paymentPercentage, 0); ?>% complete</span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Refunded</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol . number_format($totalRefundedHere, 0); ?></strong>
                <span class="rh-strip__sub"><?php echo count($refundsAgainst); ?> refund<?php echo count($refundsAgainst) === 1 ? '' : 's'; ?></span>
            </div>
        </div>

        <?php
        $pd_pill = static function (string $status): string {
            $map = ['completed' => 'ok', 'paid' => 'ok', 'confirmed' => 'ok', 'checked-in' => 'ok', 'checked-out' => 'muted', 'refunded' => 'muted',
                'partially_refunded' => 'warn', 'pending' => 'warn', 'processing' => 'warn', 'tentative' => 'warn',
                'failed' => 'alert', 'cancelled' => 'alert', 'no-show' => 'alert'];
            return '<span class="rh-pill rh-pill--' . ($map[$status] ?? 'muted') . '">' . htmlspecialchars(ucfirst(str_replace(['_', '-'], ' ', $status))) . '</span>';
        };
        if ($bookingDetails) {
            // Per-type vocabulary: a gym payment is a membership, an event payment
            // an event booking, a restaurant payment an order - not a room booking.
            $pd_type_labels = [
                'room'       => ['heading' => 'Booking',       'noun' => 'Booking'],
                'conference' => ['heading' => 'Booking',       'noun' => 'Booking'],
                'restaurant' => ['heading' => 'Order',         'noun' => 'Order'],
                'gym'        => ['heading' => 'Membership',    'noun' => 'Membership'],
                'event'      => ['heading' => 'Event booking', 'noun' => 'Event Booking'],
            ];
            $pd_labels = $pd_type_labels[$bookingDetails['type']] ?? ['heading' => 'Record', 'noun' => 'Record'];
            // Preset-aware source link: restaurant payments go to the orders console only
            // when the stock module is on (POS-only presets keep the till); gym/event
            // payments go to their inquiry pages.
            $pd_source_href = match ($bookingDetails['type']) {
                'room'       => 'booking-details.php?id=' . $bookingDetails['id'],
                'restaurant' => (function_exists('moduleEnabled') && moduleEnabled('stock')) ? 'stock-orders.php' : 'pos.php',
                'gym'        => 'gym-inquiries.php',
                'event'      => 'events-inquiries.php',
                default      => 'conference-management.php',
            };
        }
        ?>
        <div class="rh-layout">
            <div>
                <?php if ($originalPayment): ?>
                    <section class="rh-panel">
                        <p class="rh-empty">
                            <i class="fas fa-rotate-left"></i> This is a refund of original payment
                            <a class="rh-mini-link" href="payment-details.php?id=<?php echo (int)$originalPayment['id']; ?>"><?php echo htmlspecialchars($originalPayment['payment_reference']); ?></a>
                            &middot; <?php echo $currency_symbol . number_format((float)$originalPayment['total_amount'], 0); ?>
                            &middot; <?php echo date('M j, Y', strtotime($originalPayment['payment_date'])); ?>
                        </p>
                    </section>
                <?php endif; ?>

                <?php if (($payment['payment_type'] ?? '') === 'refund' && in_array((string)($payment['refund_status'] ?? ''), ['pending', 'processing'], true) && (string)$payment['payment_method'] !== 'credit_note'): ?>
                    <section class="rh-panel">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Settle this refund</h2>
                            <span class="rh-page-head__meta">Currently <?php echo htmlspecialchars((string)$payment['refund_status']); ?></span>
                        </div>
                        <form method="POST" action="payment-details.php?id=<?php echo (int)$paymentId; ?>" class="rh-panel__body pd-settle-form"
                            data-admin-confirm="Update this refund's status?" data-admin-confirm-title="Settle refund">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="settle_refund">
                            <div class="pd-settle-form__field">
                                <label for="new_refund_status">Move to</label>
                                <select id="new_refund_status" name="new_refund_status" required>
                                    <?php if ((string)$payment['refund_status'] === 'pending'): ?>
                                        <option value="processing">Processing (sent to provider)</option>
                                    <?php endif; ?>
                                    <option value="completed">Completed (money paid out)</option>
                                    <option value="failed">Failed (money not paid out)</option>
                                </select>
                            </div>
                            <div class="pd-settle-form__field pd-settle-form__field--grow">
                                <label for="settle_notes">Note (optional)</label>
                                <input type="text" id="settle_notes" name="settle_notes" maxlength="250" placeholder="Provider reference, reason for failure...">
                            </div>
                            <button type="submit" class="btn btn--primary btn--sm"><i class="fas fa-check"></i> Update refund</button>
                        </form>
                    </section>
                <?php endif; ?>

                <!-- Payment -->
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Payment</h2>
                    </div>
                    <table class="rh-kv no-auto-pagination">
                        <tbody>
                            <tr><th scope="row">Reference</th><td><?php echo htmlspecialchars($payment['payment_reference']); ?></td></tr>
                            <tr><th scope="row">Date</th><td><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></td></tr>
                            <tr><th scope="row">Method</th><td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?></td></tr>
                            <tr><th scope="row">Status</th><td><?php echo $pd_pill((string)$payment['payment_status']); ?></td></tr>
                            <?php if (!empty($payment['transaction_reference_value'])): ?>
                                <tr><th scope="row">Transaction reference</th><td><?php echo htmlspecialchars($payment['transaction_reference_value']); ?></td></tr>
                            <?php endif; ?>
                            <tr><th scope="row">Processed by</th><td><?php echo htmlspecialchars($payment['processed_by'] ?? 'System'); ?></td></tr>
                            <tr><th scope="row">Created</th><td><?php echo date('F j, Y g:i A', strtotime($payment['created_at'])); ?></td></tr>
                            <?php if ($payment['updated_at'] !== $payment['created_at']): ?>
                                <tr><th scope="row">Last updated</th><td><?php echo date('F j, Y g:i A', strtotime($payment['updated_at'])); ?></td></tr>
                            <?php endif; ?>
                            <tr><th scope="row">Receipt no.</th><td><?php echo $payment['receipt_number'] ? htmlspecialchars($payment['receipt_number']) : '<span class="rh-page-head__meta">Not generated yet</span>'; ?></td></tr>
                            <?php if ($payment['notes']): ?>
                                <tr><th scope="row">Notes</th><td><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Amount breakdown -->
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Amount breakdown</h2>
                    </div>
                    <table class="rh-kv no-auto-pagination">
                        <tbody>
                            <tr><th scope="row">Subtotal (excl. VAT)</th><td><?php echo $currency_symbol; ?><?php echo number_format($payment['payment_amount'], 2); ?></td></tr>
                            <?php if ($payment['vat_amount'] > 0): ?>
                                <tr><th scope="row">VAT rate</th><td><?php echo number_format($payment['vat_rate'], 2); ?>%</td></tr>
                                <tr><th scope="row">VAT</th><td><?php echo $currency_symbol; ?><?php echo number_format($payment['vat_amount'], 2); ?></td></tr>
                            <?php endif; ?>
                            <tr><th scope="row">Total</th><td><strong><?php echo $currency_symbol; ?><?php echo number_format($payment['total_amount'], 2); ?></strong></td></tr>
                        </tbody>
                    </table>
                </section>

                <?php if (!empty($refundsAgainst)): ?>
                    <section class="rh-panel rh-panel--flush">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Refunds against this payment</h2>
                            <span class="rh-page-head__meta"><?php echo count($refundsAgainst); ?> total &middot; <?php echo $currency_symbol . number_format($totalRefundedHere, 0); ?> refunded</span>
                        </div>
                        <div class="rh-panel__body">
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
                                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($r['refund_reason'] ?? '-')))); ?></td>
                                            <td><?php echo $pd_pill((string)($r['refund_status'] ?? 'pending')); ?></td>
                                            <td><a class="rh-mini-link" href="payment-details.php?id=<?php echo (int)$r['id']; ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($otherPayments)): ?>
                    <section class="rh-panel rh-panel--flush">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Other payments for this <?php echo htmlspecialchars(isset($pd_labels) ? strtolower($pd_labels['noun']) : 'record'); ?></h2>
                        </div>
                        <div class="rh-panel__body">
                            <table class="acct-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Date</th>
                                        <th class="num">Amount</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($otherPayments as $otherPayment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($otherPayment['payment_reference']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($otherPayment['payment_date'])); ?></td>
                                            <td class="num"><strong><?php echo $currency_symbol; ?><?php echo number_format($otherPayment['total_amount'], 0); ?></strong></td>
                                            <td><?php echo $pd_pill((string)$otherPayment['payment_status']); ?></td>
                                            <td><a class="rh-mini-link" href="payment-details.php?id=<?php echo (int)$otherPayment['id']; ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="rh-layout__side">
                <?php if ($bookingDetails): ?>
                    <section class="rh-panel">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title"><?php echo htmlspecialchars($pd_labels['heading']); ?></h2>
                            <div class="rh-panel__actions">
                                <a href="<?php echo htmlspecialchars($pd_source_href); ?>" class="btn btn--secondary btn--sm">
                                    <i class="fas fa-external-link-alt"></i> View full <?php echo htmlspecialchars(strtolower($pd_labels['noun'])); ?>
                                </a>
                            </div>
                        </div>
                        <?php
                        $pd_amt = $bookingDetails['amounts'];
                        $pd_fmt = static function ($v) use ($currency_symbol) { return $currency_symbol . number_format((float)$v, 0); };
                        ?>
                        <table class="rh-kv no-auto-pagination">
                            <tbody>
                                <tr><th scope="row">Reference</th><td><?php echo htmlspecialchars((string)$bookingDetails['reference']); ?></td></tr>
                                <?php if ($bookingDetails['type'] === 'room'): ?>
                                    <tr><th scope="row">Room</th><td><?php echo htmlspecialchars((string)$bookingDetails['room']['name']); ?></td></tr>
                                    <tr><th scope="row">Guest</th><td><?php echo htmlspecialchars((string)$bookingDetails['guest']['name']); ?></td></tr>
                                    <tr><th scope="row">Email</th><td><?php echo htmlspecialchars((string)$bookingDetails['guest']['email']); ?></td></tr>
                                    <tr><th scope="row">Dates</th><td><?php echo date('M j, Y', strtotime($bookingDetails['dates']['check_in'])); ?> &ndash; <?php echo date('M j, Y', strtotime($bookingDetails['dates']['check_out'])); ?> (<?php echo (int)$bookingDetails['dates']['nights']; ?> nights)</td></tr>
                                <?php elseif ($bookingDetails['type'] === 'conference'): ?>
                                    <tr><th scope="row">Organization</th><td><?php echo htmlspecialchars((string)$bookingDetails['organization']['name']); ?></td></tr>
                                    <tr><th scope="row">Contact</th><td><?php echo htmlspecialchars((string)$bookingDetails['organization']['contact_person']); ?></td></tr>
                                    <tr><th scope="row">Email</th><td><?php echo htmlspecialchars((string)$bookingDetails['organization']['email']); ?></td></tr>
                                    <tr><th scope="row">Event type</th><td><?php echo htmlspecialchars((string)$bookingDetails['event']['type']); ?></td></tr>
                                    <tr><th scope="row">Dates</th><td><?php echo date('M j, Y', strtotime($bookingDetails['event']['start_date'])); ?> &ndash; <?php echo date('M j, Y', strtotime($bookingDetails['event']['end_date'])); ?></td></tr>
                                <?php elseif (in_array($bookingDetails['type'], ['gym', 'event'], true)): ?>
                                    <tr><th scope="row"><?php echo htmlspecialchars($bookingDetails['person']['label']); ?></th><td><?php echo htmlspecialchars((string)$bookingDetails['person']['name']); ?></td></tr>
                                    <?php if (!empty($bookingDetails['person']['email'])): ?>
                                        <tr><th scope="row">Email</th><td><?php echo htmlspecialchars((string)$bookingDetails['person']['email']); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($bookingDetails['detail_rows'] as $pd_dl => $pd_dv): ?>
                                        <tr><th scope="row"><?php echo htmlspecialchars($pd_dl); ?></th><td><?php echo htmlspecialchars((string)$pd_dv); ?></td></tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><th scope="row">Customer</th><td><?php echo htmlspecialchars((string)$bookingDetails['customer']['name']); ?></td></tr>
                                    <?php if (!empty($bookingDetails['customer']['table_number'])): ?>
                                        <tr><th scope="row">Table</th><td><?php echo htmlspecialchars((string)$bookingDetails['customer']['table_number']); ?></td></tr>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <tr><th scope="row">Total</th><td><?php echo $pd_fmt($pd_amt['total_amount']); ?></td></tr>
                                <?php if ($bookingDetails['type'] === 'restaurant'): ?>
                                    <tr><th scope="row">Est. stock cost</th><td><?php echo $pd_fmt($pd_amt['total_cost']); ?></td></tr>
                                <?php else: ?>
                                    <tr><th scope="row">Paid</th><td><?php echo $pd_fmt($pd_amt['amount_paid']); ?></td></tr>
                                    <tr><th scope="row">Due</th><td><strong class="<?php echo $pd_amt['amount_due'] > 0 ? 'rh-strip__value--alert' : 'rh-strip__value--ok'; ?>"><?php echo $pd_fmt($pd_amt['amount_due']); ?></strong></td></tr>
                                    <?php if ($bookingDetails['type'] === 'conference' && $pd_amt['deposit_required'] > 0): ?>
                                        <tr><th scope="row">Deposit</th><td><?php echo $pd_fmt($pd_amt['deposit_required']); ?> (paid <?php echo $pd_fmt($pd_amt['deposit_paid']); ?>)</td></tr>
                                    <?php endif; ?>
                                    <?php if (($pd_amt['vat_amount'] ?? 0) > 0): ?>
                                        <tr><th scope="row">VAT</th><td><?php echo $pd_fmt($pd_amt['vat_amount']); ?> (<?php echo htmlspecialchars((string)$pd_amt['vat_rate']); ?>%)</td></tr>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <tr><th scope="row">Status</th><td><?php echo $pd_pill((string)$bookingDetails['status']); ?></td></tr>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>

    <!-- Send Receipt Modal -->
    <div class="modal-overlay" id="pdReceiptModal" aria-hidden="true" style="display:none;">
        <div class="modal-content pd-receipt-dialog" role="dialog" aria-modal="true" aria-labelledby="pdReceiptTitle">
            <div class="modal-header">
                <div>
                    <h3 id="pdReceiptTitle"><i class="fas fa-paper-plane"></i> Send Receipt</h3>
                    <div class="pd-receipt-ref" id="pdReceiptRef"><?php echo htmlspecialchars($payment['payment_reference'] ?? ''); ?></div>
                </div>
                <button type="button" class="modal-close" aria-label="Close" onclick="pdCloseReceiptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="pd-receipt-grid">
                    <div>
                        <label for="pdReceiptEmail">Email</label>
                        <input type="email" id="pdReceiptEmail" placeholder="guest@example.com" value="<?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?>">
                        <button type="button" id="pdReceiptEmailBtn" class="btn btn-primary pd-receipt-send" onclick="pdSendReceipt('email')"><i class="fas fa-envelope"></i> Send email</button>
                        <div id="pdReceiptEmailStatus" class="pd-receipt-status"></div>
                    </div>
                    <div>
                        <label for="pdReceiptPhone">WhatsApp</label>
                        <input type="tel" id="pdReceiptPhone" placeholder="+265 999 123 456" value="<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)($payment['customer_phone'] ?? ''))); ?>">
                        <button type="button" id="pdReceiptWhatsAppBtn" class="btn btn-success pd-receipt-send" onclick="pdSendReceipt('whatsapp')"><i class="fab fa-whatsapp"></i> Send WhatsApp</button>
                        <div id="pdReceiptWhatsAppStatus" class="pd-receipt-status"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <?php if (($payment['booking_type'] ?? '') === 'restaurant' && !empty($payment['booking_id'])): ?>
                    <a href="stock-receipt.php?id=<?php echo (int)$payment['booking_id']; ?>&amp;print=1" target="_blank" rel="noopener" class="btn btn-secondary"><i class="fas fa-print"></i> Print</a>
                <?php endif; ?>
                <button type="button" class="btn-primary" onclick="pdCloseReceiptModal()">Done</button>
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
                document.getElementById(sid).innerHTML = '<span style="color:#dc2626;">Enter ' + (isEmail ? 'an email address' : 'a phone number') + '.</span>';
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

