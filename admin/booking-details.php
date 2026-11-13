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
require_once __DIR__ . '/includes/finance-account-sync.php';
require_once __DIR__ . '/includes/account-receipt-send.php';
require_once __DIR__ . '/../includes/booked-rate.php'; // booked rate × nights line (owner 2026-11-09)
require_once __DIR__ . '/../includes/quotation-convert.php'; // "Made from quotation" on the Invoices panel
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
    $_eb = $pdo->prepare("SELECT id, status, amount_paid, amount_due, total_amount, deleted_at FROM bookings WHERE id = ?");
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
    // A deleted booking is read-only until restored (restore runs through bookings.php).
    if (!empty($_early_booking['deleted_at'])) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'This booking has been deleted. Restore it before making changes.']);
            exit;
        }
        $_SESSION['error_message'] = 'This booking has been deleted. Restore it before making changes.';
        header('Location: booking-details.php?id=' . $booking_id);
        exit;
    }
    // Each action needs the permission its button is shown for (the buttons are hidden without it).
    $_bdNeed = null;
    if (isset($_POST['booking_action'])) {
        $_bdNeed = rhBookingActionPermission((string)$_POST['booking_action']);
    } elseif (isset($_POST['adjust_dates'])) {
        $_bdNeed = rhBookingActionPermission('adjust_dates');
    } elseif (isset($_POST['update_payment'])) {
        $_bdNeed = rhBookingActionPermission('payment');
    } elseif (isset($_POST['charge_action'])) {
        $_bdNeed = rhBookingActionPermission((string)$_POST['charge_action']);
    } elseif (isset($_POST['checkin_arrangement_save'])) {
        $_bdNeed = 'edit_booking'; // pay-later check-in arrangement (owner 2026-11-12: anyone who can edit bookings)
    } elseif (isset($_POST['billing_save'])) {
        $_bdNeed = 'billing_on_account'; // Who pays: company account (owner 2026-11-13, migration 084)
    }
    if ($_bdNeed !== null && !hasPermission((int)($user['id'] ?? 0), $_bdNeed)) {
        $_bdMsg = 'You do not have permission to do that (' . (getAllPermissions()[$_bdNeed]['label'] ?? $_bdNeed) . ').';
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $_bdMsg]);
            exit;
        }
        $_SESSION['error_message'] = $_bdMsg;
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

                if (!in_array($charge_type, ['custom', 'service', 'minibar', 'laundry', 'room_service', 'breakfast', 'other'], true)) {
                    $_SESSION['error_message'] = 'Invalid charge type.';
                } elseif (mb_strlen($description) > 255) {
                    $_SESSION['error_message'] = 'The description is too long (255 characters maximum).';
                } elseif ($quantity <= 0 || $quantity > 100000) {
                    $_SESSION['error_message'] = 'Quantity must be greater than 0.';
                } elseif (empty($description)) {
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

                // The lifecycle guard above checked THIS booking; the charge must belong to it, or a
                // charge on a checked-out/cancelled booking could be voided through an open one.
                $chargeOwner = $pdo->prepare('SELECT booking_id FROM booking_charges WHERE id = ?');
                $chargeOwner->execute([max(0, $charge_id)]);
                $chargeOwnerId = $chargeOwner->fetchColumn();

                if ($charge_id <= 0 || $chargeOwnerId === false || (int)$chargeOwnerId !== (int)$booking_id) {
                    $_SESSION['error_message'] = 'Invalid charge ID.';
                } elseif (mb_strlen($void_reason) > 255) {
                    $_SESSION['error_message'] = 'The void reason is too long (255 characters maximum).';
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

    // A settled booking gets its receipt, never an invoice (owner rule).
    if ($lcInvoiceAction === 'send_invoice') {
        require_once 'includes/finance-account-sync.php';
        $paidRefusal = rh_invoice_paid_refusal($pdo, 'room', (int)$booking_id);
        if ($paidRefusal !== null) {
            $_SESSION['error_message'] = $paidRefusal;
            header('Location: booking-details.php?id=' . $booking_id . '#invoices');
            exit;
        }
    }

    try {
        switch ($invoice_action) {
            case 'guest_extras_invoice':
            case 'guest_extras_email':
                // Company pays the stay only (owner 2026-11-13): the guest's extras-only document, EXT-<booking ref>.
                require_once '../config/invoice.php';
                $gxRes = rh_guest_extras_document($pdo, (int)$booking_id, $invoice_action === 'guest_extras_email', (int)($user['id'] ?? 0));
                if (empty($gxRes['success'])) {
                    $_SESSION['error_message'] = $gxRes['message'];
                    break;
                }
                if ($invoice_action === 'guest_extras_invoice' && is_file((string)$gxRes['filepath'])) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . basename((string)$gxRes['filepath']) . '"');
                    readfile((string)$gxRes['filepath']);
                    exit;
                }
                $_SESSION[!empty($gxRes['email_sent']) ? 'success_message' : 'error_message'] = $gxRes['message'];
                break;

            case 'generate_invoice':
                // Generate invoice without sending
                require_once '../config/invoice.php';
                // A settled booking gets its paid-in-full statement under the number it already holds, never a new invoice.
                if (rh_account_fully_paid($pdo, 'room', (int)$booking_id)) {
                    $stmtRes = rh_issue_paid_statement($pdo, (int)$booking_id, false);
                    if (!empty($stmtRes['success'])) {
                        $_SESSION['success_message'] = 'This booking is paid in full, so the paid-in-full statement was generated: ' . $stmtRes['invoice_number'];
                    } else {
                        $_SESSION['error_message'] = 'Failed to generate the statement: ' . $stmtRes['message'];
                    }
                    break;
                }
                // The booking keeps the invoice number it already holds (a converted quotation): never a second number.
                $genNumStmt = $pdo->prepare("SELECT final_invoice_number FROM bookings WHERE id = ?");
                $genNumStmt->execute([$booking_id]);
                $genNumber = trim((string)$genNumStmt->fetchColumn());
                $invoice_result = generateInvoicePDF($booking_id, $genNumber !== '' ? $genNumber : null);
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
                // Regenerating keeps the number already issued (it never burns a new one from the sequence). A
                // settled booking gets the paid-in-full statement instead of an invoice.
                if (rh_account_fully_paid($pdo, 'room', (int)$booking_id)) {
                    $stmtRes = rh_issue_paid_statement($pdo, (int)$booking_id, false);
                    if (!empty($stmtRes['success'])) {
                        $_SESSION['success_message'] = 'This booking is paid in full, so the paid-in-full statement was regenerated: ' . $stmtRes['invoice_number'];
                    } else {
                        $_SESSION['error_message'] = 'Failed to regenerate the statement: ' . $stmtRes['message'];
                    }
                    break;
                }
                $regenNumStmt = $pdo->prepare("SELECT final_invoice_number FROM bookings WHERE id = ?");
                $regenNumStmt->execute([$booking_id]);
                $regenNumber = trim((string)$regenNumStmt->fetchColumn());
                $invoice_result = generateInvoicePDF($booking_id, $regenNumber !== '' ? $regenNumber : null);
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
                           check_in_date, check_out_date, total_amount, total_with_vat, vat_amount
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
                    $inv = generateInvoicePDF($booking_id, trim((string)($waBooking['final_invoice_number'] ?? '')) !== '' ? trim((string)$waBooking['final_invoice_number']) : null);
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

                // Private link that expires in 30 days (invoices/ itself is closed to the web).
                require_once '../includes/doc-links.php';
                $invoiceUrl = empty($waBooking['final_invoice_path']) ? '' : rh_doc_signed_url('booking_invoice', (int)$booking_id, 30);
                if ($invoiceUrl === '') {
                    $_SESSION['error_message'] = 'The invoice link could not be created, so nothing was sent.';
                    break;
                }
                $invoiceRef = $waBooking['final_invoice_number'] ?? $waBooking['booking_reference'];
                $hotelName  = getSetting('hotel_name', 'Hotel');
                $guestName  = $waBooking['guest_name'] ?? 'Guest';

                // Full amount on the invoice: room + VAT + levy + folio extras (owner 2026-10-23), not the ex-VAT base.
                $waFolio = getBookingFolioSummary((int)$booking_id);
                $waInvoiceTotal = isset($waFolio['grand_total']) ? (float)$waFolio['grand_total'] : rh_booking_full_total($waBooking);

                $waMsg = "Dear {$guestName},\n\n"
                    . "Please find your invoice ({$invoiceRef}) for your stay at {$hotelName}.\n\n"
                    . "Check-in: " . date('d M Y', strtotime($waBooking['check_in_date'])) . "\n"
                    . "Check-out: " . date('d M Y', strtotime($waBooking['check_out_date'])) . "\n"
                    . "Total: " . getSetting('currency_symbol', 'MWK') . ' ' . number_format($waInvoiceTotal, 2) . "\n\n"
                    . "View your invoice (link valid for 30 days): {$invoiceUrl}\n\n"
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

// Pay-later check-in arrangement (owner 2026-11-12): anyone with Edit Booking switches "Check in before payment"
// on or off with a short note (invoiced company, owner's stay). Only the check-in payment gate changes; the bill,
// the balance owed and the checkout-with-balance rule stay as they are. Every change goes to the booking history.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkin_arrangement_save'])) {
    $arrOn = !empty($_POST['checkin_without_payment']) ? 1 : 0;
    $arrNote = trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['checkin_arrangement_note'] ?? '')));
    if (!hasPermission((int)($user['id'] ?? 0), 'edit_booking')) {
        $_SESSION['error_message'] = 'You do not have permission to do that (Edit Booking).';
    } elseif (!rh_checkin_arrangement_columns_present($pdo)) {
        $_SESSION['error_message'] = 'Check in before payment is not available yet: database update 083 has not been run.';
    } elseif ($arrOn && $arrNote === '') {
        $_SESSION['error_message'] = 'Add a short note saying why the guest may check in before paying (for example "ICTAM - invoiced monthly" or "Owner\'s stay").';
    } elseif (mb_strlen($arrNote) > 255) {
        $_SESSION['error_message'] = 'The note is too long (255 characters maximum).';
    } else {
        $arrLog = null;
        try {
            $pdo->beginTransaction();
            $arrSt = $pdo->prepare("SELECT booking_reference, status, checkin_without_payment, checkin_arrangement_note FROM bookings WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $arrSt->execute([$booking_id]);
            $arrRow = $arrSt->fetch(PDO::FETCH_ASSOC);
            if (!$arrRow) {
                $pdo->rollBack();
                $_SESSION['error_message'] = 'Booking not found.';
            } elseif (!in_array((string)$arrRow['status'], ['pending', 'tentative', 'confirmed'], true)) {
                $pdo->rollBack();
                $_SESSION['error_message'] = 'Check in before payment can only be changed before the guest checks in.';
            } else {
                $oldOn = !empty($arrRow['checkin_without_payment']) ? 1 : 0;
                $oldNote = trim((string)($arrRow['checkin_arrangement_note'] ?? ''));
                $newNote = $arrOn ? $arrNote : '';
                if ($oldOn === $arrOn && $oldNote === $newNote) {
                    $pdo->rollBack();
                    $_SESSION['success_message'] = 'Nothing changed.';
                } else {
                    $pdo->prepare("UPDATE bookings SET checkin_without_payment = ?, checkin_arrangement_note = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$arrOn, $arrOn ? $newNote : null, $booking_id]);
                    $pdo->commit();
                    $arrLog = [(string)$arrRow['booking_reference'], $oldOn, $arrOn, $oldNote, $newNote];
                    $_SESSION['success_message'] = $arrOn
                        ? 'Check in before payment is on for this booking. The balance stays owed.'
                        : 'Check in before payment is off: a payment is needed again before check-in.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[checkin-arrangement] save failed for booking ' . $booking_id . ': ' . $e->getMessage());
            $_SESSION['error_message'] = 'Could not save the change. Nothing was changed.';
        }
        if ($arrLog !== null) {
            [$arrRef, $oldOn, $newOn, $oldNote, $newNote] = $arrLog;
            $onOff = static function (int $v): string { return $v ? 'On' : 'Off'; };
            if ($oldOn !== $newOn) {
                $arrDesc = 'Check in before payment: ' . $onOff($oldOn) . ' → ' . $onOff($newOn) . '.'
                    . ($newOn ? ' Note: ' . $newNote : ($oldNote !== '' ? ' Previous note: ' . $oldNote : ''));
            } else {
                $arrDesc = 'Check in before payment note: "' . $oldNote . '" → "' . $newNote . '".';
            }
            logBookingEvent(
                (int)$booking_id, $arrRef,
                $newOn ? ($oldOn ? 'Pay-later arrangement note changed' : 'Pay-later arrangement switched on') : 'Pay-later arrangement switched off',
                'update', $arrDesc, $onOff($oldOn), $onOff($newOn), 'admin',
                isset($user['id']) ? (int)$user['id'] : null, $user['full_name'] ?? null,
                ['checkin_without_payment' => ['old' => $oldOn, 'new' => $newOn], 'arrangement_note' => ['old' => $oldNote, 'new' => $newNote]]
            );
        }
    }
    header('Location: booking-details.php?id=' . $booking_id);
    exit;
}

// Who pays: put the booking on a company account / take it off (owner 2026-11-13, migration 084; admin/includes/billing-panel.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_save'])) {
    require_once __DIR__ . '/includes/billing-panel.php';
    rh_billing_panel_save($pdo, (int)$booking_id, $_POST, $user);
    header('Location: booking-details.php?id=' . $booking_id);
    exit;
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
                    // Convert exactly once: the status condition in the UPDATE decides the winner, so a
                    // double click / second tab can neither double-decrement stock nor send two emails.
                    $pdo->beginTransaction();
                    $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, tentative_expires_at = NULL, converted_to_confirmed_at = NOW(), converted_from_tentative = 1, updated_at = NOW() WHERE id = ? AND status = 'tentative' AND is_tentative = 1");
                    $update->execute([$booking_id]);
                    if ($update->rowCount() !== 1) {
                        $pdo->rollBack();
                        $_SESSION['error_message'] = 'This booking was already converted or changed.';
                        break;
                    }

                    // Decrement room availability — tentative bookings don't consume rooms_available,
                    // but confirmed bookings do; apply the same decrement as pending→confirmed.
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                        ->execute([$booking_data['room_id']]);
                    $pdo->prepare("INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason) VALUES (?, 'converted', ?, ?)")
                        ->execute([$booking_id, (int)$user['id'], 'Converted to confirmed by ' . $user['full_name']]);
                    $pdo->commit();

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
                $check_stmt = $pdo->prepare("SELECT b.status, b.payment_status, b.individual_room_id, b.room_id, b.booking_reference, b.check_in_date" . rh_checkin_rule_select('b') . ", ir.status as room_status FROM bookings b LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE b.id = ?");
                $check_stmt->execute([$booking_id]);
                $check_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$check_row) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } else {
                    $validation = validateCheckIn($check_row);
                    $roomGate = $validation['allowed']
                        ? evaluateCheckInRoomReady((int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkin_room_not_ready']))
                        : null;
                    if (!$validation['allowed']) {
                        $_SESSION['error_message'] = getBookingActionErrorMessage('check_in', $validation['reason']);
                    } elseif ((int)($validation['late_days'] ?? 0) > 0) {
                        // A late arrival is checked in on the bookings list, where the dialog asks what happened
                        // (owner 2026-11-12 "Dont assume, have options"). Nothing is changed here.
                        $_SESSION['error_message'] = 'This arrival is ' . (int)$validation['late_days'] . ' day(s) late. Use Late Check-In so you can say when the guest arrived.';
                    } elseif (!$roomGate['allowed']) {
                        $_SESSION['error_message'] = $roomGate['message'];
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE bookings SET status = 'checked-in', updated_at = NOW() WHERE id = ?")->execute([$booking_id]);
                        $updatedRoomCount = updateBookingRoomsStatus($booking_id, 'occupied', 'Guest checked in', $user['id'] ?? null);
                        $pdo->commit();

                        // Timeline event (outside transaction — non-fatal if it fails)
                        logBookingCheckIn($booking_id, $check_row['booking_reference'], 'admin', $user['id'], $user['full_name']);
                        if ($roomGate['override']) {
                            logCheckInRoomNotReadyOverride((int)$booking_id, (string)$check_row['booking_reference'], $roomGate['rooms'], $user['id'] ?? null, $user['full_name'] ?? null);
                        }
                        if (!empty($validation['via_arrangement'])) {
                            logCheckInUnderArrangement((int)$booking_id, (string)$check_row['booking_reference'], (string)$validation['arrangement_note'], $user['id'] ?? null, $user['full_name'] ?? null);
                        }

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
                        // "Email the guest a check-in confirmation" box (owner 2026-11-12). No field (old cached page)
                        // = today's behaviour on this page: no email. Never blocks the check-in.
                        if (array_key_exists('email_guest', $_POST)) {
                            require_once __DIR__ . '/../config/email.php';
                            $ciEmailWanted = rh_checkin_email_choice($_POST, false);
                            $ciEmailSent = rh_checkin_guest_email($pdo, (int)$booking_id, $ciEmailWanted, isset($user['id']) ? (int)$user['id'] : null, $user['full_name'] ?? null);
                            $successMsg .= $ciEmailWanted ? ($ciEmailSent ? ' Check-in email sent.' : ' The check-in email could not be sent.') : ' No email sent (you chose not to).';
                        }
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
                        // Balance gate (server-side): lock the booking, recalculate, then
                        // block unless settled or a 'checkout_with_balance' user confirmed.
                        $pdo->beginTransaction();
                        $balGate = null;
                        try {
                            $pdo->prepare("SELECT id FROM bookings WHERE id = ? FOR UPDATE")->execute([$booking_id]);
                            $balGate = evaluateCheckoutBalance($pdo, (int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkout_with_balance']));
                            if ($balGate['allowed']) {
                                $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = NOW(), updated_at = NOW() WHERE id = ?");
                                $stmt->execute([$booking_id]);

                                // Restore room availability
                                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                                    ->execute([$checkout_row['room_id']]);
                                // The stay is over: stop holding its rooms (booking_rooms.released_at).
                                rh_release_booking_room_holds($pdo, (int)$booking_id);
                                $pdo->commit();
                            } else {
                                $pdo->rollBack();
                            }
                        } catch (\Throwable $coErr) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw new RuntimeException('Checkout failed - nothing was changed. ' . $coErr->getMessage());
                        }
                    }
                    if (isset($balGate) && !$balGate['allowed']) {
                        $_SESSION['error_message'] = $balGate['message'];
                    } elseif (isset($balGate)) {
                        if ($balGate['override']) {
                            logCheckoutBalanceOverride((int)$booking_id, (string)$checkout_row['booking_reference'], (float)$balGate['balance'], (int)($user['id'] ?? 0), $user['full_name'] ?? null);
                        }

                        logBookingCheckOut($booking_id, $checkout_row['booking_reference'], 'admin', $user['id'], $user['full_name']);

                        $updatedRoomCount = updateBookingRoomsStatus($booking_id, 'cleaning', 'Guest checked out', $user['id'] ?? null);

                        // Generate and send final invoice
                        require_once '../config/invoice.php';
                        // Pay-later booking: emailed to the guest only when "Email the final invoice to the guest" was ticked.
                        $invoice_result = generateAndSendFinalInvoice($booking_id, $user['id'], !empty($_POST['email_final_invoice']) ? true : null);

                        // "Guest has left": a room cleaned before checkout is not sent to cleaning again - say so.
                        $coCleaningRooms = 0;
                        $coRoomIds = $updatedRoomCount > 0 ? getBookingRoomIds((int)$booking_id, true) : [];
                        if ($coRoomIds) {
                            $coSt = $pdo->prepare("SELECT COUNT(*) FROM individual_rooms WHERE status = 'cleaning' AND id IN (" . implode(',', array_fill(0, count($coRoomIds), '?')) . ")");
                            $coSt->execute(array_values($coRoomIds));
                            $coCleaningRooms = (int)$coSt->fetchColumn();
                        }
                        $checkout_message = 'Guest checked out successfully. Room availability restored.' .
                            ($updatedRoomCount > 0 ? ($coCleaningRooms > 0 ? ' Room assignment marked for cleaning.' : ' Room was already cleaned after the guest left.') : '');

                        if ($invoice_result['success']) {
                            if (!$invoice_result['idempotent']) {
                                $checkout_message .= ' Final invoice generated.';
                            }
                            if (!empty($invoice_result['staff_note'])) {
                                $checkout_message .= ' ' . (string)$invoice_result['staff_note']; // company account: sent to the company (or why not)
                            } elseif (!empty($invoice_result['email_skipped'])) {
                                $checkout_message .= ' The final invoice was not emailed to the guest (pay-later booking, box not ticked).';
                            } elseif (!$invoice_result['email_sent']) {
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
                // One transaction (orders, status, rooms, holds, refund) in rh_mark_booking_no_show();
                // the guest email stays here, after the commit.
                require_once __DIR__ . '/../includes/booking-functions.php';
                $ns = rh_mark_booking_no_show($pdo, (int)$booking_id, (int)($user['id'] ?? 0), (string)($user['full_name'] ?? ''), '');
                if (!$ns['success']) {
                    $_SESSION['error_message'] = $ns['error'];
                } else {
                    $noshow_row = $ns['booking'];
                    $refund_result = $ns['refund'];

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
                    if (!empty($ns['closed_orders'])) {
                        $msg .= ' Closed order(s) ' . implode(', ', $ns['closed_orders']) . '.';
                    }
                    if ($refund_result['created']) {
                        $msg .= ' Pending refund ' . $refund_result['refund_ref']
                            . ' (' . getSetting('currency_symbol', 'MWK') . ' ' . number_format((float) $refund_result['refund_amount'], 2) . ') queued - review in Payments.';
                    }
                    if (!empty($refund_result['error'])) {
                        $msg .= ' WARNING: the automatic refund could not be created (' . $refund_result['error'] . ') - raise it manually in Payments.';
                    }
                    $msg .= $email_result['success'] ? ' No-show email sent to guest.' : ' (No-show email could not be sent.)';
                    $_SESSION['success_message'] = $msg;
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

                        // Group booking (owner 2026-10-20): the page offers "whole group" and "only this room"
                        // as separate buttons. scope=group cancels every live room of the group in ONE
                        // transaction (all go or none); anything else is today's single-room cancel.
                        $cancelGroupIds = ($_POST['scope'] ?? '') === 'group' ? rh_booking_group_ids($pdo, (int)$booking_id) : [];
                        if (count($cancelGroupIds) > 1) {
                            $grpRes = rh_cancel_booking_group($pdo, $cancelGroupIds, (int)($user['id'] ?? 0), (string)$cancellation_reason);
                            if (!$grpRes['success']) {
                                throw new RuntimeException('Cancellation failed - nothing was changed. ' . $grpRes['error']);
                            }
                            require_once '../config/email.php';
                            $emailsSent = 0;
                            $emailsFailed = 0;
                            // Owner 2026-11-12: "Email the guest" unticked = cancel quietly (no email or WhatsApp to the guest;
                            // the hotel's own WhatsApp alert still goes).
                            $cancelEmailGuest = rh_cancel_email_choice($_POST, rh_cancel_email_default_ticked('details', (string)$previous_status));
                            foreach ($grpRes['cancelled'] as $gc) {
                                logBookingStatusChange($gc['id'], $gc['reference'], $gc['previous_status'], 'cancelled', 'admin', $user['id'], $user['full_name'], $cancellation_reason);
                                $gcStmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
                                $gcStmt->execute([$gc['id']]);
                                $gcRow = $gcStmt->fetch(PDO::FETCH_ASSOC);
                                $gcMail = ['success' => false, 'message' => 'Booking not found'];
                                if ($gcRow && !$cancelEmailGuest) {
                                    $gcMail = ['success' => false, 'message' => rh_cancel_email_skipped_reason()];
                                    rh_email_send_log_not_sent('booking_cancelled', (string)$gcRow['guest_email'], (string)$gcRow['guest_name'],
                                        rh_email_ctx_account('room', $gcRow['id'], $gcRow['booking_reference']), 'Staff chose not to email the guest');
                                    sendBookingCancelledHotelAlert($gcRow);
                                } elseif ($gcRow) {
                                    $gcMail = sendBookingCancelledEmail($gcRow, $cancellation_reason);
                                    if (!empty($gcMail['success'])) {
                                        $emailsSent++;
                                    } else {
                                        $emailsFailed++;
                                    }
                                }
                                // cancellation_log, one row per cancelled booking (as All Bookings does).
                                logCancellationToDatabase((int)$gc['id'], (string)$gc['reference'], 'room', (string)($gcRow['guest_email'] ?? ''), $user['id'],
                                    (string)$cancellation_reason, !empty($gcMail['success']), (string)($gcMail['message'] ?? ''));
                            }
                            $_SESSION['success_message'] = $grpRes['message']
                                . (!$cancelEmailGuest ? ' No email sent (you chose not to).' : '')
                                . ($emailsSent > 0 ? ' Cancellation email sent for ' . $emailsSent . ' booking(s).' : '')
                                . ($emailsFailed > 0 ? ' (Email failed for ' . $emailsFailed . ' booking(s).)' : '');
                        } else {
                            // One transaction: status + room/stock release + bill treatment +
                            // refund, booking row locked. Any failure rolls everything back.
                            $cancelRes = cancelRoomBookingSettled($pdo, (int)$booking_id, (int)($user['id'] ?? 0), (string)$cancellation_reason);
                            if (!$cancelRes['success']) {
                                throw new RuntimeException('Cancellation failed - nothing was changed. ' . $cancelRes['error']);
                            }

                            logBookingStatusChange($booking_id, $booking_to_cancel['booking_reference'], $previous_status, 'cancelled', 'admin', $user['id'], $user['full_name'], $cancellation_reason);
                            $cancel_refund_msg = ' ' . $cancelRes['summary'];

                            require_once '../config/email.php';
                            // Owner 2026-11-12: "Email the guest" unticked = cancel quietly (no email or WhatsApp to the guest;
                            // the hotel's own WhatsApp alert still goes).
                            if (!rh_cancel_email_choice($_POST, rh_cancel_email_default_ticked('details', (string)$previous_status))) {
                                $email_result = ['success' => false, 'message' => rh_cancel_email_skipped_reason()];
                                rh_email_send_log_not_sent('booking_cancelled', (string)$booking_to_cancel['guest_email'], (string)$booking_to_cancel['guest_name'],
                                    rh_email_ctx_account('room', $booking_to_cancel['id'], $booking_to_cancel['booking_reference']), 'Staff chose not to email the guest');
                                sendBookingCancelledHotelAlert($booking_to_cancel);
                                $_SESSION['success_message'] = 'Booking cancelled.' . $cancel_refund_msg . ' No email sent (you chose not to).';
                            } else {
                                $email_result = sendBookingCancelledEmail($booking_to_cancel, $cancellation_reason);

                                $_SESSION['success_message'] = 'Booking cancelled.' . $cancel_refund_msg .
                                    ($email_result['success'] ? ' Cancellation email sent.' : ' (Email failed)');
                            }
                            // cancellation_log (as All Bookings does): whether the guest was emailed and why not.
                            logCancellationToDatabase((int)$booking_id, (string)$booking_to_cancel['booking_reference'], 'room', (string)($booking_to_cancel['guest_email'] ?? ''),
                                $user['id'], (string)$cancellation_reason, !empty($email_result['success']), (string)($email_result['message'] ?? ''));
                        }
                    }
                }
                break;
        }
    } catch (\Throwable $e) {
        // RuntimeException carries a deliberate, staff-safe message (e.g. a failed
        // cancellation that was rolled back); anything else stays generic.
        $_SESSION['error_message'] = ($e instanceof RuntimeException && $e->getMessage() !== '')
            ? $e->getMessage()
            : 'Action failed. Please try again.';
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

    // ── Guest history (one rule with the Create Booking lookup: includes/guest-history.php) ──
    // Money: shown to whoever can open this page, the same people who see this booking's Paid figure (page
    // permission 'bookings'); checked explicitly so it stays tied to that rule.
    require_once __DIR__ . '/../includes/guest-history.php';
    $guest_history = rh_guest_history(
        $pdo,
        (string)($booking['guest_email'] ?? ''),
        (int)$booking_id,
        hasPermission((int)($user['id'] ?? 0), 'bookings'),
        6
    );

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
        SELECT b.id, b.booking_reference, b.status, b.number_of_guests, b.room_guest_name,
               b.total_with_vat, b.total_amount, b.vat_amount, b.primary_booking_id,
               b.number_of_nights, b.tourism_levy_amount, b.tourism_levy_percent, b.vat_rate, b.child_supplement_total, b.child_guests,
               b.child_price_multiplier, b.child_charge_mode, b.child_charge_amount, b.free_children, b.package_total,
               b.rate_plan_label, b.rate_plan_discount,
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

    // Activity timeline: the same step-by-step history as the History panel (includes/booking-history.php), newest first.
    require_once __DIR__ . '/../includes/booking-history.php';
    $timeline = array_reverse(rh_booking_history($pdo, (int)$booking_id, ['light' => true]));

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

    // Invoices made from a quotation (Quotations > Convert to invoice): tag the listed invoice with its quotation, and
    // list a converted invoice that is not shown yet (e.g. its number was already on the booking without a file).
    $bd_quote_invoices = [];
    if (rh_qc_columns_present($pdo)) {
        $bdQiSt = $pdo->prepare("SELECT id, quote_reference, converted_invoice_number, converted_invoice_path, converted_at FROM quotations
            WHERE booking_type = 'room' AND booking_id = ? AND converted_at IS NOT NULL ORDER BY converted_at ASC");
        $bdQiSt->execute([$booking_id]);
        $bdCanOpenQuoteInv = hasPermission((int)($user['id'] ?? 0), 'create_booking') && hasPermission((int)($user['id'] ?? 0), 'invoices');
        foreach ($bdQiSt->fetchAll(PDO::FETCH_ASSOC) as $bdQi) {
            $bdNum = trim((string)$bdQi['converted_invoice_number']);
            if ($bdNum === '') {
                continue;
            }
            $bd_quote_invoices[$bdNum] = (string)$bdQi['quote_reference'];
            $bdListed = false;
            foreach ($existing_invoices as $bdInv) {
                if (trim((string)($bdInv['invoice_number'] ?? '')) === $bdNum) {
                    $bdListed = true;
                    break;
                }
            }
            if (!$bdListed) {
                $existing_invoices[] = [
                    'id'                => null,
                    'invoice_number'    => $bdNum,
                    'invoice_path'      => null,
                    'invoice_generated' => 1,
                    'created_at'        => (string)$bdQi['converted_at'],
                    'quote_download'    => $bdCanOpenQuoteInv ? 'quotations.php?invoice=' . (int)$bdQi['id'] : '',
                ];
            }
        }
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
// Guest-bill charges and confirming need Edit Booking (owner 2026-10-19); the server enforces it above.
$bdCanEditBooking = hasPermission((int)($user['id'] ?? 0), 'edit_booking');
if (!$bdCanEditBooking) {
    foreach (['can_add_charge', 'can_void_charge'] as $_bdFlag) {
        if (!empty($bPerms[$_bdFlag])) {
            $bPerms[$_bdFlag] = false;
            $bPerms[$_bdFlag . '_reason'] = 'Needs the Edit Booking permission.';
        }
    }
}

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
            if (!empty($result['rate_plan_removed']['removed'])) {
                $message .= ' ' . rh_rate_plan_drop_sentence($result['rate_plan_removed']);
            }

            // Any extra amount is carried by the recalculated booking balance (amount_due);
            // no placeholder payment row is created.
            if ($delta > 0.01) {
                $message .= ' The additional amount now shows as balance due - collect before check-out.';
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
    // payment_status is DERIVED from real payments, never taken from the form.
    // The form value is only a request: 'paid' means "settle the outstanding
    // balance now"; anything else just re-derives the status from the ledger.
    $requested_status = (string)($_POST['payment_status'] ?? '');
    if (!in_array($requested_status, ['unpaid', 'partial', 'paid'], true)) {
        $requested_status = '';
    }
    // Owner 2026-11-12: no one-click "mark paid" (it booked the whole balance as cash). A payment is recorded with its
    // amount and method on Record payment (payment-add.php), the one path with the limits, VAT split and receipt.
    if ($requested_status === 'paid') {
        $_SESSION['error_message'] = 'Nothing was recorded. Use Record payment to enter the amount the guest paid and how.';
        header('Location: booking-details.php?id=' . (int)$booking_id);
        exit;
    }
    $previous_status = $booking['payment_status'];

    try {
        $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $configuredVatRate = $vatEnabled ? (float) getSetting('vat_rate') : 0;

        $levyAmount = (float)($booking['tourism_levy_amount'] ?? 0);
        $levyPercent = (float)($booking['tourism_levy_percent'] ?? 0);
        $paymentNotes = 'Full settlement from booking details page.';
        if ($levyAmount > 0) {
            $paymentNotes .= ' Tourism levy included' . ($levyPercent > 0 ? ' (' . number_format($levyPercent, 2) . '%).' : '.');
        }

        $new_payment_id = 0;
        $paymentAlreadyRecorded = false;
        $settleRequested = ($requested_status === 'paid');

        $pdo->beginTransaction();
        // Lock the booking row, then size the settlement from the live folio balance.
        $lockStmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$booking_id]);
        if (!recalculateBookingFinancials($booking_id)) {
            throw new RuntimeException('Could not recalculate the booking balance.');
        }

        if ($settleRequested) {
            $summaryForPayment = getBookingFolioSummary($booking_id);
            $settleAmount = round((float)($summaryForPayment['balance_due'] ?? 0), 2);

            if ($settleAmount > BALANCE_TOLERANCE) {
                // Same split as admin/payment-add.php: the amount received is GROSS,
                // payment_amount is the NET portion, vat_amount the extracted VAT.
                $paymentVatRate = $configuredVatRate;
                $paymentVatAmount = $paymentVatRate > 0 ? round($settleAmount * ($paymentVatRate / (100 + $paymentVatRate)), 2) : 0.0;
                $paymentTotalWithVat = $settleAmount;
                $paymentSubtotal = round($settleAmount - $paymentVatAmount, 2);
                // Prefer the account's own VAT ratio (levy / zero-rated lines carry no VAT).
                if (!function_exists('rh_account_vat_split') && is_file(__DIR__ . '/includes/finance-account-sync.php')) {
                    require_once __DIR__ . '/includes/finance-account-sync.php';
                }
                if (function_exists('rh_account_vat_split')) {
                    $acctSplit = rh_account_vat_split($pdo, 'room', (int)$booking_id, $settleAmount, $paymentVatRate);
                    $paymentVatRate = $acctSplit['rate'];
                    $paymentVatAmount = $acctSplit['vat'];
                    $paymentSubtotal = $acctSplit['net'];
                }

                $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(uniqid(), -4));
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

                logBookingPayment($booking_id, $booking['booking_reference'], $paymentTotalWithVat, 'full', 'cash', 'completed', $user['id'], $payment_reference);
                // Status, amount_paid and amount_due are re-derived from the ledger;
                // total_with_vat (the bill) is never written from here.
                if (!recalculateBookingFinancials($booking_id)) {
                    throw new RuntimeException('Could not recalculate the booking balance after the payment.');
                }
            } else {
                // Nothing outstanding — no second payment is created.
                $paymentAlreadyRecorded = true;
            }
        }

        $pdo->commit();

        if ($settleRequested) {
            $auto_assign_msg = '';
            if (($booking['status'] ?? '') === 'confirmed' && empty($booking['individual_room_id'])) {
                $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                    $auto_assign_msg = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                } elseif (!$autoAssignResult['success']) {
                    $auto_assign_msg = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                }
            }

            if ($paymentAlreadyRecorded) {
                // No new payment was taken, so no invoice or receipt is due — emailing
                // one here would tell the guest they had paid a second time.
                $_SESSION['success_message'] = 'Nothing is owed on this booking. No payment was recorded.' . $auto_assign_msg;
            } else {
                // Money received -> RECEIPT (not an invoice: an invoice requests payment).
                require_once '../config/receipts.php';
                $receipt_after = receipt_after_payment($pdo, $new_payment_id, $user);

                $_SESSION['success_message'] = 'Payment status updated. Payment recorded.' . $auto_assign_msg . $receipt_after['notice'];
            }
        } else {
            $_SESSION['success_message'] = 'Payment status updated.';
        }

        header('Location: booking-details.php?id=' . $booking_id);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = 'Failed to update payment status - nothing was saved: ' . $e->getMessage();
    }
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');

// Status styling
$status_colors = [
    'pending' => ['bg' => '#fef9ec', 'color' => '#92690a', 'icon' => 'fa-clock'],
    'tentative' => ['bg' => '#fff8e1', 'color' => '#7E684B', 'icon' => 'fa-hourglass-half'],
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

$vat_is_inclusive = function_exists('vat_mode') && vat_mode() === 'inclusive';
// One source of truth: getBookingFolioSummary() — grand total, VAT and balance
// due are shown exactly as the finance layer computes them (no page-local third
// balance). Subtotal before VAT is derived from those same figures.
$folio_total_amount = (float)($folio_summary['grand_total'] ?? 0);
$folio_total_vat = (float)($folio_summary['total_vat'] ?? 0);
$folio_subtotal_before_vat = max(0.0, $folio_total_amount - $folio_total_vat);
// Payment breakdown rows, all excl. VAT and levy: Room base + packages + Extras = Subtotal, and
// Subtotal + Tourism levy + VAT = Total (folio grand total, unchanged). Package costs are stored all-in (VAT inside,
// no levy), so each package shows its net; extras show net of their VAT; the room base is what remains (room net).
// A cancelled stay bills only the kept amount (no levy, no VAT on it), so no levy row then. Display only.
if (!function_exists('rh_stay_totals')) {
    require_once __DIR__ . '/../includes/pricing.php';
}
$bd_is_cancelled = (($booking['status'] ?? '') === 'cancelled');
$bd_pkg_rows = [];
$bd_pkg_net_sum = 0.0;
if (!$bd_is_cancelled) {
    foreach (($booking_packages ?? []) as $bp) {
        $bd_pn = round((float) rh_stay_totals((float)($bp['total_cost'] ?? 0), 'price', false)['net'], 2);
        $bd_pkg_rows[] = ['name' => (string)($bp['package_name'] ?? ''), 'net' => $bd_pn];
        $bd_pkg_net_sum += $bd_pn;
    }
}
$bd_levy_row = $bd_is_cancelled ? 0.0 : round(max(0.0, (float)($booking['tourism_levy_amount'] ?? 0)), 2);
$bd_subtotal = round($folio_subtotal_before_vat - $bd_levy_row, 2);
$bd_extras_net = round((float)($folio_summary['extras_total'] ?? 0) - (float)($folio_summary['extras_vat'] ?? 0), 2);
$bd_room_base = isset($folio_summary['grand_total'])
    ? round($bd_subtotal - $bd_extras_net - $bd_pkg_net_sum, 2)
    : round((float)($booking['total_amount'] ?? 0) - $bd_levy_row - $bd_pkg_net_sum, 2);
$bd_plan_pd = (float)($booking['rate_plan_discount'] ?? 0);
$bd_plan_label = trim((string)($booking['rate_plan_label'] ?? ''));
$bd_plan_total = round(abs($bd_plan_pd) * max(1, (int)($booking['number_of_nights'] ?? 1)), 2);
$folio_amount_paid = (float)($folio_summary['amount_paid'] ?? $booking['amount_paid'] ?? 0);
$folio_balance_due = (float)($folio_summary['balance_due'] ?? max(0.0, $folio_total_amount - $folio_amount_paid));
// Money owed BACK to the guest (overpayment, or a stay shortened after payment).
// Balance Due is clamped at zero, so without this the credit is invisible here.
$folio_credit_balance = max(0.0, $folio_amount_paid - $folio_total_amount);
if ($folio_credit_balance <= BALANCE_TOLERANCE) {
    $folio_credit_balance = (float)($booking['credit_balance'] ?? 0);
}
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
$checkout_overdue_state = rh_checkout_overdue_state($booking); // '' | 'late' (due today, past check-out time) | 'overdue'
if ($checkout_overdue_state === 'overdue') {
    $checkout_overdue_days = (int) $checkout_due_date->diff($today_status_date)->days;
}

$booking_alert_message = '';
$booking_alert_tone = 'info';
if ($checkin_overdue_days > 0) {
    $booking_alert_tone = 'warning';
    $booking_alert_message = 'Late check-in pending for ' . $checkin_overdue_days . ' day(s). Action required: check in or mark no-show.';
} elseif ($checkout_overdue_days > 0) {
    $booking_alert_tone = 'danger';
    $booking_alert_message = 'Checkout overdue by ' . $checkout_overdue_days . ' day(s). Check the guest out, or extend the stay (extra nights are charged at the booked rate).';
} elseif ($checkout_overdue_state === 'late') {
    $booking_alert_tone = 'warning';
    $booking_alert_message = 'Due to check out today by ' . getSetting('check_out_time', '11:00 AM') . ' and still in-house. Check out, or extend the stay if they are staying another night.';
} elseif ((string) $booking['status'] === 'tentative' || (int)($booking['is_tentative'] ?? 0) === 1) {
    $booking_alert_tone = 'info';
    $booking_alert_message = 'Tentative booking is awaiting conversion to confirmed status.';
} elseif (($pay_by = rh_unpaid_confirmed_deadline($booking)) !== null) {
    $booking_alert_tone = 'info';
    $booking_alert_message = 'Awaiting payment: unless paid by ' . date('j M Y, g:i A', strtotime($pay_by))
        . ' this booking is automatically cancelled and the room released.';
}

// "Back" returns to the page this booking was opened from (bookings list with its filters, calendar,
// dashboard, ...). Remembered per booking so an edit round-trip (details -> edit -> details) does not
// turn Back into "back to the edit form". Falls back to the last bookings-list view, then the list.
$bd_back_pages = [
    'bookings.php' => 'Bookings', 'calendar.php' => 'Calendar', 'dashboard.php' => 'Dashboard',
    'room-dashboard.php' => 'Room Dashboard', 'tentative-bookings.php' => 'Tentative Bookings',
    'payments.php' => 'Payments', 'invoices.php' => 'Invoices', 'housekeeping.php' => 'Housekeeping',
    'reports.php' => 'Reports', 'end-of-day-report.php' => 'End of Day', 'individual-rooms.php' => 'Rooms',
];
$bdRef = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
if (!empty($bdRef['path']) && (empty($bdRef['host']) || $bdRef['host'] === ($_SERVER['HTTP_HOST'] ?? '') || strpos((string)($_SERVER['HTTP_HOST'] ?? ''), (string)$bdRef['host']) === 0)) {
    $bdRefPage = basename((string)$bdRef['path']);
    parse_str((string)($bdRef['query'] ?? ''), $bdRefQuery);
    // Coming back from a deep link this booking opened (bookings.php?action=assign-room&booking_id=N)
    // is a round trip, not a new origin: keep the original Back target.
    $bdRefIsDeepLink = !empty($bdRefQuery['action']) && (int)($bdRefQuery['booking_id'] ?? 0) === (int)$booking_id;
    if (isset($bd_back_pages[$bdRefPage]) && !$bdRefIsDeepLink) {
        unset($bdRefQuery['action'], $bdRefQuery['booking_id'], $bdRefQuery['export']); // never replay a deep link / export
        $_SESSION['bd_back'][(int)$booking_id] = $bdRefPage . ($bdRefQuery ? '?' . http_build_query($bdRefQuery) : '');
        $_SESSION['bd_back'] = array_slice($_SESSION['bd_back'], -20, null, true);
    }
}
$bd_back_url = (string)($_SESSION['bd_back'][(int)$booking_id] ?? ($_SESSION['bookings_list_return'] ?? 'bookings.php'));
$bd_back_label = $bd_back_pages[basename((string)parse_url($bd_back_url, PHP_URL_PATH))] ?? 'Bookings';

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
    </style>
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="booking-details-page">

        <?php
        $bd_deleted = !empty($booking['deleted_at']);
        $bd_closed = in_array($booking['status'], ['checked-out', 'cancelled', 'no-show', 'expired'], true);
        $can_cancel = !$bd_deleted && !in_array($booking['status'], ['checked-in', 'checked-out', 'cancelled', 'no-show', 'expired'], true);
        $can_adjust_dates = !$bd_deleted && !$bd_closed;
        $bd_perm_delete = hasPermission((int)($user['id'] ?? 0), 'delete_booking');
        $bd_perm_edit = hasPermission((int)($user['id'] ?? 0), 'edit_booking');
        $bd_perm_checkin = hasPermission((int)($user['id'] ?? 0), 'checkin_guest');
        $bd_perm_checkout = hasPermission((int)($user['id'] ?? 0), 'checkout_guest');
        $bd_perm_cancel = hasPermission((int)($user['id'] ?? 0), 'cancel_booking');
        $bd_perm_pay = hasPermission((int)($user['id'] ?? 0), 'payment_add');
        // Room-type change (upgrade / downgrade): before departure, never on a missed arrival.
        $bd_can_change_type = !$bd_deleted && $bd_perm_edit
            && (in_array($booking['status'], ['pending', 'tentative', 'confirmed'], true) && (string)$booking['check_in_date'] >= date('Y-m-d')
                || ($booking['status'] === 'checked-in' && (string)$booking['check_out_date'] > date('Y-m-d')));
        // Delete: not in-house, no money on the booking (refund/void first).
        $bd_can_delete = !$bd_deleted && $bd_perm_delete && $booking['status'] !== 'checked-in'
            && (float)($booking['amount_paid'] ?? 0) <= BALANCE_TOLERANCE && (float)($booking['folio_charges_total'] ?? 0) <= BALANCE_TOLERANCE;
        $bd_deleted_by = '';
        if ($bd_deleted && !empty($booking['deleted_by'])) {
            $dbSt = $pdo->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admin_users WHERE id = ?");
            $dbSt->execute([(int)$booking['deleted_by']]);
            $bd_deleted_by = (string)($dbSt->fetchColumn() ?: '');
        }
        $child_guests = (int)($booking['child_guests'] ?? 0);
        $adult_guests = (int)($booking['adult_guests'] ?? max(1, ((int) $booking['number_of_guests']) - $child_guests));
        // No individual room yet: empty fallback, so the page says "not assigned" instead of a bare "Room".
        $bookingRoomFallback = !empty($booking['individual_room_id'])
            ? (string)($booking['individual_room_name'] ?: trim(($booking['room_type_name'] ?: 'Room') . ' ' . $booking['individual_room_number']))
            : '';
        $bookingRoomLabel = trim((string) getBookingRoomLabel((int) $booking['id'], $bookingRoomFallback));
        ?>

        <a href="<?php echo htmlspecialchars($bd_back_url, ENT_QUOTES); ?>" class="bd-back"><i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($bd_back_label); ?></a>

        <!-- Header: identity on the left, actions on the right -->
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Booking <?php echo htmlspecialchars($booking['booking_reference']); ?></h1>
                    <span class="hero-status-badge">
                        <i class="fas <?php echo htmlspecialchars($current_status['icon']); ?>"></i>
                        <?php echo htmlspecialchars(ucfirst(str_replace('-', ' ', $booking['status']))); ?>
                    </span>
                </div>
                <p class="bd-header__sub">
                    <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                    <span class="bd-sep" aria-hidden="true">&middot;</span>
                    <?php echo date('D j M', strtotime($booking['check_in_date'])); ?> &ndash; <?php echo date('D j M Y', strtotime($booking['check_out_date'])); ?>
                    <span class="bd-sep" aria-hidden="true">&middot;</span>
                    <?php echo (int) $booking['number_of_nights']; ?> night<?php echo ((int) $booking['number_of_nights']) === 1 ? '' : 's'; ?>
                    <?php if (trim((string)($booking['room_guest_name'] ?? '')) !== ''): ?>
                    <span class="bd-sep" aria-hidden="true">&middot;</span>
                    Room guest: <strong><?php echo htmlspecialchars((string)$booking['room_guest_name']); ?></strong>
                    <?php endif; ?>
                </p>
                <p class="rh-page-head__meta">
                    Created <?php echo htmlspecialchars(rhToUserTime($booking['created_at'], 'M j, Y \a\t g:i A')); ?>
                    <?php if ($booking['updated_at'] && $booking['updated_at'] != $booking['created_at']): ?>
                        &middot; Updated <?php echo htmlspecialchars(rhToUserTime($booking['updated_at'], 'M j, Y \a\t g:i A')); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="rh-page-head__actions booking-actions-flow">
                <button type="button" class="action-btn history" data-rh-history="booking:<?php echo (int)$booking_id; ?>" data-help="History|Every step for this booking, oldest first: who made it and at what price, every status change, payment, refund, credit note and email. Print it from the panel."><i class="fas fa-list-ol"></i> History</button>
                <?php if ($bd_deleted): ?>
                    <?php if ($bd_perm_delete): ?>
                        <button type="button" class="action-btn confirm" onclick="bdRestoreBooking()"><i class="fas fa-trash-arrow-up"></i> Restore Booking</button>
                    <?php endif; ?>
                <?php else: ?>
                <?php if ($bdCanEditBooking && ($booking['status'] == 'tentative' || $booking['is_tentative'] == 1)): ?>
                    <form method="POST" class="booking-action-form" data-admin-confirm="Convert this tentative booking to confirmed and send the conversion email?" data-admin-confirm-title="Convert tentative booking" data-admin-confirm-ok="Convert" data-admin-confirm-icon="fa-circle-check" data-admin-submit-text="Converting...">
                        <input type="hidden" name="booking_action" value="convert">
                        <button type="submit" class="action-btn convert" aria-label="Convert to confirmed"><i class="fas fa-circle-check"></i> Convert to Confirmed</button>
                    </form>
                <?php endif; ?>

                <?php if ($bdCanEditBooking && ($booking['status'] == 'pending')): ?>
                    <form method="POST" class="booking-action-form" data-admin-confirm="Confirm this booking and send the guest confirmation email?" data-admin-confirm-title="Confirm booking" data-admin-confirm-ok="Confirm" data-admin-confirm-icon="fa-circle-check" data-admin-submit-text="Confirming...">
                        <input type="hidden" name="booking_action" value="confirm">
                        <button type="submit" class="action-btn confirm" aria-label="Confirm booking"><i class="fas fa-circle-check"></i> Confirm Booking</button>
                    </form>
                <?php endif; ?>

                <?php if ($booking['status'] == 'confirmed'): ?>
                    <?php
                    // Same payment rule as the check-in itself (validateCheckIn): a payment recorded, nothing owed,
                    // or the booking's pay-later arrangement switched on.
                    $ci_pay_gate = rh_checkin_payment_gate($booking);
                    $can_checkin = $ci_pay_gate !== 'blocked';
                    $room_assigned = !empty($booking['individual_room_id']);
                    $check_in_date = new DateTime($booking['check_in_date']);
                    $check_in_date->setTime(0, 0, 0);
                    $today = new DateTime('today');
                    $checkin_date_reached = $check_in_date <= $today;
                    $checkin_disabled_reason = '';
                    if (!$can_checkin) {
                        $checkin_disabled_reason = 'At least a partial payment must be recorded before check-in'
                            . ($bdCanEditBooking && rh_checkin_arrangement_columns_present($pdo) ? ' (or switch on "Check in before payment" below for an agreed pay-later arrangement)' : '');
                    } elseif (!$room_assigned) {
                        $checkin_disabled_reason = 'Room must be assigned before check-in';
                    } elseif (!$checkin_date_reached) {
                        $checkin_disabled_reason = 'Check-in date has not been reached yet (' . htmlspecialchars($booking['check_in_date']) . ')';
                    }
                    $ci_room_gate = ($checkin_disabled_reason === '') ? evaluateCheckInRoomReady((int)$booking['id'], (int)($user['id'] ?? 0), false) : null;
                    $ci_room_override = $ci_room_gate && $ci_room_gate['needs_confirm'];
                    if ($ci_room_gate && !$ci_room_gate['allowed'] && !$ci_room_override) {
                        $checkin_disabled_reason = $ci_room_gate['message'];
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

                    <?php $ci_late = $checkin_date_reached && $check_in_date < $today; /* late arrival: the bookings-list dialog asks what happened */ ?>
                    <?php if ($bd_perm_checkin && $ci_late): ?>
                        <a href="bookings.php?action=checkin&amp;booking_id=<?php echo (int)$booking_id; ?>" class="action-btn checkin<?php echo ($can_checkin && $room_assigned) ? '' : ' is-disabled'; ?>"<?php echo ($can_checkin && $room_assigned) ? '' : ' aria-disabled="true" onclick="return false;" title="' . htmlspecialchars($checkin_disabled_reason, ENT_QUOTES) . '"'; ?> data-help="Late Check-In|The booked arrival date has passed. This opens the check-in dialog, which asks what happened: arrived on the booked date, arriving today, or (if the stay is over) already stayed and left.">
                            <i class="fas fa-right-to-bracket"></i> Late Check-In
                        </a>
                    <?php elseif ($bd_perm_checkin): ?><form method="POST" class="booking-action-form" data-admin-confirm="<?php echo $ci_room_override ? htmlspecialchars($ci_room_gate['message'], ENT_QUOTES) : 'Check in this guest and mark the assigned room occupied?'; ?>" data-admin-confirm-title="<?php echo $ci_room_override ? 'Room not marked clean' : 'Check in guest'; ?>" data-admin-confirm-ok="<?php echo $ci_room_override ? 'Check in anyway' : 'Check in'; ?>" data-admin-confirm-icon="fa-right-to-bracket" data-admin-submit-text="Checking in..." data-admin-confirm-check="email_guest" data-admin-confirm-check-label="Email the guest a check-in confirmation" data-admin-confirm-check-help="Untick to check in quietly: no check-in email goes to the guest.">
                        <input type="hidden" name="booking_action" value="checkin">
                        <input type="hidden" name="email_guest" value="1">
                        <?php if ($ci_room_override): ?><input type="hidden" name="confirm_checkin_room_not_ready" value="1"><?php endif; ?>
                        <button type="submit" class="action-btn checkin" data-help="Check In|Check the guest into their assigned room and mark the room occupied. Requires a payment recorded (or nothing owed, or Check in before payment switched on), a room assigned, and the check-in date to have arrived." <?php echo ($can_checkin && $room_assigned && $checkin_date_reached) ? '' : 'disabled title="' . htmlspecialchars($checkin_disabled_reason) . '"'; ?>>
                            <i class="fas fa-right-to-bracket"></i> Check In
                        </button>
                    </form><?php endif; ?>
                    <?php if ($checkin_disabled_reason): ?>
                        <p class="booking-action-inline-hint booking-action-inline-hint--error">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($checkin_disabled_reason); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($booking['status'] == 'checked-in' && rh_checkout_settlement_reason($booking) !== null): ?>
                    <a href="bookings.php?action=checkout&amp;booking_id=<?php echo (int)$booking_id; ?>" class="action-btn checkout" data-help="Check Out (settle)|The guest is leaving on a different day than booked. This opens the checkout settlement: charge or waive extra nights, refund or keep unused nights.">
                        <i class="fas fa-right-from-bracket"></i> Check Out &amp; Settle
                    </a>
                    <p class="booking-action-inline-hint">
                        <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(rh_checkout_settlement_reason($booking)); ?>
                    </p>
                <?php elseif ($booking['status'] == 'checked-in'):
                    $co_balance = round(max(0.0, (float)($folio_summary['balance_due'] ?? ($booking['amount_due'] ?? 0))), 2);
                    // On a company account (owner D2 2026-11-13): only the guest's own share gates checkout.
                    require_once __DIR__ . '/../includes/billing-accounts.php';
                    if (rh_billing_ready($pdo) && $co_balance > BALANCE_TOLERANCE) {
                        $co_split = rh_booking_bill_split($pdo, (int)$booking_id);
                        if (!empty($co_split['on_account'])) {
                            $co_balance = round(max(0.0, $co_balance - (float)$co_split['company_due']), 2);
                        }
                    }
                    $co_has_balance = $co_balance > BALANCE_TOLERANCE;
                    $co_can_override = $co_has_balance && hasPermission((int)($user['id'] ?? 0), 'checkout_with_balance');
                    $co_confirm_text = $co_can_override
                        ? 'This guest still owes ' . $currency_symbol . ' ' . number_format($co_balance, 2) . '. Check out anyway with the outstanding balance? This will be recorded in the booking timeline.'
                        : 'Check out this guest and generate the final invoice where applicable?';
                    // The rate the stay was booked at × its nights, so the checkout confirm never reads as today's price.
                    $co_rate = rh_booked_rate_for_booking($pdo, (int)$booking_id, false);
                    if ($co_rate['ok']) {
                        $co_confirm_text .= ' Room at the booked rate: ' . $co_rate['text'] . '.';
                    }
                ?>
                    <?php $co_pay_later_email = !empty($booking['checkin_without_payment']) && trim((string)($booking['guest_email'] ?? '')) !== ''; // owner 2026-11-13: unticked by default ?>
                    <?php if ($bd_perm_checkout): ?><form method="POST" class="booking-action-form" data-admin-confirm="<?php echo htmlspecialchars($co_confirm_text, ENT_QUOTES); ?>" data-admin-confirm-title="Check out guest" data-admin-confirm-ok="<?php echo $co_can_override ? 'Check out with balance' : 'Check out'; ?>" data-admin-confirm-icon="fa-right-from-bracket" data-admin-submit-text="Checking out..."<?php echo $co_pay_later_email ? ' data-admin-confirm-check="email_final_invoice" data-admin-confirm-check-label="Email the final invoice to the guest" data-admin-confirm-check-help="Pay-later booking (Check in before payment): the invoice is kept on the booking and is not emailed unless you tick this."' : ''; ?>>
                        <input type="hidden" name="booking_action" value="checkout">
                        <?php if ($co_can_override): ?><input type="hidden" name="confirm_checkout_with_balance" value="1"><?php endif; ?>
                        <?php if ($co_pay_later_email): ?><input type="hidden" name="email_final_invoice" value="0"><?php endif; ?>
                        <button type="submit" class="action-btn checkout" data-help="Check Out|Check the guest out, release the room, and generate the final invoice where applicable."<?php echo ($co_has_balance && !$co_can_override) ? ' disabled title="Outstanding balance ' . htmlspecialchars($currency_symbol . ' ' . number_format($co_balance, 2), ENT_QUOTES) . ' - record payment before checkout"' : ''; ?>>
                            <i class="fas fa-right-from-bracket"></i> Check Out
                        </button>
                    </form><?php endif; ?>
                    <?php if ($co_has_balance): ?>
                        <p class="booking-action-inline-hint booking-action-inline-hint--error">
                            <i class="fas fa-info-circle"></i> Outstanding balance: <?php echo htmlspecialchars($currency_symbol . ' ' . number_format($co_balance, 2)); ?><?php echo $co_can_override ? ' - you may check out with the balance after confirming.' : ' - payment must be recorded before checkout.'; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (in_array($booking['status'], ['confirmed', 'pending'], true) && strtotime($booking['check_in_date']) < strtotime('today')): ?>
                    <?php if ($bd_perm_cancel): ?><form method="POST" class="booking-action-form" data-admin-confirm="Mark this booking as no-show and release the assigned room?" data-admin-confirm-title="Mark no-show" data-admin-confirm-ok="Mark no-show" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-user-slash" data-admin-submit-text="Updating...">
                        <input type="hidden" name="booking_action" value="noshow">
                        <button type="submit" class="action-btn noshow"><i class="fas fa-user-slash"></i> Mark No-Show</button>
                    </form><?php endif; ?>
                <?php endif; ?>

                <?php if ($bd_perm_edit): ?>
                    <a href="edit-booking.php?id=<?php echo $booking_id; ?>" class="action-btn edit"><i class="fas fa-edit"></i> <?php echo $bd_closed ? 'Correct Guest Details' : 'Edit Booking'; ?></a>
                <?php endif; ?>
                <?php if ($bd_can_change_type): ?>
                    <a href="bookings.php?action=room-change&amp;booking_id=<?php echo $booking_id; ?>" class="action-btn change-room" data-help="Upgrade / Change Room Type|Move the booking to another room type: paid upgrade, complimentary upgrade, or downgrade. The bill and audit trail are updated for you."><i class="fas fa-arrow-up-right-dots"></i> Upgrade / Change Type</a>
                <?php endif; ?>
                <?php if ($can_adjust_dates && $bd_perm_edit): ?>
                    <button type="button" class="action-btn adjust-dates" onclick="openDateAdjustModal()">
                        <i class="fas fa-calendar-alt"></i> Adjust Stay Dates
                    </button>
                <?php endif; ?>
                <?php
                // Right document for the state (owner rule): nothing paid = quotation; balance owed = invoice; paid = receipt.
                $bd_paid_now = (float)($booking['amount_paid'] ?? 0);
                $bd_doc = rh_doc_state($bd_paid_now, (float)($booking['amount_due'] ?? 0), (string)$booking['status']);
                ?>
                <?php if ($bd_doc === 'receipt'): ?>
                    <button type="button" class="action-btn quote" onclick="rhSendAccountReceipt('room', <?php echo (int) $booking_id; ?>, <?php echo htmlspecialchars(json_encode((string)$booking['booking_reference']), ENT_QUOTES); ?>)">
                        <i class="fas fa-receipt"></i> Send Receipt
                    </button>
                <?php endif; ?>
                <?php /* same rule as the bookings list: before the stay (not yet arrived) and nothing paid */ if ($bPerms['can_send_quotation'] && $bd_paid_now <= BALANCE_TOLERANCE && in_array((string)$booking['status'], ['pending', 'tentative', 'confirmed'], true)):  ?>
                    <button type="button" class="action-btn quote" onclick="openBookingQuoteModal(<?php echo (int) $booking_id; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['guest_email'], ENT_QUOTES); ?>')">
                        <i class="fas fa-file-invoice"></i> Send Quotation
                    </button>
                <?php endif; /* quotation is offered only while it is relevant */ ?>

                <?php
                // Group booking (owner 2026-10-20): when other live rooms were booked together with this one, ask
                // each time - "whole group" is offered first, "only this room" second.
                $bd_group_live = ($can_cancel && $bd_perm_cancel) || $bd_can_delete ? rh_booking_group_members($pdo, (int)$booking_id) : [];
                $bd_group_n = count($bd_group_live);
                $bd_group_refs = implode(', ', array_column($bd_group_live, 'booking_reference'));
                ?>
                <?php if ($can_cancel): ?>
                    <?php if ($bd_perm_cancel && $bd_group_n > 1): ?><form method="POST" class="booking-action-form" data-admin-confirm="<?php echo htmlspecialchars('Cancel the WHOLE GROUP (' . $bd_group_n . ' rooms: ' . $bd_group_refs . ') and release every room? If any room cannot be cancelled, none is. Cancellation handling: ' . getCancellationRefundModeLabel() . '.', ENT_QUOTES); ?>" data-admin-confirm-check="email_guest" data-admin-confirm-check-label="Email the guest that this booking is cancelled" data-admin-confirm-check-help="Untick to cancel quietly: no email or WhatsApp goes to the guest." data-admin-confirm-title="Cancel whole group" data-admin-confirm-ok="Cancel whole group" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-ban" data-admin-submit-text="Cancelling...">
                        <input type="hidden" name="booking_action" value="cancel">
                        <input type="hidden" name="scope" value="group">
                        <input type="hidden" name="email_guest" value="1">
                        <input type="hidden" name="cancellation_reason" value="Cancelled by admin">
                        <button type="submit" class="action-btn cancel" aria-label="Cancel whole group"><i class="fas fa-ban"></i> Cancel Whole Group (<?php echo (int)$bd_group_n; ?> rooms)</button>
                    </form><?php endif; ?>
                    <?php if ($bd_perm_cancel): ?><form method="POST" class="booking-action-form" data-admin-confirm="<?php echo htmlspecialchars('Cancel this booking and release the room?' . ($bd_group_n > 1 ? ' The other rooms of the group stay booked.' : '') . ' Cancellation handling: ' . getCancellationRefundModeLabel() . '.', ENT_QUOTES); ?>" data-admin-confirm-check="email_guest" data-admin-confirm-check-label="Email the guest that this booking is cancelled" data-admin-confirm-check-help="Untick to cancel quietly: no email or WhatsApp goes to the guest." data-admin-confirm-title="Cancel booking" data-admin-confirm-ok="Cancel booking" data-admin-confirm-tone="danger" data-admin-confirm-icon="fa-ban" data-admin-submit-text="Cancelling...">
                        <input type="hidden" name="booking_action" value="cancel">
                        <?php if ($bd_group_n > 1): ?><input type="hidden" name="scope" value="single"><?php endif; ?>
                        <input type="hidden" name="email_guest" value="1">
                        <input type="hidden" name="cancellation_reason" value="Cancelled by admin">
                        <button type="submit" class="action-btn cancel" aria-label="Cancel booking"><i class="fas fa-ban"></i> <?php echo $bd_group_n > 1 ? 'Cancel This Room Only' : 'Cancel Booking'; ?></button>
                    </form><?php endif; ?>
                <?php endif; ?>

                <?php if ($bd_can_delete): ?>
                    <?php if ($bd_group_n > 1): ?>
                        <button type="button" class="action-btn cancel" onclick="bdDeleteBooking('group', <?php echo (int)$bd_group_n; ?>)" data-help="Delete Whole Group|Remove all <?php echo (int)$bd_group_n; ?> rooms booked together from the lists (kept under the Deleted filter; can be restored). If any room cannot be deleted, none is."><i class="fas fa-trash-can"></i> Delete Whole Group (<?php echo (int)$bd_group_n; ?> rooms)</button>
                    <?php endif; ?>
                    <button type="button" class="action-btn cancel" onclick="bdDeleteBooking('single', 1)" data-help="Delete Booking|Remove this booking from the lists (kept under the Deleted filter with who, when and why; it can be restored)."><i class="fas fa-trash-can"></i> <?php echo $bd_group_n > 1 ? 'Delete This Room Only' : 'Delete Booking'; ?></button>
                <?php endif; ?>
                <?php endif; /* not deleted */ ?>

                <?php if (!empty($booking['last_quotation_sent_at'])): ?>
                    <p class="booking-actions-meta">
                        <i class="fas fa-paper-plane"></i>
                        Quotation last sent <?php echo htmlspecialchars(rhToUserTime($booking['last_quotation_sent_at'], 'M j, Y \a\t g:i A')); ?>
                    </p>
                <?php endif; ?>
            </div>
        </header>

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
        if ($bd_deleted) {
            $statusBanner = ['class' => 'booking-status-banner--cancelled', 'icon' => 'fa-trash-can', 'text' => 'This booking was <strong>deleted</strong>'
                . ($bd_deleted_by !== '' ? ' by <strong>' . htmlspecialchars($bd_deleted_by) . '</strong>' : '')
                . ' on <strong>' . htmlspecialchars(date('j M Y, H:i', strtotime((string)$booking['deleted_at']))) . '</strong>.'
                . ' Reason: <em>' . htmlspecialchars((string)$booking['deleted_reason']) . '</em>. It is read-only until restored.'];
        }
        if ($statusBanner):
        ?>
            <div class="booking-status-banner <?php echo $statusBanner['class']; ?>">
                <i class="fas <?php echo $statusBanner['icon']; ?>"></i>
                <span><?php echo $statusBanner['text']; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($booking_alert_message !== ''): ?>
            <div class="booking-status-alert booking-status-alert--<?php echo htmlspecialchars($booking_alert_tone); ?>">
                <i class="fas <?php echo $booking_alert_tone === 'danger' ? 'fa-triangle-exclamation' : ($booking_alert_tone === 'warning' ? 'fa-clock' : 'fa-circle-info'); ?>"></i>
                <span><?php echo htmlspecialchars($booking_alert_message); ?></span>
            </div>
        <?php endif; ?>

        <?php
        // Pay-later check-in arrangement (owner 2026-11-12): Edit Booking holders switch it on/off before arrival;
        // everyone else sees it read-only. Hidden until database update 083 has run.
        $bd_arr_ready = rh_checkin_arrangement_columns_present($pdo);
        $bd_arr_on = $bd_arr_ready && !empty($booking['checkin_without_payment']);
        $bd_arr_note = $bd_arr_on ? trim((string)($booking['checkin_arrangement_note'] ?? '')) : '';
        $bd_arr_editable = $bd_arr_ready && $bdCanEditBooking && !$bd_deleted
            && in_array((string)$booking['status'], ['pending', 'tentative', 'confirmed'], true);
        ?>
        <?php if ($bd_arr_editable): ?>
            <form method="POST" class="booking-status-alert booking-status-alert--info bd-arrangement" id="bd-checkin-arrangement">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                <input type="hidden" name="checkin_arrangement_save" value="1">
                <label class="bd-arrangement__switch">
                    <input type="checkbox" name="checkin_without_payment" value="1" id="bd-arr-on"<?php echo $bd_arr_on ? ' checked' : ''; ?>>
                    <strong>Check in before payment</strong>
                </label>
                <span class="bd-arrangement__help">For an agreed pay-later arrangement (a company invoiced later, the owner's stay). The guest can be checked in with nothing paid; the balance stays owed.</span>
                <label class="bd-arrangement__note">
                    <span>Why (required when on)</span>
                    <input type="text" name="checkin_arrangement_note" maxlength="255" placeholder="e.g. ICTAM - invoiced monthly" value="<?php echo htmlspecialchars($bd_arr_note, ENT_QUOTES); ?>">
                </label>
                <button type="submit" class="action-btn confirm"><i class="fas fa-floppy-disk"></i> Save</button>
            </form>
        <?php elseif ($bd_arr_on): ?>
            <div class="booking-status-alert booking-status-alert--info">
                <i class="fas fa-handshake"></i>
                <span><strong>Pay-later arrangement:</strong> this guest may be checked in before paying<?php echo $bd_arr_note !== '' ? ' (' . htmlspecialchars($bd_arr_note) . ')' : ''; ?>. The balance stays owed.</span>
            </div>
        <?php endif; ?>

        <?php // Who pays (company account, migration 084): hidden until the update has run.
        require_once __DIR__ . '/includes/billing-panel.php';
        rh_billing_panel_render($pdo, $booking, $user, (string)$csrf_token); ?>

        <?php
        // A clean bookings row for the "how this total is worked out" popup: the page query above joins payments, whose
        // vat_rate / vat_amount columns would otherwise replace the booking's own.
        $bd_row_fresh = $booking;
        try {
            $bdFresh = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $bdFresh->execute([(int)$booking['id']]);
            $bd_row_fresh = $bdFresh->fetch(PDO::FETCH_ASSOC) ?: $booking;
        } catch (Throwable $e) {
            $bd_row_fresh = $booking;
        }
        // The rate this room was BOOKED at × its nights (never today's room price), from the clean row above.
        // today_text (staff only) is set when today's price for this room differs from the booked rate.
        $bd_rate = rh_booked_rate_line(
            $bd_row_fresh,
            rh_room_today_price_lookup($pdo, $bd_row_fresh)['price'],
            ['currency' => $currency_symbol, 'room_name' => (string)($booking['room_name'] ?? '')]
        );
        // A cancelled / no-show / expired booking: the rate is shown for reference only, never as money owed.
        $bd_rate_closed = in_array((string)($booking['status'] ?? ''), ['cancelled', 'no-show', 'expired'], true);
        $bd_rate_label = $bd_rate_closed ? 'Was booked at' : 'Room booked at';
        ?>
        <!-- Group booking notice -->
        <?php if (!empty($group_bookings)): ?>
        <div class="bd-group-notice">
            <i class="fas fa-layer-group"></i>
            <span class="bd-group-notice__title">
                <?php
                // A cancelled lead hands the group's documents to the next active room (rh_booking_group_doc_lead).
                $bd_grp_lead = rh_booking_group_effective_lead($pdo, (int)$booking['id']);
                $bd_grp_refs = [(int)$booking['id'] => (string)($booking['booking_reference'] ?? '')];
                foreach ($group_bookings as $gb) {
                    $bd_grp_refs[(int)$gb['id']] = (string)($gb['booking_reference'] ?? '');
                }
                if ($bd_grp_lead !== $primary_id_for_group && isset($bd_grp_refs[$bd_grp_lead], $bd_grp_refs[$primary_id_for_group])) {
                    if ((int)$booking['id'] === $primary_id_for_group) {
                        echo htmlspecialchars('Cancelled lead room — the group is now led by ' . $bd_grp_refs[$bd_grp_lead] . ' (its invoice is built from that room)');
                    } elseif ((int)$booking['id'] === $bd_grp_lead) {
                        echo htmlspecialchars('Group lead — group of ' . (count($group_bookings) + 1) . ' rooms (took over from the cancelled lead ' . $bd_grp_refs[$primary_id_for_group] . ')');
                    } else {
                        echo htmlspecialchars('Secondary room in a group booking led by ' . $bd_grp_refs[$bd_grp_lead]);
                    }
                } else {
                    echo !empty($booking['primary_booking_id']) ? 'Secondary room in a group booking' : 'Primary booking — group of ' . (count($group_bookings) + 1) . ' rooms';
                }
                ?>
            </span>
            <span class="bd-group-notice__label">Linked rooms:</span>
            <?php foreach ($group_bookings as $gb): ?>
            <a href="booking-details.php?id=<?php echo (int)$gb['id']; ?>" class="bd-group-notice__link">
                <i class="fas fa-door-open"></i>
                <?php echo htmlspecialchars($gb['room_name']); ?><?php echo trim((string)($gb['room_guest_name'] ?? '')) !== '' ? ' (' . htmlspecialchars((string)$gb['room_guest_name']) . ')' : ''; ?> &mdash; <strong><?php echo htmlspecialchars($gb['booking_reference']); ?></strong>
            </a>
            <?php endforeach; ?>
            <?php
            // Group total = every live room in the group at its full amount (room + VAT + levy), owner 2026-10-23.
            $bd_group_total = rh_booking_full_total($bd_row_fresh);
            $bd_group_rows = [$bd_row_fresh];
            foreach ($group_bookings as $gb) {
                if (!in_array((string)($gb['status'] ?? ''), ['cancelled', 'no-show', 'expired'], true)) {
                    $bd_group_total += rh_booking_full_total($gb);
                    $bd_group_rows[] = $gb;
                }
            }
            if (in_array((string)($booking['status'] ?? ''), ['cancelled', 'no-show', 'expired'], true)) {
                $bd_group_total -= rh_booking_full_total($bd_row_fresh);
                array_shift($bd_group_rows);
            }
            ?>
            <span class="bd-group-notice__label">Group total (incl. VAT &amp; levy): <strong><?php echo $currency_symbol; ?><?php echo number_format($bd_group_total, 2); ?></strong><?php echo $bd_group_rows ? rh_booking_total_info_btn($bd_group_rows, ['currency' => $currency_symbol]) : ''; ?></span>
        </div>
        <?php endif; ?>

        <?php
        $bd_pay_raw = strtolower((string)($booking['payment_status'] ?? 'unpaid'));
        $bd_pay_class = in_array($bd_pay_raw, ['paid', 'completed'], true) ? 'ok' : ($bd_pay_raw === 'partial' ? 'warn' : 'alert');
        $bd_pay_labels = ['paid' => 'Paid', 'completed' => 'Paid', 'partial' => 'Part paid', 'unpaid' => 'Unpaid', 'pending' => 'Unpaid', 'refunded' => 'Refunded', 'failed' => 'Failed'];
        $bd_pay_label = $bd_pay_labels[$bd_pay_raw] ?? ucfirst($bd_pay_raw);
        $bd_has_credit = $folio_credit_balance > BALANCE_TOLERANCE;
        $bd_has_due = !$bd_has_credit && $folio_balance_due > BALANCE_TOLERANCE;
        $bd_info_btn = rh_booking_total_info_btn($bd_row_fresh, [
            'currency' => $currency_symbol,
            'folio' => is_array($folio_summary) && !isset($folio_summary['error'])
                ? array_merge($folio_summary, ['credit' => $folio_credit_balance])
                : null,
        ]);
        ?>

        <!-- Summary strip -->
        <div class="rh-strip" role="group" aria-label="Booking financial summary">
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Folio total</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_total_amount, 2); ?></strong><?php echo $bd_info_btn; ?>
            </div>
            <?php if ($bd_rate['ok']): ?>
            <div class="rh-strip__cell" title="<?php echo htmlspecialchars($bd_rate_label . ' ' . $bd_rate['text'] . ($bd_rate['today_text'] !== '' && !$bd_rate_closed ? '. ' . $bd_rate['today_text'] : ''), ENT_QUOTES); ?>">
                <span class="rh-strip__label"><?php echo htmlspecialchars($bd_rate_label); ?></span>
                <strong class="rh-strip__value"><?php echo htmlspecialchars($bd_rate['compact']); ?></strong>
                <small class="bd-muted">room rate a night &times; nights<?php echo $bd_rate_closed ? ', for reference' : ''; ?></small>
            </div>
            <?php endif; ?>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Paid</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_amount_paid, 2); ?></strong>
            </div>
            <?php if ($bd_has_credit): ?>
            <div class="rh-strip__cell bd-strip__cell--balance is-credit">
                <span class="rh-strip__label">Credit owed to guest</span>
                <strong class="rh-strip__value"><?php echo $currency_symbol; ?><?php echo number_format($folio_credit_balance, 2); ?></strong>
            </div>
            <?php else: ?>
            <div class="rh-strip__cell bd-strip__cell--balance <?php echo $bd_has_due ? 'is-due' : 'is-settled'; ?>">
                <span class="rh-strip__label">Balance due</span>
                <strong class="rh-strip__value <?php echo $bd_has_due ? 'rh-strip__value--alert' : 'rh-strip__value--ok'; ?>"><?php echo $currency_symbol; ?><?php echo number_format($folio_balance_due, 2); ?></strong>
            </div>
            <?php endif; ?>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Payment status</span>
                <span class="rh-pill rh-pill--<?php echo $bd_pay_class; ?>"><?php echo htmlspecialchars($bd_pay_label); ?></span>
            </div>
            <div class="rh-strip__cell">
                <span class="rh-strip__label">Room status</span>
                <strong class="rh-strip__value bd-strip__value--text"><?php echo htmlspecialchars($room_status_label); ?></strong>
            </div>
        </div>

        <div class="rh-layout">

            <!-- LEFT: stay, folio, payment, invoices -->
            <div>

                <section class="rh-panel" id="stay-details">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Stay details</h2>
                    </div>
                    <table class="rh-kv no-auto-pagination">
                        <tbody>
                            <tr>
                                <th scope="row">Guest name</th>
                                <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                            </tr>
                            <?php if (trim((string)($booking['room_guest_name'] ?? '')) !== ''): ?>
                            <tr>
                                <th scope="row">Guest name for this room</th>
                                <td><?php echo htmlspecialchars((string)$booking['room_guest_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row">Email</th>
                                <td>
                                    <span class="bd-kv__text"><?php echo htmlspecialchars($booking['guest_email']); ?></span>
                                    <a href="mailto:<?php echo htmlspecialchars($booking['guest_email']); ?>" class="rh-mini-link email"><i class="fas fa-envelope"></i> Email</a>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Phone</th>
                                <td>
                                    <span class="bd-kv__text"><?php echo htmlspecialchars($booking['guest_phone']); ?></span>
                                    <a href="tel:<?php echo htmlspecialchars($booking['guest_phone']); ?>" class="rh-mini-link phone"><i class="fas fa-phone"></i> Call</a>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Country</th>
                                <td><?php echo htmlspecialchars($booking['guest_country'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Guests</th>
                                <td>
                                    <?php echo $adult_guests; ?> adult<?php echo $adult_guests === 1 ? '' : 's'; ?><?php if ($child_guests > 0): ?>, <?php echo $child_guests; ?> child<?php echo $child_guests === 1 ? '' : 'ren'; ?><?php endif; ?>
                                    <span class="bd-muted">(<?php echo (int) $booking['number_of_guests']; ?> total)</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Check-in</th>
                                <td><?php echo date('D, M j, Y', strtotime($booking['check_in_date'])); ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Check-out</th>
                                <td><?php echo date('D, M j, Y', strtotime($booking['check_out_date'])); ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Nights</th>
                                <td><?php echo (int) $booking['number_of_nights']; ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Room type</th>
                                <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                            </tr>
                            <?php if (!empty($booking['rate_plan_label'])): ?>
                            <tr>
                                <th scope="row">Rate plan</th>
                                <td><?php echo htmlspecialchars($booking['rate_plan_label']); ?><?php if ((float)($booking['rate_plan_discount'] ?? 0) > 0): ?> &mdash; -<?php echo $currency_symbol; ?><?php echo number_format((float) $booking['rate_plan_discount'], 2); ?>/night<?php elseif ((float)($booking['rate_plan_discount'] ?? 0) < -BALANCE_TOLERANCE): ?> &mdash; surcharge +<?php echo $currency_symbol; ?><?php echo number_format(-(float) $booking['rate_plan_discount'], 2); ?>/night (already in the room rate)<?php endif; ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row"><?php echo $bd_rate['ok'] ? 'Booked rate' : 'Rate / night'; ?></th>
                                <td>
                                    <?php if ($bd_rate['ok']): ?>
                                        <?php echo htmlspecialchars($bd_rate['text']); ?>
                                        <br><small class="bd-muted">The room rate a night when this booking was made &times; its nights<?php echo $bd_rate_closed ? ' (booking ' . htmlspecialchars(str_replace('-', ' ', (string)$booking['status'])) . ': for reference, not an amount owed)' : ''; ?>.</small>
                                        <?php if ($bd_rate['today_text'] !== '' && !$bd_rate_closed): ?>
                                            <br><small class="bd-muted"><?php echo htmlspecialchars($bd_rate['today_text']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo $currency_symbol; ?><?php echo number_format((float) $booking['price_per_night'], 0); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Assigned room</th>
                                <td>
                                    <?php if ($bookingRoomLabel !== ''): ?>
                                        <span class="bd-kv__text"><i class="fas fa-door-open bd-kv__icon"></i> <?php echo htmlspecialchars($bookingRoomLabel); ?><?php if ($booking['individual_room_floor']): ?> <span class="bd-muted">(floor <?php echo htmlspecialchars($booking['individual_room_floor']); ?>)</span><?php endif; ?></span>
                                    <?php else: ?>
                                        <span class="bd-muted">No specific room assigned yet</span>
                                    <?php endif; ?>
                                    <?php if ($booking['status'] == 'confirmed'): ?>
                                        <a href="bookings.php?action=assign-room&booking_id=<?php echo $booking_id; ?>" class="rh-mini-link"><i class="fas fa-key"></i> <?php echo $bookingRoomLabel !== '' ? 'Change' : 'Assign'; ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Special requests</th>
                                <td>
                                    <?php if ($booking['special_requests']): ?>
                                        <span class="bd-kv__requests"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></span>
                                    <?php else: ?>
                                        <span class="bd-muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Folio + payment breakdown (refreshed in place after charges are added or voided) -->
                <div id="folio" class="bd-folio-wrap">
                    <section class="rh-panel folio-card">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Folio / Charges</h2>
                            <div class="rh-panel__actions folio-actions">
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
                            <div class="bd-table-wrap">
                            <table class="folio-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th class="num">Qty</th>
                                        <th class="num">Unit Price</th>
                                        <th class="num">VAT</th>
                                        <th class="num">Line Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($folio_charges as $charge): ?>
                                        <tr class="<?php echo $charge['voided'] ? 'voided' : ''; ?>">
                                            <td class="nowrap">
                                                <?php echo date('M j, Y', strtotime($charge['posted_at'])); ?>
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
                                                    <br><small class="void-reason">Reason: <?php echo htmlspecialchars($charge['void_reason']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="num"><?php echo number_format($charge['quantity'], 0); ?></td>
                                            <td class="num"><?php echo $currency_symbol; ?><?php echo number_format($charge['unit_price'], 2); ?></td>
                                            <td class="num"><?php echo $charge['vat_rate'] > 0 ? number_format($charge['vat_amount'], 2) : '-'; ?></td>
                                            <td class="num strong"><?php echo $currency_symbol; ?><?php echo number_format($charge['line_total'], 2); ?></td>
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
                            </div>
                        <?php else: ?>
                            <p class="rh-empty">No folio charges yet. Use Add Charge or Add Menu Item.</p>
                        <?php endif; ?>
                    </section>

                    <section class="rh-panel bd-payment">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Payment breakdown</h2>
                            <span class="rh-pill rh-pill--<?php echo $bd_pay_class; ?>"><?php echo htmlspecialchars($bd_pay_label); ?></span>
                        </div>

                        <div class="payment-tax-breakdown">
                            <div class="payment-tax-row payment-tax-row--sub">
                                <?php if ($bd_is_cancelled): ?>
                                <span>Room (amount kept on cancellation)<br><small class="bd-muted">only this part of the stay is billed</small></span>
                                <?php else: ?>
                                <span>Room base (excl. VAT and levy)<br><small class="bd-muted">the stay (and any child charge) without VAT or levy<?php echo $bd_pkg_rows ? '; packages listed below' : ''; ?></small></span>
                                <?php endif; ?>
                                <strong class="bd-amt"><?php echo $currency_symbol; ?><?php echo number_format($bd_room_base, 2); ?></strong>
                            </div>
                            <?php if (!$bd_is_cancelled && $bd_plan_label !== '' && $bd_plan_pd > 0): ?>
                                <div class="payment-tax-row payment-tax-row--sub">
                                    <span><?php echo htmlspecialchars($bd_plan_label); ?> discount<br><small class="bd-muted">already taken off the room base above, not subtracted again</small></span>
                                    <strong class="bd-muted bd-amt">-<?php echo $currency_symbol; ?><?php echo number_format($bd_plan_total, 2); ?></strong>
                                </div>
                            <?php elseif (!$bd_is_cancelled && $bd_plan_label !== '' && $bd_plan_pd < -BALANCE_TOLERANCE): ?>
                                <div class="payment-tax-row payment-tax-row--sub">
                                    <span><?php echo htmlspecialchars($bd_plan_label); ?> surcharge<br><small class="bd-muted">already in the room base above, not added again</small></span>
                                    <strong class="bd-muted bd-amt">+<?php echo $currency_symbol; ?><?php echo number_format($bd_plan_total, 2); ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($bd_pkg_rows as $bp_row): ?>
                                <div class="payment-tax-row payment-tax-row--sub">
                                    <span><?php echo htmlspecialchars($bp_row['name']); ?> (excl. VAT)</span>
                                    <strong class="bd-amt"><?php echo $currency_symbol; ?><?php echo number_format($bp_row['net'], 2); ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <div class="payment-tax-row payment-tax-row--sub">
                                <span>Extras (excl. VAT)<br><small class="bd-muted">folio charges; their VAT is in the VAT row</small></span>
                                <strong class="bd-amt"><?php echo $currency_symbol; ?><?php echo number_format($bd_extras_net, 2); ?></strong>
                            </div>
                            <div class="payment-tax-row">
                                <span>Subtotal (excl. VAT and levy)<br><small class="bd-muted">the rows above added up</small></span>
                                <strong class="bd-amt"><?php echo $currency_symbol; ?><?php echo number_format($bd_subtotal, 2); ?></strong>
                            </div>
                            <?php if ($bd_levy_row > 0): ?>
                                <div class="payment-tax-row">
                                    <span>+ Tourism Levy<?php echo $booking_levy_percent > 0 ? ' (' . number_format($booking_levy_percent, 2) . '%)' : ''; ?><br><small class="bd-muted">on the room, not on packages or extras</small></span>
                                    <strong class="bd-amt"><?php echo $currency_symbol; ?><?php echo number_format($bd_levy_row, 2); ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="payment-tax-row">
                                <span>+ VAT<br><small class="bd-muted">room, packages and extras</small></span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_total_vat, 2); ?></strong>
                            </div>
                            <div class="payment-tax-row payment-tax-row--total">
                                <span>Total</span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_total_amount, 2); ?><?php echo $bd_info_btn; ?></strong>
                            </div>
                            <?php if ($bd_rate['ok']): ?>
                                <div class="payment-tax-row payment-tax-row--sub">
                                    <span><?php echo htmlspecialchars($bd_rate_label); ?><br><small class="bd-muted">room rate a night &times; nights<?php echo $bd_rate_closed ? ', for reference only' : '; folio extras are added on top'; ?></small></span>
                                    <strong><?php echo htmlspecialchars($bd_rate['text']); ?></strong>
                                </div>
                                <?php if ($bd_rate['today_text'] !== '' && !$bd_rate_closed): ?>
                                    <p class="bd-muted" style="margin:0 0 6px;font-size:12px;"><?php echo htmlspecialchars($bd_rate['today_text']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="payment-tax-row">
                                <span>Paid</span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($folio_amount_paid, 2); ?></strong>
                            </div>
                            <div class="payment-tax-row payment-tax-row--balance <?php echo $bd_has_due ? 'is-due' : 'is-settled'; ?>">
                                <span><?php echo $bd_has_credit ? 'Credit owed to guest' : 'Balance'; ?></span>
                                <strong><?php echo $currency_symbol; ?><?php echo number_format($bd_has_credit ? $folio_credit_balance : $folio_balance_due, 2); ?></strong>
                            </div>
                        </div>

                        <?php if (!$bd_has_due && $folio_amount_paid > $folio_total_amount + BALANCE_TOLERANCE): ?>
                            <?php $overpaid_amount = $folio_amount_paid - $folio_total_amount; ?>
                            <p class="folio-overpay-hint">
                                <i class="fas fa-info-circle"></i>
                                Guest has overpaid by <?php echo $currency_symbol . number_format($overpaid_amount, 2); ?>.
                                <a href="credit-notes.php?booking_id=<?php echo $booking_id; ?>">Issue a credit note</a> to apply the excess.
                            </p>
                        <?php endif; ?>

                        <?php if ($booking['payment_reference']): ?>
                            <p class="payment-reference">
                                <i class="fas fa-receipt"></i> Reference: <?php echo htmlspecialchars($booking['payment_reference']); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($booking['payment_status'] !== 'paid' && !$bd_has_due): ?>
                            <p class="bd-paid-note"><i class="fas fa-check-circle"></i> Nothing is owed on this booking.</p>
                        <?php elseif ($booking['payment_status'] !== 'paid'): ?>
                            <div class="payment-form">
                                <?php if ($bd_perm_pay): /* owner 2026-11-12: full or part payment, never one click */ ?>
                                    <a href="payment-add.php?booking_type=room&amp;booking_id=<?php echo (int)$booking_id; ?>" class="payment-mark-paid-btn" style="text-decoration:none;" title="Enter the amount the guest paid (all or part of the balance) and how they paid">
                                        <i class="fas fa-money-bill-wave"></i> Record payment
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="bd-paid-note"><i class="fas fa-check-circle"></i> Payment received.</p>
                        <?php endif; ?>
                    </section>
                </div><!-- /#folio -->

                <section class="rh-panel invoices-card" id="invoices">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Invoices</h2>
                        <div class="rh-panel__actions folio-actions">
                            <?php if (!$bPerms['can_generate_invoice'] || !$bPerms['can_send_invoice']): ?>
                                <div class="folio-locked-msg">
                                    <i class="fas fa-lock"></i>
                                    <?php echo htmlspecialchars($bPerms['can_generate_invoice_reason'] ?: $bPerms['can_send_invoice_reason']); ?>
                                </div>
                            <?php else: ?>
                                <form method="POST" data-admin-confirm="Generate an invoice PDF for this booking without sending it?" data-admin-confirm-title="Generate invoice" data-admin-confirm-ok="Generate" data-admin-confirm-icon="fa-file-pdf" data-admin-submit-text="Generating...">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="invoice_action" value="generate_invoice">
                                    <button type="submit" class="folio-btn primary">
                                        <i class="fas fa-file-pdf"></i> Generate Invoice
                                    </button>
                                </form>
                                <?php if ($bd_doc !== 'receipt'): ?>
                                <form method="POST" data-admin-confirm="Send this booking invoice to the guest by email?" data-admin-confirm-title="Send invoice email" data-admin-confirm-ok="Send email" data-admin-confirm-icon="fa-envelope-circle-check" data-admin-submit-text="Sending invoice...">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="invoice_action" value="send_invoice">
                                    <button type="submit" class="folio-btn success">
                                        <i class="fas fa-envelope-circle-check"></i> Send Invoice (balance due)
                                    </button>
                                </form>
                                <?php else: ?>
                                    <button type="button" class="folio-btn success" onclick="rhSendAccountReceipt('room', <?php echo (int) $booking_id; ?>, <?php echo htmlspecialchars(json_encode((string)$booking['booking_reference']), ENT_QUOTES); ?>)">
                                        <i class="fas fa-receipt"></i> Send Receipt
                                    </button>
                                <?php endif; ?>
                                <?php if ($bd_doc !== 'receipt' && function_exists('isWhatsAppEnabled') && isWhatsAppEnabled()): ?>
                                    <form method="POST" data-admin-confirm="Send the invoice link to the guest on WhatsApp? This can use the configured WhatsApp provider." data-admin-confirm-title="Send invoice via WhatsApp" data-admin-confirm-ok="Send WhatsApp" data-admin-confirm-icon="fa-whatsapp" data-admin-submit-text="Sending WhatsApp...">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="invoice_action" value="send_invoice_whatsapp">
                                        <button type="submit" class="folio-btn whatsapp">
                                            <i class="fab fa-whatsapp"></i> Send via WhatsApp
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($existing_invoices)): ?>
                        <ul class="invoice-list">
                            <?php foreach ($existing_invoices as $invoice): ?>
                                <li class="invoice-item">
                                    <div class="invoice-info">
                                        <span class="invoice-number"><i class="fas fa-file-invoice"></i> <?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                        <span class="invoice-date">Generated <?php echo htmlspecialchars(rhToUserTime($invoice['created_at'], 'M j, Y \a\t g:i A')); ?></span>
                                        <?php $bdFromQuote = $bd_quote_invoices[trim((string)($invoice['invoice_number'] ?? ''))] ?? ''; ?>
                                        <?php if ($bdFromQuote !== ''): ?>
                                            <span class="invoice-date" title="Converted from this quotation with Convert to invoice on the Quotations page.">Made from quotation <?php echo htmlspecialchars($bdFromQuote); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="invoice-actions">
                                        <?php if (empty($invoice['invoice_path']) && !empty($invoice['quote_download'])): ?>
                                            <a href="<?php echo htmlspecialchars($invoice['quote_download']); ?>" class="invoice-btn view" data-no-spa="1" data-no-admin-loader="1">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($invoice['invoice_path']): ?>
                                            <a href="document-download.php?type=<?php echo !empty($invoice['id']) ? 'invoice&amp;id=' . (int)$invoice['id'] : 'booking_invoice&amp;id=' . (int)$booking_id; ?>" target="_blank" class="invoice-btn view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="rh-empty">No invoices generated yet.</p>
                    <?php endif; ?>
                </section>
            </div><!-- /.main column -->

            <!-- RIGHT: guest history, notes, activity -->
            <aside class="rh-layout__side">

                <?php if (trim((string)($booking['guest_email'] ?? '')) !== ''): ?>
                <?php $gh_ok = !empty($guest_history['ok']); ?>
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Guest history</h2>
                        <?php if (!$gh_ok): ?>
                            <span class="gh-badge gh-badge--new"><i class="fas fa-circle-exclamation"></i> Could not load</span>
                        <?php elseif ($guest_history['is_returning']): ?>
                            <span class="gh-badge gh-badge--returning" title="Has a checked-out stay before or after this booking"><i class="fas fa-redo-alt"></i> Returning guest</span>
                        <?php else: ?>
                            <span class="gh-badge gh-badge--new" title="No other checked-out stay under this email"><i class="fas fa-star"></i> First stay</span>
                        <?php endif; ?>
                    </div>
                    <dl class="bd-stats">
                        <div><dt>Completed stays<br><small class="bd-muted">checked-out stays under this email, this one included; rooms booked together count once; in-house and upcoming not counted</small></dt><dd><?php echo $gh_ok ? (int) $guest_history['completed_stays'] : '&ndash;'; ?></dd></div>
                        <div><dt>Other bookings<br><small class="bd-muted">every other booking under this email, any status</small></dt><dd><?php echo $gh_ok ? (int) $guest_history['other_bookings'] : '&ndash;'; ?></dd></div>
                        <?php if (is_array($guest_history['money'])): ?>
                        <div><dt>Received<br><small class="bd-muted">money paid in across all their bookings, this one included, less refunds</small></dt><dd><?php echo htmlspecialchars((string)$currency_symbol) . number_format((float)$guest_history['money']['received'], 0); ?></dd></div>
                        <?php if ((float)$guest_history['money']['refunded'] > BALANCE_TOLERANCE): ?>
                        <?php $gh_split = rh_refund_split_text((float)$guest_history['money']['refunded'], (float)($guest_history['money']['to_credit'] ?? 0), (string)$currency_symbol, 0); ?>
                        <div><dt>Refunded<br><small class="bd-muted"><?php echo htmlspecialchars($gh_split !== '' ? $gh_split . '; already taken off Received' : 'paid back in cash / bank, in full; already taken off Received'); ?></small></dt><dd><?php echo htmlspecialchars((string)$currency_symbol) . number_format((float)$guest_history['money']['refunded'], 0); ?></dd></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($guest_history['recent_other'])): ?>
                    <div class="gh-past-list">
                        <small class="bd-muted">Latest other bookings (up to 6, newest arrival first)</small>
                        <?php foreach ($guest_history['recent_other'] as $pb): ?>
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
                </section>
                <?php endif; ?>

                <section class="rh-panel notes-card">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Internal notes</h2>
                    </div>
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
                            <p class="rh-empty">No notes yet.</p>
                        <?php else: ?>
                            <?php foreach ($notes as $note): ?>
                                <div class="note-item">
                                    <div class="note-header">
                                        <span class="note-author"><?php echo htmlspecialchars($note['created_by_name'] ?? 'Unknown'); ?></span>
                                        <span class="note-time"><?php echo htmlspecialchars(rhToUserTime($note['created_at'], 'M j, H:i')); ?></span>
                                    </div>
                                    <div class="note-text"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rh-panel timeline-card">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Activity timeline</h2>
                        <button type="button" class="btn btn-secondary btn-sm" data-rh-history="booking:<?php echo (int)$booking_id; ?>" title="Open every step, oldest first, with a Print button">Full history</button>
                    </div>
                    <p class="rh-empty" style="margin:0 0 6px;font-size:0.8rem;">The latest 10 steps, newest first: what happened, who did it and when (hotel time).</p>
                    <div class="timeline-list">
                        <?php if (empty($timeline)): ?>
                            <p class="rh-empty">No activity recorded yet.</p>
                        <?php else: ?>
                            <?php
                            $bd_tl_types = ['created' => 'create', 'payment' => 'payment', 'refund' => 'payment', 'credit_note' => 'payment', 'status' => 'status_change',
                                'cancel' => 'cancellation', 'email' => 'email', 'check_in' => 'check_in', 'check_out' => 'check_out', 'note' => 'note',
                                'price' => 'update', 'room' => 'update', 'edit' => 'update'];
                            foreach (array_slice($timeline, 0, 10) as $event):
                                $bd_tl_type = $bd_tl_types[$event['kind']] ?? 'update';
                                $type_info = formatActionType($bd_tl_type);
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon <?php echo htmlspecialchars($bd_tl_type); ?>">
                                        <i class="fas <?php echo htmlspecialchars($type_info['icon']); ?>"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title"><?php echo htmlspecialchars($event['what']); ?></div>
                                        <div class="timeline-meta">
                                            <?php echo htmlspecialchars($event['who']); ?> &middot; <?php echo htmlspecialchars(date('M j, H:i', strtotime($event['at']))); ?>
                                        </div>
                                        <?php if ($event['detail'] !== ''): ?>
                                            <div class="timeline-adjustment-reason" style="white-space:pre-line;"><?php echo htmlspecialchars($event['detail']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div><!-- /.rh-layout -->
    </div>

    <!-- Add Charge Modal -->
    <div class="modal-overlay" id="addChargeModal">
        <div class="modal-content">
            <form method="POST" id="addChargeForm">
                <input type="hidden" name="charge_action" value="add_charge">
            <div class="modal-header">
                <h3><i class="fas fa-plus" style="color: var(--gold, #7E684B);"></i> Add Custom Charge</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeAddChargeModal()">&times;</button>
            </div>
                <div class="modal-body">
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
                </div>
                <div class="modal-actions modal-footer">
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
            <form method="POST" id="menuForm">
                <input type="hidden" name="charge_action" value="add_menu_item">
                <input type="hidden" name="menu_type" id="menuType" value="food">
                <input type="hidden" name="menu_item_id" id="menuItemId" value="">
                <input type="hidden" name="quantity" id="menuQuantity" value="1">
            <div class="modal-header">
                <h3><i class="fas fa-utensils" style="color: var(--gold, #7E684B);"></i> Add Menu Item to Folio</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeMenuModal()">&times;</button>
            </div>
                <div class="modal-body">
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
                </div>
                <div class="modal-actions modal-footer">
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
            <form method="POST" id="voidChargeForm">
                <input type="hidden" name="charge_action" value="void_charge">
                <input type="hidden" name="charge_id" id="voidChargeId" value="">
            <div class="modal-header">
                <h3><i class="fas fa-ban" style="color: #a03030;"></i> Void Charge</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeVoidChargeModal()">&times;</button>
            </div>
                <div class="modal-body">
                <div class="form-group">
                    <label>Charge to Void</label>
                    <input type="text" id="voidChargeDescription" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label>Reason for Voiding</label>
                    <textarea name="void_reason" placeholder="e.g., Item not consumed, Error in charging, Guest complaint" required></textarea>
                </div>
                </div>
                <div class="modal-actions modal-footer">
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
            <form method="POST" id="dateAdjustForm">
                <input type="hidden" name="adjust_dates" value="1">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt" style="color: var(--color-lux-gold, #8F6A35);"></i> Adjust Stay Dates</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeDateAdjustModal()">&times;</button>
            </div>
                <div class="modal-body">
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
                    <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">At the booked rate:<br><small>room rate a night &times; new nights</small></span>
                        <span id="previewRateLine" style="font-size: 13px; font-weight: 600; text-align: right;">-</span>
                    </div>
                    <div id="previewPlanDrop" role="status" style="font-size: 12px; color: #b42318; margin-bottom: 8px;" hidden></div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">New Total:</span>
                        <span id="previewNewTotal" style="font-size: 13px; font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #d0e8ff;">
                        <span style="font-size: 12px; font-weight: 600; color: #1a7a96;">Room charge change (room only):</span>
                        <span id="previewDelta" style="font-size: 14px; font-weight: 700; color: var(--color-lux-ink, #231F1C);">-</span>
                    </div>
                </div>

                <div id="dateAdjustError" style="background: #fef2f2; color: #a03030; padding: 12px; border-radius: 8px; margin-bottom: 16px; display: none;"></div>

                <div id="dateAdjustWarning" style="background: #fff7ed; color: #c2410c; padding: 12px; border-radius: 8px; margin-bottom: 16px; display: none;"></div>

                <div class="form-group">
                    <label>Reason for Adjustment *</label>
                    <textarea name="adjustment_reason" placeholder="e.g., Guest requested early check-in, Extended stay due to flight delay, Guest checked out early" required></textarea>
                </div>
                </div>
                <div class="modal-actions modal-footer">
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
            // Alert.show renders HTML; these messages are plain text and can carry staff notes / guest names.
            var safe = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            if (window.Alert && typeof window.Alert.show === 'function') {
                Alert.show(safe, type || 'info', {
                    title:   type === 'error' ? 'Action Failed' : _bookingToastTitle(text),
                    timeout: 5500,
                    position: 'top-right'
                });
                return;
            }
            (window.__rhToastQueue = window.__rhToastQueue || []).push({
                msg: safe, type: type || 'info',
                opts: { title: type === 'error' ? 'Action Failed' : _bookingToastTitle(text), timeout: 5500 }
            });
        }

        if (bookingFlash.success) {
            showBookingActionMessage(bookingFlash.success, 'success');
        }
        if (bookingFlash.error) {
            showBookingActionMessage(bookingFlash.error, 'error');
        }
        // Result of an action finished on the bookings list (assign room, change room type,
        // checkout) that was opened from this booking.
        try {
            var _bkQueued = sessionStorage.getItem('rh_booking_action_message');
            if (_bkQueued) {
                sessionStorage.removeItem('rh_booking_action_message');
                var _bkQueuedMsg = JSON.parse(_bkQueued);
                if (_bkQueuedMsg && _bkQueuedMsg.message) {
                    showBookingActionMessage(_bkQueuedMsg.message, _bkQueuedMsg.type || 'success');
                }
            }
        } catch (e) {
            try { sessionStorage.removeItem('rh_booking_action_message'); } catch (e2) {}
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
        const _bookedSplit = <?php echo json_encode(rh_booked_stay_split($booking)); ?>;
        const bookedRoomGrossPerNight = Number(_bookedSplit.per_night) || 0;
        const bookedPackageGross = Number(_bookedSplit.package_gross) || 0;
        const currentTotal = Number(_bookedSplit.total_with_vat) || 0;
        const currentChildSupplement = <?php echo (float)($booking['child_supplement_total'] ?? 0); ?>;
        // RP-3: the discount a date change would remove (same rule as rh_rate_plan_dropped() on save), or null.
        const _planDrop = <?php echo json_encode(($bd_closed ?? false) ? null : rh_rate_plan_drop_js($pdo, $booking, (int)$booking['room_id']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const _ymdUtc = s => {
            const p = String(s || '').split('-').map(Number);
            return p.length === 3 ? Date.UTC(p[0], p[1] - 1, p[2]) : NaN;
        };
        const _dayMonYear = s => {
            const d = new Date(_ymdUtc(s));
            return d.getUTCDate() + ' ' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()] + ' ' + d.getUTCFullYear();
        };
        // '' while the booking's length-of-stay / weekend / seasonal discount still fits the new dates, else the reason.
        function bdPlanDropReason(ci, co, nights) {
            const D = _planDrop;
            if (!D || (ci === currentCheckIn && co === currentCheckOut)) return '';
            if (!D.appliesToRoom) return 'the plan no longer covers this room type';
            const nWord = nights + ' night' + (nights === 1 ? '' : 's');
            if (D.rule === 'los_discount') {
                const max = D.maxNights > 0 ? D.maxNights : Infinity;
                if (nights >= D.minNights && nights <= max) return '';
                return nights < D.minNights
                    ? 'the stay is now ' + nWord + ' and the plan needs at least ' + D.minNights + ' night' + (D.minNights === 1 ? '' : 's')
                    : 'the stay is now ' + nWord + ' and the plan allows at most ' + D.maxNights + ' night' + (D.maxNights === 1 ? '' : 's');
            }
            if (D.rule === 'weekend') {
                const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                for (let t = _ymdUtc(ci), end = _ymdUtc(co); t < end; t += 86400000) {
                    if (D.days.indexOf(new Date(t).getUTCDay()) !== -1) return '';
                }
                const list = D.days.filter(d => names[d]).map(d => names[d]);
                return 'no night of the new stay falls on ' + (list.length ? list.join(', ') : "the plan's days");
            }
            if (D.start && D.end && ci >= D.start && ci <= D.end) return ''; // seasonal: check-in date only
            return "the new check-in date (" + _dayMonYear(ci) + ") is outside the plan's dates"
                + (D.start && D.end ? ' (' + _dayMonYear(D.start) + ' to ' + _dayMonYear(D.end) + ')' : '');
        }
        // The booked room rate a night WITHOUT the children charge, and the children charge it shows separately
        // (includes/booked-rate.php), so "rate x nights + children + package = total" adds up.
        const _bookedRate = <?php echo json_encode(['ok' => (bool)$bd_rate['ok'], 'per_night' => (float)$bd_rate['per_night'], 'child' => (float)$bd_rate['child'], 'currency' => (string)$bd_rate['currency']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // "K356,000" / "MWK 356,000.50": decimals only when there are tambala (same as rh_hist_money()).
        function bdRateMoney(v) {
            const a = Math.round(Math.abs(Number(v) || 0) * 100) / 100;
            const cur = String(_bookedRate.currency || currencySymbol || '');
            const hasCents = Math.abs(a - Math.round(a)) > 0.004;
            const num = a.toLocaleString('en-US', { minimumFractionDigits: hasCents ? 2 : 0, maximumFractionDigits: hasCents ? 2 : 0 });
            return ((Number(v) || 0) < 0 ? '-' : '') + cur + (cur.length > 1 && /^[A-Za-z]+$/.test(cur) ? ' ' : '') + num;
        }

        // "K356,000 x 4 nights = K1,424,000 (+ children / + package = total)" for the new nights.
        function bdRateLine(newNights, newTotal, addBack) {
            if (!_bookedRate.ok || !(newNights > 0)) return '';
            const nightWord = newNights === 1 ? '1 night' : newNights + ' nights';
            const pkg = bookedPackageGross > 0.005 ? bookedPackageGross : 0;
            const child = _bookedRate.child > 0.005 && currentNights > 0 ? Math.round(_bookedRate.child * newNights / currentNights * 100) / 100 : 0;
            const roomOnly = Math.round((newTotal - pkg - child) * 100) / 100;
            let text = bdRateMoney(_bookedRate.per_night + (addBack || 0)) + ' \u00d7 ' + nightWord + ' = ' + bdRateMoney(roomOnly);
            let extras = '';
            if (child > 0) extras += ' + children ' + bdRateMoney(child);
            if (pkg > 0) extras += ' + package ' + bdRateMoney(pkg);
            if (extras !== '') text += extras + ' = ' + bdRateMoney(newTotal);
            return text;
        }
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

            // Repriced at the guest's BOOKED nightly rate (server-computed gross per night:
            // VAT, levy and child supplement already inside; packages kept as-is).
            const dropReason = bdPlanDropReason(newCheckIn, newCheckOut, newNights);
            const addBack = dropReason ? Number(_planDrop.perNight || 0) : 0;
            const dropEl = document.getElementById('previewPlanDrop');
            if (dropEl) {
                dropEl.textContent = dropReason ? 'The ' + _planDrop.name + ' discount will be removed: ' + dropReason + '. ' + bdRateMoney(addBack) + ' a night is added back to the rate.' : '';
                dropEl.hidden = !dropReason;
            }
            const newTotal = (bookedRoomGrossPerNight + addBack) * newNights + bookedPackageGross;
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

            document.getElementById('previewRateLine').textContent = bdRateLine(newNights, newTotal, addBack) || '-';

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

        // Delete / restore run through bookings.php (one implementation: includes/booking-soft-delete.php).
        function bdBookingPost(action, extra) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('booking_id', '<?php echo (int)$booking_id; ?>');
            fd.append('csrf_token', window._rhCsrf || (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
            Object.keys(extra || {}).forEach(function(k) { fd.append(k, extra[k]); });
            return fetch('bookings.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d || !d.success) throw new Error((d && d.message) || 'Action failed.');
                    return d;
                });
        }

        function bdDeleteBooking(scope, rooms) {
            if (!window.AdminConfirm) return;
            var wholeGroup = scope === 'group' && rooms > 1;
            AdminConfirm.prompt({
                title: (wholeGroup ? 'Delete whole group (' + rooms + ' rooms) - ' : 'Delete booking ') + <?php echo json_encode((string)$booking['booking_reference'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                message: wholeGroup ? 'All rooms booked together move to the Deleted filter. If any room cannot be deleted, none is.' : 'The booking moves to the Deleted filter on the bookings list. It can be restored later.',
                details: ['Any room it holds is released. Nothing is sent to the guest.', 'Your name, the exact time and the reason are recorded.'],
                inputLabel: 'Why is this booking being deleted? (required)',
                inputPlaceholder: 'e.g. Duplicate booking, test booking, created in error',
                confirmText: 'Delete booking',
                tone: 'danger',
                icon: 'fa-trash-can'
            }).then(function(reason) {
                if (reason === null) return;
                if (reason.trim().length < 5) {
                    Alert.show('A reason of at least 5 characters is required.', 'error');
                    return;
                }
                bdBookingPost('delete_booking', { reason: reason.trim(), scope: wholeGroup ? 'group' : 'single' })
                    .then(function(d) { Alert.show(d.message, 'success'); setTimeout(function() { location.reload(); }, 900); })
                    .catch(function(err) { Alert.show(err.message, 'error'); });
            });
        }

        function bdRestoreBooking() {
            if (!window.AdminConfirm) return;
            AdminConfirm.prompt({
                title: 'Restore booking',
                message: 'The booking gets back the status it had before it was deleted.',
                details: ['If it held a room, the room type must still be free for its dates.'],
                inputLabel: 'Note (optional)',
                confirmText: 'Restore booking',
                tone: 'success',
                icon: 'fa-trash-arrow-up'
            }).then(function(note) {
                if (note === null) return;
                bdBookingPost('restore_booking', { note: note.trim() })
                    .then(function(d) { Alert.show(d.message, 'success'); setTimeout(function() { location.reload(); }, 900); })
                    .catch(function(err) { Alert.show(err.message, 'error'); });
            });
        }
    </script>

    <?php rh_render_send_receipt_script($csrf_token ?? ''); ?>
    <?php require_once __DIR__ . '/includes/history-panel.php'; ?>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

