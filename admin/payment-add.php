<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once 'includes/finance-schema.php';
require_once '../includes/idempotency.php';
require_once '../includes/finance-sequences.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$csrf_token = $csrf_token ?? generateCsrfToken();
$conferenceFields = finance_conference_fields($pdo);
$paymentTransactionColumn = finance_payment_transaction_column($pdo);
finance_ensure_sequence_tables($pdo);

// Get VAT settings
$vatEnabled = getSetting('vat_enabled') === '1';
$vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

// Check if editing existing payment
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$payment = null;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$editId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $_SESSION['alert'] = ['type' => 'info', 'message' => 'Payment not found. It may have been deleted or does not exist.'];
        header('Location: payments.php');
        exit;
    }
}

$paymentTransactionValue = $payment[$paymentTransactionColumn] ?? '';

// Get booking type and ID from query params for new payment
$bookingType = isset($_GET['booking_type']) ? $_GET['booking_type'] : '';
$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

// Pre-fill from existing payment or query params
if ($payment) {
    $bookingType = $payment['booking_type'];
    $bookingId = $payment['booking_id'];
}

// Get booking details
$bookingDetails = null;
$outstandingAmount = 0;

if ($bookingType && $bookingId) {
    if ($bookingType === 'room') {
        $stmt = $pdo->prepare("
            SELECT
                b.id,
                b.booking_reference,
                b.guest_name,
                b.guest_email,
                b.number_of_guests,
                b.adult_guests,
                b.child_guests,
                b.child_supplement_total,
                b.total_amount,
                b.folio_charges_total,
                b.amount_paid,
                b.amount_due,
                b.vat_rate,
                b.check_in_date,
                b.check_out_date,
                r.name as room_name
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate folio summary for accurate totals
        $folioSummary = getBookingFolioSummary($bookingId);
        $outstandingAmount = $folioSummary['balance_due'] ?? $bookingDetails['amount_due'] ?? 0;
    } elseif ($bookingType === 'conference') {
        $stmt = $pdo->prepare("
            SELECT
                ci.id,
                ci.{$conferenceFields['reference']} as enquiry_reference,
                ci.{$conferenceFields['company']} as organization_name,
                ci.{$conferenceFields['contact_name']} as contact_name,
                ci.{$conferenceFields['email']} as contact_email,
                ci.total_amount,
                ci.amount_paid,
                ci.amount_due,
                ci.vat_rate,
                ci.{$conferenceFields['start_date']} as start_date,
                ci.{$conferenceFields['end_date']} as end_date,
                ci.deposit_required,
                ci.deposit_paid
            FROM conference_inquiries ci
            WHERE ci.id = ?
        ");
        $stmt->execute([$bookingId]);
        $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        $outstandingAmount = $bookingDetails['amount_due'] ?? 0;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Security token invalid. Refresh and try again.'];
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'payment-add.php'));
        exit;
    }

    // Idempotency: redirect to existing payment if this client_uuid was already used.
    // Edits (?edit=N) bypass this — user is intentionally re-saving the same row.
    $__incomingClientUuid = $_POST['client_uuid'] ?? null;
    if (!$editId && ($__existingPayment = idem_find_existing_payment($pdo, $__incomingClientUuid))) {
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Payment already recorded (' . htmlspecialchars((string)$__existingPayment['payment_reference']) . '). Duplicate submission ignored.'];
        header('Location: payment-details.php?id=' . (int)$__existingPayment['id']);
        exit;
    }
    $bookingType = $_POST['booking_type'] ?? '';
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $paymentStatus = $_POST['payment_status'] ?? 'pending';
    $transactionReference = $_POST['transaction_reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    // Strip newlines before storage to prevent email header injection
    $ccEmails = str_replace(["\r", "\n"], '', $_POST['cc_emails'] ?? '');
    $processedBy = $user['full_name'] ?? ($user['username'] ?? 'System');

    // Validate
    if (!$bookingType || !$bookingId || !$paymentMethod) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Please fill in all required fields'];
    } elseif ($paymentAmount <= 0) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Payment amount must be greater than zero'];
    } elseif ($paymentAmount > 99999999) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Payment amount exceeds the maximum allowed value'];
    } else {
        try {
            if ($editId) {
                // Update existing payment
                $updateFields = [
                    'payment_date = ?',
                    'payment_amount = ?',
                    'payment_method = ?',
                    'payment_status = ?',
                    "{$paymentTransactionColumn} = ?",
                    'notes = ?',
                    'cc_emails = ?',
                    'processed_by = ?'
                ];

                $params = [
                    $paymentDate,
                    $paymentAmount,
                    $paymentMethod,
                    $paymentStatus,
                    $transactionReference ?: null,
                    $notes ?: null,
                    $ccEmails ?: null,
                    $processedBy
                ];

                // Recalculate VAT
                $paymentVatRate = $vatRate;
                $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
                $totalAmount = round($paymentAmount + $paymentVatAmount, 2);
                $updateFields[] = 'vat_rate = ?';
                $updateFields[] = 'vat_amount = ?';
                $updateFields[] = 'total_amount = ?';
                $params[] = $paymentVatRate;
                $params[] = $paymentVatAmount;
                $params[] = $totalAmount;

                $needsReceiptNumber = $paymentStatus === 'completed' && $payment['payment_status'] !== 'completed' && !$payment['receipt_number'];

                $pdo->beginTransaction();

                if ($needsReceiptNumber) {
                    $updateFields[] = 'receipt_number = ?';
                    $params[] = finance_next_receipt_number($pdo, $paymentDate);
                }

                $params[] = $editId;

                $sql = "UPDATE payments SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // Update booking totals
                if ($bookingType === 'room') {
                    updateRoomBookingPayments($pdo, $bookingId);
                } elseif ($bookingType === 'conference') {
                    updateConferenceEnquiryPayments($pdo, $bookingId);
                }
                // restaurant payments are tracked via stock_orders — no separate balance update needed

                $pdo->commit();

                // Audit trail: log every payment edit with before/after details
                rh_log_event('payment-add', 'info', 'Payment record updated', [
                    'payment_id'   => $editId,
                    'booking_type' => $bookingType,
                    'booking_id'   => $bookingId,
                    'amount'       => $totalAmount,
                    'status'       => $paymentStatus,
                    'method'       => $paymentMethod,
                    'by'           => $user['username'] ?? null,
                ]);

                $_SESSION['alert'] = ['type' => 'success', 'message' => 'Payment updated successfully'];
                header('Location: payment-details.php?id=' . $editId);
                exit;
            } else {
                // Create new payment
                $paymentVatRate = $vatRate;
                $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
                $totalAmount = round($paymentAmount + $paymentVatAmount, 2);

                // Generate payment reference
                do {
                    $paymentRef = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
                    $refCheck = $pdo->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_reference = ?");
                    $refCheck->execute([$paymentRef]);
                    $refExists = $refCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                } while ($refExists);

                $pdo->beginTransaction();

                $receiptNumber = $paymentStatus === 'completed'
                    ? finance_next_receipt_number($pdo, $paymentDate)
                    : null;

                // Get booking reference for the payment record
                $bookingReference = '';
                if ($bookingType === 'room') {
                    $refStmt = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                    $refStmt->execute([$bookingId]);
                    $refData = $refStmt->fetch(PDO::FETCH_ASSOC);
                    $bookingReference = $refData['booking_reference'] ?? '';
                } elseif ($bookingType === 'conference') {
                    $refStmt = $pdo->prepare("SELECT {$conferenceFields['reference']} as conference_reference FROM conference_inquiries WHERE id = ?");
                    $refStmt->execute([$bookingId]);
                    $refData = $refStmt->fetch(PDO::FETCH_ASSOC);
                    $bookingReference = $refData['conference_reference'] ?? '';
                }

                $stmt = $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference, payment_date,
                        payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_status, status, {$paymentTransactionColumn},
                        receipt_number, cc_emails, processed_by, notes, client_uuid
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $__paymentClientUuid = idem_normalize_uuid($__incomingClientUuid ?? null);
                $stmt->execute([
                    $paymentRef,
                    $bookingType,
                    $bookingId,
                    $bookingReference,
                    $paymentDate,
                    $paymentAmount,
                    $paymentVatRate,
                    $paymentVatAmount,
                    $totalAmount,
                    $paymentMethod,
                    $paymentStatus,
                    $paymentStatus,
                    $transactionReference ?: null,
                    $receiptNumber,
                    $ccEmails ?: null,
                    $processedBy,
                    $notes ?: null,
                    $__paymentClientUuid
                ]);

                $newPaymentId = $pdo->lastInsertId();

                // Update booking totals
                if ($bookingType === 'room') {
                    updateRoomBookingPayments($pdo, $bookingId);
                } elseif ($bookingType === 'conference') {
                    updateConferenceEnquiryPayments($pdo, $bookingId);
                }
                // restaurant payments are tracked via stock_orders — no separate balance update needed

                $pdo->commit();
                // Burn the per-render idempotency token so a fresh one is issued next render.
                unset($_SESSION['admin_payment_add_uuid']);

                // Send payment confirmation email for room bookings
                if ($bookingType === 'room' && $paymentStatus === 'completed') {
                    try {
                        // Merge default CC recipients with additional CCs from form
                        $defaultCcRecipients = getEmailSetting('invoice_recipients', '');
                        $smtpUsername = getEmailSetting('smtp_username', '');

                        // Parse default recipients
                        $allCcRecipients = array_filter(array_map('trim', explode(',', $defaultCcRecipients)));

                        // Add SMTP username to CC list
                        if (!empty($smtpUsername) && !in_array($smtpUsername, $allCcRecipients)) {
                            $allCcRecipients[] = $smtpUsername;
                        }

                        // Add additional CCs from form — validate each address
                        if (!empty($ccEmails)) {
                            $additionalCc = array_filter(
                                array_map('trim', explode(',', $ccEmails)),
                                static function (string $e): bool {
                                    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
                                }
                            );
                            foreach ($additionalCc as $email) {
                                if (!in_array($email, $allCcRecipients, true)) {
                                    $allCcRecipients[] = $email;
                                }
                            }
                        }

                        // Send payment invoice with CC recipients
                        $email_result = sendPaymentInvoiceEmailWithCC($bookingId, $allCcRecipients);
                        if (!$email_result['success']) {
                            error_log("Failed to send room payment invoice email: " . $email_result['message']);
                        } else {
                            $logMsg = "Room payment invoice email sent successfully";
                            if (isset($email_result['preview_url'])) {
                                $logMsg .= " - Preview: " . $email_result['preview_url'];
                            }
                            if (!empty($allCcRecipients)) {
                                $logMsg .= " - CC: " . implode(', ', $allCcRecipients);
                            }
                            error_log($logMsg);
                        }
                    } catch (Exception $e) {
                        error_log("Error sending room payment invoice email: " . $e->getMessage());
                    }
                }

                // Send invoice email for conference bookings
                if ($bookingType === 'conference' && $paymentStatus === 'completed') {
                    try {
                        // Merge default CC recipients with additional CCs from form
                        $defaultCcRecipients = getEmailSetting('invoice_recipients', '');
                        $smtpUsername = getEmailSetting('smtp_username', '');

                        // Parse default recipients
                        $allCcRecipients = array_filter(array_map('trim', explode(',', $defaultCcRecipients)));

                        // Add SMTP username to CC list
                        if (!empty($smtpUsername) && !in_array($smtpUsername, $allCcRecipients)) {
                            $allCcRecipients[] = $smtpUsername;
                        }

                        // Add additional CCs from form — validate each address
                        if (!empty($ccEmails)) {
                            $additionalCc = array_filter(
                                array_map('trim', explode(',', $ccEmails)),
                                static function (string $e): bool {
                                    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
                                }
                            );
                            foreach ($additionalCc as $email) {
                                if (!in_array($email, $allCcRecipients, true)) {
                                    $allCcRecipients[] = $email;
                                }
                            }
                        }

                        // Generate invoice and send with CC recipients
                        $email_result = sendConferenceInvoiceEmailWithCC($bookingId, $allCcRecipients);
                        if (!$email_result['success']) {
                            error_log("Failed to send conference invoice email: " . $email_result['message']);
                        } else {
                            $logMsg = "Conference invoice email sent successfully";
                            if (isset($email_result['preview_url'])) {
                                $logMsg .= " - Preview: " . $email_result['preview_url'];
                            }
                            if (!empty($allCcRecipients)) {
                                $logMsg .= " - CC: " . implode(', ', $allCcRecipients);
                            }
                            error_log($logMsg);
                        }
                    } catch (Exception $e) {
                        error_log("Error sending conference invoice email: " . $e->getMessage());
                    }
                }

                $_SESSION['alert'] = ['type' => 'success', 'message' => 'Payment recorded successfully'];
                header('Location: payment-details.php?id=' . $newPaymentId);
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['alert'] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['alert'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Helper functions
function updateRoomBookingPayments(PDO $pdo, int $bookingId)
{
    recalculateBookingFinancials($bookingId);
}

function updateConferenceEnquiryPayments(PDO $pdo, int $enquiryId)
{
    $enquiryStmt = $pdo->prepare("SELECT total_amount, deposit_required FROM conference_inquiries WHERE id = ?");
    $enquiryStmt->execute([$enquiryId]);
    $enquiry = $enquiryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$enquiry) return;

    $totalAmount = (float)$enquiry['total_amount'];
    $depositRequired = (float)$enquiry['deposit_required'];

    $paidStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as paid,
            SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END) as vat_paid
        FROM payments
        WHERE booking_type = 'conference'
        AND booking_id = ?
        AND deleted_at IS NULL
    ");
    $paidStmt->execute([$enquiryId]);
    $paid = $paidStmt->fetch(PDO::FETCH_ASSOC);

    $amountPaid = (float)($paid['paid'] ?? 0);
    $vatPaid = (float)($paid['vat_paid'] ?? 0);
    $amountDue = max(0, $totalAmount - $amountPaid);
    $depositPaid = min($amountPaid, $depositRequired);

    $lastPaymentStmt = $pdo->prepare("
        SELECT MAX(payment_date) as last_payment_date
        FROM payments
        WHERE booking_type = 'conference'
        AND booking_id = ?
        AND payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
    ");
    $lastPaymentStmt->execute([$enquiryId]);
    $lastPayment = $lastPaymentStmt->fetch(PDO::FETCH_ASSOC);

    $vatEnabled = getSetting('vat_enabled') === '1';
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatAmount = $totalAmount * ($vatRate / 100);
    $totalWithVat = $totalAmount + $vatAmount;

    $updateStmt = $pdo->prepare("
        UPDATE conference_inquiries
        SET amount_paid = ?,
            amount_due = ?,
            vat_rate = ?,
            vat_amount = ?,
            total_with_vat = ?,
            deposit_paid = ?,
            last_payment_date = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $amountPaid,
        $amountDue,
        $vatRate,
        $vatAmount,
        $totalWithVat,
        $depositPaid,
        $lastPayment['last_payment_date'],
        $enquiryId
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editId ? 'Edit Payment' : 'Record Payment'; ?> | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-finance.css">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="form-container">
            <h2 class="section-title"><?php echo $editId ? 'Edit Payment' : 'Record New Payment'; ?></h2>

            <form method="POST" data-offline-queue="1"
                data-admin-confirm="Record this payment change now?"
                data-admin-confirm-title="Confirm payment"
                data-admin-confirm-details="Please verify the booking, amount, method, date, and status before saving.|Receipts and account balances may be updated."
                data-admin-confirm-ok="<?php echo $editId ? 'Update Payment' : 'Record Payment'; ?>"
                data-admin-confirm-icon="fa-money-bill-wave"
                data-admin-loader-text="Saving payment..."
                data-admin-submit-text="Saving...">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <?php
                // Per-render idempotency token. Burned on success below.
                if (!$editId && empty($_SESSION['admin_payment_add_uuid'])) {
                    $_SESSION['admin_payment_add_uuid'] = bin2hex(random_bytes(16));
                }
                ?>
                <?php if (!$editId): ?>
                    <input type="hidden" name="client_uuid" value="<?php echo htmlspecialchars($_SESSION['admin_payment_add_uuid']); ?>">
                <?php endif; ?>
                <!-- Booking Selection -->
                <div class="form-section">
                    <h3><i class="fas fa-calendar-check"></i> Booking Information</h3>

                    <?php if ($bookingDetails): ?>
                        <div class="booking-info">
                            <h4><?php echo ucfirst($bookingType); ?> Booking Details</h4>
                            <?php if ($bookingType === 'room'): ?>
                                <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['booking_reference']); ?></p>
                                <p><strong>Guest:</strong> <?php echo htmlspecialchars($bookingDetails['guest_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['guest_email']); ?></p>
                                <p><strong>Room:</strong> <?php echo htmlspecialchars($bookingDetails['room_name']); ?></p>
                                <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['check_in_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['check_out_date'])); ?></p>
                                <p><strong>Guest Split:</strong>
                                    <?php
                                    $payChildGuests = (int)($bookingDetails['child_guests'] ?? 0);
                                    $payAdultGuests = (int)($bookingDetails['adult_guests'] ?? max(1, ((int)$bookingDetails['number_of_guests']) - $payChildGuests));
                                    echo $payAdultGuests . ' adult' . ($payAdultGuests === 1 ? '' : 's');
                                    if ($payChildGuests > 0) {
                                        echo ' + ' . $payChildGuests . ' child' . ($payChildGuests === 1 ? '' : 'ren');
                                    }
                                    ?>
                                </p>
                                <?php if ((float)($bookingDetails['child_supplement_total'] ?? 0) > 0): ?>
                                    <p><strong>Child Supplement:</strong> <?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['child_supplement_total'], 0); ?></p>
                                <?php endif; ?>
                                <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['total_amount'], 0); ?></p>
                                <p><strong>Amount Paid:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amount_paid'], 0); ?></p>
                                <p><strong>Amount Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amount_due'], 0); ?></span></p>
                            <?php else: ?>
                                <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['enquiry_reference']); ?></p>
                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($bookingDetails['organization_name']); ?></p>
                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($bookingDetails['contact_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['contact_email']); ?></p>
                                <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['start_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['end_date'])); ?></p>
                                <p><strong>Total Amount:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['total_amount'], 0); ?></p>
                                <p><strong>Amount Paid:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amount_paid'], 0); ?></p>
                                <p><strong>Amount Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['amount_due'], 0); ?></span></p>
                                <?php if ($bookingDetails['deposit_required'] > 0): ?>
                                    <p><strong>Deposit Required:</strong> <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['deposit_required'], 0); ?> (Paid: <?php echo $currency_symbol; ?><?php echo number_format($bookingDetails['deposit_paid'], 0); ?>)</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <input type="hidden" name="booking_type" value="<?php echo htmlspecialchars($bookingType); ?>">
                        <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                    <?php else: ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Booking Type <span class="required">*</span></label>
                                <select name="booking_type" id="booking_type" required>
                                    <option value="">Select type...</option>
                                    <option value="room" <?php echo $bookingType === 'room' ? 'selected' : ''; ?>>Room Booking</option>
                                    <option value="conference" <?php echo $bookingType === 'conference' ? 'selected' : ''; ?>>Conference Booking</option>
                                </select>
                            </div>

                            <div class="form-group" style="position: relative;">
                                <label>Booking ID <span class="required">*</span></label>
                                <div style="display: flex; gap: 8px;">
                                    <input type="number" name="booking_id" id="booking_id" value="<?php echo $bookingId; ?>" required
                                        style="flex: 1;" placeholder="Enter booking ID or search...">
                                    <button type="button" id="search_booking_btn" class="btn btn-secondary" style="padding: 12px 16px;">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div id="booking_search_results" class="booking-search-results" style="display: none;"></div>
                                <div class="help-text">Enter the booking ID manually or click search to find bookings</div>
                            </div>
                        </div>

                        <!-- Dynamic Booking Info Section -->
                        <div id="dynamic_booking_info" class="booking-info" style="display: none;">
                            <h4 id="booking_info_title">Booking Details</h4>
                            <div id="booking_info_content"></div>
                            <button type="button" id="clear_booking_btn" class="btn btn-secondary" style="margin-top: 12px; padding: 8px 16px; font-size: 13px;">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Details -->
                <div class="form-section">
                    <h3><i class="fas fa-money-bill-wave"></i> Payment Details</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Amount <span class="required">*</span></label>
                            <input type="number" name="payment_amount" id="payment_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($payment['payment_amount'] ?? ''); ?>" required>
                            <div class="help-text">Amount before VAT</div>
                        </div>

                        <div class="form-group">
                            <label>Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars($payment['payment_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Method <span class="required">*</span></label>
                            <select name="payment_method" required>
                                <option value="">Select method...</option>
                                <option value="cash" <?php echo ($payment['payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo ($payment['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit_card" <?php echo ($payment['payment_method'] ?? '') === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="debit_card" <?php echo ($payment['payment_method'] ?? '') === 'debit_card' ? 'selected' : ''; ?>>Debit Card</option>
                                <option value="mobile_money" <?php echo ($payment['payment_method'] ?? '') === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="cheque" <?php echo ($payment['payment_method'] ?? '') === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="other" <?php echo ($payment['payment_method'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Payment Status <span class="required">*</span></label>
                            <select name="payment_status" required>
                                <option value="pending" <?php echo ($payment['payment_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo ($payment['payment_status'] ?? '') === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="paid" <?php echo ($payment['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="completed" <?php echo ($payment['payment_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="refunded" <?php echo ($payment['payment_status'] ?? '') === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                <option value="cancelled" <?php echo ($payment['payment_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Transaction Reference</label>
                        <input type="text" name="transaction_reference" value="<?php echo htmlspecialchars($paymentTransactionValue); ?>" placeholder="Bank reference, cheque number, etc.">
                    </div>

                    <div class="form-group">
                        <label>Additional CC Emails</label>
                        <input type="text" name="cc_emails" value="<?php echo htmlspecialchars($payment['cc_emails'] ?? ''); ?>" placeholder="email1@example.com, email2@example.com">
                        <div class="help-text">Comma-separated email addresses to receive a copy of the payment receipt (in addition to default recipients)</div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Any additional notes about this payment..."><?php echo htmlspecialchars($payment['notes'] ?? ''); ?></textarea>
                    </div>

                    <!-- Calculation Preview -->
                    <div class="calculation-preview" id="calculation-preview">
                        <h4>Payment Calculation</h4>
                        <div class="calc-row">
                            <span>Subtotal:</span>
                            <span id="subtotal-display"><?php echo $currency_symbol; ?>0.00</span>
                        </div>
                        <?php if ($vatEnabled): ?>
                            <div class="calc-row">
                                <span>VAT (<?php echo $vatRate; ?>%):</span>
                                <span id="vat-display"><?php echo $currency_symbol; ?>0.00</span>
                            </div>
                        <?php endif; ?>
                        <div class="calc-row total">
                            <span>Total:</span>
                            <span id="total-display"><?php echo $currency_symbol; ?>0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="payments.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editId ? 'Update Payment' : 'Record Payment'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const paymentAmount = document.getElementById('payment_amount');
        const subtotalDisplay = document.getElementById('subtotal-display');
        const vatDisplay = document.getElementById('vat-display');
        const totalDisplay = document.getElementById('total-display');
        const vatRate = <?php echo $vatRate; ?>;
        const currencySymbol = '<?php echo $currency_symbol; ?>';
        const vatEnabled = <?php echo $vatEnabled ? 'true' : 'false'; ?>;

        function updateCalculation() {
            const amount = parseFloat(paymentAmount.value) || 0;
            const vatAmount = vatEnabled ? amount * (vatRate / 100) : 0;
            const total = amount + vatAmount;

            subtotalDisplay.textContent = currencySymbol + Number(amount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            if (vatEnabled) {
                vatDisplay.textContent = currencySymbol + Number(vatAmount).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
            totalDisplay.textContent = currencySymbol + Number(total).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        paymentAmount.addEventListener('input', updateCalculation);

        // Initial calculation
        if (paymentAmount.value) {
            updateCalculation();
        }

        // Booking Search Functionality
        const bookingTypeSelect = document.getElementById('booking_type');
        const bookingIdInput = document.getElementById('booking_id');
        const searchBtn = document.getElementById('search_booking_btn');
        const searchResults = document.getElementById('booking_search_results');
        let searchTimeout = null;

        function searchBookings() {
            const bookingType = bookingTypeSelect.value;
            const searchTerm = bookingIdInput.value.trim();

            if (!bookingType) {
                searchResults.innerHTML = '<div class="booking-search-no-results">Please select a booking type first</div>';
                searchResults.style.display = 'block';
                return;
            }

            if (searchTerm.length < 1) {
                // Show recent bookings when search is empty
                loadRecentBookings(bookingType);
                return;
            }

            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            searchResults.style.display = 'block';

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Debounce search
            searchTimeout = setTimeout(() => {
                fetch(`api/search-bookings.php?type=${bookingType}&q=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(data => {
                        displaySearchResults(data);
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        searchResults.innerHTML = '<div class="booking-search-no-results">Error searching bookings</div>';
                    });
            }, 300);
        }

        function loadRecentBookings(bookingType) {
            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Loading recent bookings...</div>';
            searchResults.style.display = 'block';

            fetch(`api/search-bookings.php?type=${bookingType}&recent=1`)
                .then(response => response.json())
                .then(data => {
                    displaySearchResults(data, true);
                })
                .catch(error => {
                    console.error('Load recent error:', error);
                    searchResults.innerHTML = '<div class="booking-search-no-results">Error loading recent bookings</div>';
                });
        }

        function displaySearchResults(data, isRecent = false) {
            if (!data.bookings || data.bookings.length === 0) {
                searchResults.innerHTML = '<div class="booking-search-no-results">' + (isRecent ? 'No recent bookings found' : 'No bookings found matching your search') + '</div>';
                return;
            }

            let html = '';
            data.bookings.forEach(booking => {
                if (bookingTypeSelect.value === 'room') {
                    html += `
                        <div class="booking-search-item" data-id="${booking.id}">
                            <strong>${booking.booking_reference} - ${booking.guest_name}</strong>
                            <small>ID: ${booking.id} | Room: ${booking.room_name || 'N/A'} | ${booking.check_in_date} to ${booking.check_out_date}</small>
                            <small style="color: ${booking.amount_due > 0 ? '#dc3545' : '#28a745'};">Due: ${currencySymbol}${booking.amount_due.toLocaleString()}</small>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="booking-search-item" data-id="${booking.id}">
                            <strong>${booking.enquiry_reference} - ${booking.organization_name || booking.contact_name}</strong>
                            <small>ID: ${booking.id} | Event: ${booking.start_date} to ${booking.end_date}</small>
                            <small style="color: ${booking.amount_due > 0 ? '#dc3545' : '#28a745'};">Due: ${currencySymbol}${booking.amount_due.toLocaleString()}</small>
                        </div>
                    `;
                }
            });

            searchResults.innerHTML = html;

            // Add click handlers
            searchResults.querySelectorAll('.booking-search-item').forEach(item => {
                item.addEventListener('click', function() {
                    const bookingId = this.dataset.id;
                    bookingIdInput.value = bookingId;
                    searchResults.style.display = 'none';

                    // Auto-populate booking details
                    fetchBookingDetails(bookingTypeSelect.value, bookingId);
                });
            });
        }

        // Fetch and populate booking details
        function fetchBookingDetails(bookingType, bookingId) {
            if (!bookingType || !bookingId) return;

            const dynamicInfo = document.getElementById('dynamic_booking_info');
            const infoContent = document.getElementById('booking_info_content');
            const infoTitle = document.getElementById('booking_info_title');
            const paymentAmountInput = document.getElementById('payment_amount');
            const paymentDateInput = document.querySelector('input[name="payment_date"]');
            const paymentMethodSelect = document.querySelector('select[name="payment_method"]');
            const paymentStatusSelect = document.querySelector('select[name="payment_status"]');

            // Show loading state
            dynamicInfo.style.display = 'block';
            infoTitle.textContent = 'Loading...';
            infoContent.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading booking details...</div>';

            // Fetch booking details from API
            fetch(`api/search-bookings.php?type=${bookingType}&q=${bookingId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.bookings || data.bookings.length === 0) {
                        infoTitle.textContent = 'Error';
                        infoContent.innerHTML = '<p style="color: #dc3545;">Booking not found. Please try again.</p>';
                        return;
                    }

                    const booking = data.bookings[0];
                    const isFullyPaid = booking.amount_due <= 0;

                    // Update booking info styling based on payment status
                    if (isFullyPaid) {
                        dynamicInfo.classList.add('fully-paid');
                    } else {
                        dynamicInfo.classList.remove('fully-paid');
                    }

                    if (bookingType === 'room') {
                        infoTitle.innerHTML = 'Room Booking Details' + (isFullyPaid ? ' <span class="fully-paid-badge">FULLY PAID</span>' : '');
                        infoContent.innerHTML = `
                            <p><strong>Reference:</strong> ${booking.booking_reference}</p>
                            <p><strong>Guest:</strong> ${booking.guest_name}</p>
                            <p><strong>Email:</strong> ${booking.guest_email || 'N/A'}</p>
                            <p><strong>Room:</strong> ${booking.room_name || 'N/A'}</p>
                            <p><strong>Dates:</strong> ${booking.check_in_date} - ${booking.check_out_date}</p>
                            <p><strong>Total Amount:</strong> ${currencySymbol}${booking.total_amount.toLocaleString()}</p>
                            <p><strong>Amount Paid:</strong> ${currencySymbol}${booking.amount_paid.toLocaleString()}</p>
                            <p><strong>Amount Due:</strong> <span style="color: ${booking.amount_due > 0 ? '#dc3545' : '#28a745'}; font-weight: 600;">${currencySymbol}${booking.amount_due.toLocaleString()}</span></p>
                            <p><strong>Guest Split:</strong> ${booking.adult_guests || Math.max(1, (booking.number_of_guests || 1) - (booking.child_guests || 0))} adult${(booking.adult_guests || Math.max(1, (booking.number_of_guests || 1) - (booking.child_guests || 0))) === 1 ? '' : 's'}${(booking.child_guests || 0) > 0 ? ` + ${booking.child_guests} child${booking.child_guests === 1 ? '' : 'ren'}` : ''}</p>
                            ${(booking.child_supplement_total || 0) > 0 ? `<p><strong>Child Supplement:</strong> ${currencySymbol}${Number(booking.child_supplement_total).toLocaleString()}</p>` : ''}
                            ${isFullyPaid ? '<div class="warning-box" style="margin-top: 12px;"><h5><i class="fas fa-exclamation-triangle"></i> Fully Paid Booking</h5><p>This booking has been fully paid. Adding additional payments will create a credit balance.</p></div>' : ''}
                        `;
                    } else {
                        infoTitle.innerHTML = 'Conference Booking Details' + (isFullyPaid ? ' <span class="fully-paid-badge">FULLY PAID</span>' : '');
                        infoContent.innerHTML = `
                            <p><strong>Reference:</strong> ${booking.enquiry_reference}</p>
                            <p><strong>Organization:</strong> ${booking.organization_name || 'N/A'}</p>
                            <p><strong>Contact:</strong> ${booking.contact_name}</p>
                            <p><strong>Email:</strong> ${booking.contact_email || 'N/A'}</p>
                            <p><strong>Dates:</strong> ${booking.start_date} - ${booking.end_date}</p>
                            <p><strong>Total Amount:</strong> ${currencySymbol}${booking.total_amount.toLocaleString()}</p>
                            <p><strong>Amount Paid:</strong> ${currencySymbol}${booking.amount_paid.toLocaleString()}</p>
                            <p><strong>Amount Due:</strong> <span style="color: ${booking.amount_due > 0 ? '#dc3545' : '#28a745'}; font-weight: 600;">${currencySymbol}${booking.amount_due.toLocaleString()}</span></p>
                            ${isFullyPaid ? '<div class="warning-box" style="margin-top: 12px;"><h5><i class="fas fa-exclamation-triangle"></i> Fully Paid Booking</h5><p>This booking has been fully paid. Adding additional payments will create a credit balance.</p></div>' : ''}
                        `;
                    }

                    // Handle fully paid bookings
                    if (isFullyPaid) {
                        // Disable payment amount field
                        paymentAmountInput.disabled = true;
                        paymentAmountInput.value = '';
                        updateCalculation();

                        // Add warning and override checkbox
                        const paymentSection = document.querySelector('.form-section:nth-child(2)');
                        let warningBox = paymentSection.querySelector('.fully-paid-warning');

                        if (!warningBox) {
                            warningBox = document.createElement('div');
                            warningBox.className = 'warning-box fully-paid-warning';
                            warningBox.innerHTML = `
                                <h5><i class="fas fa-exclamation-triangle"></i> Warning: Fully Paid Booking</h5>
                                <p>This booking has been fully paid. The payment amount field has been disabled to prevent accidental overpayment.</p>
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" id="allow_manual_payment" name="allow_manual_payment">
                                    <label for="allow_manual_payment">Allow manual payment entry (for refunds, adjustments, or credit)</label>
                                </div>
                            `;
                            paymentSection.insertBefore(warningBox, paymentSection.querySelector('.form-row'));

                            // Add event listener for override checkbox
                            document.getElementById('allow_manual_payment').addEventListener('change', function() {
                                paymentAmountInput.disabled = !this.checked;
                                if (!this.checked) {
                                    paymentAmountInput.value = '';
                                    updateCalculation();
                                }
                            });
                        }
                    } else {
                        // Re-enable payment amount field
                        paymentAmountInput.disabled = false;

                        // Remove warning box if exists
                        const warningBox = document.querySelector('.fully-paid-warning');
                        if (warningBox) {
                            warningBox.remove();
                        }

                        // Auto-fill payment amount with outstanding amount
                        paymentAmountInput.value = booking.amount_due;
                        updateCalculation();
                    }

                    // Auto-fill payment date with today's date
                    if (!paymentDateInput.value) {
                        paymentDateInput.value = new Date().toISOString().split('T')[0];
                    }

                    // Auto-fill payment status based on booking payment status
                    // Default to 'completed' for new payments
                    if (!paymentStatusSelect.value || paymentStatusSelect.value === 'pending') {
                        paymentStatusSelect.value = 'completed';
                    }

                    // Auto-fill payment method with default or last used
                    // Try to get last used payment method from localStorage
                    const lastPaymentMethod = localStorage.getItem('lastPaymentMethod');
                    if (lastPaymentMethod && paymentMethodSelect.value === '') {
                        paymentMethodSelect.value = lastPaymentMethod;
                    } else if (paymentMethodSelect.value === '') {
                        // Set default to 'cash' if no previous method
                        paymentMethodSelect.value = 'cash';
                    }
                })
                .catch(error => {
                    console.error('Error fetching booking details:', error);
                    infoTitle.textContent = 'Error';
                    infoContent.innerHTML = '<p style="color: #dc3545;">Failed to load booking details. Please try again.</p>';
                });
        }

        // Clear booking selection
        const clearBookingBtn = document.getElementById('clear_booking_btn');
        if (clearBookingBtn) {
            clearBookingBtn.addEventListener('click', function() {
                const dynamicInfo = document.getElementById('dynamic_booking_info');
                const paymentAmountInput = document.getElementById('payment_amount');

                // Hide booking info
                dynamicInfo.style.display = 'none';
                dynamicInfo.classList.remove('fully-paid');

                // Clear booking ID
                bookingIdInput.value = '';

                // Re-enable and clear payment amount
                paymentAmountInput.disabled = false;
                paymentAmountInput.value = '';
                updateCalculation();

                // Remove warning box if exists
                const warningBox = document.querySelector('.fully-paid-warning');
                if (warningBox) {
                    warningBox.remove();
                }
            });
        }

        // Save payment method to localStorage when changed
        const paymentMethodSelect = document.querySelector('select[name="payment_method"]');
        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', function() {
                if (this.value) {
                    localStorage.setItem('lastPaymentMethod', this.value);
                }
            });
        }

        // Event listeners
        searchBtn.addEventListener('click', searchBookings);

        bookingIdInput.addEventListener('focus', function() {
            if (bookingTypeSelect.value) {
                loadRecentBookings(bookingTypeSelect.value);
            }
        });

        bookingIdInput.addEventListener('input', function() {
            if (this.value.trim().length > 0) {
                searchBookings();
            } else {
                // Clear booking info when input is cleared
                const dynamicInfo = document.getElementById('dynamic_booking_info');
                const paymentAmountInput = document.getElementById('payment_amount');

                dynamicInfo.style.display = 'none';
                dynamicInfo.classList.remove('fully-paid');
                paymentAmountInput.disabled = false;
                paymentAmountInput.value = '';
                updateCalculation();

                // Remove warning box if exists
                const warningBox = document.querySelector('.fully-paid-warning');
                if (warningBox) {
                    warningBox.remove();
                }
            }
        });

        bookingTypeSelect.addEventListener('change', function() {
            searchResults.style.display = 'none';
            bookingIdInput.value = '';

            const dynamicInfo = document.getElementById('dynamic_booking_info');
            const paymentAmountInput = document.getElementById('payment_amount');

            dynamicInfo.style.display = 'none';
            dynamicInfo.classList.remove('fully-paid');
            paymentAmountInput.disabled = false;
            paymentAmountInput.value = '';
            updateCalculation();

            // Remove warning box if exists
            const warningBox = document.querySelector('.fully-paid-warning');
            if (warningBox) {
                warningBox.remove();
            }
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.form-group') || !e.target.closest('[style*="position: relative"]')) {
                searchResults.style.display = 'none';
            }
        });
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
