<?php

/**
 * Conference Rooms Management - Admin Panel
 * Card-based layout with modal editing, matching room-management.php style
 */
require_once 'admin-init.php';
// Bootstrap fallback guards (admin-init.php sets these; guards satisfy static analysis)
$user       = $user       ?? ['id' => 0, 'username' => '', 'role' => 'guest', 'full_name' => ''];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name  = $site_name  ?? getSetting('site_name', 'Hotel');

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once '../includes/alert.php';
require_once '../includes/finance-sequences.php';

finance_ensure_sequence_tables($pdo);

function syncConferenceRoomManagedMedia(array $room): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $roomId = $room['id'] ?? null;
    if (!$roomId) {
        return;
    }

    upsertManagedMediaForSource('conference_rooms', $roomId, 'image_path', $room['image_path'] ?? null, [
        'title' => ($room['name'] ?? 'Conference Room') . ' (Image)',
        'description' => $room['description'] ?? null,
        'caption' => $room['description'] ?? null,
        'alt_text' => $room['name'] ?? 'Conference room image',
        'placement_key' => 'conference_rooms.image_path',
        'page_slug' => 'conference',
        'section_key' => 'conference_rooms',
        'entity_type' => 'conference_room',
        'entity_id' => (int)$roomId,
        'display_order' => (int)($room['display_order'] ?? 0),
        'use_case' => 'card_image',
        'media_type' => 'image',
    ]);
}

$message = '';
$error = '';

function uploadConferenceImage(array $fileInput): ?string
{
    if (empty($fileInput) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($fileInput['size'] ?? 0) > 8 * 1024 * 1024) {
        error_log('Conference upload rejected: file > 8MB');
        return null;
    }

    $uploadDir = __DIR__ . '/../images/conference/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileInput['tmp_name']) ?: '';
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }
    if (!@getimagesize($fileInput['tmp_name'])) {
        return null;
    }

    $filename = 'conference_' . time() . '_' . random_int(1000, 9999) . '.' . $extension;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return 'images/conference/' . $filename;
    }

    return null;
}

function syncConferenceEnquiryPaymentSnapshot(PDO $pdo, int $enquiryId): ?array
{
    $enquiryStmt = $pdo->prepare("SELECT id, total_amount, deposit_required FROM conference_inquiries WHERE id = ? LIMIT 1");
    $enquiryStmt->execute([$enquiryId]);
    $enquiry = $enquiryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$enquiry) {
        return null;
    }

    $paidStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END), 0) AS amount_paid
        FROM payments
        WHERE booking_type = 'conference'
          AND booking_id = ?
          AND deleted_at IS NULL
    ");
    $paidStmt->execute([$enquiryId]);
    $amountPaid = (float)($paidStmt->fetchColumn() ?? 0);

    $lastPaymentStmt = $pdo->prepare("
        SELECT MAX(payment_date) AS last_payment_date
        FROM payments
        WHERE booking_type = 'conference'
          AND booking_id = ?
          AND payment_status IN ('completed', 'paid')
          AND COALESCE(payment_type, '') != 'refund'
          AND deleted_at IS NULL
    ");
    $lastPaymentStmt->execute([$enquiryId]);
    $lastPaymentDate = $lastPaymentStmt->fetchColumn() ?: null;

    $totalAmount = (float)($enquiry['total_amount'] ?? 0);
    $depositRequired = (float)($enquiry['deposit_required'] ?? 0);
    $amountDue = max(0, $totalAmount - $amountPaid);
    $depositPaid = min($amountPaid, $depositRequired);

    $syncStmt = $pdo->prepare("
        UPDATE conference_inquiries
        SET amount_paid = ?,
            amount_due = ?,
            deposit_paid = ?,
            last_payment_date = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $syncStmt->execute([$amountPaid, $amountDue, $depositPaid, $lastPaymentDate, $enquiryId]);

    return [
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_due' => $amountDue,
        'deposit_required' => $depositRequired,
        'deposit_paid' => $depositPaid,
    ];
}

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token invalid — refresh the page.');
        }
        $action = $_POST['action'] ?? '';
        $imagePath = uploadConferenceImage($_FILES['image'] ?? []);

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO conference_rooms (
                    name, description, capacity, size_sqm, daily_rate,
                    amenities, image_path, is_active, display_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['description'],
                $_POST['capacity'],
                $_POST['size_sqm'] ?: null,
                $_POST['daily_rate'],
                $_POST['amenities'] ?? '',
                $imagePath,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0
            ]);

            $newConferenceRoomId = (int)$pdo->lastInsertId();
            if ($newConferenceRoomId > 0) {
                syncConferenceRoomManagedMedia([
                    'id' => $newConferenceRoomId,
                    'name' => $_POST['name'] ?? null,
                    'description' => $_POST['description'] ?? null,
                    'display_order' => $_POST['display_order'] ?? 0,
                    'image_path' => $imagePath,
                ]);
            }

            $message = 'Conference room added successfully!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $newConferenceRoomId]);
                exit;
            }
        }

        if ($action === 'update') {
            if ($imagePath) {
                $stmt = $pdo->prepare("
                    UPDATE conference_rooms
                    SET name = ?, description = ?, capacity = ?, size_sqm = ?, daily_rate = ?,
                        amenities = ?, image_path = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['capacity'],
                    $_POST['size_sqm'] ?: null,
                    $_POST['daily_rate'],
                    $_POST['amenities'] ?? '',
                    $imagePath,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE conference_rooms
                    SET name = ?, description = ?, capacity = ?, size_sqm = ?, daily_rate = ?,
                        amenities = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['capacity'],
                    $_POST['size_sqm'] ?: null,
                    $_POST['daily_rate'],
                    $_POST['amenities'] ?? '',
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
            }

            $roomId = (int)($_POST['id'] ?? 0);
            if ($roomId > 0) {
                $mediaStmt = $pdo->prepare("SELECT id, name, description, display_order, image_path FROM conference_rooms WHERE id = ? LIMIT 1");
                $mediaStmt->execute([$roomId]);
                $roomForMedia = $mediaStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncConferenceRoomManagedMedia($roomForMedia);
                }
            }

            $message = 'Conference room updated successfully!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $roomId]);
                exit;
            }
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM conference_rooms WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Conference room deleted successfully!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }

        if ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE conference_rooms SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Status updated!';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Fetch conference rooms
try {
    $stmt = $pdo->query("SELECT * FROM conference_rooms ORDER BY display_order ASC, name ASC");
    $conference_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($conference_rooms) && function_exists('applyManagedMediaOverrides')) {
        foreach ($conference_rooms as &$conferenceRoomRow) {
            $conferenceRoomRow = applyManagedMediaOverrides($conferenceRoomRow, 'conference_rooms', $conferenceRoomRow['id'] ?? '', ['image_path']);
        }
        unset($conferenceRoomRow);
    }
} catch (PDOException $e) {
    $conference_rooms = [];
    $error = 'Error fetching conference rooms: ' . $e->getMessage();
}

// Handle enquiry status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enquiry_action'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token invalid — refresh the page.');
        }
        $enquiry_id = isset($_POST['enquiry_id']) ? (int)$_POST['enquiry_id'] : 0;
        $action = (string)($_POST['enquiry_action'] ?? '');

        if ($enquiry_id <= 0) {
            throw new Exception('Invalid enquiry selected.');
        }

        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enquiry) {
            throw new Exception('Enquiry not found!');
        }

        $paymentSnapshot = syncConferenceEnquiryPaymentSnapshot($pdo, $enquiry_id);
        if ($paymentSnapshot !== null) {
            $enquiry['amount_paid'] = $paymentSnapshot['amount_paid'];
            $enquiry['amount_due'] = $paymentSnapshot['amount_due'];
            $enquiry['deposit_paid'] = $paymentSnapshot['deposit_paid'];
        }

        if (in_array($action, ['confirm', 'complete'], true)) {
            $depositRequired = (float)($paymentSnapshot['deposit_required'] ?? $enquiry['deposit_required'] ?? 0);
            $depositPaid = (float)($paymentSnapshot['deposit_paid'] ?? $enquiry['deposit_paid'] ?? 0);
            if ($depositRequired > 0 && $depositPaid + 0.0001 < $depositRequired) {
                throw new Exception('Required conference deposit has not been fully paid yet.');
            }
        }

        if ($action === 'confirm') {
            if (($enquiry['status'] ?? '') !== 'pending') {
                throw new Exception('Only pending enquiries can be confirmed.');
            }

            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'confirmed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enquiry_id]);

            $email_result = sendConferenceConfirmedEmail($enquiry);
            if ($email_result['success']) {
                $message = 'Conference enquiry confirmed successfully! Confirmation email sent.';
            } else {
                $message = 'Conference enquiry confirmed successfully! (Email not sent: ' . $email_result['message'] . ')';
            }

            // Admin CC notification for conference confirmation
            try {
                $adminCcAddr = trim((string)getEmailSetting('email_admin_email', ''));
                if (empty($adminCcAddr)) {
                    $adminCcAddr = trim((string)getEmailSetting('smtp_username', ''));
                }
                if (!empty($adminCcAddr) && filter_var($adminCcAddr, FILTER_VALIDATE_EMAIL)) {
                    $confSiteName  = getSetting('site_name', 'Hotel');
                    $confAdminUrl  = rtrim((string)getSetting('site_url', ''), '/') . '/admin/conference-management.php?id=' . $enquiry_id;
                    $confAdminBody = '<h2 style="color:#8B7355;">Conference Enquiry Confirmed</h2>'
                        . '<p>Confirmed by: <strong>' . htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Admin') . '</strong></p>'
                        . '<p><strong>Reference:</strong> ' . htmlspecialchars($enquiry['inquiry_reference'] ?? '') . '<br>'
                        . '<strong>Company/Client:</strong> ' . htmlspecialchars($enquiry['company_name'] ?? $enquiry['contact_person'] ?? '') . '<br>'
                        . '<strong>Email:</strong> ' . htmlspecialchars($enquiry['email'] ?? '') . '</p>'
                        . '<p><a href="' . htmlspecialchars($confAdminUrl) . '" style="background:#8B7355;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">View Enquiry</a></p>';
                    sendEmail($adminCcAddr, $confSiteName, '[Admin] Conference Confirmed — ' . htmlspecialchars($enquiry['inquiry_reference'] ?? ''), $confAdminBody);
                }
            } catch (Throwable $confCcEx) {
                error_log('Admin CC for conference confirmation failed: ' . $confCcEx->getMessage());
            }
        } elseif ($action === 'cancel') {
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enquiry_id]);

            // Refund accounting: record a refund row if any completed payment exists.
            $confCanPay = $pdo->prepare("
                SELECT SUM(total_amount) as total_paid
                FROM payments
                WHERE booking_type = 'conference' AND booking_id = ?
                  AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                  AND deleted_at IS NULL
            ");
            $confCanPay->execute([$enquiry_id]);
            $confPaidTotal = (float)(($confCanPay->fetch(PDO::FETCH_ASSOC))['total_paid'] ?? 0);
            if ($confPaidTotal > 0) {
                do {
                    $confRefRef = 'RFD-CONF-' . strtoupper(substr(uniqid(), -8));
                    $confRefChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                    $confRefChk->execute([$confRefRef]);
                } while ((int)$confRefChk->fetchColumn() > 0);
                $confVatEnabled = getSetting('vat_enabled') === '1';
                $confVatRate    = $confVatEnabled ? (float)getSetting('vat_rate') : 0;
                $confVatAmt     = $confVatRate > 0 ? round($confPaidTotal * ($confVatRate / (100 + $confVatRate)), 2) : 0;
                $confNetAmt     = round($confPaidTotal - $confVatAmt, 2);
                $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status,
                        refund_reason, refund_status, refund_amount,
                        recorded_by, created_at
                    ) VALUES (?, 'conference', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'refund', 'completed',
                              'cancellation', 'completed', ?, ?, NOW())
                ")->execute([
                    $confRefRef,
                    $enquiry_id,
                    $enquiry['inquiry_reference'] ?? '',
                    $confNetAmt,
                    $confVatRate,
                    $confVatAmt,
                    $confPaidTotal,
                    $confPaidTotal,
                    (int)($user['id'] ?? 0),
                ]);
                if (function_exists('updateConferenceEnquiryPayments')) {
                    updateConferenceEnquiryPayments($pdo, $enquiry_id);
                }
                $pdo->prepare("UPDATE conference_inquiries SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
                    ->execute([$enquiry_id]);
            }

            $email_result = sendConferenceCancelledEmail($enquiry);

            $email_sent = $email_result['success'];
            $email_status = $email_result['message'];
            logCancellationToDatabase(
                $enquiry['id'],
                $enquiry['inquiry_reference'],
                'conference',
                $enquiry['email'],
                $user['id'],
                'Cancelled by admin',
                $email_sent,
                $email_status
            );

            logCancellationToFile(
                $enquiry['inquiry_reference'],
                'conference',
                $enquiry['email'],
                $user['full_name'] ?? $user['username'],
                'Cancelled by admin',
                $email_sent,
                $email_status
            );

            if ($email_sent) {
                $message = 'Conference enquiry cancelled successfully! Cancellation email sent.';
            } else {
                $message = 'Conference enquiry cancelled successfully! (Email not sent: ' . $email_status . ')';
            }
        } elseif ($action === 'complete') {
            if (($enquiry['status'] ?? '') !== 'confirmed') {
                throw new Exception('Only confirmed enquiries can be marked completed.');
            }

            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enquiry_id]);
            $message = 'Conference marked as completed!';
        } elseif ($action === 'send_invoice') {
            try {
                $vatEnabled = getSetting('vat_enabled') === '1';
                $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

                $totalAmount = (float)$enquiry['total_amount'];
                $vatAmount = $vatEnabled ? ($totalAmount * ($vatRate / 100)) : 0;
                $totalWithVat = $totalAmount + $vatAmount;

                // Idempotency guard — if already fully paid just resend the invoice
                $alreadyPaid = (float)($paymentSnapshot['amount_paid'] ?? 0);
                if ($alreadyPaid >= $totalWithVat - 0.01) {
                    $invoice_result = sendConferenceInvoiceEmail($enquiry_id);
                    $message = 'Payment already recorded. Invoice resent to ' . htmlspecialchars($enquiry['email'] ?? '');
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
                            ) VALUES (?, 'conference', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?)
                        ");
                    $insert_payment->execute([
                        $payment_reference,
                        $enquiry_id,
                        $enquiry['inquiry_reference'],
                        $totalAmount,
                        $vatRate,
                        $vatAmount,
                        $totalWithVat,
                        $receipt_number,
                        $user['id']
                    ]);
                    $conf_payment_id = (int)$pdo->lastInsertId();

                    $update_amounts = $pdo->prepare("
                            UPDATE conference_inquiries
                            SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                                total_with_vat = ?, last_payment_date = CURDATE(), payment_status = 'full_paid'
                            WHERE id = ?
                        ");
                    $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $enquiry_id]);
                    $pdo->commit();

                    // Send receipt email with PDF
                    if ($conf_payment_id > 0) {
                        try {
                            require_once '../config/receipts.php';
                            receipt_auto_send($pdo, $conf_payment_id, $user);
                        } catch (Throwable $rcptEx) {
                            error_log('Receipt email failed for conference payment ' . $conf_payment_id . ': ' . $rcptEx->getMessage());
                        }
                    }

                    $invoice_result = sendConferenceInvoiceEmail($enquiry_id);
                    if ($invoice_result['success']) {
                        $message = 'Payment recorded successfully! Invoice sent to ' . htmlspecialchars($enquiry['email']);
                    } else {
                        $message = 'Payment recorded successfully! (Invoice email failed: ' . $invoice_result['message'] . ')';
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to record payment: ' . $e->getMessage();
                error_log("Conference payment error: " . $e->getMessage());
            }
        } elseif ($action === 'send_quotation') {
            $quoteValidDays = max(1, (int)($_POST['quotation_valid_days'] ?? 7));
            $quoteNotes = trim((string)($_POST['quotation_notes'] ?? ''));
            $sendWhatsapp = isset($_POST['send_whatsapp']);

            $quoteResult = sendConferenceQuotationEmail($enquiry, [
                'valid_days' => $quoteValidDays,
                'quotation_notes' => $quoteNotes,
                'attach_pdf' => true,
                'send_whatsapp' => $sendWhatsapp,
            ]);

            if (!empty($quoteResult['success'])) {
                $message = 'Conference quotation sent to ' . htmlspecialchars((string)($enquiry['email'] ?? '')) . '.';
                if (!empty($quoteResult['whatsapp']['success'])) {
                    $message .= ' WhatsApp delivered.';
                } elseif (!empty($quoteResult['whatsapp']['message']) && !in_array($quoteResult['whatsapp']['message'], ['No contact phone', 'WhatsApp disabled'], true)) {
                    $message .= ' WhatsApp issue: ' . $quoteResult['whatsapp']['message'];
                }
            } else {
                $error = 'Failed to send quotation: ' . ($quoteResult['message'] ?? 'Unknown error');
            }
        } elseif ($action === 'update_amount') {
            $amount = $_POST['total_amount'] ?? 0;
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET total_amount = ? WHERE id = ?");
            $stmt->execute([$amount, $enquiry_id]);
            $message = 'Total amount updated successfully!';
        } elseif ($action === 'update_notes') {
            $notes = $_POST['notes'] ?? '';
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET notes = ? WHERE id = ?");
            $stmt->execute([$notes, $enquiry_id]);
            $message = 'Notes updated successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Error updating enquiry: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch conference enquiries
try {
    $enquiries_stmt = $pdo->query("
        SELECT ci.*, cr.name as room_name
        FROM conference_inquiries ci
        LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
        ORDER BY ci.event_date DESC, ci.created_at DESC
    ");
    $conference_enquiries = $enquiries_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conference_enquiries = [];
}

$currency = htmlspecialchars(getSetting('currency_symbol'));

// Facebook sharing
require_once '../includes/facebook-functions.php';
$fb_conference_posting_on = isFacebookPostingEnabled()
    && getSetting('facebook_conference_enabled', '1') === '1';

$conference_css_version = (string)@filemtime(__DIR__ . '/css/conference-management.css');
if ($conference_css_version === '' || $conference_css_version === '0') {
    $conference_css_version = (string)time();
}

$facebook_settings_css_version = (string)@filemtime(__DIR__ . '/css/facebook-settings.css');
if ($facebook_settings_css_version === '' || $facebook_settings_css_version === '0') {
    $facebook_settings_css_version = (string)time();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conference Rooms - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/conference-management.css?v=<?php echo urlencode($conference_css_version); ?>">
    <link rel="stylesheet" href="css/facebook-settings.css?v=<?php echo urlencode($facebook_settings_css_version); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header-row">
            <h2 class="page-title"><i class="fas fa-users"></i> Conference Rooms Management</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <button class="btn-action" type="button" style="background:var(--gold,#8B7355); color:var(--deep-navy,#111111); padding:12px 24px; font-size:14px; border-radius:8px;" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Room
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div id="conferencePageFeedback" class="admin-modal-feedback" hidden></div>

        <!-- Conference Rooms Cards -->
        <?php if ($fb_conference_posting_on && !empty($conference_rooms)): ?>
            <div class="fb-conf-all-banner">
                <div class="fb-conf-all-banner__icon">
                    <i class="fab fa-facebook-f"></i>
                </div>
                <div class="fb-conf-all-banner__text">
                    <div class="fb-conf-all-banner__title">Share All Conference Rooms on Facebook</div>
                    <div class="fb-conf-all-banner__sub">Pick rooms, edit the caption, and preview before you post — all in one place.</div>
                </div>
                <button class="fb-conf-all-btn" type="button" onclick="openFbAllConferenceModal()">
                    <i class="fab fa-facebook-f"></i> Compose &amp; Preview Post
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($conference_rooms)): ?>
            <div class="conference-cards-grid" id="conferenceGrid">
                <?php foreach ($conference_rooms as $room): ?>
                    <div class="conference-card" data-id="<?php echo $room['id']; ?>">
                        <?php if ($room['display_order'] > 0): ?>
                            <span class="order-badge">#<?php echo $room['display_order']; ?></span>
                        <?php endif; ?>

                        <?php if (!empty($room['image_path'])): ?>
                            <?php $imgSrc = preg_match('#^https?://#i', $room['image_path']) ? $room['image_path'] : '../' . $room['image_path']; ?>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                alt="<?php echo htmlspecialchars($room['name']); ?>"
                                class="conference-card-image"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="no-image-placeholder" style="display:none;"><i class="fas fa-users"></i><span>No Image</span></div>
                        <?php else: ?>
                            <div class="no-image-placeholder"><i class="fas fa-users"></i><span>No Image</span></div>
                        <?php endif; ?>

                        <div class="conference-card-body">
                            <div class="conference-card-title">
                                <?php echo htmlspecialchars($room['name']); ?>
                            </div>
                            <div class="conference-card-desc"><?php echo htmlspecialchars($room['description'] ?? ''); ?></div>

                            <div class="conference-card-details">
                                <div class="detail-item detail-item-price"><i class="fas fa-tag"></i> <?php echo $currency; ?> <?php echo number_format($room['daily_rate'], 2); ?>/day</div>
                                <div class="detail-item"><i class="fas fa-users"></i> <?php echo $room['capacity']; ?> guests</div>
                                <div class="detail-item"><i class="fas fa-expand-arrows-alt"></i> <?php echo number_format($room['size_sqm'] ?? 0, 0); ?> sqm</div>
                            </div>

                            <?php if (!empty($room['amenities'])): ?>
                                <div class="conference-card-amenities">
                                    <i class="fas fa-concierge-bell"></i> <?php echo htmlspecialchars(substr($room['amenities'], 0, 60)); ?><?php echo strlen($room['amenities']) > 60 ? '...' : ''; ?>
                                </div>
                            <?php endif; ?>

                            <div class="conference-card-meta">
                                <?php if ($room['is_active']): ?>
                                    <span class="conference-badge active"><i class="fas fa-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="conference-badge inactive"><i class="fas fa-times"></i> Inactive</span>
                                <?php endif; ?>
                            </div>

                            <div class="conference-card-actions">
                                <div class="cmc-toolbar">
                                    <button class="cmc-icon-btn <?php echo $room['is_active'] ? 'cmc-icon-btn--active-on' : 'cmc-icon-btn--active-off'; ?>"
                                        type="button"
                                        onclick="toggleActive(<?php echo (int)$room['id']; ?>)"
                                        title="<?php echo $room['is_active'] ? 'Deactivate room' : 'Activate room'; ?>">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                    <?php if ($fb_conference_posting_on): ?>
                                        <span class="cmc-toolbar-sep"></span>
                                        <button class="cmc-icon-btn cmc-icon-btn--facebook"
                                            type="button"
                                            onclick='openFbConferenceModal(<?php echo (int)$room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["image_path"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode(number_format((float)($room["daily_rate"] ?? 0))), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode((int)($room["capacity"] ?? 0)), ENT_QUOTES, "UTF-8"); ?>)'
                                            title="Post to Facebook Page">
                                            <i class="fab fa-facebook-f"></i>
                                        </button>
                                    <?php endif; ?>
                                    <span class="cmc-spacer"></span>
                                    <button class="cmc-icon-btn cmc-icon-btn--danger"
                                        type="button"
                                        onclick="confirmDeleteRoom(<?php echo (int)$room['id']; ?>, <?php echo htmlspecialchars(json_encode($room['name'] ?? 'Conference room'), ENT_QUOTES, 'UTF-8'); ?>)"
                                        title="Delete room">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                                <button class="cmc-edit-btn" type="button"
                                    onclick='openEditModal(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-edit"></i> Edit Room
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No conference rooms found. Click "Add New Room" to get started.</p>
            </div>
        <?php endif; ?>

        <!-- Conference Enquiries Section -->
        <div class="card conference-enquiries-card">
            <h2 class="conference-enquiries-title"><i class="fas fa-calendar-check"></i> Conference Enquiries</h2>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Company</th>
                            <th>Contact</th>
                            <th>Event Date</th>
                            <th>Time</th>
                            <th>Room</th>
                            <th>Attendees</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($conference_enquiries)): ?>
                            <tr>
                                <td colspan="10" class="conference-enquiries-empty">
                                    <i class="fas fa-inbox conference-enquiries-empty__icon"></i>
                                    No conference enquiries found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($conference_enquiries as $enquiry): ?>
                                <tr id="enquiry-<?php echo (int)$enquiry['id']; ?>">
                                    <td><strong><?php echo htmlspecialchars($enquiry['inquiry_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($enquiry['company_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($enquiry['contact_person']); ?><br>
                                        <small><?php echo htmlspecialchars($enquiry['email']); ?></small><br>
                                        <small><?php echo htmlspecialchars($enquiry['phone']); ?></small>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($enquiry['event_date'])); ?></td>
                                    <td>
                                        <?php echo date('H:i', strtotime($enquiry['start_time'])); ?> -
                                        <?php echo date('H:i', strtotime($enquiry['end_time'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($enquiry['room_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo (int) $enquiry['number_of_attendees']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $enquiry['status']; ?>">
                                            <?php echo ucfirst($enquiry['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($enquiry['total_amount']): ?>
                                            <?php echo $currency; ?> <?php echo number_format($enquiry['total_amount'], 2); ?>
                                        <?php else: ?>
                                            <em>Pending</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="quick-actions">
                                            <?php if ($enquiry['status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="enquiry_action" value="confirm">
                                                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm"
                                                        data-admin-confirm="Confirm this conference enquiry?"
                                                        data-admin-confirm-title="Confirm enquiry"
                                                        data-admin-confirm-ok="Confirm"
                                                        data-admin-confirm-cancel="Cancel"
                                                        data-admin-confirm-tone="success"
                                                        data-admin-confirm-icon="fa-check">
                                                        <i class="fas fa-check"></i> Confirm
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($enquiry['status'] === 'confirmed'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="enquiry_action" value="complete">
                                                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm"
                                                        data-admin-confirm="Mark this conference as completed?"
                                                        data-admin-confirm-title="Complete conference"
                                                        data-admin-confirm-ok="Complete"
                                                        data-admin-confirm-cancel="Cancel"
                                                        data-admin-confirm-tone="warning"
                                                        data-admin-confirm-icon="fa-check-circle">
                                                        <i class="fas fa-check-circle"></i> Complete
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="enquiry_action" value="send_invoice">
                                                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                                    <button type="submit" class="btn btn-info btn-sm"
                                                        data-admin-confirm="Generate and send invoice for this conference?"
                                                        data-admin-confirm-title="Send invoice"
                                                        data-admin-confirm-ok="Send invoice"
                                                        data-admin-confirm-cancel="Cancel"
                                                        data-admin-confirm-tone="warning"
                                                        data-admin-confirm-icon="fa-file-invoice-dollar">
                                                        <i class="fas fa-file-invoice-dollar"></i> Paid
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($enquiry['status'], ['pending', 'confirmed'])): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="enquiry_action" value="send_quotation">
                                                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                                    <input type="hidden" name="quotation_valid_days" value="7">
                                                    <input type="hidden" name="send_whatsapp" value="1">
                                                    <button type="submit" class="btn btn-quote btn-sm"
                                                        data-admin-confirm="Send conference quotation now? This sends email and WhatsApp (if enabled)."
                                                        data-admin-confirm-title="Send quotation"
                                                        data-admin-confirm-ok="Send quotation"
                                                        data-admin-confirm-cancel="Cancel"
                                                        data-admin-confirm-tone="warning"
                                                        data-admin-confirm-icon="fa-file-signature">
                                                        <i class="fas fa-file-signature"></i> Quote
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="enquiry_action" value="cancel">
                                                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm"
                                                        data-admin-confirm="Cancel this conference enquiry?"
                                                        data-admin-confirm-title="Cancel enquiry"
                                                        data-admin-confirm-ok="Cancel enquiry"
                                                        data-admin-confirm-cancel="Keep enquiry"
                                                        data-admin-confirm-tone="danger"
                                                        data-admin-confirm-icon="fa-times">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="showEnquiryDetails(<?php echo htmlspecialchars(json_encode($enquiry)); ?>)">
                                                <i class="fas fa-eye"></i> Details
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Conference Room Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Conference Room</h3>
                <button class="modal-close" type="button" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="addForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Description *</label>
                            <textarea name="description" rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Featured Image</label>
                            <input type="file" name="image" accept="image/*">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Capacity *</label>
                                <input type="number" name="capacity" min="1" required>
                            </div>
                            <div class="form-group">
                                <label>Size (sqm)</label>
                                <input type="number" step="0.01" name="size_sqm">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Day Rate *</label>
                                <input type="number" step="0.01" name="daily_rate" required data-currency="<?php echo htmlspecialchars($currency, ENT_QUOTES); ?>">
                            </div>
                            <div class="form-group">
                                <label>Display Order</label>
                                <input type="number" name="display_order" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Amenities (comma separated)</label>
                            <textarea name="amenities" rows="2" placeholder="Projector, Whiteboard, WiFi, Catering"></textarea>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom:none;">
                        <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                        <div class="checkbox-row">
                            <label>
                                <input type="checkbox" name="is_active" value="1" checked> Active
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="flex-direction:column; align-items:stretch; gap:0;">
                    <div id="addModalFeedback" class="admin-modal-feedback"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="closeAddModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                        <button type="submit" id="addFormSaveBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#8B7355); color:var(--deep-navy,#111111); font-weight:600; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Room
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Conference Room Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editModalTitle"><i class="fas fa-edit"></i> Edit Conference Room</h3>
                <button class="modal-close" type="button" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">

                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" name="name" id="editName" required>
                        </div>
                        <div class="form-group">
                            <label>Description *</label>
                            <textarea name="description" id="editDescription" rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Replace Image</label>
                            <input type="file" name="image" accept="image/*">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Capacity *</label>
                                <input type="number" name="capacity" id="editCapacity" min="1" required>
                            </div>
                            <div class="form-group">
                                <label>Size (sqm)</label>
                                <input type="number" step="0.01" name="size_sqm" id="editSize">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Day Rate *</label>
                                <input type="number" step="0.01" name="daily_rate" id="editRate" required data-currency="<?php echo htmlspecialchars($currency, ENT_QUOTES); ?>">
                            </div>
                            <div class="form-group">
                                <label>Display Order</label>
                                <input type="number" name="display_order" id="editOrder" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Amenities (comma separated)</label>
                            <textarea name="amenities" id="editAmenities" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom:none;">
                        <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                        <div class="checkbox-row">
                            <label>
                                <input type="checkbox" name="is_active" id="editIsActive" value="1"> Active
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="flex-direction:column; align-items:stretch; gap:0;">
                    <div id="editModalFeedback" class="admin-modal-feedback"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="closeEditModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                        <button type="submit" id="editFormSaveBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#8B7355); color:var(--deep-navy,#111111); font-weight:600; cursor:pointer;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Enquiry Details Modal -->
    <div id="enquiryModal" class="modal-overlay" style="display:none;">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt"></i> Conference Enquiry Details</h3>
                <button class="modal-close" type="button" onclick="closeEnquiryModal()">&times;</button>
            </div>
            <div class="modal-body" id="enquiryModalBody">
            </div>
        </div>
    </div>

    <div id="admin-page-loader" class="admin-page-loader" role="status" aria-label="Loading">
        <div class="admin-page-loader-card">
            <div class="admin-page-loader-spinner"><span></span><span></span><span></span></div>
            <p class="admin-page-loader-title">Loading...</p>
        </div>
    </div>

    <script>
        const CONFERENCE_CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

        function setConferenceLoader(visible, label) {
            const loader = document.getElementById('admin-page-loader');
            if (!loader) {
                return;
            }

            const title = loader.querySelector('.admin-page-loader-title');
            if (title && label) {
                title.textContent = label;
            }

            loader.classList.toggle('is-visible', !!visible);
        }

        function showConferenceMessage(isError, message) {
            const safeMessage = String(message)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            if (window.Modal && typeof window.Modal.showMessage === 'function') {
                window.Modal.showMessage({
                    title: isError ? 'Action Failed' : 'Success',
                    message: '<p>' + safeMessage + '</p>',
                    size: 'sm'
                });
                return;
            }

            const feedback = document.getElementById('conferencePageFeedback');
            if (!feedback) {
                return;
            }

            feedback.hidden = false;
            feedback.className = 'admin-modal-feedback ' + (isError ? 'admin-modal-feedback--error' : 'admin-modal-feedback--success') + ' visible';
            feedback.innerHTML = '<i class="fas ' + (isError ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i> ' + safeMessage;
        }

        function requestConferenceConfirmation(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options);
            }

            return Promise.resolve(true);
        }

        function postConferenceAction(action, id, loadingText) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('id', String(id));
            fd.append('csrf_token', CONFERENCE_CSRF_TOKEN || '');

            setConferenceLoader(true, loadingText);

            return fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    if (!r.ok) {
                        throw new Error('Request failed');
                    }
                    window.location.reload();
                })
                .catch(function() {
                    setConferenceLoader(false, 'Loading...');
                    showConferenceMessage(true, 'Unable to complete this action. Please try again.');
                });
        }

        // ===== ADD MODAL =====
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            const fb = document.getElementById('addModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        // ===== EDIT MODAL =====
        function openEditModal(room) {
            document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit: ' + escapeHtml(room.name);
            document.getElementById('editId').value = room.id;
            document.getElementById('editName').value = room.name || '';
            document.getElementById('editDescription').value = room.description || '';
            document.getElementById('editCapacity').value = room.capacity || '';
            document.getElementById('editSize').value = room.size_sqm || '';
            document.getElementById('editRate').value = room.daily_rate || '';
            document.getElementById('editOrder').value = room.display_order || 0;
            document.getElementById('editAmenities').value = room.amenities || '';
            document.getElementById('editIsActive').checked = room.is_active == 1;
            document.getElementById('editModal').style.display = 'flex';
            const fb = document.getElementById('editModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            const fb = document.getElementById('editModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        // ── AJAX save for add form ───────────────────────────────────
        function handleConferenceFormSubmit(formId, saveBtnId, feedbackId) {
            const form = document.getElementById(formId);
            const saveBtn = document.getElementById(saveBtnId);
            const fb = document.getElementById(feedbackId);
            const origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            setConferenceLoader(true, 'Saving conference room...');
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    setConferenceLoader(false, 'Loading...');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) refreshConferenceGrid();
                })
                .catch(function() {
                    setConferenceLoader(false, 'Loading...');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        }
        document.getElementById('addForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleConferenceFormSubmit('addForm', 'addFormSaveBtn', 'addModalFeedback');
        });
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleConferenceFormSubmit('editForm', 'editFormSaveBtn', 'editModalFeedback');
        });

        function refreshConferenceGrid() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('conferenceGrid');
                    const cur = document.getElementById('conferenceGrid');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                }).catch(function() {});
        }

        // ===== TOGGLE & DELETE =====
        function toggleActive(id) {
            return postConferenceAction('toggle_active', id, 'Updating conference room...');
        }

        function deleteRoom(id) {
            return postConferenceAction('delete', id, 'Deleting conference room...');
        }

        function confirmDeleteRoom(id, roomName) {
            requestConferenceConfirmation({
                title: 'Delete conference room?',
                message: 'This permanently removes the room from conference management.',
                details: [roomName || 'Conference room'],
                confirmText: 'Delete room',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'fa-trash-alt'
            }).then(function(confirmed) {
                if (confirmed) {
                    deleteRoom(id);
                }
            });
        }

        // ===== ENQUIRY MODAL =====
        function showEnquiryDetails(enquiry) {
            const modal = document.getElementById('enquiryModal');
            const body = document.getElementById('enquiryModalBody');

            body.innerHTML = `
            <div class="enquiry-details">
                <div class="detail-row">
                    <strong>Reference:</strong>
                    <span>${escapeHtml(enquiry.inquiry_reference)}</span>
                </div>
                <div class="detail-row">
                    <strong>Company:</strong>
                    <span>${escapeHtml(enquiry.company_name)}</span>
                </div>
                <div class="detail-row">
                    <strong>Contact Person:</strong>
                    <span>${escapeHtml(enquiry.contact_person)}</span>
                </div>
                <div class="detail-row">
                    <strong>Email:</strong>
                    <span>${escapeHtml(enquiry.email)}</span>
                </div>
                <div class="detail-row">
                    <strong>Phone:</strong>
                    <span>${escapeHtml(enquiry.phone)}</span>
                </div>
                <div class="detail-row">
                    <strong>Event Date:</strong>
                    <span>${new Date(enquiry.event_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                </div>
                <div class="detail-row">
                    <strong>Time:</strong>
                    <span>${enquiry.start_time} - ${enquiry.end_time}</span>
                </div>
                <div class="detail-row">
                    <strong>Conference Room:</strong>
                    <span>${escapeHtml(enquiry.room_name || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <strong>Number of Attendees:</strong>
                    <span>${enquiry.number_of_attendees}</span>
                </div>
                <div class="detail-row">
                    <strong>Event Type:</strong>
                    <span>${escapeHtml(enquiry.event_type || 'N/A')}</span>
                </div>
                <div class="detail-row">
                    <strong>Status:</strong>
                    <span class="badge badge-${enquiry.status}">${enquiry.status.charAt(0).toUpperCase() + enquiry.status.slice(1)}</span>
                </div>
                <div class="detail-row">
                    <strong>Catering Required:</strong>
                    <span>${enquiry.catering_required ? 'Yes' : 'No'}</span>
                </div>
                <div class="detail-row">
                    <strong>AV Equipment:</strong>
                    <span>${escapeHtml(enquiry.av_equipment || 'None')}</span>
                </div>
                <div class="detail-row">
                    <strong>Special Requirements:</strong>
                    <span>${escapeHtml(enquiry.special_requirements || 'None')}</span>
                </div>
                <div class="detail-row">
                    <strong>Total Amount:</strong>
                    <span>${enquiry.total_amount ? '<?php echo $currency; ?> ' + Number(enquiry.total_amount).toLocaleString() : 'Pending'}</span>
                </div>
                <div class="detail-row">
                    <strong>Notes:</strong>
                    <span>${escapeHtml(enquiry.notes || 'None')}</span>
                </div>

                <div class="modal-actions">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(CONFERENCE_CSRF_TOKEN || '')}">
                        <input type="hidden" name="enquiry_action" value="update_amount">
                        <input type="hidden" name="enquiry_id" value="${enquiry.id}">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Update Total Amount:</label>
                            <input type="number" name="total_amount" step="0.01" value="${enquiry.total_amount || ''}" data-currency="<?php echo htmlspecialchars($currency, ENT_QUOTES); ?>">
                        </div>
                        <button type="submit" class="btn">Update Amount</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(CONFERENCE_CSRF_TOKEN || '')}">
                        <input type="hidden" name="enquiry_action" value="update_notes">
                        <input type="hidden" name="enquiry_id" value="${enquiry.id}">
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Update Notes:</label>
                            <textarea name="notes" rows="3" style="width: 100%; max-width: 400px;">${escapeHtml(enquiry.notes || '')}</textarea>
                        </div>
                        <button type="submit" class="btn">Update Notes</button>
                    </form>
                </div>
            </div>
        `;

            modal.style.display = 'flex';
        }

        function closeEnquiryModal() {
            document.getElementById('enquiryModal').style.display = 'none';
        }

        // ===== CLOSE MODALS ON OUTSIDE CLICK =====
        document.querySelectorAll('.modal-overlay').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // Helper
        function escapeHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        <?php if ($fb_conference_posting_on): ?>
            // ── Facebook Conference Share Modal ───────────────────────────────────────
            var _fbConfId = 0;
            var _fbConfImg = false;
            var _fbConfImgUrl = '';

            window._fbConfDefaults = {
                baseUrl: <?php echo json_encode(defined('BASE_URL') ? rtrim(BASE_URL, '/') : ''); ?>,
                currency: <?php echo json_encode(getSetting('currency_symbol', 'MWK')); ?>,
                hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #conference')); ?>,
                pageName: <?php echo json_encode(getSetting('facebook_page_name', '')); ?>
            };

            window._fbAllConferenceRooms = <?php echo json_encode(array_map(function ($r) {
                                                $imgSrc = $r['image_path'] ?? '';
                                                return [
                                                    'id'       => (int)$r['id'],
                                                    'name'     => $r['name'] ?? '',
                                                    'rate'     => number_format((float)($r['daily_rate'] ?? 0), 2),
                                                    'capacity' => (int)($r['capacity'] ?? 0),
                                                    'size_sqm' => (string)($r['size_sqm'] ?? ''),
                                                    'image_url' => $imgSrc,
                                                ];
                                            }, $conference_rooms), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            function openFbConferenceModal(roomId, roomName, imagePath, rate, capacity) {
                _fbConfId = roomId;
                _fbConfImg = (typeof imagePath === 'string' && imagePath !== '');
                _fbConfImgUrl = '';
                var modal = document.getElementById('fbConferenceModal');
                if (!modal) return;
                document.getElementById('fbConfTitle').textContent = 'Post "' + roomName + '" to Facebook';

                var d = window._fbConfDefaults || {};
                // Build absolute image URL
                if (_fbConfImg) {
                    _fbConfImgUrl = /^https?:\/\//i.test(imagePath) ?
                        imagePath :
                        (d.baseUrl || '').replace(/\/+$/, '') + '/' + imagePath.replace(/^\/+/, '');
                }

                var lines = ['🏢 ' + roomName];
                var details = [];
                if (capacity) details.push('Capacity: ' + capacity + ' guests');
                if (rate && rate !== '0') details.push('Rate: ' + d.currency + ' ' + rate + '/day');
                if (details.length) lines.push(details.join(' · '));
                lines.push('');
                lines.push('Book your next event: ' + d.baseUrl + '/conference.php');
                lines.push('');
                lines.push(d.hashtags || '');
                document.getElementById('fbConfCaption').value = lines.join('\n').trim();

                var imgRow = document.getElementById('fbConfImageRow');
                if (imgRow) imgRow.style.display = _fbConfImg ? 'flex' : 'none';
                document.getElementById('fbConfIncludeImage').checked = _fbConfImg;

                // Set page name preview
                var pn = document.getElementById('fbConfPreviewPageName');
                if (pn) pn.textContent = (d.pageName && d.pageName !== '') ? d.pageName : 'Your Facebook Page';

                var fb = document.getElementById('fbConfFeedback');
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
                _fbConfUpdatePreview();
                modal.style.display = 'flex';
            }

            function _fbConfUpdatePreview() {
                var caption = (document.getElementById('fbConfCaption') || {}).value || '';
                var showImg = document.getElementById('fbConfIncludeImage') && document.getElementById('fbConfIncludeImage').checked;

                var previewText = document.getElementById('fbConfPreviewText');
                if (previewText) {
                    var safe = caption
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/\n/g, '<br>');
                    previewText.innerHTML = safe ||
                        '<em style="color:#65676b;font-style:italic;">Caption will appear here\u2026</em>';
                }
                var previewImg = document.getElementById('fbConfPreviewImg');
                if (previewImg) {
                    if (showImg && _fbConfImgUrl) {
                        previewImg.src = _fbConfImgUrl;
                        previewImg.style.display = 'block';
                    } else {
                        previewImg.style.display = 'none';
                    }
                }
                var counter = document.getElementById('fbConfCharCount');
                if (counter) {
                    var len = caption.length;
                    counter.textContent = len + ' chars';
                    counter.style.color = len > 600 ? '#d32f2f' : '#65676b';
                }
            }

            function closeFbConferenceModal() {
                var m = document.getElementById('fbConferenceModal');
                if (m) m.style.display = 'none';
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Single room FB modal — live preview wiring
                var confCaption = document.getElementById('fbConfCaption');
                var confImgCb = document.getElementById('fbConfIncludeImage');
                if (confCaption) confCaption.addEventListener('input', _fbConfUpdatePreview);
                if (confImgCb) confImgCb.addEventListener('change', _fbConfUpdatePreview);

                var submitBtn = document.getElementById('fbConfSubmitBtn');
                if (!submitBtn) return;
                submitBtn.addEventListener('click', function() {
                    var caption = (document.getElementById('fbConfCaption').value || '').trim();
                    var fb = document.getElementById('fbConfFeedback');
                    if (!caption) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                        return;
                    }
                    submitBtn.disabled = true;
                    var origHtml = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';

                    var fd = new FormData();
                    fd.append('type', 'conference');
                    fd.append('id', String(_fbConfId));
                    fd.append('message', caption);
                    fd.append('include_image', document.getElementById('fbConfIncludeImage').checked ? '1' : '0');

                    fetch('api/facebook-post.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            if (data.success) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                var link = data.post_url ? ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' : '';
                                fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + link;
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                            }
                        })
                        .catch(function() {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                        });
                });
            });

            // ── Share All Conference Rooms ──────────────────────────────────────────
            var _fbAllConfFeaturedImgUrl = '';

            function _fbConfEsc(s) {
                return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function openFbAllConferenceModal() {
                var modal = document.getElementById('fbAllConferenceModal');
                if (!modal) return;
                var d = window._fbConfDefaults || {};
                var rooms = window._fbAllConferenceRooms || [];

                var listEl = document.getElementById('fbAllConfRoomList');
                listEl.innerHTML = '';
                rooms.forEach(function(room) {
                    var imgSrc = room.image_url || '';
                    if (imgSrc && !/^https?:\/\//i.test(imgSrc)) {
                        imgSrc = (d.baseUrl || '').replace(/\/+$/, '') + '/' + imgSrc.replace(/^\/+/, '');
                    }
                    var item = document.createElement('label');
                    item.className = 'fb-conf-select-item checked';
                    item.setAttribute('for', 'fbAllConf_' + room.id);
                    item.innerHTML =
                        '<input type="checkbox" id="fbAllConf_' + room.id + '" checked value="' + room.id + '"' +
                        ' data-img="' + _fbConfEsc(imgSrc) + '"' +
                        ' data-name="' + _fbConfEsc(room.name) + '"' +
                        ' data-rate="' + _fbConfEsc(room.rate) + '"' +
                        ' data-capacity="' + _fbConfEsc(String(room.capacity || '')) + '"' +
                        ' data-size="' + _fbConfEsc(String(room.size_sqm || '')) + '">' +
                        (imgSrc ?
                            '<img class="fb-conf-select-thumb" src="' + _fbConfEsc(imgSrc) + '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' :
                            '<div class="fb-conf-select-thumb"></div>') +
                        '<div class="fb-conf-select-info">' +
                        '<div class="fb-conf-select-name">' + _fbConfEsc(room.name) + '</div>' +
                        '<div class="fb-conf-select-price">' + _fbConfEsc(d.currency || 'MWK') + ' ' + _fbConfEsc(room.rate) + '/day</div>' +
                        '</div>';
                    var cb = item.querySelector('input');
                    cb.addEventListener('change', function() {
                        item.classList.toggle('checked', this.checked);
                        fcAllRebuildCaption();
                    });
                    listEl.appendChild(item);
                });

                var pn = document.getElementById('fbAllConfPreviewPageName');
                if (pn) pn.textContent = (d.pageName && d.pageName !== '') ? d.pageName : 'Your Facebook Page';

                document.getElementById('fbAllConfFeedback').className = 'admin-modal-feedback';
                document.getElementById('fbAllConfFeedback').innerHTML = '';
                fcAllRebuildCaption();
                modal.style.display = 'flex';
            }

            function closeFbAllConferenceModal() {
                var m = document.getElementById('fbAllConferenceModal');
                if (m) m.style.display = 'none';
            }

            function fcAllSelectAll(state) {
                document.querySelectorAll('#fbAllConfRoomList input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = state;
                    cb.closest('.fb-conf-select-item').classList.toggle('checked', state);
                });
                fcAllRebuildCaption();
            }

            function fcAllRebuildCaption() {
                var d = window._fbConfDefaults || {};
                var currency = d.currency || 'MWK';
                var baseUrl = d.baseUrl || '';
                var hashtags = d.hashtags || '';

                var selected = [];
                document.querySelectorAll('#fbAllConfRoomList input[type="checkbox"]:checked').forEach(function(cb) {
                    selected.push({
                        name: cb.dataset.name,
                        rate: cb.dataset.rate,
                        capacity: cb.dataset.capacity,
                        size: cb.dataset.size,
                        img: cb.dataset.img,
                    });
                });

                var countEl = document.getElementById('fbAllConfRoomCount');
                if (countEl) countEl.textContent = selected.length + ' room' + (selected.length !== 1 ? 's' : '') + ' selected';

                var hotelName = (d.pageName && d.pageName !== '') ? d.pageName : "Rosalyn's Beach Hotel";
                var lines = ['\uD83C\uDFE2 ' + hotelName + ' \u2014 Conference Facilities', ''];
                selected.forEach(function(r) {
                    lines.push('\uD83D\uDC65 ' + r.name);
                    var parts = [];
                    if (r.rate && r.rate !== '0') parts.push(currency + ' ' + r.rate + '/day');
                    if (r.capacity) parts.push('Up to ' + r.capacity + ' guests');
                    if (r.size) parts.push(r.size + ' sqm');
                    if (parts.length) lines.push('   ' + parts.join(' \u00B7 '));
                    lines.push('');
                });
                if (selected.length > 0) {
                    lines.push('\uD83D\uDCCB Enquire now: ' + baseUrl + '/conference.php');
                    lines.push('');
                    lines.push(hashtags);
                }

                var captionArea = document.getElementById('fbAllConfCaption');
                if (captionArea) captionArea.value = lines.join('\n').trim();

                _fbAllConfFeaturedImgUrl = '';
                for (var i = 0; i < selected.length; i++) {
                    if (selected[i].img) {
                        _fbAllConfFeaturedImgUrl = selected[i].img;
                        break;
                    }
                }

                fcAllUpdatePreview();
            }

            function fcAllUpdatePreview() {
                var caption = (document.getElementById('fbAllConfCaption') || {}).value || '';
                var showImg = document.getElementById('fbAllConfIncludeImage') && document.getElementById('fbAllConfIncludeImage').checked;

                var previewText = document.getElementById('fbAllConfPreviewText');
                if (previewText) {
                    var safe = caption
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/\n/g, '<br>');
                    previewText.innerHTML = safe ||
                        '<em style="color:#65676b;font-style:italic;">Select rooms above to build the preview\u2026</em>';
                }
                var previewImg = document.getElementById('fbAllConfPreviewImg');
                if (previewImg) {
                    if (showImg && _fbAllConfFeaturedImgUrl) {
                        previewImg.src = _fbAllConfFeaturedImgUrl;
                        previewImg.style.display = 'block';
                    } else {
                        previewImg.style.display = 'none';
                    }
                }
                var counter = document.getElementById('fbAllConfCharCount');
                if (counter) {
                    var len = caption.length;
                    counter.textContent = len + ' chars';
                    counter.style.color = len > 600 ? '#d32f2f' : '#65676b';
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                var captionArea = document.getElementById('fbAllConfCaption');
                var imgCheckbox = document.getElementById('fbAllConfIncludeImage');
                if (captionArea) captionArea.addEventListener('input', fcAllUpdatePreview);
                if (imgCheckbox) imgCheckbox.addEventListener('change', fcAllUpdatePreview);

                var submitBtn = document.getElementById('fbAllConfSubmitBtn');
                if (!submitBtn) return;
                submitBtn.addEventListener('click', function() {
                    var caption = (document.getElementById('fbAllConfCaption').value || '').trim();
                    var fb = document.getElementById('fbAllConfFeedback');
                    if (!caption) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Caption cannot be empty.';
                        return;
                    }
                    var checkedIds = [];
                    document.querySelectorAll('#fbAllConfRoomList input[type="checkbox"]:checked').forEach(function(cb) {
                        checkedIds.push(cb.value);
                    });
                    if (checkedIds.length === 0) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select at least one room.';
                        return;
                    }
                    submitBtn.disabled = true;
                    var origHtml = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';

                    var formData = new FormData();
                    formData.append('type', 'conferences_all');
                    formData.append('room_ids', JSON.stringify(checkedIds));
                    formData.append('message', caption);
                    formData.append('include_image', document.getElementById('fbAllConfIncludeImage').checked ? '1' : '0');

                    fetch('api/facebook-post.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            if (data.success) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                var linkHtml = data.post_url ?
                                    ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' :
                                    '';
                                fb.innerHTML = '<i class="fas fa-check-circle"></i> All conference rooms posted to Facebook!' + linkHtml;
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                            }
                        })
                        .catch(function() {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                        });
                });
            });
        <?php endif; ?>
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

    <?php if ($fb_conference_posting_on): ?>
        <!-- Facebook Conference Share Modal (two-column with live preview) -->
        <div class="modal-overlay" id="fbConferenceModal" style="display:none;" onclick="if(event.target===this)closeFbConferenceModal()">
            <div class="modal-content" style="max-width:860px;width:96vw;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 id="fbConfTitle" style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Post to Facebook</h3>
                    <button class="modal-close" type="button" onclick="closeFbConferenceModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding:20px 24px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:24px;align-items:start;">

                        <!-- LEFT: Compose -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fas fa-pencil-alt" style="color:#1877F2;margin-right:6px;font-size:0.8rem;"></i>
                                Compose post
                            </div>
                            <div class="form-group" style="margin-bottom:6px;">
                                <textarea id="fbConfCaption" class="fb-caption-preview" rows="9"
                                    style="width:100%;resize:vertical;font-family:inherit;font-size:0.875rem;line-height:1.6;box-sizing:border-box;"
                                    placeholder="Write your post caption here&hellip;"></textarea>
                                <div style="text-align:right;font-size:0.75rem;margin-top:3px;" id="fbConfCharCount">0 chars</div>
                            </div>
                            <div class="fb-include-image-row" id="fbConfImageRow"
                                style="display:none;align-items:center;gap:10px;padding:10px 12px;background:#f0f2f5;border-radius:8px;margin-bottom:10px;">
                                <input type="checkbox" id="fbConfIncludeImage" value="1" checked style="width:16px;height:16px;cursor:pointer;flex-shrink:0;">
                                <label for="fbConfIncludeImage" style="cursor:pointer;font-size:0.875rem;margin:0;">
                                    <i class="fas fa-image" style="color:#1877F2;margin-right:4px;"></i>
                                    Include room image in post
                                </label>
                            </div>
                            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 12px;font-size:0.8rem;color:#856404;">
                                <i class="fas fa-lightbulb"></i>
                                <strong>Tip:</strong> Posts with images get significantly more reach on Facebook.
                            </div>
                        </div>

                        <!-- RIGHT: Live Facebook preview -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fab fa-facebook-f" style="color:#1877F2;margin-right:6px;"></i>
                                Post preview
                            </div>
                            <div style="border:1px solid #e4e6eb;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
                                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px 8px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;">
                                        <i class="fab fa-facebook-f"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div id="fbConfPreviewPageName" style="font-weight:700;font-size:0.88rem;color:#1c1e21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            Your Page
                                        </div>
                                        <div style="font-size:0.72rem;color:#65676b;">
                                            Just now &middot; <i class="fas fa-globe-africa" style="font-size:0.65rem;"></i>
                                        </div>
                                    </div>
                                </div>
                                <div id="fbConfPreviewText"
                                    style="padding:0 16px 10px;font-size:0.875rem;line-height:1.55;color:#1c1e21;word-break:break-word;white-space:pre-wrap;">
                                    <em style="color:#65676b;font-style:italic;">Caption will appear here&hellip;</em>
                                </div>
                                <img id="fbConfPreviewImg" src="" alt="Conference room image"
                                    style="width:100%;display:none;object-fit:cover;max-height:280px;border-top:1px solid #e4e6eb;">
                                <div style="border-top:1px solid #e4e6eb;display:flex;">
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-thumbs-up"></i> Like
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-comment"></i> Comment
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="fas fa-share"></i> Share
                                    </div>
                                </div>
                            </div>
                            <p style="font-size:0.72rem;color:#aaa;margin-top:6px;text-align:center;font-style:italic;">
                                Approximate preview &mdash; actual appearance may vary
                            </p>
                        </div>
                    </div>
                    <div id="fbConfFeedback" class="admin-modal-feedback" style="margin-top:14px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" id="fbConfSubmitBtn" class="btn fb-btn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbConferenceModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Facebook Share All Conference Rooms Modal -->
        <div class="modal-overlay" id="fbAllConferenceModal" style="display:none;" onclick="if(event.target===this)closeFbAllConferenceModal()">
            <div class="modal-content" style="max-width:920px;width:96vw;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 style="color:#1877F2;display:flex;align-items:center;gap:8px;">
                        <i class="fab fa-facebook-f"></i> Share All Conference Rooms on Facebook
                    </h3>
                    <button class="modal-close" type="button" onclick="closeFbAllConferenceModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding:20px 24px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:24px;align-items:start;">

                        <!-- LEFT: Room picker + caption editor -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:10px;color:#1c1e21;">
                                <i class="fas fa-check-square" style="color:#1877F2;margin-right:6px;"></i>
                                Choose conference rooms to include
                            </div>
                            <div class="fb-conf-select-list" id="fbAllConfRoomList"></div>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                                <button type="button" onclick="fcAllSelectAll(true)"
                                    style="background:none;border:1px solid #d1d5db;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.8rem;font-family:inherit;">
                                    Select all
                                </button>
                                <button type="button" onclick="fcAllSelectAll(false)"
                                    style="background:none;border:1px solid #d1d5db;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.8rem;font-family:inherit;">
                                    Clear all
                                </button>
                                <span id="fbAllConfRoomCount" style="color:#6b7280;font-size:0.82rem;margin-left:auto;">0 rooms</span>
                            </div>

                            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e5e7eb;">
                                <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                    <i class="fas fa-pencil-alt" style="color:#1877F2;margin-right:6px;font-size:0.8rem;"></i>
                                    Caption <small style="font-weight:400;color:#6b7280;">(edit freely before posting)</small>
                                </div>
                                <textarea id="fbAllConfCaption" rows="9"
                                    style="width:100%;resize:vertical;font-size:0.875rem;line-height:1.6;box-sizing:border-box;padding:10px 12px;border:1px solid #d0d7de;border-radius:6px;font-family:inherit;"
                                    placeholder="Your post caption will be generated here from the selected rooms&hellip;"></textarea>
                                <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;">
                                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:0.875rem;flex:1;">
                                        <input type="checkbox" id="fbAllConfIncludeImage" checked style="width:16px;height:16px;cursor:pointer;accent-color:#1877F2;">
                                        <i class="fas fa-image" style="color:#1877F2;font-size:0.85rem;"></i>
                                        Include featured room image
                                    </label>
                                    <span id="fbAllConfCharCount" style="font-size:0.75rem;color:#65676b;white-space:nowrap;">0 chars</span>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT: Live Facebook preview -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fab fa-facebook-f" style="color:#1877F2;margin-right:6px;"></i>
                                Live post preview
                            </div>
                            <div style="border:1px solid #e4e6eb;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
                                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px 8px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;">
                                        <i class="fab fa-facebook-f"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div id="fbAllConfPreviewPageName" style="font-weight:700;font-size:0.88rem;color:#1c1e21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            Your Page
                                        </div>
                                        <div style="font-size:0.72rem;color:#65676b;">
                                            Just now &middot; <i class="fas fa-globe-africa" style="font-size:0.65rem;"></i>
                                        </div>
                                    </div>
                                </div>
                                <div id="fbAllConfPreviewText"
                                    style="padding:0 16px 10px;font-size:0.875rem;line-height:1.55;color:#1c1e21;word-break:break-word;white-space:pre-wrap;max-height:340px;overflow-y:auto;">
                                    <em style="color:#65676b;font-style:italic;">Select rooms above to build the preview&hellip;</em>
                                </div>
                                <img id="fbAllConfPreviewImg" src="" alt="Conference room image"
                                    style="width:100%;display:none;object-fit:cover;max-height:240px;border-top:1px solid #e4e6eb;">
                                <div style="border-top:1px solid #e4e6eb;display:flex;">
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-thumbs-up"></i> Like
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-comment"></i> Comment
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="fas fa-share"></i> Share
                                    </div>
                                </div>
                            </div>
                            <p style="font-size:0.72rem;color:#aaa;margin-top:6px;text-align:center;font-style:italic;">
                                Approximate preview &mdash; actual appearance may vary on Facebook
                            </p>
                        </div>
                    </div>
                    <div id="fbAllConfFeedback" class="admin-modal-feedback" style="margin-top:14px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn fb-btn" id="fbAllConfSubmitBtn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbAllConferenceModal()">Cancel</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</body>

</html>

