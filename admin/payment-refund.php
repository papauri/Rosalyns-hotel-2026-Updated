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

            // Validate inputs
            if ($refund_amount <= 0) {
                throw new Exception('Refund amount must be greater than zero.');
            }
            if ($refund_amount > $maxRefundable) {
                throw new Exception('Refund amount cannot exceed remaining refundable balance (' . $currency_symbol . number_format($maxRefundable, 2) . ').');
            }
            if (!in_array($refund_reason, ['early_checkout', 'late_checkout_charge', 'cancellation', 'service_issue', 'overpayment', 'other'], true)) {
                throw new Exception('Invalid refund reason.');
            }
            if (!in_array($refund_status, ['pending', 'processing', 'completed', 'failed'], true)) {
                throw new Exception('Invalid refund status.');
            }
            if ($isMobileMoneyPayment && $refund_status === 'completed') {
                throw new Exception('Mobile money refunds must start as pending or processing until provider confirmation.');
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
                $payment['payment_method'],
                $refund_payment_status,
                $payment_id,
                $refund_reason,
                $refund_status,
                $refund_amount,
                $refund_notes,
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

            $pdo->commit();

            // Recalculate booking balances so amount_paid / amount_due stay accurate after refund
            if ($payment['booking_type'] === 'room') {
                recalculateBookingFinancials((int)$payment['booking_id']);
            } elseif ($payment['booking_type'] === 'conference') {
                $cfPaidStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE
                        WHEN payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount
                        WHEN payment_type = 'refund' AND refund_status IN ('completed','processing') THEN -total_amount
                        ELSE 0
                    END), 0) AS amt_paid
                    FROM payments
                    WHERE booking_type = 'conference' AND booking_id = ? AND deleted_at IS NULL
                ");
                $cfPaidStmt->execute([$payment['booking_id']]);
                $cfAmtPaid = max(0.0, (float)($cfPaidStmt->fetchColumn() ?? 0));
                $cfUpdStmt = $pdo->prepare("
                    UPDATE conference_inquiries
                    SET amount_paid = ?, amount_due = GREATEST(0, total_amount - ?), updated_at = NOW()
                    WHERE id = ?
                ");
                $cfUpdStmt->execute([$cfAmtPaid, $cfAmtPaid, $payment['booking_id']]);
            }

            $message = 'Refund created successfully! Reference: ' . $refundRef;

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-finance.css">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content finance-page">
        <div class="acct-page-header">
            <div class="acct-page-header__copy">
                <h1 class="acct-page-header__title">Process Refund</h1>
                <p class="acct-page-header__subtitle">
                    Issue a partial or full refund against an existing payment. Updates booking balances &amp; generates a credit note.
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
            <div class="acct-error" style="background: var(--finance-success-bg); border-color: var(--finance-success-border); color: var(--finance-success);">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <a href="payments.php" class="acct-link" style="margin-left: 12px;">Return to Payments →</a>
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

                            <div class="filter-form">
                                <div class="filter-group">
                                    <label for="refund_amount">Refund Amount *</label>
                                    <div style="position: relative;">
                                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #666;"><?php echo $currency_symbol; ?></span>
                                        <input type="number" id="refund_amount" name="refund_amount"
                                            step="0.01" min="0.01" max="<?php echo $maxRefundable; ?>"
                                            value="<?php echo $maxRefundable; ?>" required
                                            style="padding-left: 30px;">
                                    </div>
                                    <small style="color: #666;">Maximum: <?php echo $currency_symbol; ?><?php echo number_format($maxRefundable, 2); ?></small>
                                </div>

                                <div class="filter-group">
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
                                </div>

                                <div class="filter-group">
                                    <label for="refund_status">Refund Status *</label>
                                    <select id="refund_status" name="refund_status" required>
                                        <option value="pending">Pending</option>
                                        <option value="processing">Processing</option>
                                        <option value="completed" <?php echo $isMobileMoneyPayment ? 'disabled' : ''; ?>>Completed</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                    <?php if ($isMobileMoneyPayment): ?>
                                        <small style="color: #666;">Mobile money refunds should remain pending or processing until provider settlement confirms completion.</small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="filter-group" style="margin-top: 14px;">
                                <label for="refund_notes">Refund Notes</label>
                                <textarea id="refund_notes" name="refund_notes" rows="3"
                                    placeholder="Additional details about this refund..."></textarea>
                            </div>

                            <!-- Refund Summary -->
                            <div class="acct-panel" style="background: var(--finance-bg); margin-top: 18px;">
                                <div class="acct-panel__head">
                                    <h3 class="acct-panel__title" style="font-size: 14px;">Refund Summary</h3>
                                </div>
                                <div style="padding: 14px 18px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; font-size: 13px;">
                                    <div>
                                        <span style="color: var(--finance-muted);">Refund Amount (excl. VAT):</span>
                                        <div id="summary_excl_vat" style="font-weight: 600;"><?php echo $currency_symbol; ?>0.00</div>
                                    </div>
                                    <div>
                                        <span style="color: var(--finance-muted);">VAT Amount:</span>
                                        <div id="summary_vat" style="font-weight: 600;"><?php echo $currency_symbol; ?>0.00</div>
                                    </div>
                                    <div>
                                        <span style="color: var(--finance-muted);">Total Refund:</span>
                                        <div id="summary_total" style="font-weight: 700; color: var(--finance-danger);"><?php echo $currency_symbol; ?>0.00</div>
                                    </div>
                                    <div>
                                        <span style="color: var(--finance-muted);">Remaining Balance:</span>
                                        <div id="summary_remaining" style="font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format($maxRefundable, 0); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons" style="margin-top: 20px;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Process Refund
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

        function updateSummary() {
            const refundEl = document.getElementById('refund_amount');
            if (!refundEl) return; // form not rendered (fully refunded)
            const refundAmount = parseFloat(refundEl.value) || 0;

            // Calculate VAT portion (pro-rated)
            const vatAmount = refundAmount * (vatRate / (100 + vatRate));
            const exclVat = refundAmount - vatAmount;
            const remaining = maxRefundable - refundAmount;

            document.getElementById('summary_excl_vat').textContent = currencySymbol + Number(exclVat).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('summary_vat').textContent = currencySymbol + Number(vatAmount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('summary_total').textContent = currencySymbol + Number(refundAmount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('summary_remaining').textContent = currencySymbol + Number(remaining).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        const refundEl = document.getElementById('refund_amount');
        if (refundEl) {
            refundEl.addEventListener('input', updateSummary);
            updateSummary(); // Initial calculation
        }
    </script>
</body>

</html>
