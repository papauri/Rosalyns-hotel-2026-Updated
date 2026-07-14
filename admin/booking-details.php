<?php

/**
 * Booking Details Page
 * Comprehensive booking management with folio/charges and invoice generation
 */
require_once 'admin-init.php';
/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

// Currency symbol is referenced in POST handlers (success messages) and in HTML output below.
// Load it once, early, so it is always defined no matter which branch runs.
$currency_symbol = getSetting('currency_symbol');

$raw_booking_id = $_GET['id'] ?? ($_GET['booking_id'] ?? 0);
$booking_id = filter_var($raw_booking_id, FILTER_VALIDATE_INT);

if (!$booking_id) {
    $_SESSION['error_message'] = 'Invalid booking details request.';
    header('Location: bookings.php');
    exit;
}

// Include timeline functions
require_once '../includes/booking-timeline.php';
require_once '../includes/finance-sequences.php';
require_once __DIR__ . '/includes/booking-lifecycle.php';
finance_ensure_sequence_tables($pdo);

// Get folio charges for this booking
$folio_charges = getBookingCharges($booking_id, true); // Include voided for display
$folio_summary = getBookingFolioSummary($booking_id);

// Get menu items for quick-add (grouped by category)
$food_menu_items = getMenuItemsForFolio('food');
$drink_menu_items = getMenuItemsForFolio('drink');
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Lightweight early booking fetch so action handlers can run lifecycle guards
// before the full $booking array is populated later.
$_early_booking = null;
try {
    $_eb = $pdo->prepare("SELECT id, status, amount_paid, amount_due, total_amount FROM bookings WHERE id = ?");
    $_eb->execute([$booking_id]);
    $_early_booking = $_eb->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { /* will fail gracefully below */ }

// CSRF guard - covers all 5 state-changing POST handlers on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Security token invalid. Refresh the page.']);
            exit;
        }
        $_SESSION['error_message'] = 'Security token invalid. Refresh the page.';
        header('Location: booking-details.php?id=' . $booking_id);
        exit;
    }
}

// Handle charge actions (POST-only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['charge_action'])) {
    $action = $_POST['charge_action'];

    // Lifecycle guard — block charge mutations on terminal / closed bookings
    $lcAction = ($action === 'void_charge') ? 'void_charge' : 'add_charge';
    if ($_early_booking) {
        $lcCheck = bookingAllowsAction($_early_booking, $lcAction);
        if (!$lcCheck['allowed']) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $lcCheck['reason']]);
                exit;
            }
            $_SESSION['error_message'] = $lcCheck['reason'];
            header('Location: booking-details.php?id=' . $booking_id . '#folio');
            exit;
        }
    }

    try {
        switch ($action) {
            case 'add_charge':
                $description = trim($_POST['description'] ?? '');
                $charge_type = $_POST['charge_type'] ?? 'custom';
                $quantity = (float)($_POST['quantity'] ?? 1);
                $unit_price = (float)($_POST['unit_price'] ?? 0);

                if (empty($description)) {
                    $_SESSION['error_message'] = 'Please provide a description for the charge.';
                } elseif ($unit_price < 0) {
                    $_SESSION['error_message'] = 'Unit price cannot be negative.';
                } else {
                    $result = addBookingCharge($booking_id, $charge_type, $description, $quantity, $unit_price, null, $user['id']);
                    if ($result['success']) {
                        $_SESSION['success_message'] = "Charge added successfully. Line total: {$currency_symbol}" . number_format($result['line_total'], 2);
                    } else {
                        $_SESSION['error_message'] = 'Failed to add charge: ' . $result['message'];
                    }
                }
                break;

            case 'add_menu_item':
                $menu_type = $_POST['menu_type'] ?? 'food';
                $menu_item_id = (int)($_POST['menu_item_id'] ?? 0);
                $quantity = (float)($_POST['quantity'] ?? 1);

                if ($menu_item_id <= 0) {
                    $_SESSION['error_message'] = 'Please select a menu item.';
                } elseif ($quantity <= 0) {
                    $_SESSION['error_message'] = 'Quantity must be greater than 0.';
                } else {
                    $result = addBookingChargeFromMenu($booking_id, $menu_type, $menu_item_id, $quantity, $user['id']);
                    if ($result['success']) {
                        $_SESSION['success_message'] = "Menu item added to folio. Line total: {$currency_symbol}" . number_format($result['line_total'], 2);
                    } else {
                        $_SESSION['error_message'] = 'Failed to add menu item: ' . $result['message'];
                    }
                }
                break;

            case 'void_charge':
                $charge_id = (int)($_POST['charge_id'] ?? 0);
                $void_reason = trim($_POST['void_reason'] ?? '');

                if ($charge_id <= 0) {
                    $_SESSION['error_message'] = 'Invalid charge ID.';
                } elseif (empty($void_reason)) {
                    $_SESSION['error_message'] = 'Please provide a reason for voiding the charge.';
                } else {
                    $result = voidBookingCharge($charge_id, $void_reason, $user['id']);
                    if ($result['success']) {
                        $_SESSION['success_message'] = 'Charge voided successfully.';
                    } else {
                        $_SESSION['error_message'] = 'Failed to void charge: ' . $result['message'];
                    }
                }
                break;
        }

        // Refresh data after changes
        $folio_charges = getBookingCharges($booking_id, true);
        $folio_summary = getBookingFolioSummary($booking_id);

        if ($isAjax) {
            $ajaxMsg = $_SESSION['success_message'] ?? ($_SESSION['error_message'] ?? 'Unknown error');
            $ajaxOk  = isset($_SESSION['success_message']);
            unset($_SESSION['success_message'], $_SESSION['error_message']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ajaxOk, 'message' => $ajaxMsg]);
            exit;
        }
        // Redirect to prevent form resubmission
        header('Location: booking-details.php?id=' . $booking_id . '#folio');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Handle invoice generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoice_action'])) {
    $invoice_action = $_POST['invoice_action'];

    // Lifecycle guard — block invoice actions on tentative / terminal bookings
    $lcInvoiceAction = in_array($invoice_action, ['send_invoice', 'send_invoice_whatsapp'], true)
        ? 'send_invoice' : 'generate_invoice';
    if ($_early_booking) {
        $lcCheck = bookingAllowsAction($_early_booking, $lcInvoiceAction);
        if (!$lcCheck['allowed']) {
            $_SESSION['error_message'] = $lcCheck['reason'];
            header('Location: booking-details.php?id=' . $booking_id . '#invoices');
            exit;
        }
    }

    try {
        switch ($invoice_action) {
            case 'generate_invoice':
                // Generate invoice without sending
                require_once '../config/invoice.php';
                $invoice_result = generateInvoicePDF($booking_id);
                if ($invoice_result) {
                    // Update booking with invoice details if not exists
                    $updateStmt = $pdo->prepare("
                        UPDATE bookings
                        SET final_invoice_path = ?,
                            final_invoice_number = COALESCE(final_invoice_number, ?),
                            updated_at = NOW()
                        WHERE id = ? AND final_invoice_path IS NULL
                    ");
                    $updateStmt->execute([$invoice_result['relative_path'], $invoice_result['invoice_number'], $booking_id]);

                    $_SESSION['success_message'] = "Invoice generated successfully: {$invoice_result['invoice_number']}";
                } else {
                    $_SESSION['error_message'] = 'Failed to generate invoice.';
                }
                break;

            case 'send_invoice':
                // Generate and send invoice
                require_once '../config/invoice.php';

                // Get invoice recipients
                $cc_recipients = [];
                $invoice_recipients = getEmailSetting('invoice_recipients', '');
                $smtp_username = getEmailSetting('smtp_username', '');

                if (!empty($invoice_recipients)) {
                    $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
                }
                if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
                    $cc_recipients[] = $smtp_username;
                }

                $result = sendPaymentInvoiceEmailWithCC($booking_id, $cc_recipients);

                if ($result['success']) {
                    logBookingAudit($booking_id, 'email_sent', null, null, 'Invoice email sent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')');
                    $_SESSION['success_message'] = "Invoice sent successfully to guest." .
                        (!empty($result['cc_recipients']) ? " CC: " . implode(', ', $result['cc_recipients']) : '');
                } else {
                    $_SESSION['error_message'] = 'Failed to send invoice: ' . $result['message'];
                }
                break;

            case 'regenerate_invoice':
                // Force regenerate invoice
                require_once '../config/invoice.php';
                $invoice_result = generateInvoicePDF($booking_id);
                if ($invoice_result) {
                    // Update booking with new invoice
                    $updateStmt = $pdo->prepare("
                        UPDATE bookings
                        SET final_invoice_path = ?,
                            final_invoice_number = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$invoice_result['relative_path'], $invoice_result['invoice_number'], $booking_id]);

                    $_SESSION['success_message'] = "Invoice regenerated: {$invoice_result['invoice_number']}";
                } else {
                    $_SESSION['error_message'] = 'Failed to regenerate invoice.';
                }
                break;

            case 'send_invoice_whatsapp':
                // Send invoice link to guest via WhatsApp
                require_once '../config/invoice.php';
                require_once '../includes/whatsapp-functions.php';

                if (!isWhatsAppEnabled()) {
                    $_SESSION['error_message'] = 'WhatsApp notifications are disabled. Enable them in WhatsApp Settings.';
                    break;
                }

                // Ensure the invoice exists first
                $waBookingStmt = $pdo->prepare("
                    SELECT guest_name, guest_phone, booking_reference,
                           final_invoice_path, final_invoice_number,
                           check_in_date, check_out_date, total_amount
                    FROM bookings WHERE id = ?
                ");
                $waBookingStmt->execute([$booking_id]);
                $waBooking = $waBookingStmt->fetch(PDO::FETCH_ASSOC);

                if (!$waBooking) {
                    $_SESSION['error_message'] = 'Booking not found.';
                    break;
                }

                // Auto-generate invoice if missing
                if (empty($waBooking['final_invoice_path'])) {
                    $inv = generateInvoicePDF($booking_id);
                    if ($inv) {
                        $pdo->prepare("UPDATE bookings SET final_invoice_path = ?, final_invoice_number = COALESCE(final_invoice_number, ?), updated_at = NOW() WHERE id = ? AND final_invoice_path IS NULL")
                            ->execute([$inv['relative_path'], $inv['invoice_number'], $booking_id]);
                        $waBooking['final_invoice_path']   = $inv['relative_path'];
                        $waBooking['final_invoice_number'] = $inv['invoice_number'];
                    }
                }

                $guestPhone = $waBooking['guest_phone'] ?? '';
                if (empty($guestPhone)) {
                    $_SESSION['error_message'] = 'This booking has no guest phone number on record.';
                    break;
                }

                $invoiceUrl = rtrim(BASE_URL, '/') . '/' . ltrim($waBooking['final_invoice_path'] ?? '', '/');
                $invoiceRef = $waBooking['final_invoice_number'] ?? $waBooking['booking_reference'];
                $hotelName  = getSetting('hotel_name', 'Hotel');
                $guestName  = $waBooking['guest_name'] ?? 'Guest';

                $waMsg = "Dear {$guestName},\n\n"
                    . "Please find your invoice ({$invoiceRef}) for your stay at {$hotelName}.\n\n"
                    . "Check-in: " . date('d M Y', strtotime($waBooking['check_in_date'])) . "\n"
                    . "Check-out: " . date('d M Y', strtotime($waBooking['check_out_date'])) . "\n"
                    . "Total: " . getSetting('currency_symbol', 'MWK') . ' ' . number_format((float) $waBooking['total_amount'], 2) . "\n\n"
                    . "Download invoice: {$invoiceUrl}\n\n"
                    . "Thank you for staying with us!";

                $waResult = sendWhatsAppMessage($guestPhone, $waMsg);

                if ($waResult['success']) {
                    logBookingAudit($booking_id, 'email_sent', null, null, 'Invoice sent via WhatsApp to ' . $guestPhone, $waBooking['booking_reference']);
                    $_SESSION['success_message'] = "Invoice sent via WhatsApp to {$guestPhone}.";
                } else {
                    $_SESSION['error_message'] = 'WhatsApp send failed: ' . ($waResult['message'] ?? 'Unknown error');
                }
                break;
        }

        header('Location: booking-details.php?id=' . $booking_id . '#invoices');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Invoice error: ' . $e->getMessage();
    }
}

// Handle status changes (POST-only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    $action = $_POST['booking_action'];

    try {
        switch ($action) {
            case 'convert':
                // Convert tentative booking to confirmed
                $stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name, r.slug as room_slug
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE b.id = ?
                ");
                $stmt->execute([$booking_id]);
                $booking_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking_data) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } elseif ($booking_data['status'] !== 'tentative' || $booking_data['is_tentative'] != 1) {
                    $_SESSION['error_message'] = 'This is not a tentative booking.';
                } else {
                    // Convert to confirmed and clear tentative fields
                    $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, tentative_expires_at = NULL, updated_at = NOW() WHERE id = ?");
                    $update->execute([$booking_id]);

                    // Decrement room availability — tentative bookings don't consume rooms_available,
                    // but confirmed bookings do; apply the same decrement as pending→confirmed.
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                        ->execute([$booking_data['room_id']]);

                    $auto_assign_msg = '';
                    if (in_array($booking_data['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking_data['individual_room_id'])) {
                        $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                        if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                            $auto_assign_msg = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                        } elseif (!$autoAssignResult['success']) {
                            $auto_assign_msg = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                        }
                    }

                    // Log to timeline
                    logTentativeConversion($booking_id, $booking_data['booking_reference'], 'admin', $user['id'], $user['full_name']);

                    // Send conversion email
                    require_once '../config/email.php';
                    $email_result = sendTentativeBookingConvertedEmail($booking_data);

                    $_SESSION['success_message'] = 'Tentative booking converted to confirmed!' . $auto_assign_msg .
                        ($email_result['success'] ? ' Confirmation email sent.' : ' (Email failed: ' . $email_result['message'] . ')');
                }
                break;

            case 'confirm':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND status = 'pending'");
                $stmt->execute([$booking_id]);
                $confirm_changed = $stmt->rowCount() > 0;

                if (!$confirm_changed) {
                    $_SESSION['error_message'] = 'Only pending bookings can be confirmed.';
                    break;
                }

                // Decrement room availability and get booking details
                $room_stmt = $pdo->prepare("SELECT room_id, booking_reference, payment_status, individual_room_id FROM bookings WHERE id = ?");
                $room_stmt->execute([$booking_id]);
                $booking_room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                $auto_assign_msg = '';
                if ($booking_room) {
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                        ->execute([$booking_room['room_id']]);

                    // Auto-assign individual room only when this booking is confirmed and paid.
                    if (in_array($booking_room['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking_room['individual_room_id'])) {
                        $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                        if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                            $auto_assign_msg = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                        } elseif (!$autoAssignResult['success']) {
                            $auto_assign_msg = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                        }
                    }

                    // Log to timeline
                    logBookingStatusChange($booking_id, $booking_room['booking_reference'], 'pending', 'confirmed', 'admin', $user['id'], $user['full_name']);
                }

                // Send confirmation email
                require_once '../config/email.php';
                $conf_stmt = $pdo->prepare("SELECT b.*, r.name as room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
                $conf_stmt->execute([$booking_id]);
                $conf_booking = $conf_stmt->fetch(PDO::FETCH_ASSOC);
                if ($conf_booking) {
                    $email_result = sendBookingConfirmedEmail($conf_booking);
                    $_SESSION['success_message'] = 'Booking confirmed.' . ($email_result['success'] ? ' Confirmation email sent.' : '') . $auto_assign_msg;
                }
                break;

            case 'checkin':
                $check_stmt = $pdo->prepare("SELECT b.status, b.payment_status, b.individual_room_id, b.room_id, b.booking_reference, b.check_in_date, ir.status as room_status FROM bookings b LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE b.id = ?");
                $check_stmt->execute([$booking_id]);
                $check_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$check_row) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } else {
                    $validation = validateCheckIn($check_row);
                    if (!$validation['allowed']) {
                        $_SESSION['error_message'] = getBookingActionErrorMessage('check_in', $validation['reason']);
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE bookings SET status = 'checked-in', updated_at = NOW() WHERE id = ?")->execute([$booking_id]);
                        $updatedRoomCount = updateBookingRoomsStatus($booking_id, 'occupied', 'Guest checked in', $user['id'] ?? null);
                        $pdo->commit();

                        // Timeline event (outside transaction — non-fatal if it fails)
                        logBookingCheckIn($booking_id, $check_row['booking_reference'], 'admin', $user['id'], $user['full_name']);

                        // Late check-in detection for audit trail
                        $ciScheduled = (new DateTime((string)$check_row['check_in_date']))->setTime(0, 0, 0);
                        $ciToday = new DateTime('today');
                        $isLateCI = $ciScheduled < $ciToday;
                        $lateCIDays = $isLateCI ? (int)$ciScheduled->diff($ciToday)->days : 0;
                        $lateAuditNote = $isLateCI ? 'Late check-in (' . $lateCIDays . ' day(s) overdue)' : null;

                        logBookingAudit(
                            $booking_id,
                            'checked-in',
                            ['status' => 'confirmed'],
                            ['status' => 'checked-in'],
                            $lateAuditNote,
                            $check_row['booking_reference']
                        );
                        rh_log_event('bookings', $isLateCI ? 'warning' : 'info',
                            $isLateCI ? 'Late guest check-in' : 'Guest checked in',
                            ['booking_id' => $booking_id, 'ref' => $check_row['booking_reference'], 'by' => $user['full_name'] ?? $user['username']]
                        );

                        $successMsg = 'Guest checked in successfully.';
                        if ($updatedRoomCount > 0) $successMsg .= ' Room marked as occupied.';
                        if ($isLateCI) $successMsg .= ' Note: late check-in (' . $lateCIDays . ' day(s) overdue).';
                        $_SESSION['success_message'] = $successMsg;
                    }
                }
                break;

            case 'checkout':
                $checkout_stmt = $pdo->prepare("SELECT b.room_id, b.individual_room_id, b.booking_reference, b.check_out_date, b.status, ir.status as room_status FROM bookings b LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE b.id = ?");
                $checkout_stmt->execute([$booking_id]);
                $checkout_row = $checkout_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$checkout_row) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } else {
                    $validation = validateCheckOut($checkout_row);
                    if (!$validation['allowed']) {
                        $_SESSION['error_message'] = getBookingActionErrorMessage('check_out', $validation['reason']);
                    } else {
                        $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = NOW(), updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$booking_id]);

                        logBookingCheckOut($booking_id, $checkout_row['booking_reference'], 'admin', $user['id'], $user['full_name']);

                        // Restore room availability
                        $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                            ->execute([$checkout_row['room_id']]);

                        $updatedRoomCount = updateBookingRoomsStatus($booking_id, 'cleaning', 'Guest checked out', $user['id'] ?? null);

                        // Generate and send final invoice
                        require_once '../config/invoice.php';
                        $invoice_result = generateAndSendFinalInvoice($booking_id, $user['id']);

                        $checkout_message = 'Guest checked out successfully. Room availability restored.' .
                            ($updatedRoomCount > 0 ? ' Room assignment marked for cleaning.' : '');

                        if ($invoice_result['success']) {
                            if (!$invoice_result['idempotent']) {
                                $checkout_message .= ' Final invoice generated.';
                            }
                            if (!$invoice_result['email_sent']) {
                                $checkout_message .= ' Note: Final invoice email could not be sent.';
                            }
                        } else {
                            $checkout_message .= ' Warning: Failed to generate final invoice.';
                        }

                        $_SESSION['success_message'] = $checkout_message;
                    }
                }
                break;

            case 'noshow':
                $check_stmt = $pdo->prepare("
                    SELECT b.*, ir.status as room_status
                    FROM bookings b
                    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                    WHERE b.id = ?
                ");
                $check_stmt->execute([$booking_id]);
                $noshow_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$noshow_row) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } else {
                    $todayNoShow = new DateTime('today');
                    $checkInDateNoShow = new DateTime((string)($noshow_row['check_in_date'] ?? 'today'));
                    $checkInDateNoShow->setTime(0, 0, 0);

                    if ($checkInDateNoShow >= $todayNoShow) {
                        $_SESSION['error_message'] = 'No-show can only be marked after the check-in date has passed.';
                    } else {
                        $transitionValidation = validateBookingStatusTransition($noshow_row['status'], 'no-show');
                        if (!$transitionValidation['allowed']) {
                            $_SESSION['error_message'] = getBookingActionErrorMessage('noshow', $transitionValidation['reason']);
                        } else {
                            $stmt = $pdo->prepare("UPDATE bookings SET status = 'no-show', updated_at = NOW() WHERE id = ?");
                            $stmt->execute([$booking_id]);

                            logBookingEvent(
                                $booking_id,
                                $noshow_row['booking_reference'],
                                'Guest marked as no-show',
                                'status_change',
                                'Guest did not arrive - marked as no-show',
                                $noshow_row['status'],
                                'no-show',
                                'admin',
                                $user['id'],
                                $user['full_name']
                            );

                            if ($noshow_row['status'] === 'confirmed') {
                                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                                    ->execute([$noshow_row['room_id']]);
                            }

                            updateBookingRoomsStatus($booking_id, 'available', 'Booking marked as no-show', $user['id'] ?? null);

                            // Auto-refund (if policy configured) and guest email
                            $refund_result = ['created' => false, 'refund_ref' => '', 'refund_amount' => 0.0];
                            if ((float)($noshow_row['amount_paid'] ?? 0) > 0) {
                                require_once __DIR__ . '/../includes/booking-functions.php';
                                $refund_result = createNoShowRefund($noshow_row, (int) $user['id'], $pdo);
                            }

                            $email_result = ['success' => false, 'message' => 'No guest email on record'];
                            if (!empty($noshow_row['guest_email'])) {
                                require_once __DIR__ . '/../config/email.php';
                                $email_result = sendNoShowEmail(
                                    $noshow_row,
                                    (float)($refund_result['refund_amount'] ?? 0.0),
                                    (string)($refund_result['refund_ref'] ?? '')
                                );
                            }

                            $msg = 'Booking marked as no-show.';
                            if ($refund_result['created']) {
                                $msg .= ' Pending refund ' . $refund_result['refund_ref']
                                    . ' (' . getSetting('currency_symbol', 'MWK') . ' ' . number_format((float) $refund_result['refund_amount'], 2) . ') queued - review in Payments.';
                            }
                            $msg .= $email_result['success'] ? ' No-show email sent to guest.' : ' (No-show email could not be sent.)';
                            $_SESSION['success_message'] = $msg;
                        }
                    }
                }
                break;

            case 'cancel':
                $booking_stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name, ir.status as individual_room_status
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                    WHERE b.id = ?
                ");
                $booking_stmt->execute([$booking_id]);
                $booking_to_cancel = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking_to_cancel) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } else {
                    $validation = validateBookingCancellation($booking_to_cancel);
                    if (!$validation['allowed']) {
                        $_SESSION['error_message'] = getBookingActionErrorMessage('cancel', $validation['reason']);
                    } else {
                        $previous_status = $booking_to_cancel['status'];
                        $cancellation_reason = $_POST['cancellation_reason'] ?? 'Cancelled by admin';

                        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$booking_id]);

                        logBookingStatusChange($booking_id, $booking_to_cancel['booking_reference'], $previous_status, 'cancelled', 'admin', $user['id'], $user['full_name'], $cancellation_reason);

                        if ($previous_status === 'confirmed') {
                            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                                ->execute([$booking_to_cancel['room_id']]);
                        }

                        updateBookingRoomsStatus($booking_id, 'available', 'Booking cancelled: ' . $cancellation_reason, $user['id'] ?? null);

                        // Refund accounting: if any completed payment exists, create a refund record.
                        $canPay_stmt = $pdo->prepare("
                            SELECT SUM(total_amount) as total_paid
                            FROM payments
                            WHERE booking_type = 'room' AND booking_id = ?
                              AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                              AND deleted_at IS NULL
                        ");
                        $canPay_stmt->execute([$booking_id]);
                        $cancel_paid_total = (float)(($canPay_stmt->fetch(PDO::FETCH_ASSOC))['total_paid'] ?? 0);

                        $cancel_refund_msg = '';
                        if ($cancel_paid_total > 0) {
                            do {
                                $cancel_refund_ref = 'RFD-CAN-' . strtoupper(substr(uniqid(), -8));
                                $canRefChk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                                $canRefChk->execute([$cancel_refund_ref]);
                            } while ((int)$canRefChk->fetchColumn() > 0);

                            $vatEnabled_can = getSetting('vat_enabled') === '1';
                            $vatRate_can    = $vatEnabled_can ? (float)getSetting('vat_rate') : 0;
                            $vatAmt_can     = $vatRate_can > 0
                                ? round($cancel_paid_total * ($vatRate_can / (100 + $vatRate_can)), 2)
                                : 0;
                            $netAmt_can     = round($cancel_paid_total - $vatAmt_can, 2);

                            $pdo->prepare("
                                INSERT INTO payments (
                                    payment_reference, booking_type, booking_id, booking_reference,
                                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                    payment_method, payment_type, payment_status,
                                    refund_reason, refund_status, refund_amount,
                                    recorded_by, created_at
                                ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'refund', 'completed',
                                          'cancellation', 'completed', ?, ?, NOW())
                            ")->execute([
                                $cancel_refund_ref,
                                $booking_id,
                                $booking_to_cancel['booking_reference'],
                                $netAmt_can,
                                $vatRate_can,
                                $vatAmt_can,
                                $cancel_paid_total,
                                $cancel_paid_total,
                                (int)($user['id'] ?? 0),
                            ]);

                            $cancel_refund_msg = ' Refund of ' . ($currency_symbol ?? 'MWK') . ' '
                                . number_format($cancel_paid_total, 2) . ' recorded (Ref: ' . $cancel_refund_ref . ').';
                        }

                        recalculateBookingFinancials($booking_id);
                        if ($cancel_paid_total > 0) {
                            $pdo->prepare("UPDATE bookings SET payment_status = 'refunded', updated_at = NOW() WHERE id = ?")
                                ->execute([$booking_id]);
                        }

                        require_once '../config/email.php';
                        $email_result = sendBookingCancelledEmail($booking_to_cancel, $cancellation_reason);

                        $_SESSION['success_message'] = 'Booking cancelled.' . $cancel_refund_msg .
                            ($email_result['success'] ? ' Cancellation email sent.' : ' (Email failed)');
                    }
                }
                break;
        }
    } catch (\Throwable $e) {
        $_SESSION['error_message'] = 'Action failed. Please try again.';
        error_log("Booking action error: " . $e->getMessage());
    }

    header('Location: booking-details.php?id=' . $booking_id);
    exit;
}

// Fetch booking details
try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               COALESCE(r.name, 'Unknown room type') as room_name,
               COALESCE(r.price_per_night, (b.total_amount / NULLIF(b.number_of_nights, 0)), 0) as price_per_night,
               COALESCE(p.payment_status, b.payment_status) as actual_payment_status,
               p.payment_reference,
               p.payment_date as last_payment_date,
               p.payment_amount,
               p.vat_rate,
               p.vat_amount,
               p.total_amount as payment_total_with_vat,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               ir.floor as individual_room_floor,
               ir.status as individual_room_status,
               rt.name as room_type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $_SESSION['error_message'] = 'Booking not found.';
        header('Location: bookings.php');
        exit;
    }

    // ── Returning guest history ──────────────────────────────────────
    $guest_history = ['total_bookings' => 0, 'completed_stays' => 0, 'lifetime_spend' => 0.0, 'bookings' => []];
    if (!empty($booking['guest_email'])) {
        try {
            $ghStmt = $pdo->prepare("
                SELECT id, booking_reference, check_in_date, check_out_date, total_amount, status
                FROM bookings
                WHERE guest_email = :email
                  AND id != :current_id
                ORDER BY check_in_date DESC
                LIMIT 6
            ");
            $ghStmt->execute([':email' => $booking['guest_email'], ':current_id' => $booking_id]);
            $past_bookings = $ghStmt->fetchAll(PDO::FETCH_ASSOC);

            $cntStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_bookings,
                    SUM(CASE WHEN status NOT IN ('cancelled','no-show','expired') THEN 1 ELSE 0 END) AS completed_stays,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','no-show','expired') THEN total_amount ELSE 0 END), 0) AS lifetime_spend
                FROM bookings
                WHERE guest_email = :email
                  AND id != :current_id
            ");
            $cntStmt->execute([':email' => $booking['guest_email'], ':current_id' => $booking_id]);
            $ghCounts = $cntStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $guest_history = [
                'total_bookings'  => (int)($ghCounts['total_bookings']  ?? 0),
                'completed_stays' => (int)($ghCounts['completed_stays'] ?? 0),
                'lifetime_spend'  => (float)($ghCounts['lifetime_spend'] ?? 0),
                'bookings'        => $past_bookings,
            ];
        } catch (\Throwable $e) {
            error_log('Guest history query: ' . $e->getMessage());
        }
    }

    // Derive room status from booking status if no individual room assigned
    if (empty($booking['individual_room_status'])) {
        $booking_status = $booking['status'];
        $status_mapping = [
            'pending' => 'available',
            'confirmed' => 'available',
            'checked-in' => 'occupied',
            'checked-out' => 'cleaning',
            'cancelled' => 'available',
            'no-show' => 'available'
        ];
        $booking['derived_room_status'] = $status_mapping[$booking_status] ?? 'available';
    } else {
        $booking['derived_room_status'] = $booking['individual_room_status'];
    }

    // ── Group booking siblings ───────────────────────────────────────
    $group_bookings = [];
    $primary_id_for_group = !empty($booking['primary_booking_id'])
        ? (int)$booking['primary_booking_id']
        : (int)$booking['id'];
    $sib_stmt = $pdo->prepare("
        SELECT b.id, b.booking_reference, b.status, b.number_of_guests,
               b.total_with_vat, b.primary_booking_id,
               COALESCE(r.name, 'Unknown') AS room_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE (b.id = :pid OR b.primary_booking_id = :pid2)
          AND b.id != :current
        ORDER BY b.id ASC
    ");
    $sib_stmt->execute([':pid' => $primary_id_for_group, ':pid2' => $primary_id_for_group, ':current' => $booking_id]);
    $group_bookings = $sib_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch booking notes
    $notes_stmt = $pdo->prepare("
        SELECT n.*, u.full_name as created_by_name
        FROM booking_notes n
        LEFT JOIN admin_users u ON n.created_by = u.id
        WHERE n.booking_id = ?
        ORDER BY n.created_at DESC
    ");
    $notes_stmt->execute([$booking_id]);
    $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch booking timeline
    $timeline = getBookingTimeline($booking_id);

    // Fetch existing invoices for this booking
    $invoices_stmt = $pdo->prepare("
        SELECT id, invoice_number, invoice_path, invoice_generated, created_at
        FROM payments
        WHERE booking_type = 'room' AND booking_id = ? AND invoice_generated = 1
        ORDER BY created_at DESC
    ");
    $invoices_stmt->execute([$booking_id]);
    $existing_invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: also surface invoice stored directly on the booking (generated without a payment)
    if (empty($existing_invoices) && !empty($booking['final_invoice_path'])) {
        $existing_invoices[] = [
            'id'               => null,
            'invoice_number'   => $booking['final_invoice_number'] ?? 'INV',
            'invoice_path'     => $booking['final_invoice_path'],
            'invoice_generated' => 1,
            'created_at'       => $booking['updated_at'] ?? date('Y-m-d H:i:s'),
        ];
    }

    // Fetch booked packages for this booking
    $booking_packages = [];
    try {
        $bp_stmt = $pdo->prepare("SELECT * FROM booking_packages WHERE booking_id = ? ORDER BY id ASC");
        $bp_stmt->execute([$booking_id]);
        $booking_packages = $bp_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("booking_packages fetch error: " . $e->getMessage());
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Unable to load booking details.';
    header('Location: bookings.php');
    exit;
}

// Build permission map used throughout the template
$bPerms = getBookingPermissions($booking);

// Handle note submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note_text = trim($_POST['note_text'] ?? '');

    if ($note_text) {
        try {
            $insert_stmt = $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)");
            $insert_stmt->execute([$booking_id, $note_text, $user['id']]);

            logBookingNote($booking_id, $booking['booking_reference'], $note_text, $user['id'], $user['full_name']);

            $_SESSION['success_message'] = 'Note added successfully.';
            header('Location: booking-details.php?id=' . $booking_id);
            exit;
        } catch (PDOException $e) {
            $error_message = 'Failed to add note.';
        }
    }
}

// Handle date adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_dates'])) {
    $new_check_in = trim($_POST['new_check_in'] ?? '');
    $new_check_out = trim($_POST['new_check_out'] ?? '');
    $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');

    if (empty($new_check_in) || empty($new_check_out)) {
        $_SESSION['error_message'] = 'Please provide both check-in and check-out dates.';
    } elseif (empty($adjustment_reason)) {
        $_SESSION['error_message'] = 'Please provide a reason for the date adjustment.';
    } else {
        // Capture old checkout for email before adjustment overwrites it
        $old_checkout_for_email = '';
        $pre_adj_stmt = $pdo->prepare("SELECT check_out_date, guest_email, guest_name, booking_reference, room_id, amount_paid FROM bookings WHERE id = ?");
        $pre_adj_stmt->execute([$booking_id]);
        $pre_adj_row = $pre_adj_stmt->fetch(PDO::FETCH_ASSOC);
        if ($pre_adj_row) {
            $old_checkout_for_email = $pre_adj_row['check_out_date'];
        }

        $result = processBookingDateAdjustment(
            $booking_id,
            $new_check_in,
            $new_check_out,
            $adjustment_reason,
            $user['id'],
            $user['full_name']
        );

        if ($result['success']) {
            $delta = $result['calculation']['amount_delta'];
            $delta_text = $delta >= 0
                ? "+{$currency_symbol}" . number_format(abs($delta), 2) . " additional amount due"
                : "-{$currency_symbol}" . number_format(abs($delta), 2) . " refund/credit";

            $message = "Stay dates adjusted successfully. {$delta_text}";

            // If extra amount is due, add a pending payment row so it appears in Payments
            if ($delta > 0.01) {
                $pay_ref = 'ADJ-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . '-' . time();
                $adj_pay_stmt = $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, payment_method, payment_type,
                        payment_status, status, notes, recorded_by
                    ) VALUES (?, 'room', ?, ?, CURDATE(), ?, 'pending', 'date_adjustment',
                              'pending', 'pending', ?, ?)
                ");
                $adj_pay_stmt->execute([
                    $pay_ref,
                    $booking_id,
                    $pre_adj_row['booking_reference'] ?? '',
                    $delta,
                    'Additional charge due to date adjustment. New check-out: ' . $new_check_out,
                    $user['id']
                ]);
                $message .= ' Payment record ' . $pay_ref . ' added - collect before check-out.';
            }

            // Add credit balance notification if applicable
            if (isset($result['credit_balance']) && $result['credit_balance'] > 0) {
                $message .= " Guest has a credit balance of {$currency_symbol}" . number_format($result['credit_balance'], 2) . ".";
            }

            // Email guest about the date change
            if (!empty($pre_adj_row['guest_email'])) {
                // Re-fetch the booking after the update so email reflects new dates
                $upd_stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
                $upd_stmt->execute([$booking_id]);
                $updated_bk = $upd_stmt->fetch(PDO::FETCH_ASSOC);
                if ($updated_bk) {
                    require_once '../config/email.php';
                    $email_result = sendExtendStayEmail($updated_bk, $delta, $old_checkout_for_email);
                    $message .= $email_result['success'] ? ' Guest notified by email.' : ' (Guest email could not be sent.)';
                }
            }

            $_SESSION['success_message'] = $message;
        } else {
            $_SESSION['error_message'] = $result['message'];
        }
    }

    if ($isAjax) {
        $ajaxMsg = $_SESSION['success_message'] ?? ($_SESSION['error_message'] ?? 'Unknown error');
        $ajaxOk  = isset($_SESSION['success_message']);
        unset($_SESSION['success_message'], $_SESSION['error_message']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ajaxOk, 'message' => $ajaxMsg]);
        exit;
    }
    header('Location: booking-details.php?id=' . $booking_id);
    exit;
}

// Handle payment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $payment_status = $_POST['payment_status'];
    $previous_status = $booking['payment_status'];

    try {
        $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $configuredVatRate = $vatEnabled ? (float) getSetting('vat_rate') : 0;

        $summaryForPayment = getBookingFolioSummary($booking_id);
        $extrasSubtotal = (float)($summaryForPayment['extras_subtotal'] ?? 0);
        $extrasVat = (float)($summaryForPayment['extras_vat'] ?? 0);

        $baseSubtotal = (float)($booking['total_amount'] ?? 0);
        $baseVat = (float)($booking['vat_amount'] ?? 0);
        if ($baseVat <= 0.0 && $configuredVatRate > 0.0) {
            $baseVat = round($baseSubtotal * ($configuredVatRate / 100), 2);
        }

        $paymentSubtotal = $baseSubtotal + $extrasSubtotal;
        $paymentVatAmount = $baseVat + $extrasVat;
        $paymentTotalWithVat = $paymentSubtotal + $paymentVatAmount;
        $paymentVatRate = $paymentSubtotal > 0 ? round(($paymentVatAmount / $paymentSubtotal) * 100, 2) : $configuredVatRate;

        $levyAmount = (float)($booking['tourism_levy_amount'] ?? 0);
        $levyPercent = (float)($booking['tourism_levy_percent'] ?? 0);
        $paymentNotes = 'Full settlement from booking details page.';
        if ($levyAmount > 0) {
            $paymentNotes .= ' Tourism levy included' . ($levyPercent > 0 ? ' (' . number_format($levyPercent, 2) . '%).' : '.');
        }

        $update_stmt = $pdo->prepare("UPDATE bookings SET payment_status = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$payment_status, $booking_id]);

        if ($payment_status === 'paid' && $previous_status !== 'paid') {
            $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
            $receipt_number = finance_next_receipt_number($pdo, date('Y-m-d'));

            $insert_payment = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, invoice_generated,
                    receipt_number, status, notes, recorded_by
                ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?, ?)
            ");
            $insert_payment->execute([
                $payment_reference,
                $booking_id,
                $booking['booking_reference'],
                $paymentSubtotal,
                $paymentVatRate,
                $paymentVatAmount,
                $paymentTotalWithVat,
                $receipt_number,
                $paymentNotes,
                $user['id']
            ]);
            $new_payment_id = (int)$pdo->lastInsertId();

            logBookingPayment($booking_id, $booking['booking_reference'], $paymentTotalWithVat, 'full_payment', 'cash', 'completed', $user['id'], $payment_reference);

            $update_amounts = $pdo->prepare("
                UPDATE bookings
                SET vat_rate = ?, vat_amount = ?, total_with_vat = ?, last_payment_date = CURDATE(), updated_at = NOW()
                WHERE id = ?
            ");
            $update_amounts->execute([$paymentVatRate, $paymentVatAmount, $paymentTotalWithVat, $booking_id]);

            if (function_exists('recalculateBookingFinancials')) {
                recalculateBookingFinancials($booking_id);
            }

            $auto_assign_msg = '';
            if (($booking['status'] ?? '') === 'confirmed' && empty($booking['individual_room_id'])) {
                $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                    $auto_assign_msg = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                } elseif (!$autoAssignResult['success']) {
                    $auto_assign_msg = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                }
            }

            require_once '../config/invoice.php';
            $invoice_result = sendPaymentInvoiceEmail($booking_id);

            require_once '../config/receipts.php';
            $receipt_result = receipt_auto_send($pdo, $new_payment_id, $user);

            $_SESSION['success_message'] = 'Payment status updated. Payment recorded.' . $auto_assign_msg .
                ($invoice_result['success'] ? ' Invoice sent!' : ' (Invoice email failed)') .
                ($receipt_result['success'] ? ' Receipt emailed.' : '');
        } else {
            $_SESSION['success_message'] = 'Payment status updated.';
        }

        header('Location: booking-details.php?id=' . $booking_id);
        exit;
    } catch (PDOException $e) {
        $error_message = 'Failed to update payment status: ' . $e->getMessage();
    }
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');

// Status styling
$status_colors = [
    'pending' => ['bg' => '#fef9ec', 'color' => '#92690a', 'icon' => 'fa-clock'],
    'tentative' => ['bg' => '#fff8e1', 'color' => '#8B7355', 'icon' => 'fa-hourglass-half'],
    'confirmed' => ['bg' => '#ecf8fd', 'color' => '#1a7a96', 'icon' => 'fa-check-circle'],
    'checked-in' => ['bg' => '#edf7f0', 'color' => '#1f7a42', 'icon' => 'fa-sign-in-alt'],
    'checked-out' => ['bg' => '#f3f4f5', 'color' => '#555c66', 'icon' => 'fa-sign-out-alt'],
    'cancelled' => ['bg' => '#fef2f2', 'color' => '#a03030', 'icon' => 'fa-times-circle'],
    'no-show' => ['bg' => '#f3e8e8', 'color' => '#6b4423', 'icon' => 'fa-user-slash'],
];
$current_status = $status_colors[$booking['status']] ?? ['bg' => '#f5f5f5', 'color' => '#666', 'icon' => 'fa-question'];

$vat_enabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
$configured_vat_rate = $vat_enabled ? (float)getSetting('vat_rate') : 0.0;

$folio_extras_subtotal = (float)($folio_summary['extras_subtotal'] ?? 0);
$folio_extras_vat = (float)($folio_summary['extras_vat'] ?? 0);
$booking_base_subtotal = (float)($booking['total_amount'] ?? 0);
$booking_base_vat = (float)($booking['vat_amount'] ?? 0);
if ($booking_base_vat <= 0.0 && $configured_vat_rate > 0.0) {
    $booking_base_vat = vat_components($booking_base_subtotal)['vat'];
}

// Inclusive mode: priced amounts already contain VAT — the folio total is the
// sum of priced amounts, never price + VAT again.
$vat_is_inclusive = function_exists('vat_mode') && vat_mode() === 'inclusive';
$folio_subtotal_before_vat = $vat_is_inclusive
    ? max(0.0, ($booking_base_subtotal - $booking_base_vat) + ($folio_extras_subtotal - $folio_extras_vat))
    : $booking_base_subtotal + $folio_extras_subtotal;
$folio_total_vat = $booking_base_vat + $folio_extras_vat;
$folio_total_amount = $vat_is_inclusive
    ? $booking_base_subtotal + $folio_extras_subtotal
    : $folio_subtotal_before_vat + $folio_total_vat;
$folio_amount_paid = (float)($folio_summary['amount_paid'] ?? $booking['amount_paid'] ?? 0);
$folio_balance_due = max(0.0, $folio_total_amount - $folio_amount_paid);
$booking_levy_amount = (float)($booking['tourism_levy_amount'] ?? 0);
$booking_levy_percent = (float)($booking['tourism_levy_percent'] ?? 0);
$booking_room_total_with_tax = $vat_is_inclusive
    ? $booking_base_subtotal
    : $booking_base_subtotal + $booking_base_vat;
$room_status_label = ucfirst(str_replace('_', ' ', (string)($booking['derived_room_status'] ?? 'available')));

$today_status_date = new DateTime('today');
$checkin_due_date = new DateTime((string) $booking['check_in_date']);
$checkin_due_date->setTime(0, 0, 0);
$checkout_due_date = new DateTime((string) $booking['check_out_date']);
$checkout_due_date->setTime(0, 0, 0);

$checkin_overdue_days = 0;
if (in_array((string) $booking['status'], ['confirmed', 'pending'], true) && $checkin_due_date < $today_status_date) {
    $checkin_overdue_days = (int) $checkin_due_date->diff($today_status_date)->days;
}

$checkout_overdue_days = 0;
if ((string) $booking['status'] === 'checked-in' && $checkout_due_date < $today_status_date) {
    $checkout_overdue_days = (int) $checkout_due_date->diff($today_status_date)->days;
}

$booking_alert_message = '';
$booking_alert_tone = 'info';
if ($checkin_overdue_days > 0) {
    $booking_alert_tone = 'warning';
    $booking_alert_message = 'Late check-in pending for ' . $checkin_overdue_days . ' day(s). Action required: check in or mark no-show.';
} elseif ($checkout_overdue_days > 0) {
    $booking_alert_tone = 'danger';
    $booking_alert_message = 'Checkout overdue by ' . $checkout_overdue_days . ' day(s). Process checkout or extend stay.';
} elseif ((string) $booking['status'] === 'tentative' || (int)($booking['is_tentative'] ?? 0) === 1) {
    $booking_alert_tone = 'info';
    $booking_alert_message = 'Tentative booking is awaiting conversion to confirmed status.';
}

$flash_success_message = (string)($_SESSION['success_message'] ?? '');
$flash_error_message = (string)($_SESSION['error_message'] ?? '');
if ($flash_error_message === '' && isset($error_message) && (string)$error_message !== '') {
    $flash_error_message = (string)$error_message;
}
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <title>Booking Details | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/booking-details.css?v=<?php echo @filemtime(__DIR__ . '/css/booking-details.css'); ?>">
    <style>
        :root {
            --bd-status-bg: <?php echo htmlspecialchars($current_status['bg']); ?>;
            --bd-status-color: <?php echo htmlspecialchars($current_status['color']); ?>;
        }
        /* Returning guest history */
        .gh-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; letter-spacing: .03em;
            border-radius: 12px; padding: 3px 10px; margin-bottom: 10px;
        }
        .gh-badge--returning { background: #d4f0dc; color: #1a6632; border: 1px solid #a3d5b3; }
        .gh-badge--new       { background: #e8f4e8; color: #2d6a2d; border: 1px solid #a8d4a8; }
        .gh-stats-row {
            display: grid; grid-template-columns: 1fr 1fr 1.5fr; gap: 8px; margin: 8px 0 10px;
        }
        .gh-stat {
            min-width: 0;
            background: #faf5ef; border: 1px solid #e8d9c4;
            border-radius: 6px; padding: 8px 10px; text-align: center;
        }
        .gh-stat-val { font-size: 16px; font-weight: 700; color: #2a2723; line-height: 1.2; word-break: break-word; overflow-wrap: anywhere; }
        .gh-stat-val--lifetime { font-size: 12px; }
        .gh-stat-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8a7a68; margin-top: 2px; }
        .gh-past-list { margin-top: 8px; }
        .gh-past-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 7px 0; border-bottom: 1px solid #f0ebe4; font-size: 12px;
        }
        .gh-past-item:last-child { border-bottom: none; }
        .gh-past-ref { font-weight: 600; color: #2a2723; }
        .gh-past-dates { color: #7a7068; }
        .gh-past-status {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            border-radius: 8px; padding: 2px 7px;
        }
        .gh-past-status.confirmed,
        .gh-past-status.checked_out,
        .gh-past-status.completed  { background:#d4edda; color:#1a6632; }
        .gh-past-status.checked_in { background:#cce5ff; color:#004085; }
        .gh-past-status.cancelled  { background:#f8d7da; color:#721c24; }
        .gh-past-status.pending    { background:#fff3cd; color:#856404; }
        .gh-past-status.no-show   { background:#f8d7da; color:#721c24; }
    </style>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="booking-details-page">

        <?php
        // Status banner for terminal and restricted states
        $statusBanner = null;
        switch ($booking['status']) {
            case 'cancelled':
                $statusBanner = ['class' => 'booking-status-banner--cancelled', 'icon' => 'fa-ban', 'text' => 'This booking is <strong>cancelled</strong>. Invoice sending, folio changes, and new payments are locked.'];
                break;
            case 'no-show':
                $statusBanner = ['class' => 'booking-status-banner--noshow', 'icon' => 'fa-user-slash', 'text' => 'This booking is marked as <strong>no-show</strong>. Folio changes and new payments are locked.'];
                break;
            case 'tentative':
                $statusBanner = ['class' => 'booking-status-banner--tentative', 'icon' => 'fa-hourglass-half', 'text' => '<strong>Tentative booking</strong> — invoices cannot be sent until this booking is confirmed.'];
                break;
            case 'checked-out':
                if ($folio_balance_due > BALANCE_TOLERANCE) {
                    $statusBanner = ['class' => 'booking-status-banner--balance', 'icon' => 'fa-exclamation-triangle', 'text' => 'Guest has <strong>checked out</strong> with an outstanding balance of <strong>' . htmlspecialchars($currency_symbol) . number_format($folio_balance_due, 2) . '</strong>. A payment can still be recorded.'];
                }
                break;
        }
        if ($statusBanner):
        ?>
            <div class="booking-status-banner <?php echo $statusBanner['class']; ?>">
                <i class="fas <?php echo $statusBanner['icon']; ?>"></i>
                <span><?php echo $statusBanner['text']; ?></span>
            </div>
        <?php endif; ?>

        <!-- Group booking notice -->
        <?php if (!empty($group_bookings)): ?>
        <div style="background:rgba(139,115,85,0.08);border:1px solid rgba(139,115,85,0.25);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <i class="fas fa-layer-group" style="color:#8B7355;font-size:1.1rem;flex-shrink:0;"></i>
            <span style="font-weight:600;color:#5A4A3A;font-size:0.9rem;">
                <?php echo !empty($booking['primary_booking_id']) ? 'Secondary room in a group booking' : 'Primary booking — group of ' . (count($group_bookings) + 1) . ' rooms'; ?>
            </span>
            <span style="color:#7A6A58;font-size:0.85rem;">Linked rooms:</span>
            <?php foreach ($group_bookings as $gb): ?>
            <a href="booking-details.php?id=<?php echo (int)$gb['id']; ?>"
               style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid rgba(139,115,85,0.3);border-radius:6px;padding:4px 10px;font-size:0.82rem;color:#5A4A3A;text-decoration:none;white-space:nowrap;">
                <i class="fas fa-door-open" style="font-size:0.75rem;color:#8B7355;"></i>
                <?php echo htmlspecialchars($gb['room_name']); ?> &mdash; <strong><?php echo htmlspecialchars($gb['booking_reference']); ?></strong>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Hero Section -->
        <div class="booking-hero">
            <div class="booking-hero-content">
                <div class="booking-hero-left">
                    <h1><i class="fas fa-calendar-check"></i> Booking Details</h1>
                    <div class="reference">Reference: <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></div>
                    <div class="booking-hero-meta">
                        <div class="hero-meta-item">
                            <i class="fas fa-clock"></i>
                            <span>Created: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['created_at'])); ?></span>
                        </div>
                        <?php if ($booking['updated_at'] && $booking['updated_at'] != $booking['created_at']): ?>
                            <div class="hero-meta-item">
                                <i class="fas fa-edit"></i>
                                <span>Updated: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['updated_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="booking-hero-right">
                    <div class="hero-status-badge">
                        <i class="fas <?php echo $current_status['icon']; ?>"></i>
                        <?php echo ucfirst(str_replace('-', ' ', $booking['status'])); ?>
                    </div>
                    <div class="hero-dates">
                        <?php echo date('M j', strtotime($booking['check_in_date'])); ?> - <?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="booking-kpi-strip">
            <div class="booking-kpi-card">
                <span class="booking-kpi-card__label">Folio Total</span>
                <strong class="booking-kpi-card__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_total_amount, 2); ?></strong>
            </div>
            <div class="booking-kpi-card">
                <span class="booking-kpi-card__label">VAT + Levy</span>
                <strong class="booking-kpi-card__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_total_vat + $booking_levy_amount, 2); ?></strong>
            </div>
            <div class="booking-kpi-card">
                <span class="booking-kpi-card__label">Amount Paid</span>
                <strong class="booking-kpi-card__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_amount_paid, 2); ?></strong>
            </div>
            <div class="booking-kpi-card <?php echo $folio_balance_due > 0 ? 'booking-kpi-card--attention' : ''; ?>">
                <span class="booking-kpi-card__label">Balance Due</span>
                <strong class="booking-kpi-card__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_balance_due, 2); ?></strong>
            </div>
            <div class="booking-kpi-card">
                <span class="booking-kpi-card__label">Room Status</span>
                <strong class="booking-kpi-card__value"><?php echo htmlspecialchars($room_status_label); ?></strong>
            </div>
        </div>

        <?php if ($booking_alert_message !== ''): ?>
            <div class="booking-status-alert booking-status-alert--<?php echo htmlspecialchars($booking_alert_tone); ?>">
                <i class="fas <?php echo $booking_alert_tone === 'danger' ? 'fa-triangle-exclamation' : ($booking_alert_tone === 'warning' ? 'fa-clock' : 'fa-circle-info'); ?>"></i>
                <span><?php echo htmlspecialchars($booking_alert_message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Details Grid -->
        <div class="details-grid">

            <!-- Overview cards: equal 4-up row (guest / stay / room / payment) -->
            <div class="story-grid">

            <!-- Guest Information Card -->
            <div class="info-card story-card story-card--guest">
                <div class="info-card-header">
                    <div class="icon guest"><i class="fas fa-user"></i></div>
                    <div class="story-head-text">
                        <h3>Guest Information</h3>
                    </div>
                </div>
                <div class="info-card-body">
                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($booking['guest_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($booking['guest_email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($booking['guest_phone']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Country</span>
                        <span class="info-value"><?php echo htmlspecialchars($booking['guest_country'] ?: 'N/A'); ?></span>
                    </div>
                    <?php
                    $child_guests = (int)($booking['child_guests'] ?? 0);
                    $adult_guests = (int)($booking['adult_guests'] ?? max(1, ((int) $booking['number_of_guests']) - $child_guests));
                    ?>
                    <div class="info-row">
                        <span class="info-label">Guests</span>
                        <span class="info-value">
                            <?php echo $adult_guests; ?> adult<?php echo $adult_guests === 1 ? '' : 's'; ?>
                            <?php if ($child_guests > 0): ?>
                                + <?php echo $child_guests; ?> child<?php echo $child_guests === 1 ? '' : 'ren'; ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="guest-contact-actions">
                        <a href="mailto:<?php echo htmlspecialchars($booking['guest_email']); ?>" class="email">
                            <i class="fas fa-envelope"></i> Email
                        </a>
                        <a href="tel:<?php echo htmlspecialchars($booking['guest_phone']); ?>" class="phone">
                            <i class="fas fa-phone"></i> Call
                        </a>
                    </div>

                    <?php if (!empty($booking['guest_email'])): ?>
                    <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f0ebe4;">
                        <?php if ($guest_history['completed_stays'] >= 1): ?>
                            <span class="gh-badge gh-badge--returning"><i class="fas fa-redo-alt"></i> Returning Guest</span>
                        <?php else: ?>
                            <span class="gh-badge gh-badge--new"><i class="fas fa-star"></i> First Stay</span>
                        <?php endif; ?>

                        <div class="gh-stats-row">
                            <div class="gh-stat">
                                <div class="gh-stat-val"><?php echo $guest_history['completed_stays']; ?></div>
                                <div class="gh-stat-lbl">Stays</div>
                            </div>
                            <div class="gh-stat">
                                <div class="gh-stat-val"><?php echo $guest_history['total_bookings']; ?></div>
                                <div class="gh-stat-lbl">Bookings</div>
                            </div>
                            <div class="gh-stat">
                                <div class="gh-stat-val gh-stat-val--lifetime"><?php echo $currency_symbol . number_format($guest_history['lifetime_spend'], 0); ?></div>
                                <div class="gh-stat-lbl">Lifetime</div>
                            </div>
                        </div>

                        <?php if (!empty($guest_history['bookings'])): ?>
                        <div class="gh-past-list">
                            <?php foreach ($guest_history['bookings'] as $pb): ?>
                            <div class="gh-past-item">
                                <div>
                                    <a href="booking-details.php?id=<?php echo (int)$pb['id']; ?>" class="gh-past-ref"><?php echo htmlspecialchars($pb['booking_reference']); ?></a>
                                    <div class="gh-past-dates"><?php echo date('M j, Y', strtotime($pb['check_in_date'])); ?> → <?php echo date('M j, Y', strtotime($pb['check_out_date'])); ?></div>
                                </div>
                                <span class="gh-past-status <?php echo htmlspecialchars(str_replace('-', '_', $pb['status'])); ?>"><?php echo htmlspecialchars(ucfirst(str_replace(['-','_'], ' ', $pb['status']))); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stay Duration Card -->
            <div class="info-card story-card story-card--stay">
                <div class="info-card-header">
                    <div class="icon stay"><i class="fas fa-calendar-alt"></i></div>
                    <div class="story-head-text">
                        <h3>Stay Duration</h3>
                    </div>
                </div>
                <div class="info-card-body">
                    <div class="stay-duration-display">
                        <div class="date-range">
                            <div class="date-box">
                                <div class="day"><?php echo date('d', strtotime($booking['check_in_date'])); ?></div>
                                <div class="month-year"><?php echo date('M Y', strtotime($booking['check_in_date'])); ?></div>
                                <div class="label">Check-in</div>
                            </div>
                            <div class="date-arrow"><i class="fas fa-arrow-right"></i></div>
                            <div class="date-box">
                                <div class="day"><?php echo date('d', strtotime($booking['check_out_date'])); ?></div>
                                <div class="month-year"><?php echo date('M Y', strtotime($booking['check_out_date'])); ?></div>
                                <div class="label">Check-out</div>
                            </div>
                        </div>
                        <div class="nights-display">
                            <i class="fas fa-moon"></i>
                            <?php echo $booking['number_of_nights']; ?> night<?php echo $booking['number_of_nights'] == 1 ? '' : 's'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Room Information Card -->
            <div class="info-card story-card story-card--room">
                <div class="info-card-header">
                    <div class="icon room"><i class="fas fa-bed"></i></div>
                    <div class="story-head-text">
                        <h3>Room Details</h3>
                    </div>
                </div>
                <div class="info-card-body">
                    <div class="room-info-display">
                        <div class="room-hero">
                            <div class="room-hero-icon"><i class="fas fa-bed"></i></div>
                            <div class="room-hero-text">
                                <div class="room-name-display"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                                <div class="room-type-display">Room type</div>
                            </div>
                        </div>

                        <?php if (!empty($booking['rate_plan_label'])): ?>
                            <div class="room-rate-plan">
                                <i class="fas fa-tag"></i>
                                <span><?php echo htmlspecialchars($booking['rate_plan_label']); ?><?php if ((float)($booking['rate_plan_discount'] ?? 0) > 0): ?> &mdash; -<?php echo $currency_symbol; ?><?php echo number_format((float) $booking['rate_plan_discount'], 2); ?>/night<?php endif; ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="room-meta-grid">
                            <div class="room-meta-cell">
                                <span class="room-meta-label">Rate / night</span>
                                <span class="room-meta-value"><?php echo $currency_symbol; ?><?php echo number_format((float) $booking['price_per_night'], 0); ?></span>
                            </div>
                            <div class="room-meta-cell">
                                <span class="room-meta-label">Nights</span>
                                <span class="room-meta-value"><?php echo (int) $booking['number_of_nights']; ?></span>
                            </div>
                            <div class="room-meta-cell">
                                <span class="room-meta-label">Occupancy</span>
                                <span class="room-meta-value"><?php echo (int) $booking['number_of_guests']; ?> guest<?php echo ((int) $booking['number_of_guests']) === 1 ? '' : 's'; ?></span>
                            </div>
                            <?php if ($booking['individual_room_floor']): ?>
                            <div class="room-meta-cell">
                                <span class="room-meta-label">Floor</span>
                                <span class="room-meta-value"><?php echo htmlspecialchars($booking['individual_room_floor']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php $bookingRoomLabel = getBookingRoomLabel((int) $booking['id'], (string)($booking['individual_room_name'] ?: (($booking['room_type_name'] ?: 'Room') . ' ' . $booking['individual_room_number']))); ?>
                        <?php if ($bookingRoomLabel !== ''): ?>
                            <div class="assigned-room-badge">
                                <i class="fas fa-door-open"></i>
                                <span><?php echo htmlspecialchars($bookingRoomLabel); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="room-unassigned">
                                <i class="fas fa-info-circle"></i> No specific room assigned yet
                            </div>
                        <?php endif; ?>

                        <div class="room-status-indicator">
                            <span class="status-dot <?php echo htmlspecialchars($booking['derived_room_status']); ?>"></span>
                            Room status: <strong><?php echo ucfirst($booking['derived_room_status']); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Information Card -->
            <div class="info-card story-card story-card--payment">
                <div class="info-card-header">
                    <div class="icon payment"><i class="fas fa-credit-card"></i></div>
                    <div class="story-head-text">
                        <h3>Payment Information</h3>
                    </div>
                </div>
                <div class="info-card-body">
                    <div class="payment-summary">
                        <?php
                        $display_total = $folio_total_amount;
                        $payment_status = $booking['actual_payment_status'];
                        $status_class = in_array($payment_status, ['paid', 'completed']) ? 'paid' : (in_array($payment_status, ['partial']) ? 'partial' : 'unpaid');
                        $status_labels = [
                            'paid' => 'Paid',
                            'unpaid' => 'Unpaid',
                            'partial' => 'Partial',
                            'completed' => 'Paid',
                            'pending' => 'Pending',
                            'failed' => 'Failed',
                            'refunded' => 'Refunded',
                        ];
                        ?>
                        <div class="payment-amount-block">
                            <span class="payment-amount-label">Total amount</span>
                            <div class="payment-amount">
                                <span class="currency"><?php echo $currency_symbol; ?></span>
                                <?php echo number_format($display_total, 2); ?>
                            </div>
                            <div class="info-value badge badge-<?php echo $status_class; ?>">
                                <i class="fas <?php echo $status_class === 'paid' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                                <?php echo $status_labels[$payment_status] ?? ucfirst($payment_status); ?>
                            </div>
                        </div>
                        <?php if ($booking['payment_reference']): ?>
                            <div class="payment-reference">
                                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($booking['payment_reference']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="payment-tax-breakdown">
                            <div class="payment-tax-row">
                                <span>Subtotal</span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_subtotal_before_vat, 2); ?></strong>
                            </div>
                            <?php if ($booking_levy_amount > 0): ?>
                                <div class="payment-tax-row">
                                    <span>Tourism Levy<?php echo $booking_levy_percent > 0 ? ' (' . number_format($booking_levy_percent, 2) . '%)' : ''; ?></span>
                                    <strong><?php echo $currency_symbol; ?><?php echo number_format($booking_levy_amount, 2); ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="payment-tax-row">
                                <span>VAT</span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_total_vat, 2); ?></strong>
                            </div>
                            <div class="payment-tax-row payment-tax-row--total">
                                <span>Total Due</span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_total_amount, 2); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($booking['payment_status'] !== 'paid'): ?>
                        <div class="payment-form">
                            <form method="POST" data-admin-confirm="Mark this booking payment as paid and send the payment invoice email?" data-admin-confirm-title="Record payment" data-admin-confirm-ok="Mark paid" data-admin-confirm-icon="fa-money-bill-wave" data-admin-submit-text="Recording payment...">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                                <input type="hidden" name="update_payment" value="1">
                                <input type="hidden" name="payment_status" value="paid">
                                <button type="submit" class="payment-mark-paid-btn">
                                    <i class="fas fa-check-circle"></i> Mark as Paid
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 12px; padding: 10px 14px; background: #edf7f0; border-radius: 10px; font-size: 12px; color: #1f7a42; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-check-circle"></i>
                            Payment received - Thank you!
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            </div><!-- /.story-grid -->

            <!-- Folio/Charges Card -->
            <div class="info-card folio-card" id="folio">
                <div class="info-card-header">
                    <div class="icon folio"><i class="fas fa-receipt"></i></div>
                    <h3>Folio / Charges</h3>
                </div>
                <div class="info-card-body">
                    <div class="folio-header">
                        <div class="folio-actions">
                            <?php if ($bPerms['can_add_charge']): ?>
                                <button class="folio-btn primary" onclick="openAddChargeModal()" data-help="Add Charge|Add a manual line item to this guest's folio — e.g. minibar, damages, or a service fee — with a custom description and amount.">
                                    <i class="fas fa-plus"></i> Add Charge
                                </button>
                                <button class="folio-btn secondary" onclick="openMenuModal()">
                                    <i class="fas fa-utensils"></i> Add Menu Item
                                </button>
                            <?php else: ?>
                                <span class="folio-locked-msg">
                                    <i class="fas fa-lock"></i>
                                    <?php echo htmlspecialchars($bPerms['can_add_charge_reason']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                    // Filter out voided charges for active display
                    $active_charges = array_filter($folio_charges, function ($c) {
                        return !$c['voided'];
                    });
                    ?>

                    <?php if (!empty($active_charges)): ?>
                        <table class="folio-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th style="text-align: right;">Qty</th>
                                    <th style="text-align: right;">Unit Price</th>
                                    <th style="text-align: right;">VAT</th>
                                    <th style="text-align: right;">Line Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($folio_charges as $charge): ?>
                                    <tr class="<?php echo $charge['voided'] ? 'voided' : ''; ?>">
                                        <td>
                                            <span style="font-size: 12px; color: #666;">
                                                <?php echo date('M j, Y', strtotime($charge['posted_at'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="charge-type <?php echo $charge['charge_type']; ?>">
                                                <?php echo htmlspecialchars($charge['charge_type']); ?>
                                            </span>
                                            <?php if ($charge['voided']): ?>
                                                <span class="void-badge"><i class="fas fa-ban"></i> Voided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($charge['description']); ?>
                                            <?php if ($charge['voided'] && $charge['void_reason']): ?>
                                                <br><small style="color: #a03030;">Reason: <?php echo htmlspecialchars($charge['void_reason']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;"><?php echo number_format($charge['quantity'], 0); ?></td>
                                        <td style="text-align: right;"><?php echo $currency_symbol; ?><?php echo number_format($charge['unit_price'], 2); ?></td>
                                        <td style="text-align: right;"><?php echo $charge['vat_rate'] > 0 ? number_format($charge['vat_amount'], 2) : '-'; ?></td>
                                        <td style="text-align: right; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format($charge['line_total'], 2); ?></td>
                                        <td>
                                            <?php if (!$charge['voided'] && $bPerms['can_void_charge']): ?>
                                                <button class="void-charge-btn" onclick="openVoidChargeModal(<?php echo $charge['id']; ?>, '<?php echo htmlspecialchars($charge['description'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-ban"></i> Void
                                                </button>
                                            <?php elseif (!$charge['voided']): ?>
                                                <span class="void-charge-btn void-charge-btn--locked" title="<?php echo htmlspecialchars($bPerms['can_void_charge_reason']); ?>">
                                                    <i class="fas fa-lock"></i> Locked
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="folio-summary">
                            <div class="folio-summary-item">
                                <div class="folio-summary-label">Room Base</div>
                                <div class="folio-summary-value"><?php echo $currency_symbol; ?><?php echo number_format((float) $booking['total_amount'] - (float)($booking['package_total'] ?? 0), 2); ?></div>
                            </div>
                            <?php if (!empty($booking['rate_plan_label']) && (float)($booking['rate_plan_discount'] ?? 0) > 0): ?>
                                <div class="folio-summary-item">
                                    <div class="folio-summary-label"><?php echo htmlspecialchars($booking['rate_plan_label']); ?></div>
                                    <div class="folio-summary-value" style="color:#1f7a42;">-<?php echo $currency_symbol; ?><?php echo number_format((float) $booking['rate_plan_discount'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($booking_packages as $bp): ?>
                                <div class="folio-summary-item">
                                    <div class="folio-summary-label"><?php echo htmlspecialchars($bp['package_name']); ?></div>
                                    <div class="folio-summary-value"><?php echo $currency_symbol; ?><?php echo number_format((float) $bp['total_cost'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <div class="folio-summary-item">
                                <div class="folio-summary-label">Extras</div>
                                <div class="folio-summary-value"><?php echo $currency_symbol; ?><?php echo number_format($folio_summary['extras_total'] ?? 0, 2); ?></div>
                            </div>
                            <?php if ($booking_levy_amount > 0): ?>
                                <div class="folio-summary-item">
                                    <div class="folio-summary-label">Tourism Levy<?php echo $booking_levy_percent > 0 ? ' (' . number_format($booking_levy_percent, 2) . '%)' : ''; ?></div>
                                    <div class="folio-summary-value"><?php echo $currency_symbol; ?><?php echo number_format($booking_levy_amount, 2); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="folio-summary-item">
                                <div class="folio-summary-label">VAT</div>
                                <div class="folio-summary-value"><?php echo $currency_symbol; ?><?php echo number_format($folio_total_vat, 2); ?></div>
                            </div>
                            <div class="folio-summary-item">
                                <div class="folio-summary-label">Total Due</div>
                                <div class="folio-summary-value total"><?php echo $currency_symbol; ?><?php echo number_format($folio_total_amount, 2); ?></div>
                            </div>
                            <?php if ($folio_amount_paid > 0): ?>
                                <div class="folio-summary-item">
                                    <div class="folio-summary-label">Amount Paid</div>
                                    <div class="folio-summary-value paid"><?php echo $currency_symbol; ?><?php echo number_format($folio_amount_paid, 2); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($folio_balance_due > BALANCE_TOLERANCE): ?>
                                <div class="folio-summary-item folio-summary-item--alert">
                                    <div class="folio-summary-label"><i class="fas fa-exclamation-triangle" style="color:#d97706;"></i> Balance Due</div>
                                    <div class="folio-summary-value balance"><?php echo $currency_symbol; ?><?php echo number_format($folio_balance_due, 2); ?></div>
                                </div>
                            <?php elseif ($folio_amount_paid > $folio_total_amount + BALANCE_TOLERANCE): ?>
                                <?php $overpaid_amount = $folio_amount_paid - $folio_total_amount; ?>
                                <div class="folio-summary-item folio-summary-item--overpay">
                                    <div class="folio-summary-label"><i class="fas fa-coins" style="color:#0369a1;"></i> Overpayment</div>
                                    <div class="folio-summary-value" style="color:#0369a1;"><?php echo $currency_symbol; ?><?php echo number_format($overpaid_amount, 2); ?></div>
                                </div>
                                <p class="folio-overpay-hint">
                                    <i class="fas fa-info-circle"></i>
                                    Guest has overpaid by <?php echo $currency_symbol . number_format($overpaid_amount, 2); ?>.
                                    <a href="credit-notes.php?booking_id=<?php echo $booking_id; ?>">Issue a credit note</a> to apply the excess.
                                </p>
                            <?php elseif ($folio_amount_paid >= $folio_total_amount - BALANCE_TOLERANCE && $folio_total_amount > 0): ?>
                                <div class="folio-summary-item folio-summary-item--settled">
                                    <div class="folio-summary-label"><i class="fas fa-circle-check" style="color:#16a34a;"></i> Fully Settled</div>
                                    <div class="folio-summary-value" style="color:#16a34a;">Paid in full</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p>No folio charges yet</p>
                            <small>Click "Add Charge" or "Add Menu Item" to add items to the guest folio.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoices Card -->
            <div class="info-card invoices-card" id="invoices">
                <div class="info-card-header">
                    <div class="icon invoice"><i class="fas fa-file-invoice"></i></div>
                    <h3>Invoices</h3>
                </div>
                <div class="info-card-body">
                    <div class="folio-header">
                        <div class="folio-actions">
                            <?php if (!$bPerms['can_generate_invoice'] || !$bPerms['can_send_invoice']): ?>
                                <div class="folio-locked-msg">
                                    <i class="fas fa-lock"></i>
                                    <?php echo htmlspecialchars($bPerms['can_generate_invoice_reason'] ?: $bPerms['can_send_invoice_reason']); ?>
                                </div>
                            <?php else: ?>
                                <form method="POST" style="display: inline;" data-admin-confirm="Generate an invoice PDF for this booking without sending it?" data-admin-confirm-title="Generate invoice" data-admin-confirm-ok="Generate" data-admin-confirm-icon="fa-file-pdf" data-admin-submit-text="Generating...">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="invoice_action" value="generate_invoice">
                                    <button type="submit" class="folio-btn primary">
                                        <i class="fas fa-file-pdf"></i> Generate Invoice
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" data-admin-confirm="Send this booking invoice to the guest by email?" data-admin-confirm-title="Send invoice email" data-admin-confirm-ok="Send email" data-admin-confirm-icon="fa-envelope-circle-check" data-admin-submit-text="Sending invoice...">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="invoice_action" value="send_invoice">
                                    <button type="submit" class="folio-btn success">
                                        <i class="fas fa-envelope-circle-check"></i> Send Invoice
                                    </button>
                                </form>
                                <?php if (function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()): ?>
                                    <form method="POST" style="display: inline;" data-admin-confirm="Send the invoice link to the guest on WhatsApp? This can use the configured WhatsApp provider." data-admin-confirm-title="Send invoice via WhatsApp" data-admin-confirm-ok="Send WhatsApp" data-admin-confirm-icon="fa-whatsapp" data-admin-submit-text="Sending WhatsApp...">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="invoice_action" value="send_invoice_whatsapp">
                                        <button type="submit" class="folio-btn" style="background:#25D366;color:#fff;border-color:#25D366;">
                                            <i class="fab fa-whatsapp"></i> Send via WhatsApp
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($existing_invoices)): ?>
                        <div class="invoice-list">
                            <?php foreach ($existing_invoices as $invoice): ?>
                                <div class="invoice-item">
                                    <div class="invoice-info">
                                        <div class="invoice-number">
                                            <i class="fas fa-file-invoice" style="color: var(--gold, #8B7355); margin-right: 8px;"></i>
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                        </div>
                                        <div class="invoice-date">
                                            Generated: <?php echo date('M j, Y \a\t g:i A', strtotime($invoice['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="invoice-actions">
                                        <?php if ($invoice['invoice_path']): ?>
                                            <a href="../<?php echo htmlspecialchars($invoice['invoice_path']); ?>" target="_blank" class="invoice-btn view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-invoice"></i>
                            <p>No invoices generated yet</p>
                            <small>Click "Generate Invoice" to create an invoice for this booking.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="timeline-notes-row">

            <!-- Timeline Card -->
            <div class="info-card timeline-card">
                <div class="info-card-header">
                    <div class="icon timeline"><i class="fas fa-history"></i></div>
                    <h3>Activity Timeline</h3>
                </div>
                <div class="info-card-body">
                    <div class="timeline-list">
                        <?php if (empty($timeline)): ?>
                            <div class="empty-state empty-state--compact">
                                <i class="fas fa-history empty-state__icon-md"></i>
                                <p>No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($timeline, 0, 10) as $event):
                                $type_info = formatActionType($event['action_type']);
                                $event_metadata = !empty($event['metadata']) ? json_decode($event['metadata'], true) : [];
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon <?php echo $event['action_type']; ?>">
                                        <i class="fas <?php echo $type_info['icon']; ?>"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title"><?php echo htmlspecialchars($event['action']); ?></div>
                                        <div class="timeline-meta">
                                            <?php if ($event['performed_by_name']): ?>
                                                by <?php echo htmlspecialchars($event['performed_by_name']); ?>
                                            <?php else: ?>
                                                System
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($event['action_type'] === 'date_adjustment' && !empty($event_metadata)): ?>
                                            <div class="timeline-adjustment-details">
                                                <div class="timeline-adjustment-row">
                                                    <span class="timeline-adjustment-label">Nights</span>
                                                    <span class="timeline-adjustment-value">
                                                        <?php echo htmlspecialchars($event_metadata['old']['nights'] ?? '?'); ?> ->
                                                        <?php echo htmlspecialchars($event_metadata['new']['nights'] ?? '?'); ?>
                                                    </span>
                                                </div>
                                                <div class="timeline-adjustment-row">
                                                    <span class="timeline-adjustment-label">Amount Delta</span>
                                                    <span class="timeline-adjustment-value <?php echo ($event_metadata['amount_delta'] ?? 0) >= 0 ? 'is-increase' : 'is-decrease'; ?>">
                                                        <?php echo ($event_metadata['amount_delta'] ?? 0) >= 0 ? '+' : '-'; ?>
                                                        <?php echo $currency_symbol . number_format(abs($event_metadata['amount_delta'] ?? 0), 2); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($event_metadata['reason'])): ?>
                                                    <div class="timeline-adjustment-reason">
                                                        "<?php echo htmlspecialchars($event_metadata['reason']); ?>"
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-time"><?php echo date('M j, H:i', strtotime($event['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Notes Card -->
            <div class="info-card notes-card">
                <div class="info-card-header">
                    <div class="icon notes"><i class="fas fa-sticky-note"></i></div>
                    <h3>Internal Notes</h3>
                </div>
                <div class="info-card-body">
                    <div class="notes-form">
                        <form method="POST">
                            <textarea name="note_text" placeholder="Add a note about this booking..." required></textarea>
                            <button type="submit" name="add_note">
                                <i class="fas fa-plus"></i> Add Note
                            </button>
                        </form>
                    </div>
                    <div class="notes-list">
                        <?php if (empty($notes)): ?>
                            <div class="empty-state empty-state--compact">
                                <i class="fas fa-sticky-note empty-state__icon-sm"></i>
                                <p>No notes yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notes as $note): ?>
                                <div class="note-item">
                                    <div class="note-header">
                                        <span class="note-author"><?php echo htmlspecialchars($note['created_by_name'] ?? 'Unknown'); ?></span>
                                        <span class="note-time"><?php echo date('M j, H:i', strtotime($note['created_at'])); ?></span>
                                    </div>
                                    <div class="note-text"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            </div><!-- /.timeline-notes-row -->

            <?php if ($booking['special_requests']): ?>
                <!-- Special Requests -->
                <div class="info-card booking-special-card">
                    <div class="info-card-header">
                        <div class="icon special-requests"><i class="fas fa-comment-dots"></i></div>
                        <h3>Special Requests</h3>
                    </div>
                    <div class="info-card-body">
                        <div class="booking-special-card__content">
                            <?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Actions Card -->
            <div class="info-card actions-card">
                <div class="info-card-header">
                    <div class="icon actions"><i class="fas fa-bolt"></i></div>
                    <h3>Quick Actions</h3>
                </div>
                <div class="info-card-body">
                    <?php
                    $can_cancel = !in_array($booking['status'], ['checked-in', 'checked-out', 'cancelled', 'no-show']);
                    $can_adjust_dates = !in_array($booking['status'], ['cancelled', 'checked-out', 'no-show']);
                    ?>
                    <div class="booking-actions-grid">
                        <section class="booking-actions-group">
                            <h4 class="booking-actions-group__title">Primary Actions</h4>
                            <div class="booking-actions-flow">
                                <?php if ($booking['status'] == 'tentative' || $booking['is_tentative'] == 1): ?>
                                    <form method="POST" class="booking-action-form" data-admin-confirm="Convert this tentative booking to confirmed and send the conversion email?" data-admin-confirm-title="Convert tentative booking" data-admin-confirm-ok="Convert" data-admin-confirm-icon="fa-circle-check" data-admin-submit-text="Converting...">
                                        <input type="hidden" name="booking_action" value="convert">
                                        <button type="submit" class="action-btn convert" aria-label="Convert to confirmed"><i class="fas fa-circle-check"></i> Convert to Confirmed</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($booking['status'] == 'pending'): ?>
                                    <form method="POST" class="booking-action-form" data-admin-confirm="Confirm this booking and send the guest confirmation email?" data-admin-confirm-title="Confirm booking" data-admin-confirm-ok="Confirm" data-admin-confirm-icon="fa-circle-check" data-admin-submit-text="Confirming...">
                                        <input type="hidden" name="booking_action" value="confirm">
                                        <button type="submit" class="action-btn confirm" aria-label="Confirm booking"><i class="fas fa-circle-check"></i> Confirm Booking</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($booking['status'] == 'confirmed'): ?>
                                    <?php
                                    $can_checkin = !in_array($booking['actual_payment_status'] ?? '', ['unpaid', ''], true);
                                    $room_assigned = !empty($booking['individual_room_id']);
                                    $check_in_date = new DateTime($booking['check_in_date']);
                                    $check_in_date->setTime(0, 0, 0);
                                    $today = new DateTime('today');
                                    $checkin_date_reached = $check_in_date <= $today;
                                    $checkin_disabled_reason = '';
                                    if (!$can_checkin) {
                                        $checkin_disabled_reason = 'At least a partial payment must be recorded before check-in';
                                    } elseif (!$room_assigned) {
                                        $checkin_disabled_reason = 'Room must be assigned before check-in';
                                    } elseif (!$checkin_date_reached) {
                                        $checkin_disabled_reason = 'Check-in date has not been reached yet (' . htmlspecialchars($booking['check_in_date']) . ')';
                                    }
                                    ?>

                                    <?php if (!$room_assigned): ?>
                                        <a href="bookings.php?action=assign-room&booking_id=<?php echo $booking_id; ?>" class="action-btn assign-room" data-help="Assign Room|Pick a specific physical room for this confirmed booking. Required before check-in can proceed.">
                                            <i class="fas fa-key"></i> Assign Room
                                        </a>
                                    <?php else: ?>
                                        <a href="bookings.php?action=assign-room&booking_id=<?php echo $booking_id; ?>" class="action-btn change-room">
                                            <i class="fas fa-right-left"></i> Change Room
                                        </a>
                                    <?php endif; ?>

                                    <form method="POST" class="booking-action-form" data-admin-confirm="Check in this guest and mark the assigned room occupied?" data-admin-confirm-title="Check in guest" data-admin-confirm-ok="Check in" data-admin-confirm-icon="fa-right-to-bracket" data-admin-submit-text="Checking in...">
                                        <input type="hidden" name="booking_action" value="checkin">
                                        <button type="submit" class="action-btn checkin" data-help="Check In|Check the guest into their assigned room and mark the room occupied. Requires payment recorded, a room assigned, and the check-in date to have arrived." <?php echo ($can_checkin && $room_assigned && $checkin_date_reached) ? '' : 'disabled title="' . htmlspecialchars($checkin_disabled_reason) . '"'; ?>>
                                            <i class="fas fa-right-to-bracket"></i> Check In
                                        </button>
                                    </form>
                                    <?php if ($checkin_disabled_reason): ?>
                                        <p class="booking-action-inline-hint booking-action-inline-hint--error">
                                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($checkin_disabled_reason); ?>
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($booking['status'] == 'checked-in'): ?>
                                    <form method="POST" class="booking-action-form" data-admin-confirm="Check out this guest and generate the final invoice where applicable?" data-admin-confirm-title="Check out guest" data-admin-confirm-ok="Check out" data-admin-confirm-icon="fa-right-from-bracket" data-admin-submit-text="Checking out...">
                                        <input type="hidden" name="booking_action" value="checkout">
                                        <button type="submit" class="action-btn checkout" data-help="Check Out|Check the guest out, release the room, and generate the final invoice where applicable.">
                                            <i class="fas fa-right-from-bracket"></i> Check Out
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($booking['status'], ['confirmed', 'pending'], true) && strtotime($booking['check_in_date']) < strtotime('today')): ?>
                                    <form method="POST" class="booking-action-form" data-admin-confirm="Mark this booking as no-show and release the assigned room?" data-admin-confirm-title="Mark no-show" data-admin-confirm-ok="Mark no-show" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-user-slash" data-admin-submit-text="Updating...">
                                        <input type="hidden" name="booking_action" value="noshow">
                                        <button type="submit" class="action-btn noshow"><i class="fas fa-user-slash"></i> Mark No-Show</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($can_cancel): ?>
                                    <form method="POST" class="booking-action-form" data-admin-confirm="Cancel this booking, release the room, and send the guest cancellation email?" data-admin-confirm-title="Cancel booking" data-admin-confirm-ok="Cancel booking" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-ban" data-admin-submit-text="Cancelling...">
                                        <input type="hidden" name="booking_action" value="cancel">
                                        <input type="hidden" name="cancellation_reason" value="Cancelled by admin">
                                        <button type="submit" class="action-btn cancel" aria-label="Cancel booking"><i class="fas fa-ban"></i> Cancel Booking</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="booking-actions-group booking-actions-group--support">
                            <h4 class="booking-actions-group__title">Management Tools</h4>
                            <div class="booking-actions-flow">
                                <a href="bookings.php" class="action-btn back" onclick="if(history.length>1){history.back();return false;}"><i class="fas fa-arrow-left"></i> Back to Bookings</a>
                                <a href="edit-booking.php?id=<?php echo $booking_id; ?>" class="action-btn edit"><i class="fas fa-edit"></i> Edit Booking</a>
                                <?php if ($bPerms['can_send_quotation']): ?>
                                    <button type="button" class="action-btn quote" onclick="openBookingQuoteModal(<?php echo (int) $booking_id; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_email'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-file-invoice"></i> Send Quotation
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="action-btn quote action-btn--locked" disabled title="<?php echo htmlspecialchars($bPerms['can_send_quotation_reason']); ?>">
                                        <i class="fas fa-lock"></i> Send Quotation
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_adjust_dates): ?>
                                    <button type="button" class="action-btn adjust-dates" onclick="openDateAdjustModal()">
                                        <i class="fas fa-calendar-alt"></i> Adjust Stay Dates
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($booking['last_quotation_sent_at'])): ?>
                                <p class="booking-actions-meta">
                                    <i class="fas fa-paper-plane"></i>
                                    Quotation last sent <?php echo date('M j, Y \a\t g:i A', strtotime($booking['last_quotation_sent_at'])); ?>
                                </p>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Charge Modal -->
    <div class="modal-overlay" id="addChargeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus" style="color: var(--gold, #8B7355);"></i> Add Custom Charge</h3>
                <button class="modal-close" onclick="closeAddChargeModal()">&times;</button>
            </div>
            <form method="POST" id="addChargeForm">
                <input type="hidden" name="charge_action" value="add_charge">
                <div class="form-group">
                    <label>Charge Type</label>
                    <select name="charge_type" required>
                        <option value="custom">Custom</option>
                        <option value="service">Service</option>
                        <option value="minibar">Minibar</option>
                        <option value="laundry">Laundry</option>
                        <option value="room_service">Room Service</option>
                        <option value="breakfast">Breakfast</option>
                        <option value="other">Other</option>
                    </select>
                    <small style="display:block; margin-top:6px; color:#856404; font-size:12px;">
                        <i class="fas fa-info-circle"></i> For food/drink items, use the <strong>Add Menu Item</strong> tab instead - that path automatically deducts ingredient stock.
                    </small>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="e.g., Late checkout fee, Airport transfer" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="1" min="0.01" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Unit Price</label>
                    <input type="number" name="unit_price" placeholder="0.00" min="0" step="0.01" required data-currency="<?php echo htmlspecialchars($currency_symbol, ENT_QUOTES); ?>">
                </div>
                <div class="modal-actions">
                    <div id="addChargeFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                    <button type="button" class="btn-secondary" onclick="closeAddChargeModal()">Close</button>
                    <button type="submit" id="addChargeSaveBtn" class="btn-primary">Add Charge</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Menu Quick Add Modal -->
    <div class="modal-overlay" id="menuModal">
        <div class="modal-content wide">
            <div class="modal-header">
                <h3><i class="fas fa-utensils" style="color: var(--gold, #8B7355);"></i> Add Menu Item to Folio</h3>
                <button class="modal-close" onclick="closeMenuModal()">&times;</button>
            </div>
            <form method="POST" id="menuForm">
                <input type="hidden" name="charge_action" value="add_menu_item">
                <input type="hidden" name="menu_type" id="menuType" value="food">
                <input type="hidden" name="menu_item_id" id="menuItemId" value="">
                <input type="hidden" name="quantity" id="menuQuantity" value="1">

                <div class="tab-nav">
                    <button type="button" class="tab-btn active" onclick="switchMenuTab('food')">Food Menu</button>
                    <button type="button" class="tab-btn" onclick="switchMenuTab('drink')">Drinks</button>
                </div>

                <div id="foodMenuTab" class="tab-content active">
                    <?php if (!empty($food_menu_items)): ?>
                        <?php foreach ($food_menu_items as $category => $items): ?>
                            <div class="menu-category-section">
                                <div class="menu-category-title"><?php echo htmlspecialchars($category); ?></div>
                                <div class="menu-items-grid">
                                    <?php foreach ($items as $item): ?>
                                        <div class="menu-item-card" data-item-id="<?php echo $item['id']; ?>" data-item-price="<?php echo $item['price']; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>" data-menu-type="food" onclick="selectMenuItem(this)">
                                            <div class="menu-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <div class="menu-item-price"><?php echo $currency_symbol; ?><?php echo number_format($item['price'], 2); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px;">
                            <i class="fas fa-utensils"></i>
                            <p>No food menu items available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="drinkMenuTab" class="tab-content">
                    <?php if (!empty($drink_menu_items)): ?>
                        <?php foreach ($drink_menu_items as $category => $items): ?>
                            <div class="menu-category-section">
                                <div class="menu-category-title"><?php echo htmlspecialchars($category); ?></div>
                                <div class="menu-items-grid">
                                    <?php foreach ($items as $item): ?>
                                        <div class="menu-item-card" data-item-id="<?php echo $item['id']; ?>" data-item-price="<?php echo $item['price']; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>" data-menu-type="drink" onclick="selectMenuItem(this)">
                                            <div class="menu-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <div class="menu-item-price"><?php echo $currency_symbol; ?><?php echo number_format($item['price'], 2); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px;">
                            <i class="fas fa-cocktail"></i>
                            <p>No drink menu items available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label>Quantity</label>
                    <input type="number" id="menuQuantityInput" value="1" min="1" step="1" onchange="updateMenuQuantity()">
                </div>

                <div class="modal-actions">
                    <div id="menuFolioFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                    <button type="button" class="btn-secondary" onclick="closeMenuModal()">Close</button>
                    <button type="submit" class="btn-primary" id="menuAddBtn" disabled>Add to Folio</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Void Charge Modal -->
    <div class="modal-overlay" id="voidChargeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-ban" style="color: #a03030;"></i> Void Charge</h3>
                <button class="modal-close" onclick="closeVoidChargeModal()">&times;</button>
            </div>
            <form method="POST" id="voidChargeForm">
                <input type="hidden" name="charge_action" value="void_charge">
                <input type="hidden" name="charge_id" id="voidChargeId" value="">
                <div class="form-group">
                    <label>Charge to Void</label>
                    <input type="text" id="voidChargeDescription" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label>Reason for Voiding</label>
                    <textarea name="void_reason" placeholder="e.g., Item not consumed, Error in charging, Guest complaint" required></textarea>
                </div>
                <div class="modal-actions">
                    <div id="voidChargeFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                    <button type="button" class="btn-secondary" onclick="closeVoidChargeModal()">Close</button>
                    <button type="submit" id="voidChargeSaveBtn" class="btn-danger">Void Charge</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Date Adjustment Modal -->
    <div class="modal-overlay" id="dateAdjustModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt" style="color: var(--color-lux-gold, #B18247);"></i> Adjust Stay Dates</h3>
                <button class="modal-close" onclick="closeDateAdjustModal()">&times;</button>
            </div>
            <form method="POST" id="dateAdjustForm">
                <input type="hidden" name="adjust_dates" value="1">

                <div style="background: #f8f9fb; padding: 16px; border-radius: 12px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #888;">Current Check-in:</span>
                        <span style="font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($booking['check_in_date']); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #888;">Current Check-out:</span>
                        <span style="font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($booking['check_out_date']); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 12px; color: #888;">Current Nights:</span>
                        <span style="font-size: 14px; font-weight: 600;"><?php echo (int) $booking['number_of_nights']; ?> nights</span>
                    </div>
                </div>

                <div class="form-group">
                    <label>New Check-in Date *</label>
                    <input type="date" name="new_check_in" id="newCheckIn" value="<?php echo htmlspecialchars($booking['check_in_date']); ?>" required onchange="previewDateAdjustment()">
                </div>

                <div class="form-group">
                    <label>New Check-out Date *</label>
                    <input type="date" name="new_check_out" id="newCheckOut" value="<?php echo htmlspecialchars($booking['check_out_date']); ?>" required onchange="previewDateAdjustment()">
                </div>

                <div id="dateAdjustPreview" style="background: #f0f8ff; padding: 16px; border-radius: 12px; margin-bottom: 16px; display: none;">
                    <div style="font-size: 13px; font-weight: 600; color: #1a7a96; margin-bottom: 12px;">
                        <i class="fas fa-calculator"></i> Adjustment Preview
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">New Nights:</span>
                        <span id="previewNights" style="font-size: 13px; font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">Nights Change:</span>
                        <span id="previewNightsDelta" style="font-size: 13px; font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">New Total:</span>
                        <span id="previewNewTotal" style="font-size: 13px; font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #d0e8ff;">
                        <span style="font-size: 12px; font-weight: 600; color: #1a7a96;">Amount Delta:</span>
                        <span id="previewDelta" style="font-size: 14px; font-weight: 700; color: var(--color-lux-ink, #231F1C);">-</span>
                    </div>
                </div>

                <div id="dateAdjustError" style="background: #fef2f2; color: #a03030; padding: 12px; border-radius: 8px; margin-bottom: 16px; display: none;"></div>

                <div id="dateAdjustWarning" style="background: #fff7ed; color: #c2410c; padding: 12px; border-radius: 8px; margin-bottom: 16px; display: none;"></div>

                <div class="form-group">
                    <label>Reason for Adjustment *</label>
                    <textarea name="adjustment_reason" placeholder="e.g., Guest requested early check-in, Extended stay due to flight delay, Guest checked out early" required></textarea>
                </div>

                <div class="modal-actions">
                    <div id="dateAdjustFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                    <button type="button" class="btn-secondary" onclick="closeDateAdjustModal()">Close</button>
                    <button type="submit" class="btn-primary" id="dateAdjustSubmitBtn">Confirm Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const currencySymbol = '<?php echo $currency_symbol; ?>';

        // Inject CSRF token into all POST forms on this page (anti-CSRF protection)
        const _bkDetailsCsrf = <?php echo json_encode($csrf_token); ?>;
        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(f) {
            if (!f.querySelector('[name="csrf_token"]')) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'csrf_token';
                inp.value = _bkDetailsCsrf;
                f.appendChild(inp);
            }
        });

        const bookingFlash = {
            success: <?php echo json_encode($flash_success_message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            error: <?php echo json_encode($flash_error_message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
        };

        var _bookingToastTitles = [
            [/invoice sent.*whatsapp/i,       'Sent via WhatsApp'],
            [/invoice sent/i,                 'Invoice Sent'],
            [/invoice generated/i,            'Invoice Generated'],
            [/invoice regenerated/i,          'Invoice Regenerated'],
            [/checked.?in/i,                  'Guest Checked In'],
            [/checked.?out/i,                 'Guest Checked Out'],
            [/no.?show/i,                     'Marked No-Show'],
            [/booking cancelled/i,            'Booking Cancelled'],
            [/booking confirmed/i,            'Booking Confirmed'],
            [/tentative.*converted/i,         'Booking Confirmed'],
            [/converted.*confirmed/i,         'Booking Confirmed'],
            [/payment.*recorded/i,            'Payment Recorded'],
            [/payment status updated/i,       'Payment Updated'],
            [/date.*adjust/i,                 'Dates Updated'],
            [/note added/i,                   'Note Added'],
            [/charge.*added/i,                'Charge Added'],
            [/charge.*voided/i,               'Charge Voided'],
            [/menu item added/i,              'Item Added to Folio'],
        ];

        function _bookingToastTitle(msg) {
            for (var i = 0; i < _bookingToastTitles.length; i++) {
                if (_bookingToastTitles[i][0].test(msg)) return _bookingToastTitles[i][1];
            }
            return null;
        }

        function showBookingActionMessage(message, type) {
            var text = String(message || '').trim();
            if (!text) return;
            if (window.Alert && typeof window.Alert.show === 'function') {
                Alert.show(text, type || 'info', {
                    title:   type === 'error' ? 'Action Failed' : _bookingToastTitle(text),
                    timeout: 5500,
                    position: 'top-right'
                });
                return;
            }
            (window.__rhToastQueue = window.__rhToastQueue || []).push({
                msg: text, type: type || 'info',
                opts: { title: type === 'error' ? 'Action Failed' : _bookingToastTitle(text), timeout: 5500 }
            });
        }

        if (bookingFlash.success) {
            showBookingActionMessage(bookingFlash.success, 'success');
        }
        if (bookingFlash.error) {
            showBookingActionMessage(bookingFlash.error, 'error');
        }

        function openAddChargeModal() {
            document.getElementById('addChargeModal').classList.add('active');
        }

        function closeAddChargeModal() {
            document.getElementById('addChargeModal').classList.remove('active');
            document.getElementById('addChargeForm').reset();
            var fb = document.getElementById('addChargeFeedback');
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
        }

        function openMenuModal() {
            document.getElementById('menuModal').classList.add('active');
        }

        function closeMenuModal() {
            document.getElementById('menuModal').classList.remove('active');
            document.getElementById('menuForm').reset();
            document.getElementById('menuAddBtn').disabled = true;
            document.querySelectorAll('.menu-item-card').forEach(card => {
                card.classList.remove('selected');
            });
            var fb = document.getElementById('menuFolioFeedback');
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
        }

        function switchMenuTab(type) {
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(type + 'MenuTab').classList.add('active');

            // Update hidden field
            document.getElementById('menuType').value = type;

            // Clear selection
            document.querySelectorAll('.menu-item-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.getElementById('menuItemId').value = '';
            document.getElementById('menuAddBtn').disabled = true;
        }

        function selectMenuItem(card) {
            // Clear previous selection
            document.querySelectorAll('.menu-item-card').forEach(c => {
                c.classList.remove('selected');
            });

            // Select this card
            card.classList.add('selected');

            // Update form
            document.getElementById('menuItemId').value = card.dataset.itemId;
            document.getElementById('menuType').value = card.dataset.menuType;
            document.getElementById('menuAddBtn').disabled = false;
        }

        function updateMenuQuantity() {
            const qty = document.getElementById('menuQuantityInput').value || 1;
            document.getElementById('menuQuantity').value = qty;
        }

        function openVoidChargeModal(chargeId, description) {
            document.getElementById('voidChargeId').value = chargeId;
            document.getElementById('voidChargeDescription').value = description;
            document.getElementById('voidChargeModal').classList.add('active');
        }

        function closeVoidChargeModal() {
            document.getElementById('voidChargeModal').classList.remove('active');
            document.getElementById('voidChargeForm').reset();
            var fb = document.getElementById('voidChargeFeedback');
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // AJAX saves for folio modals
        function handleFolioFormSubmit(formId, saveBtnId, feedbackId, preFn) {
            if (preFn) preFn();
            var form = document.getElementById(formId);
            var saveBtn = document.getElementById(saveBtnId);
            var fb = document.getElementById(feedbackId);
            var origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch(window.location.pathname + window.location.search, {
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
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.style.width = '100%';
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) refreshFolioSection();
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error - please try again.';
                });
        }
        document.getElementById('addChargeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleFolioFormSubmit('addChargeForm', 'addChargeSaveBtn', 'addChargeFeedback', null);
        });
        document.getElementById('menuForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleFolioFormSubmit('menuForm', 'menuAddBtn', 'menuFolioFeedback', updateMenuQuantity);
        });
        document.getElementById('voidChargeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            handleFolioFormSubmit('voidChargeForm', 'voidChargeSaveBtn', 'voidChargeFeedback', null);
        });
        document.getElementById('dateAdjustForm').addEventListener('submit', function() {
            var submitBtn = document.getElementById('dateAdjustSubmitBtn');
            var fb = document.getElementById('dateAdjustFeedback');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            // Allow normal POST+redirect for date adjustments so the full booking state refreshes.
        });

        function refreshFolioSection() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = doc.getElementById('folio');
                    var cur = document.getElementById('folio');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                }).catch(function() {});
        }

        // Date Adjustment Functions
        const currentCheckIn = '<?php echo htmlspecialchars($booking['check_in_date']); ?>';
        const currentCheckOut = '<?php echo htmlspecialchars($booking['check_out_date']); ?>';
        const currentNights = <?php echo (int) $booking['number_of_nights']; ?>;
        const currentTotal = <?php echo (float) $booking_room_total_with_tax; ?>;
        const currentChildSupplement = <?php echo (float)($booking['child_supplement_total'] ?? 0); ?>;
        const pricePerNight = <?php echo (float)($booking['price_per_night'] ?? 0); ?>;
        const vatRate = <?php echo $vat_enabled ? (float) getSetting('vat_rate') : 0; ?>;
        const vatMode = <?php echo json_encode(vat_mode()); ?>; // 'off' | 'inclusive' | 'exclusive'
        const levyRate = <?php echo $booking_levy_percent; ?>;

        function openDateAdjustModal() {
            document.getElementById('dateAdjustModal').classList.add('active');
            document.getElementById('newCheckIn').value = currentCheckIn;
            document.getElementById('newCheckOut').value = currentCheckOut;
            previewDateAdjustment();
        }

        function closeDateAdjustModal() {
            document.getElementById('dateAdjustModal').classList.remove('active');
            document.getElementById('dateAdjustForm').reset();
            document.getElementById('dateAdjustPreview').style.display = 'none';
            document.getElementById('dateAdjustError').style.display = 'none';
            const warningDiv = document.getElementById('dateAdjustWarning');
            if (warningDiv) {
                warningDiv.style.display = 'none';
            }
            var fb = document.getElementById('dateAdjustFeedback');
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
        }

        function previewDateAdjustment() {
            const newCheckIn = document.getElementById('newCheckIn').value;
            const newCheckOut = document.getElementById('newCheckOut').value;
            const preview = document.getElementById('dateAdjustPreview');
            const error = document.getElementById('dateAdjustError');
            const submitBtn = document.getElementById('dateAdjustSubmitBtn');

            // Reset
            error.style.display = 'none';
            submitBtn.disabled = false;

            // Validate dates
            if (!newCheckIn || !newCheckOut) {
                preview.style.display = 'none';
                return;
            }

            const checkInDate = new Date(newCheckIn);
            const checkOutDate = new Date(newCheckOut);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            // Check for past dates (only if original check-in is today or future)
            const originalCheckIn = new Date(currentCheckIn);
            if (originalCheckIn >= today && checkInDate < today) {
                error.textContent = 'Cannot adjust dates to the past. The new check-in date must be today or in the future.';
                error.style.display = 'block';
                preview.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }

            if (checkInDate >= checkOutDate) {
                error.textContent = 'Check-out date must be after check-in date.';
                error.style.display = 'block';
                preview.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }

            // Calculate nights
            const newNights = Math.round((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));

            if (newNights <= 0) {
                error.textContent = 'Booking must be for at least one night.';
                error.style.display = 'block';
                preview.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }

            // Maximum stay validation (30 nights)
            const maxStayNights = 30;
            if (newNights > maxStayNights) {
                error.textContent = 'Booking cannot exceed ' + maxStayNights + ' nights. Please contact management for extended stays.';
                error.style.display = 'block';
                preview.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }

            // Calculate new total with child supplement
            const newBaseAmount = pricePerNight * newNights;

            // Calculate child supplement adjustment (proportional to nights change)
            let newChildSupplement = 0;
            if (currentNights > 0 && currentChildSupplement > 0) {
                const nightRatio = newNights / currentNights;
                newChildSupplement = currentChildSupplement * nightRatio;
            }

            const newLevyAmount = levyRate > 0 ? ((newBaseAmount + newChildSupplement) * (levyRate / 100)) : 0;
            const newSubtotal = newBaseAmount + newChildSupplement + newLevyAmount;
            // Mode-aware VAT (mirrors calculateDateAdjustmentAmount server-side).
            const newVatAmount = vatMode === 'inclusive' ? newSubtotal * (vatRate / (100 + vatRate))
                : (vatMode === 'exclusive' ? newSubtotal * (vatRate / 100) : 0);
            const newTotal = vatMode === 'exclusive' ? newSubtotal + newVatAmount : newSubtotal;
            const amountDelta = newTotal - currentTotal;
            const nightsDelta = newNights - currentNights;

            // Update preview
            document.getElementById('previewNights').textContent = newNights + ' night' + (newNights !== 1 ? 's' : '');

            const nightsDeltaEl = document.getElementById('previewNightsDelta');
            if (nightsDelta > 0) {
                nightsDeltaEl.textContent = '+' + nightsDelta + ' night' + (nightsDelta !== 1 ? 's' : '');
                nightsDeltaEl.style.color = '#1f7a42';
            } else if (nightsDelta < 0) {
                nightsDeltaEl.textContent = nightsDelta + ' night' + (nightsDelta !== -1 ? 's' : '');
                nightsDeltaEl.style.color = '#a03030';
            } else {
                nightsDeltaEl.textContent = 'No change';
                nightsDeltaEl.style.color = '#666';
            }

            document.getElementById('previewNewTotal').textContent = currencySymbol + Number(newTotal).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            const deltaEl = document.getElementById('previewDelta');
            if (amountDelta > 0) {
                deltaEl.textContent = '+' + currencySymbol + Number(Math.abs(amountDelta)).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' additional charge';
                deltaEl.style.color = '#a03030';
            } else if (amountDelta < 0) {
                deltaEl.textContent = '-' + currencySymbol + Number(Math.abs(amountDelta)).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' refund/credit';
                deltaEl.style.color = '#1f7a42';
            } else {
                deltaEl.textContent = 'No change';
                deltaEl.style.color = '#666';
            }

            // Warning for significant changes (more than 50% increase or decrease)
            const changePercent = Math.abs(amountDelta / currentTotal * 100);
            if (changePercent > 50 && amountDelta !== 0) {
                const warningDiv = document.getElementById('dateAdjustWarning');
                if (warningDiv) {
                    warningDiv.style.display = 'block';
                    warningDiv.textContent = 'Warning: This adjustment represents a ' + changePercent.toFixed(0) + '% change in the booking total.';
                }
            }

            preview.style.display = 'block';
        }
    </script>

    <script src="js/admin-components.js"></script>

    <!-- Quotation Modal -->
    <div id="bk-quotation-modal" class="admin-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bk-quotation-modal-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="bk-quotation-modal-title" class="admin-modal__title">
                    <i class="fas fa-file-invoice"></i> Send Quotation
                </h3>
                <button type="button" class="admin-modal__close" onclick="closeBookingQuoteModal()" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="bk-quotation-summary" style="background:#FAF6F0;border-radius:6px;padding:14px 16px;margin-bottom:18px;font-size:14px;"></div>
                <div class="form-group">
                    <label for="bk-quotation-valid-days" style="font-size:14px;font-weight:500;color:#333;">Quotation valid for</label>
                    <select id="bk-quotation-valid-days" class="form-control" style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;">
                        <option value="1">1 day</option>
                        <option value="2">2 days</option>
                        <option value="3">3 days</option>
                        <option value="5">5 days</option>
                        <option value="7" selected>7 days</option>
                        <option value="14">14 days</option>
                        <option value="21">21 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>
                <div class="form-group" style="margin-top:14px;">
                    <label for="bk-quotation-notes" style="font-size:14px;font-weight:500;color:#333;">Note to guest <span style="color:#999;font-weight:400;">(optional)</span></label>
                    <textarea id="bk-quotation-notes" class="form-control" rows="3" placeholder="e.g. Rates include complimentary breakfast. Call us to discuss group discounts." style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
                <div id="bk-quotation-feedback" style="display:none;margin-top:14px;"></div>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookingQuoteModal()">Cancel</button>
                <button type="button" id="bk-quotation-send-btn" class="btn" style="background:#2F4F78;color:#fff;border-color:#2F4F78;" onclick="sendBookingQuotation()">
                    <i class="fas fa-paper-plane"></i> Send Quotation
                </button>
            </div>
        </div>
    </div>

    <script>
        var _bkQuotationBookingId = 0;

        function openBookingQuoteModal(bookingId, ref, guestName, guestEmail) {
            _bkQuotationBookingId = bookingId;
            var sumEl = document.getElementById('bk-quotation-summary');
            sumEl.innerHTML = '<strong>' + _bkQuoteEsc(guestName) + '</strong>' +
                ' &mdash; <span style="color:#555;">' + _bkQuoteEsc(guestEmail) + '</span>' +
                '<br><small style="color:#999;">Ref: ' + _bkQuoteEsc(ref) + '</small>';
            document.getElementById('bk-quotation-valid-days').value = '7';
            document.getElementById('bk-quotation-notes').value = '';
            var fb = document.getElementById('bk-quotation-feedback');
            fb.style.display = 'none';
            fb.innerHTML = '';
            var btn = document.getElementById('bk-quotation-send-btn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Quotation';
            document.getElementById('bk-quotation-modal').style.display = 'flex';
        }

        function closeBookingQuoteModal() {
            document.getElementById('bk-quotation-modal').style.display = 'none';
            _bkQuotationBookingId = 0;
        }

        function _bkQuoteEsc(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }

        function sendBookingQuotation() {
            if (!_bkQuotationBookingId) {
                return;
            }
            var btn = document.getElementById('bk-quotation-send-btn');
            var fb = document.getElementById('bk-quotation-feedback');
            var validDays = parseInt(document.getElementById('bk-quotation-valid-days').value, 10);
            var notes = document.getElementById('bk-quotation-notes').value.trim();
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrf = window._rhCsrf || (csrfMeta ? csrfMeta.getAttribute('content') : '');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending\u2026';
            fb.style.display = 'none';

            fetch('api/send-quotation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf: csrf,
                        booking_id: _bkQuotationBookingId,
                        valid_days: validDays,
                        quotation_notes: notes
                    })
                })
                .then(async function(res) {
                    var contentType = (res.headers.get('content-type') || '').toLowerCase();
                    if (!contentType.includes('application/json')) {
                        throw new Error('We could not confirm the quotation send result. Please sign in again and retry.');
                    }
                    var data = await res.json();
                    if (!res.ok || !data.success) {
                        throw new Error(data.error || data.message || 'We could not send the quotation right now.');
                    }
                    return data;
                })
                .then(function(data) {
                    var successMessage = data.message || 'Quotation email sent successfully.';
                    fb.style.cssText = 'display:block;background:#D4EDDA;color:#155724;padding:12px 14px;border-radius:4px;font-size:14px;';
                    fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + _bkQuoteEsc(successMessage);
                    showBookingActionMessage(successMessage, 'success');
                    btn.innerHTML = '<i class="fas fa-check"></i> Sent';
                    setTimeout(function() {
                        closeBookingQuoteModal();
                        location.reload();
                    }, 1800);
                })
                .catch(function(err) {
                    var friendlyMessage = (err && err.message) ? err.message : 'Network error. Please try again.';
                    fb.style.cssText = 'display:block;background:#F8D7DA;color:#721C24;padding:12px 14px;border-radius:4px;font-size:14px;';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + _bkQuoteEsc(friendlyMessage);
                    showBookingActionMessage(friendlyMessage, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Retry';
                });
        }

        document.getElementById('bk-quotation-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBookingQuoteModal();
            }
        });
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

