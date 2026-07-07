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
$_can_events_financials = hasPermission((int)($user['id'] ?? 0), 'events_financials');
$currency_symbol = (string)getSetting('currency_symbol', 'K');

// Receivable-account payment sync (syncEventInquiryPaymentSnapshot) lives in the
// shared include so payment-add.php and this page compute identical balances.
// amount_due is derived from the LOCKED invoiced gross (total_with_vat) — rate-safe.
require_once __DIR__ . '/includes/finance-account-sync.php';

// Handle status updates, deletions and financial actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inquiry_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Security token invalid.']); exit; }
        header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
    }
    try {
        $inquiry_id = $_POST['inquiry_id'] ?? 0;
        $action = $_POST['inquiry_action'];

        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? 'pending';
            $stmt = $pdo->prepare("UPDATE event_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $inquiry_id]);
            $message = 'Event booking status updated successfully!';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM event_inquiries WHERE id = ?");
            $stmt->execute([$inquiry_id]);
            $message = 'Event booking deleted successfully!';
        } elseif (in_array($action, ['confirm', 'cancel', 'complete', 'send_invoice', 'send_quotation', 'update_amount', 'update_notes'], true)) {
            $inquiry_id = (int)$inquiry_id;
            if ($inquiry_id <= 0) {
                throw new Exception('Invalid booking selected.');
            }

            if (in_array($action, ['send_invoice', 'send_quotation', 'update_amount'], true) && !hasPermission((int)($user['id'] ?? 0), 'events_financials')) {
                throw new Exception('You do not have permission to handle event invoicing or pricing.');
            }

            $stmt = $pdo->prepare("
                SELECT ei.*, e.title AS event_title, e.event_date AS event_date
                FROM event_inquiries ei
                LEFT JOIN events e ON e.id = ei.event_id
                WHERE ei.id = ?
            ");
            $stmt->execute([$inquiry_id]);
            $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inquiry) {
                throw new Exception('Event booking not found!');
            }

            $paymentSnapshot = syncEventInquiryPaymentSnapshot($pdo, $inquiry_id);
            if ($paymentSnapshot !== null) {
                $inquiry['amount_paid'] = $paymentSnapshot['amount_paid'];
                $inquiry['amount_due'] = $paymentSnapshot['amount_due'];
                $inquiry['deposit_paid'] = $paymentSnapshot['deposit_paid'];
            }

            if (in_array($action, ['confirm', 'complete'], true)) {
                $depositRequired = (float)($paymentSnapshot['deposit_required'] ?? $inquiry['deposit_required'] ?? 0);
                $depositPaid = (float)($paymentSnapshot['deposit_paid'] ?? $inquiry['deposit_paid'] ?? 0);
                if ($depositRequired > 0 && $depositPaid + 0.0001 < $depositRequired) {
                    throw new Exception('Required event deposit has not been fully paid yet.');
                }
            }

            if ($action === 'confirm') {
                if (($inquiry['status'] ?? '') !== 'pending') {
                    throw new Exception('Only pending bookings can be confirmed.');
                }

                $stmt = $pdo->prepare("UPDATE event_inquiries SET status = 'confirmed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);

                $email_result = sendEventBookingConfirmedEmail($inquiry);
                $message = $email_result['success']
                    ? 'Event booking confirmed successfully! Confirmation email sent.'
                    : 'Event booking confirmed successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
            } elseif ($action === 'cancel') {
                $stmt = $pdo->prepare("UPDATE event_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);

                // Refund accounting: record a refund row if any completed payment exists.
                $eventCanPay = $pdo->prepare("
                    SELECT SUM(total_amount) as total_paid
                    FROM payments
                    WHERE booking_type = 'event' AND booking_id = ?
                      AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                      AND deleted_at IS NULL
                ");
                $eventCanPay->execute([$inquiry_id]);
                $eventPaidTotal = (float)(($eventCanPay->fetch(PDO::FETCH_ASSOC))['total_paid'] ?? 0);

                if ($eventPaidTotal > 0) {
                    do {
                        $eventRefRef = 'RFD-EVT-' . strtoupper(substr(uniqid(), -8));
                        $eventRefChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                        $eventRefChk->execute([$eventRefRef]);
                    } while ((int)$eventRefChk->fetchColumn() > 0);

                    $eventVatEnabled = getSetting('vat_enabled') === '1';
                    $eventVatRate    = $eventVatEnabled ? (float)getSetting('vat_rate') : 0;
                    $eventVatAmt     = $eventVatRate > 0 ? round($eventPaidTotal * ($eventVatRate / (100 + $eventVatRate)), 2) : 0;
                    $eventNetAmt     = round($eventPaidTotal - $eventVatAmt, 2);

                    $pdo->prepare("
                        INSERT INTO payments (
                            payment_reference, booking_type, booking_id, booking_reference,
                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status,
                            refund_reason, refund_status, refund_amount,
                            recorded_by, created_at
                        ) VALUES (?, 'event', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'refund', 'completed',
                                  'cancellation', 'completed', ?, ?, NOW())
                    ")->execute([
                        $eventRefRef,
                        $inquiry_id,
                        $inquiry['reference_number'] ?? '',
                        $eventNetAmt,
                        $eventVatRate,
                        $eventVatAmt,
                        $eventPaidTotal,
                        $eventPaidTotal,
                        (int)($user['id'] ?? 0),
                    ]);

                    $pdo->prepare("UPDATE event_inquiries SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
                        ->execute([$inquiry_id]);
                }

                $email_result = sendEventCancelledEmail($inquiry);
                $message = $email_result['success']
                    ? 'Event booking cancelled successfully! Cancellation email sent.'
                    : 'Event booking cancelled successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
            } elseif ($action === 'complete') {
                if (($inquiry['status'] ?? '') !== 'confirmed') {
                    throw new Exception('Only confirmed bookings can be marked completed.');
                }

                $stmt = $pdo->prepare("UPDATE event_inquiries SET status = 'completed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);
                $message = 'Event booking marked as completed!';
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
                        $invoice_result = sendEventInvoiceEmail($inquiry_id);
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
                                ) VALUES (?, 'event', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?)
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
                        $event_payment_id = (int)$pdo->lastInsertId();

                        $update_amounts = $pdo->prepare("
                                UPDATE event_inquiries
                                SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                                    total_with_vat = ?, last_payment_date = CURDATE(), payment_status = 'full_paid'
                                WHERE id = ?
                            ");
                        $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $inquiry_id]);
                        $pdo->commit();

                        if ($event_payment_id > 0) {
                            try {
                                require_once '../config/receipts.php';
                                receipt_auto_send($pdo, $event_payment_id, $user);
                            } catch (Throwable $rcptEx) {
                                error_log('Receipt email failed for event payment ' . $event_payment_id . ': ' . $rcptEx->getMessage());
                            }
                        }

                        $invoice_result = sendEventInvoiceEmail($inquiry_id);
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
                    error_log("Event payment error: " . $e->getMessage());
                }
            } elseif ($action === 'send_quotation') {
                $quoteValidDays = max(1, (int)($_POST['quotation_valid_days'] ?? 7));
                $quoteNotes = trim((string)($_POST['quotation_notes'] ?? ''));
                $sendWhatsapp = isset($_POST['send_whatsapp']);

                $quoteResult = sendEventInquiryQuotationEmail($inquiry, [
                    'valid_days' => $quoteValidDays,
                    'quotation_notes' => $quoteNotes,
                    'attach_pdf' => true,
                    'send_whatsapp' => $sendWhatsapp,
                ]);

                if (!empty($quoteResult['success'])) {
                    $message = 'Event booking quotation sent to ' . htmlspecialchars((string)($inquiry['email'] ?? '')) . '.';
                    if (!empty($quoteResult['whatsapp']['success'])) {
                        $message .= ' WhatsApp delivered.';
                    }
                } else {
                    $error = 'Failed to send quotation: ' . ($quoteResult['message'] ?? 'Unknown error');
                }
            } elseif ($action === 'update_amount') {
                $amount = $_POST['total_amount'] ?? 0;
                $stmt = $pdo->prepare("UPDATE event_inquiries SET total_amount = ? WHERE id = ?");
                $stmt->execute([$amount, $inquiry_id]);
                $message = 'Total amount updated successfully!';
            } elseif ($action === 'update_notes') {
                $notes = $_POST['notes'] ?? '';
                $stmt = $pdo->prepare("UPDATE event_inquiries SET notes = ? WHERE id = ?");
                $stmt->execute([$notes, $inquiry_id]);
                $message = 'Notes updated successfully!';
            }
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event inquiry action error: ' . $e->getMessage());
        $error = 'An unexpected error occurred. The status may have been updated but the notification email could not be sent.';
    }
}

// Fetch event bookings with search/filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $sql = "SELECT ei.*, e.title AS event_title, e.event_date AS event_date
            FROM event_inquiries ei
            LEFT JOIN events e ON e.id = ei.event_id
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (ei.name LIKE ? OR ei.email LIKE ? OR ei.phone LIKE ? OR ei.reference_number LIKE ?)";
        $search_term = '%' . $search . '%';
        $params = array_fill(0, 4, $search_term);
    }

    if (!empty($status_filter)) {
        $sql .= " AND ei.status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY ei.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $event_inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $event_inquiries = [];
    $error = 'Error fetching event bookings: ' . $e->getMessage();
}

// Get status counts for filter tabs
try {
    $status_counts = [];
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM event_inquiries GROUP BY status");
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
    <title>Event Bookings - Admin Panel</title>

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
            <h2 class="section-title"><i class="fas fa-calendar-check"></i> Event Bookings Management</h2>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="events-inquiries.php" class="filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                All <?php if (!empty($status_counts)) { ?><span class="count"><?php echo array_sum($status_counts); ?></span><?php } ?>
            </a>
            <a href="events-inquiries.php?status=pending" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                Pending <?php if (isset($status_counts['pending'])) { ?><span class="count"><?php echo $status_counts['pending']; ?></span><?php } ?>
            </a>
            <a href="events-inquiries.php?status=confirmed" class="filter-tab <?php echo $status_filter === 'confirmed' ? 'active' : ''; ?>">
                Confirmed <?php if (isset($status_counts['confirmed'])) { ?><span class="count"><?php echo $status_counts['confirmed']; ?></span><?php } ?>
            </a>
            <a href="events-inquiries.php?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                Completed <?php if (isset($status_counts['completed'])) { ?><span class="count"><?php echo $status_counts['completed']; ?></span><?php } ?>
            </a>
            <a href="events-inquiries.php?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
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
                <a href="events-inquiries.php" class="btn btn-danger"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>

        <!-- Bookings Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Event</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($event_inquiries)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No event bookings found</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($event_inquiries as $inquiry): ?>
                    <tr id="inquiry-<?php echo (int)$inquiry['id']; ?>" data-focus="inquiry-<?php echo (int)$inquiry['id']; ?>">
                        <td><strong><?php echo htmlspecialchars($inquiry['reference_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($inquiry['name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($inquiry['email']); ?><br>
                            <small><?php echo htmlspecialchars($inquiry['phone']); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($inquiry['event_title'] ?? 'N/A'); ?>
                            <?php if (!empty($inquiry['event_date'])): ?>
                                <br><small><?php echo date('M j, Y', strtotime($inquiry['event_date'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)($inquiry['guests'] ?? 1); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="inquiry_action" value="update_status">
                                <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                <select name="new_status" class="status-select" onchange="this.form.submit();">
                                    <option value="pending" <?php echo $inquiry['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $inquiry['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $inquiry['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $inquiry['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
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
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="inquiry_action" value="delete">
                                    <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this booking?');">
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

    <!-- Booking Details Modal -->
    <div id="inquiryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Event Booking Details</h3>
                <span class="close" onclick="closeInquiryModal()">&times;</span>
            </div>
            <div class="modal-body" id="inquiryModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        var canEventsFinancials = <?php echo json_encode($_can_events_financials); ?>;
        var eventCsrfToken = <?php echo json_encode($csrf_token); ?>;
        var eventCurrencySymbol = <?php echo json_encode($currency_symbol); ?>;

        function showInquiryDetails(inquiry) {
            const modal = document.getElementById('inquiryModal');
            const body = document.getElementById('inquiryModalBody');

            const statusColors = {
                'pending': '#17a2b8',
                'confirmed': '#8B7355',
                'completed': '#6c757d',
                'cancelled': '#dc3545'
            };

            function money(n) {
                return eventCurrencySymbol + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
                ${canEventsFinancials ? `
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="inquiry_action" value="update_amount">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <label style="margin:0;">Set Total Amount:</label>
                        <input type="number" step="0.01" min="0" name="total_amount" value="${totalAmount}" class="form-control" style="max-width:160px;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Amount</button>
                    </form>
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" name="inquiry_action" value="send_invoice">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm" ${totalAmount > 0 ? '' : 'disabled title="Set a total amount first"'}><i class="fas fa-file-invoice-dollar"></i> Record Payment &amp; Send Invoice</button>
                    </form>
                    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                        <input type="hidden" name="inquiry_action" value="send_quotation">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <input type="hidden" name="send_whatsapp" value="1">
                        <input type="number" min="1" name="quotation_valid_days" value="7" class="form-control" style="max-width:100px;" title="Valid for (days)">
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-file-invoice"></i> Send Quotation</button>
                    </form>
                </div>
                ` : ''}
                <div class="detail-item" style="grid-column: 1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
                    ${inquiry.status === 'pending' ? `
                    <form method="POST" onsubmit="return confirm('Confirm this event booking?');">
                        <input type="hidden" name="inquiry_action" value="confirm">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Confirm</button>
                    </form>` : ''}
                    ${inquiry.status === 'confirmed' ? `
                    <form method="POST" onsubmit="return confirm('Mark this booking as completed?');">
                        <input type="hidden" name="inquiry_action" value="complete">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-flag-checkered"></i> Mark Completed</button>
                    </form>` : ''}
                    ${(inquiry.status !== 'cancelled') ? `
                    <form method="POST" onsubmit="return confirm('Cancel this booking? Any recorded payment will be refunded.');">
                        <input type="hidden" name="inquiry_action" value="cancel">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Cancel &amp; Refund</button>
                    </form>` : ''}
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:flex-start;">
                        <input type="hidden" name="inquiry_action" value="update_notes">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <textarea name="notes" class="form-control" rows="2" placeholder="Internal notes...">${escapeHtml(inquiry.notes || '')}</textarea>
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save"></i> Save Notes</button>
                    </form>
                </div>
            `;

            body.innerHTML = `
                <div class="inquiry-details">
                    <div class="detail-item">
                        <label>Reference Number</label>
                        <span><strong>${inquiry.reference_number}</strong></span>
                    </div>
                    <div class="detail-item">
                        <label>Full Name</label>
                        <span>${escapeHtml(inquiry.name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Email</label>
                        <span><a href="mailto:${inquiry.email}">${escapeHtml(inquiry.email)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Phone</label>
                        <span><a href="tel:${inquiry.phone}">${escapeHtml(inquiry.phone)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Event</label>
                        <span>${escapeHtml(inquiry.event_title || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Event Date</label>
                        <span>${inquiry.event_date ? new Date(inquiry.event_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Number of Attendees</label>
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
                        <label>Message</label>
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

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('inquiryModal');
            if (event.target === modal) {
                closeInquiryModal();
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeInquiryModal();
            }
        });
    </script>
    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>
