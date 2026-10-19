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
$currency_symbol = (string)getSetting('currency_symbol', 'MWK');

// Receivable-account payment sync (syncEventInquiryPaymentSnapshot) lives in the
// shared include so payment-add.php and this page compute identical balances.
// amount_due is derived from the LOCKED invoiced gross (total_with_vat) — rate-safe.
require_once __DIR__ . '/includes/finance-account-sync.php';
require_once __DIR__ . '/../includes/event-capacity.php';
require_once __DIR__ . '/includes/account-record-payment.php';

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
            // Quick status switch for open bookings only. Cancel (money handling) and Promote
            // (seat check) have their own actions so they cannot be bypassed here.
            $new_status = (string)($_POST['new_status'] ?? '');
            $curSt = $pdo->prepare('SELECT status FROM event_inquiries WHERE id = ?');
            $curSt->execute([(int)$inquiry_id]);
            $curStatus = (string)$curSt->fetchColumn();
            if (!in_array($new_status, ['pending', 'confirmed', 'completed'], true)) {
                throw new Exception('Use the Cancel button in the booking details to cancel (it handles any payment).');
            }
            if ($curStatus === 'waitlisted') {
                throw new Exception('Use "Promote from waitlist" in the booking details so the seat count is checked.');
            }
            if ($curStatus === 'cancelled') {
                throw new Exception('Cancelled bookings cannot be reopened. Ask the guest to book again.');
            }
            if ($curStatus === '') {
                throw new Exception('Event booking not found!');
            }
            if (in_array($new_status, ['confirmed', 'completed'], true) && $new_status !== $curStatus) {
                // Same deposit rule as the Confirm / Complete buttons - the dropdown must not bypass it.
                $dSnap = syncEventInquiryPaymentSnapshot($pdo, (int)$inquiry_id);
                $dReq = (float)($dSnap['deposit_required'] ?? 0);
                $dPaid = (float)($dSnap['deposit_paid'] ?? 0);
                if ($dReq > 0 && $dPaid + BALANCE_TOLERANCE < $dReq) {
                    throw new Exception('Required event deposit has not been fully paid yet.');
                }
            }
            $stmt = $pdo->prepare("UPDATE event_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $inquiry_id]);
            $message = 'Event booking status updated successfully!';
        } elseif ($action === 'delete') {
            // Money trail is never deleted: any payment row (even a removed one - it is still history) or any
            // credit note blocks deletion - cancel the booking instead. Checked under a row lock so a payment
            // recorded at the same moment cannot slip in between the check and the delete.
            $inquiry_id = (int)$inquiry_id;
            $pdo->beginTransaction();
            try {
                $delLock = $pdo->prepare("SELECT id FROM event_inquiries WHERE id = ? FOR UPDATE");
                $delLock->execute([$inquiry_id]);
                if (!$delLock->fetchColumn()) {
                    throw new Exception('Event booking not found!');
                }
                $payChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'event' AND booking_id = ?");
                $payChk->execute([$inquiry_id]);
                if ((int)$payChk->fetchColumn() > 0) {
                    throw new Exception('This booking has payments recorded against it and cannot be deleted. Cancel it instead.');
                }
                $cnChk = $pdo->prepare("SELECT COUNT(*) FROM credit_notes WHERE booking_type = 'event' AND booking_id = ?");
                $cnChk->execute([$inquiry_id]);
                if ((int)$cnChk->fetchColumn() > 0) {
                    throw new Exception('This booking has a credit note recorded against it and cannot be deleted. Cancel it instead.');
                }
                $pdo->prepare("DELETE FROM event_inquiries WHERE id = ?")->execute([$inquiry_id]);
                $pdo->commit();
            } catch (Throwable $delEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $delEx;
            }
            $message = 'Event booking deleted successfully!';
        } elseif (in_array($action, ['confirm', 'promote', 'cancel', 'complete', 'record_payment', 'resend_invoice', 'send_quotation', 'update_amount', 'update_notes'], true)) {
            $inquiry_id = (int)$inquiry_id;
            if ($inquiry_id <= 0) {
                throw new Exception('Invalid booking selected.');
            }

            if (in_array($action, ['record_payment', 'resend_invoice', 'send_quotation', 'update_amount'], true) && !hasPermission((int)($user['id'] ?? 0), 'events_financials')) {
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
                if ($depositRequired > 0 && $depositPaid + BALANCE_TOLERANCE < $depositRequired) {
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
            } elseif ($action === 'promote') {
                if (($inquiry['status'] ?? '') !== 'waitlisted') {
                    throw new Exception('Only waitlisted bookings can be promoted.');
                }
                $pdo->beginTransaction();
                try {
                    $capLock = $pdo->prepare('SELECT capacity FROM events WHERE id = ? FOR UPDATE');
                    $capLock->execute([(int)$inquiry['event_id']]);
                    $capacity = max(0, (int)$capLock->fetchColumn());
                    $need = max(1, (int)($inquiry['guests'] ?? 1));
                    $taken = rh_event_seats_taken($pdo, (int)$inquiry['event_id'], $inquiry_id);
                    if ($capacity > 0 && $taken + $need > $capacity) {
                        throw new Exception('Not enough seats: ' . max(0, $capacity - $taken) . ' left, this booking needs ' . $need . '. Raise the event capacity or cancel another booking first.');
                    }
                    $pdo->prepare("UPDATE event_inquiries SET status = 'pending', updated_at = NOW() WHERE id = ? AND status = 'waitlisted'")->execute([$inquiry_id]);
                    $pdo->commit();
                } catch (Throwable $promoteEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $promoteEx;
                }
                $email_result = sendEventWaitlistPromotedEmail($inquiry);
                $message = 'Booking moved off the waitlist (now pending).'
                    . ($email_result['success'] ? ' The guest has been emailed.' : ' (Email not sent: ' . htmlspecialchars($email_result['message']) . ')');
            } elseif ($action === 'cancel') {
                if ((string)($inquiry['status'] ?? '') === 'cancelled') {
                    throw new Exception('This booking is already cancelled.');
                }
                // Cancelling voids the bill (amount due 0). No automatic refund: whatever was paid stays
                // on the account as credit owed back, and staff refund it deliberately from the payment's
                // Refund screen (or issue a credit note). Status + resync commit together or not at all.
                require_once __DIR__ . '/includes/finance-account-sync.php';
                $pdo->beginTransaction();
                try {
                    $cancelLock = $pdo->prepare("SELECT status FROM event_inquiries WHERE id = ? FOR UPDATE");
                    $cancelLock->execute([$inquiry_id]);
                    if ((string)$cancelLock->fetchColumn() === 'cancelled') {
                        throw new Exception('This booking is already cancelled.');
                    }
                    $pdo->prepare("UPDATE event_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$inquiry_id]);
                    if (!rh_sync_account_payments($pdo, 'event', (int)$inquiry_id)) {
                        throw new Exception('Could not recalculate the account balance.');
                    }
                    // Refund settings: 'refund_all' pays the net paid back inside this same transaction.
                    rh_cancel_auto_refund_account($pdo, 'event', (int)$inquiry_id, (int)($user['id'] ?? 0));
                    $pdo->commit();
                } catch (Throwable $cancelEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $cancelEx;
                }
                $cancelCredit = rh_account_credit_owed($pdo, 'event', (int)$inquiry_id);
                $cancelCreditNote = $cancelCredit > BALANCE_TOLERANCE
                    ? ' ' . getSetting('currency_symbol') . number_format($cancelCredit, 2) . ' already paid stays as credit owed back - refund it from the payment Refund screen.'
                    : '';
                $waitInfo = rh_event_capacity_info($pdo, (int)$inquiry['event_id']);
                if ($waitInfo['waitlisted'] > 0) {
                    $cancelCreditNote .= ' ' . $waitInfo['waitlisted'] . ' booking(s) are on the waitlist for this event - promote one from the Waitlisted tab.';
                }

                $email_result = sendEventCancelledEmail($inquiry);
                $message = $email_result['success']
                    ? 'Event booking cancelled successfully! Cancellation email sent.' . $cancelCreditNote
                    : 'Event booking cancelled successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')' . $cancelCreditNote;
            } elseif ($action === 'complete') {
                if (($inquiry['status'] ?? '') !== 'confirmed') {
                    throw new Exception('Only confirmed bookings can be marked completed.');
                }

                $stmt = $pdo->prepare("UPDATE event_inquiries SET status = 'completed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);
                $message = 'Event booking marked as completed!';
            } elseif ($action === 'record_payment') {
                // Staff choose the method and the amount (default: the balance). The helper re-reads the paid
                // total under a row lock, so a double click can never collect the balance twice.
                $payRes = rh_record_account_payment($pdo, 'event', $inquiry_id, rh_account_payment_input_from_post($_POST), (int)($user['id'] ?? 0), (string)($user['full_name'] ?? $user['username'] ?? ''));
                $message = rh_account_after_payment_messages($pdo, $payRes, !empty($_POST['email_invoice']), 'sendEventInvoiceEmail', $inquiry_id, $user, $currency_symbol . ' ');
            } elseif ($action === 'resend_invoice') {
                if ((float)($paymentSnapshot['amount_paid'] ?? 0) <= BALANCE_TOLERANCE) {
                    throw new Exception('No payment has been recorded yet, so there is no invoice to send.');
                }
                if (!filter_var((string)($inquiry['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('This booking has no valid email address on file.');
                }
                $invoice_result = sendEventInvoiceEmail($inquiry_id);
                if (!empty($invoice_result['success'])) {
                    $message = stripos((string)($invoice_result['message'] ?? ''), 'preview') !== false
                        ? 'Invoice generated (development mode: preview only, no live email sent).'
                        : 'Invoice emailed to ' . htmlspecialchars((string)$inquiry['email']) . '.';
                } else {
                    $error = 'The invoice email could not be sent: ' . ($invoice_result['message'] ?? 'unknown error');
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
                $amountRaw = $_POST['total_amount'] ?? '';
                if (!is_numeric($amountRaw) || (float)$amountRaw < 0 || (float)$amountRaw > 99999999) {
                    throw new Exception('Total amount must be a number (0 or more).');
                }
                if ((string)($inquiry['status'] ?? '') === 'cancelled') {
                    throw new Exception('A cancelled booking\'s bill is void; its amount cannot be changed.');
                }
                $amount = round((float)$amountRaw, 2);
                // Re-lock the VAT figures on the new amount (the balance is measured against the locked gross,
                // so changing total_amount alone left amount_due on the old price), then resync from the ledger.
                $newVat = vat_components($amount);
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("UPDATE event_inquiries SET total_amount = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ? WHERE id = ?");
                    $stmt->execute([$amount, $newVat['rate'], $newVat['vat'], $newVat['total'], $inquiry_id]);
                    syncEventInquiryPaymentSnapshot($pdo, $inquiry_id);
                    $pdo->commit();
                } catch (Throwable $amtEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $amtEx;
                }
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

    // The waitlist is first-come-first-served: oldest first so staff promote from the top.
    $sql .= $status_filter === 'waitlisted' ? " ORDER BY ei.created_at ASC, ei.id ASC" : " ORDER BY ei.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $event_inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Figures the Record payment dialog needs (balance = gross billed - net paid; a cancelled bill is void).
    foreach ($event_inquiries as &$rpRow) {
        $rpRow['rp_total'] = rh_account_gross_total($rpRow);
        $rpRow['rp_paid'] = (float)($rpRow['amount_paid'] ?? 0);
        $rpRow['rp_balance'] = in_array($rpRow['status'], ['cancelled', 'waitlisted'], true) ? 0.0 : max(0.0, round($rpRow['rp_total'] - $rpRow['rp_paid'], 2));
    }
    unset($rpRow);

    // For each waitlisted booking, how many people joined the same event's waitlist before it
    // (drives the "promoting out of order" warning).
    $earlierStmt = $pdo->prepare("SELECT COUNT(*) FROM event_inquiries
        WHERE event_id = ? AND status = 'waitlisted' AND id <> ?
          AND (created_at < ? OR (created_at = ? AND id < ?))");
    foreach ($event_inquiries as &$wlRow) {
        $wlRow['earlier_waitlisted'] = 0;
        if (($wlRow['status'] ?? '') === 'waitlisted') {
            $earlierStmt->execute([(int)$wlRow['event_id'], (int)$wlRow['id'], $wlRow['created_at'], $wlRow['created_at'], (int)$wlRow['id']]);
            $wlRow['earlier_waitlisted'] = (int)$earlierStmt->fetchColumn();
        }
    }
    unset($wlRow);
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
            <a href="events-inquiries.php?status=waitlisted" class="filter-tab <?php echo $status_filter === 'waitlisted' ? 'active' : ''; ?>">
                Waitlisted <?php if (isset($status_counts['waitlisted'])) { ?><span class="count"><?php echo $status_counts['waitlisted']; ?></span><?php } ?>
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
        <div class="table-container" data-rh-results>
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
                            <?php if (!in_array($inquiry['status'], ['cancelled', 'waitlisted'], true) && (float)($inquiry['total_amount'] ?? 0) <= BALANCE_TOLERANCE): ?>
                                <br><span class="badge" style="background:#fff3cd;color:#856404;" title="No ticket total is set yet. Open View and enter the total before quoting, confirming or recording payment.">Total not set</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)($inquiry['guests'] ?? 1); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="inquiry_action" value="update_status">
                                <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                <?php if (in_array($inquiry['status'], ['pending', 'confirmed', 'completed'], true)): ?>
                                <select name="new_status" class="status-select" data-prev="<?php echo htmlspecialchars((string)$inquiry['status'], ENT_QUOTES); ?>" onchange="rhEventStatusChange(this)" aria-label="Change status">
                                    <option value="pending" <?php echo $inquiry['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $inquiry['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $inquiry['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                                <?php else: ?>
                                <span class="status-select" style="display:inline-block;"><?php echo htmlspecialchars(ucfirst((string)$inquiry['status'])); ?></span>
                                <?php endif; ?>
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
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-admin-confirm="Delete this booking permanently? This cannot be undone."
                                            data-admin-confirm-title="Delete booking"
                                            data-admin-confirm-ok="Delete"
                                            data-admin-confirm-cancel="Keep"
                                            data-admin-confirm-tone="danger"
                                            data-admin-confirm-icon="fa-trash">
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
                <button type="button" class="modal-close" onclick="closeInquiryModal()" aria-label="Close">&times;</button>
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

        // Status dropdown: confirm through the shared admin dialog, put the old value back on cancel.
        function rhEventStatusChange(sel) {
            var form = sel.form;
            var prev = sel.getAttribute('data-prev') || '';
            var label = sel.options[sel.selectedIndex].text;
            if (!window.AdminConfirm || typeof window.AdminConfirm.request !== 'function') {
                form.submit();
                return;
            }
            window.AdminConfirm.request({
                title: 'Change status', message: 'Change this booking to "' + label + '"?', confirmText: 'Change status',
                cancelText: 'Keep current', tone: 'warning', icon: 'fa-exchange-alt'
            }).then(function (ok) {
                if (ok) { form.submit(); } else { sel.value = prev; }
            });
        }

        function showInquiryDetails(inquiry) {
            window._eventCurrentInquiry = inquiry;
            const modal = document.getElementById('inquiryModal');
            const body = document.getElementById('inquiryModalBody');

            const statusColors = {
                'pending': '#17a2b8',
                'confirmed': '#7E684B',
                'completed': '#6c757d',
                'cancelled': '#dc3545',
                'waitlisted': '#C8A45A'
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
                ${(!(totalAmount > 0) && inquiry.status !== 'cancelled' && inquiry.status !== 'waitlisted') ? `
                <div class="detail-item" style="grid-column: 1 / -1;background:#fff3cd;border:1px solid #ffe08a;border-radius:6px;padding:10px 12px;color:#856404;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>No total amount is set.</strong> Ticket totals are entered by hand: set the total below before sending a quotation or recording a payment.
                </div>` : ''}
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
                <div class="detail-item" style="grid-column: 1 / -1;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary btn-sm" ${(totalAmount > 0 && inquiry.status !== 'cancelled' && inquiry.status !== 'waitlisted') ? '' : 'disabled title="Set a total amount first (not for cancelled or waitlisted bookings)"'}
                        onclick="closeInquiryModal();rhOpenRecordPayment({id: ${Number(inquiry.id)}, ref: window._eventCurrentInquiry.reference_number, name: window._eventCurrentInquiry.name, email: window._eventCurrentInquiry.email, total: window._eventCurrentInquiry.rp_total, paid: window._eventCurrentInquiry.rp_paid, balance: window._eventCurrentInquiry.rp_balance})">
                        <i class="fas fa-cash-register"></i> Record payment
                    </button>
                    ${(Number(inquiry.rp_paid) > 0 && inquiry.email) ? `
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="inquiry_action" value="resend_invoice">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm"
                            data-admin-confirm="Email the invoice to ${escapeHtml(inquiry.email)} now?"
                            data-admin-confirm-title="Send invoice"
                            data-admin-confirm-ok="Send invoice"
                            data-admin-confirm-tone="warning"
                            data-admin-confirm-icon="fa-file-invoice-dollar"><i class="fas fa-file-invoice-dollar"></i> Send invoice</button>
                    </form>` : ''}
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
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
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="confirm">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm" data-admin-confirm="Confirm this event booking? The guest is emailed." data-admin-confirm-title="Confirm booking" data-admin-confirm-ok="Confirm" data-admin-confirm-tone="success" data-admin-confirm-icon="fa-check"><i class="fas fa-check"></i> Confirm</button>
                    </form>` : ''}
                    ${inquiry.status === 'waitlisted' ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="promote">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm" data-admin-confirm="${(Number(inquiry.earlier_waitlisted) || 0) > 0 ? 'WARNING: ' + (Number(inquiry.earlier_waitlisted) || 0) + ' guest(s) joined this waitlist before this booking and are still waiting. Promoting this one skips them. ' : ''}Move this booking off the waitlist? The guest will be emailed that a place is available." data-admin-confirm-title="Promote from waitlist" data-admin-confirm-ok="Promote" data-admin-confirm-tone="warning" data-admin-confirm-icon="fa-arrow-up"><i class="fas fa-arrow-up"></i> Promote from waitlist</button>
                    </form>` : ''}
                    ${inquiry.status === 'confirmed' ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="complete">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm" data-admin-confirm="Mark this booking as completed?" data-admin-confirm-title="Complete booking" data-admin-confirm-ok="Complete" data-admin-confirm-tone="warning" data-admin-confirm-icon="fa-flag-checkered"><i class="fas fa-flag-checkered"></i> Mark Completed</button>
                    </form>` : ''}
                    ${(inquiry.status !== 'cancelled') ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="cancel">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${eventCsrfToken}">
                        <button type="submit" class="btn btn-danger btn-sm" data-admin-confirm="Cancel this booking? The bill is voided; any payment already recorded stays as credit owed back to the guest (refund it from the Payments screen)." data-admin-confirm-title="Cancel booking" data-admin-confirm-ok="Cancel booking" data-admin-confirm-cancel="Keep" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-ban"><i class="fas fa-ban"></i> Cancel Booking</button>
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

    <?php rh_render_record_payment_modal((string)$csrf_token, 'inquiry_action', 'inquiry_id', $currency_symbol); ?>

    <?php require_once 'includes/admin-footer.php'; ?>
