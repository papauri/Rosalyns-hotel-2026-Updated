<?php

/**
 * Payment Refund Processing
 * Handles refund creation and processing for existing payments
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/alert.php';
require_once 'includes/finance-schema.php';

$message = '';
$error = '';
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$conferenceFields = finance_conference_fields($pdo);

// Get the original payment details
if ($payment_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   CASE WHEN p.booking_type = 'room' THEN b.guest_name
                        WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['company']}
                        WHEN p.booking_type = 'restaurant' THEN COALESCE(NULLIF(so.customer_name, ''), CONCAT('Restaurant order ', so.reference))
                   END as customer_name,
                   CASE WHEN p.booking_type = 'room' THEN b.guest_email
                        WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
                   END as customer_email
            FROM payments p
            LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
            LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
              LEFT JOIN stock_orders so ON p.booking_type = 'restaurant' AND p.booking_id = so.id
            WHERE p.id = ? AND p.deleted_at IS NULL
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $error = 'Payment not found.';
        } elseif (!in_array($payment['payment_status'], ['completed', 'paid'], true)) {
            $error = 'Refunds can only be processed for completed or paid payments.';
        } elseif ($payment['payment_type'] === 'refund') {
            $error = 'Cannot refund a refund transaction.';
        }
    } catch (PDOException $e) {
        $error = 'Error loading payment: ' . $e->getMessage();
        $payment = null;
    }
} else {
    $error = 'Invalid payment ID.';
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$csrf_token = $csrf_token ?? generateCsrfToken();

// Compute already-refunded total + remaining refundable for this payment
$alreadyRefunded = 0.0;
$refundCount = 0;
$priorRefunds = [];
if (isset($payment) && $payment) {
    $rfStmt = $pdo->prepare("
        SELECT id, payment_reference, payment_date, refund_amount, refund_reason, refund_status, refund_notes, total_amount, created_at
        FROM payments
        WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
        ORDER BY created_at DESC
    ");
    $rfStmt->execute([$payment_id]);
    $priorRefunds = $rfStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($priorRefunds as $r) {
        if (in_array($r['refund_status'], ['completed', 'processing', 'pending'], true)) {
            $alreadyRefunded += (float)($r['refund_amount'] ?: $r['total_amount']);
        }
        $refundCount++;
    }
}
$maxRefundable = isset($payment) && $payment ? max(0, (float)$payment['total_amount'] - $alreadyRefunded) : 0;
$isMobileMoneyPayment = isset($payment['payment_method']) && (string)$payment['payment_method'] === 'mobile_money';

// Handle refund form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $payment) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Refresh and try again.';
    } elseif ($_POST['action'] === 'create_refund') {
        try {
            $refund_amount = floatval($_POST['refund_amount'] ?? 0);
            $refund_reason = $_POST['refund_reason'] ?? '';
            $refund_notes = $_POST['refund_notes'] ?? '';
            $refund_status = $_POST['refund_status'] ?? 'pending';
            $refund_method = $_POST['refund_method'] ?? 'original';

            // Validate inputs
            if (!in_array($refund_method, ['original', 'store_credit'], true)) {
                throw new Exception('Invalid refund method.');
            }
            $isStoreCredit = ($refund_method === 'store_credit');

            if ($refund_amount <= 0) {
                throw new Exception('Refund amount must be greater than zero.');
            }
            if ($refund_amount > $maxRefundable) {
                throw new Exception('Refund amount cannot exceed remaining refundable balance (' . $currency_symbol . number_format($maxRefundable, 2) . ').');
            }
            if (!in_array($refund_reason, ['early_checkout', 'late_checkout_charge', 'cancellation', 'service_issue', 'overpayment', 'other'], true)) {
                throw new Exception('Invalid refund reason.');
            }

            if ($isStoreCredit) {
                // Store credit is issued immediately as a credit note — it is always
                // settled now, independent of the original payment method (no external
                // provider settlement is involved). The mobile-money hold does not apply.
                $refund_status = 'completed';
            } else {
                if (!in_array($refund_status, ['pending', 'processing', 'completed', 'failed'], true)) {
                    throw new Exception('Invalid refund status.');
                }
                if ($isMobileMoneyPayment && $refund_status === 'completed') {
                    throw new Exception('Mobile money refunds must start as pending or processing until provider confirmation.');
                }
            }

            $refund_payment_status = match ($refund_status) {
                'completed' => 'completed',
                'failed' => 'cancelled',
                default => 'pending',
            };

            // Calculate VAT portion of refund (pro-rated)
            $vat_rate = $payment['vat_rate'] ?? 0;
            $vat_amount = round($refund_amount * ($vat_rate / (100 + $vat_rate)), 2);
            $payment_amount = $refund_amount - $vat_amount;

            // Generate refund reference
            $year = date('Y');
            do {
                $refundRef = 'REF-' . $year . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
                $refundRefCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ? LIMIT 1");
                $refundRefCheck->execute([$refundRef]);
                $refundRefExists = ((int)$refundRefCheck->fetchColumn()) > 0;
            } while ($refundRefExists);

            // Start transaction — open BEFORE re-validating to prevent concurrent
            // over-refund (two simultaneous requests both passing the pre-transaction check).
            $pdo->beginTransaction();

            // Re-fetch original payment with row lock and recompute refundable balance
            // inside the transaction to prevent concurrent double-refunds.
            $lockedPayStmt = $pdo->prepare("SELECT id, total_amount FROM payments WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockedPayStmt->execute([$payment_id]);
            $lockedPayment = $lockedPayStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedPayment) {
                throw new Exception('Payment record could not be locked. Please try again.');
            }
            $lockedRefundedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN refund_status IN ('completed','processing','pending') THEN COALESCE(refund_amount, total_amount) ELSE 0 END), 0)
                FROM payments WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL
            ");
            $lockedRefundedStmt->execute([$payment_id]);
            $lockedAlreadyRefunded = (float)$lockedRefundedStmt->fetchColumn();
            $lockedMaxRefundable   = max(0, (float)$lockedPayment['total_amount'] - $lockedAlreadyRefunded);
            if ($refund_amount > $lockedMaxRefundable) {
                throw new Exception('Refund amount exceeds available balance. Remaining: ' . $currency_symbol . number_format($lockedMaxRefundable, 2) . '.');
            }

            // Insert refund record
            $insertStmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, original_payment_id,
                    refund_reason, refund_status, refund_amount, refund_notes,
                    recorded_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'refund', ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");

            // For store credit the payout vehicle is a credit note, so record the
            // refund row's method as 'credit_note' (the credit note itself is issued
            // after commit). Cash/card/mobile-money refunds keep the original method.
            $refund_payment_method = $isStoreCredit ? 'credit_note' : $payment['payment_method'];
            $effective_refund_notes = $isStoreCredit
                ? trim('Refunded as store credit. ' . $refund_notes)
                : $refund_notes;

            $insertStmt->execute([
                $refundRef,
                $payment['booking_type'],
                $payment['booking_id'],
                $payment['booking_reference'],
                date('Y-m-d'),
                $payment_amount,
                $vat_rate,
                $vat_amount,
                $refund_amount,
                $refund_payment_method,
                $refund_payment_status,
                $payment_id,
                $refund_reason,
                $refund_status,
                $refund_amount,
                $effective_refund_notes,
                $_SESSION['admin_user_id'] ?? null
            ]);

            // Update original payment status only when settled refunds fully cover the original payment.
            // Pending refunds reserve refundable balance but should not finalize original payment status.
            $settledRefundedStmt = $pdo->prepare("\n                    SELECT COALESCE(SUM(CASE WHEN refund_status IN ('completed','processing') THEN COALESCE(refund_amount, total_amount) ELSE 0 END), 0)\n                    FROM payments\n                    WHERE original_payment_id = ? AND payment_type = 'refund' AND deleted_at IS NULL\n                ");
            $settledRefundedStmt->execute([$payment_id]);
            $totalRefundedAfterThis = round((float)$settledRefundedStmt->fetchColumn(), 2);
            $originalTotal = round((float)$lockedPayment['total_amount'], 2);
            if ($totalRefundedAfterThis >= $originalTotal) {
                $updateStmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_status = 'refunded', updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$payment_id]);
            }

            // Recalculate booking balances INSIDE the transaction so that amount_paid / amount_due
            // remain consistent with the refund row. If recalc fails the whole transaction rolls back.
            if ($payment['booking_type'] === 'room') {
                recalculateBookingFinancials((int)$payment['booking_id']);
            } elseif ($payment['booking_type'] === 'conference') {
                require_once __DIR__ . '/includes/finance-account-sync.php';
                $cfStmt = $pdo->prepare("SELECT total_amount, total_with_vat FROM conference_inquiries WHERE id = ? LIMIT 1");
                $cfStmt->execute([$payment['booking_id']]);
                if ($cfRow = $cfStmt->fetch(PDO::FETCH_ASSOC)) {
                    // Paid nets out settled refunds; due is measured against the
                    // invoiced GROSS (locked total_with_vat), never the net total.
                    $cfAmtPaid = rh_sum_account_paid($pdo, 'conference', (int)$payment['booking_id']);
                    $cfDue = max(0.0, round(rh_account_gross_total($cfRow) - $cfAmtPaid, 2));
                    $pdo->prepare("UPDATE conference_inquiries SET amount_paid = ?, amount_due = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$cfAmtPaid, $cfDue, $payment['booking_id']]);
                }
            } elseif ($payment['booking_type'] === 'gym') {
                require_once __DIR__ . '/includes/finance-account-sync.php';
                syncGymInquiryPaymentSnapshot($pdo, (int)$payment['booking_id']);
            } elseif ($payment['booking_type'] === 'event') {
                require_once __DIR__ . '/includes/finance-account-sync.php';
                syncEventInquiryPaymentSnapshot($pdo, (int)$payment['booking_id']);
            }

            $pdo->commit();

            $refundEmailSent = false;
            $refundEmailNote = '';

            if ($isStoreCredit) {
                // Issue the credit note as a post-commit side effect (mirrors the
                // refund-email pattern). The core refund is already durable; if the
                // credit note fails we surface a clear manual-fallback message rather
                // than rolling back a settled refund.
                $message = 'Refund issued as store credit (Ref ' . $refundRef . ').';
                try {
                    require_once __DIR__ . '/../config/credit-notes.php';

                    // Map refund reasons to the credit-note reason vocabulary.
                    $cnReasonMap = [
                        'early_checkout'       => 'early_checkout',
                        'cancellation'         => 'cancellation',
                        'service_issue'        => 'service_issue',
                        'overpayment'          => 'overpayment',
                        'late_checkout_charge' => 'other',
                        'other'                => 'other',
                    ];
                    $cnGuestName = trim((string)($payment['customer_name'] ?? ''));
                    if ($cnGuestName === '') {
                        $cnGuestName = trim((string)($payment['booking_reference'] ?? '')) ?: 'Guest';
                    }
                    $cnBookingType = in_array($payment['booking_type'], ['room', 'conference', 'restaurant'], true)
                        ? $payment['booking_type'] : 'goodwill';
                    $cnHasEmail = !empty($payment['customer_email']) && filter_var($payment['customer_email'], FILTER_VALIDATE_EMAIL);

                    $cnResult = issueCreditNote($pdo, [
                        'amount'              => $refund_amount,
                        'guest_name'          => $cnGuestName,
                        'guest_email'         => $payment['customer_email'] ?? null,
                        'booking_id'          => $payment['booking_id'],
                        'booking_reference'   => $payment['booking_reference'],
                        'booking_type'        => $cnBookingType,
                        'reason'              => $cnReasonMap[$refund_reason] ?? 'other',
                        'reason_notes'        => 'Store-credit refund ' . $refundRef . ($refund_notes !== '' ? ' — ' . $refund_notes : ''),
                        'vat_rate'            => $vat_rate,
                        'original_payment_id' => $payment_id,
                        'issued_by'           => (int)($_SESSION['admin_user_id'] ?? 0),
                        'generate_pdf'        => true,
                        'send_email'          => $cnHasEmail,
                    ]);

                    if (!empty($cnResult['success'])) {
                        $cnNumber = (string)($cnResult['credit_note_number'] ?? '');
                        $message = 'Refund issued as store credit. Credit note ' . $cnNumber . ' created (Ref ' . $refundRef . ').';
                        $refundEmailSent = $cnHasEmail; // credit-note email carries the redeemable number
                        if (!$cnHasEmail) {
                            $message .= ' No customer email on file — share the credit note number manually.';
                        }
                        // Stamp the credit note number onto the refund row for traceability.
                        $pdo->prepare("UPDATE payments SET refund_notes = CONCAT(COALESCE(refund_notes, ''), ?) WHERE payment_reference = ?")
                            ->execute([' [Credit note: ' . $cnNumber . ']', $refundRef]);
                    } else {
                        $message .= ' WARNING: the credit note could not be issued automatically — issue it manually from Credit Notes. (' . ($cnResult['error'] ?? 'unknown error') . ')';
                        error_log('Store-credit CN issue failed for ' . $refundRef . ': ' . ($cnResult['error'] ?? 'unknown'));
                    }
                } catch (Throwable $cnEx) {
                    $message .= ' WARNING: the credit note could not be issued automatically — issue it manually from Credit Notes.';
                    error_log('Store-credit CN exception for ' . $refundRef . ': ' . $cnEx->getMessage());
                }
            } else {
                // Standard refund back to the original payment method — notify by email.
                $message = 'Refund created successfully! Reference: ' . $refundRef;
                try {
                    require_once __DIR__ . '/../config/email.php';
                    $rfEmailResult = sendRefundNotificationEmail(
                        $payment,
                        $refundRef,
                        $refund_amount,
                        $refund_reason
                    );
                    if (!empty($rfEmailResult['success'])) {
                        $refundEmailSent = true;
                        $message .= ' Refund notification emailed to customer.';
                    } else {
                        $refundEmailNote = $rfEmailResult['message'] ?? 'Email could not be sent.';
                        error_log('Refund notification email failed for ' . $refundRef . ': ' . $refundEmailNote);
                        $message .= ' (Note: refund saved, but the notification email was not sent — ' . $refundEmailNote . ')';
                    }
                } catch (Throwable $emailEx) {
                    $refundEmailNote = $emailEx->getMessage();
                    error_log('Refund notification email exception for ' . $refundRef . ': ' . $refundEmailNote);
                    $message .= ' (Note: refund saved, but the notification email could not be sent.)';
                }
            }

            // Log the action to admin_activity_log
            $logStmt = $pdo->prepare("
                INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['admin_user_id'] ?? null,
                $_SESSION['admin_username'] ?? 'system',
                'refund_created',
                "Refund {$refundRef} created for payment {$payment['payment_reference']}, amount: {$currency_symbol}{$refund_amount}",
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Error creating refund: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Refund | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-finance.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-finance.css'); ?>">
    <style>
        /* ── Process Refund — scoped UI ─────────────────────────────── */
        .refund-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr;
            gap: 16px;
        }
        .refund-field { display: flex; flex-direction: column; gap: 6px; }
        .refund-field > label {
            font-size: 12px;
            font-weight: 600;
            color: var(--finance-ink, #2a2a2a);
            letter-spacing: 0.01em;
        }
        .refund-field__hint { font-size: 11px; color: var(--finance-muted, #6b7280); line-height: 1.4; }
        .refund-field select,
        .refund-field textarea {
            width: 100%;
            border: 1px solid var(--finance-border, #d9d4ca);
            border-radius: 8px;
            padding: 9px 11px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
            color: var(--finance-ink, #2a2a2a);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .refund-field select:focus,
        .refund-field textarea:focus,
        .refund-amount:focus-within {
            outline: none;
            border-color: var(--finance-accent, #8a6d3b);
            box-shadow: 0 0 0 3px rgba(138, 109, 59, 0.12);
        }
        /* currency-prefixed amount input */
        .refund-amount {
            display: flex;
            align-items: stretch;
            border: 1px solid var(--finance-border, #d9d4ca);
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .refund-amount__symbol {
            display: flex; align-items: center;
            padding: 0 12px;
            background: var(--finance-bg, #f6f3ee);
            border-right: 1px solid var(--finance-border, #d9d4ca);
            font-size: 13px; font-weight: 700; color: var(--finance-muted, #6b7280);
            white-space: nowrap; flex-shrink: 0;
        }
        .refund-amount input {
            flex: 1; min-width: 0;
            border: 0; background: transparent;
            padding: 10px 12px;
            font-size: 15px; font-weight: 600;
            color: var(--finance-ink, #2a2a2a);
        }
        .refund-amount input:focus { outline: none; }
        .refund-amount.is-invalid { border-color: var(--finance-danger, #c0392b); box-shadow: 0 0 0 3px rgba(192,57,43,.12); }
        /* quick-fill chips */
        .refund-quickfill { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
        .refund-chip {
            border: 1px solid var(--finance-border, #d9d4ca);
            background: #fff;
            color: var(--finance-ink, #2a2a2a);
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 11px; font-weight: 600;
            cursor: pointer;
            transition: background .12s ease, border-color .12s ease, color .12s ease;
        }
        .refund-chip:hover { background: var(--finance-bg, #f6f3ee); border-color: var(--finance-accent, #8a6d3b); }
        .refund-chip:active { transform: translateY(1px); }
        /* live summary */
        .refund-summary { margin-top: 18px; }
        .refund-summary__rows { padding: 6px 18px 14px; }
        .refund-summary__row {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px dashed var(--finance-border, #e6e1d8);
            font-size: 13px;
        }
        .refund-summary__row:last-child { border-bottom: 0; }
        .refund-summary__row span { color: var(--finance-muted, #6b7280); }
        .refund-summary__row strong { font-weight: 600; color: var(--finance-ink, #2a2a2a); }
        .refund-summary__row--total { margin-top: 4px; padding-top: 13px; border-top: 2px solid var(--finance-border, #d9d4ca); border-bottom: 0; }
        .refund-summary__row--total span { font-weight: 600; color: var(--finance-ink, #2a2a2a); font-size: 14px; }
        .refund-summary__row--total strong { font-size: 18px; color: var(--finance-danger, #c0392b); }
        .refund-amount-warn {
            display: none;
            margin-top: 8px;
            font-size: 12px; color: var(--finance-danger, #c0392b);
        }
        .refund-amount-warn.show { display: block; }
        .refund-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
        /* refund destination segmented toggle */
        .refund-dest { margin-bottom: 18px; }
        .refund-dest__label {
            font-size: 12px; font-weight: 600; color: var(--finance-ink, #2a2a2a);
            display: block; margin-bottom: 8px;
        }
        .refund-dest__options { display: flex; gap: 10px; flex-wrap: wrap; }
        .refund-dest__opt {
            flex: 1 1 240px;
            display: flex; align-items: flex-start; gap: 10px;
            border: 1px solid var(--finance-border, #d9d4ca);
            border-radius: 10px;
            padding: 12px 14px;
            cursor: pointer;
            background: #fff;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .refund-dest__opt:hover { border-color: var(--finance-accent, #8a6d3b); }
        .refund-dest__opt input { margin-top: 3px; accent-color: var(--finance-accent, #8a6d3b); }
        .refund-dest__opt.is-selected {
            border-color: var(--finance-accent, #8a6d3b);
            background: var(--finance-bg, #f6f3ee);
            box-shadow: 0 0 0 3px rgba(138, 109, 59, 0.10);
        }
        .refund-dest__opt-title { font-size: 13px; font-weight: 600; color: var(--finance-ink, #2a2a2a); }
        .refund-dest__opt-desc { font-size: 11px; color: var(--finance-muted, #6b7280); line-height: 1.4; margin-top: 2px; }
        .refund-field.is-disabled { opacity: .5; pointer-events: none; }
        @media (max-width: 720px) {
            .refund-grid { grid-template-columns: 1fr; }
            .refund-actions .btn { flex: 1; justify-content: center; }
        }
    </style>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content finance-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">Process Refund</h1>
                <p class="acct-page-header__subtitle">
                    Issue a partial or full refund against an existing payment. Updates booking balances &amp; emails the customer a refund confirmation.
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="payments.php" class="acct-quick-action" onclick="if(history.length>1){history.back();return false;}">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <?php if (!empty($payment['id'])): ?>
                    <a href="payment-details.php?id=<?php echo (int)$payment['id']; ?>" class="acct-quick-action">
                        <i class="fas fa-eye"></i> View Original
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="acct-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="acct-panel refund-success" style="margin-top: 4px;">
                <div style="padding: 24px 22px; text-align: center;">
                    <div class="refund-success__icon" style="font-size: 2.4rem; color: var(--finance-success, #1e7e4f); margin-bottom: 10px;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <h2 style="margin: 0 0 6px; font-size: 1.15rem; color: var(--finance-ink, #2a2a2a);">Refund processed</h2>
                    <p style="margin: 0 0 14px; color: var(--finance-muted, #6b6b6b); font-size: 0.92rem; max-width: 520px; margin-left: auto; margin-right: auto;">
                        <?php echo htmlspecialchars($message); ?>
                    </p>
                    <div style="margin-bottom: 18px;">
                        <?php if (!empty($refundEmailSent)): ?>
                            <span class="acct-pill acct-pill--completed"><i class="fas fa-envelope-circle-check"></i> Customer notified by email</span>
                        <?php else: ?>
                            <span class="acct-pill acct-pill--pending"><i class="fas fa-envelope"></i> Email not sent — notify the customer manually</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                        <a href="payments.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Return to Payments</a>
                        <?php if (!empty($payment['id'])): ?>
                            <a href="payment-details.php?id=<?php echo (int)$payment['id']; ?>" class="btn btn-secondary"><i class="fas fa-eye"></i> View Original Payment</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($payment && !$message): ?>
            <!-- Original payment + refundable position (compact KPI strip — no padded cards) -->
            <div class="acct-kpis">
                <div class="acct-kpi acct-kpi--revenue">
                    <div class="acct-kpi__label">Original Payment</div>
                    <div class="acct-kpi__value"><?php echo $currency_symbol . number_format((float)$payment['total_amount'], 0); ?></div>
                    <div class="acct-kpi__meta">
                        Ref <strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong> ·
                        <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?> ·
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?>
                    </div>
                </div>
                <div class="acct-kpi acct-kpi--receivables">
                    <div class="acct-kpi__label">Already Refunded</div>
                    <div class="acct-kpi__value"><?php echo $currency_symbol . number_format($alreadyRefunded, 0); ?></div>
                    <div class="acct-kpi__meta">
                        <?php echo $refundCount; ?> prior refund<?php echo $refundCount === 1 ? '' : 's'; ?>
                    </div>
                </div>
                <div class="acct-kpi acct-kpi--cash">
                    <div class="acct-kpi__label">Refundable Balance</div>
                    <div class="acct-kpi__value"><?php echo $currency_symbol . number_format($maxRefundable, 0); ?></div>
                    <div class="acct-kpi__meta">
                        Maximum amount you can refund now
                    </div>
                </div>
                <div class="acct-kpi acct-kpi--vat">
                    <div class="acct-kpi__label">Customer</div>
                    <div class="acct-kpi__value" style="font-size: clamp(1.05rem, 1.2vw + 0.6rem, 1.4rem); font-weight: 500;">
                        <?php echo htmlspecialchars($payment['customer_name'] ?? 'N/A'); ?>
                    </div>
                    <div class="acct-kpi__meta">
                        <?php echo htmlspecialchars(ucfirst((string)$payment['booking_type'])); ?> ·
                        <?php echo htmlspecialchars($payment['booking_reference'] ?? '—'); ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($priorRefunds)): ?>
                <div class="acct-panel" style="margin-top: 18px;">
                    <div class="acct-panel__head">
                        <h3 class="acct-panel__title">Refund History</h3>
                        <span class="acct-panel__sub"><?php echo $refundCount; ?> previous</span>
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
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($priorRefunds as $r): ?>
                                    <tr>
                                        <td><a class="acct-link" href="payment-details.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['payment_reference']); ?></a></td>
                                        <td><?php echo date('M j, Y', strtotime($r['payment_date'])); ?></td>
                                        <td class="num"><strong><?php echo $currency_symbol . number_format((float)($r['refund_amount'] ?: $r['total_amount']), 0); ?></strong></td>
                                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($r['refund_reason'] ?? '—')))); ?></td>
                                        <td><span class="acct-pill acct-pill--<?php echo htmlspecialchars((string)($r['refund_status'] ?? 'pending')); ?>"><?php echo htmlspecialchars(ucfirst((string)($r['refund_status'] ?? 'pending'))); ?></span></td>
                                        <td class="acct-muted"><?php echo nl2br(htmlspecialchars((string)($r['refund_notes'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($maxRefundable <= 0): ?>
                <div class="acct-error" style="margin-top: 18px;">
                    <i class="fas fa-ban"></i> This payment has been fully refunded. No further refunds can be processed.
                </div>
            <?php else: ?>

                <!-- Refund Form -->
                <div class="acct-panel" style="margin-top: 18px;">
                    <div class="acct-panel__head">
                        <h3 class="acct-panel__title"><i class="fas fa-undo"></i> &nbsp;Refund Details</h3>
                        <span class="acct-panel__sub">VAT is auto-prorated from the original rate</span>
                    </div>
                    <div style="padding: 18px;">
                        <form method="POST" class="form-container"
                            data-admin-confirm="Process this refund now?"
                            data-admin-confirm-title="Confirm refund"
                            data-admin-confirm-details="Please verify the refund amount, reason, and status before saving.|This will update payment records and balances."
                            data-admin-confirm-ok="Process Refund"
                            data-admin-confirm-icon="fa-rotate-left"
                            data-admin-confirm-tone="danger"
                            data-admin-loader-text="Processing refund..."
                            data-admin-submit-text="Processing...">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="create_refund">

                            <div class="refund-dest">
                                <span class="refund-dest__label">Refund to *</span>
                                <div class="refund-dest__options">
                                    <label class="refund-dest__opt is-selected" id="refund_dest_original">
                                        <input type="radio" name="refund_method" value="original" checked>
                                        <span>
                                            <span class="refund-dest__opt-title"><i class="fas fa-rotate-left"></i> Original payment method</span>
                                            <span class="refund-dest__opt-desc">Return the money the way it was paid (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$payment['payment_method']))); ?>).</span>
                                        </span>
                                    </label>
                                    <label class="refund-dest__opt" id="refund_dest_credit">
                                        <input type="radio" name="refund_method" value="store_credit">
                                        <span>
                                            <span class="refund-dest__opt-title"><i class="fas fa-wallet"></i> Store credit (credit note)</span>
                                            <span class="refund-dest__opt-desc">Issue a credit note the customer can redeem on a future purchase. Settled immediately.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="refund-grid">
                                <div class="refund-field">
                                    <label for="refund_amount">Refund Amount *</label>
                                    <div class="refund-amount" id="refund_amount_wrap">
                                        <span class="refund-amount__symbol"><?php echo htmlspecialchars($currency_symbol); ?></span>
                                        <input type="number" id="refund_amount" name="refund_amount"
                                            step="0.01" min="0.01" max="<?php echo $maxRefundable; ?>"
                                            value="<?php echo $maxRefundable; ?>" required
                                            inputmode="decimal" autocomplete="off">
                                    </div>
                                    <div class="refund-quickfill" aria-label="Quick fill refund amount">
                                        <button type="button" class="refund-chip" data-fill="full">Full (<?php echo $currency_symbol . number_format($maxRefundable, 2); ?>)</button>
                                        <button type="button" class="refund-chip" data-fill="half">50%</button>
                                        <button type="button" class="refund-chip" data-fill="clear">Clear</button>
                                    </div>
                                    <div class="refund-amount-warn" id="refund_amount_warn"><i class="fas fa-triangle-exclamation"></i> Amount exceeds the refundable balance.</div>
                                    <small class="refund-field__hint">Maximum refundable: <?php echo $currency_symbol; ?><?php echo number_format($maxRefundable, 2); ?></small>
                                </div>

                                <div class="refund-field">
                                    <label for="refund_reason">Refund Reason *</label>
                                    <select id="refund_reason" name="refund_reason" required>
                                        <option value="">Select a reason</option>
                                        <option value="early_checkout">Early Checkout</option>
                                        <option value="late_checkout_charge">Late Checkout Charge</option>
                                        <option value="cancellation">Cancellation</option>
                                        <option value="service_issue">Service Issue</option>
                                        <option value="overpayment">Overpayment</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <small class="refund-field__hint">Shown on the customer's refund email.</small>
                                </div>

                                <div class="refund-field" id="refund_status_field">
                                    <label for="refund_status">Refund Status *</label>
                                    <select id="refund_status" name="refund_status" required>
                                        <option value="pending">Pending</option>
                                        <option value="processing">Processing</option>
                                        <option value="completed" <?php echo $isMobileMoneyPayment ? 'disabled' : ''; ?>>Completed</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                    <?php if ($isMobileMoneyPayment): ?>
                                        <small class="refund-field__hint">Mobile money refunds stay pending/processing until the provider confirms settlement.</small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="refund-field" style="margin-top: 16px;">
                                <label for="refund_notes">Refund Notes</label>
                                <textarea id="refund_notes" name="refund_notes" rows="3"
                                    placeholder="Internal notes about this refund (not shown to the customer)..."></textarea>
                            </div>

                            <!-- Refund Summary -->
                            <div class="acct-panel refund-summary" style="background: var(--finance-bg);">
                                <div class="acct-panel__head">
                                    <h3 class="acct-panel__title" style="font-size: 14px;">Refund Summary</h3>
                                </div>
                                <div class="refund-summary__rows">
                                    <div class="refund-summary__row">
                                        <span>Refund amount (excl. VAT)</span>
                                        <strong id="summary_excl_vat"><?php echo $currency_symbol; ?>0.00</strong>
                                    </div>
                                    <div class="refund-summary__row">
                                        <span>VAT portion (pro-rated)</span>
                                        <strong id="summary_vat"><?php echo $currency_symbol; ?>0.00</strong>
                                    </div>
                                    <div class="refund-summary__row">
                                        <span>Remaining refundable after this</span>
                                        <strong id="summary_remaining"><?php echo $currency_symbol; ?><?php echo number_format($maxRefundable, 2); ?></strong>
                                    </div>
                                    <div class="refund-summary__row refund-summary__row--total">
                                        <span>Total refund</span>
                                        <strong id="summary_total"><?php echo $currency_symbol; ?>0.00</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="refund-actions">
                                <button type="submit" class="btn btn-primary" id="refund_submit_btn">
                                    <i class="fas fa-rotate-left"></i> Process Refund
                                </button>
                                <a href="payments.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div><!-- /padding wrapper -->
                </div><!-- /acct-panel form wrapper -->
            <?php endif; // maxRefundable > 0
            ?>
        <?php endif; // payment && !message
        ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

    <script>
        // Calculate refund summary in real-time
        const originalAmount = <?php echo $payment['total_amount'] ?? 0; ?>;
        const maxRefundable = <?php echo $maxRefundable; ?>;
        const vatRate = <?php echo $payment['vat_rate'] ?? 0; ?>;
        const currencySymbol = '<?php echo $currency_symbol; ?>';

        const fmt = (n) => currencySymbol + Number(n).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        const refundEl = document.getElementById('refund_amount');
        const wrapEl = document.getElementById('refund_amount_wrap');
        const warnEl = document.getElementById('refund_amount_warn');
        const submitBtn = document.getElementById('refund_submit_btn');

        function updateSummary() {
            if (!refundEl) return; // form not rendered (fully refunded)
            const refundAmount = parseFloat(refundEl.value) || 0;

            // Calculate VAT portion (pro-rated out of the gross refund)
            const vatAmount = refundAmount * (vatRate / (100 + vatRate));
            const exclVat = refundAmount - vatAmount;
            const remaining = maxRefundable - refundAmount;

            document.getElementById('summary_excl_vat').textContent = fmt(exclVat);
            document.getElementById('summary_vat').textContent = fmt(vatAmount);
            document.getElementById('summary_total').textContent = fmt(refundAmount);
            document.getElementById('summary_remaining').textContent = fmt(Math.max(0, remaining));

            // Validation feedback: block over-refund and zero/negative amounts
            const overLimit = refundAmount > maxRefundable + 0.001;
            const invalid = overLimit || refundAmount <= 0;
            if (wrapEl) wrapEl.classList.toggle('is-invalid', invalid);
            if (warnEl) warnEl.classList.toggle('show', overLimit);
            if (submitBtn) {
                submitBtn.disabled = invalid;
                submitBtn.style.opacity = invalid ? '0.55' : '';
                submitBtn.style.pointerEvents = invalid ? 'none' : '';
            }
        }

        function setAmount(val) {
            if (!refundEl) return;
            refundEl.value = (Math.round(val * 100) / 100).toFixed(2);
            updateSummary();
            refundEl.focus();
        }

        document.querySelectorAll('.refund-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                const mode = chip.getAttribute('data-fill');
                if (mode === 'full') setAmount(maxRefundable);
                else if (mode === 'half') setAmount(maxRefundable / 2);
                else if (mode === 'clear') setAmount(0);
            });
        });

        if (refundEl) {
            refundEl.addEventListener('input', updateSummary);
            updateSummary();
        }

        // ── Refund destination (original method vs store credit) ──────
        const destRadios = document.querySelectorAll('input[name="refund_method"]');
        const statusField = document.getElementById('refund_status_field');
        const statusSelect = document.getElementById('refund_status');

        function syncRefundDestination() {
            const selected = document.querySelector('input[name="refund_method"]:checked');
            const mode = selected ? selected.value : 'original';

            document.querySelectorAll('.refund-dest__opt').forEach((opt) => {
                const r = opt.querySelector('input[type="radio"]');
                opt.classList.toggle('is-selected', !!(r && r.checked));
            });

            const isCredit = mode === 'store_credit';
            // Store credit settles immediately, so the status field is not applicable.
            if (statusField) statusField.classList.toggle('is-disabled', isCredit);
            if (statusSelect) {
                statusSelect.disabled = isCredit; // disabled fields are not POSTed; server forces 'completed'
                if (isCredit) statusSelect.value = 'completed';
            }
        }

        destRadios.forEach((r) => r.addEventListener('change', syncRefundDestination));
        syncRefundDestination();
    </script>
</body>

</html>

