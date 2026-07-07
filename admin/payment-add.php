<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once 'includes/finance-schema.php';
require_once 'includes/booking-lifecycle.php';
require_once 'includes/finance-account-sync.php';
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

// Module flags — the booking picker only offers account types whose module
// is enabled for this installation's business preset. Gym and event carry
// receivable balances too, so they are collectible here (not just from their
// own inquiry pages).
$pa_mod_bookings = function_exists('moduleEnabled') && moduleEnabled('bookings');
$pa_mod_conf     = function_exists('moduleEnabled') && moduleEnabled('conference');
$pa_mod_gym      = function_exists('moduleEnabled') && moduleEnabled('gym');
$pa_mod_event    = function_exists('isEventsEnabled') && isEventsEnabled();
$pa_any_booking  = $pa_mod_bookings || $pa_mod_conf || $pa_mod_gym || $pa_mod_event;
$pa_default_type = $pa_mod_bookings ? 'room' : ($pa_mod_conf ? 'conference' : ($pa_mod_gym ? 'gym' : ($pa_mod_event ? 'event' : 'room')));

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
    } elseif ($bookingType === 'gym') {
        $stmt = $pdo->prepare("
            SELECT id, reference_number AS enquiry_reference, name AS contact_name,
                   name AS organization_name, email AS contact_email,
                   total_amount, total_with_vat, amount_paid, amount_due, vat_rate,
                   created_at AS start_date, preferred_date AS end_date,
                   deposit_required, deposit_paid
            FROM gym_inquiries WHERE id = ?
        ");
        $stmt->execute([$bookingId]);
        $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        $outstandingAmount = $bookingDetails['amount_due'] ?? 0;
    } elseif ($bookingType === 'event') {
        $stmt = $pdo->prepare("
            SELECT id, reference_number AS enquiry_reference, name AS contact_name,
                   name AS organization_name, email AS contact_email,
                   total_amount, total_with_vat, amount_paid, amount_due, vat_rate,
                   created_at AS start_date, created_at AS end_date,
                   deposit_required, deposit_paid
            FROM event_inquiries WHERE id = ?
        ");
        $stmt->execute([$bookingId]);
        $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        $outstandingAmount = $bookingDetails['amount_due'] ?? 0;
    }
}

// Unified card display figures. "Total" must be the GROSS grand total (incl. VAT
// and, for rooms, folio extras) so it always equals Paid + Outstanding and can
// never read lower than the amount due. total_amount alone is the net base.
$paidDisplay = (float)($bookingDetails['amount_paid'] ?? 0);
$grandTotalDisplay = $paidDisplay + (float)$outstandingAmount;

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

    $allowedPaymentTypes = ['room', 'conference', 'restaurant', 'gym', 'event'];
    $typeEnabled = [
        'room'       => $pa_mod_bookings,
        'conference' => $pa_mod_conf,
        'gym'        => $pa_mod_gym,
        'event'      => $pa_mod_event,
    ];
    // Existing ledger rows must remain editable even if their module/preset is
    // currently off, or if they are POS/restaurant rows created by the till.
    $editingExistingPaymentType = $editId && $payment && $bookingType === (string)($payment['booking_type'] ?? '');

    // Validate
    if (!in_array($bookingType, $allowedPaymentTypes, true)
        || (!$editingExistingPaymentType && empty($typeEnabled[$bookingType]))) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'This account type is not available for the active preset.'];
    } elseif (!$bookingId || !$paymentMethod) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Please fill in all required fields'];
    } elseif ($paymentAmount <= 0) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Payment amount must be greater than zero'];
    } elseif ($paymentAmount > 99999999) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Payment amount exceeds the maximum allowed value'];
    } else {
        try {
            if ($editId) {
                // Update existing payment
                // The entered amount is the GROSS received. Extract VAT so the row
                // stays consistent: payment_amount (NET) + vat_amount = total_amount.
                $paymentVatRate = $vatRate;
                $paymentVatAmount = $paymentVatRate > 0 ? round($paymentAmount * ($paymentVatRate / (100 + $paymentVatRate)), 2) : 0.0;
                $totalAmount = $paymentAmount;                       // gross = what was received
                $paymentNet  = round($paymentAmount - $paymentVatAmount, 2);

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
                    $paymentNet,
                    $paymentMethod,
                    $paymentStatus,
                    $transactionReference ?: null,
                    $notes ?: null,
                    $ccEmails ?: null,
                    $processedBy
                ];

                $updateFields[] = 'vat_rate = ?';
                $updateFields[] = 'vat_amount = ?';
                $updateFields[] = 'total_amount = ?';
                $params[] = $paymentVatRate;
                $params[] = $paymentVatAmount;
                $params[] = $totalAmount;

                $needsReceiptNumber = in_array($paymentStatus, ['completed', 'paid'], true)
                    && !in_array((string)$payment['payment_status'], ['completed', 'paid'], true)
                    && !$payment['receipt_number'];

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
                } elseif ($bookingType === 'gym') {
                    syncGymInquiryPaymentSnapshot($pdo, $bookingId);
                } elseif ($bookingType === 'event') {
                    syncEventInquiryPaymentSnapshot($pdo, $bookingId);
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
                // Lifecycle guard: block new payments on terminal / fully-settled bookings
                if ($bookingType === 'room') {
                    $lcStmt = $pdo->prepare("SELECT status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
                    $lcStmt->execute([$bookingId]);
                    $lcRow = $lcStmt->fetch(PDO::FETCH_ASSOC);
                    if ($lcRow) {
                        $lcCheck = bookingAllowsAction($lcRow, 'record_payment');
                        if (!$lcCheck['allowed']) {
                            throw new Exception($lcCheck['reason']);
                        }
                    }
                }

                // The entered amount is the GROSS received (VAT-inclusive). Extract
                // the VAT portion so the payment row is internally consistent:
                // payment_amount (NET) + vat_amount = total_amount (GROSS).
                $paymentVatRate = $vatRate;
                $paymentVatAmount = $paymentVatRate > 0 ? round($paymentAmount * ($paymentVatRate / (100 + $paymentVatRate)), 2) : 0.0;
                $totalAmount = $paymentAmount;                       // gross = what was received
                $paymentNet  = round($paymentAmount - $paymentVatAmount, 2); // ex-VAT portion

                // Generate payment reference
                do {
                    $paymentRef = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
                    $refCheck = $pdo->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_reference = ?");
                    $refCheck->execute([$paymentRef]);
                    $refExists = $refCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                } while ($refExists);

                $pdo->beginTransaction();

                $receiptNumber = in_array($paymentStatus, ['completed', 'paid'], true)
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
                } elseif ($bookingType === 'gym') {
                    $refStmt = $pdo->prepare("SELECT reference_number FROM gym_inquiries WHERE id = ?");
                    $refStmt->execute([$bookingId]);
                    $bookingReference = (string)($refStmt->fetchColumn() ?: '');
                } elseif ($bookingType === 'event') {
                    $refStmt = $pdo->prepare("SELECT reference_number FROM event_inquiries WHERE id = ?");
                    $refStmt->execute([$bookingId]);
                    $bookingReference = (string)($refStmt->fetchColumn() ?: '');
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
                    $paymentNet,
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
                } elseif ($bookingType === 'gym') {
                    syncGymInquiryPaymentSnapshot($pdo, $bookingId);
                } elseif ($bookingType === 'event') {
                    syncEventInquiryPaymentSnapshot($pdo, $bookingId);
                }
                // restaurant payments are tracked via stock_orders — no separate balance update needed

                $pdo->commit();
                // Burn the per-render idempotency token so a fresh one is issued next render.
                unset($_SESSION['admin_payment_add_uuid']);

                // Overpayment check — auto-issue a credit note if the guest has overpaid
                $overpayMsg = '';
                if ($bookingType === 'room' && in_array($paymentStatus, ['completed', 'paid'], true)) {
                    $overpayResult = detectOverpayment($pdo, $bookingId, $paymentAmount);
                    if ($overpayResult['overpaid'] && $overpayResult['excess'] > 0) {
                        $b = $overpayResult['booking'];
                        try {
                            require_once __DIR__ . '/../config/credit-notes.php';
                            $cnResult = issueCreditNote($pdo, [
                                'booking_type'      => 'room',
                                'booking_id'        => $bookingId,
                                'booking_reference' => $b['booking_reference'] ?? '',
                                'guest_name'        => $b['guest_name'] ?? 'Guest',
                                'guest_email'       => $b['guest_email'] ?? '',
                                'amount'            => $overpayResult['excess'],
                                'reason'            => 'overpayment',
                                'reason_notes'      => 'Guest overpaid by ' . number_format($overpayResult['excess'], 2) . ' on payment ' . $paymentRef,
                                'issued_by'         => (int)$user['id'],
                                'send_email'        => !empty($b['guest_email']),
                                'generate_pdf'      => false,
                            ]);
                            if ($cnResult['success']) {
                                $overpayMsg = ' Overpayment detected — credit note ' . $cnResult['credit_note_number'] . ' for ' . number_format($overpayResult['excess'], 2) . ' issued and queued under Credit Notes.';
                            } else {
                                $overpayMsg = ' Overpayment of ' . number_format($overpayResult['excess'], 2) . ' detected. Credit note could not be auto-created — please issue one manually.';
                            }
                        } catch (Throwable $e) {
                            error_log("Overpayment credit note failed: " . $e->getMessage());
                            $overpayMsg = ' Overpayment of ' . number_format($overpayResult['excess'], 2) . ' detected — please issue a credit note manually.';
                        }
                    }
                }

                // Send payment confirmation email for room bookings
                if ($bookingType === 'room' && in_array($paymentStatus, ['completed', 'paid'], true)) {
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

                    // Send receipt email with PDF
                    try {
                        require_once __DIR__ . '/../config/receipts.php';
                        receipt_auto_send($pdo, (int)$newPaymentId, $user);
                    } catch (Throwable $rcptEx) {
                        error_log('Receipt email failed for payment ' . $newPaymentId . ': ' . $rcptEx->getMessage());
                    }
                }

                // Send invoice email for conference bookings
                if ($bookingType === 'conference' && in_array($paymentStatus, ['completed', 'paid'], true)) {
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

                $successMsg = 'Payment recorded successfully.' . ($overpayMsg ?? '');
                $_SESSION['alert'] = ['type' => 'success', 'message' => $successMsg];
                header('Location: payment-details.php?id=' . $newPaymentId . '&new_payment=1');
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
    $enquiryStmt = $pdo->prepare("SELECT total_amount, total_with_vat, deposit_required FROM conference_inquiries WHERE id = ?");
    $enquiryStmt->execute([$enquiryId]);
    $enquiry = $enquiryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$enquiry) return;

    $depositRequired = (float)$enquiry['deposit_required'];

    // amount_paid is the sum of GROSS completed non-refund payments (auditable).
    $amountPaid = rh_sum_account_paid($pdo, 'conference', $enquiryId);

    // amount_due is measured against the invoiced GROSS grand total. Prefer the
    // locked total_with_vat so a conference invoiced at a historical VAT rate is
    // never re-based to the current rate; only compute from net when it was
    // never populated. Invariant: total_with_vat = amount_paid + amount_due.
    $grossTotal = rh_account_gross_total($enquiry);
    $amountDue = max(0.0, round($grossTotal - $amountPaid, 2));
    $depositPaid = min($amountPaid, $depositRequired);
    $lastPaymentDate = rh_last_account_payment_date($pdo, 'conference', $enquiryId);

    // Only (re)populate the VAT breakdown when it was never locked, to keep the
    // stored figure faithful to the original invoice's rate.
    $storedGross = (float)($enquiry['total_with_vat'] ?? 0);
    if ($storedGross > 0.001) {
        $updateStmt = $pdo->prepare("
            UPDATE conference_inquiries
            SET amount_paid = ?, amount_due = ?, deposit_paid = ?, last_payment_date = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$amountPaid, $amountDue, $depositPaid, $lastPaymentDate, $enquiryId]);
    } else {
        $vatParts = vat_components((float)$enquiry['total_amount']);
        $updateStmt = $pdo->prepare("
            UPDATE conference_inquiries
            SET amount_paid = ?, amount_due = ?, vat_rate = ?, vat_amount = ?,
                total_with_vat = ?, deposit_paid = ?, last_payment_date = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $amountPaid, $amountDue, $vatParts['rate'], $vatParts['vat'],
            $vatParts['total'], $depositPaid, $lastPaymentDate, $enquiryId
        ]);
    }
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
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <link rel="stylesheet" href="css/payment-add.css?v=<?php echo @filemtime(__DIR__ . '/css/payment-add.css'); ?>">
    <link rel="stylesheet" href="css/admin-walkme.css" data-no-spa>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <!-- ══ Payment Workspace ══════════════════════════════════════════════ -->
    <div class="admin-container payment-add-container">

        <!-- Hero header -->
        <div class="payment-workspace__hero" id="pa-hero">
            <div>
                <span class="payment-workspace__eyebrow">Finance</span>
                <h1 class="payment-workspace__title">
                    <?php echo $editId ? 'Edit Payment' : 'Record New Payment'; ?>
                </h1>
                <p class="payment-workspace__subtitle">
                    <?php if ($editId): ?>
                        Updating payment record. Receipts and booking balances will be recalculated on save.
                    <?php else: ?>
                        Link a booking, choose how the guest paid, enter the amount — done.
                    <?php endif; ?>
                </p>
            </div>
            <div class="payment-workspace__hero-actions">
                <a href="payments.php" class="tbl-btn tbl-btn--view">
                    <i class="fas fa-arrow-left"></i> Back to Payments
                </a>
                <?php if (!$editId): ?>
                    <span id="pa-tour-anchor"></span>
                <?php endif; ?>
            </div>
        </div>

        <form id="pa-form" method="POST" data-offline-queue="1"
            data-admin-confirm="Record this payment change now?"
            data-admin-confirm-title="Confirm payment"
            data-admin-confirm-details="Please verify the booking, amount, method, date, and status before saving.|Receipts and account balances may be updated."
            data-admin-confirm-ok="<?php echo $editId ? 'Update Payment' : 'Record Payment'; ?>"
            data-admin-confirm-icon="fa-money-bill-wave"
            data-admin-loader-text="Saving payment..."
            data-admin-submit-text="Saving...">

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <?php
            if (!$editId && empty($_SESSION['admin_payment_add_uuid'])) {
                $_SESSION['admin_payment_add_uuid'] = bin2hex(random_bytes(16));
            }
            ?>
            <?php if (!$editId): ?>
                <input type="hidden" name="client_uuid" value="<?php echo htmlspecialchars($_SESSION['admin_payment_add_uuid']); ?>">
            <?php endif; ?>
            <input type="hidden" name="booking_type" id="hd-booking-type" value="<?php echo htmlspecialchars($bookingType !== '' ? $bookingType : $pa_default_type); ?>">
            <input type="hidden" name="booking_id"   id="hd-booking-id"   value="<?php echo $bookingId; ?>">

            <div class="payment-console">

                <!-- ── MAIN column ──────────────────────────────────── -->
                <div class="payment-console__main">

                    <!-- Step 1: Booking -->
                    <div class="payment-panel" id="pa-booking-panel">
                        <div class="payment-panel__header">
                            <div>
                                <span class="payment-panel__kicker">Step 1</span>
                                <h2 class="payment-panel__title"><i class="fas fa-calendar-check"></i> Booking</h2>
                            </div>
                            <?php if ($bookingDetails): ?>
                                <span class="payment-chip payment-chip--success"><i class="fas fa-check"></i> Linked</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($bookingDetails): ?>
                            <!-- Pre-linked booking card -->
                            <div class="payment-booking-card" id="pa-linked-card">
                                <div class="payment-booking-card__summary">
                                    <div>
                                        <p class="payment-booking-card__type"><?php echo ucfirst($bookingType); ?> Booking</p>
                                        <h3>
                                            <?php if ($bookingType === 'room'): ?>
                                                <?php echo htmlspecialchars($bookingDetails['guest_name']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($bookingDetails['organization_name'] ?? $bookingDetails['contact_name']); ?>
                                            <?php endif; ?>
                                        </h3>
                                        <p>
                                            <?php if ($bookingType === 'room'): ?>
                                                <?php echo htmlspecialchars($bookingDetails['booking_reference']); ?>
                                                &bull; <?php echo htmlspecialchars($bookingDetails['room_name']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($bookingDetails['enquiry_reference']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="payment-booking-card__balance">
                                        <span>Amount Due</span>
                                        <strong style="color:<?php echo $outstandingAmount > 0 ? 'var(--finance-danger)' : 'var(--finance-success)'; ?>">
                                            <?php echo $currency_symbol . number_format($outstandingAmount, 0); ?>
                                        </strong>
                                    </div>
                                </div>
                                <dl class="payment-booking-card__meta">
                                    <?php if ($bookingType === 'room'): ?>
                                        <div><dt>Check-in</dt><dd><?php echo date('M j, Y', strtotime($bookingDetails['check_in_date'])); ?></dd></div>
                                        <div><dt>Check-out</dt><dd><?php echo date('M j, Y', strtotime($bookingDetails['check_out_date'])); ?></dd></div>
                                    <?php else: ?>
                                        <div><dt>Start</dt><dd><?php echo !empty($bookingDetails['start_date']) ? date('M j, Y', strtotime($bookingDetails['start_date'])) : '—'; ?></dd></div>
                                        <div><dt>End</dt><dd><?php echo !empty($bookingDetails['end_date']) ? date('M j, Y', strtotime($bookingDetails['end_date'])) : '—'; ?></dd></div>
                                    <?php endif; ?>
                                    <div><dt>Total (incl. VAT<?php echo $bookingType === 'room' ? ' &amp; extras' : ''; ?>)</dt><dd><?php echo $currency_symbol . number_format($grandTotalDisplay, 0); ?></dd></div>
                                    <div><dt>Paid</dt><dd><?php echo $currency_symbol . number_format($paidDisplay, 0); ?></dd></div>
                                </dl>
                            </div>

                        <?php else: ?>
                            <?php if (!$pa_any_booking): ?>
                            <!-- No bookable account types on this preset — direct payments
                                 are captured at the POS till or from inquiry pages instead. -->
                            <div class="pa-picker" id="pa-picker">
                                <p style="margin:0;padding:0.9rem 1rem;background:var(--finance-warning-bg,#fff8e6);border:1px solid var(--finance-warning-border,#e8c98a);border-radius:8px;font-size:0.87rem;color:var(--finance-muted,#6b6156);">
                                    <i class="fas fa-circle-info"></i>
                                    No receivable account types are enabled for this business preset. POS sales are settled at the till; enable rooms, conference, gym or events in Module Settings to record account payments here.
                                </p>
                            </div>
                            <?php else: ?>
                            <!-- Booking search picker -->
                            <div class="pa-picker" id="pa-picker">
                                <div class="pa-picker__type-row">
                                    <?php if ($pa_mod_bookings): ?>
                                    <button type="button" class="pa-type-btn <?php echo $pa_default_type === 'room' ? 'is-active' : ''; ?>" data-type="room" id="pa-type-room">
                                        <i class="fas fa-bed"></i> Room Booking
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($pa_mod_conf): ?>
                                    <button type="button" class="pa-type-btn <?php echo $pa_default_type === 'conference' ? 'is-active' : ''; ?>" data-type="conference" id="pa-type-conference">
                                        <i class="fas fa-users"></i> Conference
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($pa_mod_gym): ?>
                                    <button type="button" class="pa-type-btn <?php echo $pa_default_type === 'gym' ? 'is-active' : ''; ?>" data-type="gym" id="pa-type-gym">
                                        <i class="fas fa-dumbbell"></i> Gym
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($pa_mod_event): ?>
                                    <button type="button" class="pa-type-btn <?php echo $pa_default_type === 'event' ? 'is-active' : ''; ?>" data-type="event" id="pa-type-event">
                                        <i class="fas fa-calendar-star"></i> Event
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <div class="pa-search-wrap" id="pa-search-wrap">
                                    <label class="pa-search-label" for="pa-search-input">
                                        <i class="fas fa-search"></i>
                                        Search by <?php echo $pa_mod_bookings ? 'guest' : 'contact'; ?> name, reference, or email
                                        <button type="button" class="wm-help" data-tooltip="Type at least 2 characters to search. Results show outstanding balance. Click any row to link it." aria-label="Help">?</button>
                                    </label>
                                    <input type="text"
                                        id="pa-search-input"
                                        class="pa-search-input"
                                        placeholder="e.g. Smith, RH-2024-001, guest@email.com…"
                                        autocomplete="off"
                                        spellcheck="false">
                                    <div id="pa-search-results" class="booking-search-results" hidden></div>
                                </div>
                            </div>

                            <!-- Selected booking card (hidden until picked) -->
                            <div class="payment-booking-card" id="pa-selected-card" hidden>
                                <div class="payment-booking-card__summary">
                                    <div>
                                        <p class="payment-booking-card__type" id="psc-type"></p>
                                        <h3 id="psc-name"></h3>
                                        <p id="psc-ref"></p>
                                    </div>
                                    <div class="payment-booking-card__balance">
                                        <span>Amount Due</span>
                                        <strong id="psc-due"></strong>
                                    </div>
                                </div>
                                <dl class="payment-booking-card__meta" id="psc-meta"></dl>
                                <div style="margin-top:1rem;">
                                    <button type="button" class="tbl-btn tbl-btn--view" id="pa-clear-btn">
                                        <i class="fas fa-times"></i> Change Booking
                                    </button>
                                </div>
                            </div>

                            <!-- Fully paid warning -->
                            <div id="pa-fullypaid-warn" style="display:none; margin-top:1rem; padding:1rem; background:var(--finance-warning-bg); border:1px solid var(--finance-warning-border); border-radius:8px;">
                                <p style="margin:0 0 0.5rem; font-weight:600; color:var(--finance-warning);"><i class="fas fa-exclamation-triangle"></i> Booking fully paid</p>
                                <p style="margin:0 0 0.75rem; font-size:0.85rem; color:var(--finance-muted);">This booking has no outstanding balance. Recording here will create a credit or overpayment.</p>
                                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;cursor:pointer;">
                                    <input type="checkbox" id="pa-override-cb"> Allow payment anyway (adjustment / credit)
                                </label>
                            </div>
                            <?php endif; /* $pa_any_booking */ ?>
                        <?php endif; ?>
                    </div>

                    <!-- Step 2: Payment method visual picker -->
                    <div class="payment-panel" id="pa-method-panel">
                        <div class="payment-panel__header payment-panel__header--compact">
                            <div>
                                <span class="payment-panel__kicker">Step 2</span>
                                <h2 class="payment-panel__title"><i class="fas fa-wallet"></i> Payment Method</h2>
                            </div>
                        </div>

                        <?php
                        $currentMethod = $payment['payment_method'] ?? '';
                        $methods = [
                            ['value' => 'cash',          'label' => 'Cash',          'icon' => 'fa-money-bill-wave'],
                            ['value' => 'bank_transfer',  'label' => 'Bank Transfer', 'icon' => 'fa-university'],
                            ['value' => 'credit_card',   'label' => 'Credit Card',   'icon' => 'fa-credit-card'],
                            ['value' => 'debit_card',    'label' => 'Debit Card',    'icon' => 'fa-credit-card'],
                            ['value' => 'mobile_money',  'label' => 'Mobile Money',  'icon' => 'fa-mobile-alt'],
                            ['value' => 'cheque',        'label' => 'Cheque',        'icon' => 'fa-file-invoice'],
                            ['value' => 'other',         'label' => 'Other',         'icon' => 'fa-ellipsis-h'],
                        ];
                        ?>
                        <div class="payment-method-grid" id="pa-method-grid">
                            <?php foreach ($methods as $m): ?>
                                <button type="button"
                                    class="payment-method-card <?php echo $currentMethod === $m['value'] ? 'is-active' : ''; ?>"
                                    data-method="<?php echo $m['value']; ?>"
                                    data-tooltip="<?php echo $m['label']; ?>">
                                    <i class="fas <?php echo $m['icon']; ?>"></i>
                                    <strong><?php echo $m['label']; ?></strong>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="payment_method" id="pa-method-hidden" value="<?php echo htmlspecialchars($currentMethod); ?>" required>
                        <p id="pa-method-err" class="pa-field-err" style="display:none; color:var(--finance-danger); font-size:0.82rem; margin-top:0.25rem;">Please choose a payment method.</p>

                        <div class="payment-form-row" style="margin-top:1rem;">
                            <div>
                                <label class="pa-label" for="pa-txn-ref">
                                    Transaction Reference
                                    <button type="button" class="wm-help" data-tooltip="Bank reference number, cheque number, mobile money transaction ID, etc." aria-label="Help">?</button>
                                </label>
                                <input type="text" id="pa-txn-ref" name="transaction_reference"
                                    value="<?php echo htmlspecialchars($paymentTransactionValue); ?>"
                                    placeholder="e.g. TXN-2024-00123, CHQ-0047…"
                                    class="pa-input">
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Amount & details -->
                    <div class="payment-panel" id="pa-amount-panel">
                        <div class="payment-panel__header payment-panel__header--compact">
                            <div>
                                <span class="payment-panel__kicker">Step 3</span>
                                <h2 class="payment-panel__title"><i class="fas fa-coins"></i> Amount & Details</h2>
                            </div>
                        </div>

                        <div class="payment-form-row">
                            <div>
                                <label class="pa-label" for="pa-amount">
                                    Payment Amount <span class="pa-required">*</span>
                                    <button type="button" class="wm-help" data-tooltip="Enter the total amount received<?php echo $vatEnabled ? ' (including VAT — the VAT portion is shown below)' : ''; ?>. This is what reduces the balance due. Leave blank only if fully paid and recording an adjustment." aria-label="Help">?</button>
                                </label>
                                <div class="pa-amount-wrap">
                                    <span class="pa-currency"><?php echo htmlspecialchars($currency_symbol); ?></span>
                                    <input type="number" id="pa-amount" name="payment_amount"
                                        step="0.01" min="0"
                                        value="<?php echo htmlspecialchars($payment['payment_amount'] ?? ''); ?>"
                                        class="pa-input pa-input--amount"
                                        placeholder="0.00"
                                        required>
                                </div>
                            </div>
                            <div>
                                <label class="pa-label" for="pa-date">
                                    Payment Date <span class="pa-required">*</span>
                                </label>
                                <input type="date" id="pa-date" name="payment_date"
                                    value="<?php echo htmlspecialchars($payment['payment_date'] ?? date('Y-m-d')); ?>"
                                    class="pa-input"
                                    required>
                            </div>
                        </div>

                        <div class="payment-form-row" style="margin-top:1rem;">
                            <div>
                                <label class="pa-label" for="pa-status">
                                    Payment Status <span class="pa-required">*</span>
                                    <button type="button" class="wm-help" data-tooltip="'Completed' or 'Paid' triggers a receipt email to the guest and updates the booking balance immediately." aria-label="Help">?</button>
                                </label>
                                <select id="pa-status" name="payment_status" class="pa-input" required>
                                    <?php
                                    $statuses = [
                                        'pending'   => 'Pending',
                                        'partial'   => 'Partial',
                                        'paid'      => 'Paid',
                                        'completed' => 'Completed',
                                        'refunded'  => 'Refunded',
                                        'cancelled' => 'Cancelled',
                                    ];
                                    $currentStatus = $payment['payment_status'] ?? 'completed';
                                    foreach ($statuses as $val => $lbl):
                                    ?>
                                        <option value="<?php echo $val; ?>" <?php echo $currentStatus === $val ? 'selected' : ''; ?>>
                                            <?php echo $lbl; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:1rem;">
                            <label class="pa-label" for="pa-cc">
                                Additional CC Emails
                                <button type="button" class="wm-help" data-tooltip="Comma-separated addresses that will receive a copy of the receipt email in addition to default finance recipients." aria-label="Help">?</button>
                            </label>
                            <input type="text" id="pa-cc" name="cc_emails"
                                value="<?php echo htmlspecialchars($payment['cc_emails'] ?? ''); ?>"
                                placeholder="extra@example.com, manager@hotel.com"
                                class="pa-input">
                        </div>

                        <div style="margin-top:1rem;">
                            <label class="pa-label" for="pa-notes">Notes</label>
                            <textarea id="pa-notes" name="notes" class="pa-input pa-textarea"
                                placeholder="Any additional notes about this payment…"><?php echo htmlspecialchars($payment['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Actions bar -->
                    <div class="payment-actions-bar" id="pa-actions-bar">
                        <a href="payments.php" class="tbl-btn tbl-btn--view">Cancel</a>
                        <button type="submit" class="tbl-btn tbl-btn--confirm" id="pa-submit-btn">
                            <i class="fas fa-save"></i>
                            <?php echo $editId ? 'Update Payment' : 'Record Payment'; ?>
                        </button>
                    </div>

                </div><!-- /.payment-console__main -->

                <!-- ── SIDE column: live summary ─────────────────────── -->
                <div class="payment-console__side">

                    <div class="payment-summary-card payment-summary-card--sticky" id="pa-summary-card">
                        <div class="payment-panel__kicker">Live Summary</div>
                        <h2 class="payment-summary-card__title" style="margin-top:0.25rem; font-size:1.15rem;">
                            Payment Preview
                        </h2>

                        <!-- Amount breakdown -->
                        <div class="payment-total-preview" id="pa-preview">
                            <div class="payment-total-preview__row">
                                <span>Subtotal</span>
                                <strong id="prev-subtotal"><?php echo $currency_symbol; ?>0.00</strong>
                            </div>
                            <?php if ($vatEnabled): ?>
                                <div class="payment-total-preview__row">
                                    <span>VAT (<?php echo $vatRate; ?>%)</span>
                                    <strong id="prev-vat"><?php echo $currency_symbol; ?>0.00</strong>
                                </div>
                            <?php endif; ?>
                            <div class="payment-total-preview__row payment-total-preview__row--total">
                                <span>Total to record</span>
                                <strong id="prev-total"><?php echo $currency_symbol; ?>0.00</strong>
                            </div>
                        </div>

                        <!-- Outstanding meter (shows when booking linked) -->
                        <div class="payment-summary-card__meter" id="pa-meter" style="display:none;">
                            <span>Outstanding after this payment</span>
                            <strong id="pa-meter-val">—</strong>
                            <div id="pa-meter-bar-wrap" style="margin-top:0.5rem; height:6px; border-radius:4px; background:var(--finance-border); overflow:hidden;">
                                <div id="pa-meter-bar" style="height:100%; border-radius:4px; background:var(--finance-success); width:0%; transition:width 0.4s;"></div>
                            </div>
                        </div>

                        <!-- Method recap -->
                        <div style="margin-top:1rem; padding:0.75rem; background:var(--finance-bg); border:1px solid var(--finance-border); border-radius:8px; font-size:0.85rem;">
                            <div style="color:var(--finance-muted); font-size:0.72rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; margin-bottom:0.35rem;">Method</div>
                            <span id="prev-method" style="color:var(--finance-text); font-weight:600;">—</span>
                        </div>

                        <!-- Status recap -->
                        <div style="margin-top:0.65rem; padding:0.75rem; background:var(--finance-bg); border:1px solid var(--finance-border); border-radius:8px; font-size:0.85rem;">
                            <div style="color:var(--finance-muted); font-size:0.72rem; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; margin-bottom:0.35rem;">Status</div>
                            <span id="prev-status" style="color:var(--finance-text); font-weight:600;">—</span>
                        </div>

                        <!-- Email notice -->
                        <div id="pa-email-notice" style="display:none; margin-top:0.65rem; padding:0.75rem; background:var(--finance-info-bg); border:1px solid var(--finance-info-border); border-radius:8px; font-size:0.82rem; color:var(--finance-info);">
                            <i class="fas fa-envelope"></i> A receipt email will be sent to the guest on save.
                        </div>
                    </div>

                </div><!-- /.payment-console__side -->

            </div><!-- /.payment-console -->
        </form>
    </div><!-- /.admin-container -->

    <script src="js/admin-walkme.js" data-no-spa></script>
    <script>
    (function () {
        'use strict';

        /* ── PHP data ─────────────────────────────────────────── */
        const VAT_RATE      = <?php echo (float)$vatRate; ?>;
        const VAT_ENABLED   = <?php echo $vatEnabled ? 'true' : 'false'; ?>;
        const CURRENCY      = <?php echo json_encode($currency_symbol); ?>;
        const IS_EDIT       = <?php echo $editId ? 'true' : 'false'; ?>;
        const PRE_BOOKING   = <?php echo $bookingDetails ? 'true' : 'false'; ?>;
        const PRE_DUE       = <?php echo (float)$outstandingAmount; ?>;

        /* ── Element refs ─────────────────────────────────────── */
        const amountInput   = document.getElementById('pa-amount');
        const statusSel     = document.getElementById('pa-status');
        const methodHidden  = document.getElementById('pa-method-hidden');
        const hdType        = document.getElementById('hd-booking-type');
        const hdId          = document.getElementById('hd-booking-id');

        /* ── Helpers ──────────────────────────────────────────── */
        function fmt(n) {
            return CURRENCY + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function el(id) { return document.getElementById(id); }

        /* ── Live calculation preview ─────────────────────────────
           The amount entered is the GROSS received (what the payer actually
           hands over, VAT included) — this is exactly what is stored as the
           payment's total_amount and what reduces the account balance. We
           EXTRACT the VAT portion from it (never add on top), so the preview
           matches how the payment is recorded and reconciles against the
           account's gross outstanding. */
        function updatePreview() {
            const gross = parseFloat(amountInput.value) || 0;
            const net   = VAT_ENABLED && VAT_RATE > 0 ? gross / (1 + VAT_RATE / 100) : gross;
            const vat   = gross - net;

            el('prev-subtotal').textContent = fmt(net);
            if (VAT_ENABLED && el('prev-vat')) el('prev-vat').textContent = fmt(vat);
            el('prev-total').textContent    = fmt(gross);

            // Outstanding meter — compare the gross received against the gross due.
            const total = gross;
            const dueEl = el('pa-meter');
            if (dueEl && _linkedDue !== null) {
                dueEl.style.display = '';
                const remaining = Math.max(0, _linkedDue - total);
                el('pa-meter-val').textContent = remaining <= 0
                    ? '✓ Fully covered'
                    : fmt(remaining) + ' remaining';
                el('pa-meter-val').style.color = remaining <= 0
                    ? 'var(--finance-success)'
                    : 'var(--finance-danger)';
                const pct = _linkedDue > 0 ? Math.min(100, (total / _linkedDue) * 100) : 100;
                el('pa-meter-bar').style.width = pct + '%';
                el('pa-meter-bar').style.background = remaining <= 0
                    ? 'var(--finance-success)'
                    : 'var(--finance-accent)';
            }

            // Email notice
            const emailNotice = el('pa-email-notice');
            if (emailNotice) {
                const triggerStatus = ['completed', 'paid'];
                emailNotice.style.display = triggerStatus.includes(statusSel.value) ? '' : 'none';
            }
        }

        /* ── Method card picker ───────────────────────────────── */
        const methodLabels = {
            cash: 'Cash', bank_transfer: 'Bank Transfer', credit_card: 'Credit Card',
            debit_card: 'Debit Card', mobile_money: 'Mobile Money', cheque: 'Cheque', other: 'Other'
        };

        document.querySelectorAll('#pa-method-grid .payment-method-card').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#pa-method-grid .payment-method-card').forEach(function (b) {
                    b.classList.remove('is-active');
                });
                btn.classList.add('is-active');
                const val = btn.dataset.method;
                methodHidden.value = val;
                localStorage.setItem('lastPaymentMethod', val);
                el('prev-method').textContent = methodLabels[val] || val;
                el('pa-method-err').style.display = 'none';
            });
        });

        // Restore last method on fresh form
        if (!IS_EDIT && !methodHidden.value) {
            const last = localStorage.getItem('lastPaymentMethod');
            if (last) {
                const btn = document.querySelector('#pa-method-grid [data-method="' + last + '"]');
                if (btn) btn.click();
            }
        } else if (methodHidden.value) {
            el('prev-method').textContent = methodLabels[methodHidden.value] || methodHidden.value;
        }

        /* ── Booking type toggle ──────────────────────────────── */
        let _activeType = hdType.value || 'room';
        let _linkedDue  = PRE_BOOKING ? PRE_DUE : null;

        function setActiveType(type) {
            _activeType = type;
            hdType.value = type;
            document.querySelectorAll('.pa-type-btn').forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.type === type);
            });
            // Clear any existing selection when switching type
            _clearSelection();
        }

        document.querySelectorAll('.pa-type-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { setActiveType(btn.dataset.type); });
        });

        /* ── Booking search ───────────────────────────────────── */
        let _searchTimer = null;
        const searchInput   = el('pa-search-input');
        const searchResults = el('pa-search-results');
        const selectedCard  = el('pa-selected-card');
        const pickerEl      = el('pa-picker');

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const q = searchInput.value.trim();
                clearTimeout(_searchTimer);
                if (q.length < 2) {
                    if (searchResults) { searchResults.innerHTML = ''; searchResults.hidden = true; }
                    return;
                }
                _searchTimer = setTimeout(function () { _doSearch(q); }, 280);
            });

            searchInput.addEventListener('focus', function () {
                const q = searchInput.value.trim();
                if (q.length >= 2) _doSearch(q);
                else _loadRecent();
            });
        }

        document.addEventListener('click', function (e) {
            if (searchResults && !e.target.closest('#pa-search-wrap')) {
                searchResults.hidden = true;
            }
        });

        function _doSearch(q) {
            if (!searchResults) return;
            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching…</div>';
            searchResults.hidden = false;
            fetch('api/search-bookings.php?type=' + encodeURIComponent(_activeType) + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) { _renderResults(data); })
                .catch(function () {
                    searchResults.innerHTML = '<div class="booking-search-no-results">Search error — please try again.</div>';
                });
        }

        function _loadRecent() {
            if (!searchResults) return;
            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Loading recent…</div>';
            searchResults.hidden = false;
            fetch('api/search-bookings.php?type=' + encodeURIComponent(_activeType) + '&recent=1')
                .then(function (r) { return r.json(); })
                .then(function (data) { _renderResults(data, true); })
                .catch(function () {
                    searchResults.innerHTML = '<div class="booking-search-no-results">Could not load recent bookings.</div>';
                });
        }

        function _renderResults(data, isRecent) {
            if (!searchResults) return;
            const bookings = (data && data.bookings) ? data.bookings : [];
            if (bookings.length === 0) {
                searchResults.innerHTML = '<div class="booking-search-no-results">' +
                    (isRecent ? 'No recent bookings.' : 'No bookings found.') + '</div>';
                return;
            }
            let html = '';
            bookings.forEach(function (b) {
                const isDue = b.amount_due > 0;
                const dueCls = isDue ? 'is-due' : 'is-settled';
                const dueLabel = isDue ? fmt(b.amount_due) + ' due' : '✓ Settled';
                let ref, name, sub;
                if (_activeType === 'room') {
                    ref  = b.booking_reference || '';
                    name = b.guest_name || '';
                    sub  = (b.room_name || '') + ' &bull; ' + (b.check_in_date || '') + ' → ' + (b.check_out_date || '');
                } else {
                    ref  = b.enquiry_reference || '';
                    name = b.organization_name || b.contact_name || '';
                    sub  = (b.start_date || '') + ' → ' + (b.end_date || '');
                }
                html += '<button type="button" class="booking-search-item" data-booking=\'' +
                    JSON.stringify(b).replace(/'/g, '&#39;') + '\'>' +
                    '<strong>' + _esc(ref) + ' &mdash; ' + _esc(name) + '</strong>' +
                    '<small>' + sub + '</small>' +
                    '<small class="' + dueCls + '">' + dueLabel + '</small>' +
                    '</button>';
            });
            searchResults.innerHTML = html;
            searchResults.querySelectorAll('.booking-search-item').forEach(function (item) {
                item.addEventListener('click', function () {
                    const b = JSON.parse(item.dataset.booking);
                    _selectBooking(b);
                    searchResults.hidden = true;
                });
            });
        }

        function _selectBooking(b) {
            hdId.value    = b.id;
            hdType.value  = _activeType;
            _linkedDue    = parseFloat(b.amount_due) || 0;

            // Populate selected card
            const typeLabels = { room: 'Room Booking', conference: 'Conference Booking', gym: 'Gym Membership', event: 'Event Booking' };
            el('psc-type').textContent = typeLabels[_activeType] || 'Booking';

            // Grand total (gross, incl. VAT + any room folio extras) = paid + due,
            // so the card's Total always reconciles with Paid + Amount Due and is
            // never lower than the outstanding balance.
            const _paid  = parseFloat(b.amount_paid) || 0;
            const _due   = parseFloat(b.amount_due) || 0;
            const _grand = _paid + _due;

            if (_activeType === 'room') {
                el('psc-name').textContent = b.guest_name || '';
                el('psc-ref').textContent  = (b.booking_reference || '') + ' · ' + (b.room_name || '');
                el('psc-meta').innerHTML   =
                    _metaItem('Check-in',  b.check_in_date) +
                    _metaItem('Check-out', b.check_out_date) +
                    _metaItem('Total (incl. VAT & extras)', fmt(_grand)) +
                    _metaItem('Paid',      fmt(_paid));
            } else {
                el('psc-name').textContent = b.organization_name || b.contact_name || '';
                el('psc-ref').textContent  = b.enquiry_reference || '';
                el('psc-meta').innerHTML   =
                    _metaItem('Start', b.start_date) +
                    _metaItem('End',   b.end_date) +
                    _metaItem('Total (incl. VAT)', fmt(_grand)) +
                    _metaItem('Paid',  fmt(_paid));
            }

            const dueEl = el('psc-due');
            dueEl.textContent = fmt(_linkedDue);
            dueEl.style.color = _linkedDue > 0 ? 'var(--finance-danger)' : 'var(--finance-success)';

            // Show card, hide picker
            if (pickerEl)      pickerEl.style.display = 'none';
            if (selectedCard)  selectedCard.hidden = false;

            // Fully paid warning
            const fpWarn = el('pa-fullypaid-warn');
            if (fpWarn) {
                if (_linkedDue <= 0) {
                    fpWarn.style.display = '';
                    amountInput.disabled = true;
                    amountInput.value    = '';
                } else {
                    fpWarn.style.display = 'none';
                    amountInput.disabled = false;
                    // Auto-fill outstanding amount
                    amountInput.value = _linkedDue.toFixed(2);
                }
            } else {
                amountInput.value = _linkedDue > 0 ? _linkedDue.toFixed(2) : '';
            }

            // Auto-set status to completed
            if (statusSel.value === 'pending') statusSel.value = 'completed';

            // Show the outstanding meter
            const meter = el('pa-meter');
            if (meter) meter.style.display = '';

            updatePreview();
        }

        function _clearSelection() {
            hdId.value   = '';
            _linkedDue   = null;
            if (selectedCard) selectedCard.hidden = true;
            if (pickerEl)     pickerEl.style.display = '';
            if (searchInput)  searchInput.value = '';
            if (searchResults){ searchResults.innerHTML = ''; searchResults.hidden = true; }
            amountInput.disabled = false;
            amountInput.value    = '';
            const fpWarn = el('pa-fullypaid-warn');
            if (fpWarn) fpWarn.style.display = 'none';
            const meter = el('pa-meter');
            if (meter) meter.style.display = 'none';
            updatePreview();
        }

        const clearBtn = el('pa-clear-btn');
        if (clearBtn) clearBtn.addEventListener('click', _clearSelection);

        // Override checkbox for fully paid
        const overrideCb = el('pa-override-cb');
        if (overrideCb) {
            overrideCb.addEventListener('change', function () {
                amountInput.disabled = !overrideCb.checked;
                if (!overrideCb.checked) { amountInput.value = ''; updatePreview(); }
            });
        }

        /* ── Status change watcher ────────────────────────────── */
        statusSel.addEventListener('change', function () {
            el('prev-status').textContent = statusSel.options[statusSel.selectedIndex].text;
            updatePreview();
        });
        el('prev-status').textContent = statusSel.options[statusSel.selectedIndex].text;

        /* ── Amount watcher ───────────────────────────────────── */
        amountInput.addEventListener('input', updatePreview);

        /* ── Form submission guard ────────────────────────────── */
        document.getElementById('pa-form').addEventListener('submit', function (e) {
            let ok = true;
            if (!methodHidden.value) {
                el('pa-method-err').style.display = '';
                el('pa-method-panel').scrollIntoView({ behavior: 'smooth', block: 'center' });
                ok = false;
            }
            if (!ok) e.preventDefault();
        });

        /* ── Initial render ───────────────────────────────────── */
        updatePreview();

        // Populate outstanding meter for pre-linked bookings, and — when arriving
        // to collect a specific account (e.g. the "Collect" button) — prefill the
        // full gross outstanding so a full settlement is one click. Skips edits.
        if (PRE_BOOKING) {
            const meter = el('pa-meter');
            if (meter) meter.style.display = '';
            if (!IS_EDIT && PRE_DUE > 0 && amountInput && !amountInput.value) {
                amountInput.value = PRE_DUE.toFixed(2);
            }
            updatePreview();
        }

        /* ── Helpers ──────────────────────────────────────────── */
        function _metaItem(label, value) {
            return '<div><dt>' + label + '</dt><dd>' + _esc(value || '—') + '</dd></div>';
        }
        function _esc(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        /* ══════════════════════════════════════════════════════
           WalkMe tour registration
           ══════════════════════════════════════════════════════ */
        if (window.AdminWalkMe && !IS_EDIT) {
            AdminWalkMe.registerTour('payment-add', [
                {
                    target: '#pa-booking-panel',
                    icon: 'fa-calendar-check',
                    label: 'Step 1 of 5',
                    title: 'Link a Booking',
                    text: 'Choose <strong>Room</strong> or <strong>Conference</strong>, then type the guest name, reference number, or email address to find the booking. Click any result to link it.',
                },
                {
                    target: '#pa-method-panel',
                    icon: 'fa-wallet',
                    label: 'Step 2 of 5',
                    title: 'Pick the Payment Method',
                    text: 'Click the card that matches how the guest paid — Cash, Card, Bank Transfer, Mobile Money, etc. The last method you used is remembered automatically.',
                },
                {
                    target: '#pa-amount',
                    icon: 'fa-coins',
                    label: 'Step 3 of 5',
                    title: 'Enter the Amount',
                    text: 'The outstanding balance is pre-filled for you. You can change it for partial payments. <?php echo $vatEnabled ? 'VAT at ' . $vatRate . '% is added automatically.' : ''; ?>',
                },
                {
                    target: '#pa-status',
                    icon: 'fa-check-circle',
                    label: 'Step 4 of 5',
                    title: 'Set the Status',
                    text: 'Use <strong>Completed</strong> or <strong>Paid</strong> for received payments — this triggers a receipt email and updates the booking balance. Use <strong>Pending</strong> for payments not yet confirmed.',
                },
                {
                    target: '#pa-summary-card',
                    icon: 'fa-receipt',
                    label: 'Step 5 of 5',
                    title: 'Review & Save',
                    text: 'The live summary on the right shows the total, remaining balance, and whether an email will be sent. When everything looks right, click <strong>Record Payment</strong>.',
                    placement: 'left',
                },
            ]);

            // Add "Take a tour" button into hero actions
            const tourAnchor = el('pa-tour-anchor');
            if (tourAnchor) {
                AdminWalkMe.addStartButton(tourAnchor, 'payment-add', 'Tour this page');
            }

            // Auto-start for first-time visitors
            AdminWalkMe.startTour('payment-add');
        }

        // Wire all remaining tooltips (including the ? help buttons)
        if (window.AdminWalkMe) AdminWalkMe.wireTooltips(document);

    }());
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

