<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once '../includes/alert.php';
require_once '../includes/finance-sequences.php';
require_once __DIR__ . '/includes/account-record-payment.php';
require_once __DIR__ . '/includes/account-receipt-send.php';
require_once __DIR__ . '/includes/gym-classes-lib.php';
require_once __DIR__ . '/includes/gym-schedule-lib.php';
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
$currency_symbol = (string)getSetting('currency_symbol', 'MWK');

// Receivable-account payment sync (syncGymInquiryPaymentSnapshot) is defined in
// the shared include so payment-add.php and this page compute identical balances.
// Its model derives amount_due from the LOCKED invoiced gross (total_with_vat),
// which is rate-safe — it never rewrites an old-rate invoice to the current rate.
require_once __DIR__ . '/includes/finance-account-sync.php';
require_once __DIR__ . '/includes/gym-billing-lib.php';

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
            if (!$inquiry_row) {
                throw new Exception('Gym inquiry not found!');
            }
            $oldStatus = (string)($inquiry_row['status'] ?? '');
            // Cancel has its own action (it voids the bill and resyncs the balance); a cancelled
            // account stays cancelled - the quick switch must not reopen it or skip the deposit rule.
            if ($new_status === 'cancelled' && $oldStatus !== 'cancelled') {
                throw new Exception('Use the Cancel button in the inquiry details to cancel (it voids the bill and handles any payment).');
            }
            if ($oldStatus === 'cancelled' && $new_status !== 'cancelled') {
                throw new Exception('Cancelled inquiries cannot be reopened. Ask the guest to submit a new one.');
            }
            if ($new_status === $oldStatus) {
                throw new Exception('The inquiry is already ' . $new_status . '.');
            }
            if (in_array($new_status, ['confirmed', 'converted'], true)) {
                $dSnap = syncGymInquiryPaymentSnapshot($pdo, (int)$inquiry_id);
                $dReq = (float)($dSnap['deposit_required'] ?? 0);
                $dPaid = (float)($dSnap['deposit_paid'] ?? 0);
                if ($dReq > 0 && $dPaid + BALANCE_TOLERANCE < $dReq) {
                    throw new Exception('Required gym deposit has not been fully paid yet.');
                }
            }
            // "Converted" means a member exists: send staff through the enrol form (package, fee, expiry and
            // the billable account all come from there) instead of only flipping the label.
            if ($new_status === 'converted') {
                $enrolChk = $pdo->prepare("SELECT member_number FROM gym_members WHERE gym_inquiry_id = ? LIMIT 1");
                $enrolChk->execute([(int)$inquiry_id]);
                if (!$enrolChk->fetchColumn()) {
                    header('Location: gym-members.php?enrol_name=' . urlencode((string)$inquiry_row['name'])
                        . '&enrol_email=' . urlencode((string)($inquiry_row['email'] ?? ''))
                        . '&enrol_phone=' . urlencode((string)($inquiry_row['phone'] ?? ''))
                        . '&enrol_type=' . urlencode((string)($inquiry_row['membership_type'] ?? ''))
                        . '&enrol_inquiry_id=' . (int)$inquiry_id);
                    exit;
                }
            }

            $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $inquiry_id]);

            // Send status-change emails
            if ($inquiry_row && !empty($inquiry_row['email'])) {
                if ($new_status === 'contacted') {
                    $email_result = sendGymInquiryContactedEmail($inquiry_row);
                    $email_note = $email_result['success']
                        ? ' Notification email sent to ' . htmlspecialchars($inquiry_row['email']) . '.'
                        : ' (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
                    $message = 'Inquiry marked as Contacted.' . $email_note;
                } elseif ($new_status === 'confirmed') {
                    // Same confirmation email the Confirm button sends.
                    $email_result = sendGymConfirmedEmail($inquiry_row);
                    $message = $email_result['success']
                        ? 'Inquiry confirmed. Confirmation email sent to ' . htmlspecialchars($inquiry_row['email']) . '.'
                        : 'Inquiry confirmed. (Email not sent: ' . htmlspecialchars($email_result['message']) . ')';
                } elseif ($new_status === 'converted') {
                    // Only reached when the member is already enrolled (the enrol form sent its own card email).
                    $message = 'Inquiry marked converted (member already enrolled).';
                } else {
                    $message = 'Inquiry status updated successfully!';
                }
            } else {
                $message = 'Inquiry status updated successfully!';
            }
        } elseif ($action === 'delete') {
            // Money trail is never deleted: any payment row (even a removed one - it is still history) or any
            // credit note blocks deletion; a member created from this enquiry blocks it too. Checked under a row
            // lock so a payment recorded at the same moment cannot slip in between the check and the delete.
            $inquiry_id = (int)$inquiry_id;
            $pdo->beginTransaction();
            try {
                $delLock = $pdo->prepare("SELECT id FROM gym_inquiries WHERE id = ? FOR UPDATE");
                $delLock->execute([$inquiry_id]);
                if (!$delLock->fetchColumn()) {
                    throw new Exception('Gym inquiry not found!');
                }
                $payChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_type = 'gym' AND booking_id = ?");
                $payChk->execute([$inquiry_id]);
                if ((int)$payChk->fetchColumn() > 0) {
                    throw new Exception('This inquiry has payments recorded against it and cannot be deleted. Cancel it instead.');
                }
                $cnChk = $pdo->prepare("SELECT COUNT(*) FROM credit_notes WHERE booking_type = 'gym' AND booking_id = ?");
                $cnChk->execute([$inquiry_id]);
                if ((int)$cnChk->fetchColumn() > 0) {
                    throw new Exception('This inquiry has a credit note recorded against it and cannot be deleted. Cancel it instead.');
                }
                $memChk = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE gym_inquiry_id = ?");
                $memChk->execute([$inquiry_id]);
                if ((int)$memChk->fetchColumn() > 0) {
                    throw new Exception('A member was created from this enquiry — cancel the member first.');
                }
                $pdo->prepare("DELETE FROM gym_inquiries WHERE id = ?")->execute([$inquiry_id]);
                $pdo->commit();
            } catch (Throwable $delEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $delEx;
            }
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

            $site_name = (string)getSetting('site_name', 'Hotel');
            $memberName = htmlspecialchars((string)($inquiry_row['name'] ?? 'Member'), ENT_QUOTES, 'UTF-8');
            // Preserve the admin's line breaks; escape everything to prevent HTML injection.
            $safeBody = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
            $htmlBody = '
                <h1 style="color:#7E684B;text-align:center;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>
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
        } elseif (in_array($action, ['confirm', 'cancel', 'complete', 'record_payment', 'resend_invoice', 'send_quotation', 'update_amount', 'update_notes'], true)) {
            $inquiry_id = (int)$inquiry_id;
            if ($inquiry_id <= 0) {
                throw new Exception('Invalid inquiry selected.');
            }

            if (in_array($action, ['record_payment', 'resend_invoice', 'send_quotation', 'update_amount'], true) && !hasPermission((int)($user['id'] ?? 0), 'gym_financials')) {
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
                if ($depositRequired > 0 && $depositPaid + BALANCE_TOLERANCE < $depositRequired) {
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
                if ((string)($inquiry['status'] ?? '') === 'cancelled') {
                    throw new Exception('This booking is already cancelled.');
                }
                // Cancelling voids the bill (amount due 0). No automatic refund: whatever was paid stays
                // on the account as credit owed back, and staff refund it deliberately from the payment's
                // Refund screen (or issue a credit note). Status + resync commit together or not at all.
                require_once __DIR__ . '/includes/finance-account-sync.php';
                // Lazily created gym tables are made before the transaction (CREATE TABLE commits implicitly).
                gymClassesEnsureTables($pdo);
                gymScheduleEnsureTables($pdo);
                $pdo->beginTransaction();
                try {
                    $cancelLock = $pdo->prepare("SELECT status FROM gym_inquiries WHERE id = ? FOR UPDATE");
                    $cancelLock->execute([$inquiry_id]);
                    if ((string)$cancelLock->fetchColumn() === 'cancelled') {
                        throw new Exception('This booking is already cancelled.');
                    }
                    $pdo->prepare("UPDATE gym_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$inquiry_id]);
                    // The member created from this enquiry stops being a live member, and their future
                    // floor-slot bookings and class places are released (the bill/payment handling below is unchanged).
                    $linkedMembers = $pdo->prepare("SELECT id, member_number FROM gym_members WHERE gym_inquiry_id = ? AND status IN ('active','suspended') FOR UPDATE");
                    $linkedMembers->execute([$inquiry_id]);
                    $cancelledMemberIds = [];
                    $renewalVoided = 0.0;
                    foreach ($linkedMembers->fetchAll(PDO::FETCH_ASSOC) as $linkedMember) {
                        $linkedMemberId = (int)$linkedMember['id'];
                        $pdo->prepare("UPDATE gym_members SET status = 'cancelled' WHERE id = ?")->execute([$linkedMemberId]);
                        gym_member_release_future($pdo, $linkedMemberId);
                        // This enquiry is already cancelled above (its paid money is credit owed back as usual). The member's
                        // OTHER live bills (desk renewals tied by the [member:NUMBER] marker) lose their unpaid part too.
                        $renewalVoided += rh_gym_void_unpaid_for_member($pdo, $linkedMemberId, (string)$linkedMember['member_number'], null,
                            (string)($user['full_name'] ?? $user['username'] ?? ('user #' . (int)($user['id'] ?? 0))))['cleared'];
                        $cancelledMemberIds[] = $linkedMemberId;
                    }
                    if (!rh_sync_account_payments($pdo, 'gym', (int)$inquiry_id)) {
                        throw new Exception('Could not recalculate the account balance.');
                    }
                    // Refund settings: 'refund_all' pays the net paid back inside this same transaction.
                    rh_cancel_auto_refund_account($pdo, 'gym', (int)$inquiry_id, (int)($user['id'] ?? 0));
                    $pdo->commit();
                } catch (Throwable $cancelEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $cancelEx;
                }
                if (function_exists('logGymMemberAudit')) {
                    foreach ($cancelledMemberIds as $cmId) {
                        logGymMemberAudit($cmId, 'status_changed', ['status' => 'active/suspended'], ['status' => 'cancelled'], 'Cancelled with gym enquiry ' . (string)($inquiry['reference_number'] ?? ('#' . $inquiry_id)));
                    }
                }
                $cancelCredit = rh_account_credit_owed($pdo, 'gym', (int)$inquiry_id);
                $cancelCreditNote = $cancelCredit > BALANCE_TOLERANCE
                    ? ' ' . getSetting('currency_symbol') . number_format($cancelCredit, 2) . ' already paid stays as credit owed back - refund it from the payment Refund screen.'
                    : '';
                if ($renewalVoided > BALANCE_TOLERANCE) {
                    $cancelCreditNote .= ' Unpaid renewal bill of ' . getSetting('currency_symbol') . number_format($renewalVoided, 2) . ' for the cancelled member was also cleared.';
                }

                $email_result = sendGymCancelledEmail($inquiry);
                $message = $email_result['success']
                    ? 'Gym membership cancelled successfully! Cancellation email sent.' . $cancelCreditNote
                    : 'Gym membership cancelled successfully! (Email not sent: ' . htmlspecialchars($email_result['message']) . ')' . $cancelCreditNote;
            } elseif ($action === 'complete') {
                if (($inquiry['status'] ?? '') !== 'confirmed') {
                    throw new Exception('Only confirmed memberships can be marked completed.');
                }

                $stmt = $pdo->prepare("UPDATE gym_inquiries SET status = 'closed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$inquiry_id]);
                $message = 'Gym membership marked as completed!';
            } elseif ($action === 'record_payment') {
                // Staff choose the method and the amount (default: the balance). The helper re-reads the paid
                // total under a row lock, so a double click can never collect the balance twice.
                $payRes = rh_record_account_payment($pdo, 'gym', $inquiry_id, rh_account_payment_input_from_post($_POST), (int)($user['id'] ?? 0), (string)($user['full_name'] ?? $user['username'] ?? ''));
                $message = rh_account_after_payment_messages($pdo, $payRes, !empty($_POST['email_invoice']), 'sendGymInvoiceEmail', $inquiry_id, $user, $currency_symbol . ' ');
            } elseif ($action === 'resend_invoice') {
                if ((float)($paymentSnapshot['amount_paid'] ?? 0) <= BALANCE_TOLERANCE) {
                    throw new Exception('No payment has been recorded yet, so there is no invoice to send.');
                }
                if (!filter_var((string)($inquiry['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('This inquiry has no valid email address on file.');
                }
                $invoice_result = sendGymInvoiceEmail($inquiry_id);
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
                    $stmt = $pdo->prepare("UPDATE gym_inquiries SET total_amount = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ? WHERE id = ?");
                    $stmt->execute([$amount, $newVat['rate'], $newVat['vat'], $newVat['total'], $inquiry_id]);
                    syncGymInquiryPaymentSnapshot($pdo, $inquiry_id);
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

    // Enrolled inquiries (have a member record) and the figures the Record payment dialog needs.
    $enrolledInquiryIds = [];
    try {
        foreach ($pdo->query("SELECT gym_inquiry_id FROM gym_members WHERE gym_inquiry_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $gid) {
            $enrolledInquiryIds[(int)$gid] = true;
        }
    } catch (PDOException $e) { /* gym_members migration not run yet */ }
    foreach ($gym_inquiries as &$giRow) {
        $giRow['rp_total'] = rh_account_gross_total($giRow);
        $giRow['rp_paid'] = (float)($giRow['amount_paid'] ?? 0);
        $giRow['rp_balance'] = ($giRow['status'] === 'cancelled') ? 0.0 : max(0.0, round($giRow['rp_total'] - $giRow['rp_paid'], 2));
        // A desk enrolment/renewal account (notes carry a [member:...] marker) already belongs to a member.
        $giRow['enrolled'] = (isset($enrolledInquiryIds[(int)$giRow['id']]) || strpos((string)($giRow['notes'] ?? ''), '[member:') !== false) ? 1 : 0;
    }
    unset($giRow);
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
        <div class="table-container" data-rh-results>
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
                        <td>
                            <?php echo htmlspecialchars($inquiry['membership_type'] ?? 'N/A'); ?>
                            <?php if ($inquiry['status'] !== 'cancelled' && (float)($inquiry['total_amount'] ?? 0) <= BALANCE_TOLERANCE): ?>
                                <br><span class="badge" style="background:#fff3cd;color:#856404;" title="No price is set yet. Open View and enter the total before quoting, confirming or recording payment.">Total not set</span>
                            <?php endif; ?>
                        </td>
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
                                    <select name="new_status" class="status-select" data-prev="<?php echo htmlspecialchars($inquiry['status'], ENT_QUOTES); ?>" onchange="rhGymStatusChange(this)" aria-label="Change status">
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
                                <button type="button" class="btn btn-sm" style="background:#7E684B;color:#fff;"
                                        title="Email this member directly"
                                        onclick='openEmailComposer(<?php echo htmlspecialchars(json_encode(['id' => (int)$inquiry['id'], 'name' => (string)$inquiry['name'], 'email' => (string)$inquiry['email'], 'reference_number' => (string)($inquiry['reference_number'] ?? '')]), ENT_QUOTES); ?>)'>
                                    <i class="fas fa-envelope"></i> Email
                                </button>
                                <?php endif; ?>
                                <?php if ($inquiry['status'] !== 'cancelled' && empty($inquiry['enrolled'])): ?>
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
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-admin-confirm="Delete this inquiry permanently? This cannot be undone."
                                            data-admin-confirm-title="Delete inquiry"
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

    <!-- Inquiry Details Modal -->
    <div id="inquiryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Gym Inquiry Details</h3>
                <button type="button" class="modal-close" onclick="closeInquiryModal()" aria-label="Close">&times;</button>
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
                <h3><i class="fas fa-envelope"></i> Email Member</h3>
                <button type="button" class="modal-close" onclick="closeEmailComposer()" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="emailComposerForm">
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEmailComposer()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="composer-send-btn"><i class="fas fa-paper-plane"></i> Send Email</button>
                </div>
            </form>
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

        // Status dropdown: confirm through the shared admin dialog, put the old value back on cancel.
        function rhGymStatusChange(sel) {
            var form = sel.form;
            var prev = sel.getAttribute('data-prev') || '';
            var label = sel.options[sel.selectedIndex].text;
            var next = sel.value;
            if (!window.AdminConfirm || typeof window.AdminConfirm.request !== 'function') {
                form.submit();
                return;
            }
            var msg = 'Change this inquiry to "' + label + '"?';
            if (next === 'converted') { msg += ' You will be taken to the enrol form to create the member.'; }
            if (next === 'confirmed') { msg += ' The member is emailed a confirmation.'; }
            window.AdminConfirm.request({
                title: 'Change status', message: msg, confirmText: 'Change status', cancelText: 'Keep current',
                tone: 'warning', icon: 'fa-exchange-alt'
            }).then(function (ok) {
                if (ok) { form.submit(); } else { sel.value = prev; }
            });
        }

        function showInquiryDetails(inquiry) {
            const modal = document.getElementById('inquiryModal');
            const body = document.getElementById('inquiryModalBody');
            window._gymCurrentInquiry = inquiry;

            const statusColors = {
                'new': '#17a2b8',
                'contacted': '#ffc107',
                'confirmed': '#7E684B',
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
                ${(!(totalAmount > 0) && inquiry.status !== 'cancelled') ? `
                <div class="detail-item" style="grid-column: 1 / -1;background:#fff3cd;border:1px solid #ffe08a;border-radius:6px;padding:10px 12px;color:#856404;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>No total amount is set.</strong> Prices are entered by hand: set the total below before sending a quotation, confirming or recording a payment.
                </div>` : ''}
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
                <div class="detail-item" style="grid-column: 1 / -1;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary btn-sm" ${(totalAmount > 0 && inquiry.status !== 'cancelled') ? '' : 'disabled title="Set a total amount first"'}
                        onclick="closeInquiryModal();rhOpenRecordPayment({id: ${Number(inquiry.id)}, ref: window._gymCurrentInquiry.reference_number, name: window._gymCurrentInquiry.name, email: window._gymCurrentInquiry.email, total: window._gymCurrentInquiry.rp_total, paid: window._gymCurrentInquiry.rp_paid, balance: window._gymCurrentInquiry.rp_balance})">
                        <i class="fas fa-cash-register"></i> Record payment
                    </button>
                    ${(Number(inquiry.rp_paid) > 0.01) ? `
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeInquiryModal();rhSendAccountReceipt('gym', ${Number(inquiry.id)}, window._gymCurrentInquiry.reference_number)"><i class="fas fa-receipt"></i> Send receipt</button>` : ''}
                    ${(Number(inquiry.rp_balance) > 0.01 && totalAmount > 0 && inquiry.email) ? `
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="inquiry_action" value="resend_invoice">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm"
                            data-admin-confirm="Email the invoice to ${escapeHtml(inquiry.email)} now?"
                            data-admin-confirm-title="Send invoice"
                            data-admin-confirm-ok="Send invoice"
                            data-admin-confirm-tone="warning"
                            data-admin-confirm-icon="fa-file-invoice-dollar"><i class="fas fa-file-invoice-dollar"></i> Send invoice (balance due)</button>
                    </form>` : ''}
                </div>
                ${(Number(inquiry.rp_paid) > 0.01) ? '' : `
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                        <input type="hidden" name="inquiry_action" value="send_quotation">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <input type="hidden" name="send_whatsapp" value="1">
                        <input type="number" min="1" name="quotation_valid_days" value="7" class="form-control" style="max-width:100px;" title="Valid for (days)">
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-file-invoice"></i> Send Quotation</button>
                    </form>
                </div>
                `}
                ` : ''}
                <div class="detail-item" style="grid-column: 1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
                    ${inquiry.status === 'new' ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="confirm">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-primary btn-sm" data-admin-confirm="Confirm this gym membership? The member is emailed." data-admin-confirm-title="Confirm membership" data-admin-confirm-ok="Confirm" data-admin-confirm-tone="success" data-admin-confirm-icon="fa-check"><i class="fas fa-check"></i> Confirm</button>
                    </form>` : ''}
                    ${inquiry.status === 'confirmed' ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="complete">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-secondary btn-sm" data-admin-confirm="Mark this membership as completed?" data-admin-confirm-title="Complete membership" data-admin-confirm-ok="Complete" data-admin-confirm-tone="warning" data-admin-confirm-icon="fa-flag-checkered"><i class="fas fa-flag-checkered"></i> Mark Completed</button>
                    </form>` : ''}
                    ${(inquiry.status !== 'cancelled') ? `
                    <form method="POST">
                        <input type="hidden" name="inquiry_action" value="cancel">
                        <input type="hidden" name="inquiry_id" value="${inquiry.id}">
                        <input type="hidden" name="csrf_token" value="${gymCsrfToken}">
                        <button type="submit" class="btn btn-danger btn-sm" data-admin-confirm="Cancel this membership? The bill is voided; any payment already recorded stays as credit owed back (refund it from the Payments screen)." data-admin-confirm-title="Cancel membership" data-admin-confirm-ok="Cancel membership" data-admin-confirm-cancel="Keep" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-ban"><i class="fas fa-ban"></i> Cancel Membership</button>
                    </form>` : ''}
                    ${inquiry.email ? `
                    <button type="button" class="btn btn-sm" style="background:#7E684B;color:#fff;" onclick="openEmailComposer(window._gymCurrentInquiry)">
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

    <?php rh_render_record_payment_modal((string)$csrf_token, 'inquiry_action', 'inquiry_id', $currency_symbol); ?>
    <?php rh_render_send_receipt_script((string)$csrf_token); ?>

    <?php require_once 'includes/admin-footer.php'; ?>

