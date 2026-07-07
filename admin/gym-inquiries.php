<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once '../includes/alert.php';
require_once '../includes/finance-sequences.php';

finance_ensure_sequence_tables($pdo);

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$_can_gym_financials = hasPermission((int)($user['id'] ?? 0), 'gym_financials');
$currency_symbol = (string)getSetting('currency_symbol', 'K');

// Receivable-account payment sync (syncGymInquiryPaymentSnapshot) is defined in
// the shared include so payment-add.php and this page compute identical balances.
// Its model derives amount_due from the LOCKED invoiced gross (total_with_vat),
// which is rate-safe — it never rewrites an old-rate invoice to the current rate.
require_once __DIR__ . '/includes/finance-account-sync.php';

// Handle status updates and deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inquiry_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Security token invalid.']); exit; }
        header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
    }
    try {
        $inquiry_id = $_POST['inquiry_id'] ?? 0;
        $action = $_POST['inquiry_action'];

        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? 'new';
            $allowed_gym_statuses = ['new', 'contacted', 'confirmed', 'converted', 'closed', 'cancelled'];
            if (!in_array($new_status, $allowed_gym_statuses, true)) {
                throw new Exception('Invalid status value.');
            }

            // Fetch inquiry before updating for email context
            $fetch = $pdo->prepare("SELECT * FROM gym_inquiries WHERE id = ?");
            $fetch->execute([$inquiry_id]);
            $inquiry_row = $fetch->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $inquiry_id]);

            // Send status-change emails
            if ($inquiry_row && !empty($inquiry_row['email'])) {
                if ($new_status === 'contacted') {
                    $email_result = sendGymInquiryContactedEmail($inquiry_row);
                    $email_note = $email_result['success']
                        ? ' Notification email sent to ' . htmlspecialchars($inquiry_row['email']) . '.'
                        : ' (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
                    $message = 'Inquiry marked as Contacted.' . $email_note;
                } elseif ($new_status === 'converted') {
                    $email_result = sendGymInquiryConvertedEmail($inquiry_row);
                    $email_note = $email_result['success']
                        ? ' Welcome email sent to ' . htmlspecialchars($inquiry_row['email']) . '.'
                        : ' (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
                    $message = 'Inquiry converted to member.' . $email_note;
                } else {
                    $message = 'Inquiry status updated successfully!';
                }
            } else {
                $message = 'Inquiry status updated successfully!';
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM gym_inquiries WHERE id = ?");
            $stmt->execute([$inquiry_id]);
            $message = 'Gym inquiry deleted successfully!';
        } elseif ($action === 'send_message') {
            // Free-form message emailed straight to the member from this page.
            $inquiry_id = (int)$inquiry_id;
            if ($inquiry_id <= 0) {
                throw new Exception('Invalid inquiry selected.');
            }

            $fetch = $pdo->prepare("SELECT * FROM gym_inquiries WHERE id = ?");
            $fetch->execute([$inquiry_id]);
            $inquiry_row = $fetch->fetch(PDO::FETCH_ASSOC);
            if (!$inquiry_row) {
                throw new Exception('Gym inquiry not found.');
            }
            $recipient = trim((string)($inquiry_row['email'] ?? ''));
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('This inquiry has no valid email address on file.');
            }

            $subject = trim((string)($_POST['email_subject'] ?? ''));
            $bodyText = trim((string)($_POST['email_message'] ?? ''));
            if ($subject === '' || $bodyText === '') {
                throw new Exception('Both a subject and a message are required to email the member.');
            }
            if (mb_strlen($subject) > 200) {
                $subject = mb_substr($subject, 0, 200);
            }

            $site_name = (string)getSetting('site_name', 'Our Gym');
            $memberName = htmlspecialchars((string)($inquiry_row['name'] ?? 'Member'), ENT_QUOTES, 'UTF-8');
            // Preserve the admin's line breaks; escape everything to prevent HTML injection.
            $safeBody = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
            $htmlBody = '
                <h1 style="color:#8B7355;text-align:center;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>
                <p>Dear ' . $memberName . ',</p>
                <div style="color:#333;line-height:1.7;font-size:15px;margin:16px 0;">' . $safeBody . '</div>
                <p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">Warm regards &mdash; ' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') . '</p>';

            $email_result = sendEmail($recipient, (string)($inquiry_row['name'] ?? ''), $subject, $htmlBody);
            if (!empty($email_result['success'])) {
                $preview = !empty($email_result['preview']) || !empty($email_result['preview_url']);
                $message = $preview
                    ? 'Email preview generated (development mode). No live email was sent.'
                    : 'Message emailed to ' . htmlspecialchars($recipient) . '.';
            } else {
                throw new Exception('Failed to send email: ' . ($email_result['message'] ?? 'Unknown error'));
            }
        } elseif (in_array($action, ['confirm', 'cancel', 'complete', 'send_invoice', 'send_quotation', 'update_amount', 'update_notes'], true)) {
            $inquiry_id = (int)$inquiry_id;
            if ($inquiry_id <= 0) {
                throw new Exception('Invalid inquiry selected.');
            }

            if (in_array($action, ['send_invoice', 'send_quotation', 'update_amount'], true) && !hasPermission((int)($user['id'] ?? 0), 'gym_financials')) {
                throw new Exception('You do not have permission to handle gym invoicing or pricing.');
            }

            $stmt = $pdo->prepare("SELECT * FROM gym_inquiries WHERE id = ?");
            $stmt->execute([$inquiry_id]);
            $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inquiry) {
                throw new Exception('Gym inquiry not found!');
            }

            $paymentSnapshot = syncGymInquiryPaymentSnapshot($pdo, $inquiry_id);
            if ($paymentSnapshot !== null) {
                $inquiry['amount_paid'] = $paymentSnapshot['amount_paid'];
                $inquiry['amount_due'] = $paymentSnapshot['amount_due'];
                $inquiry['deposit_paid'] = $paymentSnapshot['deposit_paid'];
            }

            if (in_array($action, ['confirm', 'complete'], true)) {
                $depositRequired = (float)($paymentSnapshot['deposit_required'] ?? $inquiry['deposit_required'] ?? 0);
                $depositPaid = (float)($paymentSnapshot['deposit_paid'] ?? $inquiry['deposit_paid'] ?? 0);
                if ($depositRequired > 0 && $depositPaid + 0.0001 < $depositRequired) {
                    throw new Exception('Required gym deposit has not been fully paid yet.');
                }
            }

            if ($action === 'confirm') {
                if (($inquiry['status'] ?? '') !== 'new') {
                    throw new Exception('Only new inquiries can be confirmed.');
                }

                $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = 'confirmed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);

                $email_result = sendGymConfirmedEmail($inquiry);
                $message = $email_result['success']
                    ? 'Gym membership confirmed successfully! Confirmation email sent.'
                    : 'Gym membership confirmed successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
            } elseif ($action === 'cancel') {
                $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);

                // Refund accounting: record a refund row if any completed payment exists.
                $gymCanPay = $pdo->prepare("
                    SELECT SUM(total_amount) as total_paid
                    FROM payments
                    WHERE booking_type = 'gym' AND booking_id = ?
                      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                      AND deleted_at IS NULL
                ");
                $gymCanPay->execute([$inquiry_id]);
                $gymPaidTotal = (float)(($gymCanPay->fetch(PDO::FETCH_ASSOC))['total_paid'] ?? 0);

                if ($gymPaidTotal > 0) {
                    do {
                        $gymRefRef = 'RFD-GYM-' . strtoupper(substr(uniqid(), -8));
                        $gymRefChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                        $gymRefChk->execute([$gymRefRef]);
                    } while ((int)$gymRefChk->fetchColumn() > 0);

                    $gymVatEnabled = getSetting('vat_enabled') === '1';
                    $gymVatRate    = $gymVatEnabled ? (float)getSetting('vat_rate') : 0;
                    $gymVatAmt     = $gymVatRate > 0 ? round($gymPaidTotal * ($gymVatRate / (100 + $gymVatRate)), 2) : 0;
                    $gymNetAmt     = round($gymPaidTotal - $gymVatAmt, 2);

                    $pdo->prepare("
                        INSERT INTO payments (
                            payment_reference, booking_type, booking_id, booking_reference,
                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status,
                            refund_reason, refund_status, refund_amount,
                            recorded_by, created_at
                        ) VALUES (?, 'gym', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'refund', 'completed',
                                  'cancellation', 'completed', ?, ?, NOW())
                    ")->execute([
                        $gymRefRef,
                        $inquiry_id,
                        $inquiry['reference_number'] ?? '',
                        $gymNetAmt,
                        $gymVatRate,
                        $gymVatAmt,
                        $gymPaidTotal,
                        $gymPaidTotal,
                        (int)($user['id'] ?? 0),
                    ]);

                    $pdo->prepare("UPDATE gym_inquiries SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
                        ->execute([$inquiry_id]);
                }

                $email_result = sendGymCancelledEmail($inquiry);
                $message = $email_result['success']
                    ? 'Gym membership cancelled successfully! Cancellation email sent.'
                    : 'Gym membership cancelled successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
            } elseif ($action === 'complete') {
                if (($inquiry['status'] ?? '') !== 'confirmed') {
                    throw new Exception('Only confirmed memberships can be marked completed.');
                }

                $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = 'closed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);
                $message = 'Gym membership marked as completed!';
            } elseif ($action === 'send_invoice') {
                try {
                    $totalAmount = (float)$inquiry['total_amount'];
                    // VAT per installation mode (exclusive on top / inclusive extracted / off).
                    $vatParts = vat_components($totalAmount);
                    $vatRate = $vatParts['rate'];
                    $vatAmount = $vatParts['vat'];
                    $totalWithVat = $vatParts['total'];

                    // Idempotency guard — if already fully paid just resend the invoice
                    $alreadyPaid = (float)($paymentSnapshot['amount_paid'] ?? 0);
                    if ($alreadyPaid >= $totalWithVat - 0.01 && $totalWithVat > 0) {
                        $invoice_result = sendGymInvoiceEmail($inquiry_id);
                        $message = 'Payment already recorded. Invoice resent to ' . htmlspecialchars($inquiry['email'] ?? '');
                        $message .= $invoice_result['success'] ? '' : ' (Invoice email failed: ' . $invoice_result['message'] . ')';
                    } else {
                        do {
                            $payment_reference = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
                            $refChk = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ? LIMIT 1');
                            $refChk->execute([$payment_reference]);
                        } while ((int)$refChk->fetchColumn() > 0);

                        $pdo->beginTransaction();
                        $receipt_number = finance_next_receipt_number($pdo, date('Y-m-d'));

                        $insert_payment = $pdo->prepare("
                                INSERT INTO payments (
                                    payment_reference, booking_type, booking_id, booking_reference,
                                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                    payment_method, payment_type, payment_status, invoice_generated,
                                    receipt_number, status, recorded_by
                                ) VALUES (?, 'gym', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?)
                            ");
                        $insert_payment->execute([
                            $payment_reference,
                            $inquiry_id,
                            $inquiry['reference_number'],
                            $vatParts['net'], // payment_amount is always the ex-VAT figure
                            $vatRate,
                            $vatAmount,
                            $totalWithVat,
                            $receipt_number,
                            $user['id']
                        ]);
                        $gym_payment_id = (int)$pdo->lastInsertId();

                        $update_amounts = $pdo->prepare("
                                UPDATE gym_inquiries
                                SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                                    total_with_vat = ?, last_payment_date = CURDATE(), payment_status = 'full_paid'
                                WHERE id = ?
                            ");
                        $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $inquiry_id]);
                        $pdo->commit();

                        if ($gym_payment_id > 0) {
                            try {
                                require_once '../config/receipts.php';
                                receipt_auto_send($pdo, $gym_payment_id, $user);
                            } catch (Throwable $rcptEx) {
                                error_log('Receipt email failed for gym payment ' . $gym_payment_id . ': ' . $rcptEx->getMessage());
                            }
                        }

                        $invoice_result = sendGymInvoiceEmail($inquiry_id);
                        if ($invoice_result['success']) {
                            $message = 'Payment recorded successfully! Invoice sent to ' . htmlspecialchars($inquiry['email']);
                        } else {
                            $message = 'Payment recorded successfully! (Invoice email failed: ' . $invoice_result['message'] . ')';
                        }
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Failed to record payment: ' . $e->getMessage();
                    error_log("Gym payment error: " . $e->getMessage());
                }
            } elseif ($action === 'send_quotation') {
                $quoteValidDays = max(1, (int)($_POST['quotation_valid_days'] ?? 7));
                $quoteNotes = trim((string)($_POST['quotation_notes'] ?? ''));
                $sendWhatsapp = isset($_POST['send_whatsapp']);

                $quoteResult = sendGymQuotationEmail($inquiry, [
                    'valid_days' => $quoteValidDays,
                    'quotation_notes' => $quoteNotes,
                    'attach_pdf' => true,
                    'send_whatsapp' => $sendWhatsapp,
                ]);

                if (!empty($quoteResult['success'])) {
                    $message = 'Gym membership quotation sent to ' . htmlspecialchars((string)($inquiry['email'] ?? '')) . '.';
                    if (!empty($quoteResult['whatsapp']['success'])) {
                        $message .= ' WhatsApp delivered.';
                    }
                } else {
                    $error = 'Failed to send quotation: ' . ($quoteResult['message'] ?? 'Unknown error');
                }
            } elseif ($action === 'update_amount') {
                $amount = $_POST['total_amount'] ?? 0;
                $stmt = $pdo->prepare("UPDATE gym_inquiries SET total_amount = ? WHERE id = ?");
                $stmt->execute([$amount, $inquiry_id]);
                $message = 'Total amount updated successfully!';
            } elseif ($action === 'update_notes') {
                $notes = $_POST['notes'] ?? '';
                $stmt = $pdo->prepare("UPDATE gym_inquiries SET notes = ? WHERE id = ?");
                $stmt->execute([$notes, $inquiry_id]);
                $message = 'Notes updated successfully!';
            }
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Gym inquiry action error: ' . $e->getMessage());
        $error = 'An unexpected error occurred. The status may have been updated but the notification email could not be sent.';
    }
}

// Fetch gym inquiries with search/filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $sql = "SELECT * FROM gym_inquiries WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR reference_number LIKE ?)";
        $search_term = '%' . $search . '%';
        $params = array_fill(0, 4, $search_term);
    }

    if (!empty($status_filter)) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $gym_inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gym_inquiries = [];
    $error = 'Error fetching gym inquiries: ' . $e->getMessage();
}

// Get status counts for filter tabs
try {
    $status_counts = [];
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM gym_inquiries GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_counts[$row['status']] = $row['count'];
    }
} catch (PDOException $e) {
    $status_counts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>(function(){var _t='<?= htmlspecialchars($csrf_token,ENT_QUOTES)?>';var _f=window.fetch;window.fetch=function(u,o){if(o&&o.body instanceof FormData&&!o.body.has('csrf_token'))o.body.append('csrf_token',_t);return _f.apply(this,arguments);};})();</script>
    <title>Gym Inquiries - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>"></head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div class="page-header">
            <h2 class="section-title"><i class="fas fa-dumbbell"></i> Gym Inquiries Management</h2>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="gym-inquiries.php" class="filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                All <?php if (!empty($status_counts)) { ?><span class="count"><?php echo array_sum($status_counts); ?></span><?php } ?>
            </a>
            <a href="gym-inquiries.php?status=new" class="filter-tab <?php echo $status_filter === 'new' ? 'active' : ''; ?>">
                New <?php if (isset($status_counts['new'])) { ?><span class="count"><?php echo $status_counts['new']; ?></span><?php } ?>
            </a>
            <a href="gym-inquiries.php?status=contacted" class="filter-tab <?php echo $status_filter === 'contacted' ? 'active' : ''; ?>">
                Contacted <?php if (isset($status_counts['contacted'])) { ?><span class="count"><?php echo $status_counts['contacted']; ?></span><?php } ?>
            </a>
            <a href="gym-inquiries.php?status=converted" class="filter-tab <?php echo $status_filter === 'converted' ? 'active' : ''; ?>">
                Converted <?php if (isset($status_counts['converted'])) { ?><span class="count"><?php echo $status_counts['converted']; ?></span><?php } ?>
            </a>
            <a href="gym-inquiries.php?status=closed" class="filter-tab <?php echo $status_filter === 'closed' ? 'active' : ''; ?>">
                Closed <?php if (isset($status_counts['closed'])) { ?><span class="count"><?php echo $status_counts['closed']; ?></span><?php } ?>
            </a>
            <a href="gym-inquiries.php?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                Cancelled <?php if (isset($status_counts['cancelled'])) { ?><span class="count"><?php echo $status_counts['cancelled']; ?></span><?php } ?>
            </a>
        </div>

        <!-- Search Bar -->
        <form method="GET" class="search-bar">
            <input type="text" name="search" placeholder="Search by name, email, phone, or reference..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
            <?php if (!empty($status_filter)): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <?php if (!empty($search) || !empty($status_filter)): ?>
                <a href="gym-inquiries.php" class="btn btn-danger"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>

        <!-- Inquiries Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Package</th>
                        <th>Preferred Date</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gym_inquiries)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No gym inquiries found</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($gym_inquiries as $inquiry): ?>
                    <tr id="inquiry-<?php echo (int)$inquiry['id']; ?>" data-focus="inquiry-<?php echo (int)$inquiry['id']; ?>">
                        <td><strong><?php echo htmlspecialchars($inquiry['reference_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($inquiry['name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($inquiry['email']); ?><br>
                            <small><?php echo htmlspecialchars($inquiry['phone']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($inquiry['membership_type'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($inquiry['preferred_date']): ?>
                                <?php echo date('M j, Y', strtotime($inquiry['preferred_date'])); ?>
                                <?php if ($inquiry['preferred_time']): ?>
                                    <br><small><?php echo date('H:i', strtotime($inquiry['preferred_time'])); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>N/A</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="inquiry_action" value="update_status">
                                <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                <div class="status-pill-select" data-status="<?php echo htmlspecialchars($inquiry['status'], ENT_QUOTES); ?>">
                                    <span class="status-dot"></span>
                                    <select name="new_status" class="status-select" onchange="this.form.submit();" aria-label="Change status">
                                        <option value="new" <?php echo $inquiry['status'] === 'new' ? 'selected' : ''; ?>>New</option>
                                        <option value="contacted" <?php echo $inquiry['status'] === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                                        <option value="confirmed" <?php echo $inquiry['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed (paid)</option>
                                        <option value="converted" <?php echo $inquiry['status'] === 'converted' ? 'selected' : ''; ?>>Converted</option>
                                        <option value="closed" <?php echo $inquiry['status'] === 'closed' ? 'selected' : ''; ?>>Closed / Completed</option>
                                        <option value="cancelled" <?php echo $inquiry['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                    <i class="fas fa-chevron-down status-caret"></i>
                                </div>
                            </form>
                        </td>
                        <td>
                            <small><?php echo date('M j, Y', strtotime($inquiry['created_at'])); ?></small><br>
                            <small style="color:#999;"><?php echo date('H:i', strtotime($inquiry['created_at'])); ?></small>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button type="button" class="btn btn-primary btn-sm" onclick="showInquiryDetails(<?php echo htmlspecialchars(json_encode($inquiry)); ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (!empty($inquiry['email'])): ?>
                                <button type="button" class="btn btn-sm" style="background:#8B7355;color:#fff;"
                                        title="Email this member directly"
                                        onclick='openEmailComposer(<?php echo htmlspecialchars(json_encode(['id' => (int)$inquiry['id'], 'name' => (string)$inquiry['name'], 'email' => (string)$inquiry['email'], 'reference_number' => (string)($inquiry['reference_number'] ?? '')]), ENT_QUOTES); ?>)'>
                                    <i class="fas fa-envelope"></i> Email
                                </button>
                                <?php endif; ?>
                                <?php if ($inquiry['status'] !== 'cancelled'): ?>
                                <a class="btn btn-sm" style="background:#2e7d32;color:#ffffff;text-decoration:none;"
                                   title="Enrol this inquiry as a gym member (opens the register pre-filled)"
                                   href="gym-members.php?enrol_name=<?php echo urlencode((string)$inquiry['name']); ?>&enrol_email=<?php echo urlencode((string)($inquiry['email'] ?? '')); ?>&enrol_phone=<?php echo urlencode((string)($inquiry['phone'] ?? '')); ?>&enrol_type=<?php echo urlencode((string)($inquiry['membership_type'] ?? '')); ?>&enrol_inquiry_id=<?php echo (int)$inquiry['id']; ?>">
                                    <i class="fas fa-id-card"></i> Enrol
                                </a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="inquiry_action" value="delete">
                                    <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this inquiry?');">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inquiry Details Modal -->
    <div id="inquiryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Gym Inquiry Details</h3>
                <span class="close" onclick="closeInquiryModal()">&times;</span>
            </div>
            <div class="modal-body" id="inquiryModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Email Composer Modal -->
    <div id="emailComposerModal" class="modal">
        <div class="modal-content" style="max-width:min(96vw,42rem);width:min(96vw,42rem);">
            <div class="modal-header">
                <h3><i class="fas fa-envelope" style="color:#8B7355;"></i> Email Member</h3>
                <span class="close" onclick="closeEmailComposer()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" id="emailComposerForm">
                    <input type="hidden" name="inquiry_action" value="send_message">
                    <input type="hidden" name="inquiry_id" id="composer-inquiry-id">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div class="composer-to" id="composer-to"></div>
                    <div class="composer-field">
                        <label for="email_subject">Subject</label>
                        <input type="text" id="email_subject" name="email_subject" class="form-control" maxlength="200" required placeholder="e.g. Following up on your gym membership inquiry">
                    </div>
                    <div class="composer-field">
                        <label for="email_message">Message</label>
                        <textarea id="email_message" name="email_message" class="form-control" rows="7" required placeholder="Write your message to the member. Line breaks are preserved."></textarea>
                        <small class="composer-hint">Sent as a branded email from your hotel. The member's name and your signature are added automatically.</small>
                    </div>
                    <div class="composer-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEmailComposer()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="composer-send-btn"><i class="fas fa-paper-plane"></i> Send Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        var canGymFinancials = <?php echo json_encode($_can_gym_financials); ?>;
        var gymCsrfToken = <?php echo json_encode($csrf_token); ?>;
        var gymCurrencySymbol = <?php echo json_encode($currency_symbol); ?>;

        // Global HTML-escape helper (also used by the email composer).
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = String(str == null ? '' : str);
            return div.innerHTML;
        }

        function showInquiryDetails(inquiry) {
            const modal = document.getElementById('inquiryModal');
            const body = document.getElementById('inquiryModalBody');
            window._gymCurrentInquiry = inquiry;

            const statusColors = {
                'new': '#17a2b8',
                'contacted': '#ffc107',
                'confirmed': '#8B7355',
                'converted': '#28a745',
                'closed': '#6c757d',
                'cancelled': '#dc3545'
            };

            function money(n) {
                return gymCurrencySymbol + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = String(str == null ? '' : str);
                return div.innerHTML;
            }

            const totalAmount = inquiry.total_amount || 0;
            const amountPaid = inquiry.amount_paid || 0;
            const amountDue = inquiry.amount_due || 0;
            const paymentStatus = inquiry.payment_status || 'pending';

            const financialHtml = `
                <div class="detail-item" style="grid-column: 1 / -1; border-top: 1px solid #eee; margin-top: 10px; padding-top: 14px;">
                    <label style="font-weight:700;">Accounting</label>
                </div>
                <div class="detail-item">
                    <label>Total Amount</label>
                    <span>${money(totalAmount)}</span>
                </div>
                <div class="detail-item">
                    <label>Amount Paid</label>
                    <span style="color:#28a745;font-weight:600;">${money(amountPaid)}</span>
                </div>
                <div class="detail-item">
                    <label>Amount Due</label>
                    <span style="color:${amountDue > 0 ? '#dc3545' : '#28a745'};font-weight:600;">${money(amountDue)}</span>
                </div>
                <div class="detail-item">
                    <label>Payment Status</label>
                    <span>${paymentStatus.replace('_', ' ')}</span>
                </div>
                ${canGymFinancials ? `
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="inquiry_action" value="update_amount">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <label style="margin:0;">Set Total Amount:</label>
                        <input type="number" step="0.01" min="0" name="total_amount" value="${totalAmount}" class="form-control" style="max-width:160px;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Amount</button>
                    </form>
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" name="inquiry_action" value="send_invoice">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm" ${totalAmount > 0 ? '' : 'disabled title="Set a total amount first"'}><i class="fas fa-file-invoice-dollar"></i> Record Payment &amp; Send Invoice</button>
                    </form>
                    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                        <input type="hidden" name="inquiry_action" value="send_quotation">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <input type="hidden" name="send_whatsapp" value="1">
                        <input type="number" min="1" name="quotation_valid_days" value="7" class="form-control" style="max-width:100px;" title="Valid for (days)">
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-file-invoice"></i> Send Quotation</button>
                    </form>
                </div>
                ` : ''}
                <div class="detail-item" style="grid-column: 1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
                    ${inquiry.status === 'new' ? `
                    <form method="POST" onsubmit="return confirm('Confirm this gym membership?');">
                        <input type="hidden" name="inquiry_action" value="confirm">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Confirm</button>
                    </form>` : ''}
                    ${inquiry.status === 'confirmed' ? `
                    <form method="POST" onsubmit="return confirm('Mark this membership as completed?');">
                        <input type="hidden" name="inquiry_action" value="complete">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-flag-checkered"></i> Mark Completed</button>
                    </form>` : ''}
                    ${(inquiry.status !== 'cancelled') ? `
                    <form method="POST" onsubmit="return confirm('Cancel this membership? Any recorded payment will be refunded.');">
                        <input type="hidden" name="inquiry_action" value="cancel">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Cancel &amp; Refund</button>
                    </form>` : ''}
                    ${inquiry.email ? `
                    <button type="button" class="btn btn-sm" style="background:#8B7355;color:#fff;" onclick="openEmailComposer(window._gymCurrentInquiry)">
                        <i class="fas fa-envelope"></i> Email Member
                    </button>` : ''}
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="hidden" name="inquiry_action" value="update_notes">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <textarea name="notes" class="form-control" rows="2" placeholder="Internal notes...">${escapeHtml(inquiry.notes || '')}</textarea>
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save"></i> Save Notes</button>
                    </form>
                </div>
            `;

            body.innerHTML = `
                <div class="inquiry-details">
                    <div class="detail-item">
                        <label>Reference Number</label>
                        <span><strong>${escapeHtml(inquiry.reference_number)}</strong></span>
                    </div>
                    <div class="detail-item">
                        <label>Full Name</label>
                        <span>${escapeHtml(inquiry.name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Email</label>
                        <span><a href="mailto:${encodeURIComponent(inquiry.email || '')}">${escapeHtml(inquiry.email)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Phone</label>
                        <span><a href="tel:${encodeURIComponent(inquiry.phone || '')}">${escapeHtml(inquiry.phone)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Membership Type</label>
                        <span>${escapeHtml(inquiry.membership_type || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Preferred Date</label>
                        <span>${inquiry.preferred_date ? new Date(inquiry.preferred_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Preferred Time</label>
                        <span>${inquiry.preferred_time ? new Date('1970-01-01T' + inquiry.preferred_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Number of People</label>
                        <span>${inquiry.guests || 1}</span>
                    </div>
                    <div class="detail-item">
                        <label>Status</label>
                        <span><span class="badge" style="background:${statusColors[inquiry.status] || '#999'};color:white;">${inquiry.status.charAt(0).toUpperCase() + inquiry.status.slice(1)}</span></span>
                    </div>
                    <div class="detail-item">
                        <label>Consent Given</label>
                        <span>${inquiry.consent ? '✓ Yes' : '✗ No'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Created At</label>
                        <span>${new Date(inquiry.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
                    </div>
                    ${inquiry.message ? `
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <label>Message / Goals</label>
                        <span style="white-space: pre-wrap; background: #f8f9fa; padding: 12px; border-radius: 6px;">${escapeHtml(inquiry.message)}</span>
                    </div>
                    ` : ''}
                    ${financialHtml}
                </div>
            `;

            modal.classList.add('show');
        }

        function closeInquiryModal() {
            document.getElementById('inquiryModal').classList.remove('show');
        }

        // ── Direct email composer ─────────────────────────────────────────
        function openEmailComposer(inquiry) {
            const modal = document.getElementById('emailComposerModal');
            if (!modal) return;
            document.getElementById('composer-inquiry-id').value = inquiry.id;

            const ref = inquiry.reference_number ? ' · ' + escapeHtml(inquiry.reference_number) : '';
            document.getElementById('composer-to').innerHTML =
                '<span class="composer-to-label">To</span>' +
                '<span class="composer-to-value">' + escapeHtml(inquiry.name || 'Member') +
                ' &lt;' + escapeHtml(inquiry.email || '') + '&gt;' + ref + '</span>';

            const form = document.getElementById('emailComposerForm');
            form.email_subject.value = '';
            form.email_message.value = '';

            // Close the details modal if it happens to be open, then show composer.
            closeInquiryModal();
            modal.classList.add('show');
            setTimeout(function() { form.email_subject.focus(); }, 80);
        }

        function closeEmailComposer() {
            document.getElementById('emailComposerModal').classList.remove('show');
        }

        // Guard against a double-submit sending two emails.
        document.getElementById('emailComposerForm').addEventListener('submit', function() {
            const btn = document.getElementById('composer-send-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        });

        // Close modals when clicking the backdrop
        window.addEventListener('click', function(event) {
            if (event.target === document.getElementById('inquiryModal')) {
                closeInquiryModal();
            }
            if (event.target === document.getElementById('emailComposerModal')) {
                closeEmailComposer();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeInquiryModal();
                closeEmailComposer();
            }
        });
    </script>
    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>

