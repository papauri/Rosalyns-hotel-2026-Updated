<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once '../config/base-url.php';
require_once __DIR__ . '/includes/review-panel.php'; // the Decisions panel: prints nothing unless switched on and allowed

/** @var array $user Admin user array injected by admin-init.php */
/** @var string $csrf_token */

// Load per-user granular permissions for action-button visibility
$_user_permissions = getUserPermissions($user['id']);
$_is_admin_user = in_array($user['role'] ?? '', ['admin', 'manager'], true);
$_perm_quick_modify = $_user_permissions['quick_modify_booking'] ?? false;
$_perm_edit_financials = $_user_permissions['edit_booking_financials'] ?? false;
$_perm_delete = $_user_permissions['delete_booking'] ?? false;

require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/booking-timeline.php';
require_once '../includes/booking-soft-delete.php';
require_once '../includes/booking-room-change.php';
require_once '../includes/finance-sequences.php';
require_once 'includes/finance-account-sync.php';
require_once 'includes/account-receipt-send.php';
require_once __DIR__ . '/../includes/booked-rate-change.php'; // booked rate × nights lines (also loads includes/booked-rate.php)
require_once __DIR__ . '/includes/bookings-list-filters.php'; // tab/date/payment filter rules shared by badges, list and export
require_once __DIR__ . '/../includes/booking-created-by.php'; // "by …" under the Created date in the list
require_once __DIR__ . '/../includes/late-stay.php'; // late check-in / leave-date choices: one quote for assess and settle
require_once __DIR__ . '/../includes/stay-nights.php'; // add / take off nights: one quote for the review card and the save
require_once __DIR__ . '/../includes/billing-accounts.php'; // company pays later (migration 084): who owes the balance
$message = '';
$error = '';
$currency_symbol = (string)getSetting('currency_symbol', 'MWK');
finance_ensure_sequence_tables($pdo);

function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Ensure the booking_status_log audit table exists. Extend-stay, admin
 * checkout-date change and room-upgrade all write an audit row here; on
 * installs where this table was never created the INSERT threw a fatal
 * "table doesn't exist" and aborted the whole action (after the booking row
 * had already been updated, leaving the guest silently extended but the UI
 * showing an error). Self-heal the schema, matching the ensure* pattern used
 * across the app. Idempotent and cheap (CREATE TABLE IF NOT EXISTS).
 */
function ensureBookingStatusLogTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_status_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                old_status VARCHAR(32) DEFAULT NULL,
                new_status VARCHAR(32) DEFAULT NULL,
                changed_by INT DEFAULT NULL,
                change_reason VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bsl_booking (booking_id),
                INDEX idx_bsl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Logging must never block the actual booking operation.
        error_log('ensureBookingStatusLogTable: ' . $e->getMessage());
    }
}

/**
 * Write a booking_status_log row without ever letting an audit failure break
 * the surrounding action (missing table, column drift, etc. are swallowed).
 */
function bookings_log_status_change(PDO $pdo, int $bookingId, ?string $oldStatus, ?string $newStatus, ?int $changedBy, string $reason): void
{
    try {
        ensureBookingStatusLogTable($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO booking_status_log (booking_id, old_status, new_status, changed_by, change_reason, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$bookingId, $oldStatus, $newStatus, $changedBy, mb_substr($reason, 0, 500)]);
    } catch (Throwable $e) {
        error_log('bookings_log_status_change (booking ' . $bookingId . '): ' . $e->getMessage());
    }
}

/**
 * Final invoice at checkout (same as the booking page does). Idempotent; never blocks the checkout.
 * Returns a short note for the success message.
 * $emailGuest: the "Email the final invoice to the guest" tick (pay-later bookings only; null = no box shown). A
 * pay-later booking (Check in before payment) is emailed only when it was ticked; other bookings as before.
 */
function bookings_final_invoice_note(int $bookingId, int $userId, ?bool $emailGuest = null): string
{
    try {
        require_once __DIR__ . '/../config/invoice.php';
        $inv = generateAndSendFinalInvoice($bookingId, $userId, $emailGuest);
        if (empty($inv['success'])) {
            return ' Warning: the final invoice could not be generated.';
        }
        // Balance owed -> final INVOICE; paid in full -> a paid STATEMENT (the guest already holds every receipt).
        $owed = (float)($inv['balance_due'] ?? 0) > BALANCE_TOLERANCE;
        $docName = $owed ? 'Final invoice' : 'Stay statement (paid in full)';
        if (!empty($inv['staff_note']) && empty($inv['idempotent'])) {
            return ' ' . (string)$inv['staff_note']; // on a company account: sent to the company billing email (or why not)
        }
        if (!empty($inv['email_skipped']) && empty($inv['idempotent'])) {
            return ' ' . $docName . ' generated, not emailed to the guest (pay-later booking, box not ticked).';
        }
        return (empty($inv['idempotent']) ? ' ' . $docName . ' generated.' : '')
            . ((empty($inv['email_sent']) && empty($inv['idempotent'])) ? ' (' . $docName . ' email not sent.)' : '');
    } catch (Throwable $e) {
        error_log('Final invoice at checkout failed for booking ' . $bookingId . ': ' . $e->getMessage());
        return ' Warning: the final invoice could not be generated.';
    }
}

/**
 * What the check-in dialog needs to ask about a late arrival, from the SERVER's today (never the browser clock):
 * late_days, stay_ended and the booked dates shown in the choices.
 */
function bookings_late_checkin_info(array $bk): array
{
    $w = rh_late_stay_window($bk);
    return [
        'late_days' => (int)$w['late_days'],
        'stay_ended' => (bool)$w['stay_ended'],
        'check_in' => rh_late_stay_fmt($w['check_in']),
        'check_out' => rh_late_stay_fmt($w['check_out']),
        'today' => rh_late_stay_fmt($w['today']),
    ];
}

function getSignedDateDiffDays(DateTimeInterface $fromDate, DateTimeInterface $toDate): int
{
    $diff = $fromDate->diff($toDate);
    $days = (int)($diff->days ?? 0);
    return ((int)$diff->invert === 1) ? -$days : $days;
}

/**
 * Booked-rate lines for a stay changing to $newNights (extend, shorten, change dates, early / late checkout):
 * current_text "K356,000 × 3 nights = K1,068,000", new_text "K356,000 × 4 nights = K1,424,000" (the same
 * rh_reprice_at_booked_rate() figure the save writes), delta and a plain delta_text. Read-only.
 */
function bookings_stay_rate_preview(array $bk, int $newNights, string $cur, ?string $newIn = null, ?string $newOut = null): array
{
    $newNights = max(1, $newNights);
    // RP-3: the same discount check the save makes (only when the dates actually change).
    $drop = ['removed' => false, 'per_night' => 0.0];
    $newIn = $newIn ?? substr((string)$bk['check_in_date'], 0, 10);
    if ($newOut !== null && ($newIn !== substr((string)$bk['check_in_date'], 0, 10) || $newOut !== substr((string)$bk['check_out_date'], 0, 10))) {
        $drop = rh_rate_plan_dropped($GLOBALS['pdo'], $bk, $newIn, $newOut, $newNights, (int)$bk['room_id']);
    }
    $rp = rh_reprice_at_booked_rate($bk, $newNights, (float)$drop['per_night']);
    $now = rh_booked_rate_line($bk, null, ['currency' => $cur]);
    $new = rh_booked_rate_change_for_booking($bk, $rp, $newNights, ['currency' => $cur]);
    $delta = round((float)$rp['delta'], 2);
    if ($delta > BALANCE_TOLERANCE) {
        $deltaText = rh_hist_money($delta, $cur) . ' more than now';
    } elseif ($delta < -BALANCE_TOLERANCE) {
        $deltaText = rh_hist_money(abs($delta), $cur) . ' less than now';
    } else {
        $deltaText = 'No change to the room total';
    }
    return [
        'ok' => $now['ok'] && $new['ok'],
        'nights' => $newNights,
        'current_text' => $now['text'],
        'new_text' => $new['text'],
        'new_total' => round((float)$rp['new_gross'], 2),
        'delta' => $delta,
        'delta_text' => $deltaText,
        'plan_note' => !empty($drop['removed'])
            ? 'The ' . $drop['plan_name'] . ' discount will be removed: ' . $drop['reason'] . '. ' . rh_hist_money((float)$drop['per_night'], $cur) . ' a night is added back to the rate.'
            : '',
    ];
}

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        // A deleted booking is read-only until it is restored.
        $guardId = (int)($_POST['booking_id'] ?? ($_POST['id'] ?? 0));
        if ($guardId > 0 && $action !== 'restore_booking' && !in_array($action, ['get_booking_details', 'get_booking_audit_log', 'get_booking_for_modify'], true)) {
            $delSt = $pdo->prepare("SELECT deleted_at FROM bookings WHERE id = ?");
            $delSt->execute([$guardId]);
            if (!empty($delSt->fetchColumn())) {
                throw new Exception('This booking has been deleted. Restore it before making changes.');
            }
        }

        // Ensure the audit table exists up front (before any transaction) so its
        // CREATE-TABLE DDL can't trigger an implicit commit mid-transaction, and
        // so the later bookings_log_status_change() calls never hit a missing table.
        ensureBookingStatusLogTable($pdo);

        // Each action needs the permission its button is shown for.
        $_bkNeed = rhBookingActionPermission((string)$action, (string)($_POST['status'] ?? ''));
        if ($_bkNeed !== null && !hasPermission((int)($user['id'] ?? 0), $_bkNeed)) {
            throw new Exception('You do not have permission to do that (' . (getAllPermissions()[$_bkNeed]['label'] ?? $_bkNeed) . ').');
        }

        // Owner 2026-11-12: no one-click "mark paid" (it booked the whole balance as cash). The money icon opens the
        // Record payment dialog (full or part, method, confirm), which posts to payment-add.php. A page loaded before
        // this change still sends 'paid' here: refuse it, nothing is recorded.
        if ($action === 'update_payment' && (string)($_POST['payment_status'] ?? '') === 'paid') {
            throw new Exception('Nothing was recorded. Reload the page and use the money icon (Record payment) to enter the amount the guest paid and how.');
        }

        if ($action === 'delete_booking' || $action === 'restore_booking') {
            // Recoverable delete / restore (permission delete_booking; see includes/booking-soft-delete.php).
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!hasPermission((int)($user['id'] ?? 0), 'delete_booking')) {
                throw new Exception('You do not have permission to delete or restore bookings.');
            }
            $actorName = (string)($user['full_name'] ?? ($user['username'] ?? 'Admin'));
            if ($action === 'delete_booking') {
                $delId = (int)($_POST['booking_id'] ?? 0);
                // Group booking (owner 2026-10-20): the modal asks each time, scope=group deletes every live
                // room of the group in one transaction; scope=single (or no scope) is today's one-room delete.
                $delGroup = ($_POST['scope'] ?? '') === 'group' ? rh_booking_group_ids($pdo, $delId) : [];
                $res = count($delGroup) > 1
                    ? rh_soft_delete_booking_group($pdo, $delGroup, (int)$user['id'], $actorName, (string)($_POST['reason'] ?? ''))
                    : rh_soft_delete_booking($pdo, $delId, (int)$user['id'], $actorName, (string)($_POST['reason'] ?? ''));
            } else {
                $res = rh_restore_booking($pdo, (int)($_POST['booking_id'] ?? 0), (int)$user['id'], $actorName, (string)($_POST['note'] ?? ''));
            }
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        } elseif ($action === 'booking_group_info') {
            // Read-only lookup for the cancel / delete dialogs: the live rooms booked together with this one.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!hasPermission((int)($user['id'] ?? 0), 'cancel_booking') && !hasPermission((int)($user['id'] ?? 0), 'delete_booking')) {
                throw new Exception('You do not have permission to do that.');
            }
            $gm = rh_booking_group_members($pdo, (int)($_POST['booking_id'] ?? ($_POST['id'] ?? 0)));
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'count' => count($gm), 'members' => array_map(static fn ($m) => [
                'id' => (int)$m['id'], 'reference' => (string)$m['booking_reference'], 'guest' => (string)$m['guest_name'],
                'status' => (string)$m['status'], 'room' => (string)$m['room_name'],
            ], $gm)]);
            exit;
        } elseif ($action === 'resend_email') {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $email_type = $_POST['email_type'] ?? '';
            $cc_emails = $_POST['cc_emails'] ?? '';

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // Include email functions
            require_once '../config/email.php';
            // System Logs > Emails sent: every email resent here is about this booking (the sender adds the kind).
            // Script scope: cleared in the finally below so it never reaches the scheduler that runs after the page.
            $emailCtx = rh_email_context(rh_email_ctx_account('room', $booking_id, (string)($booking['booking_reference'] ?? '')));

            // Parse CC emails
            $cc_array = [];
            if (!empty($cc_emails)) {
                $cc_array = array_filter(array_map('trim', explode(',', $cc_emails)));
                $cc_array = array_filter($cc_array, function ($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }

            // Send appropriate email based on type
            $email_result = ['success' => false, 'message' => 'Invalid email type'];

            try {
            switch ($email_type) {
                case 'booking_received':
                    $email_result = sendBookingReceivedEmail($booking);
                    break;
                case 'booking_confirmed':
                    $email_result = sendBookingConfirmedEmail($booking);
                    break;
                case 'tentative_confirmed':
                    $booking['tentative_expires_at'] = $booking['tentative_expires_at'] ?? date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $email_result = sendTentativeBookingConfirmedEmail($booking);
                    break;
                case 'tentative_converted':
                    $email_result = sendTentativeBookingConvertedEmail($booking);
                    break;
                case 'booking_cancelled':
                    // Never tell a guest with a live reservation that it was cancelled.
                    if (!in_array($booking['status'], ['cancelled', 'expired', 'no-show'], true)) {
                        throw new Exception('A cancellation email can only be resent for a cancelled, expired or no-show booking.');
                    }
                    $cancellation_reason = 'Resent by admin';
                    $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                    break;
                case 'booking_reminder':
                    // Reminder emails are only valid for active bookings. A no-show
                    // (or any closed status) is explicitly excluded here so it can
                    // never receive a late/overdue alert — no-show takes precedence
                    // over any time-based checkout logic. sendBookingReminderEmail()
                    // enforces the same rule again as defence-in-depth.
                    if (!in_array($booking['status'], ['confirmed', 'pending', 'checked-in'], true)) {
                        throw new Exception('Reminder emails are only for active bookings (no-show, cancelled and closed bookings are excluded).');
                    }
                    $email_result = sendBookingReminderEmail($booking);
                    break;
                case 'invoice':
                    require_once '../config/invoice.php';
                    $cc_recipients = array_values(array_filter($cc_array));
                    $inv_smtp = getEmailSetting('smtp_username', '');
                    if (!empty($inv_smtp) && !in_array($inv_smtp, $cc_recipients)) {
                        $cc_recipients[] = $inv_smtp;
                    }
                    $email_result = sendPaymentInvoiceEmailWithCC($booking_id, $cc_recipients);
                    if ($email_result['success']) {
                        logBookingAudit($booking_id, 'email_sent', null, null, 'Invoice email resent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')', $booking['booking_reference'] ?? null);
                    }
                    break;
                default:
                    throw new Exception('Invalid email type selected');
            }
            } finally {
                $emailCtx = null;
                rh_email_context_clear();
            }

            if ($email_result['success']) {
                $message = 'Email sent successfully to ' . htmlspecialchars($booking['guest_email']);
                if (!empty($cc_array)) {
                    $message .= ' (CC: ' . implode(', ', array_map(function ($email) {
                        return htmlspecialchars($email);
                    }, $cc_array)) . ')';
                }
                if (isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            } else {
                throw new Exception('Failed to send email: ' . $email_result['message']);
            }
        } elseif ($action === 'make_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // Use centralized validation for tentative transition
            $validation = validateTentativeTransition($booking);
            if (!$validation['allowed']) {
                throw new Exception(getBookingActionErrorMessage('make_tentative', $validation['reason']));
            }

            // Get tentative duration setting
            $tentative_hours = (int)getSetting('tentative_duration_hours', 48);
            $expires_at = date('Y-m-d H:i:s', strtotime("+$tentative_hours hours"));
            $note = $_POST['note'] ?? '';

            // Convert to tentative status
            $update_stmt = $pdo->prepare("
                UPDATE bookings
                SET status = 'tentative',
                    is_tentative = 1,
                    tentative_expires_at = ?
                WHERE id = ?
            ");
            $update_stmt->execute([$expires_at, $booking_id]);

            // Log the action
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, new_expires_at, action_reason, performed_by, created_at
                ) VALUES (?, 'created', ?, ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                $expires_at,
                $note,
                $user['id']
            ]);

            // Send tentative booking email
            require_once '../config/email.php';
            $booking['tentative_expires_at'] = $expires_at;
            $email_result = sendTentativeBookingConfirmedEmail($booking);

            if ($email_result['success']) {
                $message = 'Booking converted to tentative! Confirmation email sent to guest.';
            } else {
                $message = 'Booking made tentative! (Email failed: ' . $email_result['message'] . ')';
            }

            rh_log_event('bookings', 'info', 'Booking made tentative', ['booking_id' => $booking_id, 'ref' => $booking['booking_reference'] ?? null, 'expires' => $expires_at, 'by' => $user['username'] ?? null]);
            logBookingAudit($booking_id, 'tentative', ['status' => $booking['status'] ?? 'confirmed'], ['status' => 'tentative', 'tentative_expires_at' => $expires_at], 'Made tentative by admin', $booking['booking_reference'] ?? null);
        } elseif ($action === 'convert_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details WITH room information
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            if ($booking['status'] !== 'tentative' || $booking['is_tentative'] != 1) {
                throw new Exception('This is not a tentative booking');
            }

            // Convert exactly once: the status condition in the UPDATE decides the winner, so a
            // double click / second tab can neither double-decrement stock nor send two emails.
            $pdo->beginTransaction();
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, tentative_expires_at = NULL, converted_to_confirmed_at = NOW(), converted_from_tentative = 1 WHERE id = ? AND status = 'tentative' AND is_tentative = 1");
            $update_stmt->execute([$booking_id]);
            if ($update_stmt->rowCount() !== 1) {
                $pdo->rollBack();
                throw new Exception('This booking was already converted or changed.');
            }

            // Decrement room availability — tentative bookings don't consume rooms_available,
            // but confirmed bookings do; apply the same decrement as pending→confirmed.
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                ->execute([$booking['room_id']]);
            $pdo->commit();

            $autoAssignMessage = '';
            if (in_array($booking['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking['individual_room_id'])) {
                $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                    $autoAssignMessage = ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                } elseif (!$autoAssignResult['success']) {
                    $autoAssignMessage = ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                }
            }

            // Log the conversion
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, action_reason, performed_by, created_at
                ) VALUES (?, 'converted', ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                'Converted from tentative to confirmed by admin',
                $user['id']
            ]);

            // Send conversion email
            require_once '../config/email.php';
            $email_result = sendTentativeBookingConvertedEmail($booking);

            // Log email result for debugging
            error_log("Email sending result for booking {$booking_id}: " . json_encode($email_result));

            if ($email_result['success']) {
                if (isset($email_result['preview_url'])) {
                    $message = 'Tentative booking converted to confirmed!' . $autoAssignMessage . ' <a href="../' . htmlspecialchars($email_result['preview_url']) . '" target="_blank">View email preview</a> (Development Mode)';
                } else {
                    $message = 'Tentative booking converted to confirmed!' . $autoAssignMessage . ' Conversion email sent to ' . htmlspecialchars($booking['guest_email']);
                }
            } else {
                $message = 'Tentative booking converted!' . $autoAssignMessage . ' <strong>Email failed:</strong> ' . htmlspecialchars($email_result['message']);
                error_log("FAILED to send email for converted booking {$booking_id}: " . $email_result['message']);
            }

            rh_log_event('bookings', 'info', 'Tentative booking confirmed', ['booking_id' => $booking_id, 'ref' => $booking['booking_reference'] ?? null, 'by' => $user['username'] ?? null]);
            logBookingAudit($booking_id, 'confirmed', ['status' => 'tentative', 'is_tentative' => 1], ['status' => 'confirmed', 'is_tentative' => 0], 'Converted from tentative to confirmed by admin', $booking['booking_reference'] ?? null);
        } elseif ($action === 'convert_to_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception('Booking not found');
            }

            // BLOCK: Confirmed bookings CANNOT be converted to tentative
            // This is a business rule to prevent accounting issues
            // Once a booking is confirmed or has any payment, it cannot revert to tentative
            $validation = validateTentativeTransition($booking);
            if (!$validation['allowed']) {
                throw new Exception(getBookingActionErrorMessage('make_tentative', $validation['reason']));
            }

            // If we reach here, the validation passed (shouldn't happen for confirmed bookings)
            throw new Exception('Operation not allowed: confirmed bookings cannot be converted to tentative');
        } elseif ($action === 'get_available_rooms') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $room_type_id = (int)($_POST['room_type_id'] ?? 0);
            $check_in = trim($_POST['check_in'] ?? '');
            $check_out = trim($_POST['check_out'] ?? '');
            $exclude_booking_id = !empty($_POST['exclude_booking_id']) ? (int)$_POST['exclude_booking_id'] : null;

            if ($room_type_id <= 0 || !$check_in || !$check_out) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing required parameters',
                    'data' => []
                ]);
                exit;
            }

            $bookingChildGuests = 0;
            $bookingInHouse = false;
            if ($exclude_booking_id) {
                $childStmt = $pdo->prepare("SELECT child_guests, status FROM bookings WHERE id = ?");
                $childStmt->execute([$exclude_booking_id]);
                $bkRow = $childStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $bookingChildGuests = (int)($bkRow['child_guests'] ?? 0);
                $bookingInHouse = (string)($bkRow['status'] ?? '') === 'checked-in';
            }

            // Every room of the type (or every joined pair) with its state and the reason it
            // cannot be used, so nothing silently disappears from the picker.
            // An in-house guest only needs the room for the nights still to come.
            if ($bookingInHouse && substr($check_in, 0, 10) < date('Y-m-d')) {
                $check_in = date('Y-m-d');
            }
            $candidates = rh_room_assignment_candidates($room_type_id, $check_in, $check_out, $exclude_booking_id, $bookingInHouse);
            $normalized = array_map(function ($item) use ($bookingChildGuests) {
                $item['requires_child_override'] = $item['available'] && $bookingChildGuests > 0 && (int)$item['children_allowed'] === 0;
                return $item;
            }, $candidates['items']);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Rooms loaded',
                'mode' => $candidates['mode'],
                'data' => $normalized
            ]);
            exit;
        } elseif ($action === 'assign_room_combination') {
            // Joined-room booking: assign a whole pair (room_combinations row).
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $combination_id = (int)($_POST['combination_id'] ?? 0);
            if ($booking_id <= 0 || $combination_id <= 0) {
                throw new Exception('Invalid booking or room pair selection');
            }
            $bkStmt = $pdo->prepare("SELECT id, status, booking_reference, individual_room_id, room_combination_id FROM bookings WHERE id = ? AND deleted_at IS NULL");
            $bkStmt->execute([$booking_id]);
            $bookingToAssign = $bkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$bookingToAssign) {
                throw new Exception('Booking not found');
            }
            $validation = validateRoomAssignment($bookingToAssign);
            if (!$validation['allowed']) {
                throw new Exception(getBookingActionErrorMessage('assign_room', $validation['reason']));
            }
            $comboResult = assignRoomCombinationToBooking($booking_id, $combination_id, isset($user['id']) ? (int)$user['id'] : null);
            if (empty($comboResult['success'])) {
                throw new Exception('Those joined rooms could not be assigned: ' . ($comboResult['message'] ?: 'they are unavailable for the booking dates') . '.');
            }
            logBookingAudit(
                $booking_id,
                'room_assigned',
                ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null, 'room_combination_id' => $bookingToAssign['room_combination_id'] ?? null],
                ['individual_room_id' => $comboResult['assigned_room_id'], 'room_combination_id' => $combination_id],
                'Joined rooms ' . $comboResult['assigned_room_number'] . ' assigned manually by admin',
                $bookingToAssign['booking_reference'] ?? null
            );
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $comboResult['message']]);
            exit;
        } elseif ($action === 'assign_individual_room') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $individual_room_id = (int)($_POST['individual_room_id'] ?? 0);
            $allowChildPolicyOverride = isset($_POST['allow_child_policy_override']) && $_POST['allow_child_policy_override'] === '1';
            $childPolicyOverrideNote = trim((string)($_POST['child_policy_override_note'] ?? ''));

            if ($booking_id <= 0 || $individual_room_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid booking or room selection']);
                exit;
            }

            $bkStmt = $pdo->prepare("SELECT id, status, room_id, individual_room_id, child_guests, booking_reference, check_in_date FROM bookings WHERE id = ? AND deleted_at IS NULL");
            $bkStmt->execute([$booking_id]);
            $bookingToAssign = $bkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bookingToAssign) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            // Use centralized validation for room assignment
            $validation = validateRoomAssignment($bookingToAssign);
            if (!$validation['allowed']) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => getBookingActionErrorMessage('assign_room', $validation['reason'])]);
                exit;
            }

            if (roomTypeHasActiveCombinations((int)$bookingToAssign['room_id'])) {
                // A joined-room booking takes the whole pair the chosen room belongs to
                // (e.g. "Assign" on one member room in Individual Rooms).
                $pairSt = $pdo->prepare("SELECT id FROM room_combinations WHERE is_active = 1 AND combined_room_type_id = ? AND (room_a_id = ? OR room_b_id = ?) ORDER BY id LIMIT 1");
                $pairSt->execute([(int)$bookingToAssign['room_id'], $individual_room_id, $individual_room_id]);
                $pairId = (int)$pairSt->fetchColumn();
                header('Content-Type: application/json');
                if ($pairId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'This is a joined-room booking and that room is not part of one of its pairs. Assign it a pair from Bookings.']);
                    exit;
                }
                $comboResult = assignRoomCombinationToBooking($booking_id, $pairId, isset($user['id']) ? (int)$user['id'] : null);
                if (empty($comboResult['success'])) {
                    echo json_encode(['success' => false, 'message' => 'Those joined rooms could not be assigned: ' . ($comboResult['message'] ?: 'they are unavailable for the booking dates') . '.']);
                    exit;
                }
                logBookingAudit($booking_id, 'room_assigned', ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null],
                    ['individual_room_id' => $comboResult['assigned_room_id'], 'room_combination_id' => $pairId],
                    'Joined rooms ' . $comboResult['assigned_room_number'] . ' assigned manually by admin', $bookingToAssign['booking_reference'] ?? null);
                echo json_encode(['success' => true, 'message' => $comboResult['message']]);
                exit;
            }

            $policyInfo = getIndividualRoomEffectivePolicy((int)$bookingToAssign['room_id'], $individual_room_id);
            if (!$policyInfo) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Selected room does not match this booking room type']);
                exit;
            }

            if ((int)($bookingToAssign['child_guests'] ?? 0) > 0 && empty($policyInfo['policy']['children_allowed'])) {
                if (!$allowChildPolicyOverride || $childPolicyOverrideNote === '') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'This booking includes children. Assigning a room that does not accept children requires an override note.']);
                    exit;
                }
            }

            // Room-status gate with a clear, actionable reason (out of service; or not clean
            // when an in-house guest would move in now). A reservation may take a room that is
            // still being turned over: check-in is what waits for housekeeping.
            $hkBlock = getRoomHousekeepingAssignmentBlock($individual_room_id, (string)$bookingToAssign['status'] === 'checked-in');
            if ($hkBlock && !empty($hkBlock['blocked'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $hkBlock['message']]);
                exit;
            }

            $assigned = assignIndividualRoomToBooking($booking_id, $individual_room_id, $allowChildPolicyOverride, $childPolicyOverrideNote, $user['id'] ?? null);

            if (!$assigned) {
                header('Content-Type: application/json');
                $assignWhy = rh_room_assign_last_error();
                echo json_encode(['success' => false, 'message' => 'Selected room could not be assigned' . ($assignWhy !== '' ? ': ' . $assignWhy : ' - it is unavailable for the booking dates (it may overlap another reservation, a block, a maintenance window or a housekeeping task).') . ' Check the room timeline and try again.']);
                exit;
            }

            // Note: assignIndividualRoomToBooking() locks the booking + room, re-validates, and keeps the
            // room's status as is (it becomes 'occupied' at check-in).
            logBookingAudit(
                $booking_id,
                'room_assigned',
                ['individual_room_id' => $bookingToAssign['individual_room_id'] ?? null],
                ['individual_room_id' => $individual_room_id],
                $allowChildPolicyOverride ? 'Child policy override: ' . $childPolicyOverrideNote : 'Room assigned manually by admin',
                $bookingToAssign['booking_reference'] ?? null
            );

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Room assigned successfully']);
            exit;
        } elseif ($action === 'update_status') {
            $booking_id = (int)($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            $checkin_note = trim((string)($_POST['checkin_note'] ?? ''));

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Enforce business rules:
            // - Check-in only allowed when confirmed AND paid
            // - Cancel check-in (undo) allowed only when currently checked-in
            if ($new_status === 'checked-in') {
                // Use centralized validation
                $check = $pdo->prepare("SELECT id, status, payment_status, individual_room_id, booking_reference, check_in_date, check_out_date" . rh_checkin_rule_select() . " FROM bookings WHERE id = ?");
                $check->execute([$booking_id]);
                $booking = $check->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new Exception('Booking not found');
                }

                // Use the validation helper function
                $validation = validateCheckIn($booking);
                if (!$validation['allowed']) {
                    throw new Exception(getBookingActionErrorMessage('check_in', $validation['reason']));
                }

                // Late arrival (owner 2026-11-12 "Dont assume, have options"): front desk says what happened -
                // arrived on the booked date, arriving today, or (stay over) already stayed and left. Re-checked here.
                $ciWindow = rh_late_stay_window($booking);
                $ciChoice = rh_late_checkin_choice($ciWindow, $_POST['late_arrival'] ?? null);
                if (!$ciChoice['ok']) {
                    throw new Exception($ciChoice['error']);
                }

                // The clean-room gate protects a guest moving in. "Already stayed and left" (owner 2026-11-13): nobody moves in,
                // so no gate (said in History below); a room another in-house guest holds is never touched by that path.
                $roomGate = $ciChoice['choice'] === 'ended'
                    ? ['allowed' => true, 'override' => false, 'needs_confirm' => false, 'message' => '', 'rooms' => []]
                    : evaluateCheckInRoomReady($booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkin_room_not_ready']));
                if (!$roomGate['allowed']) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $roomGate['message'], 'needs_confirm_room' => $roomGate['needs_confirm']]);
                    exit;
                }

                if ($ciChoice['choice'] === 'ended') {
                    // "Guest already stayed and has left": check in and out together at the booked dates, no extra
                    // charge, one transaction (the balance rule still applies). Never a check-in email for an ended stay.
                    $recUserName = $user['full_name'] ?? ($user['username'] ?? null);
                    $rec = rh_record_completed_stay($pdo, (int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkout_with_balance']));
                    if (!$rec['success']) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $rec['message'] !== '' ? $rec['message'] : 'Nothing was changed.', 'needs_confirm_balance' => $rec['needs_confirm']]);
                        exit;
                    }
                    $recRef = (string)($booking['booking_reference'] ?? '');
                    $recIn = rh_late_checkin_note($ciWindow, 'ended') . ' Clean-room check skipped: the stay had already ended, nobody moved into the room.';
                    $recOut = 'Completed stay: guest left on the booked date ' . rh_late_stay_fmt($ciWindow['check_out'])
                        . ', no extra nights charged (booked ' . rh_late_stay_fmt($ciWindow['check_in']) . ' to ' . rh_late_stay_fmt($ciWindow['check_out']) . ').';
                    // Housekeeping followed the checkout rule (rh_completed_stay_room_turnover): say what happened per room.
                    $recRoomNote = rh_completed_stay_room_note((array)($rec['rooms'] ?? []));
                    if ($recRoomNote !== '') {
                        $recOut .= ' ' . $recRoomNote;
                    }
                    if ($checkin_note !== '') {
                        $recIn .= ' Check-in note: ' . $checkin_note;
                    }
                    logBookingCheckIn($booking_id, $recRef, 'admin', $user['id'] ?? null, $recUserName);
                    logBookingEvent((int)$booking_id, $recRef, 'Late check-in: completed stay recorded', 'check_in', $recIn, 'confirmed', 'checked-out', 'admin', $user['id'] ?? null, $recUserName,
                        ['arrival_choice' => 'ended', 'actual_arrival' => $ciWindow['check_in'], 'leave_date' => $ciWindow['check_out'], 'late_days' => $ciWindow['late_days'], 'check_in_email' => 'not sent (stay ended)', 'clean_room_check' => 'skipped (stay ended)']);
                    if ($roomGate['override']) {
                        logCheckInRoomNotReadyOverride($booking_id, $recRef, $roomGate['rooms'], $user['id'] ?? null, $recUserName);
                    }
                    if (!empty($validation['via_arrangement'])) {
                        logCheckInUnderArrangement($booking_id, $recRef, (string)$validation['arrangement_note'], $user['id'] ?? null, $recUserName);
                    }
                    logBookingAudit($booking_id, 'checked-in', ['status' => 'confirmed'],
                        ['status' => 'checked-in', 'arrival_choice' => 'ended', 'actual_arrival' => $ciWindow['check_in'], 'check_in_email' => 'not sent (stay ended)'], $recIn, $recRef);
                    logBookingCheckOut($booking_id, $recRef, 'admin', $user['id'] ?? null, $recUserName);
                    logBookingAudit($booking_id, 'checked-out', ['status' => 'checked-in'],
                        ['status' => 'checked-out', 'settlement' => 'completed_stay', 'leave_date' => $ciWindow['check_out'], 'extra_nights' => 0, 'amount' => 0], $recOut, $recRef);
                    if ($rec['override']) {
                        logCheckoutBalanceOverride((int)$booking_id, $recRef, (float)$rec['balance'], (int)($user['id'] ?? 0), $recUserName);
                    }
                    rh_log_event('bookings', 'warning', 'Late check-in: completed stay recorded (checked in and out)', [
                        'booking_id' => $booking_id, 'ref' => $recRef, 'arrival_choice' => 'ended', 'booked_check_in' => $ciWindow['check_in'],
                        'leave_date' => $ciWindow['check_out'], 'late_days' => $ciWindow['late_days'], 'extra_nights' => 0, 'rooms' => $rec['rooms'] ?? [], 'clean_room_check' => 'skipped (stay ended)', 'by' => $user['username'] ?? null,
                    ]);
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Completed stay recorded for ' . $recRef . ': checked in and out at the booked dates, no extra nights charged. No check-in email sent (the stay has ended).'
                        . bookings_final_invoice_note((int)$booking_id, (int)($user['id'] ?? 0))]);
                    exit;
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ?");
                $stmt->execute([$booking_id]);

                updateBookingRoomsStatus(
                    $booking_id,
                    'occupied',
                    'Guest checked in: ' . ($booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                    $user['id'] ?? null
                );
                $pdo->commit();

                // Timeline event (outside transaction — non-fatal if it fails)
                logBookingCheckIn($booking_id, $booking['booking_reference'] ?? '', 'admin', $user['id'] ?? null, $user['full_name'] ?? null);
                if ($roomGate['override']) {
                    logCheckInRoomNotReadyOverride($booking_id, (string)($booking['booking_reference'] ?? ''), $roomGate['rooms'], $user['id'] ?? null, $user['full_name'] ?? null);
                }
                if (!empty($validation['via_arrangement'])) {
                    logCheckInUnderArrangement($booking_id, (string)($booking['booking_reference'] ?? ''), (string)$validation['arrangement_note'], $user['id'] ?? null, $user['full_name'] ?? null);
                }

                $scheduledCheckIn = new DateTime((string)($booking['check_in_date'] ?? 'today'));
                $scheduledCheckIn->setTime(0, 0, 0);
                $todayCheckIn = new DateTime('today');
                $isLateCheckIn = $scheduledCheckIn < $todayCheckIn;
                $lateDays = $isLateCheckIn ? (int)$scheduledCheckIn->diff($todayCheckIn)->days : 0;

                $message = 'Guest checked in!';
                $checkInEventContext = [
                    'booking_id' => $booking_id,
                    'ref' => $booking['booking_reference'] ?? null,
                    'scheduled_check_in' => $booking['check_in_date'] ?? null,
                    'is_late_checkin' => $isLateCheckIn,
                    'days_late' => $lateDays,
                    'by' => $user['username'] ?? null,
                ];
                $ciActualArrival = $ciChoice['choice'] !== '' ? rh_late_checkin_arrival($ciWindow, $ciChoice['choice']) : null;
                if ($ciChoice['choice'] !== '') {
                    $checkInEventContext['arrival_choice'] = $ciChoice['choice'];
                    $checkInEventContext['actual_arrival'] = $ciActualArrival;
                }
                if ($checkin_note !== '') {
                    $checkInEventContext['note'] = $checkin_note;
                }
                rh_log_event(
                    'bookings',
                    $isLateCheckIn ? 'warning' : 'info',
                    $isLateCheckIn ? 'Late guest check-in completed' : 'Guest checked in',
                    $checkInEventContext
                );

                $checkInAuditNote = $checkin_note !== '' ? ('Check-in note: ' . $checkin_note) : null;
                if ($isLateCheckIn && $lateDays > 0) {
                    // The chosen option and the actual arrival date go in the note (History) and in new_values
                    // (actual_arrival is what the checkout settlement reads back for "nights actually stayed").
                    $lateDescriptor = $ciChoice['choice'] !== ''
                        ? rh_late_checkin_note($ciWindow, $ciChoice['choice'])
                        : 'Late check-in (' . $lateDays . ' day(s) overdue)';
                    $checkInAuditNote = $checkInAuditNote !== null
                        ? ($lateDescriptor . ' - ' . $checkInAuditNote)
                        : $lateDescriptor;
                    if ($ciChoice['choice'] !== '') {
                        logBookingEvent((int)$booking_id, (string)($booking['booking_reference'] ?? ''), 'Late check-in', 'check_in', $lateDescriptor,
                            (string)$booking['check_in_date'], (string)$ciActualArrival, 'admin', $user['id'] ?? null, $user['full_name'] ?? null,
                            ['arrival_choice' => $ciChoice['choice'], 'actual_arrival' => $ciActualArrival, 'late_days' => $lateDays]);
                    }
                }
                $ciAuditNew = ['status' => 'checked-in'];
                if ($ciChoice['choice'] !== '') {
                    $ciAuditNew['arrival_choice'] = $ciChoice['choice'];
                    $ciAuditNew['actual_arrival'] = $ciActualArrival;
                }
                logBookingAudit(
                    $booking_id,
                    'checked-in',
                    ['status' => 'confirmed'],
                    $ciAuditNew,
                    $checkInAuditNote,
                    $booking['booking_reference'] ?? null
                );

                // "Email the guest a check-in confirmation" box (owner 2026-11-12). No field (old cached page, the
                // Assistant's check-in) = today's behaviour here: no email. Never blocks the check-in.
                if (array_key_exists('email_guest', $_POST)) {
                    require_once __DIR__ . '/../config/email.php';
                    $ciEmailWanted = rh_checkin_email_choice($_POST, false);
                    $ciEmailSent = rh_checkin_guest_email($pdo, (int)$booking_id, $ciEmailWanted, isset($user['id']) ? (int)$user['id'] : null, $user['full_name'] ?? null);
                    $message .= $ciEmailWanted ? ($ciEmailSent ? ' Check-in email sent.' : ' The check-in email could not be sent.') : ' No email sent (you chose not to).';
                }
            } elseif ($new_status === 'cancel-checkin') {
                $check = $pdo->prepare("SELECT id, status, booking_reference FROM bookings WHERE id = ?");
                $check->execute([$booking_id]);
                $booking = $check->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new Exception('Booking not found');
                }

                if ($booking['status'] !== 'checked-in') {
                    throw new Exception('Can only cancel check-in for a booking that is currently checked in (current status: ' . ($booking['status'] ?? 'unknown') . ')');
                }

                $pdo->beginTransaction();
                // A guest with an open tab / room-service order is in the house: the order must be closed first.
                $pdo->prepare("SELECT id FROM bookings WHERE id = ? FOR UPDATE")->execute([$booking_id]);
                $openOrderRef = rh_booking_open_order_ref($pdo, (int)$booking_id);
                if ($openOrderRef !== null) {
                    $pdo->rollBack();
                    throw new Exception('Order ' . $openOrderRef . ' is still open - close it in Orders & Tabs first.');
                }
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                $stmt->execute([$booking_id]);

                updateBookingRoomsStatus(
                    $booking_id,
                    'available',
                    'Check-in cancelled: ' . ($booking['booking_reference'] ?? ('Booking #' . $booking_id)),
                    $user['id'] ?? null
                );
                $pdo->commit();

                $message = 'Check-in cancelled (reverted to confirmed).';
                rh_log_event('bookings', 'info', 'Check-in cancelled', ['booking_id' => $booking_id, 'by' => $user['username'] ?? null]);
                logBookingAudit($booking_id, 'check-in cancelled', ['status' => 'checked-in'], ['status' => 'confirmed'], null, $booking['booking_reference'] ?? null);
            } else {
                $allowed = ['pending', 'confirmed', 'checked-out', 'cancelled'];
                if (!in_array($new_status, $allowed, true)) {
                    throw new Exception('Invalid status');
                }

                // Get current booking status and room_id before updating
                $check_stmt = $pdo->prepare("SELECT status, payment_status, room_id, individual_room_id, booking_reference, check_in_date, check_out_date FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $current_booking = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$current_booking) {
                    throw new Exception('Booking not found');
                }

                $current_status = $current_booking['status'];
                $room_id = $current_booking['room_id'];
                $cancelledNow = []; // bookings cancelled by this request: [id, previous_status]

                // Validate status transition using helper function
                $transitionValidation = validateBookingStatusTransition($current_status, $new_status);
                if (!$transitionValidation['allowed']) {
                    throw new Exception($transitionValidation['reason']);
                }

                if ($new_status === 'cancelled') {
                    // Cancellation: status change + room/stock release + bill treatment +
                    // refund all commit together (one transaction, booking row locked).
                    $cancellation_reason = $_POST['cancellation_reason'] ?? 'Cancelled by admin';
                    // Group booking (owner 2026-10-20): the dialog asks each time. scope=group cancels every live
                    // room of the group in ONE transaction (all go or none); scope=single (or none) is one room.
                    $cancelGroupIds = ($_POST['scope'] ?? '') === 'group' ? rh_booking_group_ids($pdo, (int)$booking_id) : [];
                    if (count($cancelGroupIds) > 1) {
                        $grpRes = rh_cancel_booking_group($pdo, $cancelGroupIds, (int)($user['id'] ?? 0), (string)$cancellation_reason);
                        if (!$grpRes['success']) {
                            throw new Exception('Cancellation failed - nothing was changed. ' . $grpRes['error']);
                        }
                        $message = $grpRes['message'];
                        foreach ($grpRes['cancelled'] as $gc) {
                            $cancelledNow[] = ['id' => $gc['id'], 'previous_status' => $gc['previous_status']];
                            rh_log_event('bookings', 'info', 'Booking status changed (group cancel)', ['booking_id' => $gc['id'], 'ref' => $gc['reference'], 'to' => $new_status, 'group' => $cancelGroupIds, 'by' => $user['username'] ?? null]);
                            logBookingAudit($gc['id'], $new_status, ['status' => $gc['previous_status']], ['status' => $new_status], 'Cancelled with its group (' . count($cancelGroupIds) . ' rooms)', $gc['reference']);
                        }
                    } else {
                        $cancelRes = cancelRoomBookingSettled($pdo, (int)$booking_id, (int)($user['id'] ?? 0), (string)$cancellation_reason);
                        if (!$cancelRes['success']) {
                            throw new Exception('Cancellation failed - nothing was changed. ' . $cancelRes['error']);
                        }
                        $cancelledNow[] = ['id' => (int)$booking_id, 'previous_status' => (string)$current_status];
                        $message = 'Booking cancelled. ' . $cancelRes['summary'];
                        rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => $new_status, 'mode' => $cancelRes['mode'], 'refund' => $cancelRes['refund_total'], 'by' => $user['username'] ?? null]);
                        logBookingAudit($booking_id, $new_status, ['status' => $current_status], ['status' => $new_status], null, $current_booking['booking_reference'] ?? null);
                    }
                } elseif ($new_status === 'checked-out') {
                    // Checkout always passes the balance gate (override needs the permission + explicit confirm).
                    $coRes = rh_checkout_with_gate($pdo, (int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkout_with_balance']), $user['full_name'] ?? ($user['username'] ?? null));
                    if (!$coRes['success']) {
                        throw new Exception($coRes['message'] !== '' ? $coRes['message'] : 'Checkout failed - nothing was changed.');
                    }
                    $message = 'Booking status updated!';
                    rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => $new_status, 'by' => $user['username'] ?? null]);
                    logBookingAudit($booking_id, $new_status, ['status' => $current_status], ['status' => $new_status], null, $current_booking['booking_reference'] ?? null);
                } else {
                // Update booking status; if leaving tentative, clear tentative fields so badge counts stay accurate
                $clear_tentative_flag = ($new_status !== 'tentative') ? ', is_tentative = 0, tentative_expires_at = NULL' : '';
                $confirmRoomUpdated = null;
                if (in_array($current_status, ['pending', 'tentative'], true) && $new_status === 'confirmed') {
                    // Confirming a hold takes a room out of stock: one transaction that locks the booking row and
                    // every room type sharing its rooms (read BEFORE the transaction, locked in ascending id),
                    // re-checks availability, and only then changes the status and the room counter. Two people
                    // confirming at once, or confirming while someone books, can never both claim the last room.
                    $confirmLockIds = rh_related_room_type_ids($pdo, [(int)$room_id]);
                    $pdo->beginTransaction();
                    try {
                        $lockBk = $pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
                        $lockBk->execute([$booking_id]);
                        $lockedStatus = $lockBk->fetchColumn();
                        if (!in_array($lockedStatus, ['pending', 'tentative'], true)) {
                            throw new Exception('This booking was changed by someone else a moment ago. Refresh the page and check its status.');
                        }
                        rh_lock_room_types($pdo, $confirmLockIds);
                        $availabilityCheck = checkRoomAvailability($room_id, $current_booking['check_in_date'], $current_booking['check_out_date'], $booking_id);
                        if (!$availabilityCheck['available']) {
                            throw new Exception(getBookingActionErrorMessage('confirm', $availabilityCheck['error'] ?? 'No rooms available'));
                        }
                        $pdo->prepare("UPDATE bookings SET status = ?{$clear_tentative_flag} WHERE id = ?")->execute([$new_status, $booking_id]);
                        $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0");
                        $update_room->execute([$room_id]);
                        $confirmRoomUpdated = $update_room->rowCount() > 0;
                        $pdo->commit();
                    } catch (Throwable $confirmEx) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw rh_is_lock_conflict($confirmEx) ? new Exception(rh_lock_conflict_message()) : $confirmEx;
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = ?{$clear_tentative_flag} WHERE id = ?");
                    $stmt->execute([$new_status, $booking_id]);
                }
                $message = 'Booking status updated!';
                rh_log_event('bookings', 'info', 'Booking status changed', ['booking_id' => $booking_id, 'from' => $current_status, 'to' => $new_status, 'by' => $user['username'] ?? null]);
                logBookingAudit($booking_id, $new_status, ['status' => $current_status], ['status' => $new_status], null, $current_booking['booking_reference'] ?? null);
                }

                // Handle room availability changes
                if (in_array($current_status, ['pending', 'tentative'], true) && $new_status === 'confirmed') {
                    // (Availability re-check, status change and room counter were done in the locked transaction above.)
                    if (!$confirmRoomUpdated) {
                        // This shouldn't happen if availability checks are working, but handle it
                        $message .= ' (Warning: Could not update room availability - room may be fully booked)';
                    } else {
                        $message .= ' Room availability updated.';
                    }

                    // Send booking confirmed email
                    $booking_stmt = $pdo->prepare("
                        SELECT b.*, r.name as room_name
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        WHERE b.id = ?
                    ");
                    $booking_stmt->execute([$booking_id]);
                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($booking) {
                        // Include email functions
                        require_once '../config/email.php';

                        // Send booking confirmed email
                        $email_result = sendBookingConfirmedEmail($booking);

                        if ($email_result['success']) {
                            $message .= ' Confirmation email sent to guest.';
                        } else {
                            $message .= ' (Note: Confirmation email failed: ' . $email_result['message'] . ')';
                        }

                        // Auto-assign individual room only once the booking is confirmed and paid.
                        if (in_array($booking['payment_status'] ?? '', ['paid', 'completed'], true) && empty($booking['individual_room_id'])) {
                            $autoAssignResult = autoAssignConfirmedPaidBooking($booking_id);
                            if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                                $message .= ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                            } else {
                                $message .= ' (Note: Auto-assign skipped - ' . htmlspecialchars($autoAssignResult['message']) . ')';
                            }
                        }
                    }
                } elseif ($new_status === 'cancelled' && !empty($cancelledNow)) {
                    // (Stock release, room release and refund were done atomically by cancelRoomBookingSettled above.)
                    // Every booking cancelled by this request that was confirmed gets the guest email + the log rows.
                    require_once '../config/email.php';
                    $cancellation_reason = $_POST['cancellation_reason'] ?? 'Cancelled by admin';
                    foreach ($cancelledNow as $cn) {
                        if ($cn['previous_status'] !== 'confirmed') {
                            continue;
                        }
                        $booking_stmt = $pdo->prepare("
                            SELECT b.*, r.name as room_name
                            FROM bookings b
                            LEFT JOIN rooms r ON b.room_id = r.id
                            WHERE b.id = ?
                        ");
                        $booking_stmt->execute([$cn['id']]);
                        $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$booking) {
                            continue;
                        }
                        // Owner 2026-11-12: staff may untick "Email the guest" to cancel quietly (no email, no WhatsApp).
                        // A form without the field (page loaded before this change) keeps the old behaviour.
                        $cancelEmailSkipped = !rh_cancel_email_choice($_POST, rh_cancel_email_default_ticked('list', (string)$cn['previous_status']));
                        if ($cancelEmailSkipped) {
                            $email_result = ['success' => false, 'message' => rh_cancel_email_skipped_reason()];
                            rh_email_send_log_not_sent('booking_cancelled', (string)$booking['guest_email'], (string)$booking['guest_name'],
                                rh_email_ctx_account('room', $booking['id'], $booking['booking_reference']), 'Staff chose not to email the guest');
                            if (rh_cancel_email_default_ticked('list', (string)$cn['previous_status'])) {
                                sendBookingCancelledHotelAlert($booking); // unticked: nothing to the guest; the hotel's own WhatsApp alert still goes
                            }
                        } else {
                            // Send cancellation email
                            $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                        }

                        // Log cancellation to database
                        $email_sent = $email_result['success'];
                        $email_status = $email_result['message'];
                        logCancellationToDatabase(
                            $booking['id'],
                            $booking['booking_reference'],
                            'room',
                            $booking['guest_email'],
                            $user['id'],
                            $cancellation_reason,
                            $email_sent,
                            $email_status
                        );

                        // Log cancellation to file
                        logCancellationToFile(
                            $booking['booking_reference'],
                            'room',
                            $booking['guest_email'],
                            $user['full_name'] ?? $user['username'],
                            $cancellation_reason,
                            $email_sent,
                            $email_status
                        );

                        $refTag = count($cancelledNow) > 1 ? (' [' . $booking['booking_reference'] . ']') : '';
                        if ($cancelEmailSkipped) {
                            $message .= ' No email sent' . $refTag . ' (you chose not to).';
                        } elseif ($email_sent) {
                            $message .= ' Cancellation email sent' . $refTag . '.';
                        } else {
                            $message .= ' (Email failed' . $refTag . ': ' . $email_status . ')';
                        }
                    }
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        } elseif ($action === 'modify_booking') {
            // Comprehensive modify-booking handler used by the Modify modal.
            $actorId = (int)($user['id'] ?? 0);
            if ($actorId <= 0 || !hasPermission($actorId, 'edit_booking') || !hasPermission($actorId, 'quick_modify_booking')) {
                throw new Exception('You do not have permission to quick modify bookings.');
            }

            $canEditBookingFinancials = hasPermission($actorId, 'edit_booking_financials');
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) throw new Exception('Invalid booking id');

            $cur = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $cur->execute([$booking_id]);
            $current = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new Exception('Booking not found');

            // Only fields the modal allows. Whitelist + sanitize.
            $allowed = [
                'guest_name'        => 'string',
                'guest_email'       => 'string',
                'guest_phone'       => 'string',
                'guest_country'     => 'string',
                'check_in_date'     => 'date',
                'check_out_date'    => 'date',
                'number_of_guests'  => 'int',
                'adult_guests'      => 'int',
                'child_guests'      => 'int',
                'special_requests'  => 'string',
                'individual_room_id' => 'int',
            ];
            // Status, payment status and price are NOT quick-modify fields: status moves through the
            // dedicated actions (confirm / check-in / checkout / cancel / no-show), payment status is
            // always derived from the payments ledger, and price corrections go through a credit note.
            if (!in_array((string)$current['status'], ['pending', 'tentative', 'confirmed', 'checked-in'], true)) {
                throw new Exception('This booking is ' . $current['status'] . '. Use the full edit page to correct guest details.');
            }
            $updates = [];
            $newValues = [];
            foreach ($allowed as $field => $type) {
                if (!array_key_exists($field, $_POST)) continue;
                $raw = $_POST[$field];
                if ($raw === '' && in_array($type, ['int', 'float'], true)) continue;
                switch ($type) {
                    case 'int':
                        $val = (int)$raw;
                        break;
                    case 'float':
                        $val = (float)$raw;
                        break;
                    case 'date':
                        $val = $raw ?: null;
                        if ($val && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                            throw new Exception("Invalid date for $field");
                        }
                        break;
                    default:
                        $val = trim((string)$raw);
                }
                $updates[$field] = $val;
                $newValues[$field] = $val;
            }

            if (!empty($updates['check_in_date']) && !empty($updates['check_out_date'])) {
                if (strtotime($updates['check_out_date']) <= strtotime($updates['check_in_date'])) {
                    throw new Exception('Check-out date must be after check-in date.');
                }
            }
            // Children change the price (child supplement): that repricing lives on the full edit page.
            if (isset($updates['child_guests']) && (int)$updates['child_guests'] !== (int)($current['child_guests'] ?? 0)) {
                throw new Exception('Changing the number of children changes the price. Use the full edit page for that.');
            }
            // In-house: the stay length goes through Extend stay / Adjust dates (audited, booked rate).
            if ((string)$current['status'] === 'checked-in'
                && ((isset($updates['check_in_date']) && $updates['check_in_date'] !== $current['check_in_date'])
                    || (isset($updates['check_out_date']) && $updates['check_out_date'] !== $current['check_out_date']))) {
                throw new Exception('The guest is in-house: use Extend stay or Adjust stay dates to change their dates.');
            }
            if (isset($updates['check_in_date']) && $updates['check_in_date'] !== $current['check_in_date'] && $updates['check_in_date'] < date('Y-m-d')) {
                throw new Exception('The check-in date cannot be moved into the past.');
            }

            // Recompute number_of_nights when dates changed
            if (isset($updates['check_in_date']) || isset($updates['check_out_date'])) {
                $ci = $updates['check_in_date']  ?? $current['check_in_date'];
                $co = $updates['check_out_date'] ?? $current['check_out_date'];
                $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
                $updates['number_of_nights'] = $nights;
                $newValues['number_of_nights'] = $nights;
            }

            // Remove amount_paid and amount_due from the direct update — they are always
            // recomputed from the payments ledger by recalculateBookingFinancials() below,
            // so never trust client-supplied values for these fields.
            unset($updates['amount_paid'], $updates['amount_due'], $newValues['amount_paid'], $newValues['amount_due']);

            if (empty($updates)) {
                throw new Exception('No fields to update.');
            }
            $mbDatesChanged = is_array($updates) && (
                (isset($updates['check_in_date']) && $updates['check_in_date'] !== $current['check_in_date'])
                || (isset($updates['check_out_date']) && $updates['check_out_date'] !== $current['check_out_date'])
            );

            // Build & run UPDATE (inside a transaction with the booking row locked so the
            // reprice below reads current figures)
            $pdo->beginTransaction();
            $lockMb = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $lockMb->execute([$booking_id]);
            $current = $lockMb->fetch(PDO::FETCH_ASSOC) ?: $current;

            // Availability is checked UNDER the same room-row lock the create paths take, so a
            // concurrent booking cannot slip into the new dates between check and write.
            if (is_array($updates) && isset($updates['individual_room_id']) && (int)$updates['individual_room_id'] <= 0) {
                unset($updates['individual_room_id'], $newValues['individual_room_id']);
            }
            $mbIndChanged = is_array($updates) && isset($updates['individual_room_id'])
                && (int)$updates['individual_room_id'] > 0
                && (int)$updates['individual_room_id'] !== (int)($current['individual_room_id'] ?? 0);
            if ($mbDatesChanged || $mbIndChanged) {
                $mbIn  = $updates['check_in_date']  ?? $current['check_in_date'];
                $mbOut = $updates['check_out_date'] ?? $current['check_out_date'];
                // Shared stay-change gate: booking / room-type / room locks, then only the NEW
                // nights are re-checked at type level and for every assigned (or newly chosen) room.
                $mbGate = rh_validate_booking_stay_change($booking_id, $mbIn, $mbOut, null, $mbIndChanged ? (int)$updates['individual_room_id'] : null);
                if (!$mbGate['ok']) {
                    throw new Exception($mbGate['error']);
                }
            }
            $sets = [];
            $vals = [];
            foreach ($updates as $f => $v) {
                $sets[] = "$f = ?";
                $vals[] = $v;
            }
            $sets[] = "updated_at = NOW()";
            $vals[] = $booking_id;
            $stmt = $pdo->prepare("UPDATE bookings SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($vals);

            // A newly chosen room replaces the old hold (and any joined-room pairing).
            if ($mbIndChanged) {
                syncBookingRooms($booking_id, [(int)$updates['individual_room_id']], null);
                $pdo->prepare("UPDATE bookings SET room_combination_id = NULL WHERE id = ?")->execute([$booking_id]);
            }

            // Date change: reprice at the BOOKED nightly rate (RP-3: minus a length-of-stay / weekend / seasonal
            // discount the new dates no longer qualify for).
            $mbDrop = ['removed' => false, 'per_night' => 0.0];
            if ($mbDatesChanged && isset($updates['number_of_nights'])) {
                $mbDrop = rh_rate_plan_dropped($pdo, $current, (string)($updates['check_in_date'] ?? $current['check_in_date']),
                    (string)($updates['check_out_date'] ?? $current['check_out_date']), (int)$updates['number_of_nights'], (int)$current['room_id']);
                $mbRp = rh_reprice_at_booked_rate($current, (int)$updates['number_of_nights'], (float)$mbDrop['per_night']);
                rh_write_booking_stay_totals($pdo, $booking_id, $mbRp['totals'], null, null, null, $mbRp['new_child_supplement']);
                if (!empty($mbDrop['removed'])) {
                    rh_rate_plan_drop_clear($pdo, $booking_id);
                }
            }

            // Recompute financials from the payments ledger to keep amount_paid / amount_due accurate
            recalculateBookingFinancials($booking_id);
            $pdo->commit();

            // Audit
            $oldSubset = array_intersect_key($current, $newValues);
            logBookingAudit($booking_id, 'modified', $oldSubset, $newValues, $_POST['note'] ?? null, $current['booking_reference'] ?? null);
            rh_log_event('bookings', 'info', 'Booking modified', ['booking_id' => $booking_id, 'fields' => array_keys($newValues), 'by' => $user['username'] ?? null]);
            if (!empty($mbDrop['removed'])) {
                rh_rate_plan_drop_log($booking_id, $current['booking_reference'] ?? null, $mbDrop, (float)rh_booked_stay_split($current)['per_night'],
                    (float)$mbRp['per_night'], (float)$mbRp['old_gross'], (float)$mbRp['new_gross'], (string)($user['full_name'] ?? ($user['username'] ?? '')));
            }

            $message = 'Booking updated successfully.';
            if (isset($mbRp)) {
                $mbLine = rh_booked_rate_change_for_booking($current, $mbRp, (int)$updates['number_of_nights'], ['currency' => $currency_symbol]);
                if ($mbLine['ok']) {
                    $message .= (!empty($mbDrop['removed']) ? ' Room: ' : ' Room at the booked rate: ') . $mbLine['text'] . '.';
                }
                if (!empty($mbDrop['removed'])) {
                    $message .= ' ' . rh_rate_plan_drop_sentence($mbDrop);
                }
            }

            // Send guest modification email when meaningful fields changed
            $notifyFields = ['check_in_date', 'check_out_date', 'number_of_guests', 'adult_guests', 'child_guests',
                             'room_id', 'total_amount', 'status', 'special_requests', 'guest_email', 'guest_name', 'guest_phone'];
            $meaningfulChanges = array_intersect_key($newValues, array_flip($notifyFields));
            if (!empty($meaningfulChanges)) {
                try {
                    require_once '../config/email.php';
                    $modStmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
                    $modStmt->execute([$booking_id]);
                    $modBooking = $modStmt->fetch(PDO::FETCH_ASSOC);
                    if ($modBooking) {
                        $modEmailResult = sendBookingModifiedEmail($modBooking, $meaningfulChanges);
                        if ($modEmailResult['success']) {
                            $message .= ' Guest notified by email.';
                        } else {
                            error_log('Quick-modify guest email failed for booking ' . $booking_id . ': ' . $modEmailResult['message']);
                        }
                    }
                } catch (Throwable $modEmailEx) {
                    error_log('Quick-modify guest email exception for booking ' . $booking_id . ': ' . $modEmailEx->getMessage());
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
            }
        } elseif ($action === 'get_booking_for_modify') {
            $actorId = (int)($user['id'] ?? 0);
            if ($actorId <= 0 || !hasPermission($actorId, 'edit_booking') || !hasPermission($actorId, 'quick_modify_booking')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'You do not have permission to quick modify bookings.']);
                exit;
            }

            $bid = (int)($_POST['booking_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT b.*, r.name AS room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
            $stmt->execute([$bid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'Not found']);
            exit;
        } elseif ($action === 'get_booking_audit_log') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            $payload = [
                'booking' => null,
                'audit_logs' => function_exists('getBookingAuditLog') ? getBookingAuditLog($bid, 120) : [],
                'payments' => [],
                'tentative_log' => [],
            ];

            if ($bid > 0) {
                $booking_stmt = $pdo->prepare("\n                    SELECT b.id,
                           b.booking_reference,
                           b.guest_name,
                           b.guest_email,
                           b.guest_phone,
                           b.status,
                           b.payment_status,
                           b.check_in_date,
                           b.check_out_date,
                           b.number_of_nights,
                           b.total_amount, b.total_with_vat, b.vat_amount,
                           b.amount_paid,
                           b.amount_due,
                           b.created_at,
                           b.updated_at,
                           r.name AS room_type_name,
                           ir.room_number AS room_number
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                    WHERE b.id = ?");
                $booking_stmt->execute([$bid]);
                $payload['booking'] = $booking_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($payload['booking']) { $payload['booking']['total_full'] = rh_booking_full_total($payload['booking']); }

                $payments_stmt = $pdo->prepare("\n                    SELECT p.id,
                           p.payment_reference,
                           p.payment_date,
                           p.payment_method,
                           p.payment_type,
                           p.payment_status,
                           p.payment_amount,
                           p.total_amount,
                           p.vat_amount,
                           p.refund_amount,
                           p.refund_reason,
                           p.notes,
                           p.recorded_by,
                           p.created_at,
                           COALESCE(au.full_name, au.username) AS recorded_by_name
                    FROM payments p
                    LEFT JOIN admin_users au ON au.id = p.recorded_by
                    WHERE p.booking_type = 'room'
                      AND p.booking_id = ?
                      AND p.deleted_at IS NULL
                    ORDER BY COALESCE(p.created_at, CONCAT(p.payment_date, ' 00:00:00')) DESC, p.id DESC
                    LIMIT 200");
                $payments_stmt->execute([$bid]);
                $payload['payments'] = $payments_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if (function_exists('auditTableExists') && auditTableExists($pdo, 'tentative_booking_log')) {
                    $tentative_stmt = $pdo->prepare("\n                        SELECT tbl.id,
                               tbl.action,
                               tbl.action_reason,
                               tbl.previous_expires_at,
                               tbl.new_expires_at,
                               tbl.performed_by,
                               tbl.created_at,
                               COALESCE(au.full_name, au.username) AS performed_by_name
                        FROM tentative_booking_log tbl
                        LEFT JOIN admin_users au ON au.id = tbl.performed_by
                        WHERE tbl.booking_id = ?
                        ORDER BY tbl.created_at DESC, tbl.id DESC
                        LIMIT 120");
                    $tentative_stmt->execute([$bid]);
                    $payload['tentative_log'] = $tentative_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $payload]);
            exit;
        } elseif ($action === 'send_invoice_email') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            if ($bid <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
                exit;
            }
            require_once '../config/invoice.php';
            $cc_recipients = [];
            $invoice_recipients = getEmailSetting('invoice_recipients', '');
            $smtp_username     = getEmailSetting('smtp_username', '');
            if (!empty($invoice_recipients)) {
                $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
            }
            if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
                $cc_recipients[] = $smtp_username;
            }
            $result = sendPaymentInvoiceEmailWithCC($bid, $cc_recipients);
            if ($result['success']) {
                logBookingAudit($bid, 'email_sent', null, null, 'Invoice email sent by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')');
            }
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        } elseif ($action === 'find_payment_for_refund') {
            $bid = (int)($_POST['booking_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, payment_reference, total_amount, payment_status, payment_type FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND (deleted_at IS NULL)
                  AND payment_status IN ('completed','paid')
                  AND COALESCE(payment_type, '') <> 'refund'
                ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$bid]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($p ? ['success' => true, 'payment' => $p] : ['success' => false, 'message' => 'No refundable payment found for this booking.']);
            exit;
        } elseif ($action === 'update_payment') {
            // payment_status is DERIVED from real payments — the form value is only a
            // request ('paid' = settle the outstanding balance); never written raw.
            $payment_status = (string)($_POST['payment_status'] ?? '');
            if (!in_array($payment_status, ['unpaid', 'partial', 'paid'], true)) {
                throw new Exception('Invalid payment status.');
            }
            $booking_id = (int)($_POST['id'] ?? 0);

            // Get previous payment status and booking details
            $check = $pdo->prepare("SELECT status, payment_status, individual_room_id, total_amount, booking_reference FROM bookings WHERE id = ?");
            $check->execute([$booking_id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Booking not found');
            }

            $previous_status = $row['payment_status'] ?? 'unpaid';
            $booking_reference = $row['booking_reference'];

            // Lock the booking, re-derive its financials, then size any settlement
            // from the live folio balance (room + VAT + folio charges - net paid).
            $pdo->beginTransaction();
            try {
                $pdo->prepare("SELECT id FROM bookings WHERE id = ? FOR UPDATE")->execute([$booking_id]);
                if (!recalculateBookingFinancials((int)$booking_id)) {
                    throw new RuntimeException('Could not recalculate the booking balance - nothing was changed.');
                }
                $settleSummary = getBookingFolioSummary((int)$booking_id);
                $settleAmount = round((float)($settleSummary['balance_due'] ?? 0), 2);

                $vatEnabledSettle = rh_vat_enabled();
                $vatRate = $vatEnabledSettle ? (float)getSetting('vat_rate') : 0.0;
                // Same split as admin/payment-add.php: amount received is GROSS,
                // payment_amount is the NET portion, vat_amount the extracted VAT.
                $vatAmount = $vatRate > 0 ? round($settleAmount * ($vatRate / (100 + $vatRate)), 2) : 0.0;
                $netSettle = round($settleAmount - $vatAmount, 2);
                // Prefer the account's own VAT ratio (levy / zero-rated lines carry no VAT).
                if (!function_exists('rh_account_vat_split') && is_file(__DIR__ . '/includes/finance-account-sync.php')) {
                    require_once __DIR__ . '/includes/finance-account-sync.php';
                }
                if (function_exists('rh_account_vat_split')) {
                    $acctSplit = rh_account_vat_split($pdo, 'room', (int)$booking_id, $settleAmount, $vatRate);
                    $vatRate = $acctSplit['rate'];
                    $vatAmount = $acctSplit['vat'];
                    $netSettle = $acctSplit['net'];
                }
                $totalWithVat = $settleAmount;

                $message = 'Payment status updated!';
                $settleCreated = false;

            // If marking as paid, insert a settlement payment for the balance due
            if ($payment_status === 'paid' && $settleAmount > BALANCE_TOLERANCE) {
                // Generate payment reference
                $payment_reference_base = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
                $payment_reference = $payment_reference_base;
                $payment_reference_suffix = 1;
                $paymentReferenceCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
                $paymentReferenceCheck->execute([$payment_reference]);
                while ((int)$paymentReferenceCheck->fetchColumn() > 0) {
                    $payment_reference_suffix++;
                    $payment_reference = $payment_reference_base . '-' . $payment_reference_suffix;
                    $paymentReferenceCheck->execute([$payment_reference]);
                }

                // Settlement = the full outstanding balance (room + VAT + folio charges,
                // less net paid). The balance check above replaces the old "any payment
                // exists" guard, so a retry can never double-record.
                $receipt_number = finance_next_receipt_number($pdo, date('Y-m-d'));
                $insert_payment = $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status, invoice_generated,
                        receipt_number, status, recorded_by
                    ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, ?, 'completed', ?)
                ");
                $insert_payment->execute([
                    $payment_reference,
                    $booking_id,
                    $booking_reference,
                    $netSettle, // payment_amount is always the ex-VAT figure
                    $vatRate,
                    $vatAmount,
                    $totalWithVat,
                    $receipt_number,
                    $user['id']
                ]);
                $settlePayId = (int)$pdo->lastInsertId();
                $settleCreated = true;
            }

            if ($payment_status === 'paid' && !$settleCreated) {
                $message = 'Nothing is owed on this booking. No payment was recorded.';
            }
            // Derive amount_paid / amount_due / payment_status from the payments
            // ledger (total_with_vat — the bill — is never written from here).
            if (!recalculateBookingFinancials((int)$booking_id)) {
                throw new RuntimeException('Could not recalculate the booking balance after the payment - nothing was saved.');
            }
            $pdo->commit();
            } catch (\Throwable $settleErr) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $settleErr;
            }

            logBookingAudit((int)$booking_id, 'payment_updated', ['payment_status' => $previous_status], ['payment_status' => $payment_status], 'Payment status re-derived from payments by admin (' . ($user['full_name'] ?? ($user['username'] ?? '')) . ')', $booking_reference);

            if ($settleCreated) {
                $message .= ' Payment recorded in accounting system.';

                if (($row['status'] ?? '') === 'confirmed' && empty($row['individual_room_id'])) {
                    $autoAssignResult = autoAssignConfirmedPaidBooking((int)$booking_id);
                    if ($autoAssignResult['success'] && !empty($autoAssignResult['assigned_room_number'])) {
                        $message .= ' Room ' . htmlspecialchars($autoAssignResult['assigned_room_number']) . ' auto-assigned.';
                    } elseif (!$autoAssignResult['success']) {
                        $message .= ' (Room auto-assignment skipped: ' . htmlspecialchars($autoAssignResult['message']) . ')';
                    }
                }

                // Money received -> RECEIPT with PDF (not an invoice: an invoice requests payment).
                $bk_pay_id = (int)($settlePayId ?? 0);
                if ($bk_pay_id > 0) {
                    require_once '../config/receipts.php';
                    $message .= receipt_after_payment($pdo, $bk_pay_id, $user)['notice'];
                }
            }
        } elseif ($action === 'checkout') {
            // Checkout a checked-in booking
            $booking_id = intval($_POST['id'] ?? 0);
            if ($booking_id > 0) {
                // Get booking info
                $check_stmt = $pdo->prepare("SELECT id, status, room_id, individual_room_id, booking_reference, check_out_date FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $bk = $check_stmt->fetch(PDO::FETCH_ASSOC);

                // Use validation helper
                $validation = $bk ? validateCheckOut($bk) : ['allowed' => false, 'reason' => 'Booking not found'];

                $balGate = null;
                if ($validation['allowed']) {
                    // Balance gate (server-side): lock, recalculate, block unless settled or
                    // a 'checkout_with_balance' user explicitly confirmed.
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("SELECT id FROM bookings WHERE id = ? FOR UPDATE")->execute([$booking_id]);
                        $balGate = evaluateCheckoutBalance($pdo, (int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkout_with_balance']));
                        if ($balGate['allowed']) {
                            $upd = $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ?");
                            $upd->execute([$booking_id]);

                            // Restore room availability
                            $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                            $restore->execute([$bk['room_id']]);
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
                        throw new Exception('Checkout failed - nothing was changed. ' . $coErr->getMessage());
                    }
                }

                if ($validation['allowed'] && !$balGate['allowed']) {
                    $error = $balGate['message'];
                } elseif ($validation['allowed']) {
                    if ($balGate['override']) {
                        logCheckoutBalanceOverride((int)$booking_id, (string)$bk['booking_reference'], (float)$balGate['balance'], (int)($user['id'] ?? 0), $user['full_name'] ?? ($user['username'] ?? null));
                    }

                    updateBookingRoomsStatus(
                        $booking_id,
                        'cleaning',
                        'Checkout completed: ' . ($bk['booking_reference'] ?? ('Booking #' . $booking_id)),
                        $user['id'] ?? null
                    );

                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' checked out successfully. Room availability restored.'
                        . bookings_final_invoice_note((int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['email_final_invoice']) ? true : null);
                    rh_log_event('bookings', 'info', 'Guest checked out', ['booking_id' => $booking_id, 'ref' => $bk['booking_reference'], 'by' => $user['username'] ?? null]);
                    logBookingAudit($booking_id, 'checked-out', ['status' => 'checked-in'], ['status' => 'checked-out'], null, $bk['booking_reference'] ?? null);
                } else {
                    $error = getBookingActionErrorMessage('check_out', $validation['reason']);
                }
            }
        } elseif ($action === 'checkout_assess') {
            // ── Checkout settlement: ask when the guest left (owner 2026-11-12 "Dont assume, have options") ──
            // Every choice is quoted here by includes/late-stay.php, the SAME function checkout_settle charges /
            // refunds with, so the quote always equals the money written.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }

            $assess_stmt = $pdo->prepare("
                SELECT b.*,
                       r.name AS room_type_name,
                       ir.room_number AS individual_room_number
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ? AND b.status = 'checked-in'
            ");
            $assess_stmt->execute([$booking_id]);
            $abk = $assess_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$abk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            // Live balance + whether this user may check out with it (UI only; the
            // server re-checks in checkout / checkout_settle).
            $assessBal = evaluateCheckoutBalance($pdo, (int)$booking_id, (int)($user['id'] ?? 0), false);
            // evaluateCheckoutBalance() re-derived paid / due: quote from the fresh row, not the cached columns.
            $assess_stmt->execute([$booking_id]);
            $abk = $assess_stmt->fetch(PDO::FETCH_ASSOC) ?: $abk;
            $assessBalFields = ['balance_due' => $assessBal['balance'], 'can_override' => (bool)$assessBal['can_override']];
            // Pay-later booking (Check in before payment): the checkout confirm shows an UNTICKED "Email the final invoice
            // to the guest" box (owner 2026-11-13: a company pays later, the guest must not be chased).
            $assessBalFields['pay_later'] = !empty($abk['checkin_without_payment']);
            $assessBalFields['pay_later_note'] = $assessBalFields['pay_later'] ? trim((string)($abk['checkin_arrangement_note'] ?? '')) : '';
            $assessBalFields['guest_email_on_file'] = trim((string)($abk['guest_email'] ?? '')) !== '';
            // On a company account (owner D2 2026-11-13): balance_due above is the guest's own share only; the company
            // share stays on the account and the final invoice goes to the company billing email (no guest tick box).
            $assessBalFields['company_due'] = (float)($assessBal['company_due'] ?? 0);
            $assessBalFields['company_name'] = (string)($assessBal['company_name'] ?? '');
            if ($assessBalFields['company_name'] !== '') {
                $assessBalFields['pay_later'] = false;
            }
            // The rate this stay was booked at × its booked nights (shown in the checkout confirm / settlement).
            $assessRate = rh_booked_rate_line($abk, null, ['currency' => $currency_symbol]);
            $assessBalFields['booked_rate_text'] = $assessRate['ok'] ? $assessRate['text'] : '';

            $todayA = date('Y-m-d');
            $schedOutA = substr((string)$abk['check_out_date'], 0, 10);
            if ($schedOutA === $todayA) {
                header('Content-Type: application/json');
                echo json_encode($assessBalFields + [
                    'success'    => true,
                    'settlement' => ['needed' => false, 'type' => 'none'],
                ]);
                exit;
            }

            // Recorded actual arrival ("Guest is arriving today" at a late check-in) -> the "nights actually
            // stayed" basis is offered too (owner decision 2026-11-12, front desk chooses each time).
            $arrivalA = rh_late_stay_actual_arrival($pdo, (int)$booking_id, (string)$abk['check_in_date']);
            $optsA = rh_late_stay_options($abk, $arrivalA, $todayA);
            $quotesA = [];
            foreach ($optsA['dates'] as $ymdA => $rowA) {
                foreach ($rowA as $basisA => $qA) {
                    if (empty($qA['ok'])) {
                        continue;
                    }
                    $lineA = '';
                    if ($qA['reprice'] !== null) {
                        $lnA = rh_booked_rate_change_for_booking($abk, $qA['reprice'], (int)$qA['nights'], ['currency' => $currency_symbol]);
                        $lineA = !empty($lnA['ok']) ? (string)$lnA['text'] : '';
                    }
                    $quotesA[$ymdA][$basisA] = [
                        'direction'     => $qA['direction'],
                        'amount'        => $qA['amount'],
                        'refundable'    => $qA['refundable'],
                        'nights'        => $qA['nights'],
                        'extra_nights'  => $qA['extra_nights'],
                        'unused_nights' => $qA['unused_nights'],
                        'per_night'     => $qA['per_night'],
                        'new_gross'     => $qA['new_gross'],
                        'stay_text'     => rh_late_stay_fmt($qA['new_check_in']) . ' to ' . rh_late_stay_fmt($qA['new_check_out']),
                        'new_rate_text' => $lineA,
                        'describe'      => rh_late_stay_describe($qA, $currency_symbol),
                    ];
                }
            }
            // How an unused-nights refund is paid out, per quote, for THAT quote's refund amount: back to the original
            // payment method(s) the same way the normal Refund records it (allocateBookingRefunds(), the helper
            // checkout_settle refunds with). Read only. Store credit is offered only when the refund setting says "ask".
            foreach ($quotesA as $ymdA => $rowA) {
                foreach ($rowA as $basisA => $qA) {
                    if ($qA['direction'] !== 'refund' || (float)$qA['refundable'] <= BALANCE_TOLERANCE) {
                        continue;
                    }
                    $methodsA = [];
                    foreach (allocateBookingRefunds($pdo, (int)$booking_id, (float)$qA['refundable']) as $allocA) {
                        $methodsA[ucfirst(str_replace('_', ' ', (string)(($allocA['payment']['payment_method'] ?? '') ?: 'other')))] = true;
                    }
                    $quotesA[$ymdA][$basisA]['refund_methods'] = implode(', ', array_keys($methodsA));
                }
            }
            $storeCreditA = rh_refund_rule('refund_method_default') === 'ask';

            header('Content-Type: application/json');
            echo json_encode($assessBalFields + [
                'success'    => true,
                'settlement' => [
                    'needed'         => true,
                    'type'           => 'leave',
                    'overdue'        => $schedOutA < $todayA,
                    'sched_checkout' => rh_late_stay_fmt($schedOutA),
                    'sched_ymd'      => $schedOutA,
                    'today_ymd'      => $todayA,
                    'today_fmt'      => rh_late_stay_fmt($todayA),
                    'min_ymd'        => $optsA['min'],
                    'arrival_ymd'    => $optsA['arrival'],
                    'arrival_fmt'    => $optsA['arrival'] ? rh_late_stay_fmt($optsA['arrival']) : '',
                    'quotes'         => $quotesA,
                    'store_credit_allowed' => $storeCreditA,
                    'booked_nights'  => max(1, (int)($abk['number_of_nights'] ?? 1)),
                    'amount_paid'    => (float)($abk['amount_paid'] ?? 0),
                    'amount_due'     => (float)($abk['amount_due'] ?? 0),
                    'total_amount'   => (float)$abk['total_amount'],
                    'check_in_date'  => rh_late_stay_fmt((string)$abk['check_in_date']),
                    'guest_name'     => trim((string)($abk['guest_name'] ?? '')),
                    'room_label'     => trim((string)($abk['individual_room_number'] ?? $abk['room_type_name'] ?? 'N/A')),
                    'booking_ref'    => $abk['booking_reference'],
                ],
            ]);
            exit;
        } elseif ($action === 'checkout_settle') {
            // ── Settle finances for the day the guest left, then check out ──
            // The SERVER decides charge / refund / nothing from the posted leave date (re-validated against the
            // locked row and today); posted figures are never used. A payment is recorded only for the amount
            // front desk says it actually received, through the normal limit + VAT split.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id     = (int)($_POST['id'] ?? 0);
            $settleChoice   = (string)($_POST['settlement_action'] ?? ''); // 'leave' | 'skip'
            $payment_method = (string)($_POST['payment_method'] ?? 'cash');
            if (!in_array($payment_method, ['cash', 'bank_transfer', 'mobile_money', 'credit_card', 'debit_card', 'cheque', 'other'], true)) {
                $payment_method = 'other';
            }
            $amountReceived = round(max(0.0, (float)($_POST['amount_received'] ?? 0)), 2);
            $settle_notes   = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 200);
            // "Processed by" on every payment / refund row this settlement writes: the same value Record Payment
            // (payment-add.php) stores, so Payments, receipts and the CSV name the staff member.
            $settleProcessedBy = (string)($user['full_name'] ?? ($user['username'] ?? 'System'));
            // Unused-nights refund: back to the original method(s), or store credit (a credit note) - the same two ways as
            // the normal Refund (payment-refund.php), and store credit only when the refund setting is "ask".
            $refundAs = (string)($_POST['refund_as'] ?? 'original');
            if (!in_array($refundAs, ['original', 'store_credit'], true)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid refund method.']);
                exit;
            }
            if (rh_refund_rule('refund_method_default') !== 'ask') {
                $refundAs = 'original';
            }
            $settleCreditNotes = []; // store credit: notes issued in the transaction, PDF + email after the commit

            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }
            if (!in_array($settleChoice, ['leave', 'skip'], true)) {
                // Old cached pages posted 'charge' / 'refund' without a leave date: never charge on an assumption.
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Choose when the guest left (or Skip settlement) before checking out. Reload the page if this keeps happening.']);
                exit;
            }

            $settlement_pay_id = 0;
            $settlementPaymentReference = null;
            $earlyRefundLegs = []; // refund notices go out after the commit (money returned = guest is told)
            $refundTotal = 0.0;
            $pdo->beginTransaction();
            try {
                $lockS = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
                $lockS->execute([$booking_id]);
                $sbk = $lockS->fetch(PDO::FETCH_ASSOC);
                if (!$sbk || $sbk['status'] !== 'checked-in') {
                    throw new Exception('Booking not found or not checked-in');
                }
                // Same figures the quote used: re-derive paid / due under the lock, then read the row again.
                if (!recalculateBookingFinancials($booking_id)) {
                    throw new Exception('Could not recalculate the booking balance.');
                }
                $lockS->execute([$booking_id]);
                $sbk = $lockS->fetch(PDO::FETCH_ASSOC) ?: $sbk;
                $todayStr_s = date('Y-m-d');
                $before_s = ['status' => 'checked-in', 'check_in_date' => $sbk['check_in_date'], 'check_out_date' => $sbk['check_out_date'],
                    'number_of_nights' => (int)$sbk['number_of_nights'], 'total_with_vat' => (float)$sbk['total_with_vat']];
                $q = null;

                if ($settleChoice === 'leave') {
                    $leaveS = rh_late_stay_ymd((string)($_POST['leave_date'] ?? ''));
                    $arrivalS = rh_late_stay_actual_arrival($pdo, (int)$booking_id, (string)$sbk['check_in_date']);
                    $basisS = (string)($_POST['stay_basis'] ?? '');
                    if ($arrivalS !== null && $leaveS !== null && $leaveS > substr((string)$sbk['check_out_date'], 0, 10)) {
                        // Late arrival + late departure: front desk chooses the basis each time (owner 2026-11-12).
                        if (!in_array($basisS, ['booked', 'actual'], true)) {
                            throw new Exception('Choose how to count the nights: keep the booked nights and charge the nights after the booked check-out, or charge only the nights actually stayed.');
                        }
                    } else {
                        $basisS = 'booked';
                    }
                    $q = rh_late_stay_quote($sbk, (string)$leaveS, $basisS, $arrivalS, $todayStr_s);
                    if (empty($q['ok'])) {
                        throw new Exception($q['error']);
                    }

                    if ($q['reprice'] !== null) {
                        // Booked rate × the nights for the leave date; the leave date (never today) is the new check-out.
                        rh_write_booking_stay_totals(
                            $pdo, $booking_id, $q['reprice']['totals'], (int)$q['nights'],
                            $q['new_check_in'], $q['new_check_out'], $q['reprice']['new_child_supplement']
                        );
                        if (!recalculateBookingFinancials($booking_id)) {
                            throw new Exception('Could not recalculate the booking balance.');
                        }
                    }

                    if ($amountReceived > BALANCE_TOLERANCE) {
                        if ($q['direction'] !== 'charge') {
                            throw new Exception('A payment can only be taken here when the guest owes extra nights. Record other payments on the booking.');
                        }
                        $limitErr_s = rh_payment_limit_error($amountReceived, rh_account_balance_due($pdo, 'room', (int)$booking_id), false);
                        if ($limitErr_s !== null) {
                            throw new Exception($limitErr_s);
                        }
                        $vatRate_s = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
                        $split_s = rh_account_vat_split($pdo, 'room', (int)$booking_id, $amountReceived, $vatRate_s);

                        $paymentRefBase = 'PAY-EC-' . date('Y') . '-' . str_pad((string)$booking_id, 6, '0', STR_PAD_LEFT);
                        $settlementPaymentReference = $paymentRefBase;
                        $paymentRefSuffix = 1;
                        $paymentRefCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE payment_reference = ?');
                        $paymentRefCheck->execute([$settlementPaymentReference]);
                        while ((int)$paymentRefCheck->fetchColumn() > 0) {
                            $paymentRefSuffix++;
                            $settlementPaymentReference = $paymentRefBase . '-' . $paymentRefSuffix;
                            $paymentRefCheck->execute([$settlementPaymentReference]);
                        }
                        $settlementReceipt = finance_next_receipt_number($pdo, $todayStr_s);
                        $settlementNotes = 'Late checkout: guest left ' . rh_late_stay_fmt($q['leave_date']) . ', ' . (int)$q['extra_nights']
                            . ' extra night(s) at the booked rate' . ($settle_notes !== '' ? '. ' . $settle_notes : '');
                        $pdo->prepare("
                            INSERT INTO payments (
                                payment_reference, booking_type, booking_id, booking_reference,
                                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                payment_method, payment_type, payment_status, invoice_generated,
                                receipt_number, status, notes, recorded_by, processed_by
                            ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'partial_payment', 'completed', 1, ?, 'completed', ?, ?, ?)
                        ")->execute([
                            $settlementPaymentReference,
                            $booking_id,
                            $sbk['booking_reference'],
                            $split_s['net'],
                            $split_s['rate'],
                            $split_s['vat'],
                            $amountReceived,
                            $payment_method,
                            $settlementReceipt,
                            $settlementNotes,
                            (int)($user['id'] ?? 0),
                            $settleProcessedBy,
                        ]);
                        $settlement_pay_id = (int)$pdo->lastInsertId();
                        if (!recalculateBookingFinancials($booking_id)) {
                            throw new Exception('Could not recalculate the booking balance after the payment.');
                        }
                    }

                    if ($q['direction'] === 'refund') {
                        // Unused nights: the bill was reduced above; refund (net paid - new bill) in this same
                        // transaction against the original payment(s) / method(s), shown in full.
                        $finE = $pdo->prepare("SELECT amount_paid, folio_charges_total, total_with_vat FROM bookings WHERE id = ?");
                        $finE->execute([$booking_id]);
                        $fe = $finE->fetch(PDO::FETCH_ASSOC);
                        $refundAmt = max(0.0, round((float)$fe['amount_paid'] - (float)$fe['total_with_vat'] - (float)$fe['folio_charges_total'], 2));

                        if ($refundAmt > BALANCE_TOLERANCE && $refundAs === 'store_credit') {
                            // Store credit, as the normal Refund issues it: a credit note (issueCreditNote) made out to who
                            // paid (company share -> the company's billing email), linked refund rows with method
                            // 'credit_note' (recordCreditNoteRefundLegs), all in this transaction; PDF + email after commit.
                            require_once __DIR__ . '/../config/credit-notes.php';
                            $scReason = 'Guest left ' . rh_late_stay_fmt($q['leave_date']) . ': ' . (int)$q['unused_nights'] . " unused night(s) at {$currency_symbol} "
                                . number_format((float)$q['per_night'], 2) . '/night (booked rate). Refunded as store credit.'
                                . ($settle_notes !== '' ? ' ' . $settle_notes : '');
                            $scGroups = [];
                            foreach (allocateBookingRefunds($pdo, (int)$booking_id, $refundAmt, true) as $al) {
                                $scGroups[(string)($al['payer'] ?? 'guest') === 'company' ? 'company' : 'guest'][] = $al;
                            }
                            $scBill = function_exists('rh_booking_billing') ? rh_booking_billing($pdo, $sbk) : ['on_account' => false];
                            $scProc = $pdo->prepare("UPDATE payments SET processed_by = ? WHERE payment_reference = ?");
                            foreach (['company', 'guest'] as $scPayer) {
                                if (empty($scGroups[$scPayer])) {
                                    continue;
                                }
                                $scPart = 0.0;
                                $scVat = 0.0;
                                foreach ($scGroups[$scPayer] as $al) {
                                    $scPart = round($scPart + (float)$al['amount'], 2);
                                    $scVat = round($scVat + (float)rh_refund_vat_split($al['payment'], (float)$al['amount'])['vat'], 2); // note VAT == refund VAT
                                }
                                $scIsCo = $scPayer === 'company' && !empty($scBill['on_account']);
                                $scName = $scIsCo ? (string)$scBill['name'] : (trim((string)$sbk['guest_name']) !== '' ? (string)$sbk['guest_name'] : 'Guest');
                                $scEmail = $scIsCo ? (string)$scBill['billing_email'] : (string)($sbk['guest_email'] ?? '');
                                $scCn = issueCreditNote($pdo, [
                                    'amount'              => $scPart,
                                    'guest_name'          => $scName,
                                    'guest_email'         => $scEmail !== '' ? $scEmail : null,
                                    'booking_id'          => (int)$booking_id,
                                    'booking_reference'   => (string)$sbk['booking_reference'],
                                    'booking_type'        => 'room',
                                    'reason'              => 'early_checkout',
                                    'reason_notes'        => mb_substr($scReason, 0, 1000),
                                    'vat_rate'            => (float)($scGroups[$scPayer][0]['payment']['vat_rate'] ?? 0),
                                    'vat_amount'          => $scVat,
                                    'original_payment_id' => (int)$scGroups[$scPayer][0]['payment']['id'],
                                    'issued_by'           => (int)($user['id'] ?? 0),
                                    'generate_pdf'        => false,
                                    'send_email'          => false,
                                ]);
                                if (empty($scCn['success'])) {
                                    throw new Exception('The credit note could not be issued, so nothing was refunded. ' . ($scCn['error'] ?? ''));
                                }
                                $scLegs = recordCreditNoteRefundLegs($pdo, (int)$booking_id, (string)$sbk['booking_reference'], $scPart,
                                    (int)$scCn['credit_note_id'], (string)$scCn['credit_note_number'], (int)($user['id'] ?? 0), 'early_checkout', 'room', $scGroups[$scPayer]);
                                if ($scLegs['total'] + BALANCE_TOLERANCE < $scPart) {
                                    throw new Exception('Could not move the full amount into the credit note. Nothing was changed.');
                                }
                                foreach ($scLegs['refs'] as $scRef) {
                                    $scProc->execute([$settleProcessedBy, $scRef]);
                                }
                                $refundTotal = round($refundTotal + $scPart, 2);
                                $settleCreditNotes[] = ['id' => (int)$scCn['credit_note_id'], 'number' => (string)$scCn['credit_note_number'], 'amount' => $scPart,
                                    'email' => trim($scEmail), 'payer' => $scIsCo ? 'company' : 'guest', 'name' => $scName];
                            }
                        } elseif ($refundAmt > BALANCE_TOLERANCE) {
                            $refundReason = 'Guest left ' . rh_late_stay_fmt($q['leave_date']) . ': ' . (int)$q['unused_nights'] . " unused night(s) at {$currency_symbol} "
                                . number_format((float)$q['per_night'], 2) . '/night (booked rate)'
                                . ($settle_notes !== '' ? '. ' . $settle_notes : '');
                            $insRef = $pdo->prepare("
                                INSERT INTO payments (
                                    payment_reference, booking_type, booking_id, booking_reference,
                                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                                    payment_method, payment_type, payment_status, original_payment_id,
                                    refund_reason, refund_status, refund_amount, refund_notes,
                                    recorded_by, processed_by, created_at
                                ) VALUES (
                                    ?, 'room', ?, ?, ?,
                                    ?, ?, ?, ?,
                                    ?, 'refund', 'completed', ?,
                                    'early_checkout', 'completed', ?, ?,
                                    ?, ?, NOW()
                                )
                            ");
                            foreach (allocateBookingRefunds($pdo, (int)$booking_id, $refundAmt, true) as $alloc) {
                                $orig = $alloc['payment'];
                                $leg  = (float)$alloc['amount'];
                                $spE  = rh_refund_vat_split($orig, $leg);
                                $vr   = rh_refund_leg_rate($pdo, $orig, (float)$spE['rate']); // true rate; VAT amount / net unchanged
                                $va   = $spE['vat'];
                                $earlyLegRef = finance_next_refund_reference($pdo, $todayStr_s);
                                $insRef->execute([
                                    $earlyLegRef,
                                    $booking_id,
                                    $sbk['booking_reference'],
                                    $todayStr_s,
                                    round($leg - $va, 2),
                                    $vr,
                                    $va,
                                    $leg,
                                    $orig['payment_method'] ?: 'other',
                                    (int)$orig['id'],
                                    $leg,
                                    $refundReason, // free text belongs in refund_notes; refund_reason is an ENUM
                                    (int)($user['id'] ?? 0),
                                    $settleProcessedBy,
                                ]);
                                $refundTotal = round($refundTotal + $leg, 2);
                                $earlyRefundLegs[] = ['orig' => array_merge($orig, ['booking_type' => 'room', 'booking_id' => (int)$booking_id, 'booking_reference' => $sbk['booking_reference']]), 'ref' => $earlyLegRef, 'amount' => $leg, 'reason' => $refundReason];
                                if (!function_exists('rh_refresh_original_payment_status') && is_file(__DIR__ . '/includes/finance-account-sync.php')) {
                                    require_once __DIR__ . '/includes/finance-account-sync.php';
                                }
                                if (function_exists('rh_refresh_original_payment_status')) {
                                    rh_refresh_original_payment_status($pdo, (int)$orig['id']);
                                }
                            }
                        }
                        // Re-derive paid / due / credit after the bill reduction and refund
                        recalculateBookingFinancials($booking_id);
                    }
                }
                // 'skip' -> no financial record and no date change (handled outside the system); still logged below.

                // ── Balance gate (after any settlement above, before status change) ──
                $balGate = evaluateCheckoutBalance($pdo, (int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['confirm_checkout_with_balance']));
                if (!$balGate['allowed']) {
                    throw new Exception($balGate['message']);
                }
                if ($balGate['override']) {
                    logCheckoutBalanceOverride((int)$booking_id, (string)$sbk['booking_reference'], (float)$balGate['balance'], (int)($user['id'] ?? 0), $user['full_name'] ?? ($user['username'] ?? null));
                }

                // ── Perform the actual checkout ──
                // checkout_completed_at as processGuestCheckout / rh_checkout_with_gate (housekeeping, review emails read it).
                $pdo->prepare("UPDATE bookings SET status = 'checked-out', checkout_completed_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([$booking_id]);

                $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                    ->execute([$sbk['room_id']]);
                // Same close step as every other checkout (processGuestCheckout, rh_checkout_with_gate): room-service
                // orders already charged to the folio stop being 'placed', so the room's active-order lock is released.
                if (!function_exists('closeFolioChargedRoomServiceOrders')) {
                    require_once __DIR__ . '/../includes/room-management.php';
                }
                $settleOrdersClosed = closeFolioChargedRoomServiceOrders($pdo, (int)$booking_id);
                // The stay is over: stop holding its rooms (booking_rooms.released_at).
                rh_release_booking_room_holds($pdo, (int)$booking_id);

                updateBookingRoomsStatus(
                    $booking_id,
                    'cleaning',
                    'Checkout completed (' . $settleChoice . '): ' . ($sbk['booking_reference'] ?? ('Booking #' . $booking_id)),
                    (int)($user['id'] ?? 0)
                );

                // History (owner: "Make sure there is logs and all"): the chosen option, the leave date, nights, money.
                $afterS = $pdo->prepare("SELECT check_in_date, check_out_date, number_of_nights, total_with_vat FROM bookings WHERE id = ?");
                $afterS->execute([$booking_id]);
                $after_s = $afterS->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($q !== null) {
                    $settleDescribe = rh_late_stay_describe($q, $currency_symbol);
                } else {
                    $settleDescribe = 'Checkout settlement skipped (handled outside the system): booked stay '
                        . rh_late_stay_fmt((string)$sbk['check_in_date']) . ' to ' . rh_late_stay_fmt((string)$sbk['check_out_date'])
                        . ', ' . (int)$sbk['number_of_nights'] . ' night(s), room total ' . $currency_symbol . ' ' . number_format((float)$sbk['total_with_vat'], 2) . ' unchanged.';
                }
                if ($settlementPaymentReference) {
                    $settleDescribe .= ' Received ' . $currency_symbol . ' ' . number_format($amountReceived, 2) . ' (' . str_replace('_', ' ', $payment_method) . ', ' . $settlementPaymentReference . ').';
                }
                if ($settleCreditNotes) {
                    foreach ($settleCreditNotes as $scOne) {
                        $settleDescribe .= ' Refunded ' . $currency_symbol . ' ' . number_format((float)$scOne['amount'], 2) . ' as store credit: credit note ' . $scOne['number']
                            . ($scOne['payer'] === 'company' ? ' (made out to ' . $scOne['name'] . ', which paid it)' : '') . '.';
                    }
                } elseif ($refundTotal > BALANCE_TOLERANCE) {
                    $settleDescribe .= ' Refunded ' . $currency_symbol . ' ' . number_format($refundTotal, 2) . '.';
                }
                if ($settle_notes !== '') {
                    $settleDescribe .= ' Note: ' . $settle_notes;
                }
                $settleCtx = [
                    'booking_id'      => $booking_id,
                    'ref'             => $sbk['booking_reference'],
                    'settlement'      => $settleChoice,
                    'leave_date'      => $q['leave_date'] ?? null,
                    'basis'           => $q['basis'] ?? null,
                    'nights'          => $q['nights'] ?? (int)$sbk['number_of_nights'],
                    'extra_nights'    => $q['extra_nights'] ?? 0,
                    'unused_nights'   => $q['unused_nights'] ?? 0,
                    'direction'       => $q['direction'] ?? 'skipped',
                    'amount'          => $q['amount'] ?? 0,
                    'amount_received' => $settlementPaymentReference ? $amountReceived : 0,
                    'refunded'        => $refundTotal,
                    'refund_as'       => $refundTotal > BALANCE_TOLERANCE ? $refundAs : null,
                    'credit_notes'    => array_column($settleCreditNotes, 'number'),
                    'room_service_orders_closed' => $settleOrdersClosed,
                    'by'              => $user['username'] ?? null,
                ];
                rh_log_event('bookings', 'info', 'Guest checked out (settlement: ' . $settleChoice . ')', $settleCtx);
                logBookingEvent((int)$booking_id, (string)$sbk['booking_reference'], 'Checkout settlement', 'check_out', $settleDescribe,
                    (string)$sbk['check_out_date'], (string)($after_s['check_out_date'] ?? $sbk['check_out_date']), 'admin', $user['id'] ?? null,
                    $user['full_name'] ?? ($user['username'] ?? null), $settleCtx);
                logBookingAudit(
                    $booking_id,
                    'checked-out',
                    $before_s,
                    ['status' => 'checked-out', 'settlement' => $settleChoice, 'leave_date' => $q['leave_date'] ?? null, 'basis' => $q['basis'] ?? null,
                        'check_in_date' => $after_s['check_in_date'] ?? null, 'check_out_date' => $after_s['check_out_date'] ?? null,
                        'number_of_nights' => isset($after_s['number_of_nights']) ? (int)$after_s['number_of_nights'] : null,
                        'total_with_vat' => isset($after_s['total_with_vat']) ? (float)$after_s['total_with_vat'] : null,
                        'direction' => $q['direction'] ?? 'skipped', 'amount' => $q['amount'] ?? 0,
                        'amount_received' => $settlementPaymentReference ? $amountReceived : 0, 'refunded' => $refundTotal],
                    $settleDescribe,
                    $sbk['booking_reference'] ?? null
                );

                $pdo->commit();

                // Receipt for the money received at this checkout, if any.
                $settlementReceiptNotice = '';
                if ($settlement_pay_id > 0) {
                    require_once '../config/receipts.php';
                    $settlementReceiptNotice = receipt_after_payment($pdo, (int)$settlement_pay_id, $user)['notice'];
                }
                // Refund notice for unused nights (one per refund leg). Company money: to the company (staff told if it
                // could not be sent).
                $refundNoticeNote = '';
                foreach ($earlyRefundLegs as $erl) {
                    try {
                        require_once '../config/email.php';
                        $erlRes = sendRefundNotificationEmail($erl['orig'], $erl['ref'], (float)$erl['amount'], (string)$erl['reason']);
                        if (empty($erlRes['success'])) {
                            error_log('Early-checkout refund notice not sent for ' . $erl['ref'] . ': ' . ($erlRes['message'] ?? 'unknown'));
                            if (strpos((string)($erlRes['message'] ?? ''), 'went back to') !== false) {
                                $refundNoticeNote .= ' ' . $erlRes['message'];
                            }
                        }
                    } catch (Throwable $rfEx) {
                        error_log('Early-checkout refund notice failed for ' . $erl['ref'] . ': ' . $rfEx->getMessage());
                    }
                }
                // Store credit: the credit note PDF + email, best effort after the commit (as the normal Refund).
                foreach ($settleCreditNotes as $scOne) {
                    try {
                        generateCreditNotePDF($pdo, (int)$scOne['id']);
                        if ($scOne['email'] !== '' && filter_var($scOne['email'], FILTER_VALIDATE_EMAIL)) {
                            sendCreditNoteEmail($pdo, (int)$scOne['id']);
                        } else {
                            $refundNoticeNote .= ' Credit note ' . $scOne['number'] . ' was not emailed (no email on file): share the number manually.';
                        }
                    } catch (Throwable $scEx) {
                        $refundNoticeNote .= ' (Credit note ' . $scOne['number'] . ' PDF/email could not be produced: regenerate it from Credit Notes.)';
                        error_log('Settlement store-credit CN post-commit failed for ' . $scOne['number'] . ': ' . $scEx->getMessage());
                    }
                }

                header('Content-Type: application/json');
                $checkoutMessage = 'Checkout completed. ' . $settleDescribe . $settlementReceiptNotice . $refundNoticeNote
                    . bookings_final_invoice_note((int)$booking_id, (int)($user['id'] ?? 0), !empty($_POST['email_final_invoice']) ? true : null);
                echo json_encode(['success' => true, 'message' => $checkoutMessage]);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("checkout_settle error: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Checkout failed - nothing was changed. ' . $e->getMessage()]);
                exit;
            }
        } elseif ($action === 'consolidation_fetch') {
            // ── Return full financial snapshot for a booking ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing booking id']);
                exit;
            }

            $cf_stmt = $pdo->prepare("
                SELECT b.*,
                       r.name AS room_type_name,
                       ir.room_number AS individual_room_number
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ?
            ");
            $cf_stmt->execute([$booking_id]);
            $cf_bk = $cf_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cf_bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }

            // Active folio charges
            $charges_stmt = $pdo->prepare("
                SELECT description, charge_type, quantity, unit_price, line_total, posted_at
                FROM booking_charges
                WHERE booking_id = ? AND voided = 0
                ORDER BY posted_at ASC
            ");
            $charges_stmt->execute([$booking_id]);
            $cf_charges = $charges_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Completed payments (excluding refunds)
            $pays_stmt = $pdo->prepare("
                SELECT payment_reference, payment_date, total_amount, payment_method, payment_type, notes
                FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND payment_status IN ('completed','paid') AND COALESCE(payment_type, '') != 'refund'
                  AND deleted_at IS NULL
                ORDER BY payment_date ASC, id ASC
            ");
            $pays_stmt->execute([$booking_id]);
            $cf_pays = $pays_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Refunds
            $refunds_stmt = $pdo->prepare("
                SELECT payment_reference, payment_date, total_amount, payment_method, refund_reason
                FROM payments
                WHERE booking_type = 'room' AND booking_id = ?
                  AND payment_type = 'refund'
                  AND deleted_at IS NULL
                ORDER BY payment_date ASC, id ASC
            ");
            $refunds_stmt->execute([$booking_id]);
            $cf_refunds = $refunds_stmt->fetchAll(PDO::FETCH_ASSOC);
            // Company pays later: Record payment asks who paid (guest / company) on an on-account booking.
            $cf_split = rh_booking_bill_split($pdo, (int)$booking_id, $cf_bk);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'booking' => [
                    'id'               => (int)$cf_bk['id'],
                    'reference'        => $cf_bk['booking_reference'],
                    'guest_name'       => $cf_bk['guest_name'],
                    'room_type'        => $cf_bk['room_type_name'] ?? '',
                    'room_number'      => $cf_bk['individual_room_number'] ?? '',
                    'check_in'         => date('d M Y', strtotime($cf_bk['check_in_date'])),
                    'check_out'        => date('d M Y', strtotime($cf_bk['check_out_date'])),
                    'nights'           => (int)$cf_bk['number_of_nights'],
                    'status'           => $cf_bk['status'],
                    'payment_status'   => $cf_bk['payment_status'],
                    'total_amount'     => (float)$cf_bk['total_amount'],
                    'total_with_vat'   => (float)($cf_bk['total_with_vat'] ?? $cf_bk['total_amount']),
                    'folio_charges'    => (float)($cf_bk['folio_charges_total'] ?? 0),
                    'amount_paid'      => (float)($cf_bk['amount_paid'] ?? 0),
                    'amount_due'       => (float)($cf_bk['amount_due'] ?? 0),
                    'on_account'       => !empty($cf_split['on_account']),
                    'company_name'     => (string)($cf_split['name'] ?? ''),
                    'company_due'      => (float)($cf_split['company_due'] ?? 0),
                    'guest_due'        => (float)($cf_split['guest_due'] ?? 0),
                ],
                'charges'  => $cf_charges,
                'payments' => $cf_pays,
                'refunds'  => $cf_refunds,
            ]);
            exit;
        } elseif ($action === 'consolidation_record') {
            // ── Record a manual consolidation payment ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id     = (int)($_POST['id'] ?? 0);
            $pay_amount     = (float)($_POST['amount'] ?? 0);
            $pay_method     = $_POST['payment_method'] ?? 'cash';
            $pay_type       = $_POST['payment_type'] ?? 'partial_payment'; // partial_payment | full_payment
            $pay_date       = $_POST['payment_date'] ?? date('Y-m-d');
            $notes          = trim($_POST['notes'] ?? '');
            $acct_notes     = trim($_POST['accountant_notes'] ?? '');
            $combined_notes = $notes . ($acct_notes ? ' | ACCT: ' . $acct_notes : '');

            if ($booking_id <= 0 || $pay_amount <= 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
                exit;
            }

            // Validate date
            $payDateObj = DateTime::createFromFormat('Y-m-d', $pay_date);
            if (!$payDateObj) {
                $pay_date = date('Y-m-d');
            }

            require_once __DIR__ . '/includes/booking-lifecycle.php';
            require_once __DIR__ . '/includes/finance-account-sync.php';
            require_once __DIR__ . '/../config/credit-notes.php';

            // Only values the ledger understands: payment_type is limited to a partial or the
            // final payment (an 'adjustment' is not a payment), method to the enum.
            if (!in_array($pay_type, ['partial_payment', 'full_payment'], true)) {
                $pay_type = 'partial_payment';
            }
            if (!in_array($pay_method, ['cash', 'bank_transfer', 'mobile_money', 'credit_card', 'debit_card', 'cheque', 'other'], true)) {
                $pay_method = 'other';
            }

            // Company pays later (fix 2026-11-13): on an on-account booking staff say who paid, nothing preselected.
            // Company money becomes a one-line company payment (billing_remittances + line) through the same helper
            // as Company accounts, so it counts as the company's; guest money stays a normal payment below.
            $cr_bill = rh_booking_billing($pdo, ['id' => $booking_id]);
            if (!empty($cr_bill['on_account'])) {
                $cr_paidBy = (string)($_POST['paid_by'] ?? '');
                if (!in_array($cr_paidBy, ['guest', 'company'], true)) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Choose who paid: the guest or ' . $cr_bill['name'] . '. Nothing was recorded.']);
                    exit;
                }
                if ($cr_paidBy === 'company') {
                    // Same permission as recording it on Company accounts (page company_accounts + Add Payment).
                    if (!hasPermission((int)($user['id'] ?? 0), 'company_accounts') || !hasPermission((int)($user['id'] ?? 0), 'payment_add')) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'You do not have permission to record company payments (Company accounts). Nothing was recorded.']);
                        exit;
                    }
                    if (!empty($_POST['confirm_overpay'])) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'A company payment cannot be more than the company owes on this booking. Nothing was recorded.']);
                        exit;
                    }
                    try {
                        $cr_rem = rh_billing_record_remittance($pdo, (int)$cr_bill['account_id'], [
                            'amount' => (string)$pay_amount, 'payment_method' => $pay_method, 'received_date' => $pay_date,
                            'bank_reference' => '', 'notes' => $combined_notes, 'lines' => [$booking_id => (string)$pay_amount],
                        ], $user);
                    } catch (Throwable $crRemEx) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Could not record the company payment: ' . $crRemEx->getMessage()]);
                        exit;
                    }
                    $cr_leg = $cr_rem['legs'][0];
                    $upd_stmt = $pdo->prepare("SELECT amount_paid, amount_due, payment_status, booking_reference FROM bookings WHERE id = ?");
                    $upd_stmt->execute([$booking_id]);
                    $upd = $upd_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    logBookingAudit($booking_id, 'payment_consolidation', ['amount_paid' => null],
                        ['amount_paid' => $pay_amount, 'payment_reference' => $cr_leg['payment_reference'], 'paid_by' => 'company', 'company' => $cr_bill['name'],
                            'remittance' => $cr_rem['reference']],
                        'Company payment ' . $cr_rem['reference'] . ' from ' . $cr_bill['name'] . ' recorded on the booking by ' . ($user['full_name'] ?? ($user['username'] ?? '')),
                        (string)($upd['booking_reference'] ?? ''));
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'           => true,
                        'message'           => $cr_bill['name'] . ' paid ' . $currency_symbol . ' ' . number_format($pay_amount, 2) . ' (company payment ' . $cr_rem['reference']
                            . ', receipt ' . $cr_leg['receipt_number'] . '). The company still owes ' . $currency_symbol . ' ' . number_format((float)$cr_leg['company_due_after'], 2)
                            . ' on this booking. Send the payment advice from Company accounts.',
                        'payment_reference' => $cr_leg['payment_reference'],
                        'new_amount_paid'   => (float)($upd['amount_paid'] ?? 0),
                        'new_amount_due'    => (float)($upd['amount_due'] ?? 0),
                        'new_payment_status' => (string)($upd['payment_status'] ?? ''),
                    ]);
                    exit;
                }
            }

            // Unique reference
            do {
                $payRef_cr = 'CON-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $refChk_cr = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
                $refChk_cr->execute([$payRef_cr]);
            } while ((int)$refChk_cr->fetchColumn() > 0);

            $consolidation_pay_id = 0;
            $overpayCn_cr = null;
            $cr_bk = null;
            $pdo->beginTransaction();
            try {
                // Lock the booking, re-derive its balance, then apply the same lifecycle rule as every
                // other payment path (no payment on a cancelled / no-show / expired booking, nor on a
                // settled checked-out one).
                $cr_stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
                $cr_stmt->execute([$booking_id]);
                $cr_bk = $cr_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$cr_bk) {
                    $pdo->rollBack();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Booking not found']);
                    exit;
                }
                if (!recalculateBookingFinancials($booking_id)) {
                    throw new RuntimeException('Could not recalculate the booking balance - nothing was recorded.');
                }
                $cr_stmt->execute([$booking_id]);
                $cr_bk = $cr_stmt->fetch(PDO::FETCH_ASSOC) ?: $cr_bk;
                $cr_guard = bookingAllowsAction($cr_bk, 'record_payment');
                if (empty($cr_guard['allowed'])) {
                    $pdo->rollBack();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $cr_guard['reason'] ?: 'A payment cannot be recorded on this booking.']);
                    exit;
                }
                // Nothing owed = no payment; more than owed only with the explicit tick (balance re-read under the lock).
                $cr_limitErr = rh_payment_limit_error($pay_amount, rh_account_balance_due($pdo, 'room', $booking_id), !empty($_POST['confirm_overpay']));
                if ($cr_limitErr !== null) {
                    $pdo->rollBack();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $cr_limitErr]);
                    exit;
                }

                // VAT split pro rata to the booking's own VAT / gross ratio (levy carries no VAT).
                $vatEnabled_cr = rh_vat_enabled();
                $vatRate_cr    = $vatEnabled_cr ? (float)getSetting('vat_rate') : 0;
                $cr_split      = rh_account_vat_split($pdo, 'room', $booking_id, $pay_amount, (float)$vatRate_cr);
                $vatRate_cr    = $cr_split['rate'];
                $vatAmt_cr     = $cr_split['vat'];
                $netAmt_cr     = $cr_split['net'];

                $receipt_number_cr = finance_next_receipt_number($pdo, $pay_date);

                $pdo->prepare("
                    INSERT INTO payments (
                        payment_reference, booking_type, booking_id, booking_reference,
                        payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                        payment_method, payment_type, payment_status,
                        receipt_number, notes, recorded_by, created_at
                    ) VALUES (?, 'room', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW())
                ")->execute([
                    $payRef_cr,
                    $booking_id,
                    $cr_bk['booking_reference'],
                    $pay_date,
                    $netAmt_cr,
                    $vatRate_cr,
                    $vatAmt_cr,
                    $pay_amount,
                    $pay_method,
                    $pay_type,
                    $receipt_number_cr,
                    $combined_notes ?: null,
                    (int)($user['id'] ?? 0),
                ]);
                $consolidation_pay_id = (int)$pdo->lastInsertId();

                if (!recalculateBookingFinancials($booking_id)) {
                    throw new RuntimeException('Could not recalculate the booking balance after the payment - nothing was recorded.');
                }
                // Overpayment lives in a credit note only (owner rule): convert any excess inside
                // this same transaction so bookings.credit_balance never holds it.
                $overpayCn_cr = rh_auto_credit_overpayment($pdo, 'room', $booking_id, $pay_amount, $payRef_cr, (int)($user['id'] ?? 0));
                $pdo->commit();
            } catch (Throwable $crEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('consolidation_record failed: ' . $crEx->getMessage());
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Could not record the payment: ' . $crEx->getMessage()]);
                exit;
            }
            rh_email_overpay_credit_notes($pdo, $overpayCn_cr); // every overpayment note with an address (company note -> billing email)

            // Receipt email for this consolidation payment
            $consolidationReceiptNotice = '';
            if ($consolidation_pay_id > 0) {
                require_once '../config/receipts.php';
                $consolidationReceiptNotice = receipt_after_payment($pdo, (int)$consolidation_pay_id, $user)['notice'];
            }

            // Fetch updated figures to return to JS
            $upd_stmt = $pdo->prepare("SELECT amount_paid, amount_due, payment_status FROM bookings WHERE id = ?");
            $upd_stmt->execute([$booking_id]);
            $upd = $upd_stmt->fetch(PDO::FETCH_ASSOC);

            rh_log_event('bookings', 'info', 'Manual consolidation payment recorded', [
                'booking_id' => $booking_id,
                'ref'        => $cr_bk['booking_reference'],
                'amount'     => $pay_amount,
                'method'     => $pay_method,
                'type'       => $pay_type,
                'by'         => $user['username'] ?? null,
            ]);
            logBookingAudit(
                $booking_id,
                'payment_consolidation',
                ['amount_paid' => null],
                ['amount_paid' => $pay_amount, 'payment_reference' => $payRef_cr],
                $combined_notes ?: 'Manual consolidation by ' . ($user['full_name'] ?? ($user['username'] ?? '')),
                $cr_bk['booking_reference']
            );

            header('Content-Type: application/json');
            echo json_encode([
                'success'          => true,
                'message'          => 'Payment of ' . $currency_symbol . ' ' . number_format($pay_amount, 2) . ' recorded successfully.'
                    . ($overpayCn_cr ? ' The overpayment of ' . $currency_symbol . ' ' . number_format((float)$overpayCn_cr['excess'], 2) . ' was issued as credit note ' . $overpayCn_cr['credit_note_number'] . '.' : '')
                    . $consolidationReceiptNotice,
                'payment_reference' => $payRef_cr,
                'new_amount_paid'  => (float)($upd['amount_paid'] ?? 0),
                'new_amount_due'   => (float)($upd['amount_due'] ?? 0),
                'new_payment_status' => $upd['payment_status'] ?? '',
            ]);
            exit;
        } elseif ($action === 'noshow') {
            // Mark a confirmed booking as no-show
            $booking_id = intval($_POST['id'] ?? 0);
            $noshow_note = trim((string)($_POST['checkin_note'] ?? ''));
            if ($booking_id > 0) {
                // One transaction (orders, status, rooms, holds, refund) in rh_mark_booking_no_show().
                require_once __DIR__ . '/../includes/booking-functions.php';
                $ns = rh_mark_booking_no_show($pdo, $booking_id, (int)($user['id'] ?? 0), (string)($user['full_name'] ?? ($user['username'] ?? '')), $noshow_note);
                if (!$ns['success']) {
                    $error = $ns['error'];
                } else {
                    $bk = $ns['booking'];
                    $ns_refund = $ns['refund'];
                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' marked as No-Show. Room availability restored.';
                    if (!empty($ns['closed_orders'])) {
                        $message .= ' Closed order(s) ' . htmlspecialchars(implode(', ', $ns['closed_orders'])) . '.';
                    }
                    if ($ns_refund['created']) {
                        $message .= ' Pending refund ' . $ns_refund['refund_ref']
                            . ' (' . getSetting('currency_symbol', 'MWK') . ' ' . number_format((float)$ns_refund['refund_amount'], 2) . ') queued.';
                    } elseif (!empty($ns_refund['error'])) {
                        $message .= ' WARNING: the automatic refund could not be created (' . $ns_refund['error'] . ') - raise it manually in Payments.';
                    }
                }
            }

            if (isAjaxRequest()) {
                header('Content-Type: application/json');
                if (!empty($error)) {
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
                echo json_encode(['success' => true, 'message' => $message ?? 'Booking marked as no-show.']);

                // Flush response to the browser NOW so the loader clears immediately.
                // Email sending (SMTP) can block for 30-300s on a slow/unreachable server;
                // by flushing first the UI is unblocked regardless of email outcome.
                if (ob_get_level()) { ob_end_flush(); }
                flush();
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

                // Send guest no-show email after the connection is closed
                if (empty($error) && !empty($bk['guest_email'])) {
                    require_once __DIR__ . '/../config/email.php';
                    sendNoShowEmail(
                        $bk,
                        (float)($ns_refund['refund_amount'] ?? 0.0),
                        (string)($ns_refund['refund_ref'] ?? '')
                    );
                }
                exit;
            }
        } elseif ($action === 'get_booking_details') {
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }

            $booking_id = (int)($_POST['booking_id'] ?? 0);
            if ($booking_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing booking ID'
                ]);
                exit;
            }

            // Fetch booking details with room and individual room info
            $stmt = $pdo->prepare("
                SELECT b.*,
                    r.name as room_name,
                    COALESCE(p.payment_status, b.payment_status) as actual_payment_status,
                    p.payment_reference,
                    ir.room_number as individual_room_number,
                    ir.room_name as individual_room_name,
                    ir.floor as individual_room_floor,
                    ir.status as individual_room_status
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'
                LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Booking not found'
                ]);
                exit;
            }

            // Format dates and amounts
            $booking['check_in_date_formatted'] = date('M j, Y', strtotime($booking['check_in_date']));
            $booking['check_out_date_formatted'] = date('M j, Y', strtotime($booking['check_out_date']));
            $booking['created_at_formatted'] = date('M j, Y H:i', strtotime($booking['created_at']));
            $booking['total_full'] = rh_booking_full_total($booking); // room + VAT + levy (owner 2026-10-23)
            $booking['total_formatted'] = number_format($booking['total_full'], 2);
            $booking['total_breakdown'] = rh_booking_total_breakdown($booking);
            // Booked rate × nights, plus today's price note (staff only) when the room price has changed since.
            $gbdRate = rh_booked_rate_line($booking, rh_room_today_price_lookup($pdo, $booking)['price'], ['currency' => $currency_symbol]);
            $booking['booked_rate_text'] = $gbdRate['ok'] ? $gbdRate['text'] : '';
            $booking['booked_rate_today'] = $gbdRate['ok'] ? $gbdRate['today_text'] : '';
            // "How the total is made up": the stored figures only (includes/booked-rate-change.php).
            $booking['money_card'] = rh_booking_money_card($booking, $gbdRate, ['currency' => $currency_symbol]);
            // Plain words: "partially_refunded" -> "Partially refunded", "checked-in" -> "Checked in".
            $booking['status_label'] = rh_hist_words((string)$booking['status']);
            $booking['payment_status_label'] = rh_hist_words((string)($booking['actual_payment_status'] ?? $booking['payment_status']));

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $booking
            ]);
            exit;
        } elseif ($action === 'room_change_options') {
            // Upgrade / downgrade picker: every other active room type with availability and a quote.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!hasPermission((int)($user['id'] ?? 0), 'edit_booking')) {
                throw new Exception('You do not have permission to change a booking room type.');
            }
            $opts = rh_room_change_options($pdo, (int)($_POST['booking_id'] ?? 0));
            foreach ($opts['options'] as &$opt) {
                $opt['reasons'] = rh_room_change_reasons($opt['quote']['direction']);
            }
            unset($opt);
            $opts['can_keep_price'] = (bool)$_perm_edit_financials || $_is_admin_user;
            $opts['currency'] = $currency_symbol;
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $opts]);
            exit;
        } elseif ($action === 'stay_price_preview') {
            // Read-only: the booked rate × the new nights for a date change (extend / change checkout / quick
            // modify), from the same rh_reprice_at_booked_rate() the save uses. Writes nothing.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            header('Content-Type: application/json');
            $spId = (int)($_POST['booking_id'] ?? 0);
            $spSt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $spSt->execute([$spId]);
            $spBk = $spSt->fetch(PDO::FETCH_ASSOC);
            if (!$spBk) {
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
                exit;
            }
            $spInRaw = trim((string)($_POST['new_check_in'] ?? ''));
            $spOutRaw = trim((string)($_POST['new_check_out'] ?? ''));
            $spIn = DateTime::createFromFormat('!Y-m-d', $spInRaw !== '' ? $spInRaw : (string)$spBk['check_in_date']);
            $spOut = DateTime::createFromFormat('!Y-m-d', $spOutRaw);
            if (!$spIn || !$spOut || $spOut->format('Y-m-d') !== $spOutRaw) {
                echo json_encode(['success' => false, 'message' => 'Pick a valid check-out date']);
                exit;
            }
            $spNights = (int)$spIn->diff($spOut)->days;
            if ($spOut <= $spIn || $spNights < 1) {
                echo json_encode(['success' => false, 'message' => 'Check-out must be after check-in']);
                exit;
            }
            echo json_encode(['success' => true] + bookings_stay_rate_preview($spBk, $spNights, $currency_symbol, $spIn->format('Y-m-d'), $spOut->format('Y-m-d')));
            exit;
        } elseif ($action === 'stay_nights_quote' || $action === 'stay_nights_apply') {
            // Add / take off nights on an in-house stay (owner 2026-11-13). The quote is the review card; the apply
            // re-quotes under the row lock and must match it (includes/stay-nights.php). Permission: checkout_guest
            // (rhBookingActionPermission); a refund leg also needs refund_payment.
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            header('Content-Type: application/json');
            $snId = (int)($_POST['booking_id'] ?? 0);
            $snDelta = (int)($_POST['delta'] ?? 0);
            $snCanRefund = hasPermission((int)($user['id'] ?? 0), 'refund_payment');
            if ($action === 'stay_nights_quote') {
                $snSt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL");
                $snSt->execute([$snId]);
                $snBk = $snSt->fetch(PDO::FETCH_ASSOC);
                if (!$snBk) {
                    echo json_encode(['success' => false, 'message' => 'Booking not found']);
                    exit;
                }
                $snQ = rh_stay_nights_quote($pdo, $snBk, $snDelta, null, true, $currency_symbol);
                if (empty($snQ['ok'])) {
                    echo json_encode(['success' => false, 'message' => $snQ['error']]);
                    exit;
                }
                unset($snQ['drop'], $snQ['reprice']);
                echo json_encode(['success' => true, 'quote' => $snQ + [
                    'reference' => (string)$snBk['booking_reference'],
                    'guest_name' => (string)$snBk['guest_name'],
                    'old_check_out_fmt' => date('D j M Y', strtotime($snQ['old_check_out'])),
                    'new_check_out_fmt' => date('D j M Y', strtotime($snQ['new_check_out'])),
                    'can_refund' => $snCanRefund,
                    'pay_later' => !empty($snBk['checkin_without_payment']),
                ]]);
                exit;
            }
            try {
                $snRes = rh_stay_nights_apply($pdo, $snId, [
                    'delta' => $snDelta,
                    'expected_new_total' => $_POST['expected_new_total'] ?? null,
                    'expected_total_delta' => $_POST['expected_total_delta'] ?? null,
                    'reason' => (string)($_POST['reason'] ?? ''),
                    'money_back_mode' => (string)($_POST['money_back_mode'] ?? ''),
                    'money_back_typed' => $_POST['money_back_typed'] ?? null,
                    'can_refund' => $snCanRefund,
                ], $user);
            } catch (Throwable $snEx) {
                echo json_encode(['success' => false, 'message' => $snEx->getMessage()]);
                exit;
            }
            // Money returned = the guest is told (refund notice per leg; credit note email), after the commit.
            // Company money (company pays later): the notice / credit note goes to the company billing email.
            $snNote = '';
            foreach ($snRes['refund_legs'] as $snLeg) {
                try {
                    require_once '../config/email.php';
                    $snMail = sendRefundNotificationEmail($snLeg['orig'], $snLeg['ref'], (float)$snLeg['amount'], (string)$snLeg['reason']);
                    if (empty($snMail['success'])) {
                        error_log('Nights refund notice not sent for ' . $snLeg['ref'] . ': ' . ($snMail['message'] ?? 'unknown'));
                        if (strpos((string)($snMail['message'] ?? ''), 'went back to') !== false) {
                            $snNote .= ' ' . $snMail['message'];
                        }
                    }
                } catch (Throwable $snMailEx) {
                    error_log('Nights refund notice failed for ' . $snLeg['ref'] . ': ' . $snMailEx->getMessage());
                }
            }
            foreach (($snRes['credit_notes'] ?? []) as $snCn) {
                if ($snCn['email'] === '') {
                    if ($snCn['payer'] === 'company') {
                        $snNote .= ' Credit note ' . $snCn['number'] . ' was not emailed: ' . $snCn['name'] . ' has no billing email.';
                        rh_log_event('payments', 'warning', 'Company credit note not emailed', ['booking_id' => $snId, 'credit_note' => $snCn['number'], 'company' => $snCn['name']]);
                    }
                    continue;
                }
                try {
                    require_once '../config/credit-notes.php';
                    sendCreditNoteEmail($pdo, (int)$snCn['id']);
                } catch (Throwable $snCnEx) {
                    error_log('Nights credit note email failed: ' . $snCnEx->getMessage());
                }
            }
            // Plain text: the page's toast (showBookingActionMessage) escapes it (escaping here too showed &#039; etc.).
            echo json_encode(['success' => true, 'message' => $snRes['describe'] . $snNote]);
            exit;
        } elseif ($action === 'extend_stay') {
            // Extend the checkout date for a checked-in booking
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            $booking_id   = (int)($_POST['booking_id'] ?? 0);
            $new_checkout = trim($_POST['new_checkout'] ?? '');

            if ($booking_id <= 0 || !$new_checkout) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }

            // Validate date format
            $newCheckoutDt = DateTime::createFromFormat('Y-m-d', $new_checkout);
            if (!$newCheckoutDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit;
            }

            // Fetch booking
            $bk_stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'checked-in'");
            $bk_stmt->execute([$booking_id]);
            $bk = $bk_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $oldCheckout = $bk['check_out_date'];
            $checkIn     = $bk['check_in_date'];

            // New checkout must be after check-in
            $checkInDt = new DateTime($checkIn);
            if ($newCheckoutDt <= $checkInDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after check-in date']);
                exit;
            }

            // New checkout must be strictly after the current checkout date (actually extending)
            $oldCheckoutDt = new DateTime($oldCheckout);
            if ($newCheckoutDt <= $oldCheckoutDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after the current checkout date']);
                exit;
            }

            // New checkout must not be in the past
            $todayDt = new DateTime('today');
            if ($newCheckoutDt < $todayDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date cannot be in the past']);
                exit;
            }

            // Recalculate nights and total
            $newNights = (int)$newCheckoutDt->diff($checkInDt)->days;
            if ($newNights < 1) $newNights = 1;

            // Atomicity: lock the room-type row, then run the conflict check + update in
            // one transaction so a concurrent booking or extend can't slip into the newly
            // extended nights between the check and the write (overbooking).
            $pdo->beginTransaction();

            // Shared stay-change gate (booking, room-type and room locks; only the extra nights are
            // checked): the old test treated ANY other booking of the room type as a conflict even
            // when plenty of rooms were free, and ignored blocks and maintenance on the guest's room.
            $extGate = rh_validate_booking_stay_change($booking_id, (string)$bk['check_in_date'], $new_checkout);
            if (!$extGate['ok']) {
                $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Cannot extend: ' . $extGate['error'], 'conflicts' => $extGate['conflicts']]);
                exit;
            }

            // Reprice at the guest's BOOKED nightly rate (not today's catalogue rate); VAT and
            // levy are extracted from the gross, never added on top. Re-read the booking under
            // its row lock so the booked figures are current.
            $lockBk = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $lockBk->execute([$booking_id]);
            $bk = $lockBk->fetch(PDO::FETCH_ASSOC);
            if (!$bk || $bk['status'] !== 'checked-in') {
                $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }
            // RP-3: a length-of-stay / weekend / seasonal discount the longer stay no longer qualifies for is removed.
            $extDrop = rh_rate_plan_dropped($pdo, $bk, (string)$bk['check_in_date'], $new_checkout, $newNights, (int)$bk['room_id']);
            $rpExt = rh_reprice_at_booked_rate($bk, $newNights, (float)$extDrop['per_night']);
            rh_write_booking_stay_totals($pdo, $booking_id, $rpExt['totals'], $newNights, (string)$bk['check_in_date'], $new_checkout, $rpExt['new_child_supplement']);
            if (!empty($extDrop['removed'])) {
                rh_rate_plan_drop_clear($pdo, $booking_id);
            }
            $newTotal = $rpExt['new_gross'];

            // Recalculate amount_due so it reflects the new total correctly
            recalculateBookingFinancials($booking_id);
            // History old -> new (owner 2026-11-13, L3): dates, nights, room total and balance, in the same transaction.
            $extAfterSt = $pdo->prepare("SELECT check_out_date, number_of_nights, total_with_vat, amount_due FROM bookings WHERE id = ?");
            $extAfterSt->execute([$booking_id]);
            $extAfter = $extAfterSt->fetch(PDO::FETCH_ASSOC) ?: [];
            $extBefore = ['check_out_date' => substr((string)$bk['check_out_date'], 0, 10), 'number_of_nights' => (int)$bk['number_of_nights'],
                'total_with_vat' => round((float)$bk['total_with_vat'], 2), 'amount_due' => round((float)$bk['amount_due'], 2)];
            $extAfterVals = ['check_out_date' => substr((string)($extAfter['check_out_date'] ?? $new_checkout), 0, 10), 'number_of_nights' => (int)($extAfter['number_of_nights'] ?? $newNights),
                'total_with_vat' => round((float)($extAfter['total_with_vat'] ?? $newTotal), 2), 'amount_due' => round((float)($extAfter['amount_due'] ?? 0), 2)];
            $extDescribe = 'Stay extended: check-out ' . $extBefore['check_out_date'] . ' -> ' . $extAfterVals['check_out_date'] . ', '
                . $extBefore['number_of_nights'] . ' -> ' . $extAfterVals['number_of_nights'] . ' night(s). Room total ' . $currency_symbol . ' '
                . number_format($extBefore['total_with_vat'], 2) . ' -> ' . $currency_symbol . ' ' . number_format($extAfterVals['total_with_vat'], 2)
                . '. Balance ' . $currency_symbol . ' ' . number_format($extBefore['amount_due'], 2) . ' -> ' . $currency_symbol . ' ' . number_format($extAfterVals['amount_due'], 2) . '.';
            logBookingAudit($booking_id, 'stay_extended', $extBefore, $extAfterVals, $extDescribe, $bk['booking_reference'] ?? null);
            logBookingEvent((int)$booking_id, (string)$bk['booking_reference'], 'Stay extended', 'update', $extDescribe,
                $extBefore['check_out_date'], $extAfterVals['check_out_date'], 'admin', $user['id'] ?? null,
                $user['full_name'] ?? ($user['username'] ?? null), ['old' => $extBefore, 'new' => $extAfterVals]);
            $pdo->commit();
            rh_log_event('bookings', 'info', 'Stay extended', ['booking_id' => $booking_id, 'ref' => $bk['booking_reference'], 'old' => $extBefore, 'new' => $extAfterVals, 'by' => $user['username'] ?? null]);

            // Log the extension (never fatal — must not undo the successful update)
            bookings_log_status_change(
                $pdo,
                $booking_id,
                'checked-in',
                'checked-in',
                $user['id'] ?? null,
                "Stay extended from {$oldCheckout} to {$new_checkout}. New total: K " . number_format($newTotal, 2)
            );

            rh_rate_plan_drop_log($booking_id, $bk['booking_reference'] ?? null, $extDrop, (float)rh_booked_stay_split($bk)['per_night'],
                (float)$rpExt['per_night'], (float)$rpExt['old_gross'], (float)$rpExt['new_gross'], (string)($user['full_name'] ?? ($user['username'] ?? '')));

            $extLine = rh_booked_rate_change_for_booking($bk, $rpExt, $newNights, ['currency' => $currency_symbol]);
            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'message'      => 'Stay extended successfully to ' . date('M j, Y', strtotime($new_checkout))
                    . ($extLine['ok'] ? (!empty($extDrop['removed']) ? '. Room: ' : '. Room at the booked rate: ') . $extLine['text'] . '.' : '')
                    . (!empty($extDrop['removed']) ? ' ' . rh_rate_plan_drop_sentence($extDrop) : ''),
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights,
                'new_total'    => number_format($newTotal, 2)
            ]);
            exit;
        } elseif ($action === 'admin_update_checkout_date') {
            // ── Admin-only: manually correct a checked-in booking's checkout date ──
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!$_is_admin_user) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Administrator access required']);
                exit;
            }
            $booking_id    = (int)($_POST['booking_id'] ?? 0);
            $new_checkout  = trim($_POST['new_checkout'] ?? '');
            $change_reason = trim($_POST['reason'] ?? '');

            if ($booking_id <= 0 || !$new_checkout) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            $newCoDt = DateTime::createFromFormat('Y-m-d', $new_checkout);
            if (!$newCoDt) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit;
            }

            $acd_stmt = $pdo->prepare("SELECT b.*, r.name as room_type_name, r.price_per_night, r.price_single_occupancy, r.price_double_occupancy, r.price_triple_occupancy FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ? AND b.status = 'checked-in'");
            $acd_stmt->execute([$booking_id]);
            $acd_bk = $acd_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$acd_bk) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Booking not found or not checked-in']);
                exit;
            }

            $checkInDt_acd = new DateTime($acd_bk['check_in_date']);
            if ($newCoDt <= $checkInDt_acd) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'New checkout date must be after check-in date']);
                exit;
            }

            $newNights_acd = max(1, (int)$newCoDt->diff($checkInDt_acd)->days);

            $oldCheckout_acd = $acd_bk['check_out_date'];

            // Reprice at the BOOKED nightly rate; VAT/levy extracted from the gross (never
            // added on top). Booking row locked and re-read inside the transaction.
            $pdo->beginTransaction();
            try {
                $lockAcd = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
                $lockAcd->execute([$booking_id]);
                $acd_locked = $lockAcd->fetch(PDO::FETCH_ASSOC);
                if (!$acd_locked || $acd_locked['status'] !== 'checked-in') {
                    throw new Exception('Booking not found or not checked-in');
                }
                $acdGate = rh_validate_booking_stay_change($booking_id, (string)$acd_locked['check_in_date'], $new_checkout);
                if (!$acdGate['ok']) {
                    throw new Exception($acdGate['error']);
                }
                // RP-3: a length-of-stay / weekend / seasonal discount the new stay no longer qualifies for is removed.
                $acdDrop = rh_rate_plan_dropped($pdo, $acd_locked, (string)$acd_locked['check_in_date'], $new_checkout, $newNights_acd, (int)$acd_locked['room_id']);
                $rpAcd = rh_reprice_at_booked_rate($acd_locked, $newNights_acd, (float)$acdDrop['per_night']);
                rh_write_booking_stay_totals($pdo, $booking_id, $rpAcd['totals'], $newNights_acd, (string)$acd_locked['check_in_date'], $new_checkout, $rpAcd['new_child_supplement']);
                if (!empty($acdDrop['removed'])) {
                    rh_rate_plan_drop_clear($pdo, $booking_id);
                }
                recalculateBookingFinancials($booking_id);
                // L1 (2026-11-13): a shorter stay must never leave paid money sitting on the booking as credit
                // ("overpayment lives in the credit note only"). When this change would give money back, nothing is
                // saved: staff use "Add / take off nights" (refund or credit note, amount retyped) or Check out & settle.
                $acdCredit = $pdo->prepare("SELECT credit_balance FROM bookings WHERE id = ?");
                $acdCredit->execute([$booking_id]);
                $acdCreditAfter = round((float)$acdCredit->fetchColumn(), 2);
                if ($acdCreditAfter > round(max(0.0, (float)($acd_locked['credit_balance'] ?? 0)), 2) + BALANCE_TOLERANCE) {
                    throw new Exception('This shorter stay would leave ' . $currency_symbol . ' ' . number_format($acdCreditAfter, 2)
                        . ' paid that the guest no longer owes. Nothing was changed. Use "Add / take off nights" (refund or credit note), or Check out & settle if the guest is leaving today.');
                }
                $pdo->commit();
            } catch (Throwable $acdEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $acdEx->getMessage()]);
                exit;
            }
            $newTotal_acd     = $rpAcd['new_gross'];
            $totalWithVat_acd = $rpAcd['new_gross'];

            $logReason = "Admin checkout date change: {$oldCheckout_acd} → {$new_checkout} ({$newNights_acd} nights)"
                . ($change_reason ? '. Reason: ' . $change_reason : '');
            bookings_log_status_change($pdo, $booking_id, 'checked-in', 'checked-in', $user['id'] ?? null, $logReason);

            rh_log_event('bookings', 'warning', 'Admin changed checkout date', [
                'booking_id'   => $booking_id,
                'ref'          => $acd_bk['booking_reference'],
                'old_checkout' => $oldCheckout_acd,
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights_acd,
                'new_total'    => $newTotal_acd,
                'reason'       => $change_reason,
                'by'           => $user['username'] ?? null,
            ]);

            rh_rate_plan_drop_log($booking_id, $acd_bk['booking_reference'] ?? null, $acdDrop, (float)rh_booked_stay_split($acd_locked)['per_night'],
                (float)$rpAcd['per_night'], (float)$rpAcd['old_gross'], (float)$rpAcd['new_gross'], (string)($user['full_name'] ?? ($user['username'] ?? '')));

            $acdLine = rh_booked_rate_change_for_booking($acd_locked, $rpAcd, $newNights_acd, ['currency' => $currency_symbol]);
            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'message'      => 'Checkout date updated to ' . date('M j, Y', strtotime($new_checkout)) . ". ({$newNights_acd} nights, total recalculated"
                    . ($acdLine['ok'] ? (!empty($acdDrop['removed']) ? ': ' : ' at the booked rate: ') . $acdLine['text'] : '') . '.)'
                    . (!empty($acdDrop['removed']) ? ' ' . rh_rate_plan_drop_sentence($acdDrop) : ''),
                'new_checkout' => $new_checkout,
                'new_nights'   => $newNights_acd,
                'new_total'    => number_format($totalWithVat_acd, 2),
            ]);
            exit;
        } elseif ($action === 'upgrade_room_type') {
            // Paid upgrade / complimentary upgrade / downgrade (includes/booking-room-change.php).
            if (!isAjaxRequest()) {
                throw new Exception('Invalid request');
            }
            if (!hasPermission((int)($user['id'] ?? 0), 'edit_booking')) {
                throw new Exception('You do not have permission to change a booking room type.');
            }
            $res = rh_apply_room_type_change(
                $pdo,
                (int)($_POST['booking_id'] ?? 0),
                (int)($_POST['new_room_id'] ?? 0),
                (string)($_POST['pricing'] ?? 'charge'),
                (string)($_POST['reason_code'] ?? ''),
                (string)($_POST['note'] ?? ''),
                $user,
                (bool)$_perm_edit_financials || $_is_admin_user
            );
            if ($res['success'] && ($_POST['send_email'] ?? '') === '1') {
                require_once '../config/email.php';
                $d = $res['data'];
                $mail = sendBookingRoomUpgradeEmail(array_merge($d['booking'], [
                    'old_room_name' => $d['old_room_name'], 'new_room_name' => $d['new_room_name'],
                    'old_total' => $d['old_total'], 'new_total' => $d['new_total'], 'price_difference' => $d['price_difference'],
                    'change_direction' => $d['direction'], 'change_pricing' => $d['pricing'],
                ]));
                $res['message'] .= !empty($mail['success']) ? ' Guest emailed.' : ' (Email not sent: ' . ($mail['message'] ?? 'error') . ')';
            }
            unset($res['data']);
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            // Return 200 OK with success: false so frontend can handle the error message gracefully
            // without browser console errors or fetch rejection
            http_response_code(200);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $error = 'Error: ' . $e->getMessage();
    }

    // An AJAX action that did not answer in JSON itself (some branches only set $message / $error and fall
    // through to the page render): answer with JSON now, so the browser gets a real success or failure
    // instead of the whole page (which used to be mistaken for "done").
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        // Success only when a handler actually reported one: an unknown action, or one that returned
        // early without a message, did nothing and must say so.
        if (!empty($error)) {
            echo json_encode(['success' => false, 'message' => (string)$error]);
        } elseif ((string)($message ?? '') !== '') {
            echo json_encode(['success' => true, 'message' => (string)$message]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Nothing was changed. Please reload the page and try again.']);
        }
        exit;
    }
}

// Shared filter inputs (used by on-screen table and CSV export)
$search_query = trim($_GET['search'] ?? '');
// Accept ?status= as an alias for ?filter_status= so dashboard insight cards and
// stat tiles that deep-link with ?status=checked-in land on the matching tab
// (server-filtered + tab activated + row flash), not just the unfiltered list.
$filter_status = $_GET['filter_status'] ?? ($_GET['status'] ?? '');
// From / To on the date chosen in Dates (?date_type= arrival|departure|staying|booked, default arrival), both end days
// included; invalid dates are ignored, From after To is swapped. Rules: rh_bk_date_scope (bookings-list-filters.php).
[$filter_date_from, $filter_date_to] = rh_bk_date_range($_GET['date_from'] ?? '', $_GET['date_to'] ?? '');
$filter_date_type = rh_bk_date_type($_GET['date_type'] ?? '');
$bk_has_dates = $filter_date_from !== '' || $filter_date_to !== '';
// ?arrival=overdue (Room Dashboard / Dashboard "overdue arrivals" links): a real filter, shown in the notice with Clear.
$filter_arrival = rh_bk_arrival_filter($_GET['arrival'] ?? '');
// ?departure=overdue (Dashboard "Overdue departures" chip): still checked in after the check-out date; same notice + Clear.
$filter_departure = rh_bk_departure_filter($_GET['departure'] ?? '');
// ?balance=owing (Dashboard "Departures with a balance" chip, with ?filter=checkout_today): amount due above
// BALANCE_TOLERANCE, the chip's own rule (rh_bk_balance_clause -> rh_fd_balance_tolerance); same notice + Clear.
$filter_balance = rh_bk_balance_filter($_GET['balance'] ?? '');

// Deep links from booking-details.php (?action=assign-room|room-change&booking_id=N): narrow the list to
// that booking so its row is on screen, then the page script opens the matching modal. When the action
// finishes or is cancelled the page returns to the booking (see BK_DEEP_RETURN_URL), so the list is
// never left filtered down to one booking and a reload never re-opens the modal.
$deep_link_action = in_array(($_GET['action'] ?? ''), ['assign-room', 'room-change', 'checkout', 'checkin'], true) ? (string)$_GET['action'] : '';
$deep_link_booking_id = $deep_link_action !== '' ? (int)($_GET['booking_id'] ?? 0) : 0;
if ($deep_link_booking_id > 0 && $search_query === '') {
    $dlSt = $pdo->prepare("SELECT booking_reference, status FROM bookings WHERE id = ? AND deleted_at IS NULL");
    $dlSt->execute([$deep_link_booking_id]);
    $dlRow = $dlSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $search_query = (string)($dlRow['booking_reference'] ?? '');
    if ($deep_link_action === 'assign-room' && $search_query !== '' && !in_array((string)$dlRow['status'], ['confirmed', 'checked-in'], true)) {
        $_SESSION['error_message'] = 'A room can only be assigned to a confirmed or in-house booking (this one is ' . str_replace('-', ' ', (string)$dlRow['status']) . ').';
        header('Location: booking-details.php?id=' . $deep_link_booking_id);
        exit;
    }
    if ($search_query === '') {
        $deep_link_action = '';
        $deep_link_booking_id = 0;
    }
}
$has_active_room_filters = $search_query !== '' || $filter_status !== '' || $filter_date_from !== '' || $filter_date_to !== '' || $filter_arrival !== '' || $filter_departure !== '' || $filter_balance !== '';

// Remember this list view (filters, tab, page) so "Back" on a booking returns to it.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['export']) && $deep_link_action === '') {
    $listQuery = $_GET;
    unset($listQuery['action'], $listQuery['booking_id'], $listQuery['export']);
    $_SESSION['bookings_list_return'] = 'bookings.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');
}

// Map ?filter= shortcuts (from dashboard badge links) to JS tab names; ?payment_status=paid|unpaid (Accounting
// Dashboard links) opens the matching tab.
$bk_filter_key = rh_bk_filter_key($_GET['filter'] ?? '', $_GET['payment_status'] ?? '');
$active_tab_override = $bk_filter_key !== '' ? rh_bk_tab_map()[$bk_filter_key] : '';
// Status-based tabs: detect from filter_status (tab names match filter_status values directly)
if ($active_tab_override === '' && $filter_status !== '') {
    $active_tab_override = $filter_status;
}

// Pagination params
$current_page = max(1, (int)($_GET['page'] ?? 1));
// Rows per page (?size= 10/25/50/100/all, default 10); "all" is settled once the list is counted (below).
$bk_page_size = rh_bk_page_size($_GET['size'] ?? '10');
$per_page     = $bk_page_size === 'all' ? 10 : (int)$bk_page_size;

// Header sort runs on the server (the whole list, then page 1): fixed key -> ORDER BY map.
require_once __DIR__ . '/includes/server-sort.php';
// Total sorts by the total the column shows; Room by the room number (else type name), natural order.
$bk_sort_map = rh_bk_sort_map();
$bk_sort_key = '';
$bk_sort_dir = 'desc';

// Pre-initialise stats in case the main query throws
$total_bookings = $pending = $tentative = $confirmed = $checked_in = 0;
$checked_out = $cancelled = $no_show = $paid = $unpaid = 0;
$today_checkins = $today_checkouts = $today_bookings = 0;
$week_bookings = $month_bookings = $expiring_soon = 0;
$list_count = 0;
$deleted_count = 0;
$current_page_count = 0;
$total_pages = 1;
$offset      = 0;
$bookings_stats_insights = [];

// Handle CSV export (filter-aware)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $export_where_clauses = [];
        $export_params = [];

        // Same search rule as the list (rh_bk_search_where: every word matches somewhere, phone numbers by digits).
        $export_search = rh_bk_search_where($search_query);
        if ($export_search['sql'] !== '') {
            $export_where_clauses[] = $export_search['sql'];
            $export_params = array_merge($export_params, $export_search['params']);
        }

        $export_where_clauses[] = $filter_status === 'deleted' ? "b.deleted_at IS NOT NULL" : "b.deleted_at IS NULL";
        if (!empty($filter_status) && $filter_status !== 'deleted') {
            $export_where_clauses[] = "b.status = ?";
            $export_params[] = $filter_status;
        }

        // Same rules as the on-screen list: dates (on the chosen date type), overdue arrivals, the open tab.
        $export_dates = rh_bk_date_scope($filter_date_from, $filter_date_to, $filter_date_type);
        $export_where_clauses = array_merge($export_where_clauses, $export_dates['clauses']);
        $export_params = array_merge($export_params, $export_dates['params']);
        if (($export_arrival = rh_bk_arrival_clause($filter_arrival)) !== null) {
            $export_where_clauses[] = $export_arrival;
        }
        if (($export_departure = rh_bk_departure_clause($filter_departure)) !== null) {
            $export_where_clauses[] = $export_departure;
        }
        if (($export_balance = rh_bk_balance_clause($filter_balance)) !== null) {
            $export_where_clauses[] = $export_balance['clause'];
            $export_params = array_merge($export_params, $export_balance['params']);
        }
        $export_where_clauses = array_merge($export_where_clauses, rh_bk_tab_clauses($active_tab_override)['clauses']);

        $export_where_sql = !empty($export_where_clauses) ? 'WHERE ' . implode(' AND ', $export_where_clauses) : '';

        $export_stmt = $pdo->prepare("
                        SELECT b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.guest_country,
                                     r.name as room_name, ir.room_number as individual_room_number, ir.room_name as individual_room_name,
                                     b.check_in_date, b.check_out_date, b.number_of_nights,
                                     b.number_of_guests, b.total_amount, b.total_with_vat, b.vat_amount, b.status, b.payment_status, b.occupancy_type,
                                     b.special_requests, b.created_at,
                                     (
                                         SELECT px.payment_reference
                                         FROM payments px
                                         WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                                         ORDER BY px.created_at DESC
                                         LIMIT 1
                                     ) as payment_reference
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
                        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
                        {$export_where_sql}
            ORDER BY b.created_at DESC
        ");
        $export_stmt->execute($export_params);
        $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bookings-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Reference',
            'Guest Name',
            'Email',
            'Phone',
            'Country',
            'Room',
            'Room Number',
            'Room Unit Name',
            'Check-in',
            'Check-out',
            'Nights',
            'Guests',
            'Total (incl. VAT & levy)',
            'Status',
            'Payment',
            'Payment Ref',
            'Occupancy',
            'Special Requests',
            'Created'
        ]);

        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['booking_reference'],
                $row['guest_name'],
                $row['guest_email'],
                $row['guest_phone'],
                $row['guest_country'],
                $row['room_name'],
                $row['individual_room_number'],
                $row['individual_room_name'],
                $row['check_in_date'],
                $row['check_out_date'],
                $row['number_of_nights'],
                $row['number_of_guests'],
                number_format(rh_booking_full_total($row), 2, '.', ''),
                $row['status'],
                $row['payment_status'],
                $row['payment_reference'],
                $row['occupancy_type'],
                $row['special_requests'],
                $row['created_at']
            ]);
        }

        fclose($output);
        exit;
    } catch (PDOException $e) {
        $error = 'Export failed: ' . $e->getMessage();
    }
}

// ─── Auto-expire tentative bookings whose deadline has passed ────────────
$page_expired_count = 0;
try {
    $expired_list = getExpiredTentativeBookings();
    foreach ($expired_list as $eb) {
        if (markTentativeBookingExpired((int)$eb['id'])) {
            $page_expired_count++;
        }
    }
    if ($page_expired_count > 0) {
        rh_log_event(
            'bookings_page',
            'info',
            "Auto-expired {$page_expired_count} tentative booking(s) on page load",
            []
        );
    }
} catch (Throwable $e) {
    error_log("Tentative sweep on bookings page failed: " . $e->getMessage());
}

// Fetch all bookings with room details and payment status from payments table
try {
    $where_clauses = [];
    $params = [];

    // Search box: every word matches somewhere, a phone number matches by its digits (rh_bk_search_where, shared
    // with the export so both give the same rows).
    $bk_search = rh_bk_search_where($search_query);
    if ($bk_search['sql'] !== '') {
        $where_clauses[] = $bk_search['sql'];
        $params = array_merge($params, $bk_search['params']);
    }

    // Date filters belong to the shared scope (search + dates) so the tab counts and insight cards
    // match the list; they were added after the snapshot, so the counts ignored the dates.
    // From / To on the chosen date type (rh_bk_date_scope), the same rule in badges, list, count, insights and export.
    $bk_date_scope = rh_bk_date_scope($filter_date_from, $filter_date_to, $filter_date_type);
    $where_clauses = array_merge($where_clauses, $bk_date_scope['clauses']);
    $params        = array_merge($params, $bk_date_scope['params']);
    // Overdue arrivals (no-show candidates, from the Room Dashboard / Dashboard alert): arrival date passed while
    // still confirmed/pending. Part of the shared scope so every tab badge counts what the list shows.
    if (($bk_arrival_clause = rh_bk_arrival_clause($filter_arrival)) !== null) {
        $where_clauses[] = $bk_arrival_clause;
    }
    // Overdue departures (Dashboard chip): checked in, check-out date passed. Shared scope, like overdue arrivals.
    if (($bk_departure_clause = rh_bk_departure_clause($filter_departure)) !== null) {
        $where_clauses[] = $bk_departure_clause;
    }
    // Balance still to pay (Dashboard "Departures with a balance"): shared scope too, so the badges match the list.
    if (($bk_balance_clause = rh_bk_balance_clause($filter_balance)) !== null) {
        $where_clauses[] = $bk_balance_clause['clause'];
        $params = array_merge($params, $bk_balance_clause['params']);
    }

    // Deleted bookings are only ever shown under the Deleted filter; every count/insight excludes them.
    $deleted_scope_clauses = $where_clauses;
    $deleted_scope_params  = $params;
    $where_clauses[] = $filter_status === 'deleted' ? "b.deleted_at IS NOT NULL" : "b.deleted_at IS NULL";

    // Snapshot where clauses for insights (respects search/date filters but not status filter)
    // so insight cards show filtered data matching the current search/date scope.
    $insights_where_clauses = array_merge($deleted_scope_clauses, ["b.deleted_at IS NULL"]);
    $insights_params        = $deleted_scope_params;

    // Stats badge counts must respect search and date filters so badge counts match displayed results,
    // but exclude status filter so all tabs show accurate counts for the current filters.
    $stats_where_clauses = $insights_where_clauses;
    $stats_params        = $deleted_scope_params;

    if (!empty($filter_status) && $filter_status !== 'deleted') {
        $where_clauses[] = "b.status = ?";
        $params[] = $filter_status;
    }

    $where_sql       = !empty($where_clauses)       ? 'WHERE ' . implode(' AND ', $where_clauses)       : '';
    $stats_where_sql = !empty($stats_where_clauses) ? 'WHERE ' . implode(' AND ', $stats_where_clauses) : '';

    // ── Stats: single query for all tab-badge counts (no payments join to avoid row duplication) ──
    // Uses $stats_where_sql which includes search/date filters but excludes filter_status so badges
    // show accurate counts for the current search/date scope regardless of which tab is active.
    // Each tab badge is SUM(<the tab's own list WHERE pieces>), so a badge and its tab can never disagree.
    // The pieces are fixed SQL from rh_bk_tab_clauses() (no user input).
    $bk_badge_sum = static function (string $tab): string {
        return 'SUM(' . implode(' AND ', rh_bk_tab_clauses($tab)['clauses']) . ')';
    };
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                                                      AS total,
            SUM(b.status = 'pending')                                                                     AS pending,
            SUM(b.status = 'tentative')                                                                  AS tentative,
            SUM(b.status = 'confirmed')                                                                   AS confirmed,
            SUM(b.status = 'checked-in')                                                                  AS checked_in,
            SUM(b.status = 'checked-out')                                                                 AS checked_out,
            SUM(b.status = 'cancelled')                                                                   AS cancelled,
            SUM(b.status = 'no-show')                                                                     AS no_show,
            {$bk_badge_sum('paid')}                                                                       AS paid,
            {$bk_badge_sum('unpaid')}                                                                     AS unpaid,
            {$bk_badge_sum('today-checkins')}                                                             AS today_checkins,
            {$bk_badge_sum('today-checkouts')}                                                            AS today_checkouts,
            {$bk_badge_sum('today-bookings')}                                                             AS today_bookings,
            {$bk_badge_sum('week-bookings')}                                                              AS week_bookings,
            {$bk_badge_sum('month-bookings')}                                                             AS month_bookings,
            {$bk_badge_sum('expiring-soon')}                                                              AS expiring_soon
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        {$stats_where_sql}
    ");
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $deleted_scope_sql = 'WHERE ' . implode(' AND ', array_merge($deleted_scope_clauses, ["b.deleted_at IS NOT NULL"]));
    $deleted_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id {$deleted_scope_sql}");
    $deleted_count_stmt->execute($deleted_scope_params);
    $deleted_count = (int)$deleted_count_stmt->fetchColumn();

    $total_bookings  = (int)($stats['total']          ?? 0);
    $pending         = (int)($stats['pending']         ?? 0);
    $tentative       = (int)($stats['tentative']       ?? 0);
    $confirmed       = (int)($stats['confirmed']       ?? 0);
    $checked_in      = (int)($stats['checked_in']      ?? 0);
    $checked_out     = (int)($stats['checked_out']     ?? 0);
    $cancelled       = (int)($stats['cancelled']       ?? 0);
    $no_show         = (int)($stats['no_show']         ?? 0);
    $paid            = (int)($stats['paid']            ?? 0);
    $unpaid          = (int)($stats['unpaid']          ?? 0);
    $today_checkins  = (int)($stats['today_checkins']  ?? 0);
    $today_checkouts = (int)($stats['today_checkouts'] ?? 0);
    $today_bookings  = (int)($stats['today_bookings']  ?? 0);
    $week_bookings   = (int)($stats['week_bookings']   ?? 0);
    $month_bookings  = (int)($stats['month_bookings']  ?? 0);
    $expiring_soon   = (int)($stats['expiring_soon']   ?? 0);

    $bookings_stats_insight_limit = 8;

    $build_bookings_stats_link = static function (array $query_params): string {
        $query_string = http_build_query($query_params);
        return 'bookings.php' . ($query_string !== '' ? '?' . $query_string : '');
    };

    $map_bookings_stats_row = static function (array $row): array {
        $individual_room_name = trim((string)($row['individual_room_name'] ?? ''));
        $individual_room_number = trim((string)($row['individual_room_number'] ?? ''));
        $room_name = trim((string)($row['room_name'] ?? ''));

        if ($individual_room_name !== '' && $individual_room_number !== '') {
            $room_label = $individual_room_name . ' (' . $individual_room_number . ')';
        } elseif ($individual_room_name !== '') {
            $room_label = $individual_room_name;
        } elseif ($individual_room_number !== '') {
            $room_label = 'Room ' . $individual_room_number;
        } elseif ($room_name !== '') {
            $room_label = $room_name;
        } else {
            $room_label = 'Unassigned';
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'reference' => (string)($row['booking_reference'] ?? ''),
            'guest_name' => (string)($row['guest_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'room' => $room_label,
            'check_in' => (string)($row['check_in_date'] ?? ''),
            'check_out' => (string)($row['check_out_date'] ?? ''),
            'amount' => rh_booking_full_total($row),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'details_href' => 'booking-details.php?id=' . (int)($row['id'] ?? 0),
        ];
    };

    $fetch_bookings_stats_rows = static function (array $extra_clauses, array $extra_params, string $order_by) use ($pdo, $insights_where_clauses, $insights_params, $bookings_stats_insight_limit, $map_bookings_stats_row): array {
        $insight_where_clauses = array_merge($insights_where_clauses, $extra_clauses);
        $insight_where_sql = !empty($insight_where_clauses) ? 'WHERE ' . implode(' AND ', $insight_where_clauses) : '';
        $insight_params = array_merge($insights_params, $extra_params);

        $insight_stmt = $pdo->prepare("
            SELECT b.id,
                   b.booking_reference,
                   b.guest_name,
                   b.status,
                   b.check_in_date,
                   b.check_out_date,
                   b.total_amount, b.total_with_vat, b.vat_amount,
                   b.payment_status,
                   r.name AS room_name,
                   ir.room_name AS individual_room_name,
                   ir.room_number AS individual_room_number
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            {$insight_where_sql}
            ORDER BY {$order_by}
            LIMIT ?
        ");

        $insight_stmt->execute(array_merge($insight_params, [$bookings_stats_insight_limit]));
        $rows = $insight_stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($map_bookings_stats_row, $rows);
    };

    $insight_base_query = [];
    if ($search_query !== '') {
        $insight_base_query['search'] = $search_query;
    }
    if ($filter_date_from !== '') {
        $insight_base_query['date_from'] = $filter_date_from;
    }
    if ($filter_date_to !== '') {
        $insight_base_query['date_to'] = $filter_date_to;
    }
    if ($bk_has_dates) {
        $insight_base_query['date_type'] = $filter_date_type; // the cards' links keep which date From / To apply to
    }
    if ($filter_arrival !== '') {
        $insight_base_query['arrival'] = $filter_arrival; // the cards' rows are in the same overdue scope
    }
    if ($filter_departure !== '') {
        $insight_base_query['departure'] = $filter_departure;
    }
    if ($filter_balance !== '') {
        $insight_base_query['balance'] = $filter_balance;
    }

    $bookings_stats_rows = [
        'total_bookings' => $fetch_bookings_stats_rows([], [], 'b.created_at DESC'),
        'pending' => $fetch_bookings_stats_rows(["b.status = ?"], ['pending'], 'b.check_in_date ASC, b.created_at DESC'),
        'tentative' => $fetch_bookings_stats_rows(["b.status = ?"], ['tentative'], "COALESCE(b.tentative_expires_at, '9999-12-31 23:59:59') ASC, b.created_at DESC"),
        'confirmed' => $fetch_bookings_stats_rows(["b.status = ?"], ['confirmed'], 'b.check_in_date ASC, b.created_at DESC'),
        'checked_in' => $fetch_bookings_stats_rows(["b.status = ?"], ['checked-in'], 'b.check_out_date ASC, b.created_at DESC'),
    ];

    $bookings_stats_insights = [
        'total_bookings' => [
            'title' => 'Total Bookings',
            'subtitle' => 'Latest bookings in the current view scope.',
            'count' => $total_bookings,
            'summary' => 'Shows newest reservations so operations can triage active booking flow quickly.',
            'empty' => 'No bookings found in the current search/date scope.',
            'rows' => $bookings_stats_rows['total_bookings'],
            'link' => [
                'href' => $build_bookings_stats_link($insight_base_query),
                'label' => 'Open bookings list',
            ],
        ],
        'pending' => [
            'title' => 'Pending Bookings',
            'subtitle' => 'Bookings waiting for confirmation or follow-up.',
            'count' => $pending,
            'summary' => 'Prioritize these guests to avoid drop-offs and improve conversion.',
            'empty' => 'No pending bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['pending'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'pending'])),
                'label' => 'Open pending bookings',
            ],
        ],
        'tentative' => [
            'title' => 'Tentative Bookings',
            'subtitle' => 'Tentative reservations with expiry risk.',
            'count' => $tentative,
            'summary' => 'Review tentative bookings and secure payment before they expire.',
            'empty' => 'No tentative bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['tentative'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'tentative'])),
                'label' => 'Open tentative bookings',
            ],
        ],
        'confirmed' => [
            'title' => 'Confirmed Bookings',
            'subtitle' => 'Arrivals expected to check in soon.',
            'count' => $confirmed,
            'summary' => 'Use this queue for pre-arrival preparation and room readiness checks.',
            'empty' => 'No confirmed bookings in the current search/date scope.',
            'rows' => $bookings_stats_rows['confirmed'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'confirmed'])),
                'label' => 'Open confirmed bookings',
            ],
        ],
        'checked_in' => [
            'title' => 'Checked-In Guests',
            'subtitle' => 'Guests currently staying in-house.',
            'count' => $checked_in,
            'summary' => 'Track in-house guests and upcoming checkouts from one focused list.',
            'empty' => 'No checked-in guests in the current search/date scope.',
            'rows' => $bookings_stats_rows['checked_in'],
            'link' => [
                'href' => $build_bookings_stats_link(array_merge($insight_base_query, ['filter_status' => 'checked-in'])),
                'label' => 'Open checked-in guests',
            ],
        ],
    ];

    // Build list-specific WHERE and ORDER BY — server-side constraints for all tab shortcuts.
    // Stats query above stays unfiltered so badge counts remain accurate.
    // Tab shortcuts: the same WHERE pieces as the badge SUMs above (rh_bk_tab_clauses).
    $bk_tab_rule = rh_bk_tab_clauses($active_tab_override);
    $tab_extra_clauses = $bk_tab_rule['clauses'];
    $list_order_by = $bk_tab_rule['order'] ?? 'b.created_at DESC';
    // Status-based tabs (pending, tentative, confirmed, checked-in, checked-out, cancelled, no-show)
    // are already handled by $filter_status → $where_clauses — no extra clauses needed.

    [$bk_sort_order, $bk_sort_key, $bk_sort_dir] = rh_server_sort_resolve($bk_sort_map, $list_order_by);
    if ($bk_sort_key !== '') {
        $list_order_by = $bk_sort_key === 'room' ? rh_bk_room_sort_order($bk_sort_dir) : $bk_sort_order;
    }

    $list_where_clauses = array_merge($where_clauses, $tab_extra_clauses);
    $list_where_sql = !empty($list_where_clauses) ? 'WHERE ' . implode(' AND ', $list_where_clauses) : '';

    // Re-count for pagination when the list is filtered by tab override, status filter, search, or date filters.
    // Use a separate variable so $total_bookings remains the global count for the "All" tab badge.
    $list_count = $total_bookings;
    if (!empty($tab_extra_clauses) || $filter_status !== '' || $search_query !== '' || $filter_date_from !== '' || $filter_date_to !== '' || $filter_arrival !== '' || $filter_departure !== '' || $filter_balance !== '') {
        $cnt_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            {$list_where_sql}
        ");
        $cnt_stmt->execute($params);
        $list_count = (int)$cnt_stmt->fetchColumn();
    }

    // Rows per page: "All" shows every row while the list has at most RH_BK_ALL_ROWS_MAX, else 100 a page.
    $bk_size_resolved = rh_bk_resolve_page_size($bk_page_size, $list_count);
    $bk_page_size = $bk_size_resolved['size'];
    $per_page     = $bk_size_resolved['per_page'];

    // Clamp current page to valid range and compute offset
    $total_pages  = $list_count > 0 ? (int)ceil($list_count / $per_page) : 1;
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT b.*,
               r.name as room_name,
               COALESCE(
                   (SELECT px.payment_status FROM payments px
                    WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                    ORDER BY px.created_at DESC LIMIT 1),
                   b.payment_status
               ) as actual_payment_status,
               (SELECT px.payment_reference FROM payments px
                WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                ORDER BY px.created_at DESC, px.id DESC LIMIT 1) as payment_reference,
               (SELECT px.id FROM payments px
                WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                ORDER BY px.created_at DESC, px.id DESC LIMIT 1) as latest_payment_id,
               (SELECT px.payment_date FROM payments px
                WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                ORDER BY px.created_at DESC LIMIT 1) as last_payment_date,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               ir.floor as individual_room_floor,
               ir.status as individual_room_status,
               rt.name as room_type_name,
               COALESCE(NULLIF(du.full_name, ''), du.username) as deleted_by_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        LEFT JOIN rooms rt ON ir.room_type_id = rt.id
        LEFT JOIN admin_users du ON du.id = b.deleted_by
        {$list_where_sql}
        ORDER BY {$list_order_by}, b.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_page_count = count($bookings);
    // Who created each booking on this page: one read-only query for the page's ids (never one per row).
    $bk_created_by = rh_created_by_map($pdo, array_column($bookings, 'id'));
    // Company pays later: who owes each on-account row on this page, in one batch (never queries per row).
    $bk_bill_splits = rh_billing_ready($pdo) ? rh_billing_splits_for_rows($pdo, array_values(array_filter($bookings, static function ($r) {
        return (int)($r['billing_account_id'] ?? 0) > 0 || (int)($r['primary_booking_id'] ?? 0) > 0;
    }))) : [];

    // Latest conference enquiries & bookings (one table holds both). Only when the person can open the
    // Conference page (permission + module); the full list, quotes and payments live there.
    $conference_inquiries = [];
    $conference_total = 0;
    if (!function_exists('isConferenceEnabled')) {
        require_once __DIR__ . '/../includes/booking-functions.php';
    }
    // Hidden when the Conference module is off (admins included), else needs the Conference page.
    $show_conference_block = isConferenceEnabled()
        && function_exists('rhCanOpenPage') && rhCanOpenPage((int)($user['id'] ?? 0), 'conference-management.php');
    if ($show_conference_block) {
        $conference_total = (int)$pdo->query("SELECT COUNT(*) FROM conference_inquiries")->fetchColumn();
        $conference_inquiries = $pdo->query("
            SELECT * FROM conference_inquiries
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = 'Error fetching bookings: ' . $e->getMessage();
    $bookings = [];
    $conference_inquiries = [];
    $bookings_stats_insights = [];
}

// Statistics are computed by the DB stats query inside the main try block above.

// ─── Missed check-ins: confirmed + pending bookings whose check-in date has PASSED (same as the room dashboard) ───
$missed_checkins = [];
try {
    $missed_stmt = $pdo->query("
        SELECT b.*, r.name as room_name,
               COALESCE(
                   (SELECT px.payment_status
                    FROM payments px
                    WHERE px.booking_type = 'room' AND px.booking_id = b.id AND px.deleted_at IS NULL
                    ORDER BY px.created_at DESC
                    LIMIT 1),
                   b.payment_status
               ) AS actual_payment_status,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               DATEDIFF(CURDATE(), b.check_in_date) as days_overdue
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        WHERE b.status IN ('confirmed', 'pending')
          AND b.check_in_date < CURDATE()
          AND b.deleted_at IS NULL
        ORDER BY b.check_in_date ASC
    ");
    $missed_checkins = $missed_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching missed check-ins: " . $e->getMessage());
}

// ─── Overdue checkouts: checked-in bookings whose checkout date has PASSED (or today and past checkout time) ───
$overdue_checkouts = [];
$is_past_checkout_time = false; // pre-initialised; set inside try block below
try {
    $is_past_checkout_time = rh_checkout_time_passed_today();

    $date_condition = $is_past_checkout_time
        ? "b.check_out_date <= CURDATE()"
        : "b.check_out_date < CURDATE()";

    // Only genuinely checked-in guests can be overdue on checkout. The
    // status = 'checked-in' gate inherently excludes no-show, cancelled and
    // confirmed-but-never-arrived bookings — those are surfaced as missed
    // check-ins above, never as late checkouts. No-show takes precedence over
    // any time-based checkout logic.
    $overdue_stmt = $pdo->query("
        SELECT b.*, r.name as room_name,
               ir.room_number as individual_room_number,
               ir.room_name as individual_room_name,
               DATEDIFF(CURDATE(), b.check_out_date) as days_overdue
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
        WHERE b.status = 'checked-in'
          AND {$date_condition}
          AND b.deleted_at IS NULL
        ORDER BY b.check_out_date ASC
    ");
    $overdue_checkouts = $overdue_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching overdue checkouts: " . $e->getMessage());
}


// $today_str is needed for table row rendering (overdue check-in flag)
$today     = new DateTime();
$today_str = $today->format('Y-m-d');
// $today_checkins, $today_checkouts, $today_bookings, $week_bookings,
// $month_bookings are already set by the DB stats query above.
// Confirm / Convert buttons need Edit Booking (owner 2026-10-19; enforced server-side too).
$bkCanConfirm = hasPermission((int)($user['id'] ?? 0), 'edit_booking');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <title>All Bookings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/bookings.css?v=<?php echo @filemtime(__DIR__ . '/css/bookings.css'); ?>">
</head>

<body>

    <?php require_once 'includes/admin-header.php'; ?>
    <script>
        // CSRF-aware fetch shim — re-installed on every SPA navigation.
        // window._rhCsrf is set by admin-header.php inside #rh-admin-page so it is
        // always the current session token, even after SPA navigation from a page
        // that does not have its own fetch interceptor (e.g. dashboard.php).
        (function() {
            if (!window.__rhBkOrigFetch) window.__rhBkOrigFetch = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) {
                    o.body.append('csrf_token', window._rhCsrf || '');
                } else if (o && o.body instanceof URLSearchParams && !o.body.has('csrf_token')) {
                    o.body.append('csrf_token', window._rhCsrf || '');
                }
                return window.__rhBkOrigFetch.apply(this, arguments);
            };
        })();
    </script>

    <div class="content">
        <?php echo rh_review_panel('bookings'); if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Missed Check-ins Alert Banner -->
        <?php if (!empty($missed_checkins)): ?>
            <div class="alert-banner bookings-alert-banner bookings-alert-banner--danger" role="status" aria-live="polite">
                <div class="bookings-alert-banner__icon" aria-hidden="true">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="bookings-alert-banner__copy">
                    <h3 class="bookings-alert-banner__title">Missed Check-ins!</h3>
                    <p class="bookings-alert-banner__text"><?php echo count($missed_checkins); ?> confirmed or pending bookings have passed their check-in date without checking in.</p>
                    <p class="bookings-alert-banner__helper"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Helper: Open details to review each booking and update arrival status immediately.</span></p>
                </div>
                <button class="btn btn-secondary bookings-alert-banner__action" type="button" onclick="openMissedCheckinsModal()">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                    <span>View Details</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Overdue Checkouts Alert Banner -->
        <?php if (!empty($overdue_checkouts)): ?>
            <div class="alert-banner bookings-alert-banner bookings-alert-banner--warning" role="status" aria-live="polite">
                <div class="bookings-alert-banner__icon" aria-hidden="true">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="bookings-alert-banner__copy">
                    <h3 class="bookings-alert-banner__title">Overdue Checkouts!</h3>
                    <p class="bookings-alert-banner__text"><?php echo count($overdue_checkouts); ?> checked-in guests have passed their checkout date.</p>
                    <p class="bookings-alert-banner__helper"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Helper: Open details to process checkout updates and release room availability.</span></p>
                </div>
                <button class="btn btn-secondary bookings-alert-banner__action" type="button" onclick="openOverdueCheckoutsModal()">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                    <span>View Details</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Search & Tools Bar -->
        <div class="bookings-toolbar">
            <form method="GET" data-live-search-form="room-bookings" class="bookings-toolbar__form">
                <?php echo rh_server_sort_hidden($bk_sort_key, $bk_sort_dir); ?>
                <?php /* Searching or filtering keeps the open tab and the overdue views (the notice + Clear is the way out). */ ?>
                <?php foreach (['filter' => $bk_filter_key, 'arrival' => $filter_arrival, 'departure' => $filter_departure, 'balance' => $filter_balance, 'size' => ($bk_page_size !== '10' ? $bk_page_size : '')] as $bk_keep_k => $bk_keep_v): if ($bk_keep_v !== ''): ?>
                    <input type="hidden" name="<?php echo $bk_keep_k; ?>" value="<?php echo htmlspecialchars($bk_keep_v, ENT_QUOTES); ?>" data-bk-keep>
                <?php endif; endforeach; ?>
                <div class="bookings-toolbar__search">
                    <i class="fas fa-search bookings-toolbar__search-icon" aria-hidden="true"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                        data-live-search-input="room-bookings"
                        placeholder="Search ref, guest, phone, room no, payment ref..."
                        class="bookings-toolbar__input bookings-toolbar__input--search"
                        data-help="Search Bookings|Search by booking reference, guest name, phone number, room number, or payment reference. Results update automatically as you type.">
                </div>
                <select name="filter_status" class="bookings-toolbar__input bookings-toolbar__input--status">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="tentative" <?php echo $filter_status === 'tentative' ? 'selected' : ''; ?>>Tentative</option>
                    <option value="confirmed" <?php echo $filter_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="checked-in" <?php echo $filter_status === 'checked-in' ? 'selected' : ''; ?>>Checked In</option>
                    <option value="checked-out" <?php echo $filter_status === 'checked-out' ? 'selected' : ''; ?>>Checked Out</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="no-show" <?php echo $filter_status === 'no-show' ? 'selected' : ''; ?>>No-Show</option>
                    <option value="deleted" <?php echo $filter_status === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                </select>
                <select name="date_type" class="bookings-toolbar__input bookings-toolbar__input--status" aria-label="Which date From and To apply to"
                    title="Which date From and To apply to. Arrival: check-in date. Departure: check-out date. Staying: in the hotel on at least one night in the range (the departure day is not a night). Booked on: the day the booking was made."
                    data-help="Dates|Pick which date From and To look at. Arrival = the check-in date. Departure = the check-out date. Staying = guests in the hotel on at least one night in the range (the day they leave does not count as a night). Booked on = the day the booking was made. Both end days are included.">
                    <?php foreach (rh_bk_date_types() as $bk_dt_key => $bk_dt_label): ?>
                        <option value="<?php echo $bk_dt_key; ?>" <?php echo $filter_date_type === $bk_dt_key ? 'selected' : ''; ?>><?php echo htmlspecialchars($bk_dt_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="rh-inline-field"><span class="rh-inline-field__text">From</span>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                    placeholder="From" title="From: first day of the range, on the date chosen in Dates (Arrival, Departure, Staying or Booked on). The day itself is included. Same date in From and To = that one day." aria-label="From date"
                    class="bookings-toolbar__input bookings-toolbar__input--date"></label>
                <label class="rh-inline-field"><span class="rh-inline-field__text">To</span>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                    placeholder="To" title="To: last day of the range, on the date chosen in Dates (Arrival, Departure, Staying or Booked on). The day itself is included. Same date in From and To = that one day." aria-label="To date"
                    class="bookings-toolbar__input bookings-toolbar__input--date"></label>
                <span class="bookings-toolbar__btns"><?php /* Filter + Clear stay together on one line */ ?>
                <button type="submit" class="bookings-toolbar__button bookings-toolbar__button--filter btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php if ($has_active_room_filters): ?>
                    <a href="bookings.php" class="bookings-toolbar__button bookings-toolbar__button--clear btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
                </span>
            </form>
            <div class="bookings-toolbar__actions">
                <a href="<?php echo htmlspecialchars('bookings.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="bookings-toolbar__action bookings-toolbar__action--export">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="tentative-bookings.php" class="bookings-toolbar__action bookings-toolbar__action--tentative">
                    <i class="fas fa-hourglass-half"></i> Tentative<?php if ($tentative > 0): ?> <span class="bookings-toolbar__badge"><?php echo $tentative; ?></span><?php endif; ?>
                </a>
                <a href="create-booking.php" class="bookings-toolbar__action bookings-toolbar__action--new" data-help="New Booking|Open the full reservation form to create a new room booking for a guest.">
                    <i class="fas fa-plus"></i> New Booking
                </a>
            </div>
        </div>

        <div id="booking-results" data-active-tab="<?php echo htmlspecialchars($active_tab_override ?: 'all', ENT_QUOTES); ?>" data-admin-pagination-scope data-flash-scope>
            <?php if ($has_active_room_filters): ?>
                <div style="background: #eef3ff; border: 1px solid #cfd8ff; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px; color: #1f2d6b;">
                        <i class="fas fa-filter"></i>
                        <strong><?php echo htmlspecialchars(rh_bk_notice_title($filter_arrival, $filter_departure, $filter_balance, $bk_filter_key)); ?></strong>
                    </div>
                    <div style="color: #2f3a63; font-size: 13px;">
                        Showing <?php echo number_format($list_count); ?> matching room booking<?php echo $list_count === 1 ? '' : 's'; ?><?php if ($search_query !== ''): ?> for &ldquo;<?php echo htmlspecialchars($search_query); ?>&rdquo;<?php endif; ?><?php $bk_scope_words = rh_bk_date_scope_words($filter_date_from, $filter_date_to, $filter_date_type); if ($bk_scope_words !== ''): ?>, <?php echo htmlspecialchars($bk_scope_words); ?><?php endif; ?><?php if ($filter_arrival === 'overdue'): ?> whose arrival date has passed and who never checked in<?php endif; ?><?php if ($filter_departure === 'overdue'): ?> still checked in after their check-out date<?php endif; ?><?php if ($filter_balance === 'owing'): ?><?php echo $bk_filter_key === 'checkout_today' ? ' due out today' : ''; ?> with a balance still to pay<?php endif; ?>.
                        <a href="bookings.php" style="margin-left: 8px; color: #1f2d6b; font-weight: 600;">Clear</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tabs-container">
                <div class="tabs-header">
                    <?php /* Everyday tabs only. Confirmed, Checked Out, Cancelled and No-Show are in the
                             status filter; Paid and booked-today/week/month still work via ?filter=. */ ?>
                    <button class="tab-button <?php echo $active_tab_override === '' ? 'active' : ''; ?>" data-tab="all" data-count="<?php echo $total_bookings; ?>">
                        <i class="fas fa-list"></i>
                        All
                        <span class="tab-count"><?php echo $total_bookings; ?></span>
                    </button>
                    <button class="tab-button" data-tab="today-checkins" data-count="<?php echo $today_checkins; ?>">
                        <i class="fas fa-calendar-day"></i>
                        Today's Check-ins
                        <span class="tab-count"><?php echo $today_checkins; ?></span>
                    </button>
                    <button class="tab-button" data-tab="today-checkouts" data-count="<?php echo $today_checkouts; ?>">
                        <i class="fas fa-calendar-times"></i>
                        Today's Check-outs
                        <span class="tab-count"><?php echo $today_checkouts; ?></span>
                    </button>
                    <button class="tab-button" data-tab="checked-in" data-count="<?php echo $checked_in; ?>">
                        <i class="fas fa-sign-in-alt"></i>
                        In-House
                        <span class="tab-count"><?php echo $checked_in; ?></span>
                    </button>
                    <button class="tab-button" data-tab="pending" data-count="<?php echo $pending; ?>">
                        <i class="fas fa-clock"></i>
                        Pending
                        <span class="tab-count"><?php echo $pending; ?></span>
                    </button>
                    <button class="tab-button" data-tab="tentative" data-count="<?php echo $tentative; ?>">
                        <i class="fas fa-hourglass-half"></i>
                        Tentative
                        <span class="tab-count"><?php echo $tentative; ?></span>
                    </button>
                    <?php if ($expiring_soon > 0): /* only while tentative holds are about to lapse */ ?>
                    <button class="tab-button" data-tab="expiring-soon" data-count="<?php echo $expiring_soon; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        Expiring Soon
                        <span class="tab-count"><?php echo $expiring_soon; ?></span>
                    </button>
                    <?php endif; ?>
                    <button class="tab-button" data-tab="unpaid" data-count="<?php echo $unpaid; ?>">
                        <i class="fas fa-exclamation-circle"></i>
                        Unpaid
                        <span class="tab-count"><?php echo $unpaid; ?></span>
                    </button>
                    <?php if ($deleted_count > 0 || $filter_status === 'deleted'): ?>
                    <button class="tab-button <?php echo $filter_status === 'deleted' ? 'active' : ''; ?>" data-tab="deleted" data-count="<?php echo $deleted_count; ?>" title="Deleted bookings: who deleted them, when and why. Restore from here.">
                        <i class="fas fa-trash-can"></i>
                        Deleted
                        <span class="tab-count"><?php echo $deleted_count; ?></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Room Bookings -->
            <div class="bookings-section" data-rh-results>
                <h3 class="section-title">
                    <i class="fas fa-bed"></i> Room Bookings
                    <span style="font-size: 14px; font-weight: normal; color: #666;">
                        <?php if ($total_pages > 1): ?>
                            (<?php echo $current_page_count; ?> of <?php echo number_format($list_count); ?> <?php echo $has_active_room_filters ? 'matching' : 'total'; ?> &mdash; page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                        <?php else: ?>
                            (<?php echo number_format($list_count); ?> <?php echo $has_active_room_filters ? 'matching' : 'total'; ?>)
                        <?php endif; ?>
                    </span>
                </h3>

                <?php if (!empty($bookings)): ?>
                    <?php $bk_can_open_payment = rhCanLinkTo((int)($user['id'] ?? 0), 'payment-details.php'); // once per page: link payment refs to the payment record ?>
                    <div class="table-responsive">
                        <table class="booking-table bookings-table rh-phone-actions rh-fold-actions" data-server-sort="1" data-hotel-today="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES); ?>">
                            <thead>
                                <tr>
                                    <?php rh_server_sort_th('Ref', 'ref', $bk_sort_key, $bk_sort_dir, 'col-optional bk-col-ref'); ?>
                                    <?php rh_server_sort_th('Guest Name', 'guest', $bk_sort_key, $bk_sort_dir); ?>
                                    <?php rh_server_sort_th('Room', 'room', $bk_sort_key, $bk_sort_dir, 'col-optional-lg'); ?>
                                    <?php rh_server_sort_th('Check In', 'check_in', $bk_sort_key, $bk_sort_dir); ?>
                                    <?php rh_server_sort_th('Check Out', 'check_out', $bk_sort_key, $bk_sort_dir, 'col-optional bk-col-out'); ?>
                                    <?php rh_server_sort_th('Nights', 'nights', $bk_sort_key, $bk_sort_dir, 'col-optional-lg bk-col-nights'); ?>
                                    <?php rh_server_sort_th('Guests', 'guests', $bk_sort_key, $bk_sort_dir, 'col-optional-lg bk-col-guests'); ?>
                                    <?php rh_server_sort_th('Total', 'total', $bk_sort_key, $bk_sort_dir, 'bk-th-total'); ?>
                                    <?php rh_server_sort_th('Status', 'status', $bk_sort_key, $bk_sort_dir, 'col-optional bk-col-status'); ?>
                                    <?php rh_server_sort_th('Payment', 'payment', $bk_sort_key, $bk_sort_dir, 'col-optional-md'); ?>
                                    <?php rh_server_sort_th('Created', 'created', $bk_sort_key, $bk_sort_dir, 'col-optional-lg bk-col-created'); ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <?php
                                    $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
                                    $expires_soon = false;
                                    if ($is_tentative && $booking['tentative_expires_at']) {
                                        $expires_at = new DateTime($booking['tentative_expires_at']);
                                        $now = new DateTime();
                                        $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                                        $expires_soon = $hours_until_expiry <= 24 && $hours_until_expiry > 0;
                                    }

                                    $is_missed_checkin = ($booking['status'] === 'confirmed' && $booking['check_in_date'] < $today_str);

                                    // 'overdue' = departure date passed; 'late' = due today and past check-out time.
                                    $checkout_overdue_state = rh_checkout_overdue_state($booking);
                                    $is_overdue_checkout = $checkout_overdue_state !== '';
                                    $is_deleted_row = !empty($booking['deleted_at']);
                                    if ($is_deleted_row) {
                                        $is_missed_checkin = $is_overdue_checkout = false;
                                    }

                                    $row_style = '';
                                    if ($is_deleted_row) {
                                        $row_style = 'style="background: rgba(108, 117, 125, 0.07); border-left: 4px solid #6c757d;"';
                                    } elseif ($is_missed_checkin) {
                                        $row_style = 'style="background: rgba(220, 53, 69, 0.05); border-left: 4px solid #dc3545;"';
                                    } elseif ($is_overdue_checkout) {
                                        $row_style = 'style="background: rgba(255, 193, 7, 0.05); border-left: 4px solid #ffc107;"';
                                    } elseif ($is_tentative) {
                                        $row_style = 'style="background: linear-gradient(90deg, rgba(139, 115, 85, 0.05) 0%, white 10%);"';
                                    }
                                    ?>
                                    <tr <?php echo $row_style; ?>
                                        id="booking-<?php echo (int)$booking['id']; ?>"
                                        data-focus="booking-<?php echo (int)$booking['id']; ?>"
                                        data-status="<?php echo htmlspecialchars($is_deleted_row ? 'deleted' : $booking['status'], ENT_QUOTES); ?>"
                                        data-payment-status="<?php echo htmlspecialchars((string)($booking['payment_status'] ?: ($booking['actual_payment_status'] ?? '')), ENT_QUOTES); ?>"
                                        <?php $bk_pay_flags = rh_bk_payment_flags($booking['payment_status'] ?? null, (string)$booking['status']); /* same rule as the Paid / Unpaid badges */ ?>
                                        data-paid="<?php echo $bk_pay_flags['paid'] ? '1' : '0'; ?>"
                                        data-unpaid="<?php echo $bk_pay_flags['unpaid'] ? '1' : '0'; ?>"
                                        data-check-in="<?php echo htmlspecialchars($booking['check_in_date'], ENT_QUOTES); ?>"
                                        data-check-out="<?php echo htmlspecialchars($booking['check_out_date'], ENT_QUOTES); ?>"
                                        data-created="<?php echo htmlspecialchars(date('Y-m-d', strtotime($booking['created_at'])), ENT_QUOTES); ?>"
                                        data-expiring-soon="<?php echo $expires_soon ? '1' : '0'; ?>"
                                        data-tentative="<?php echo $is_tentative ? '1' : '0'; ?>">
                                        <td class="col-optional bk-col-ref" data-label="Reference">
                                            <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                            <?php if ($is_tentative): ?>
                                                <br><span class="tentative-indicator"><i class="fas fa-clock"></i> Tentative</span>
                                            <?php elseif ($is_missed_checkin): ?>
                                                <br><span style="color: #dc3545; font-size: 11px; font-weight: 600;"><i class="fas fa-exclamation-triangle"></i> Missed Check-in</span>
                                            <?php elseif ($is_overdue_checkout): ?>
                                                <br><span style="color: #856404; font-size: 11px; font-weight: 600;"><i class="fas fa-clock"></i> <?php echo $checkout_overdue_state === 'late' ? 'Late checkout' : 'Overdue checkout'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Guest">
                                            <strong class="rh-guest-name" title="<?php echo htmlspecialchars((string)$booking['guest_name'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($booking['guest_name']); ?></strong><span class="rh-phone-sub bk-ref-twin"><?php echo htmlspecialchars($booking['booking_reference']); ?></span><?php /* Flag twin: the Ref cell's flag, shown under the guest when the Ref column drops out (bookings.css, laptops). */ if ($is_tentative): ?><span class="rh-phone-sub bk-flag-twin bk-flag-twin--tentative"><i class="fas fa-clock"></i> Tentative</span><?php elseif ($is_missed_checkin): ?><span class="rh-phone-sub bk-flag-twin bk-flag-twin--danger"><i class="fas fa-exclamation-triangle"></i> Missed Check-in</span><?php elseif ($is_overdue_checkout): ?><span class="rh-phone-sub bk-flag-twin bk-flag-twin--warn"><i class="fas fa-clock"></i> <?php echo $checkout_overdue_state === 'late' ? 'Late checkout' : 'Overdue checkout'; ?></span><?php endif; ?>
                                            <?php if (trim((string)($booking['room_guest_name'] ?? '')) !== ''): ?>
                                                <br><small class="room-guest-line" style="color:#555;">Room guest: <?php echo htmlspecialchars((string)$booking['room_guest_name']); ?></small>
                                            <?php endif; ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($booking['guest_phone']); ?></small>
                                            <?php if (!empty($booking['guest_email'])): ?>
                                                <small class="guest-email col-optional-lg" title="<?php echo htmlspecialchars((string)$booking['guest_email'], ENT_QUOTES); ?>"><br><?php echo htmlspecialchars($booking['guest_email']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-optional-lg" data-label="Room">
                                            <?php echo htmlspecialchars($booking['room_name']); ?>
                                            <?php if (!empty($booking['rate_plan_label'])): ?>
                                                <br><small style="color:#8A5F2A; font-size:11px;"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($booking['rate_plan_label']); ?></small>
                                            <?php endif; ?>
                                            <?php $roomAssignmentLabel = getBookingRoomLabel((int)$booking['id'], (string)($booking['individual_room_name'] ?: $booking['individual_room_number'])); ?>
                                            <?php if ($roomAssignmentLabel !== ''): ?>
                                                <br><span class="room-chip"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomAssignmentLabel); ?></span>
                                            <?php else: ?>
                                                <br><span class="room-chip room-chip--unassigned"><i class="fas fa-minus"></i> Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Check In"><span class="rh-ph-hide"><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></span><span class="rh-phone-sub rh-phone-sub--dark rh-phone-sub--nw">In <?php echo date('j M', strtotime($booking['check_in_date'])); ?></span><span class="rh-phone-sub rh-phone-sub--dark rh-phone-sub--nw">Out <?php echo date('j M', strtotime($booking['check_out_date'])); ?></span>
                                            <?php /* Phone list row, line 2 (bookings.css, max 640px only): stay + nights + the room chip from the Room cell above. */ $bk_ph_nights = (int)$booking['number_of_nights']; ?>
                                            <span class="bk-ph-stay"><span class="bk-ph-stay__dates"><?php echo date('j M', strtotime($booking['check_in_date'])); ?> &rarr; <?php echo date('j M', strtotime($booking['check_out_date'])); ?> &middot; <?php echo $bk_ph_nights . ($bk_ph_nights === 1 ? ' night' : ' nights'); ?></span><?php if ($roomAssignmentLabel !== ''): ?><span class="room-chip"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($roomAssignmentLabel); ?></span><?php else: ?><span class="room-chip room-chip--unassigned"><i class="fas fa-minus"></i> Unassigned</span><?php endif; ?></span>
                                        </td>
                                        <td class="col-optional bk-col-out" data-label="Check Out"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                        <td class="col-optional-lg bk-col-nights" data-label="Nights"><?php echo $booking['number_of_nights']; ?></td>
                                        <td class="col-optional-lg bk-col-guests" data-label="Guests"><?php echo $booking['number_of_guests']; ?></td>
                                        <td data-label="Total">
                                            <?php
                                            // The booking's own payment status (unpaid / partial / paid / refunded) is the
                                            // truth for the guest's bill; the latest payment record is only one transaction
                                            // ('completed' even when it covered part of the bill), so it is shown as the reference.
                                            // Worked out here so the phone list row can show it beside the total; the Payment cell below uses the same values.
                                            $status = (string)($booking['payment_status'] ?: ($booking['actual_payment_status'] ?? ''));
                                            // Map payment statuses to user-friendly labels
                                            $status_labels = [
                                                'paid' => 'Paid',
                                                'unpaid' => 'Unpaid',
                                                'partial' => 'Partial',
                                                'completed' => 'Paid',
                                                'pending' => 'Pending',
                                                'failed' => 'Failed',
                                                'refunded' => 'Refunded',
                                                'partially_refunded' => 'Partial Refund'
                                            ];
                                            // Part-paid: what is still to pay (amount_due), shown under the payment badge; 0 = nothing shown.
                                            $bk_left_to_pay = $is_deleted_row ? 0.0 : rh_bk_left_to_pay($status, $booking['status'] ?? '', $booking['amount_due'] ?? 0, $booking['amount_paid'] ?? 0);
                                            $bk_left_text = $bk_left_to_pay > 0 ? $currency_symbol . ' ' . number_format($bk_left_to_pay, 2) . ' left to pay' : '';
                                            ?>
                                            <span class="bk-total-main"><strong><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format(rh_booking_full_total($booking), 2); ?></strong><?php echo rh_booking_total_info_btn($booking); ?><span class="bk-ph-pay badge badge-<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"><?php echo htmlspecialchars($status_labels[$status] ?? ucfirst($status)); ?></span><?php if ($bk_left_text !== ''): ?><span class="bk-ph-left bk-left-to-pay"><?php echo htmlspecialchars($bk_left_text); ?></span><?php endif; ?></span>
                                            <?php $bk_rate = rh_booked_rate_line($booking, null, ['currency' => $currency_symbol]); // the row's own columns, no query ?>
                                            <?php if ($bk_rate['ok']): ?>
                                                <br><small class="bk-booked-rate" style="color:#666;white-space:nowrap;" title="<?php echo htmlspecialchars('Booked at ' . $bk_rate['text'] . '. Room rate a night × nights, at the price the booking was made at; today\'s room price does not change it.', ENT_QUOTES); ?>"><?php echo htmlspecialchars($bk_rate['compact'], ENT_QUOTES); ?></small>
                                            <?php endif; ?>
                                            <span class="rh-phone-sub rh-phone-sub--pill bk-status-twin"><span class="badge badge-<?php echo htmlspecialchars($is_deleted_row ? 'cancelled' : $booking['status'], ENT_QUOTES); ?>"><?php echo $is_deleted_row ? 'Deleted' : htmlspecialchars(ucfirst((string)$booking['status'])); ?></span></span><?php /* Status note twin: the Status cell's deleted-by / when / reason or tentative expiry, shown under the pill when the Status column drops out (bookings.css, laptops). */ ?><?php if ($is_deleted_row): ?><small class="bk-status-note">by <?php echo htmlspecialchars((string)($booking['deleted_by_name'] ?? 'unknown')); ?>, <?php echo date('j M Y, H:i', strtotime((string)$booking['deleted_at'])); ?><?php if (trim((string)($booking['deleted_reason'] ?? '')) !== ''): ?><br><em><?php echo htmlspecialchars((string)$booking['deleted_reason']); ?></em><?php endif; ?></small><?php endif; ?><?php if ($is_tentative && $booking['tentative_expires_at']): ?><small class="bk-status-note">Expires: <?php echo (new DateTime($booking['tentative_expires_at']))->format('M d, H:i'); ?></small><?php endif; ?>
                                            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                                <?php if ($expires_soon): ?>
                                                    <br><span class="expires-soon"><i class="fas fa-exclamation-triangle"></i> Expires soon!</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-optional bk-col-status" data-label="Status">
                                            <?php if ($is_deleted_row): ?>
                                                <span class="badge badge-cancelled"><i class="fas fa-trash-can"></i> Deleted</span>
                                                <br><small style="color: #555; font-size: 11px; line-height: 1.4; display: inline-block; margin-top: 4px;">
                                                    by <?php echo htmlspecialchars((string)($booking['deleted_by_name'] ?? 'unknown')); ?><br>
                                                    <?php echo date('j M Y, H:i', strtotime((string)$booking['deleted_at'])); ?><br>
                                                    <em><?php echo htmlspecialchars((string)($booking['deleted_reason'] ?? '')); ?></em>
                                                </small>
                                            <?php else: ?>
                                            <span class="badge badge-<?php echo htmlspecialchars($booking['status'], ENT_QUOTES); ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                                <br><small style="color: #666; font-size: 10px;">
                                                    <?php
                                                    $expires = new DateTime($booking['tentative_expires_at']);
                                                    echo 'Expires: ' . $expires->format('M d, H:i');
                                                    ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-optional-md" data-label="Payment">
                                            <?php /* $status / $status_labels: worked out in the Total cell above. */ ?>
                                            <span class="badge badge-<?php echo htmlspecialchars($status, ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($status_labels[$status] ?? ucfirst($status)); ?>
                                            </span>
                                            <?php if ($bk_left_text !== ''): ?>
                                                <br><strong class="bk-left-to-pay"><span class="bk-left-to-pay__amt"><?php echo htmlspecialchars($currency_symbol . ' ' . number_format($bk_left_to_pay, 2)); ?></span> left to pay</strong>
                                            <?php endif; ?>
                                            <?php
                                            // Company pays later (migration 084): the list says who owes the balance instead of a plain "Unpaid".
                                            $bkBillLabel = '';
                                            if (isset($bk_bill_splits[(int)$booking['id']])) {
                                                $bkBillLabel = rh_booking_pay_label($bk_bill_splits[(int)$booking['id']]);
                                            }
                                            ?>
                                            <?php if ($bkBillLabel !== ''): ?>
                                                <br><small class="bk-arrangement-tag" title="<?php echo htmlspecialchars('Company pays later: the room stay is billed to the company, not chased from the guest and it does not block checkout.', ENT_QUOTES); ?>"><?php echo htmlspecialchars($bkBillLabel); ?></small>
                                            <?php elseif (!empty($booking['checkin_without_payment']) && in_array((string)$booking['status'], ['pending', 'tentative', 'confirmed', 'checked-in'], true)): ?>
                                                <br><small class="bk-arrangement-tag" title="<?php echo htmlspecialchars('May check in before paying' . (trim((string)($booking['checkin_arrangement_note'] ?? '')) !== '' ? ': ' . trim((string)$booking['checkin_arrangement_note']) : '') . '. The balance stays owed.', ENT_QUOTES); ?>">Pay-later arrangement</small>
                                            <?php endif; ?>
                                            <?php if ($booking['payment_reference']): ?>
                                                <br><small style="color: #666; font-size: 10px;">
                                                    <?php if ($bk_can_open_payment && (int)($booking['latest_payment_id'] ?? 0) > 0): ?>
                                                        <a href="payment-details.php?id=<?php echo (int)$booking['latest_payment_id']; ?>" class="bk-pay-ref-link" aria-label="<?php echo htmlspecialchars('Open payment ' . $booking['payment_reference'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($booking['payment_reference']); ?></a>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($booking['payment_reference']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-optional-lg bk-col-created" data-label="Created">
                                            <small style="color: #666; font-size: 11px;">
                                                <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($booking['created_at'])); ?>
                                            </small>
                                            <?php echo rh_created_by_html($bk_created_by[(int)$booking['id']] ?? ['label' => '']); ?>
                                        </td>
                                        <td class="actions-cell" data-label="Actions">
                                            <?php
                                            // Granular permission gates for action buttons
                                            $_perm_checkin  = $_user_permissions['checkin_guest']  ?? false;
                                            $_perm_checkout = $_user_permissions['checkout_guest'] ?? false;
                                            $_perm_cancel   = $_user_permissions['cancel_booking'] ?? false;
                                            $_perm_edit     = $_user_permissions['edit_booking']   ?? false;
                                            $_perm_pay      = $_user_permissions['payment_add']    ?? false;

                                            // What this booking can still do, front-desk style: only actions valid for
                                            // its status, dates and money position are rendered (nothing greyed-out noise).
                                            $bk_id     = (int)$booking['id'];
                                            $bk_ref    = htmlspecialchars($booking['booking_reference'], ENT_QUOTES);
                                            $bk_guest  = htmlspecialchars($booking['guest_name'], ENT_QUOTES);
                                            // Values passed into inline onclick JS: JSON-encode, THEN HTML-escape (a quote in a
                                            // guest name must neither break the button nor run script).
                                            $bk_js = static fn ($v): string => htmlspecialchars((string)json_encode((string)$v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
                                            $bk_ref_js = $bk_js($booking['booking_reference']);
                                            $bk_guest_js = $bk_js($booking['guest_name']);
                                            $bk_status = (string)$booking['status'];
                                            $bk_deleted = !empty($booking['deleted_at']);
                                            $bk_paid    = (float)($booking['amount_paid'] ?? 0);
                                            $bk_due     = (float)($booking['amount_due'] ?? 0);
                                            $bk_credit  = (float)($booking['credit_balance'] ?? 0);
                                            $bk_folio   = (float)($booking['folio_charges_total'] ?? 0);
                                            $bk_holding = in_array($bk_status, ['pending', 'tentative', 'confirmed'], true); // reservation, not yet arrived
                                            $bk_inhouse = $bk_status === 'checked-in';
                                            $bk_closed  = in_array($bk_status, ['checked-out', 'cancelled', 'no-show', 'expired'], true);
                                            $bk_payment_st = (string)($booking['payment_status'] ?: ($booking['actual_payment_status'] ?? ''));

                                            // Money owed back (overpaid / cancelled with payment) -> Refund. A no-show forfeits by
                                            // policy, but staff may still refund at their discretion while money is held.
                                            $can_refund = !$bk_deleted && $_perm_pay && (
                                                $bk_credit > BALANCE_TOLERANCE
                                                || (in_array($bk_status, ['cancelled', 'no-show', 'expired'], true) && $bk_paid > BALANCE_TOLERANCE)
                                            );
                                            // The money icon (Record payment dialog: full or part, method, confirm) only before anything is paid.
                                            $can_mark_paid = $_perm_pay && in_array($bk_status, ['pending', 'confirmed'], true)
                                                && !in_array($booking['payment_status'], ['paid', 'partial', 'completed'], true);
                                            $can_modify = $bk_holding || $bk_inhouse;
                                            $can_change_room_type = ($bk_holding && !$is_missed_checkin) || $bk_inhouse;
                                            // A late arrival (confirmed, arrival date passed) still needs a room to check in.
                                            $can_assign_room = $bk_status === 'confirmed' || $bk_inhouse; // in-house: move to another room of the same type
                                            // The right document for the state (owner rule): nothing paid yet = quotation first;
                                            // balance owed = invoice first; fully paid = receipt (never an invoice).
                                            $bk_doc = rh_doc_state($bk_paid, $bk_due, $bk_status);
                                            $can_quote = $bk_holding && $bk_paid <= BALANCE_TOLERANCE;
                                            $can_invoice = in_array($bk_status, ['confirmed', 'checked-in', 'checked-out'], true) && $bk_due > BALANCE_TOLERANCE;
                                            $can_receipt = !$bk_deleted && $bk_paid > BALANCE_TOLERANCE && !in_array($bk_status, ['no-show', 'expired'], true);
                                            $can_consolidate = $_perm_pay && ($bk_holding || $bk_inhouse
                                                || ($bk_status === 'checked-out' && ($bk_due > BALANCE_TOLERANCE || $bk_credit > BALANCE_TOLERANCE)));
                                            $can_make_tentative = $bk_status === 'pending' && !in_array($booking['payment_status'], ['paid', 'partial'], true);
                                            $can_delete = $_perm_delete && !$bk_inhouse && $bk_paid <= BALANCE_TOLERANCE && $bk_folio <= BALANCE_TOLERANCE;
                                            ?>
                                            <div class="actions-row">
                                                <button type="button" class="quick-action view" title="View booking summary" aria-label="View booking summary" data-booking-id="<?php echo $bk_id; ?>" data-booking-ref="<?php echo $bk_ref; ?>" data-guest-name="<?php echo htmlspecialchars((string)($booking['guest_name'] ?? ''), ENT_QUOTES); ?>" data-guest-email="<?php echo htmlspecialchars((string)($booking['guest_email'] ?? ''), ENT_QUOTES); ?>" data-guest-phone="<?php echo htmlspecialchars((string)($booking['guest_phone'] ?? ''), ENT_QUOTES); ?>" data-room-name="<?php echo htmlspecialchars((string)($booking['room_name'] ?? ''), ENT_QUOTES); ?>" data-individual-room-name="<?php echo htmlspecialchars((string)($booking['individual_room_name'] ?? ''), ENT_QUOTES); ?>" data-individual-room-number="<?php echo htmlspecialchars((string)($booking['individual_room_number'] ?? ''), ENT_QUOTES); ?>" data-check-in-date="<?php echo htmlspecialchars((string)($booking['check_in_date'] ?? ''), ENT_QUOTES); ?>" data-check-out-date="<?php echo htmlspecialchars((string)($booking['check_out_date'] ?? ''), ENT_QUOTES); ?>" data-number-of-nights="<?php echo htmlspecialchars((string)($booking['number_of_nights'] ?? ''), ENT_QUOTES); ?>" data-number-of-guests="<?php echo htmlspecialchars((string)($booking['number_of_guests'] ?? ''), ENT_QUOTES); ?>" data-total-display="<?php echo htmlspecialchars($currency_symbol . ' ' . number_format(rh_booking_full_total($booking), 2), ENT_QUOTES); ?>" data-booked-rate="<?php echo htmlspecialchars(!empty($bk_rate['ok']) ? $bk_rate['text'] : '', ENT_QUOTES); ?>" data-status-label="<?php echo htmlspecialchars($bk_deleted ? 'Deleted' : rh_hist_words($bk_status), ENT_QUOTES); ?>" data-payment-status-label="<?php echo htmlspecialchars(rh_hist_words((string)$bk_payment_st), ENT_QUOTES); ?>" data-created-at-label="<?php echo htmlspecialchars(!empty($booking['created_at']) ? date('M j, Y H:i', strtotime((string)$booking['created_at'])) : '', ENT_QUOTES); ?>" data-special-requests="<?php echo htmlspecialchars((string)($booking['special_requests'] ?? ''), ENT_QUOTES); ?>" onclick="openViewBookingModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, this, typeof event !== 'undefined' ? event : null)">
                                                    <i class="fas fa-circle-info"></i>
                                                    <span class="label">View details</span>
                                                </button>

                                                <?php if ($bk_deleted): ?>
                                                    <?php if ($_perm_delete): ?>
                                                        <button type="button" class="quick-action confirm" title="Restore booking" aria-label="Restore booking" data-help="Restore Booking|Bring this deleted booking back with the status it had before. If it held a room, the room type must still be free for its dates." onclick="restoreDeletedBooking(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)">
                                                            <i class="fas fa-trash-arrow-up"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>

                                                <?php /* ── Reservation stage ── */ ?>
                                                <?php if ($is_tentative && $bkCanConfirm): ?>
                                                    <button class="quick-action confirm" title="Convert to confirmed" aria-label="Convert to confirmed" onclick="convertTentativeBooking(<?php echo $bk_id; ?>)">
                                                        <i class="fas fa-circle-check"></i>
                                                    </button>
                                                <?php elseif ($bk_status === 'pending' && $bkCanConfirm): ?>
                                                    <button class="quick-action confirm" title="Confirm booking" aria-label="Confirm booking" data-help="Confirm Booking|Move this pending booking to Confirmed status so it appears in the confirmed queue and can proceed to check-in." onclick="updateStatus(<?php echo $bk_id; ?>, 'confirmed')">
                                                        <i class="fas fa-circle-check"></i>
                                                    </button>
                                                <?php elseif ($bk_status === 'confirmed'): ?>
                                                    <?php
                                                    // Payment part of the one check-in rule (validateCheckIn): ok / nothing owed / arrangement / blocked.
                                                    $ci_pay_gate = rh_checkin_payment_gate($booking);
                                                    $is_paid = $ci_pay_gate !== 'blocked';
                                                    $checkin_date_reached = (string)$booking['check_in_date'] <= $today_str;
                                                    $can_checkin = $is_paid && $checkin_date_reached;
                                                    $checkin_error = !$is_paid
                                                        ? 'Cannot check in: booking must have at least partial payment (or Check in before payment switched on).'
                                                        : (!$checkin_date_reached ? 'Cannot check in: arrival date is ' . $booking['check_in_date'] . '.' : '');
                                                    $room_assigned_bool = !empty($booking['individual_room_id']) ? 'true' : 'false';
                                                    $checkin_attrs = 'data-booking-id="' . $bk_id . '" data-booking-ref="' . $bk_ref . '" data-guest-name="' . $bk_guest . (trim((string)($booking['room_guest_name'] ?? '')) !== '' ? htmlspecialchars(' (room guest: ' . $booking['room_guest_name'] . ')', ENT_QUOTES) : '')
                                                        . '" data-check-in-date="' . htmlspecialchars($booking['check_in_date'], ENT_QUOTES) . '" data-payment-status="' . htmlspecialchars((string)$bk_payment_st, ENT_QUOTES)
                                                        . '" data-room-assigned="' . $room_assigned_bool . '" data-booking-status="confirmed" data-payment-gate="' . htmlspecialchars($ci_pay_gate, ENT_QUOTES)
                                                        . '" data-late-info="' . htmlspecialchars(json_encode(bookings_late_checkin_info($booking)), ENT_QUOTES) . '"';
                                                    ?>
                                                    <?php if ($_perm_checkin && $checkin_date_reached): /* arrivals only from the arrival date */ ?>
                                                        <button type="button" class="quick-action <?php echo $is_missed_checkin ? 'checkin--urgent' : 'checkin'; ?> <?php echo $can_checkin ? '' : 'disabled'; ?>" data-action="check-in" <?php echo $checkin_attrs; ?> title="<?php echo htmlspecialchars($can_checkin ? ($is_missed_checkin ? 'Late check-in' : 'Check in guest') : $checkin_error, ENT_QUOTES); ?>" aria-label="<?php echo $is_missed_checkin ? 'Late check-in' : 'Check in guest'; ?>" data-help="Check In Guest|Check the guest into their room. Requires at least partial payment (or nothing owed, or Check in before payment switched on for a pay-later arrangement) and the arrival date to have come.">
                                                            <i class="fas fa-right-to-bracket"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($is_missed_checkin && $_perm_cancel): ?>
                                                        <button type="button" class="quick-action noshow--urgent" data-action="no-show" <?php echo $checkin_attrs; ?> title="Mark no-show" aria-label="Mark no-show">
                                                            <i class="fas fa-user-slash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($bk_holding && $_perm_cancel): ?>
                                                    <button class="quick-action cancel" title="Cancel booking" aria-label="Cancel booking" onclick="openCancelBookingModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_guest_js; ?>, <?php echo $bk_js($bk_status); ?>)">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php /* ── In-house ── */ ?>
                                                <?php if ($bk_inhouse && $_perm_checkout): ?>
                                                    <?php
                                                    // Checkout any day: a late or early departure opens the settlement window
                                                    // (extra nights charged / waived, unused nights refunded / kept).
                                                    $is_early_departure = (string)$booking['check_out_date'] > $today_str;
                                                    ?>
                                                    <button class="quick-action <?php echo $is_overdue_checkout ? 'checkout--urgent' : 'checkout'; ?>" title="<?php echo $is_overdue_checkout ? 'Checkout now (overdue)' : ($is_early_departure ? 'Early checkout (settles unused nights)' : 'Checkout guest'); ?>" aria-label="Checkout guest" data-help="Checkout Guest|Settle the folio and close the stay. Leaving late or early opens the settlement window for the extra or unused nights." onclick="checkoutBooking(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)">
                                                        <i class="fas fa-right-from-bracket"></i>
                                                    </button>
                                                    <button class="quick-action extend" title="Add or take off nights" aria-label="Add or take off nights" data-help="Add or take off nights|Add nights (room availability is checked) or take nights off the end of the stay, at the booked rate. You see the new total and balance before confirming; a reason is required." onclick="openExtendStayModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_js($booking['check_out_date']); ?>, <?php echo $bk_guest_js; ?>)">
                                                        <i class="fas fa-calendar-plus"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php /* ── Money ── */ ?>
                                                <?php if ($can_mark_paid && $_perm_pay): ?>
                                                    <button class="quick-action paid" title="Record payment" aria-label="Record payment" data-help="Record Payment|Opens a dialog: take the full balance or a part payment, choose how the guest paid, then confirm. Nothing is recorded until you confirm. Only shown before any payment has been recorded; after that use Payments &amp; balance in the More menu." onclick="<?php if (!empty($bk_bill_splits[$bk_id]['on_account'])): /* company pays later: the Payments & balance dialog asks who paid (guest / company) */ ?>openConsolidationModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)<?php else: ?>rhQuickPayOpen(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_guest_js; ?>, <?php echo htmlspecialchars(json_encode(round(max(0.0, $bk_due), 2)), ENT_QUOTES); ?>)<?php endif; ?>">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($can_refund): ?>
                                                    <button class="quick-action refund" title="Refund payment" aria-label="Refund payment" onclick="openRefundForBooking(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)">
                                                        <i class="fas fa-money-bill-transfer"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php endif; /* not deleted */ ?>

                                                <div class="actions-more">
                                                    <button type="button" class="quick-action actions-more-toggle" title="More actions" aria-label="More actions" onclick="toggleActionsMore(this, typeof event !== 'undefined' ? event : null)">
                                                        <i class="fas fa-ellipsis-vertical"></i>
                                                        <span class="label">More</span>
                                                    </button>
                                                    <div class="actions-more-menu">
                                                        <a href="booking-details.php?id=<?php echo $bk_id; ?>"><i class="fas fa-circle-info"></i> Full details</a>
                                                        <?php if (!$bk_deleted): ?>
                                                            <?php if ($_perm_edit): ?>
                                                                <a href="edit-booking.php?id=<?php echo $bk_id; ?>"><i class="fas fa-pen-to-square"></i> <?php echo $bk_closed ? 'Correct guest details' : 'Full edit page'; ?></a>
                                                            <?php endif; ?>
                                                            <?php if ($can_modify && $_perm_edit && $_perm_quick_modify): ?>
                                                                <button type="button" data-action="open-modify" data-booking-id="<?php echo $bk_id; ?>" data-booking-ref="<?php echo $bk_ref; ?>"><i class="fas fa-sliders"></i> Quick modify</button>
                                                            <?php endif; ?>

                                                            <?php if ($can_assign_room || $can_change_room_type || $can_make_tentative || ($bk_inhouse && ($_perm_checkin || $_is_admin_user))): ?>
                                                                <hr class="menu-divider">
                                                            <?php endif; ?>
                                                            <?php if ($can_assign_room && $_perm_edit): ?>
                                                                <button type="button" data-action="assign-room" data-booking-id="<?php echo $bk_id; ?>" data-booking-ref="<?php echo $bk_ref; ?>" data-check-in="<?php echo htmlspecialchars($booking['check_in_date'], ENT_QUOTES); ?>" data-check-out="<?php echo htmlspecialchars($booking['check_out_date'], ENT_QUOTES); ?>" data-room-id="<?php echo (int)$booking['room_id']; ?>"><i class="fas <?php echo empty($booking['individual_room_id']) ? 'fa-key' : 'fa-right-left'; ?>"></i> <?php echo empty($booking['individual_room_id']) ? 'Assign room' : 'Change room'; ?></button>
                                                            <?php endif; ?>
                                                            <?php if ($can_change_room_type && $_perm_edit): ?>
                                                                <button type="button" data-action="upgrade-room" data-booking-id="<?php echo $bk_id; ?>" data-booking-ref="<?php echo $bk_ref; ?>" data-booking-status="<?php echo htmlspecialchars($bk_status, ENT_QUOTES); ?>"><i class="fas fa-arrow-up-right-dots"></i> Upgrade / change room type</button>
                                                            <?php endif; ?>
                                                            <?php if ($can_make_tentative): ?>
                                                                <button type="button" data-action="make-tentative" data-booking-id="<?php echo $bk_id; ?>" data-booking-ref="<?php echo $bk_ref; ?>" data-tentative-type="make_tentative"><i class="fas fa-hourglass-half"></i> Make tentative</button>
                                                            <?php endif; ?>
                                                            <?php if ($bk_inhouse && !$is_overdue_checkout && $_perm_checkin): ?>
                                                                <button type="button" onclick="updateStatus(<?php echo $bk_id; ?>, 'cancel-checkin')"><i class="fas fa-rotate-left"></i> Undo check-in</button>
                                                            <?php endif; ?>
                                                            <?php if ($bk_inhouse && $is_overdue_checkout && $_is_admin_user): ?>
                                                                <button type="button" onclick="openAdminChangeDateModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_js($booking['check_out_date']); ?>, <?php echo $bk_guest_js; ?>)"><i class="fas fa-calendar-pen"></i> Change checkout date</button>
                                                            <?php endif; ?>
                                                            <?php if ($can_consolidate): ?>
                                                                <button type="button" onclick="openConsolidationModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)"><i class="fas fa-scale-balanced"></i> Payments &amp; balance</button>
                                                            <?php endif; ?>

                                                            <hr class="menu-divider">
                                                            <button type="button" onclick="openResendEmailModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_js($bk_status); ?>, <?php echo ($is_missed_checkin || $is_overdue_checkout) ? 'true' : 'false'; ?>)"><i class="fas fa-envelope"></i> Resend email</button>
                                                            <?php if ($bk_doc === 'receipt' && $can_receipt): ?>
                                                                <button type="button" onclick="rhSendAccountReceipt('room', <?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)"><i class="fas fa-receipt"></i> Send receipt</button>
                                                            <?php endif; ?>
                                                            <?php if ($can_quote): ?>
                                                                <button type="button" onclick="openBookingListQuoteModal(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_guest_js; ?>, <?php echo $bk_js($booking['guest_email'] ?? ''); ?>)"><i class="fas fa-file-invoice-dollar"></i> Send quotation</button>
                                                                <?php if (!empty($booking['guest_phone'])): ?>
                                                                    <button type="button" onclick="openBookingListQuotationWhatsApp(<?php echo $bk_ref_js; ?>, <?php echo $bk_guest_js; ?>, <?php echo $bk_js($booking['guest_phone']); ?>)"><i class="fab fa-whatsapp"></i> Quotation via WhatsApp</button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <?php if ($can_invoice): ?>
                                                                <button type="button" onclick="sendInvoiceEmail(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, this)"><i class="fas fa-file-invoice"></i> Send invoice (balance due)</button>
                                                            <?php endif; ?>
                                                            <?php if ($bk_doc !== 'receipt' && $can_receipt): ?>
                                                                <button type="button" onclick="rhSendAccountReceipt('room', <?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)"><i class="fas fa-receipt"></i> Send receipt</button>
                                                            <?php endif; ?>
                                                        <?php endif; /* not deleted */ ?>

                                                        <hr class="menu-divider">
                                                        <button type="button" data-rh-history="booking:<?php echo (int)$bk_id; ?>" onclick="_closeAllActionMenus(null)" title="Every step for this booking, oldest first, with who did it"><i class="fas fa-list-ol"></i> History</button>
                                                        <button type="button" onclick="viewBookingAuditLog(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)"><i class="fas fa-clock-rotate-left"></i> Audit log</button>
                                                        <?php if ($bk_deleted && $_perm_delete): ?>
                                                            <button type="button" onclick="restoreDeletedBooking(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>)"><i class="fas fa-trash-arrow-up"></i> Restore booking</button>
                                                        <?php elseif ($can_delete): ?>
                                                            <button type="button" class="menu-item--danger" onclick="deleteBookingRecord(<?php echo $bk_id; ?>, <?php echo $bk_ref_js; ?>, <?php echo $bk_guest_js; ?>, <?php echo $bk_js($bk_status); ?>)"><i class="fas fa-trash-can"></i> Delete booking</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <?php if ($has_active_room_filters): ?>
                            <p>No room bookings match your current search filters.</p>
                            <a href="bookings.php" style="display: inline-block; margin-top: 8px; padding: 8px 14px; border: 1px solid #ddd; border-radius: 8px; color: #444; text-decoration: none; font-size: 13px;">Clear filters</a>
                        <?php else: ?>
                            <p>No room bookings yet.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php /* Shown whenever the list has more than 10 rows (page links only when there are pages), so the
                         rows-per-page choice is always there to switch back from 50 / All. */ ?>
                <?php if ($total_pages > 1 || $list_count > 10): ?>
                    <?php
                    $pg_params = array_filter([
                        'search'        => $search_query,
                        'filter_status' => $filter_status,
                        'date_from'     => $filter_date_from,
                        'date_to'       => $filter_date_to,
                        'date_type'     => $bk_has_dates ? $filter_date_type : '',
                        'filter'        => $bk_filter_key,   // keep the open tab (Unpaid, Today's Check-ins...) on page 2+
                        'arrival'       => $filter_arrival,
                        'departure'     => $filter_departure,
                        'balance'       => $filter_balance,
                        'size'          => $bk_page_size !== '10' ? $bk_page_size : '',   // rows per page kept on every page link
                    ], fn($v) => $v !== '');
                    $pg_params = array_merge($pg_params, rh_server_sort_params($bk_sort_key, $bk_sort_dir));
                    $pg_base = 'bookings.php?' . (empty($pg_params) ? '' : http_build_query($pg_params) . '&');
                    ?>
                    <nav class="bookings-pagination" data-admin-pagination>
                        <?php if ($total_pages > 1): ?>
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . ($current_page - 1), ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1">&lsaquo; Prev</a>
                        <?php endif; ?>

                        <?php
                        $pg_start = max(1, $current_page - 2);
                        $pg_end   = min($total_pages, $current_page + 2);
                        if ($pg_start > 1): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=1', ENT_QUOTES); ?>" class="pg-btn" data-no-spa="1">1</a>
                            <?php if ($pg_start > 2): ?><span class="pg-ellipsis">&hellip;</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($pg = $pg_start; $pg <= $pg_end; $pg++): ?>
                            <?php if ($pg === $current_page): ?>
                                <span class="pg-current"><?php echo $pg; ?></span>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($pg_base . 'page=' . $pg, ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1"><?php echo $pg; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($pg_end < $total_pages): ?>
                            <?php if ($pg_end < $total_pages - 1): ?><span class="pg-ellipsis">&hellip;</span><?php endif; ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . $total_pages, ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo htmlspecialchars($pg_base . 'page=' . ($current_page + 1), ENT_QUOTES); ?>" class="pg-btn" data-page-nav data-no-spa="1">Next &rsaquo;</a>
                        <?php endif; ?>
                        <?php endif; /* page links */ ?>

                        <span class="pg-summary">Showing <?php echo (($current_page - 1) * $per_page) + 1; ?>–<?php echo min($current_page * $per_page, $list_count); ?> of <?php echo $list_count; ?></span>
                        <?php /* Rows per page: the page script (data-bk-page-size) reloads the results at page 1, keeping filters, tab and sort. */ ?>
                        <label class="pg-size">Show <select aria-label="Rows per page" data-bk-page-size data-no-autofilter>
                            <?php foreach (array_merge(rh_bk_page_sizes(), rh_bk_all_size_allowed($list_count) ? ['all'] : []) as $bk_sz): ?>
                                <option value="<?php echo htmlspecialchars($bk_sz, ENT_QUOTES); ?>"<?php echo $bk_sz === $bk_page_size ? ' selected' : ''; ?>><?php echo $bk_sz === 'all' ? 'All' : htmlspecialchars($bk_sz); ?></option>
                            <?php endforeach; ?>
                        </select> per page</label>
                    </nav>
                <?php endif; ?>
            </div>
        </div><!-- /booking-results -->

        <?php if (!empty($show_conference_block)): ?>
        <!-- Conference enquiries & bookings (latest 10; the Conference page has the full list) -->
        <div class="bookings-section">
            <h3 class="section-title">
                <i class="fas fa-users"></i> Conference enquiries &amp; bookings
                <span style="font-size: 14px; font-weight: normal; color: #666;">
                    (<?php echo $conference_total > count($conference_inquiries) ? 'latest ' . count($conference_inquiries) . ' of ' . (int)$conference_total : (int)$conference_total . ' total'; ?>)
                </span>
                <a href="conference-management.php" class="rh-mini-link bookings-section__title-link" style="margin-left: 8px; font-size: 14px;">Open Conference page</a>
            </h3>

            <?php if (!empty($conference_inquiries)): ?>
                <div class="table-responsive">
                    <table class="booking-table tablet-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Date Received</th>
                                <th style="width: 220px;">Company/Name</th>
                                <th style="width: 220px;">Contact</th>
                                <th style="width: 180px;">Event Type</th>
                                <th style="width: 140px;">Expected Date</th>
                                <th style="width: 100px;">Attendees</th>
                                <th style="width: 140px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conference_inquiries as $inquiry): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($inquiry['company_name']); ?></strong>
                                        <br><small><?php echo htmlspecialchars($inquiry['contact_person']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($inquiry['email']); ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($inquiry['phone']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($inquiry['event_type']); ?></td>
                                    <td><?php echo !empty($inquiry['event_date']) ? htmlspecialchars((!empty($inquiry['end_date']) && $inquiry['end_date'] > $inquiry['event_date']) ? date('M d, Y', strtotime($inquiry['event_date'])) . ' – ' . date('M d, Y', strtotime($inquiry['end_date'])) : date('M d, Y', strtotime($inquiry['event_date']))) : '—'; ?></td>
                                    <td><?php echo $inquiry['number_of_attendees']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $inquiry['status']; ?>">
                                            <?php echo ucfirst($inquiry['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No conference enquiries or bookings yet.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Currency symbol, declared INSIDE #rh-admin-page so the admin SPA
        // re-runs it on every navigation to this page. The head-level copy only
        // executes on a full page load; when arriving here via an SPA link from
        // another page the head is never re-run, so without this in-content copy
        // fmt()/openCheckoutSettlementModal() below would hit
        // "_currencySymbol is not defined". Kept on window so the later
        // fmtMWK block (a separate SPA IIFE scope) resolves it too.
        window._currencySymbol = <?= json_encode($currency_symbol) ?>;
        var _currencySymbol = window._currencySymbol;

        // Ensure Alert is defined (fallback for early script execution)
        if (typeof Alert === 'undefined') {
            window.Alert = {
                show: function(message, type) {
                    // Fallback: silent no-op — Alert module not loaded yet
                }
            };
        }

        // Searching / filtering keeps the open tab and the overdue views. The form sits outside #booking-results
        // (tabs reload only the results), so the kept values are taken from the current URL at submit time,
        // never from what the page first rendered.
        if (!window.__bkKeepFiltersBound) {
            window.__bkKeepFiltersBound = true;
            document.addEventListener('submit', function(e) {
                const f = e.target;
                if (!f || !f.matches || !f.matches('[data-live-search-form="room-bookings"]')) return;
                const cur = new URLSearchParams(window.location.search);
                f.querySelectorAll('input[data-bk-keep]').forEach(i => i.remove());
                ['filter', 'payment_status', 'arrival', 'departure', 'balance', 'size'].forEach(k => {
                    const v = cur.get(k);
                    if (!v) return;
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = k;
                    i.value = v;
                    i.setAttribute('data-bk-keep', '');
                    f.appendChild(i);
                });
            }, true);
        }

        (function initRoomBookingsLiveSearch() {
            const searchForm = document.querySelector('[data-live-search-form="room-bookings"]');
            const searchInput = searchForm ? searchForm.querySelector('[data-live-search-input="room-bookings"]') : null;
            if (!searchForm || !searchInput) {
                return;
            }

            // Search as you type reloads only the results (loadBookingResults), so the box keeps focus and the page
            // does not jump down to the list. Same URL a submit would build: the form's fields plus the kept tab /
            // overdue / size values from the address bar (the capture listener above).
            function liveSearchUrl() {
                const keep = new URLSearchParams(window.location.search);
                const params = new URLSearchParams();
                new FormData(searchForm).forEach(function(v, k) {
                    if (typeof v === 'string' && !searchForm.querySelector('input[data-bk-keep][name="' + k + '"]')) params.append(k, v);
                });
                ['filter', 'payment_status', 'arrival', 'departure', 'balance', 'size'].forEach(function(k) {
                    const v = keep.get(k);
                    if (v) params.set(k, v);
                });
                return 'bookings.php' + (params.toString() ? '?' + params.toString() : '');
            }

            let searchDebounceTimer = null;
            searchInput.addEventListener('input', function() {
                window.clearTimeout(searchDebounceTimer);
                searchDebounceTimer = window.setTimeout(function() {
                    const query = searchInput.value.trim();
                    if (query.length === 0 || query.length >= 2) {
                        loadBookingResults(liveSearchUrl(), { live: true });
                    }
                }, 350);
            });
            // Enter in the box: the same in-place search at once (a button click still submits as before).
            searchForm.addEventListener('submit', function(e) {
                if (document.activeElement !== searchInput) return;
                e.preventDefault();
                window.clearTimeout(searchDebounceTimer);
                loadBookingResults(liveSearchUrl(), { live: true });
            });
        })();

        // ============================================
        // LOADING STATE MANAGEMENT FOR BUTTONS
        // ============================================

        /**
         * Set loading state on a button to prevent double-clicks
         * @param {HTMLElement} button - The button element
         * @param {boolean} isLoading - Whether to show loading state
         * @param {string} originalContent - Original button content (optional)
         */
        function setButtonLoading(button, isLoading, originalContent = null) {
            if (!button) return;

            if (isLoading) {
                // Store original content if not already stored
                if (!button.dataset.originalContent) {
                    button.dataset.originalContent = originalContent || button.innerHTML;
                }

                // Disable button and show loading spinner
                button.disabled = true;
                button.classList.add('btn-loading');
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.style.pointerEvents = 'none';
                button.style.opacity = '0.7';
            } else {
                // Restore original state
                button.disabled = false;
                button.classList.remove('btn-loading');
                button.innerHTML = button.dataset.originalContent || originalContent;
                button.style.pointerEvents = '';
                button.style.opacity = '';
                delete button.dataset.originalContent;
            }
        }

        /**
         * Set loading state on all quick-action buttons in a container
         * @param {HTMLElement} container - Container element
         * @param {boolean} isLoading - Loading state
         * @param {HTMLElement} excludeButton - Button to exclude (the one clicked)
         */
        function setAllButtonsLoading(container, isLoading, excludeButton = null) {
            const buttons = container.querySelectorAll('.quick-action, .btn');
            buttons.forEach(btn => {
                if (btn !== excludeButton) {
                    if (isLoading) {
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        btn.style.pointerEvents = 'none';
                    } else {
                        btn.disabled = false;
                        btn.style.opacity = '';
                        btn.style.pointerEvents = '';
                    }
                }
            });
        }

        /**
         * Show global loading overlay
         */
        function showLoadingOverlay(message = 'Processing...') {
            let overlay = document.getElementById('globalLoadingOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'globalLoadingOverlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 99999;
                `;
                overlay.innerHTML = `
                    <div style="background: white; padding: 30px 40px; border-radius: 12px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                        <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--gold, #7E684B); margin-bottom: 16px; display: block;"></i>
                        <div id="loadingMessage" style="font-size: 16px; color: var(--navy, #1A1A1A); font-weight: 500;">${message}</div>
                    </div>
                `;
                document.body.appendChild(overlay);
            } else {
                overlay.style.display = 'flex';
                document.getElementById('loadingMessage').textContent = message;
            }
        }

        /**
         * Hide global loading overlay
         */
        function hideLoadingOverlay() {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }

        // Confirm with an optional tick box (options.check = {label, help, checked}): resolves {confirmed, checked}.
        // Uses AdminConfirm.ask (admin-main.js); without it the box is treated as unticked.
        function confirmAdminActionWithCheck(options) {
            if (options && options.check && window.AdminConfirm && typeof window.AdminConfirm.ask === 'function') {
                return window.AdminConfirm.ask(options).then(r => ({ confirmed: !!(r && r.confirmed), checked: !!(r && r.confirmed && r.checkShown && r.checked) }));
            }
            return confirmAdminAction(options).then(c => ({ confirmed: !!c, checked: false }));
        }

        function confirmAdminAction(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options);
            }
            Alert.show('Confirmation controls are still loading. Please try again in a moment.', 'warning');
            return Promise.resolve(false);
        }

        function promptAdminAction(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.prompt === 'function') {
                return window.AdminConfirm.prompt(options);
            }
            Alert.show('Confirmation controls are still loading. Please try again in a moment.', 'warning');
            return Promise.resolve(null);
        }

        function showBookingActionMessage(message, type = 'info') {
            const text = String(message || '').trim();
            if (!text) {
                return;
            }
            if (window.Alert && typeof window.Alert.show === 'function') {
                // Alert.show renders HTML; these messages are plain text and can carry staff notes / guest names.
                Alert.show(text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'), type);
                return;
            }
            if (type === 'error') {
                console.error(text);
            } else {
                console.warn(text);
            }
        }

        const BOOKING_ACTION_MESSAGE_STORAGE_KEY = 'rh_booking_action_message';

        function queueBookingActionMessage(message, type = 'success') {
            const text = String(message || '').trim();
            if (!text) {
                return;
            }
            try {
                sessionStorage.setItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY, JSON.stringify({
                    message: text,
                    type: type || 'success'
                }));
            } catch (e) {
                console.warn('Unable to persist booking action message', e);
            }
        }

        function restoreQueuedBookingActionMessage() {
            try {
                const raw = sessionStorage.getItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
                if (!raw) {
                    return;
                }
                sessionStorage.removeItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
                const payload = JSON.parse(raw);
                if (!payload || !payload.message) {
                    return;
                }
                showBookingActionMessage(payload.message, payload.type || 'success');
            } catch (e) {
                sessionStorage.removeItem(BOOKING_ACTION_MESSAGE_STORAGE_KEY);
            }
        }

        // Set when a booking opened this page through a deep link (?action=...&booking_id=N):
        // finishing or cancelling that action goes back to the booking instead of leaving the
        // list filtered to one row (and a reload would re-open the modal).
        window.BK_DEEP_RETURN_URL = <?php echo json_encode($deep_link_booking_id > 0 ? 'booking-details.php?id=' . (int)$deep_link_booking_id : ''); ?>;
        window.bkDeepLeaving = false;

        function bkReturnOrReload() {
            if (window.BK_DEEP_RETURN_URL) {
                window.bkDeepLeaving = true;
                window.location.replace(window.BK_DEEP_RETURN_URL);
            } else {
                window.location.reload();
            }
        }

        function bkDeepCancel() {
            if (window.BK_DEEP_RETURN_URL && !window.bkDeepLeaving) {
                window.bkDeepLeaving = true;
                window.location.replace(window.BK_DEEP_RETURN_URL);
            }
        }

        function reloadWithBookingActionMessage(responseData, fallbackMessage = 'Action completed successfully.') {
            const message = responseData && responseData.message ? responseData.message : fallbackMessage;
            queueBookingActionMessage(message, 'success');
            bkReturnOrReload();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', restoreQueuedBookingActionMessage, {
                once: true
            });
        } else {
            restoreQueuedBookingActionMessage();
        }

        // Every booking action posts to this page explicitly. Never post to window.location: right after an
        // in-place (SPA) page change the address bar can still be on the previous page, and the POST would
        // land on the wrong script (it used to answer with a whole page that looked like success).
        function rhBookingsPostUrl() {
            return new URL('bookings.php', window.location.href).pathname;
        }
        window.rhBookingsPostUrl = rhBookingsPostUrl;

        function postBookingAction(formData, errorMessage, timeoutMs) {
            if (formData instanceof FormData && !formData.has('csrf_token')) {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = window._rhCsrf || (csrfMeta ? (csrfMeta.getAttribute('content') || '') : '');
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
            }

            const controller = new AbortController();
            const tid = setTimeout(() => controller.abort(), timeoutMs || 30000);

            return fetch(rhBookingsPostUrl(), {
                method: 'POST',
                body: formData,
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(async response => {
                clearTimeout(tid);
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    const data = await response.json();
                    if (!response.ok || data.success === false) {
                        const err = new Error(data.message || data.error || errorMessage || 'Action failed.');
                        err.data = data;
                        throw err;
                    }
                    return data;
                }

                // Anything that is not a JSON answer is NOT a confirmation (it is usually a page or a login
                // screen). Never report success for it.
                throw new Error('The server did not confirm this action. Please reload and check before trying again.');
            }).catch(err => {
                clearTimeout(tid);
                if (err.name === 'AbortError') {
                    throw new Error('The request timed out. Please try again.');
                }
                throw err;
            });
        }

        function setModalActionLoading(form, isLoading, loadingText) {
            if (!form || !(form instanceof HTMLElement)) {
                return;
            }
            const modalContent = form.closest('.modal-content');
            if (!modalContent) {
                return;
            }

            let loader = modalContent.querySelector('.modal-inline-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.className = 'modal-inline-loader';
                loader.setAttribute('hidden', 'hidden');
                loader.innerHTML = '<span class="modal-inline-loader__spinner" aria-hidden="true"></span><span class="modal-inline-loader__text">Processing...</span>';
                modalContent.appendChild(loader);
            }

            const textNode = loader.querySelector('.modal-inline-loader__text');
            if (textNode && loadingText) {
                textNode.textContent = loadingText;
            }

            if (isLoading) {
                loader.removeAttribute('hidden');
                modalContent.classList.add('is-modal-busy');
            } else {
                loader.setAttribute('hidden', 'hidden');
                modalContent.classList.remove('is-modal-busy');
            }
        }

        // Tab switching functionality
        let currentTab = 'all';

        function switchTab(tabName) {
            currentTab = tabName;

            // Update active tab button
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                }
            });

            // Filter table rows
            filterBookingsTable(tabName);

            // Update section title
            updateSectionTitle(tabName);
        }

        function filterBookingsTable(tabName) {
            const table = document.querySelector('.booking-table tbody');
            if (!table) return;

            const rows = table.querySelectorAll('tr');
            let visibleCount = 0;

            // "Today" is the hotel's date from the server (data-hotel-today), never the device clock, so a tablet
            // with the wrong date or time zone cannot hide rows the server listed for today.
            const _tbl = table.closest('table');
            const todayStr = (_tbl && /^\d{4}-\d{2}-\d{2}$/.test(_tbl.dataset.hotelToday || '')) ? _tbl.dataset.hotelToday : <?php echo json_encode(date('Y-m-d')); ?>;
            const _tp = todayStr.split('-').map(Number);
            const today = new Date(_tp[0], _tp[1] - 1, _tp[2]);

            // Calculate week start (7 days ago)
            const weekStart = new Date(today);
            weekStart.setDate(weekStart.getDate() - 7);

            // Calculate month start (first day of current month)
            const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

            rows.forEach(row => {
                const status = (row.dataset.status || '').trim().toLowerCase();
                const checkInDateStr = row.dataset.checkIn || '';
                const checkOutDateStr = row.dataset.checkOut || '';
                const createdDateStr = row.dataset.created || '';
                const createdDate = createdDateStr ? new Date(createdDateStr + 'T00:00:00') : null;

                const isExpiringSoon = row.dataset.expiringSoon === '1';
                const isTentative = row.dataset.tentative === '1';
                const isTodayCheckIn = checkInDateStr === todayStr && (status === 'confirmed' || status === 'pending');
                const isTodayCheckOut = checkOutDateStr === todayStr && status === 'checked-in';
                const isTodayBooking = createdDateStr === todayStr;
                const isWeekBooking = createdDate && createdDate >= weekStart;
                const isMonthBooking = createdDate && createdDate >= monthStart;

                let isVisible = false;

                switch (tabName) {
                    case 'all':
                        isVisible = true;
                        break;
                    case 'pending':
                        isVisible = status === 'pending';
                        break;
                    case 'tentative':
                        isVisible = status === 'tentative' || isTentative;
                        break;
                    case 'expiring-soon':
                        isVisible = isExpiringSoon;
                        break;
                    case 'confirmed':
                        isVisible = status === 'confirmed';
                        break;
                    case 'today-checkins':
                        isVisible = isTodayCheckIn;
                        break;
                    case 'today-checkouts':
                        isVisible = isTodayCheckOut;
                        break;
                    case 'checked-in':
                        isVisible = status === 'checked-in';
                        break;
                    case 'checked-out':
                        isVisible = status === 'checked-out';
                        break;
                    case 'cancelled':
                        isVisible = status === 'cancelled';
                        break;
                    case 'no-show':
                        isVisible = status === 'no-show';
                        break;
                    case 'paid':
                        // Server rule (rh_bk_payment_flags): the booking's own payment status only.
                        isVisible = row.dataset.paid === '1';
                        break;
                    case 'unpaid':
                        isVisible = row.dataset.unpaid === '1';
                        break;
                    case 'today-bookings':
                        isVisible = isTodayBooking;
                        break;
                    case 'week-bookings':
                        isVisible = isWeekBooking;
                        break;
                    case 'month-bookings':
                        isVisible = isMonthBooking;
                        break;
                    default:
                        // Generic status-named tab (e.g. "expired") that has no
                        // bespoke rule above — match the row's status directly so
                        // deep links like ?status=expired still show their rows
                        // instead of hiding everything.
                        isVisible = status === tabName;
                        break;
                }

                if (isVisible) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update count in section title
            const countSpan = document.querySelector('.section-title span');
            if (countSpan) {
                const totalRows = rows.length;
                if (visibleCount === totalRows) {
                    countSpan.textContent = `(${visibleCount} shown)`;
                } else {
                    countSpan.textContent = `(${visibleCount} of ${totalRows} shown)`;
                }
            }
        }

        function updateSectionTitle(tabName) {
            const titleElement = document.querySelector('.section-title');
            if (!titleElement) return;

            const tabTitles = {
                'all': 'All Room Bookings',
                'pending': 'Pending Bookings',
                'tentative': 'Tentative Bookings',
                'expiring-soon': 'Expiring Soon (Urgent)',
                'confirmed': 'Confirmed Bookings',
                'today-checkins': "Today's Check-ins",
                'today-checkouts': "Today's Check-outs",
                'checked-in': 'Checked In Guests',
                'checked-out': 'Checked Out Bookings',
                'cancelled': 'Cancelled Bookings',
                'no-show': 'No-Show Bookings',
                'deleted': 'Deleted Bookings',
                'paid': 'Paid Bookings',
                'unpaid': 'Unpaid Bookings',
                'today-bookings': "Today's Bookings",
                'week-bookings': "This Week's Bookings",
                'month-bookings': "This Month's Bookings"
            };

            const icon = titleElement.querySelector('i');
            const countSpan = titleElement.querySelector('span');

            let newTitle = tabTitles[tabName] || 'Room Bookings';
            let newIcon = 'fa-bed';

            if (tabName === 'pending') newIcon = 'fa-clock';
            if (tabName === 'tentative') newIcon = 'fa-hourglass-half';
            if (tabName === 'expiring-soon') newIcon = 'fa-exclamation-triangle';
            if (tabName === 'confirmed') newIcon = 'fa-check-circle';
            if (tabName === 'today-checkins') newIcon = 'fa-calendar-day';
            if (tabName === 'today-checkouts') newIcon = 'fa-calendar-times';
            if (tabName === 'checked-in') newIcon = 'fa-sign-in-alt';
            if (tabName === 'checked-out') newIcon = 'fa-sign-out-alt';
            if (tabName === 'cancelled') newIcon = 'fa-times-circle';
            if (tabName === 'no-show') newIcon = 'fa-user-slash';
            if (tabName === 'deleted') newIcon = 'fa-trash-can';
            if (tabName === 'paid') newIcon = 'fa-dollar-sign';
            if (tabName === 'unpaid') newIcon = 'fa-exclamation-circle';
            if (tabName === 'today-bookings') newIcon = 'fa-calendar-day';
            if (tabName === 'week-bookings') newIcon = 'fa-calendar-week';
            if (tabName === 'month-bookings') newIcon = 'fa-calendar-alt';

            titleElement.innerHTML = `<i class="fas ${newIcon}"></i> ${newTitle} `;
            if (countSpan) {
                titleElement.appendChild(countSpan);
            }
        }

        // ── Tab navigation: each tab navigates to a URL so filtering is always server-side ──
        // JS visual filtering (filterBookingsTable) is still applied to the loaded rows
        // as a fast visual layer, but the source of truth is always the server-rendered rows.

        const TAB_FILTER_MAP = {
            'pending': {
                filter_status: 'pending'
            },
            'tentative': {
                filter_status: 'tentative'
            },
            'expiring-soon': {
                filter: 'expiring_soon'
            },
            'confirmed': {
                filter_status: 'confirmed'
            },
            'today-checkins': {
                filter: 'checkin_today'
            },
            'today-checkouts': {
                filter: 'checkout_today'
            },
            'checked-in': {
                filter_status: 'checked-in'
            },
            'checked-out': {
                filter_status: 'checked-out'
            },
            'cancelled': {
                filter_status: 'cancelled'
            },
            'no-show': {
                filter_status: 'no-show'
            },
            'deleted': {
                filter_status: 'deleted'
            },
            'paid': {
                filter: 'paid'
            },
            'unpaid': {
                filter: 'unpaid'
            },
            'today-bookings': {
                filter: 'today_bookings'
            },
            'week-bookings': {
                filter: 'week_bookings'
            },
            'month-bookings': {
                filter: 'month_bookings'
            },
        };

        function shortQuickActionLabel(button, helpText) {
            const classMap = {
                view: 'View details',
                email: 'Email',
                whatsapp: 'WhatsApp',
                assign: 'Assign',
                upgrade: 'Upgrade',
                modify: 'Modify',
                refund: 'Refund',
                paid: 'Paid',
                checkin: 'Check in',
                checkout: 'Checkout',
                cancel: 'Cancel',
                tentative: 'Tentative',
                noshow: 'No-show',
                consolidation: 'Consolidate',
                extend: 'Nights',
                'actions-more-toggle': 'More'
            };

            for (const className in classMap) {
                if (button.classList.contains(className)) {
                    return classMap[className];
                }
            }

            const clean = String(helpText || '').replace(/\s+/g, ' ').trim();
            if (clean === '') {
                return '';
            }

            if (clean.length <= 14) {
                return clean;
            }

            const words = clean.split(' ');
            if (words.length > 1) {
                return words.slice(0, 2).join(' ');
            }

            return clean.slice(0, 14).trim();
        }

        function hydrateQuickActionButtons(scopeRoot) {
            const scope = scopeRoot || document;
            scope.querySelectorAll('.actions-row .quick-action').forEach(function(button) {
                const helpText = button.getAttribute('aria-label') || button.getAttribute('title') || button.textContent.trim();
                if (helpText) {
                    button.setAttribute('aria-label', helpText);
                    button.dataset.help = helpText;
                }

                const hasTextNode = Array.from(button.childNodes).some(function(node) {
                    return node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '';
                });
                if (hasTextNode && !button.querySelector('.label')) {
                    return;
                }

                const labelText = shortQuickActionLabel(button, helpText);
                if (!labelText) {
                    return;
                }

                let labelSpan = button.querySelector('.label');
                if (!labelSpan) {
                    labelSpan = document.createElement('span');
                    labelSpan.className = 'label';
                    button.appendChild(labelSpan);
                }
                labelSpan.textContent = labelText;
            });
        }

        if (!window.__bookingsQuickActionHydratorBound) {
            window.__bookingsQuickActionHydratorBound = true;
            document.addEventListener('rh:content-updated', function() {
                if (!/\/admin\/bookings\.php$/i.test(window.location.pathname)) {
                    return;
                }
                const bookingResults = document.getElementById('booking-results');
                hydrateQuickActionButtons(bookingResults || document);
            });
        }

        // ── AJAX results loading — only #booking-results reloads on tab/page switch ──
        // opts.live (search as you type): no scroll to the list, the address bar is replaced not pushed, and an
        // older search that answers late is dropped (only the newest search may draw the list).
        async function loadBookingResults(url, opts) {
            opts = opts || {};
            const container = document.getElementById('booking-results');
            if (!container) {
                window.location.href = url;
                return;
            }
            const seq = (window.__bkResultsSeq = (window.__bkResultsSeq || 0) + 1);
            if (window.__bkResultsAbort) {
                try { window.__bkResultsAbort.abort(); } catch (_) { /* ignore */ }
            }
            const ctrl = window.AbortController ? new AbortController() : null;
            window.__bkResultsAbort = ctrl;

            // Create and show minimal loader within the table section
            let loader = container.querySelector('.minimal-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.className = 'minimal-loader';
                loader.style.cssText = `
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(255, 255, 255, 0.95);
                    padding: 20px 30px;
                    border-radius: 10px;
                    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
                    z-index: 100;
                    display: none;
                    align-items: center;
                    gap: 12px;
                    pointer-events: none;
                `;
                loader.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 20px; color: var(--gold, #7E684B);"></i><span style="font-size: 14px; color: #444; font-weight: 500;">Loading...</span>';
                container.style.position = 'relative';
                container.appendChild(loader);
            }
            loader.style.display = 'flex';
            container.style.opacity = '0.6';
            container.style.pointerEvents = 'none';
            container.style.transition = 'opacity 0.15s ease';

            try {
                const res = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache'
                    },
                    signal: ctrl ? ctrl.signal : undefined
                });
                if (!res.ok) throw new Error('Network error');
                const html = await res.text();
                if (seq !== window.__bkResultsSeq) return; // a newer search or tab is on its way
                const doc = new DOMParser().parseFromString(html, 'text/html');

                // Swap results container content
                const fresh = doc.getElementById('booking-results');
                if (fresh) container.innerHTML = fresh.innerHTML;
                hydrateQuickActionButtons(container);

                // Keep pagination/tab navigation focused on the booking table area.
                // Shared anchoring: lands below the sticky header and stays put when the list is already in view.
                // Not while typing a search: the page stays where it is so the box stays under the user's fingers.
                const topAnchor = container.querySelector('[data-rh-results]') || container.querySelector('.bookings-section') || container;
                if (opts.live) {
                    // no scroll, no focus change
                } else if (window.rhAnchors) {
                    window.rhAnchors.scrollToResults(topAnchor);
                } else {
                    window.scrollTo({ top: Math.max(0, (topAnchor.getBoundingClientRect().top + window.scrollY) - 88), behavior: 'smooth' });
                }

                // Update tab badge counts from the freshly fetched page
                doc.querySelectorAll('.tab-button[data-tab]').forEach(btn => {
                    const local = document.querySelector('.tab-button[data-tab="' + btn.dataset.tab + '"]');
                    if (!local) return;
                    local.dataset.count = btn.dataset.count;
                    const span = local.querySelector('.tab-count');
                    if (span) span.textContent = btn.dataset.count;
                });

                // Update stat cards
                const freshNums = doc.querySelectorAll('.stat-card .number');
                document.querySelectorAll('.stat-card .number').forEach(function(n, i) {
                    if (freshNums[i]) n.textContent = freshNums[i].textContent;
                });

                // Push URL (replace while typing a search: Back is not filled with half-typed words) and re-apply
                // visual tab state
                if (opts.live) {
                    history.replaceState({ spa: true, url: new URL(url, window.location.href).href }, '', url);
                } else {
                    history.pushState({
                        url: url
                    }, '', url);
                }
                const activeTab = fresh ? (fresh.dataset.activeTab || 'all') : 'all';
                switchTab(activeTab);

                // Export CSV sits outside the results: point it at what the list now shows (search, tab, page size).
                const exportLink = document.querySelector('.bookings-toolbar__action--export');
                if (exportLink) {
                    const ex = new URL(window.location.href);
                    ['page', 'action', 'booking_id'].forEach(k => ex.searchParams.delete(k));
                    ex.searchParams.set('export', 'csv');
                    ex.hash = '';
                    exportLink.setAttribute('href', 'bookings.php' + ex.search);
                }

            } catch (err) {
                if (err && err.name === 'AbortError') return; // superseded by a newer search or tab
                if (seq !== window.__bkResultsSeq) return;
                window.location.href = url;
            } finally {
                if (seq === window.__bkResultsSeq) {
                    if (loader) loader.style.display = 'none';
                    container.style.opacity = '';
                    container.style.pointerEvents = '';
                    window.__bkResultsAbort = null;
                }
            }
        }

        function navigateToTab(tabName) {
            const params = new URLSearchParams(window.location.search);
            // A tab replaces every list filter that is not in the search bar: tab shortcut, status (and its
            // ?status= alias), overdue arrivals, Accounting's ?payment_status=, page.
            ['filter', 'filter_status', 'status', 'arrival', 'departure', 'balance', 'payment_status', 'page'].forEach(k => params.delete(k));
            const tabFilter = TAB_FILTER_MAP[tabName];
            if (tabFilter) {
                for (const [k, v] of Object.entries(tabFilter)) {
                    params.set(k, v);
                }
            }
            loadBookingResults('bookings.php' + (params.toString() ? '?' + params.toString() : ''));
        }

        // Browser back/forward support
        window.addEventListener('popstate', function() {
            loadBookingResults(window.location.href);
        });

        // Initialize — re-runs on every SPA navigation to this page.
        // NOTE: runs immediately when re-executed by admin-spa.js (DOMContentLoaded already fired).
        (function initBookingsPage() {
            const activeTab = <?php echo json_encode($active_tab_override ?: 'all'); ?>;

            // Register tab handler for the global admin-components.js tab system.
            // It will be called whenever any .tab-button is clicked on this page.
            window.__pageTabHandler = function(tabName, tabBtn) {
                if (tabBtn && !tabBtn.closest('.tabs-header')) return;
                if (tabName === 'all') {
                    const params = new URLSearchParams(window.location.search);
                    ['filter', 'filter_status', 'status', 'arrival', 'departure', 'balance', 'payment_status', 'page'].forEach(k => params.delete(k));
                    loadBookingResults('bookings.php' + (params.toString() ? '?' + params.toString() : ''));
                } else if (tabName) {
                    navigateToTab(tabName);
                }
            };

            // Pagination: delegated listener for .pg-btn links inside #booking-results
            if (!window.__bookingsPaginationBound) {
                window.__bookingsPaginationBound = true;
                document.addEventListener('click', function(e) {
                    if (!/bookings\.php/i.test(window.location.pathname)) return;
                    const pgBtn = e.target.closest('.pg-btn');
                    if (pgBtn && pgBtn.href) {
                        e.preventDefault();
                        loadBookingResults(pgBtn.href);
                    }
                });
            }

            // Rows per page (the pager's data-bk-page-size select): back to page 1 at the new size, keeping every
            // filter, tab and sort already in the URL; tabs, sort and search then keep ?size= from the URL.
            if (!window.__bookingsPageSizeBound) {
                window.__bookingsPageSizeBound = true;
                document.addEventListener('change', function(e) {
                    if (!/bookings\.php/i.test(window.location.pathname)) return;
                    const sel = e.target && e.target.closest ? e.target.closest('select[data-bk-page-size]') : null;
                    if (!sel) return;
                    const url = new URL(window.location.href);
                    ['page', 'action', 'booking_id', 'export'].forEach(k => url.searchParams.delete(k));
                    if (sel.value === '10') {
                        url.searchParams.delete('size');
                    } else {
                        url.searchParams.set('size', sel.value);
                    }
                    loadBookingResults(url.pathname + url.search);
                });
            }

            // Column-header sort (admin-main.js fires rh:server-sort): reload the results in place.
            if (!window.__bookingsSortBound) {
                window.__bookingsSortBound = true;
                document.addEventListener('rh:server-sort', function(e) {
                    if (!/bookings\.php/i.test(window.location.pathname)) return;
                    if (!document.getElementById('booking-results')) return;
                    e.preventDefault();
                    loadBookingResults(e.detail.url);
                });
            }

            // Highlight correct tab on initial load
            document.querySelectorAll('.tab-button').forEach(function(button) {
                button.classList.toggle('active', button.dataset.tab === activeTab);
            });

            // Apply visual filter on loaded rows
            switchTab(activeTab);
            hydrateQuickActionButtons(document);
        })();

        async function makeTentative(id, button) {
            const confirmed = await confirmAdminAction({
                title: 'Make booking tentative',
                message: 'Convert this pending booking to a tentative reservation?',
                details: [
                    'The room will be held for 48 hours.',
                    'A confirmation email will be sent to the guest.'
                ],
                confirmText: 'Make Tentative',
                icon: 'fa-hourglass-half'
            });
            if (!confirmed) return;

            // Show loading state
            if (button) {
                setButtonLoading(button, true);
            } else {
                const activeBtn = document.activeElement;
                if (activeBtn && activeBtn.classList.contains('quick-action')) {
                    setButtonLoading(activeBtn, true);
                }
            }
            showLoadingOverlay('Converting to tentative...');

            const formData = new FormData();
            formData.append('action', 'make_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking to tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking moved to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (button) {
                        setButtonLoading(button, false);
                    }
                    Alert.show(error.message || 'Error converting booking to tentative', 'error');
                });
        }

        async function convertTentativeBooking(id) {
            const confirmed = await confirmAdminAction({
                title: 'Confirm reservation',
                message: 'Convert this tentative booking to a confirmed reservation?',
                details: ['A confirmation email will be sent to the guest.'],
                confirmText: 'Confirm Booking',
                icon: 'fa-circle-check',
                tone: 'success'
            });
            if (!confirmed) return;

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Converting booking to confirmed...');

            const formData = new FormData();
            formData.append('action', 'convert_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking')
                .then(data => reloadWithBookingActionMessage(data, 'Tentative booking confirmed successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error converting booking', 'error');
                });
        }

        async function convertToTentative(id) {
            const confirmed = await confirmAdminAction({
                title: 'Move booking to tentative',
                message: 'Convert this confirmed booking to tentative?',
                details: [
                    'The booking will be placed on hold for 48 hours.',
                    'An email will be sent to the guest.'
                ],
                confirmText: 'Move to Tentative',
                icon: 'fa-clock'
            });
            if (!confirmed) return;

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Converting booking to tentative...');

            const formData = new FormData();
            formData.append('action', 'convert_to_tentative');
            formData.append('id', id);

            postBookingAction(formData, 'Error converting booking to tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking converted to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error converting booking to tentative', 'error');
                });
        }

        function updateStatus(id, status) {
            // Find the button that was clicked (if any)
            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            // Show loading state on button
            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }

            // Show global loading overlay
            showLoadingOverlay('Updating booking status...');

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', status);

            postBookingAction(formData, 'Error updating status')
                .then(data => reloadWithBookingActionMessage(data, 'Booking status updated successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error updating status', 'error');
                });
        }

        function updatePayment(id, payment_status) {
            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Updating payment status...');

            const formData = new FormData();
            formData.append('action', 'update_payment');
            formData.append('id', id);
            formData.append('payment_status', payment_status);

            postBookingAction(formData, 'Error updating payment')
                .then(data => reloadWithBookingActionMessage(data, 'Payment status updated successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error updating payment', 'error');
                });
        }

        async function cancelBooking(id, reference) {
            const reason = await promptAdminAction({
                title: 'Cancel booking',
                message: 'Cancel booking ' + reference + '?',
                details: ['The booking status will change to cancelled.', 'Room availability will be released where applicable.', 'Cancellation handling: ' + <?php echo json_encode(getCancellationRefundModeLabel(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>],
                confirmText: 'Cancel Booking',
                inputLabel: 'Cancellation reason (optional)',
                inputPlaceholder: 'Example: Guest requested cancellation',
                icon: 'fa-ban',
                tone: 'danger'
            });
            if (reason === null) {
                return;
            }

            const activeBtn = document.activeElement;
            const isQuickAction = activeBtn && activeBtn.classList.contains('quick-action');

            if (isQuickAction) {
                setButtonLoading(activeBtn, true);
            }
            showLoadingOverlay('Cancelling booking...');

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', 'cancelled');
            formData.append('cancellation_reason', reason || 'Cancelled by admin');

            postBookingAction(formData, 'Error cancelling booking')
                .then(data => reloadWithBookingActionMessage(data, 'Booking cancelled successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (isQuickAction) {
                        setButtonLoading(activeBtn, false);
                    }
                    Alert.show(error.message || 'Error cancelling booking', 'error');
                });
        }

        async function checkoutBooking(id, reference) {
            // Close any open bookings-alert-modal (e.g. overdueCheckoutsModal) so the
            // settlement modal or confirm dialog is not hidden behind it (z-index: 4000).
            document.querySelectorAll('.bookings-alert-modal.active').forEach(function(m) {
                setBookingPageModalOpen(m, false);
            });

            // Step 1: assess whether a financial settlement is needed
            const assessData = new FormData();
            assessData.append('action', 'checkout_assess');
            assessData.append('id', id);

            let settlement = null;
            try {
                showLoadingOverlay('Checking settlement...');
                const assessResp = await postBookingAction(assessData, 'Failed to assess checkout');
                settlement = assessResp.settlement || {
                    needed: false,
                    type: 'none'
                };
                settlement.balance_due = parseFloat(assessResp.balance_due || 0);
                settlement.can_override = !!assessResp.can_override;
                settlement.booked_rate_text = String(assessResp.booked_rate_text || '');
                // On a company account: what stays on the company's account (never blocks checkout, owner D2).
                window._csOnAccount = !!assessResp.company_name;
                window._csCompanyName = String(assessResp.company_name || '');
                window._csCompanyDue = parseFloat(assessResp.company_due || 0) || 0;
                window._csCompany = (assessResp.company_name && parseFloat(assessResp.company_due || 0) > 0.01)
                    ? 'On account: ' + String(assessResp.company_name) + ' owes ' + fmt(assessResp.company_due) + '. It stays on the company account and the final invoice goes to the company billing email.'
                    : '';
                // Pay-later booking: an UNTICKED "Email the final invoice to the guest" box in the confirm (owner 2026-11-13).
                window._csEmailBox = (assessResp.pay_later && assessResp.guest_email_on_file) ? {
                    label: 'Email the final invoice to the guest',
                    help: 'Pay-later booking' + (assessResp.pay_later_note ? ' (' + String(assessResp.pay_later_note) + ')' : '') + ': the invoice is kept on the booking and is not emailed unless you tick this.',
                    checked: false
                } : null;
            } catch (err) {
                hideLoadingOverlay();
                Alert.show(err.message || 'Could not assess checkout', 'error');
                return;
            }
            hideLoadingOverlay();

            if (!settlement.needed) {
                // Standard on-time checkout: simple confirm then execute
                const owes = settlement.balance_due > 0.01;
                if (owes && !settlement.can_override) {
                    Alert.show('Checkout blocked: the guest still owes ' + fmt(settlement.balance_due) + '. Record the payment first, or ask someone with the "Check out with balance" permission.', 'error');
                    return;
                }
                const coDetails = ['The booking will be marked as checked out.', 'Room status and audit history will be updated.'];
                if (settlement.booked_rate_text) {
                    // The rate this stay was booked at x its nights (today's room price does not change it).
                    coDetails.unshift('Room at the booked rate: ' + settlement.booked_rate_text);
                }
                if (owes) {
                    coDetails.unshift('OUTSTANDING BALANCE: ' + fmt(settlement.balance_due) + ' - checking out with this balance will be recorded in the booking timeline.');
                }
                if (window._csCompany) coDetails.unshift(window._csCompany);
                const coEmailBox = window._csEmailBox || null;
                const coAnswer = await confirmAdminActionWithCheck({
                    title: owes ? 'Check out with balance?' : 'Confirm checkout',
                    message: 'Check out booking ' + reference + '?',
                    details: coDetails,
                    confirmText: owes ? 'Check Out with Balance' : 'Check Out',
                    icon: 'fa-right-from-bracket',
                    check: coEmailBox ? Object.assign({}, coEmailBox, { checked: false }) : undefined
                });
                if (!coAnswer.confirmed) return;

                showLoadingOverlay('Checking out booking...');

                const formData = new FormData();
                formData.append('action', 'checkout');
                formData.append('id', id);
                if (owes) formData.append('confirm_checkout_with_balance', '1');
                if (coEmailBox && coAnswer.checked) formData.append('email_final_invoice', '1');
                postBookingAction(formData, 'Error checking out booking')
                    .then(data => reloadWithBookingActionMessage(data, 'Booking checked out successfully.'))
                    .catch(error => {
                        hideLoadingOverlay();
                        Alert.show(error.message || 'Error checking out booking', 'error');
                    });
            } else {
                // Open the settlement modal
                window._csBalance = {
                    due: settlement.balance_due || 0,
                    can: !!settlement.can_override,
                    newRate: String(settlement.new_rate_text || '')
                };
                openCheckoutSettlementModal(id, reference, settlement);
            }
        }

        // ── Checkout Settlement Modal ─────────────────────────────────────────
        function fmt(n) {
            return _currencySymbol + ' ' + parseFloat(n).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // The settlement asks WHEN the guest left (owner 2026-11-12). Every leave date's figures come from the server
        // (checkout_assess, the same helper the settle step charges with); this only picks the matching quote.
        function csSelectedLeave() {
            const s = window._csState && window._csState.s;
            const picked = document.querySelector('#checkoutSettlementForm input[name="cs_leave"]:checked');
            if (!s || !picked) return { choice: '', date: '' };
            if (picked.value === 'skip') return { choice: 'skip', date: '' };
            if (picked.value === 'booked') return { choice: 'booked', date: s.sched_ymd };
            if (picked.value === 'today') return { choice: 'today', date: s.today_ymd };
            const v = String(document.getElementById('cs_leave_other').value || '');
            return { choice: 'other', date: v };
        }

        function csNeedsBasis(s, date) {
            return !!(s && s.arrival_ymd && date && date > s.sched_ymd);
        }

        // The quote for the current choice, or null (with a reason) when the choice is incomplete.
        function csCurrentQuote() {
            const s = window._csState && window._csState.s;
            const sel = csSelectedLeave();
            if (!s || !sel.choice) return { q: null, why: 'Choose when the guest left.' };
            if (sel.choice === 'skip') return { q: null, skip: true, why: '' };
            const row = sel.date && s.quotes ? s.quotes[sel.date] : null;
            if (!row) return { q: null, why: 'Pick a date between ' + (s.check_in_date || '') + ' and today (' + s.today_fmt + ').' };
            let basis = 'booked';
            if (csNeedsBasis(s, sel.date)) {
                const b = document.querySelector('#checkoutSettlementForm input[name="cs_basis"]:checked');
                if (!b) return { q: null, why: 'Choose how to count the nights.', row: row, date: sel.date };
                basis = b.value;
            }
            const q = row[basis];
            if (!q) return { q: null, why: 'That way of counting is not possible for this date.' };
            return { q: q, basis: basis, date: sel.date, row: row };
        }

        function csMoneyLine(q) {
            if (!q) return '';
            if (q.direction === 'charge') return 'Charge ' + fmt(q.amount) + ' (' + q.extra_nights + ' night(s) more than booked)';
            if (q.direction === 'refund') return 'Bill reduced by ' + fmt(q.amount) + ' (' + q.unused_nights + ' unused night(s))';
            return 'No money change';
        }

        function renderCheckoutSettlement() {
            const st = window._csState;
            if (!st) return;
            const s = st.s;
            const quoteEl = document.getElementById('cs_quote');
            const chargeRow = document.getElementById('cs_charge_row');
            const refundRow = document.getElementById('cs_refund_row');
            const proceedBtn = document.getElementById('cs_proceed_btn');
            const basisBox = document.getElementById('cs_basis_box');
            const otherInput = document.getElementById('cs_leave_other');
            const sel = csSelectedLeave();

            otherInput.disabled = sel.choice !== 'other';
            const showBasis = csNeedsBasis(s, sel.date);
            basisBox.hidden = !showBasis;
            basisBox.querySelectorAll('input[name="cs_basis"]').forEach(function (r) { r.disabled = !showBasis; if (!showBasis) r.checked = false; });
            if (showBasis && s.quotes[sel.date]) {
                const row = s.quotes[sel.date];
                document.getElementById('cs_basis_booked_help').textContent = row.booked ? (csMoneyLine(row.booked) + '. ' + row.booked.describe) : '';
                document.getElementById('cs_basis_actual_help').textContent = row.actual ? (csMoneyLine(row.actual) + '. ' + row.actual.describe) : '';
            }

            const cur = csCurrentQuote();
            chargeRow.style.display = 'none';
            refundRow.style.display = 'none';
            quoteEl.hidden = false;
            const due = parseFloat((window._csBalance || {}).due || 0);
            if (cur.skip) {
                quoteEl.innerHTML = '<p style="margin:0;color:#555;">No charge or refund is recorded. The booked stay (' + escHtml(s.check_in_date) + ' to ' + escHtml(s.sched_checkout) + ', ' + s.booked_nights + ' night(s)) and its total stay as they are.</p>';
                proceedBtn.disabled = false;
                proceedBtn.textContent = 'Check out (skip settlement)';
                proceedBtn.className = 'btn btn-secondary';
                st.balanceAfter = due;
                return;
            }
            if (!cur.q) {
                quoteEl.innerHTML = cur.why ? '<p style="margin:0;color:#856404;">' + escHtml(cur.why) + '</p>' : '';
                quoteEl.hidden = !cur.why;
                proceedBtn.disabled = true;
                proceedBtn.textContent = 'Proceed & Checkout';
                proceedBtn.className = 'btn btn-primary';
                return;
            }
            const q = cur.q;
            const received = q.direction === 'charge' ? Math.max(0, parseFloat(document.getElementById('cs_amount_received').value || '0') || 0) : 0;
            let balanceAfter = due;
            // On a company account the room nights are the company's: extra / unused nights move the company share, not
            // the guest's (the server re-checks the real split before checkout).
            if (!window._csOnAccount && q.direction === 'charge') balanceAfter = Math.max(0, due + parseFloat(q.amount) - received);
            if (!window._csOnAccount && q.direction === 'refund') balanceAfter = Math.max(0, due - parseFloat(q.amount));
            // On account (owner D2): the extra nights go on the company's share (no override); money received now is the
            // guest's and pays the guest's own share first, any rest lowers the company's share.
            let companyAfter = null;
            if (window._csOnAccount && q.direction === 'charge') {
                balanceAfter = Math.max(0, due - received);
                companyAfter = Math.max(0, (window._csCompanyDue || 0) + parseFloat(q.amount) - Math.max(0, received - due));
            }
            st.balanceAfter = balanceAfter;
            const rows = [
                ['Nights', q.nights + ' night(s): ' + q.stay_text],
                ...(q.new_rate_text ? [['Room for these nights<br><small style="color:#666;">booked rate &times; nights</small>', escHtml(q.new_rate_text)]] : []),
                ...(q.direction === 'charge' ? [['Extra nights', String(q.extra_nights)], ['Added to the bill', '<strong>' + fmt(q.amount) + '</strong>']] : []),
                ...(q.direction === 'refund' ? [['Unused nights', String(q.unused_nights)], ['Bill reduced by', fmt(q.amount)], ['Refund now (capped at paid)', '<strong>' + fmt(q.refundable) + '</strong>']] : []),
                ['Paid so far', fmt(s.amount_paid)],
                [window._csOnAccount ? 'Guest owes after checkout' : 'Balance after checkout', '<strong>' + fmt(balanceAfter) + '</strong>'],
                ...(companyAfter !== null ? [[escHtml(window._csCompanyName) + ' owes after checkout<br><small style="color:#666;">stays on the company account</small>', fmt(companyAfter)]] : [])
            ];
            quoteEl.innerHTML = '<p style="margin:0 0 8px;">' + escHtml(q.describe) + '</p>' +
                '<table class="cs-table">' + rows.map(function (r) { return '<tr><td>' + r[0] + '</td><td>' + r[1] + '</td></tr>'; }).join('') + '</table>';
            if (q.direction === 'charge') {
                chargeRow.style.display = '';
                document.getElementById('cs_amount_received').max = String((due + parseFloat(q.amount)).toFixed(2));
                proceedBtn.textContent = 'Charge & Checkout';
                proceedBtn.className = 'btn btn-danger';
            } else if (q.direction === 'refund') {
                const refundDue = parseFloat(q.refundable) > 0;
                refundRow.style.display = refundDue ? '' : 'none';
                // The method(s) THIS refund amount goes back to (server-computed per leave date).
                const methods = q.refund_methods ? String(q.refund_methods) : 'the original payment method';
                document.getElementById('cs_refund_methods').textContent = methods;
                document.getElementById('cs_refund_as_original_help').textContent = fmt(q.refundable) + ' back to ' + methods + '.';
                const asBox = document.getElementById('cs_refund_as_box');
                const scAllowed = refundDue && !!s.store_credit_allowed;
                asBox.hidden = !scAllowed;
                asBox.querySelectorAll('input[name="cs_refund_as"]').forEach(function (r) { r.disabled = !scAllowed; });
                const asPicked = scAllowed ? document.querySelector('#checkoutSettlementForm input[name="cs_refund_as"]:checked') : null;
                const isSc = !!asPicked && asPicked.value === 'store_credit';
                document.getElementById('cs_refund_method_line').hidden = scAllowed;
                proceedBtn.textContent = refundDue ? (isSc ? 'Store Credit & Checkout' : 'Refund & Checkout') : 'Checkout (no refund due)';
                proceedBtn.className = 'btn btn-success';
                if (scAllowed && !asPicked) {
                    proceedBtn.disabled = true; // front desk picks how the refund is paid
                    return;
                }
            } else {
                proceedBtn.textContent = 'Checkout (no charge)';
                proceedBtn.className = 'btn btn-primary';
            }
            proceedBtn.disabled = false;
        }

        function openCheckoutSettlementModal(bookingId, reference, s) {
            const modal = document.getElementById('checkoutSettlementModal');
            const form = document.getElementById('checkoutSettlementForm');
            if (!modal || !form) return;

            form.reset();
            form.dataset.busy = '';
            setModalActionLoading(form, false);
            const proceedBtn = document.getElementById('cs_proceed_btn');
            proceedBtn.classList.remove('btn-loading');
            proceedBtn.style.pointerEvents = '';
            proceedBtn.style.opacity = '';
            delete proceedBtn.dataset.originalContent;
            document.getElementById('cs_error').style.display = 'none';

            window._csState = { bookingId: bookingId, reference: reference, s: s, balanceAfter: 0 };
            document.getElementById('cs_booking_id').value = bookingId;
            document.getElementById('cs_booking_ref').value = reference;
            document.getElementById('cs_settlement_type').value = s.type || 'leave';

            const name = s.guest_name ? escHtml(s.guest_name) : escHtml(reference);
            const room = s.room_label ? ' &nbsp;|&nbsp; Room <strong>' + escHtml(s.room_label) + '</strong>' : '';
            document.getElementById('cs_title').innerHTML = s.overdue
                ? '<i class="fas fa-exclamation-triangle" style="color:#ffc107;"></i> Checkout after the booked date'
                : '<i class="fas fa-rotate-left" style="color:#28a745;"></i> Checkout before the booked date';
            document.getElementById('cs_body').innerHTML =
                '<div style="background:#f7f3ee;border-radius:6px;padding:10px 12px;font-size:13px;line-height:1.7;">' +
                '<strong>' + name + '</strong>' + room + '<br>' +
                '<span style="color:#666;">Ref: ' + escHtml(s.booking_ref || reference) + '</span>&nbsp;&nbsp;' +
                '<span style="color:#666;">Booked: ' + escHtml(s.check_in_date || '') + ' to ' + escHtml(s.sched_checkout || '') + ' (' + s.booked_nights + ' night(s))</span>' +
                (s.arrival_fmt ? '<br><span style="color:#666;">Arrival recorded at check-in: ' + escHtml(s.arrival_fmt) + '</span>' : '') +
                (s.booked_rate_text ? '<br><span style="color:#666;">Booked at: ' + escHtml(s.booked_rate_text) + '</span>' : '') +
                '</div>';

            // Choices: "booked date" only once that date has passed; "today"; any other date from the stay start.
            const bookedWrap = document.getElementById('cs_opt_booked_wrap');
            bookedWrap.hidden = !s.overdue;
            bookedWrap.querySelector('input').disabled = !s.overdue;
            const qb = s.quotes && s.quotes[s.sched_ymd] ? s.quotes[s.sched_ymd].booked : null;
            const qt = s.quotes && s.quotes[s.today_ymd] ? s.quotes[s.today_ymd].booked : null;
            document.getElementById('cs_opt_booked_label').textContent = 'Guest left on the booked date (' + s.sched_checkout + ')';
            document.getElementById('cs_opt_booked_help').textContent = qb ? csMoneyLine(qb) + '.' : '';
            document.getElementById('cs_opt_today_label').textContent = (s.overdue ? 'Guest stayed until today (' : 'Guest is leaving today (') + s.today_fmt + ')';
            document.getElementById('cs_opt_today_help').textContent = qt ? csMoneyLine(qt) + (s.arrival_ymd && s.overdue ? ' if the booked nights are kept.' : '.') : '';
            const other = document.getElementById('cs_leave_other');
            other.min = s.min_ymd || '';
            other.max = s.today_ymd || '';
            other.value = '';
            document.getElementById('cs_opt_other_help').textContent = 'Pick the day the guest left (' + (s.check_in_date || '') + ' to ' + s.today_fmt + ').';
            document.getElementById('cs_arrival_fmt').textContent = s.arrival_fmt || '';

            renderCheckoutSettlement();
            setBookingPageModalOpen(modal, true);
        }

        function closeCheckoutSettlementModal() {
            const modal = document.getElementById('checkoutSettlementModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // Exported for the overdue-checkout modal's inline onclick buttons —
        // reliable under SPA re-execution (see note by the modal handlers below).
        window.checkoutBooking = checkoutBooking;
        window.openCheckoutSettlementModal = openCheckoutSettlementModal;
        window.closeCheckoutSettlementModal = closeCheckoutSettlementModal;

        function escHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        const bookingsStatsInsights = <?php echo json_encode($bookings_stats_insights, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let bookingsStatsInsightLastTrigger = null;

        function formatBookingsInsightDate(value) {
            const rawValue = String(value || '').trim();
            if (rawValue === '') {
                return '—';
            }

            const parsed = new Date(rawValue + 'T00:00:00');
            if (Number.isNaN(parsed.getTime())) {
                return rawValue;
            }

            return parsed.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function formatBookingsInsightCurrency(value) {
            const numericValue = Number.parseFloat(value);
            if (!Number.isFinite(numericValue)) {
                return _currencySymbol + ' 0.00';
            }

            return _currencySymbol + ' ' + numericValue.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatBookingsInsightStatus(value) {
            return String(value || '')
                .replace(/[_-]/g, ' ')
                .replace(/\b\w/g, function(match) {
                    return match.toUpperCase();
                });
        }

        function renderBookingsStatsInsight(cardKey) {
            const payload = bookingsStatsInsights && Object.prototype.hasOwnProperty.call(bookingsStatsInsights, cardKey) ? bookingsStatsInsights[cardKey] : null;
            const titleEl = document.getElementById('bookingsStatsInsightTitle');
            const subtitleEl = document.getElementById('bookingsStatsInsightSubtitle');
            const summaryEl = document.getElementById('bookingsStatsInsightSummary');
            const rowsEl = document.getElementById('bookingsStatsInsightRows');
            const linkEl = document.getElementById('bookingsStatsInsightLink');

            if (!payload || !titleEl || !subtitleEl || !summaryEl || !rowsEl || !linkEl) {
                return false;
            }

            const countValue = Number.parseInt(payload.count, 10);
            const totalLabel = Number.isFinite(countValue) ? countValue.toLocaleString('en-US') : '0';
            const rows = Array.isArray(payload.rows) ? payload.rows : [];

            titleEl.innerHTML = '<i class="fas fa-chart-pie"></i> ' + escHtml(payload.title || 'Booking Insight');
            subtitleEl.textContent = payload.subtitle || 'Latest booking records.';
            summaryEl.innerHTML =
                '<div class="bookings-stats-insight-modal__kpi">' + escHtml(totalLabel) + '</div>' +
                '<p>' + escHtml(payload.summary || 'Latest records for this booking card.') + '</p>';

            if (!rows.length) {
                rowsEl.innerHTML = '<tr class="bookings-alert-modal__empty-row"><td colspan="6">' + escHtml(payload.empty || 'No matching records right now.') + '</td></tr>';
            } else {
                rowsEl.innerHTML = rows.map(function(row) {
                    const detailsHref = row.details_href ? String(row.details_href) : ('booking-details.php?id=' + encodeURIComponent(String(row.id || '0')));
                    const stayText = formatBookingsInsightDate(row.check_in) + ' - ' + formatBookingsInsightDate(row.check_out);
                    const paymentLabel = formatBookingsInsightStatus(row.payment_status || 'unknown');
                    const statusLabel = formatBookingsInsightStatus(row.status || 'unknown');

                    return '<tr>' +
                        '<td data-label="Reference"><strong>' + escHtml(row.reference || '—') + '</strong></td>' +
                        '<td data-label="Guest">' + escHtml(row.guest_name || '—') + '<br><small class="bookings-stats-insight-modal__muted">' + escHtml(statusLabel) + '</small></td>' +
                        '<td data-label="Room">' + escHtml(row.room || 'Unassigned') + '</td>' +
                        '<td data-label="Stay">' + escHtml(stayText) + '</td>' +
                        '<td data-label="Total">' + escHtml(formatBookingsInsightCurrency(row.amount)) + '<br><small class="bookings-stats-insight-modal__muted">' + escHtml(paymentLabel) + '</small></td>' +
                        '<td data-label="Actions"><a class="btn btn-sm btn-primary bookings-stats-insight-modal__row-action" href="' + escHtml(detailsHref) + '" data-no-spa="1" data-no-admin-loader="1">View</a></td>' +
                        '</tr>';
                }).join('');
            }

            const payloadLink = payload.link && typeof payload.link === 'object' ? payload.link : null;
            if (payloadLink && payloadLink.href) {
                linkEl.href = String(payloadLink.href);
                linkEl.textContent = payloadLink.label || 'Open full list';
                linkEl.style.display = '';
            } else {
                linkEl.style.display = 'none';
            }

            return true;
        }

        function openBookingsStatsInsightModal(cardKey, triggerEl) {
            const modal = document.getElementById('bookingsStatsInsightModal');
            if (!modal || !renderBookingsStatsInsight(cardKey)) {
                return;
            }

            bookingsStatsInsightLastTrigger = triggerEl instanceof HTMLElement ? triggerEl : null;
            setBookingPageModalOpen(modal, true);

            const closeBtn = modal.querySelector('.bookings-alert-modal__close');
            if (closeBtn instanceof HTMLElement) {
                requestAnimationFrame(function() {
                    closeBtn.focus();
                });
            }
        }

        function closeBookingsStatsInsightModal() {
            const modal = document.getElementById('bookingsStatsInsightModal');
            if (!modal) {
                return;
            }

            setBookingPageModalOpen(modal, false);

            if (bookingsStatsInsightLastTrigger instanceof HTMLElement && document.contains(bookingsStatsInsightLastTrigger)) {
                requestAnimationFrame(function() {
                    bookingsStatsInsightLastTrigger.focus();
                });
            }
        }

        async function handleCheckoutSettlementSubmit(e) {
            e.preventDefault();
            const form = e.target;
            // getAttribute: this form has an input named "id", so form.id is that input, not the form's id
            if (!form || !form.getAttribute || form.getAttribute('id') !== 'checkoutSettlementForm') {
                return;
            }
            if (form.dataset.busy === '1') return; // a request is already running
            const st = window._csState;
            const errEl = document.getElementById('cs_error');
            const showErr = function (msg) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
                Alert.show(escHtml(msg), 'error'); // Alert.show renders HTML: server text (notes, names) shown as text
            };
            errEl.style.display = 'none';
            if (!st) return;

            const bookingId = document.getElementById('cs_booking_id').value;
            const bookingRef = document.getElementById('cs_booking_ref').value;
            const cur = csCurrentQuote();
            if (!cur.skip && !cur.q) {
                showErr(cur.why || 'Choose when the guest left.');
                return;
            }
            const q = cur.q;
            const received = q && q.direction === 'charge' ? Math.max(0, parseFloat(document.getElementById('cs_amount_received').value || '0') || 0) : 0;
            if (q && q.direction === 'charge' && received > parseFloat((window._csBalance || {}).due || 0) + parseFloat(q.amount) + 0.01) {
                showErr('The amount received is more than the guest owes.');
                return;
            }

            const formData = new FormData();
            formData.set('action', 'checkout_settle');
            formData.set('id', bookingId);
            formData.set('settlement_action', cur.skip ? 'skip' : 'leave');
            if (!cur.skip) {
                formData.set('leave_date', cur.date);
                if (csNeedsBasis(st.s, cur.date)) formData.set('stay_basis', cur.basis);
                // A refund goes back to the original payment method(s), or store credit when allowed (as the normal Refund).
                if (q.direction === 'charge' && received > 0) {
                    formData.set('amount_received', received.toFixed(2));
                    formData.set('payment_method', document.getElementById('cs_payment_method').value);
                }
                if (q.direction === 'refund' && parseFloat(q.refundable) > 0) {
                    const asPicked = st.s.store_credit_allowed ? document.querySelector('#checkoutSettlementForm input[name="cs_refund_as"]:checked') : null;
                    if (st.s.store_credit_allowed && !asPicked) {
                        showErr('Choose how the refund is paid: back to the original payment method, or store credit.');
                        return;
                    }
                    formData.set('refund_as', asPicked ? asPicked.value : 'original');
                }
            }
            formData.set('notes', document.getElementById('cs_notes').value);

            const submitBtn = document.getElementById('cs_proceed_btn');
            const csBal = window._csBalance || { due: 0, can: false };
            const balanceAfter = parseFloat(st.balanceAfter || 0);
            const csDetails = [cur.skip ? 'Skip settlement: no charge or refund recorded, booked stay unchanged.' : q.describe];
            if (q && q.direction === 'charge') {
                csDetails.push(received > 0 ? 'Received now: ' + fmt(received) : 'Nothing received now: the extra nights stay on the bill as a balance.');
            }
            if (q && q.direction === 'refund' && parseFloat(q.refundable) > 0) {
                csDetails.push(formData.get('refund_as') === 'store_credit'
                    ? 'Refund now: ' + fmt(q.refundable) + ' as store credit (a credit note).'
                    : 'Refund now: ' + fmt(q.refundable) + ' back to ' + (q.refund_methods ? String(q.refund_methods) : 'the original payment method') + '.');
            }
            csDetails.push('This will mark the booking as checked out and update room status.');
            if (window._csCompany) csDetails.unshift(window._csCompany);
            if (balanceAfter > 0.01 && csBal.can) {
                csDetails.unshift('OUTSTANDING BALANCE after checkout: ' + fmt(balanceAfter) + ' - it stays owed and is recorded in the booking timeline.');
                formData.set('confirm_checkout_with_balance', '1');
            } else if (balanceAfter > 0.01) {
                // "Amount received" is only on screen when the guest owes extra nights; otherwise the balance is from
                // before this checkout and is paid on the booking itself.
                showErr(q && q.direction === 'charge'
                    ? 'The guest would still owe ' + fmt(balanceAfter) + '. Enter the amount received, or ask someone with the "Check out with balance" permission.'
                    : 'The guest still owes ' + fmt(balanceAfter) + ' from before this checkout. Record that payment on the booking first (Record Payment), then check out, or ask someone with the "Check out with balance" permission.');
                return;
            }
            const csEmailBox = window._csEmailBox ? Object.assign({}, window._csEmailBox, { checked: false }) : null;
            const csAnswer = await confirmAdminActionWithCheck({
                title: 'Confirm checkout',
                message: 'Complete checkout for booking ' + (bookingRef || ('#' + bookingId)) + '?',
                details: csDetails,
                confirmText: 'Complete Checkout',
                icon: 'fa-right-from-bracket',
                check: csEmailBox || undefined
            });
            if (!csAnswer.confirmed) {
                return;
            }
            if (csEmailBox && csAnswer.checked) formData.set('email_final_invoice', '1');

            form.dataset.busy = '1';
            if (submitBtn) setButtonLoading(submitBtn, true);
            setModalActionLoading(form, true, 'Processing settlement and checkout...');

            try {
                // The final invoice may be emailed: give the server longer than the 30 s default.
                const data = await postBookingAction(formData, 'Checkout failed', 90000);
                closeCheckoutSettlementModal();
                showLoadingOverlay('Updating the list...');
                reloadWithBookingActionMessage(data, 'Checkout completed successfully.');
            } catch (err) {
                form.dataset.busy = '';
                setModalActionLoading(form, false);
                if (submitBtn) setButtonLoading(submitBtn, false);
                const msg = String((err && err.message) || 'Checkout failed.');
                showErr(msg.indexOf('timed out') !== -1
                    ? 'The server took too long to answer. The checkout may still have gone through: reload the page and check the booking before trying again.'
                    : msg);
            }
        }
        window.handleCheckoutSettlementSubmit = handleCheckoutSettlementSubmit;

        // Bound ONCE per browser page: the admin SPA re-runs this script on every visit, and a second
        // document-level listener used to open the confirm twice (and post twice).
        if (!window.__rhCsSubmitBound) {
            window.__rhCsSubmitBound = true;
            document.addEventListener('submit', function(e) {
                if (e.target && e.target.getAttribute && e.target.getAttribute('id') === 'checkoutSettlementForm' && typeof window.handleCheckoutSettlementSubmit === 'function') {
                    window.handleCheckoutSettlementSubmit(e);
                }
            });
        }
        window.renderCheckoutSettlement = renderCheckoutSettlement;
        // The settlement form is printed AFTER this script, so its inputs are watched from the document (bound once:
        // the SPA re-runs this script on every visit) and always call the latest render function.
        if (!window.__rhCsChoiceBound) {
            window.__rhCsChoiceBound = true;
            const csOnChoice = function (e) {
                const t = e.target;
                if (!t || !t.closest || !t.closest('#checkoutSettlementForm')) return;
                if (e.type === 'change' && t.name === 'cs_leave' && t.value === 'other') {
                    const other = document.getElementById('cs_leave_other');
                    if (other) {
                        other.disabled = false;
                        other.focus();
                    }
                }
                if (t.name === 'cs_leave' || t.name === 'cs_basis' || t.name === 'cs_refund_as' || t.id === 'cs_leave_other' || t.id === 'cs_amount_received') {
                    const errEl = document.getElementById('cs_error');
                    if (errEl && e.type === 'change') errEl.style.display = 'none';
                    if (typeof window.renderCheckoutSettlement === 'function') window.renderCheckoutSettlement();
                }
            };
            document.addEventListener('change', csOnChoice);
            document.addEventListener('input', csOnChoice);
        }

        function markNoShow(id, reference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus) {
            openCheckInModal(
                id,
                reference || '',
                guestName || '',
                checkInDate || '',
                paymentStatus || '',
                roomAssigned || false,
                bookingStatus || 'confirmed',
                'noshow'
            );
        }
    </script>

    <!-- Quick pay (owner 2026-11-12: full OR part payment, never one click). Posts to payment-add.php, the same path as
         Payments > Add Payment (balance limit, VAT split, receipt email), then comes back to this list. -->
    <div id="rhQuickPayModal" class="modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="rhQuickPayTitle">
        <div class="modal-content rh-dialog-narrow">
            <div class="modal-header">
                <h3 id="rhQuickPayTitle"><i class="fas fa-money-bill-wave"></i> Record payment</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="rhQuickPayClose()">&times;</button>
            </div>
            <form method="POST" action="payment-add.php" id="rhQuickPayForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="booking_type" value="room">
                    <input type="hidden" name="booking_id" id="rhQpId" value="">
                    <input type="hidden" name="payment_status" value="completed">
                    <input type="hidden" name="payment_date" id="rhQpDate" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES); ?>">
                    <input type="hidden" name="return_to" value="bookings">
                    <input type="hidden" name="client_uuid" id="rhQpUuid" value="">
                    <input type="hidden" name="payment_amount" id="rhQpAmount" value="">
                    <p id="rhQpSummary" class="rh-dialog-lead"></p>
                    <div id="rhQpOwedWrap">
                        <fieldset class="rh-choices">
                            <legend>How much did the guest pay? *</legend>
                            <label class="rh-choice">
                                <input type="radio" name="rh_qp_kind" value="full" id="rhQpFull">
                                <span id="rhQpFullLabel">The full balance</span>
                            </label>
                            <label class="rh-choice">
                                <input type="radio" name="rh_qp_kind" value="part" id="rhQpPart">
                                <span>Part of it</span>
                            </label>
                            <div id="rhQpPartWrap" class="form-group rh-choice-detail" style="display:none;">
                                <label for="rhQpPartAmount">Amount received (<?php echo htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES); ?>) *</label>
                                <input type="number" step="0.01" min="0.01" id="rhQpPartAmount" class="form-control" inputmode="decimal">
                                <small id="rhQpPartHint"></small>
                            </div>
                        </fieldset>
                        <div class="form-group">
                            <label for="rhQpMethod">How did they pay? *</label>
                            <select name="payment_method" id="rhQpMethod" class="form-control" required>
                                <option value="">Choose...</option>
                                <option value="cash">Cash</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="cheque">Cheque</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="rhQpRef">Transaction reference</label>
                            <input type="text" name="transaction_reference" id="rhQpRef" class="form-control" maxlength="100" placeholder="Mobile money ID, bank ref, cheque no...">
                        </div>
                        <p id="rhQpConfirmText" class="rh-live-note" aria-live="polite"></p>
                    </div>
                    <p id="rhQpNothing" class="rh-review-card" style="display:none;"><strong>Nothing is owed on this booking.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="rhQuickPayClose()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="rhQpSubmit" disabled><i class="fas fa-check"></i> Confirm payment</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var cur = <?php echo json_encode((string)getSetting('currency_symbol', 'MWK'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var due = 0;
        function el(id) { return document.getElementById(id); }
        function fmt(n) { return cur + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
        function newUuid() {
            if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
            // Plain HTTP has no randomUUID: 16 random bytes from getRandomValues (else time + two random parts), so a
            // double submit still carries one id the server dedupes on (idempotency: payments.client_uuid).
            if (window.crypto && crypto.getRandomValues) {
                var b = new Uint8Array(16);
                crypto.getRandomValues(b);
                return 'qp-' + Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
            }
            return 'qp-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
        }
        // Today in the hotel's time zone (the app date), not the date the page was loaded: a dialog left open past midnight
        // must not record yesterday. Falls back to the server date printed with the page.
        var qpTz = <?php echo json_encode(date_default_timezone_get(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function hotelToday() {
            try {
                var p = new Intl.DateTimeFormat('en-CA', { timeZone: qpTz, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
                var v = {};
                p.forEach(function (x) { v[x.type] = x.value; });
                if (v.year && v.month && v.day) { return v.year + '-' + v.month + '-' + v.day; }
            } catch (e) { /* old browser: keep the page date */ }
            return el('rhQpDate').defaultValue;
        }
        function setOpen(open) {
            var m = el('rhQuickPayModal');
            if (typeof window.setBookingPageModalOpen === 'function') { window.setBookingPageModalOpen(m, open); return; }
            m.style.display = open ? 'flex' : 'none';
            m.setAttribute('aria-hidden', open ? 'false' : 'true');
        }
        // Works out the amount from the choice and only enables Confirm when amount and method are valid.
        function sync() {
            var kind = el('rhQpFull').checked ? 'full' : (el('rhQpPart').checked ? 'part' : '');
            el('rhQpPartWrap').style.display = kind === 'part' ? '' : 'none';
            el('rhQpPartAmount').required = kind === 'part';
            var amt = 0, hint = '', ok = false;
            if (kind === 'full') {
                amt = due; ok = true;
            } else if (kind === 'part') {
                amt = Math.round(parseFloat(el('rhQpPartAmount').value || '0') * 100) / 100;
                if (!(amt > 0)) { hint = 'Enter the amount the guest handed over.'; }
                else if (amt > due + 0.009) { hint = 'That is more than the balance (' + fmt(due) + '). Choose the full balance, or record an overpayment from Payments.'; }
                else { ok = true; hint = 'Still owed after this: ' + fmt(Math.max(0, due - amt)) + '.'; }
            }
            el('rhQpPartHint').textContent = hint;
            el('rhQpPartHint').style.color = (kind === 'part' && !ok) ? '#b42318' : '';
            var method = el('rhQpMethod').value;
            var mLabel = method ? el('rhQpMethod').options[el('rhQpMethod').selectedIndex].text : '';
            el('rhQpAmount').value = ok ? amt.toFixed(2) : '';
            el('rhQpConfirmText').textContent = (ok && method)
                ? 'You are recording ' + fmt(amt) + ' paid by ' + mLabel + (kind === 'full' ? ' (the full balance).' : ' (part payment).')
                : '';
            el('rhQpSubmit').disabled = !(ok && method);
            el('rhQpSubmit').innerHTML = '<i class="fas fa-check"></i> ' + ((ok && method) ? 'Confirm ' + esc(fmt(amt)) : 'Confirm payment');
        }
        window.rhQuickPayOpen = function (id, ref, guest, balance) {
            due = Math.max(0, Number(balance || 0));
            el('rhQpId').value = id;
            el('rhQpDate').value = hotelToday();
            el('rhQpUuid').value = newUuid();
            el('rhQpSummary').innerHTML = '<strong>' + esc(ref) + '</strong> &middot; ' + esc(guest) + '<br>Balance due: <strong>' + esc(fmt(due)) + '</strong>';
            el('rhQpFullLabel').textContent = 'The full balance: ' + fmt(due);
            el('rhQpFull').checked = false;
            el('rhQpPart').checked = false;
            el('rhQpPartAmount').value = '';
            el('rhQpMethod').value = '';
            el('rhQpRef').value = '';
            var owed = due > 0.009;
            el('rhQpOwedWrap').style.display = owed ? '' : 'none';
            el('rhQpNothing').style.display = owed ? 'none' : '';
            el('rhQpMethod').required = owed;
            el('rhQpSubmit').style.display = owed ? '' : 'none';
            sync();
            setOpen(true);
        };
        window.rhQuickPayClose = function () { setOpen(false); };
        ['rhQpFull', 'rhQpPart', 'rhQpMethod'].forEach(function (id) { el(id).addEventListener('change', sync); });
        el('rhQpPartAmount').addEventListener('input', sync);
        el('rhQuickPayForm').addEventListener('submit', function (ev) {
            sync();
            el('rhQpDate').value = hotelToday(); // the day it is recorded, in the hotel's time zone
            if (el('rhQpSubmit').disabled || !el('rhQpAmount').value) { ev.preventDefault(); return; }
            el('rhQpSubmit').disabled = true; // one click, one payment
            el('rhQpSubmit').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
        });
        el('rhQuickPayModal').addEventListener('click', function (ev) { if (ev.target === this) { window.rhQuickPayClose(); } });
    }());
    </script>

    <!-- Email Resend Modal -->
    <div id="resendEmailModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Resend Email</h3>
                <button type="button" class="close-modal" onclick="closeResendEmailModal()" aria-label="Close">&times;</button>
            </div>
            <form id="resendEmailForm" method="POST" action="">
                <input type="hidden" name="action" value="resend_email">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="booking_id" id="modal_booking_id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="modal_booking_reference" class="form-control" readonly style="background: #f5f5f5;">
                    </div>

                    <div class="form-group">
                        <label for="email_type"><i class="fas fa-envelope"></i> Email Type:</label>
                        <select name="email_type" id="email_type" class="form-control" required>
                            <option value="">-- Select Email Type --</option>
                            <option value="booking_received">Booking Received (Initial confirmation)</option>
                            <option value="booking_confirmed">Booking Confirmed</option>
                            <option value="tentative_confirmed" id="opt_tentative_confirmed">Tentative Booking Confirmed</option>
                            <option value="tentative_converted" id="opt_tentative_converted">Tentative Converted to Confirmed</option>
                            <option value="booking_cancelled">Booking Cancelled</option>
                            <option value="invoice">Invoice</option>
                            <option value="booking_reminder" id="opt_booking_reminder" style="display:none;">&#9888; Check-in Reminder (Late / Overdue)</option>
                        </select>
                        <small style="color: #666;">Select the type of email to resend based on current booking status</small>
                    </div>

                    <div class="form-group">
                        <label for="cc_emails"><i class="fas fa-users"></i> CC Emails (Optional):</label>
                        <input type="text" name="cc_emails" id="cc_emails" class="form-control" placeholder="email1@example.com, email2@example.com">
                        <small style="color: #666;">Comma-separated email addresses to CC</small>
                    </div>

                    <!-- Inline success state (replaces toast on mobile) -->
                    <div id="resendEmailSuccess" style="display:none;text-align:center;padding:18px 12px;">
                        <div style="font-size:2.2rem;color:#28a745;margin-bottom:8px;"><i class="fas fa-circle-check"></i></div>
                        <div style="font-weight:600;color:#1a4731;font-size:1rem;" id="resendEmailSuccessMsg">Email sent!</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResendEmailModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="resendEmailSubmitBtn"><i class="fas fa-paper-plane"></i> Send Email</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Room Assignment Modal -->
    <div id="quickRoomAssignModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="fas fa-door-open"></i> Assign Room</h3>
                <button type="button" class="close-modal" onclick="closeQuickRoomAssignModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                    <input type="text" id="quick_assign_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Dates:</label>
                    <input type="text" id="quick_assign_dates" class="form-control" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-door-open"></i> Select Individual Room:</label>
                    <div id="quick_assign_room_list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 10px;">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-spinner fa-spin"></i> Loading available rooms...
                        </div>
                    </div>
                </div>
                <input type="hidden" id="quick_assign_booking_id">
                <input type="hidden" id="quick_assign_room_id">
                <div id="quick_assign_override_panel" class="form-group" style="display:none; border: 1px solid #f0c36d; background: #fff8e1; border-radius: 8px; padding: 12px;">
                    <label style="display:flex; gap: 8px; align-items:flex-start; font-weight:600; color:#7a4f01;">
                        <input type="checkbox" id="quick_assign_children_override" style="margin-top: 3px;">
                        Override child room policy for this assignment
                    </label>
                    <textarea id="quick_assign_override_note" class="form-control" rows="3" placeholder="Required note explaining why this room is being assigned for a booking with children" style="margin-top: 10px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickRoomAssignModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitQuickRoomAssign()"><i class="fas fa-check"></i> Assign Room</button>
            </div>
        </div>
    </div>

    <!-- Make Tentative Modal -->
    <div id="makeTentativeModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-clock"></i> Make Tentative</h3>
                <button type="button" class="close-modal" onclick="closeMakeTentativeModal()" aria-label="Close">&times;</button>
            </div>
            <form id="makeTentativeForm" method="POST" action="">
                <input type="hidden" name="action" id="make_tentative_action" value="">
                <input type="hidden" name="id" id="make_tentative_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="make_tentative_ref" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label for="tentative_note"><i class="fas fa-sticky-note"></i> Optional Note:</label>
                        <textarea name="note" id="tentative_note" class="form-control" rows="3" placeholder="Add a note about why this booking is being made tentative..."></textarea>
                        <small style="color: #666;">This note will be recorded in the booking log.</small>
                    </div>
                    <div class="form-group" style="background: #fff8e1; padding: 12px; border-radius: 8px;">
                        <p style="margin: 0; color: #7E684B; font-size: 13px;">
                            <i class="fas fa-info-circle"></i>
                            This will convert the booking to a tentative reservation, holding the room for
                            <strong id="tentative_duration_display">48</strong> hours. A confirmation email will be sent to the guest.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMakeTentativeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Make Tentative</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Check In Modal -->
    <div id="checkInModal" class="modal-overlay bookings-checkin-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-checkin-modal__dialog">
            <div class="modal-header bookings-checkin-modal__header">
                <div class="bookings-checkin-modal__title-wrap">
                    <h3 id="checkin_modal_title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h3>
                    <p id="checkin_modal_subtitle" class="bookings-checkin-modal__subtitle">Verify prerequisites and complete guest arrival.</p>
                </div>
                <button class="close-modal" type="button" onclick="closeCheckInModal()" aria-label="Close check-in modal">&times;</button>
            </div>
            <form id="checkInForm" method="POST" action="">
                <input type="hidden" name="action" id="checkin_action" value="update_status">
                <input type="hidden" name="status" id="checkin_status" value="checked-in">
                <input type="hidden" name="id" id="checkin_booking_id" value="">
                <input type="hidden" id="checkin_modal_mode" value="checkin">
                <div class="modal-body bookings-checkin-modal__body">
                    <div class="bookings-checkin-modal__mode-row">
                        <span id="checkin_mode_badge" class="bookings-checkin-modal__mode-badge">Standard Check-In</span>
                        <span id="checkin_mode_hint" class="bookings-checkin-modal__mode-hint">Proceed when all prerequisites are satisfied.</span>
                    </div>

                    <div id="checkin_context_note" class="bookings-checkin-modal__context-note" style="display: none;"></div>

                    <?php /* Late arrival (owner 2026-11-12 "Dont assume, have options"): nothing preselected; the server re-checks the choice. */ ?>
                    <fieldset id="checkin_late_choices" class="bookings-checkin-modal__late rh-choices" hidden>
                        <legend>What happened? <small>Pick one. Nothing is assumed.</small></legend>
                        <label class="bookings-checkin-modal__late-option rh-choice">
                            <input type="radio" name="late_arrival" value="booked_date" disabled>
                            <span><strong id="late_opt_booked_label">Guest arrived on the booked date, recorded late</strong><small>Booked dates and total stay as booked.</small></span>
                        </label>
                        <label class="bookings-checkin-modal__late-option rh-choice">
                            <input type="radio" name="late_arrival" value="today" disabled>
                            <span><strong id="late_opt_today_label">Guest is arriving today</strong><small>Booked dates and total stay as booked. Today is recorded as the arrival date.</small></span>
                        </label>
                        <label class="bookings-checkin-modal__late-option rh-choice" id="late_opt_ended_wrap" hidden>
                            <input type="radio" name="late_arrival" value="ended" disabled>
                            <span><strong id="late_opt_ended_label">Guest already stayed and has left: record the completed stay</strong><small>Checks in and out together at the booked dates. No extra nights charged, no check-in email. The balance rules still apply.</small></span>
                        </label>
                    </fieldset>

                    <div class="bookings-checkin-modal__identity-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Booking Reference</label>
                            <input type="text" id="checkin_booking_ref" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Scheduled Check-in</label>
                            <input type="text" id="checkin_date" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                        <div class="form-group bookings-checkin-modal__identity-grid-full">
                            <label><i class="fas fa-user"></i> Guest Name</label>
                            <input type="text" id="checkin_guest_name" class="form-control bookings-checkin-modal__readonly" readonly>
                        </div>
                    </div>

                    <div class="prerequisites bookings-checkin-modal__prerequisites" id="checkin_prerequisites">
                        <h4><i class="fas fa-list-check"></i> Prerequisites</h4>
                        <ul>
                            <li id="prereq_payment"><i class="fas fa-times-circle"></i> Payment must be marked as PAID</li>
                            <li id="prereq_room"><i class="fas fa-times-circle"></i> A room must be assigned</li>
                            <li id="prereq_status"><i class="fas fa-times-circle"></i> Booking must be CONFIRMED</li>
                        </ul>
                    </div>

                    <div class="form-group" id="checkin_note_group">
                        <label for="checkin_note"><i class="fas fa-sticky-note"></i> Optional Note</label>
                        <textarea name="checkin_note" id="checkin_note" class="form-control" rows="2" placeholder="Add any check-in or no-show notes..."></textarea>
                    </div>

                    <?php /* Owner 2026-11-12 "A tick box": hidden 0 before the box, so unticked posts email_guest=0. */ ?>
                    <div class="form-group" id="checkin_email_group">
                        <input type="hidden" name="email_guest" value="0" id="checkin_email_guest_off">
                        <label class="admin-confirm-check">
                            <input type="checkbox" name="email_guest" value="1" id="checkin_email_guest" checked>
                            <span>Email the guest a check-in confirmation<small>Untick to check in quietly: no check-in email goes to the guest.</small></span>
                        </label>
                    </div>

                    <p id="checkin_error_message" class="bookings-checkin-modal__error" style="display: none;"></p>
                </div>
                <div class="modal-footer bookings-checkin-modal__footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCheckInModal()">Cancel</button>
                    <button type="submit" id="checkin_submit_btn" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Check In</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Booking Modal -->
    <div id="cancelBookingModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Cancel Booking</h3>
                <button type="button" class="close-modal" onclick="closeCancelBookingModal()" aria-label="Close">&times;</button>
            </div>
            <form id="cancelBookingForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="cancelled">
                <input type="hidden" name="id" id="cancel_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="cancel_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Guest Name:</label>
                        <input type="text" id="cancel_guest_name" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label for="cancellation_reason"><i class="fas fa-comment"></i> Cancellation Reason (Optional):</label>
                        <textarea name="cancellation_reason" id="cancellation_reason" class="form-control" rows="3" placeholder="Reason for cancellation..."></textarea>
                        <small style="color: #666;">This reason will be included in the cancellation email and logs.</small>
                    </div>
                    <div class="form-group" id="cancel_group_box" style="display: none; background: #fff8e6; padding: 12px; border-radius: 8px;">
                        <label style="margin-bottom: 6px;"><i class="fas fa-layer-group"></i> This room was booked together with other rooms. Cancel:</label>
                        <label style="display: block; font-weight: normal; margin: 4px 0;"><input type="radio" name="scope" value="group" checked disabled> <span id="cancel_group_label">Whole group</span></label>
                        <label style="display: block; font-weight: normal; margin: 4px 0;"><input type="radio" name="scope" value="single" disabled> Only this room</label>
                        <small id="cancel_group_refs" style="color: #666;"></small>
                    </div>
                    <div class="form-group" id="cancel_email_box">
                        <input type="hidden" name="email_guest" value="0">
                        <label style="display: flex; gap: 8px; align-items: flex-start; font-weight: 600; margin: 0;">
                            <input type="checkbox" name="email_guest" value="1" id="cancel_email_guest" checked style="margin-top: 3px;">
                            <span>Email the guest that this booking is cancelled</span>
                        </label>
                        <small style="color: #666; display: block; margin-top: 4px;">Untick to cancel quietly: no email or WhatsApp goes to the guest.</small>
                        <small id="cancel_email_unconfirmed" style="color: #666; display: none; margin-top: 4px;">This booking was not confirmed yet, so no email goes to the guest.</small>
                    </div>
                    <div class="form-group" style="background: #f8f9fa; padding: 12px; border-radius: 8px;">
                        <p style="margin: 0; color: #666; font-size: 13px;">
                            <i class="fas fa-info-circle"></i>
                            Cancelling this booking will restore room availability. The guest gets the cancellation email only when the box above is ticked.
                        </p>
                        <p style="margin: 6px 0 0; color: #444; font-size: 13px;">
                            <i class="fas fa-coins"></i>
                            <strong>Cancellation handling:</strong> <?php echo htmlspecialchars(getCancellationRefundModeLabel(), ENT_QUOTES); ?>
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCancelBookingModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Cancel Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Group Booking Modal (only opened when the booking is one of several rooms booked together) -->
    <div id="deleteGroupModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-trash-can"></i> Delete Booking</h3>
                <button type="button" class="close-modal" onclick="closeDeleteGroupModal()" aria-label="Close">&times;</button>
            </div>
            <form id="deleteGroupForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete_booking">
                <input type="hidden" name="booking_id" id="delete_group_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="delete_group_ref" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group" id="delete_group_box" style="background: #fff8e6; padding: 12px; border-radius: 8px;">
                        <label style="margin-bottom: 6px;"><i class="fas fa-layer-group"></i> This room was booked together with other rooms. Delete:</label>
                        <label style="display: block; font-weight: normal; margin: 4px 0;"><input type="radio" name="scope" value="group" checked> <span id="delete_group_label">Whole group</span></label>
                        <label style="display: block; font-weight: normal; margin: 4px 0;"><input type="radio" name="scope" value="single"> Only this room</label>
                        <small id="delete_group_refs" style="color: #666;"></small>
                    </div>
                    <div class="form-group">
                        <label for="delete_group_reason"><i class="fas fa-comment"></i> Why is this being deleted? (required)</label>
                        <textarea name="reason" id="delete_group_reason" class="form-control" rows="3" placeholder="e.g. Duplicate booking, test booking, created in error"></textarea>
                        <small style="color: #666;">Deleted bookings are kept under the Deleted filter and can be restored. Nothing is sent to the guest. If any room cannot be deleted, none of them is.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteGroupModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-can"></i> Delete Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Missed Check-ins Modal -->
    <div id="missedCheckinsModal" class="modal-overlay bookings-alert-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header bookings-alert-modal__header--danger">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow">Dashboard Insight</p>
                    <h3><i class="fas fa-exclamation-triangle"></i> Missed Check-ins</h3>
                    <p class="bookings-alert-modal__subtitle">Confirmed bookings that passed check-in date without arrival.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeMissedCheckinsModal()" aria-label="Close missed check-ins modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-alert-modal__notice bookings-alert-modal__notice--danger">
                    <i class="fas fa-triangle-exclamation"></i>
                    <p>Handle these overdue arrivals by completing a late check-in, marking no-show, or cancelling the booking with a reason.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap">
                    <table class="booking-table bookings-alert-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Check-in Date</th>
                                <th>Overdue</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($missed_checkins)): foreach ($missed_checkins as $bk): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars($bk['booking_reference']); ?></strong></td>
                                        <td data-label="Guest"><?php echo htmlspecialchars($bk['guest_name']); ?><br><small><?php echo htmlspecialchars($bk['guest_phone']); ?></small></td>
                                        <td data-label="Room"><?php echo htmlspecialchars($bk['room_name']); ?><br><small><?php echo $bk['individual_room_name'] ? htmlspecialchars($bk['individual_room_name'] . ' (' . $bk['individual_room_number'] . ')') : 'Unassigned'; ?></small></td>
                                        <td data-label="Check-in Date" class="bookings-alert-modal__overdue-cell"><?php echo date('M j, Y', strtotime($bk['check_in_date'])); ?></td>
                                        <td data-label="Overdue"><span class="badge bookings-alert-modal__badge bookings-alert-modal__badge--danger"><?php echo $bk['days_overdue']; ?> day(s)</span></td>
                                        <td data-label="Actions" class="bookings-alert-modal__actions">
                                            <button class="btn btn-sm btn-primary bookings-alert-modal__action-btn" type="button" onclick="openMissedCheckinWorkflow(<?php echo $bk['id']; ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['booking_reference'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['guest_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, '<?php echo $bk['check_in_date']; ?>', '<?php echo htmlspecialchars((string)($bk['actual_payment_status'] ?? $bk['payment_status']), ENT_QUOTES); ?>', '<?php echo !empty($bk['individual_room_id']) ? 'true' : 'false'; ?>', '<?php echo $bk['status']; ?>', 'checkin', '<?php echo htmlspecialchars(rh_checkin_payment_gate($bk), ENT_QUOTES); ?>', <?php echo htmlspecialchars(json_encode(bookings_late_checkin_info($bk), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>)">Late Check-in</button>
                                            <button class="btn btn-sm btn-danger bookings-alert-modal__action-btn" type="button" onclick="openMissedCheckinWorkflow(<?php echo $bk['id']; ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['booking_reference'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['guest_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, '<?php echo $bk['check_in_date']; ?>', '<?php echo htmlspecialchars((string)($bk['actual_payment_status'] ?? $bk['payment_status']), ENT_QUOTES); ?>', '<?php echo !empty($bk['individual_room_id']) ? 'true' : 'false'; ?>', '<?php echo $bk['status']; ?>', 'noshow')">Mark No-Show</button>
                                            <button class="btn btn-sm btn-secondary bookings-alert-modal__action-btn" type="button" onclick="openMissedCancelWorkflow(<?php echo $bk['id']; ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['booking_reference'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, <?php echo htmlspecialchars((string)json_encode((string)$bk['guest_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>)">Cancel Booking</button>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr class="bookings-alert-modal__empty-row">
                                    <td colspan="6">No missed check-ins.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMissedCheckinsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Overdue Checkouts Modal -->
    <div id="overdueCheckoutsModal" class="modal-overlay bookings-alert-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header bookings-alert-modal__header--warning">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow">Dashboard Insight</p>
                    <h3><i class="fas fa-triangle-exclamation"></i> Overdue Checkouts</h3>
                    <p class="bookings-alert-modal__subtitle">Checked-in guests whose scheduled checkout date has already passed.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeOverdueCheckoutsModal()" aria-label="Close overdue checkouts modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-alert-modal__notice bookings-alert-modal__notice--warning">
                    <i class="fas fa-info-circle"></i>
                    <p>These guests have passed their scheduled checkout date. Process their checkout to free the room, or extend their stay to bill extra nights accurately.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap">
                    <table class="booking-table bookings-alert-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Checkout Due</th>
                                <th>Overdue</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($overdue_checkouts)): foreach ($overdue_checkouts as $bk): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo htmlspecialchars($bk['booking_reference']); ?></strong></td>
                                        <td data-label="Guest"><?php echo htmlspecialchars($bk['guest_name']); ?></td>
                                        <td data-label="Room"><?php echo htmlspecialchars($bk['room_name']); ?><br><small><?php echo $bk['individual_room_name'] ? htmlspecialchars($bk['individual_room_name'] . ' (' . $bk['individual_room_number'] . ')') : 'Unassigned'; ?></small></td>
                                        <td data-label="Checkout Due" class="bookings-alert-modal__warning-cell"><?php echo date('M j, Y', strtotime($bk['check_out_date'])); ?></td>
                                        <td data-label="Overdue"><span class="badge bookings-alert-modal__badge bookings-alert-modal__badge--warning"><?php echo $bk['days_overdue'] == 0 ? 'Today' : $bk['days_overdue'] . ' day(s)'; ?></span></td>
                                        <td data-label="Actions" class="bookings-alert-modal__actions">
                                            <button class="btn btn-sm btn-danger bookings-alert-modal__action-btn" onclick="checkoutBooking(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>')"><i class="fas fa-sign-out-alt"></i> Checkout Now</button>
                                            <button class="btn btn-sm btn-success bookings-alert-modal__action-btn" onclick="closeOverdueCheckoutsModal(); openExtendStayModal(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo $bk['check_out_date']; ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-plus"></i> Add nights</button>
                                            <?php if ($_is_admin_user): ?>
                                                <button class="btn btn-sm bookings-alert-modal__action-btn bookings-alert-modal__action-btn--admin-date" onclick="closeOverdueCheckoutsModal(); openAdminChangeDateModal(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['booking_reference'], ENT_QUOTES); ?>', '<?php echo $bk['check_out_date']; ?>', '<?php echo htmlspecialchars($bk['guest_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-pen"></i> Change Date</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr class="bookings-alert-modal__empty-row">
                                    <td colspan="6"><i class="fas fa-circle-check"></i> No overdue checkouts - all guests are within their scheduled dates.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeOverdueCheckoutsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Booking Stats Insight Modal -->
    <div id="bookingsStatsInsightModal" class="modal-overlay bookings-alert-modal bookings-stats-insight-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-content bookings-alert-modal__dialog bookings-alert-modal__dialog--wide">
            <div class="modal-header bookings-alert-modal__header">
                <div class="bookings-alert-modal__title-wrap">
                    <p class="bookings-alert-modal__eyebrow" id="bookingsStatsInsightEyebrow">Dashboard Insight</p>
                    <h3 id="bookingsStatsInsightTitle"><i class="fas fa-chart-pie"></i> Booking Insight</h3>
                    <p class="bookings-alert-modal__subtitle" id="bookingsStatsInsightSubtitle">Latest records for this card.</p>
                </div>
                <button class="close-modal bookings-alert-modal__close" type="button" onclick="closeBookingsStatsInsightModal()" aria-label="Close bookings stats insight modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body bookings-alert-modal__body">
                <div class="bookings-stats-insight-modal__summary" id="bookingsStatsInsightSummary">
                    <div class="bookings-stats-insight-modal__kpi">0</div>
                    <p>Select a card above to view its operational insight.</p>
                </div>
                <div class="bookings-alert-modal__table-wrap" id="bookingsStatsInsightTableWrap">
                    <table class="booking-table bookings-alert-modal__table bookings-stats-insight-modal__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Stay</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bookingsStatsInsightRows">
                            <tr class="bookings-alert-modal__empty-row">
                                <td colspan="6">Insight details load when you open a card.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bookings-stats-insight-modal__footer">
                <a id="bookingsStatsInsightLink" class="btn btn-primary" href="bookings.php">Open full list</a>
                <button type="button" class="btn btn-secondary" onclick="closeBookingsStatsInsightModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Manual Consolidation Modal -->
    <div id="consolidationModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 680px;">
            <div class="modal-header">
                <h3><i class="fas fa-scale-balanced"></i> Manual Payment Consolidation</h3>
                <button type="button" class="close-modal" onclick="closeConsolidationModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="con_body" style="padding:0;">
                <div style="padding:20px; text-align:center; color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading financial summary...</div>
            </div>
        </div>
    </div>

    <!-- Add / take off nights (owner 2026-11-13): stepper -> review card -> confirm. Opened by openExtendStayModal(). -->
    <div id="extendStayModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content rh-dialog-narrow">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> <span id="sn_title">Add or take off nights</span></h3>
                <button type="button" class="close-modal" onclick="closeExtendStayModal()" aria-label="Close">&times;</button>
            </div>
            <form id="extendStayForm" method="POST" action="bookings.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" id="extend_booking_id" value="">
                <input type="hidden" id="extend_booking_ref" value="">
                <input type="hidden" id="extend_guest_name" value="">
                <input type="hidden" id="extend_current_checkout" value="">

                <div class="modal-body">
                    <p id="sn_who" class="rh-dialog-lead"></p>

                    <!-- Step 1: how many nights -->
                    <div id="sn_step1">
                        <div class="form-group">
                            <label id="sn_stepper_label">Nights to add or take off</label>
                            <small>Nights are added or taken off at the end of the stay, at the rate this booking was made at. No amount is typed here.</small>
                            <div role="group" aria-labelledby="sn_stepper_label" class="rh-stepper">
                                <button type="button" id="sn_minus" class="btn btn-secondary" aria-label="One night fewer">&minus;</button>
                                <output id="sn_delta_text" aria-live="polite">+1 night</output>
                                <button type="button" id="sn_plus" class="btn btn-secondary" aria-label="One night more">+</button>
                            </div>
                        </div>
                        <div id="sn_live" class="rh-review-card rh-review-card--live" aria-live="polite"></div>
                    </div>

                    <!-- Step 2: review, money back, reason -->
                    <div id="sn_step2" hidden>
                        <div id="sn_review" class="rh-review-card"></div>
                        <div id="sn_moneyback" class="rh-review-card rh-review-card--warn" hidden>
                            <strong id="sn_moneyback_title"></strong>
                            <p class="rh-choice-q">How does it go back?</p>
                            <label class="rh-choice"><input type="radio" name="sn_mode" value="refund" id="sn_mode_refund"> <span>Refund to the original payment method(s), in full</span></label>
                            <small id="sn_refund_noperm" class="rh-choice-note" style="display:none;">You do not have the Refund payments permission: choose a credit note, or ask someone who has it.</small>
                            <label class="rh-choice"><input type="radio" name="sn_mode" value="credit_note" id="sn_mode_credit"> <span>Credit note (kept for the guest's next stay or bill)</span></label>
                            <div class="form-group">
                                <label for="sn_typed">Type the amount going back to confirm</label>
                                <input type="text" id="sn_typed" class="form-control" inputmode="decimal" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-group rh-mt">
                            <label for="sn_reason">Reason (required)</label>
                            <input type="text" id="sn_reason" class="form-control" maxlength="300" placeholder="e.g. guest staying one more night (company LPO)">
                            <small>Saved in the booking History with every figure above.</small>
                        </div>
                    </div>
                    <div id="sn_error" class="alert alert-error rh-mt" role="alert" style="display:none;"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="sn_cancel" onclick="closeExtendStayModal()">Cancel</button>
                    <button type="button" class="btn btn-secondary" id="sn_back" style="display:none;">Back</button>
                    <button type="button" class="btn btn-primary" id="sn_review_btn" disabled><i class="fas fa-magnifying-glass"></i> Review change</button>
                    <button type="button" class="btn btn-primary" id="sn_confirm_btn" style="display:none;"><i class="fas fa-check"></i> Confirm change</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Change Checkout Date Modal -->
    <div id="adminChangeDateModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-pen"></i> Admin: Change Checkout Date</h3>
                <button type="button" class="close-modal" onclick="closeAdminChangeDateModal()" aria-label="Close">&times;</button>
            </div>
            <form id="adminChangeDateForm" method="POST" action="bookings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="admin_update_checkout_date">
                <input type="hidden" name="booking_id" id="acd_booking_id" value="">
                <div class="modal-body">
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; color: #78350f;">
                        <i class="fas fa-triangle-exclamation" style="color: var(--au-warning, #f59e0b);"></i>
                        <strong>Admin override.</strong> This bypasses normal checkout restrictions and recalculates the booking total. Use only to correct the actual checkout date on record.
                    </div>
                    <div class="form-group">
                        <label>Booking:</label>
                        <input type="text" id="acd_booking_ref" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Guest:</label>
                        <input type="text" id="acd_guest_name" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label>Current checkout date:</label>
                        <input type="text" id="acd_current_checkout" class="form-control" readonly style="background: var(--au-bg, #f4f6fa);">
                    </div>
                    <div class="form-group">
                        <label for="acd_new_checkout" style="font-weight:600; color: var(--au-purple, #8b5cf6);">New checkout date: <span style="color: var(--au-danger, #ef4444);">*</span></label>
                        <input type="date" id="acd_new_checkout" name="new_checkout" class="form-control" required style="border-color: var(--au-purple, #8b5cf6);">
                        <small style="color: var(--au-muted, #6b7280); display:block; margin-top:4px;">Can be in the past (e.g. to correct an overdue record). Total &amp; amount due will be recalculated.</small>
                        <div id="acd_rate_preview" class="bk-rate-preview" hidden aria-live="polite" style="background:#f7f3ee;border-radius:6px;padding:10px 12px;margin-top:10px;font-size:13px;line-height:1.6;"></div>
                    </div>
                    <div class="form-group">
                        <label for="acd_reason">Reason (required):</label>
                        <input type="text" id="acd_reason" name="reason" class="form-control" placeholder="e.g. Guest departed early, missed checkout in system" maxlength="200" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAdminChangeDateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Update Checkout Date</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Checkout Settlement Modal -->
    <div id="checkoutSettlementModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content rh-dialog-narrow">
            <div class="modal-header">
                <h3 id="cs_title"><i class="fas fa-receipt"></i> Checkout Settlement</h3>
                <button type="button" class="close-modal" onclick="closeCheckoutSettlementModal()" aria-label="Close">&times;</button>
            </div>
            <form id="checkoutSettlementForm" method="POST" action="bookings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="checkout_settle">
                <input type="hidden" id="cs_booking_id" name="id" value="">
                <input type="hidden" id="cs_booking_ref" value="">
                <input type="hidden" id="cs_settlement_type" value="">
                <input type="hidden" id="cs_settlement_action" name="settlement_action" value="skip">

                <div class="modal-body">
                    <!-- Dynamic summary injected by JS -->
                    <div id="cs_body" class="rh-dialog-lead"></div>

                    <?php /* Owner 2026-11-12 "Dont assume, have options": when did the guest leave? Nothing preselected; the
                        server re-checks the date and decides charge / refund / nothing from it. */ ?>
                    <fieldset id="cs_leave_box" class="cs-choices rh-choices">
                        <legend>When did the guest leave? <small>Pick one. Nothing is assumed.</small></legend>
                        <label class="cs-choice rh-choice" id="cs_opt_booked_wrap">
                            <input type="radio" name="cs_leave" value="booked">
                            <span><strong id="cs_opt_booked_label">Guest left on the booked date</strong><small id="cs_opt_booked_help"></small></span>
                        </label>
                        <label class="cs-choice rh-choice">
                            <input type="radio" name="cs_leave" value="today">
                            <span><strong id="cs_opt_today_label">Guest is leaving today</strong><small id="cs_opt_today_help"></small></span>
                        </label>
                        <label class="cs-choice rh-choice">
                            <input type="radio" name="cs_leave" value="other">
                            <span><strong>Guest left on another date</strong><small id="cs_opt_other_help">Pick the day the guest left.</small>
                                <input type="date" id="cs_leave_other" class="form-control" aria-label="Date the guest left" disabled></span>
                        </label>
                        <label class="cs-choice rh-choice">
                            <input type="radio" name="cs_leave" value="skip">
                            <span><strong>Skip settlement: check out without recording a charge or refund</strong><small>Use only if the adjustment has been handled outside the system. The booked dates and total stay as they are.</small></span>
                        </label>
                    </fieldset>

                    <fieldset id="cs_basis_box" class="cs-choices rh-choices" hidden>
                        <legend>How should the nights be counted? <small>Arrival was recorded as <span id="cs_arrival_fmt"></span>, after the booked check-in.</small></legend>
                        <label class="cs-choice rh-choice">
                            <input type="radio" name="cs_basis" value="booked">
                            <span><strong>Keep booked nights + charge nights after the booked checkout</strong><small id="cs_basis_booked_help"></small></span>
                        </label>
                        <label class="cs-choice rh-choice">
                            <input type="radio" name="cs_basis" value="actual">
                            <span><strong>Charge only the nights actually stayed</strong><small id="cs_basis_actual_help"></small></span>
                        </label>
                    </fieldset>

                    <div id="cs_quote" class="rh-dialog-lead" hidden></div>

                    <!-- Money received now for the extra nights (optional; only what was actually received) -->
                    <div id="cs_charge_row" style="display:none;">
                        <div class="form-group">
                            <label for="cs_amount_received">Amount received now (optional)</label>
                            <input type="number" id="cs_amount_received" class="form-control" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                            <small>Enter only money the guest actually paid now. Anything not paid stays on the bill as a balance.</small>
                        </div>
                        <div class="form-group">
                            <label for="cs_payment_method">Payment method</label>
                            <select id="cs_payment_method" name="cs_payment_method" class="form-control">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- How the refund is paid out: back to the original payment method(s), as the normal Refund records it -->
                    <div id="cs_refund_row" style="display:none;">
                        <?php /* Store credit only when the refund setting is "ask", as on the normal Refund. Nothing preselected. */ ?>
                        <fieldset id="cs_refund_as_box" class="cs-choices rh-choices" hidden>
                            <legend>How is the refund paid? <small>Pick one, as on the Refund button.</small></legend>
                            <label class="cs-choice rh-choice">
                                <input type="radio" name="cs_refund_as" value="original">
                                <span><strong>Back to the original payment method</strong><small id="cs_refund_as_original_help"></small></span>
                            </label>
                            <label class="cs-choice rh-choice">
                                <input type="radio" name="cs_refund_as" value="store_credit">
                                <span><strong>Store credit (credit note)</strong><small>A credit note for the refund amount, emailed to whoever paid; no money is paid out now.</small></span>
                            </label>
                        </fieldset>
                        <div class="form-group" id="cs_refund_method_line">
                            <strong>Refund method:</strong> <span id="cs_refund_methods">the original payment method</span>
                            <small>The refund goes back the way the guest paid, the same as the Refund button on a payment.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="cs_notes">Notes (optional)</label>
                        <input type="text" id="cs_notes" name="cs_notes" class="form-control" placeholder="e.g. guest agreed, cash collected" maxlength="200">
                    </div>
                    <p id="cs_error" class="bookings-checkin-modal__error" style="display:none;"></p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCheckoutSettlementModal()">Cancel</button>
                    <button type="button" id="cs_proceed_btn" class="btn btn-primary" disabled onclick="const f=document.getElementById('checkoutSettlementForm'); if(f){ f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); } return false;">Proceed & Checkout</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .cs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
        }

        .cs-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }

        .cs-table tr.cs-total td {
            font-weight: 700;
            background: #f7f3ee;
            border-top: 2px solid #c0a882;
        }

        .modal-content {
            position: relative;
        }

        .modal-inline-loader {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.76);
            backdrop-filter: blur(1.5px);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.58rem;
            border-radius: inherit;
            z-index: 40;
            color: #1f2937;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .modal-inline-loader__spinner {
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(31, 41, 55, 0.18);
            border-top-color: #1f2937;
            border-radius: 999px;
            animation: modal-inline-spin 0.72s linear infinite;
        }

        .modal-content.is-modal-busy {
            pointer-events: none;
        }

        /* The loader's display:flex used to beat the [hidden] attribute, so after the first failed attempt the
           "Processing..." layer stayed over the dialog and swallowed every click (the "stalling" check-in modal). */
        .modal-inline-loader[hidden] {
            display: none;
        }

        /* Late check-in / checkout choice cards: shared .rh-choices / .rh-choice in admin-components.css. */

        @keyframes modal-inline-spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <!-- View Booking Details Modal -->
    <div id="viewBookingModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header bookings-view-modal__header">
                <div class="bookings-view-modal__title-block">
                    <h3><i class="fas fa-eye" aria-hidden="true"></i> Booking Details</h3>
                    <div class="bookings-view-modal__meta" aria-label="Booking quick summary">
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Ref</span>
                            <span id="view_booking_header_ref">—</span>
                        </span>
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Status</span>
                            <span id="view_booking_header_status">—</span>
                        </span>
                        <span class="bookings-view-modal__pill">
                            <span class="bookings-view-modal__pill-label">Payment</span>
                            <span id="view_booking_header_payment">—</span>
                        </span>
                    </div>
                </div>
                <button type="button" class="close-modal" onclick="closeViewBookingModal()" aria-label="Close booking details modal"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body">
                <div class="details-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                    <div class="detail-item">
                        <label>Booking Reference:</label>
                        <div id="view_booking_ref" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Guest Name:</label>
                        <div id="view_guest_name" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Email:</label>
                        <div id="view_guest_email" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Phone:</label>
                        <div id="view_guest_phone" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Room:</label>
                        <div id="view_room_name" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Individual Room:</label>
                        <div id="view_individual_room" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Check-in:</label>
                        <div id="view_check_in" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Check-out:</label>
                        <div id="view_check_out" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Nights:</label>
                        <div id="view_nights" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Guests:</label>
                        <div id="view_guests" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Total Amount (incl. VAT &amp; levy):<span id="view_total_info"></span></label>
                        <div id="view_total" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Booked at (room rate a night &times; nights):</label>
                        <div id="view_booked_rate" class="detail-value"></div>
                        <div id="view_booked_rate_today" style="color:#666;font-size:12px;" hidden></div>
                    </div>
                    <div class="detail-item">
                        <label>Status:</label>
                        <div id="view_status" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Payment Status:</label>
                        <div id="view_payment" class="detail-value"></div>
                    </div>
                    <div class="detail-item">
                        <label>Created At:</label>
                        <div id="view_created" class="detail-value"></div>
                    </div>
                </div>
                <div id="view_money_wrap" class="details-grid bk-money-grid" hidden>
                    <div class="detail-item bk-money-card">
                        <label>How the total is made up</label>
                        <small id="view_money_help" class="bk-money-help"></small>
                        <div id="view_money_lines" class="bk-money-lines"></div>
                        <small id="view_money_note" class="bk-money-help" hidden></small>
                    </div>
                </div>
                <div class="detail-item" style="grid-column: span 2;">
                    <label>Special Requests:</label>
                    <div id="view_special_requests" class="detail-value" style="background: #f5f5f5; padding: 8px; border-radius: 6px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewBookingModal()">Close</button>
                <a id="view_full_details_link" href="#" class="btn btn-primary" target="_blank"><i class="fas fa-external-link-alt"></i> Full Details</a>
            </div>
        </div>
    </div>

    <!-- Room type change (upgrade / downgrade) modal -->
    <div id="upgradeRoomModal" class="modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="upgradeRoomTitle">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h3 id="upgradeRoomTitle"><i class="fas fa-arrow-up-right-dots"></i> Upgrade / change room type</h3>
                <button type="button" class="close-modal" onclick="closeUpgradeRoomModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="rc_summary" class="rc-summary">Loading booking&hellip;</div>

                <div class="form-group">
                    <label for="upgrade_new_room"><i class="fas fa-bed"></i> Move to room type</label>
                    <select id="upgrade_new_room" class="form-control" required>
                        <option value="">Loading room types&hellip;</option>
                    </select>
                    <small id="rc_type_hint" class="rc-hint">Rooms that are full for these dates, or too small for the party, cannot be chosen.</small>
                </div>

                <div id="rc_detail" hidden>
                    <div id="rc_direction" class="rc-direction"></div>

                    <fieldset class="rc-pricing">
                        <legend>Billing</legend>
                        <label class="rc-option">
                            <input type="radio" name="rc_pricing" value="charge" checked>
                            <span><strong id="rc_charge_label">Charge the difference</strong><br><small id="rc_charge_hint"></small></span>
                        </label>
                        <label class="rc-option" id="rc_keep_wrap">
                            <input type="radio" name="rc_pricing" value="keep">
                            <span><strong id="rc_keep_label">No charge</strong><br><small id="rc_keep_hint"></small></span>
                        </label>
                    </fieldset>

                    <div class="form-group">
                        <label for="rc_reason"><i class="fas fa-circle-question"></i> Reason</label>
                        <select id="rc_reason" class="form-control" required></select>
                    </div>
                    <div class="form-group">
                        <label for="rc_note"><i class="fas fa-comment"></i> Note <span id="rc_note_req" class="rc-req">(required)</span></label>
                        <textarea id="rc_note" class="form-control" rows="2" maxlength="500" placeholder="Who authorised it and why, e.g. 'Room 12 out of order - moved by duty manager'"></textarea>
                    </div>

                    <div class="rc-totals">
                        <div><span>Current bill (room)</span><strong id="rc_current_total">-</strong></div>
                        <div><span>New bill (room)</span><strong id="rc_new_total">-</strong></div>
                        <div><span>Change</span><strong id="rc_delta">-</strong></div>
                    </div>
                    <p id="rc_accounting_note" class="rc-hint"></p>

                    <label class="rc-option rc-option--inline">
                        <input type="checkbox" id="upgrade_send_email" value="1" checked>
                        <span><i class="fas fa-envelope"></i> Email the guest about the change</span>
                    </label>
                </div>
                <input type="hidden" id="upgrade_booking_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUpgradeRoomModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="rc_submit" onclick="submitUpgradeRoom()" disabled><i class="fas fa-check"></i> Apply room change</button>
            </div>
        </div>
    </div>
    <style>
        #upgradeRoomModal .rc-summary { background: var(--admin-surface-muted, #f6f4f0); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; line-height: 1.55; }
        #upgradeRoomModal .rc-hint { color: #666; font-size: 12px; display: block; margin-top: 4px; }
        #upgradeRoomModal .rc-direction { border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; font-size: 13px; font-weight: 600; }
        #upgradeRoomModal .rc-direction--upgrade { background: #e8f5e9; color: #1b5e20; }
        #upgradeRoomModal .rc-direction--downgrade { background: #fff4e5; color: #8a4b00; }
        #upgradeRoomModal .rc-direction--same { background: #eef3ff; color: #1f2d6b; }
        #upgradeRoomModal .rc-pricing { border: 1px solid #e3ddd3; border-radius: 8px; padding: 8px 12px 4px; margin: 0 0 12px; }
        #upgradeRoomModal .rc-pricing legend { font-size: 12px; font-weight: 600; padding: 0 4px; }
        #upgradeRoomModal .rc-option { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 8px; font-size: 13px; cursor: pointer; }
        #upgradeRoomModal .rc-option input { margin-top: 3px; }
        #upgradeRoomModal .rc-option--inline { align-items: center; margin-top: 10px; }
        #upgradeRoomModal .rc-option.is-disabled { opacity: .55; cursor: not-allowed; }
        #upgradeRoomModal .rc-req { color: #b42318; font-weight: 400; font-size: 12px; }
        #upgradeRoomModal .rc-totals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; background: #faf8f4; border-radius: 8px; padding: 10px 12px; }
        #upgradeRoomModal .rc-totals div { display: flex; flex-direction: column; font-size: 12px; color: #666; }
        #upgradeRoomModal .rc-totals strong { font-size: 15px; color: #222; }
        @media (max-width: 520px) { #upgradeRoomModal .rc-totals { grid-template-columns: 1fr; } }
    </style>

    <script>
        // Ensure setBookingPageModalOpen is available early (full definition also lives in
        // the third <script> block below; this copy guarantees it is defined before any
        // onclick handler in this block fires, even if the later block fails to parse).
        if (typeof setBookingPageModalOpen !== 'function') {
            window.setBookingPageModalOpen = function setBookingPageModalOpen(modal, isOpen) {
                if (!modal) return;
                modal.style.transition = 'none';
                modal.style.display = isOpen ? 'flex' : 'none';
                modal.style.opacity = isOpen ? '1' : '0';
                modal.style.visibility = isOpen ? 'visible' : 'hidden';
                modal.classList.toggle('active', isOpen);
                modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen) {
                    document.body.classList.add('modal-open');
                } else if (!document.querySelector('.modal-overlay.active, .modal.active')) {
                    document.body.classList.remove('modal-open');
                }
            };
        }

        let viewBookingRequestController = null;
        let viewBookingActiveTrigger = null;

        function setViewBookingTriggerLoading(button, isLoading) {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            if (isLoading) {
                if (!button.dataset.originalContent) {
                    button.dataset.originalContent = button.innerHTML;
                }
                if (!button.dataset.originalAriaLabel) {
                    button.dataset.originalAriaLabel = button.getAttribute('aria-label') || '';
                }

                button.disabled = true;
                button.classList.add('btn-loading');
                button.setAttribute('aria-busy', 'true');
                button.setAttribute('aria-label', 'Loading booking details');
                button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>';
                return;
            }

            button.disabled = false;
            button.classList.remove('btn-loading');
            button.removeAttribute('aria-busy');
            if (button.dataset.originalAriaLabel) {
                button.setAttribute('aria-label', button.dataset.originalAriaLabel);
            }
            if (button.dataset.originalContent) {
                button.innerHTML = button.dataset.originalContent;
            }
            delete button.dataset.originalAriaLabel;
            delete button.dataset.originalContent;
        }

        function buildViewBookingFallback(triggerButton, bookingId, bookingReference) {
            const dataset = (triggerButton instanceof HTMLElement && triggerButton.dataset) ? triggerButton.dataset : {};

            const fallbackRef = String(bookingReference || dataset.bookingRef || bookingId || '').trim();
            const fallbackRoomName = String(dataset.roomName || '').trim();
            const fallbackIndividualRoomName = String(dataset.individualRoomName || '').trim();
            const fallbackIndividualRoomNumber = String(dataset.individualRoomNumber || '').trim();
            const fallbackIndividualRoom = fallbackIndividualRoomName ?
                (fallbackIndividualRoomNumber ? (fallbackIndividualRoomName + ' (#' + fallbackIndividualRoomNumber + ')') : fallbackIndividualRoomName) :
                (fallbackIndividualRoomNumber || 'Not assigned');

            return {
                booking_reference: fallbackRef,
                guest_name: String(dataset.guestName || '').trim(),
                guest_email: String(dataset.guestEmail || '').trim(),
                guest_phone: String(dataset.guestPhone || '').trim(),
                room_name: fallbackRoomName,
                individual_room_name: fallbackIndividualRoomName,
                individual_room_number: fallbackIndividualRoomNumber,
                check_in_date_formatted: String(dataset.checkInDate || '').trim(),
                check_out_date_formatted: String(dataset.checkOutDate || '').trim(),
                number_of_nights: String(dataset.numberOfNights || '').trim(),
                number_of_guests: String(dataset.numberOfGuests || '').trim(),
                total_formatted: String(dataset.totalDisplay || '').trim(),
                booked_rate_text: String(dataset.bookedRate || '').trim(),
                status_label: String(dataset.statusLabel || '').trim(),
                payment_status_label: String(dataset.paymentStatusLabel || '').trim(),
                created_at_formatted: String(dataset.createdAtLabel || '').trim(),
                special_requests: String(dataset.specialRequests || '').trim(),
                __fallbackIndividualRoomDisplay: fallbackIndividualRoom
            };
        }

        // "How the total is made up" card: server-built lines (stored figures), drawn with textContent only.
        function renderViewMoneyCard(card) {
            const wrap = document.getElementById('view_money_wrap');
            const list = document.getElementById('view_money_lines');
            if (!wrap || !list) return;
            list.textContent = '';
            if (!card || !card.ok || !Array.isArray(card.lines) || !card.lines.length) {
                wrap.hidden = true;
                return;
            }
            const help = document.getElementById('view_money_help');
            if (help) help.textContent = String(card.help || '');
            card.lines.forEach(function(ln) {
                const row = document.createElement('div');
                row.className = 'bk-money-line bk-money-line--' + String(ln.kind || 'part').replace(/[^a-z]/g, '');
                const label = document.createElement('span');
                label.textContent = String(ln.label || '');
                const value = document.createElement('strong');
                value.textContent = String(ln.value || '');
                row.appendChild(label);
                row.appendChild(value);
                list.appendChild(row);
            });
            const note = document.getElementById('view_money_note');
            if (note) {
                note.textContent = String(card.note || '');
                note.hidden = !card.note;
            }
            wrap.hidden = false;
        }

        function populateViewBookingModalFields(booking) {
            const setText = function(id, value, emptyValue = '—') {
                const element = document.getElementById(id);
                if (!element) return;
                const normalized = String(value ?? '').trim();
                element.textContent = normalized !== '' ? normalized : emptyValue;
            };

            const individualRoom = String(booking.__fallbackIndividualRoomDisplay || '').trim() ||
                (booking.individual_room_name ?
                    (booking.individual_room_number ? (booking.individual_room_name + ' (#' + booking.individual_room_number + ')') : booking.individual_room_name) :
                    (booking.individual_room_number || 'Not assigned'));

            setText('view_booking_ref', booking.booking_reference, '—');
            setText('view_guest_name', booking.guest_name, '—');
            setText('view_guest_email', booking.guest_email, '—');
            setText('view_guest_phone', booking.guest_phone, '—');
            setText('view_room_name', booking.room_name, '—');
            setText('view_individual_room', individualRoom, 'Not assigned');
            setText('view_check_in', booking.check_in_date_formatted || booking.check_in_date, '—');
            setText('view_check_out', booking.check_out_date_formatted || booking.check_out_date, '—');
            setText('view_nights', booking.number_of_nights, '—');
            setText('view_guests', booking.number_of_guests, '—');
            setText('view_total', booking.total_formatted || booking.total_amount, '—');
            (function () {
                var infoEl = document.getElementById('view_total_info');
                if (infoEl) infoEl.innerHTML = (window.rhTotalInfoHtml && booking.total_breakdown) ? window.rhTotalInfoHtml(booking.total_breakdown) : '';
            })();
            setText('view_booked_rate', booking.booked_rate_text, '—');
            renderViewMoneyCard(booking.money_card);
            (function () {
                var todayEl = document.getElementById('view_booked_rate_today');
                if (!todayEl) return;
                var t = String(booking.booked_rate_today || '').trim();
                todayEl.textContent = t;
                todayEl.hidden = t === '';
            })();
            setText('view_status', booking.status_label || booking.status, '—');
            setText('view_payment', booking.payment_status_label || booking.payment_status, '—');
            setText('view_created', booking.created_at_formatted || booking.created_at, '—');
            setText('view_special_requests', booking.special_requests, 'None');

            setText('view_booking_header_ref', booking.booking_reference, '—');
            setText('view_booking_header_status', booking.status_label || booking.status, '—');
            setText('view_booking_header_payment', booking.payment_status_label || booking.payment_status, '—');
        }

        function openViewBookingModal(bookingId, bookingReference, triggerButton = null, clickEvent = null) {
            if (clickEvent) {
                clickEvent.preventDefault();
                clickEvent.stopPropagation();
            }

            let normalizedBookingId = Number.parseInt(String(bookingId || ''), 10);
            if (!Number.isFinite(normalizedBookingId) || normalizedBookingId <= 0) {
                const triggerBookingId = triggerButton instanceof HTMLElement ? Number.parseInt(String(triggerButton.dataset.bookingId || ''), 10) : NaN;
                if (Number.isFinite(triggerBookingId) && triggerBookingId > 0) {
                    normalizedBookingId = triggerBookingId;
                }
            }

            if (!Number.isFinite(normalizedBookingId) || normalizedBookingId <= 0) {
                showAlert('Unable to open booking details because the booking ID is invalid.', 'error');
                return;
            }

            if (viewBookingRequestController && typeof viewBookingRequestController.abort === 'function') {
                viewBookingRequestController.abort();
            }

            if (viewBookingActiveTrigger && viewBookingActiveTrigger !== triggerButton) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
            }

            viewBookingActiveTrigger = triggerButton instanceof HTMLElement ? triggerButton : null;
            if (viewBookingActiveTrigger) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, true);
            }

            const modal = document.getElementById('viewBookingModal');
            setBookingPageModalOpen(modal, true);
            // Set full details link
            const fullDetailsLink = document.getElementById('view_full_details_link');
            fullDetailsLink.href = `booking-details.php?id=${normalizedBookingId}`;

            // Immediately hydrate with row-level fallback values so the modal is
            // still useful even if the network request is slow or fails.
            const fallbackBooking = buildViewBookingFallback(viewBookingActiveTrigger, normalizedBookingId, bookingReference);
            populateViewBookingModalFields(fallbackBooking);

            // Fetch booking details via AJAX
            const formData = new FormData();
            formData.append('action', 'get_booking_details');
            formData.append('booking_id', String(normalizedBookingId));
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = window._rhCsrf || (csrfMeta ? (csrfMeta.getAttribute('content') || '') : '');
            if (csrfToken && !formData.has('csrf_token')) {
                formData.append('csrf_token', csrfToken);
            }

            viewBookingRequestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const requestSignal = viewBookingRequestController ? viewBookingRequestController.signal : undefined;

            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: formData,
                    signal: requestSignal,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(async response => {
                    const payloadText = await response.text();
                    let data;
                    try {
                        data = JSON.parse(payloadText);
                    } catch (parseError) {
                        throw new Error('Received an invalid booking details response.');
                    }

                    if (!response.ok || !data.success || !data.data) {
                        throw new Error(data.message || 'Failed to load booking details.');
                    }

                    return data.data;
                })
                .then(booking => {
                    populateViewBookingModalFields(booking);
                })
                .catch((error) => {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    const message = (error && error.message) ? error.message : 'Error loading booking details. Please try again.';
                    Alert.show(message, 'error');
                })
                .finally(() => {
                    if (viewBookingRequestController && viewBookingRequestController.signal === requestSignal) {
                        viewBookingRequestController = null;
                    }
                    if (viewBookingActiveTrigger) {
                        setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
                        viewBookingActiveTrigger = null;
                    }
                });
        }

        function closeViewBookingModal() {
            const modal = document.getElementById('viewBookingModal');
            if (viewBookingRequestController && typeof viewBookingRequestController.abort === 'function') {
                viewBookingRequestController.abort();
                viewBookingRequestController = null;
            }
            if (viewBookingActiveTrigger) {
                setViewBookingTriggerLoading(viewBookingActiveTrigger, false);
                viewBookingActiveTrigger = null;
            }
            setBookingPageModalOpen(modal, false);
        }

        // Close modal when clicking outside
        document.getElementById('viewBookingModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeViewBookingModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }
            const modal = document.getElementById('viewBookingModal');
            if (!modal || !modal.classList.contains('active')) {
                return;
            }
            closeViewBookingModal();
        });

        function openResendEmailModal(bookingId, bookingReference, bookingStatus, isLateOrOverdue = false) {
            const modal = document.getElementById('resendEmailModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('modal_booking_id').value = bookingId;
            document.getElementById('modal_booking_reference').value = bookingReference;

            // Reset success state
            document.getElementById('resendEmailSuccess').style.display = 'none';
            document.getElementById('resendEmailSubmitBtn').style.display = '';
            const prevErr = document.getElementById('resendEmailError');
            if (prevErr) prevErr.remove();

            // Show/hide reminder option — only for late check-in or overdue bookings
            const reminderOpt = document.getElementById('opt_booking_reminder');
            if (reminderOpt) {
                reminderOpt.style.display = isLateOrOverdue ? '' : 'none';
            }

            // Tentative email types only make sense for tentative bookings — hiding
            // them elsewhere stops a confirmed booking from offering a "Tentative"
            // email type in the dropdown.
            const isTentative = bookingStatus === 'tentative';
            ['opt_tentative_confirmed', 'opt_tentative_converted'].forEach(function (id) {
                const opt = document.getElementById(id);
                if (opt) opt.style.display = isTentative ? '' : 'none';
            });

            // Set default email type based on booking status
            const emailTypeSelect = document.getElementById('email_type');
            emailTypeSelect.value = '';

            switch (bookingStatus) {
                case 'pending':
                    emailTypeSelect.value = 'booking_received';
                    break;
                case 'tentative':
                    emailTypeSelect.value = 'tentative_confirmed';
                    break;
                case 'confirmed':
                    emailTypeSelect.value = isLateOrOverdue ? 'booking_reminder' : 'booking_confirmed';
                    break;
                case 'cancelled':
                    emailTypeSelect.value = 'booking_cancelled';
                    break;
                case 'checked-in':
                    if (isLateOrOverdue) emailTypeSelect.value = 'booking_reminder';
                    break;
            }
        }

        function closeResendEmailModal() {
            const modal = document.getElementById('resendEmailModal');
            setBookingPageModalOpen(modal, false);
            const form = document.getElementById('resendEmailForm');
            form.reset();
            // Restore form body if it was hidden by success state
            const body = form.querySelector('.modal-body');
            if (body) body.style.display = '';
            const successEl = document.getElementById('resendEmailSuccess');
            if (successEl) successEl.style.display = 'none';
            const submitBtn = document.getElementById('resendEmailSubmitBtn');
            if (submitBtn) { submitBtn.style.display = ''; submitBtn.disabled = false; }
            const errEl = document.getElementById('resendEmailError');
            if (errEl) errEl.remove();
        }

        // AJAX submission for resend email form
        document.addEventListener('DOMContentLoaded', function() {
            const resendForm = document.getElementById('resendEmailForm');
            if (!resendForm) return;
            resendForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const submitBtn = resendForm.querySelector('[type="submit"]');
                const originalLabel = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
                }

                // Remove any previous inline error
                const prevErr = document.getElementById('resendEmailError');
                if (prevErr) prevErr.remove();

                const formData = new FormData(resendForm);
                fetch(rhBookingsPostUrl(), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(res => {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Show inline success (visible on mobile without needing to scroll)
                            const successEl = document.getElementById('resendEmailSuccess');
                            const successMsg = document.getElementById('resendEmailSuccessMsg');
                            if (successEl) {
                                if (successMsg) successMsg.textContent = data.message || 'Email sent successfully!';
                                resendForm.querySelector('.modal-body').style.display = 'none';
                                successEl.style.display = 'block';
                                if (submitBtn) submitBtn.style.display = 'none';
                            }
                            // Auto-close after 1.6s then show toast
                            setTimeout(() => {
                                closeResendEmailModal();
                                showBookingActionMessage(data.message || 'Email sent successfully.', 'success');
                            }, 1600);
                        } else {
                            const errDiv = document.createElement('div');
                            errDiv.id = 'resendEmailError';
                            errDiv.style.cssText = 'color:#dc3545;font-size:13px;margin:8px 0 0;padding:8px 12px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;';
                            errDiv.textContent = data.message || 'Failed to send email.';
                            resendForm.querySelector('.modal-footer').before(errDiv);
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalLabel;
                            }
                        }
                    })
                    .catch(() => {
                        const errDiv = document.createElement('div');
                        errDiv.id = 'resendEmailError';
                        errDiv.style.cssText = 'color:#dc3545;font-size:13px;margin:8px 0 0;padding:8px 12px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;';
                        errDiv.textContent = 'Network error — please try again.';
                        resendForm.querySelector('.modal-footer').before(errDiv);
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalLabel;
                        }
                    });
            });
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('resendEmailModal');
            if (event.target === modal) {
                closeResendEmailModal();
            }
            const modal2 = document.getElementById('makeTentativeModal');
            if (event.target === modal2) {
                closeMakeTentativeModal();
            }
            const modal3 = document.getElementById('checkInModal');
            if (event.target === modal3) {
                closeCheckInModal();
            }
            const modal4 = document.getElementById('cancelBookingModal');
            if (event.target === modal4) {
                closeCancelBookingModal();
            }
            const modal5 = document.getElementById('missedCheckinsModal');
            if (event.target === modal5) {
                closeMissedCheckinsModal();
            }
            const modal6 = document.getElementById('overdueCheckoutsModal');
            if (event.target === modal6) {
                closeOverdueCheckoutsModal();
            }
            const modal7 = document.getElementById('bookingsStatsInsightModal');
            if (event.target === modal7) {
                closeBookingsStatsInsightModal();
            }
        });

        // Quick Room Assignment Modal Functions
        let selectedRoomId = null;

        function openQuickRoomAssignModal(bookingId, bookingReference, checkIn, checkOut, roomId) {
            const modal = document.getElementById('quickRoomAssignModal');
            if (modal) {
                setBookingPageModalOpen(modal, true);
            }
            document.getElementById('quick_assign_booking_id').value = bookingId;
            document.getElementById('quick_assign_booking_ref').value = bookingReference;
            document.getElementById('quick_assign_room_id').value = roomId;

            const checkInDate = new Date(checkIn);
            const checkOutDate = new Date(checkOut);
            document.getElementById('quick_assign_dates').value =
                checkInDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }) +
                ' - ' +
                checkOutDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });

            // Load available rooms
            loadAvailableRooms(roomId, checkIn, checkOut, bookingId);
        }

        function closeQuickRoomAssignModal() {
            const modal = document.getElementById('quickRoomAssignModal');
            if (modal) {
                setBookingPageModalOpen(modal, false);
            }
            document.getElementById('quick_assign_room_list').innerHTML = '';
            document.getElementById('quick_assign_override_panel').style.display = 'none';
            document.getElementById('quick_assign_children_override').checked = false;
            document.getElementById('quick_assign_override_note').value = '';
            selectedRoomId = null;
            bkDeepCancel();
        }

        function quickAssignEsc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[ch]);
        }

        // Lists EVERY room of the booking's type (or every joined pair for a joined-room type).
        // Rooms that cannot take the stay stay visible, greyed out with the reason, instead of
        // silently disappearing. A room still being turned over can be assigned: check-in waits
        // for housekeeping, not the assignment.
        function loadAvailableRooms(roomId, checkIn, checkOut, bookingId) {
            const roomList = document.getElementById('quick_assign_room_list');
            const modal = document.getElementById('quickRoomAssignModal');
            roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Loading rooms...</div>';

            const formData = new FormData();
            formData.append('action', 'get_available_rooms');
            formData.append('room_type_id', roomId);
            formData.append('check_in', checkIn);
            formData.append('check_out', checkOut);
            formData.append('exclude_booking_id', bookingId);

            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const mode = data.mode === 'combinations' ? 'combinations' : 'rooms';
                    if (modal) modal.dataset.assignMode = mode;
                    const items = (data.success && Array.isArray(data.data)) ? data.data : [];
                    if (!items.length) {
                        roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> '
                            + (mode === 'combinations' ? 'No joined-room pairs are set up for this room type.' : 'No active rooms are set up for this room type.') + '</div>';
                        return;
                    }
                    const free = items.filter(i => i.available && !i.current).length;
                    roomList.innerHTML = '';
                    const summary = document.createElement('div');
                    summary.style.cssText = 'font-size: 12px; color: #555; margin-bottom: 8px;';
                    summary.textContent = free + ' of ' + items.length + (mode === 'combinations' ? ' joined-room pairs' : ' rooms') + ' can take this stay.';
                    roomList.appendChild(summary);

                    items.forEach(room => {
                        const roomCard = document.createElement('div');
                        const selectable = room.available && !room.current;
                        const requiresChildOverride = room.requires_child_override === true;
                        const turnover = selectable && room.turnover;
                        const accent = !room.available ? '#dc3545' : (room.current ? '#6c757d' : ((requiresChildOverride || turnover || room.held_for_joined) ? '#f0ad4e' : '#28a745'));
                        roomCard.className = 'room-assign-card';
                        roomCard.dataset.available = selectable ? 'true' : 'false';
                        roomCard.dataset.accent = accent;
                        roomCard.dataset.requiresChildOverride = requiresChildOverride ? 'true' : 'false';
                        roomCard.style.cssText = `
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            gap: 12px;
                            padding: 12px;
                            margin-bottom: 8px;
                            border: 2px solid ${accent};
                            border-radius: 8px;
                            cursor: ${selectable ? 'pointer' : 'not-allowed'};
                            background: ${selectable ? '#fff' : '#f6f6f6'};
                            opacity: ${room.available ? '1' : '0.75'};
                            transition: all 0.2s;
                        `;

                        let title;
                        if (mode === 'combinations') {
                            title = 'Rooms ' + quickAssignEsc(room.label) + (room.room_name ? ` <small style="font-weight:400;opacity:0.8">${quickAssignEsc(room.room_name)}</small>` : '');
                        } else {
                            title = 'Room ' + quickAssignEsc(room.label)
                                + (room.room_name && room.room_name !== room.label ? ` <small style="font-weight:400;opacity:0.8">${quickAssignEsc(room.room_name)}</small>` : '');
                        }

                        const notes = [];
                        if (room.floor) notes.push(`<small style="color: #666;"><i class="fas fa-layer-group"></i> Floor: ${quickAssignEsc(room.floor)}</small>`);
                        if (!room.available) notes.push(`<small style="display:block; color:#a71d2a; margin-top:4px;"><i class="fas fa-ban"></i> ${quickAssignEsc(room.reason)}</small>`);
                        if (turnover) notes.push(`<small style="display:block; color:#7a4f01; margin-top:4px;"><i class="fas fa-broom"></i> ${quickAssignEsc(room.turnover)} - it must be marked clean before check-in</small>`);
                        if (selectable && room.held_for_joined) notes.push(`<small style="display:block; color:#7a4f01; margin-top:4px;"><i class="fas fa-link"></i> Held for a joined-room booking that has no rooms yet</small>`);
                        if (requiresChildOverride) notes.push(`<small style="display:block; color:#7a4f01; margin-top:4px;"><i class="fas fa-triangle-exclamation"></i> Child policy override note required</small>`);

                        let badge;
                        if (room.current) {
                            badge = ['#e2e3e5', '#383d41', 'Current'];
                        } else if (!room.available) {
                            badge = ['#f8d7da', '#721c24', 'Unavailable'];
                        } else if (requiresChildOverride) {
                            badge = ['#fff3cd', '#7a4f01', 'Needs note'];
                        } else if (turnover) {
                            badge = ['#fff3cd', '#7a4f01', 'Turnover'];
                        } else {
                            badge = ['#d4edda', '#155724', 'Available'];
                        }

                        roomCard.innerHTML = `
                            <div>
                                <div style="font-weight: 600; color: var(--navy);">
                                    <i class="fas fa-door-open" style="color: var(--gold);"></i>
                                    ${title}
                                </div>
                                ${notes.join('')}
                            </div>
                            <div>
                                <span class="badge" style="background: ${badge[0]}; color: ${badge[1]}; padding: 4px 12px; border-radius: 12px; font-size: 11px; white-space: nowrap;">${badge[2]}</span>
                            </div>
                        `;

                        if (selectable) {
                            roomCard.onclick = () => selectRoomForAssignment(room.id, roomCard);
                        }

                        roomList.appendChild(roomCard);
                    });
                })
                .catch(() => {
                    roomList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Error loading rooms.</div>';
                });
        }

        function selectRoomForAssignment(roomId, cardElement) {
            selectedRoomId = roomId;

            // Remove previous selection
            document.querySelectorAll('.room-assign-card').forEach(card => {
                delete card.dataset.selected;
                card.style.background = card.dataset.available === 'true' ? '#fff' : '#f6f6f6';
                card.style.borderColor = card.dataset.accent || '#28a745';
            });

            // Highlight selected card
            cardElement.dataset.selected = 'true';
            cardElement.style.background = '#fff8e1';
            cardElement.style.borderColor = 'var(--gold)';

            const requiresOverride = cardElement.dataset.requiresChildOverride === 'true';
            document.getElementById('quick_assign_override_panel').style.display = requiresOverride ? 'block' : 'none';
            if (!requiresOverride) {
                document.getElementById('quick_assign_children_override').checked = false;
                document.getElementById('quick_assign_override_note').value = '';
            }
        }

        async function submitQuickRoomAssign() {
            if (!selectedRoomId) {
                Alert.show('Please select a room to assign.', 'error');
                return;
            }

            const modal = document.getElementById('quickRoomAssignModal');
            const isPair = !!(modal && modal.dataset.assignMode === 'combinations');
            const bookingId = document.getElementById('quick_assign_booking_id').value;
            const bookingRef = document.getElementById('quick_assign_booking_ref').value;
            const dates = document.getElementById('quick_assign_dates').value;
            const selectedCard = document.querySelector('.room-assign-card[data-selected="true"]');
            const roomName = selectedCard ? selectedCard.textContent.replace(/\s+/g, ' ').trim() : 'Selected room';
            const requiresOverride = selectedCard && selectedCard.dataset.requiresChildOverride === 'true';
            const overrideChecked = document.getElementById('quick_assign_children_override').checked;
            const overrideNote = document.getElementById('quick_assign_override_note').value.trim();

            if (requiresOverride && (!overrideChecked || overrideNote === '')) {
                Alert.show('A child policy override note is required for this room.', 'error');
                return;
            }

            const confirmed = await confirmAdminAction({
                title: isPair ? 'Confirm joined-room assignment' : 'Confirm room assignment',
                message: (isPair ? 'Assign this pair of rooms to booking ' : 'Assign this room to booking ') + (bookingRef || bookingId) + '?',
                details: [(isPair ? 'Rooms: ' : 'Room: ') + roomName, 'Stay dates: ' + dates],
                confirmText: isPair ? 'Assign Rooms' : 'Assign Room',
                icon: 'fa-door-open',
                tone: 'success'
            });
            if (!confirmed) return;

            const submitBtn = document.querySelector('#quickRoomAssignModal button[onclick="submitQuickRoomAssign()"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            showLoadingOverlay(isPair ? 'Assigning rooms...' : 'Assigning room...');

            const formData = new FormData();
            formData.append('booking_id', bookingId);
            if (isPair) {
                formData.append('action', 'assign_room_combination');
                formData.append('combination_id', selectedRoomId);
            } else {
                formData.append('action', 'assign_individual_room');
                formData.append('individual_room_id', selectedRoomId);
                if (requiresOverride) {
                    formData.append('allow_child_policy_override', '1');
                    formData.append('child_policy_override_note', overrideNote);
                }
            }

            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const successMessage = data.message || 'Room assigned successfully!';
                        showBookingActionMessage(successMessage, 'success');
                        queueBookingActionMessage(successMessage, 'success');
                        window.bkDeepLeaving = true; // closing the modal below is not a cancel
                        closeQuickRoomAssignModal();
                        setTimeout(bkReturnOrReload, 1000);
                    } else {
                        hideLoadingOverlay();
                        if (submitBtn) setButtonLoading(submitBtn, false);
                        Alert.show(data.message || 'Failed to assign room.', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show('Error assigning room.', 'error');
                });
        }

        // Make Tentative Modal Functions
        function openMakeTentativeModal(bookingId, bookingReference, actionType) {
            const modal = document.getElementById('makeTentativeModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('make_tentative_booking_id').value = bookingId;
            document.getElementById('make_tentative_ref').value = bookingReference;
            document.getElementById('make_tentative_action').value = actionType;
            // Set modal title based on action
            const title = document.querySelector('#makeTentativeModal h3');
            if (actionType === 'make_tentative') {
                title.innerHTML = '<i class="fas fa-clock"></i> Make Tentative';
                document.querySelector('#makeTentativeForm button[type="submit"]').innerHTML = '<i class="fas fa-check"></i> Make Tentative';
            } else if (actionType === 'convert_to_tentative') {
                title.innerHTML = '<i class="fas fa-clock"></i> Convert to Tentative';
                document.querySelector('#makeTentativeForm button[type="submit"]').innerHTML = '<i class="fas fa-check"></i> Convert to Tentative';
            }
            // Clear note
            document.getElementById('tentative_note').value = '';
        }

        function closeMakeTentativeModal() {
            const modal = document.getElementById('makeTentativeModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('makeTentativeForm').reset();
        }

        // Check In Modal Functions
        // paymentGate (optional): the server's payment rule for this booking (rh_checkin_payment_gate):
        // 'paid' | 'nothing_owed' | 'arrangement' | 'blocked'. Missing = the old payment-status check.
        // Clears anything a previous attempt left behind (busy overlay, spinner button, pointer-events), so the
        // dialog never opens frozen after a request that failed, timed out or was closed mid-way.
        function resetCheckInModalState() {
            const form = document.getElementById('checkInForm');
            const submitBtn = document.getElementById('checkin_submit_btn');
            if (form) {
                form.dataset.busy = '';
                setModalActionLoading(form, false);
            }
            if (submitBtn) {
                submitBtn.classList.remove('btn-loading');
                submitBtn.style.pointerEvents = '';
                submitBtn.style.opacity = '';
                delete submitBtn.dataset.originalContent;
            }
        }

        // Late check-in choices: enabled only for a late arrival, nothing preselected. "Already stayed and left"
        // switches the check-in email off (an ended stay never gets a welcome email).
        function applyCheckInLateChoice() {
            const form = document.getElementById('checkInForm');
            const submitBtn = document.getElementById('checkin_submit_btn');
            const box = document.getElementById('checkin_late_choices');
            const emailBox = document.getElementById('checkin_email_guest');
            const emailGroup = document.getElementById('checkin_email_group');
            if (!form || !submitBtn || !box || box.hidden) return;
            const picked = form.querySelector('input[name="late_arrival"]:checked');
            const ended = !!picked && picked.value === 'ended';
            if (emailBox) {
                if (ended) {
                    emailBox.checked = false;
                    emailBox.disabled = true;
                } else if (emailBox.disabled) {
                    emailBox.disabled = false;
                    emailBox.checked = true;
                }
            }
            if (emailGroup) emailGroup.classList.toggle('is-disabled', ended);
            submitBtn.disabled = !(form.dataset.prereqOk === '1' && picked);
            submitBtn.innerHTML = ended
                ? '<i class="fas fa-flag-checkered"></i> Record completed stay'
                : '<i class="fas fa-sign-in-alt"></i> Confirm Late Check-In';
        }

        function openCheckInModal(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode = 'checkin', paymentGate = '', lateInfo = null) {
            const safeMode = mode === 'noshow' ? 'noshow' : 'checkin';
            const modal = document.getElementById('checkInModal');
            const titleEl = document.getElementById('checkin_modal_title');
            const subtitleEl = document.getElementById('checkin_modal_subtitle');
            const modeBadgeEl = document.getElementById('checkin_mode_badge');
            const modeHintEl = document.getElementById('checkin_mode_hint');
            const contextEl = document.getElementById('checkin_context_note');
            const prerequisitesEl = document.getElementById('checkin_prerequisites');
            const noteGroupEl = document.getElementById('checkin_note_group');
            const errorEl = document.getElementById('checkin_error_message');
            const actionInput = document.getElementById('checkin_action');
            const statusInput = document.getElementById('checkin_status');
            const modeInput = document.getElementById('checkin_modal_mode');
            const submitBtn = document.getElementById('checkin_submit_btn');
            const formEl = document.getElementById('checkInForm');
            const lateBox = document.getElementById('checkin_late_choices');
            const endedWrap = document.getElementById('late_opt_ended_wrap');

            resetCheckInModalState();
            if (formEl) formEl.reset();
            setBookingPageModalOpen(modal, true);

            document.getElementById('checkin_booking_id').value = bookingId;
            document.getElementById('checkin_booking_ref').value = bookingReference;
            document.getElementById('checkin_guest_name').value = guestName;
            document.getElementById('checkin_date').value = checkInDate;

            const gate = String(paymentGate || '');
            const paymentOk = gate !== ''
                ? gate !== 'blocked'
                : (paymentStatus === 'paid' || paymentStatus === 'completed' || paymentStatus === 'partial');
            const roomOk = roomAssigned === true || roomAssigned === '1' || roomAssigned === 'true';
            const statusOk = bookingStatus === 'confirmed';

            // Lateness comes from the SERVER (lateInfo) so the choices shown always match what it requires;
            // the browser clock is only a fallback for an old cached call without it.
            const li = (lateInfo && typeof lateInfo === 'object') ? lateInfo : null;
            const checkInDateObj = new Date(String(checkInDate) + 'T00:00:00');
            const todayObj = new Date();
            todayObj.setHours(0, 0, 0, 0);
            const clientLate = !Number.isNaN(checkInDateObj.getTime()) && checkInDateObj < todayObj
                ? Math.floor((todayObj - checkInDateObj) / 86400000) : 0;
            const overdueDays = li ? (parseInt(li.late_days, 10) || 0) : clientLate;
            const isLateCheckIn = overdueDays > 0;
            const stayEnded = !!(li && li.stay_ended);
            const dateReached = li ? true : (!Number.isNaN(checkInDateObj.getTime()) && checkInDateObj <= todayObj);

            const prereqPaymentEl = document.getElementById('prereq_payment');
            prereqPaymentEl.innerHTML = '<i class="fas ' + (paymentOk ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ';
            prereqPaymentEl.appendChild(document.createTextNode(
                gate === 'arrangement' ? 'Nothing paid yet: pay-later arrangement (balance stays owed)'
                    : (gate === 'nothing_owed' ? 'Nothing owed on this booking'
                    : (paymentOk ? ('Payment: ' + paymentStatus) : 'Payment required (at least partial)'))));
            document.getElementById('prereq_room').innerHTML = '<i class="fas ' + (roomOk ? 'fa-check-circle' : 'fa-exclamation-circle') + '" style="color:' + (roomOk ? '' : '#f0a500') + '"></i> ' +
                (roomOk ? 'Room assigned' : 'No specific room assigned (auto-assign)');
            document.getElementById('prereq_status').innerHTML = '<i class="fas ' + (statusOk ? 'fa-check-circle' : 'fa-times-circle') + '"></i> ' +
                (statusOk ? 'Booking is CONFIRMED' : 'Booking must be CONFIRMED');

            modeInput.value = safeMode;
            contextEl.style.display = 'block';
            errorEl.style.display = 'none';
            errorEl.style.color = '';
            noteGroupEl.style.display = 'block';

            // Late choices: hidden + disabled (never posted) unless this is a late check-in.
            const showLate = safeMode === 'checkin' && isLateCheckIn;
            if (lateBox) {
                lateBox.hidden = !showLate;
                lateBox.querySelectorAll('input[name="late_arrival"]').forEach(function (r) {
                    r.checked = false;
                    r.disabled = !showLate || (r.value === 'ended' && !stayEnded);
                });
            }
            if (endedWrap) endedWrap.hidden = !(showLate && stayEnded);
            if (showLate && li) {
                document.getElementById('late_opt_booked_label').textContent = 'Guest arrived on the booked date (' + (li.check_in || checkInDate) + '), recorded late';
                document.getElementById('late_opt_today_label').textContent = 'Guest is arriving today (' + (li.today || '') + ')';
                document.getElementById('late_opt_ended_label').textContent = 'Guest already stayed and has left: record the completed stay (' + (li.check_in || '') + ' to ' + (li.check_out || '') + ')';
            }

            // "Email the guest a check-in confirmation": check-in only (ticked each time the modal opens); a no-show
            // never posts it.
            const emailGroupEl = document.getElementById('checkin_email_group');
            if (emailGroupEl) {
                const isCheckin = safeMode === 'checkin';
                emailGroupEl.style.display = isCheckin ? 'block' : 'none';
                emailGroupEl.classList.remove('is-disabled');
                emailGroupEl.querySelectorAll('input').forEach(function (el) { el.disabled = !isCheckin; });
                const emailBox = document.getElementById('checkin_email_guest');
                if (emailBox) emailBox.checked = true;
            }

            if (safeMode === 'noshow') {
                actionInput.value = 'noshow';
                statusInput.value = '';
                titleEl.innerHTML = '<i class="fas fa-user-slash"></i> Mark Booking as No-Show';
                subtitleEl.textContent = 'Use this when a confirmed guest did not arrive after check-in date.';
                modeBadgeEl.textContent = 'No-Show Workflow';
                modeBadgeEl.classList.add('is-danger');
                modeHintEl.textContent = 'This restores room availability and logs a no-show audit event.';
                contextEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' +
                    (isLateCheckIn ? ('Check-in is overdue by ' + overdueDays + ' day(s).') : 'No-show requires the check-in date to have passed.');
                prerequisitesEl.style.display = 'none';

                const canNoShow = statusOk && isLateCheckIn;
                submitBtn.disabled = !canNoShow;
                submitBtn.classList.remove('btn-primary');
                submitBtn.classList.add('btn-danger');
                submitBtn.innerHTML = '<i class="fas fa-user-slash"></i> Mark No-Show';
                if (formEl) formEl.dataset.prereqOk = canNoShow ? '1' : '0';

                if (!canNoShow) {
                    const issues = [];
                    if (!statusOk) issues.push('booking is not confirmed');
                    if (!isLateCheckIn) issues.push('check-in date has not passed');
                    errorEl.textContent = 'Cannot mark no-show because ' + issues.join(' and ') + '.';
                    errorEl.style.display = 'block';
                }
                return;
            }

            actionInput.value = 'update_status';
            statusInput.value = 'checked-in';
            titleEl.innerHTML = '<i class="fas fa-sign-in-alt"></i> ' + (isLateCheckIn ? 'Late Check-In' : 'Check In Guest');
            subtitleEl.textContent = isLateCheckIn ?
                'The booked arrival date has passed. Say what happened before checking in.' :
                'Verify prerequisites and complete guest arrival.';
            modeBadgeEl.textContent = isLateCheckIn ? 'Late Check-In' : 'Standard Check-In';
            modeBadgeEl.classList.remove('is-danger');
            modeHintEl.textContent = 'Check-in updates booking and occupancy immediately.';
            contextEl.innerHTML = isLateCheckIn ?
                ('<i class="fas fa-clock"></i> Check-in is overdue by ' + overdueDays + ' day(s).' + (stayEnded ? ' The booked stay has already ended.' : '')) :
                '<i class="fas fa-calendar-check"></i> On-time check-in workflow.';
            prerequisitesEl.style.display = 'block';

            const canCheckIn = paymentOk && statusOk && dateReached; // roomOk is advisory only
            if (formEl) formEl.dataset.prereqOk = canCheckIn ? '1' : '0';
            submitBtn.disabled = !canCheckIn || showLate; // a late check-in waits for a choice
            submitBtn.classList.remove('btn-danger');
            submitBtn.classList.add('btn-primary');
            submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> ' + (isLateCheckIn ? 'Confirm Late Check-In' : 'Check In');

            if (!canCheckIn) {
                const issues = [];
                if (!paymentOk) issues.push('no payment recorded');
                if (!statusOk) issues.push('booking not confirmed');
                if (!dateReached) issues.push('check-in date not reached');
                errorEl.textContent = 'Cannot check in because ' + issues.join(', ') + '.';
                errorEl.style.display = 'block';
            } else if (!roomOk) {
                errorEl.textContent = 'Note: No specific room assigned — booking will check in without an individual room number.';
                errorEl.style.display = 'block';
                errorEl.style.color = '#856404';
            }
        }

        function closeCheckInModal() {
            const modal = document.getElementById('checkInModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('checkInForm').reset();
            resetCheckInModalState();
            bkDeepCancel(); // opened from booking-details (?action=checkin): go back to the booking
        }

        // Cancel Booking Modal Functions
        // Group booking lookup (live rooms booked together with this one) for the cancel / delete dialogs.
        function bkFetchGroupInfo(bookingId) {
            const fd = new FormData();
            fd.append('action', 'booking_group_info');
            fd.append('booking_id', bookingId);
            return postBookingAction(fd, 'Could not look up the group booking.')
                .then(data => ({ count: Number(data.count) || 0, members: Array.isArray(data.members) ? data.members : [] }))
                .catch(() => ({ count: 0, members: [] }));
        }

        // Shows / hides the "Whole group | Only this room" choice. Radios are disabled while hidden so no
        // scope is posted for an ordinary booking (the server then does today's single-room action).
        function bkApplyGroupChoice(boxId, labelId, refsId, info) {
            const box = document.getElementById(boxId);
            const radios = box.querySelectorAll('input[name="scope"]');
            const isGroup = info.count > 1;
            box.style.display = isGroup ? '' : 'none';
            radios.forEach(r => { r.disabled = !isGroup; r.checked = r.value === 'group'; });
            if (isGroup) {
                document.getElementById(labelId).textContent = 'Whole group (' + info.count + ' rooms)';
                document.getElementById(refsId).textContent = info.members.map(m => m.reference + (m.room ? ' (' + m.room + ')' : '')).join(', ');
            }
        }

        // "Email the guest" (owner 2026-11-12): ticked exactly when cancelling would email the guest (a confirmed
        // booking, or a group with a confirmed room); otherwise the box is replaced by a plain note.
        function bkApplyCancelEmailChoice(wouldEmail) {
            const box = document.getElementById('cancel_email_guest');
            const note = document.getElementById('cancel_email_unconfirmed');
            if (!box) return;
            box.checked = !!wouldEmail;
            box.disabled = !wouldEmail;
            box.closest('label').style.display = wouldEmail ? 'flex' : 'none';
            if (box.closest('label').nextElementSibling) box.closest('label').nextElementSibling.style.display = wouldEmail ? 'block' : 'none';
            if (note) note.style.display = wouldEmail ? 'none' : 'block';
        }

        function openCancelBookingModal(bookingId, bookingReference, guestName, bookingStatus) {
            const modal = document.getElementById('cancelBookingModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('cancel_booking_id').value = bookingId;
            document.getElementById('cancel_booking_ref').value = bookingReference;
            document.getElementById('cancel_guest_name').value = guestName;
            document.getElementById('cancellation_reason').value = '';
            const cancelStatus = String(bookingStatus || '');
            bkApplyCancelEmailChoice(cancelStatus === '' || cancelStatus === 'confirmed');
            bkApplyGroupChoice('cancel_group_box', 'cancel_group_label', 'cancel_group_refs', { count: 0, members: [] });
            const submitBtn = document.querySelector('#cancelBookingForm button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true; // until the group lookup answers, so the choice is never skipped
            bkFetchGroupInfo(bookingId).then(info => {
                if (String(document.getElementById('cancel_booking_id').value) !== String(bookingId)) return;
                bkApplyGroupChoice('cancel_group_box', 'cancel_group_label', 'cancel_group_refs', info);
                if (cancelStatus !== '' && cancelStatus !== 'confirmed' && info && Array.isArray(info.members)
                    && info.members.some(m => String(m.status) === 'confirmed')) {
                    bkApplyCancelEmailChoice(true); // a confirmed room in the group is emailed when the whole group goes
                }
                if (submitBtn) submitBtn.disabled = false;
            });
        }

        function closeCancelBookingModal() {
            const modal = document.getElementById('cancelBookingModal');
            setBookingPageModalOpen(modal, false);
            document.getElementById('cancelBookingForm').reset();
        }

        // Form submission for new modals
        document.getElementById('makeTentativeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Add note if provided
            const note = document.getElementById('tentative_note').value;
            if (note) {
                formData.append('note', note);
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            showLoadingOverlay('Making booking tentative...');

            postBookingAction(formData, 'Error making booking tentative')
                .then(data => reloadWithBookingActionMessage(data, 'Booking moved to tentative successfully.'))
                .catch(error => {
                    hideLoadingOverlay();
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(error.message || 'Error making booking tentative', 'error');
                });
        });

        document.getElementById('checkInForm').addEventListener('change', function(e) {
            if (e.target && e.target.name === 'late_arrival') {
                applyCheckInLateChoice();
                const errorEl = document.getElementById('checkin_error_message');
                if (errorEl && errorEl.dataset.lateError === '1') {
                    errorEl.style.display = 'none';
                    errorEl.dataset.lateError = '';
                }
            }
        });

        // Every path ends with the dialog usable again: the button and the busy overlay are restored on any
        // error (server message, non-JSON answer, timeout, network), and a second click while a request is
        // running does nothing instead of sending it twice.
        document.getElementById('checkInForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            if (form.dataset.busy === '1') return;
            const mode = document.getElementById('checkin_modal_mode').value;
            const errorEl = document.getElementById('checkin_error_message');
            const lateBox = document.getElementById('checkin_late_choices');
            if (mode !== 'noshow' && lateBox && !lateBox.hidden && !form.querySelector('input[name="late_arrival"]:checked')) {
                errorEl.textContent = 'Choose what happened (arrived on the booked date, arriving today' + (document.getElementById('late_opt_ended_wrap').hidden ? '' : ', or already stayed and left') + ') before checking in.';
                errorEl.style.color = '';
                errorEl.style.display = 'block';
                errorEl.dataset.lateError = '1';
                return;
            }
            const formData = new FormData(this); // checkin_note is posted by its own field
            const picked = form.querySelector('input[name="late_arrival"]:checked');
            const isEnded = mode !== 'noshow' && !!picked && picked.value === 'ended';
            const submitBtn = this.querySelector('button[type="submit"]');
            const busyText = mode === 'noshow' ? 'Marking as No-Show...' : (isEnded ? 'Recording the completed stay...' : 'Checking in guest...');
            const failText = mode === 'noshow' ? 'Error marking booking as no-show' : 'Error checking in guest';
            const okText = mode === 'noshow' ? 'Booking marked as no-show successfully.' : (isEnded ? 'Completed stay recorded.' : 'Guest checked in successfully.');
            const setBusy = function(on) {
                form.dataset.busy = on ? '1' : '';
                if (submitBtn) setButtonLoading(submitBtn, on);
                setModalActionLoading(form, on, busyText);
            };
            const showError = function(msg) {
                errorEl.textContent = msg;
                errorEl.style.color = '';
                errorEl.style.display = 'block';
                Alert.show(msg, 'error');
            };

            const send = async function() {
                setBusy(true);
                let data;
                try {
                    // A check-in may send the guest an email: give the server longer than the 30 s default.
                    data = await postBookingAction(formData, failText, 90000);
                } catch (error) {
                    setBusy(false);
                    const d = (error && error.data) || {};
                    if (d.needs_confirm_room && !formData.has('confirm_checkin_room_not_ready')) {
                        const anyway = await confirmAdminAction({
                            title: 'Room not marked clean',
                            message: d.message,
                            confirmText: 'Check in anyway',
                            icon: 'fa-broom'
                        });
                        if (anyway) {
                            formData.append('confirm_checkin_room_not_ready', '1');
                            return send();
                        }
                        return;
                    }
                    if (d.needs_confirm_balance && !formData.has('confirm_checkout_with_balance')) {
                        const withBalance = await confirmAdminAction({
                            title: 'Record the stay with a balance?',
                            message: d.message,
                            details: ['The stay is recorded as checked in and out; the balance stays owed and is recorded in the booking timeline.'],
                            confirmText: 'Record with balance',
                            icon: 'fa-right-from-bracket'
                        });
                        if (withBalance) {
                            formData.append('confirm_checkout_with_balance', '1');
                            return send();
                        }
                        return;
                    }
                    const msg = String((error && error.message) || failText);
                    showError(msg.indexOf('timed out') !== -1
                        ? 'The server took too long to answer. The check-in may still have gone through: reload the page and check the booking before trying again.'
                        : msg);
                    return;
                }
                closeCheckInModalSilently();
                showLoadingOverlay('Updating the list...');
                reloadWithBookingActionMessage(data, okText);
            };
            send();
        });

        // Success: the page reloads; hide the dialog first so it never sits on screen "busy".
        function closeCheckInModalSilently() {
            const modal = document.getElementById('checkInModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        document.getElementById('cancelBookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            setModalActionLoading(this, true, 'Cancelling booking...');

            postBookingAction(formData, 'Error cancelling booking')
                .then(data => reloadWithBookingActionMessage(data, 'Booking cancelled successfully.'))
                .catch(error => {
                    setModalActionLoading(this, false);
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(error.message || 'Error cancelling booking', 'error');
                });
        });

        // Admin Banner Modals Functions
        function openMissedCheckinsModal() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, true);
        }

        function closeMissedCheckinsModal() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, false);
        }

        function toggleMissedCheckins() {
            const modal = document.getElementById('missedCheckinsModal');
            setBookingPageModalOpen(modal, !modal.classList.contains('active'));
        }

        function openMissedCheckinWorkflow(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode = 'checkin', paymentGate = '', lateInfo = null) {
            closeMissedCheckinsModal();
            openCheckInModal(bookingId, bookingReference, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, mode, paymentGate, lateInfo);
        }

        function openMissedCancelWorkflow(bookingId, bookingReference, guestName) {
            closeMissedCheckinsModal();
            openCancelBookingModal(bookingId, bookingReference, guestName);
        }

        function openOverdueCheckoutsModal() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, true);
        }

        function closeOverdueCheckoutsModal() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, false);
        }

        function toggleOverdueCheckouts() {
            const modal = document.getElementById('overdueCheckoutsModal');
            setBookingPageModalOpen(modal, !modal.classList.contains('active'));
        }

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('.js-bookings-stat-insight');
            if (!trigger) {
                return;
            }
            if (event.defaultPrevented) {
                return;
            }
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openBookingsStatsInsightModal(String(trigger.dataset.statsCard || ''), trigger);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const keyboardTarget = event.target instanceof Element ? event.target.closest('.js-bookings-stat-insight') : null;
            if (!keyboardTarget) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            openBookingsStatsInsightModal(String(keyboardTarget.dataset.statsCard || ''), keyboardTarget);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') {
                return;
            }

            const statsModal = document.getElementById('bookingsStatsInsightModal');
            if (statsModal && statsModal.classList.contains('active')) {
                closeBookingsStatsInsightModal();
                return;
            }

            const missedModal = document.getElementById('missedCheckinsModal');
            if (missedModal && missedModal.classList.contains('active')) {
                closeMissedCheckinsModal();
                return;
            }

            const overdueModal = document.getElementById('overdueCheckoutsModal');
            if (overdueModal && overdueModal.classList.contains('active')) {
                closeOverdueCheckoutsModal();
            }
        });

        // ── Booked rate x new nights (read-only quote from the server: the same reprice the save uses) ──
        // Resolves to the preview object, or null when there is nothing to show; never blocks the action.
        function bkStayRateQuote(bookingId, checkIn, checkOut) {
            if (!bookingId || !checkOut) return Promise.resolve(null);
            const fd = new FormData();
            fd.append('action', 'stay_price_preview');
            fd.append('booking_id', bookingId);
            if (checkIn) fd.append('new_check_in', checkIn);
            fd.append('new_check_out', checkOut);
            return postBookingAction(fd, 'Could not work out the new total.', 15000)
                .then(d => (d && d.ok ? d : null))
                .catch(() => null);
        }

        function bkRenderStayRate(el, q) {
            if (!el) return;
            el.textContent = '';
            if (!q) {
                el.hidden = true;
                return;
            }
            const rows = [
                ['Booked now: ', q.current_text],
                ['After the change: ', q.new_text],
                ['Difference: ', q.delta_text]
            ];
            if (q.plan_note) rows.push(['Discount: ', q.plan_note]); // RP-3: a discount the new dates no longer qualify for
            rows.forEach(r => {
                const line = document.createElement('div');
                const b = document.createElement('strong');
                b.textContent = r[0];
                line.appendChild(b);
                line.appendChild(document.createTextNode(String(r[1] || '')));
                el.appendChild(line);
            });
            const help = document.createElement('small');
            help.style.color = '#666';
            help.textContent = 'Rate a night \u00d7 nights, at the price this booking was made at. Today\'s room price does not change it.';
            el.appendChild(help);
            el.hidden = false;
        }

        function bkStayRateDetails(q) {
            if (!q) return [];
            return q.plan_note
                ? ['Room: ' + q.new_text, 'Difference: ' + q.delta_text, q.plan_note]
                : ['Room at the booked rate: ' + q.new_text, 'Difference: ' + q.delta_text];
        }

        function bkRefreshStayRate(previewId, bookingId, checkIn, checkOut) {
            const el = document.getElementById(previewId);
            if (!el) return Promise.resolve(null);
            const key = [bookingId, checkIn || '', checkOut || ''].join('|');
            el.dataset.quoteKey = key;
            return bkStayRateQuote(bookingId, checkIn, checkOut).then(q => {
                if (el.dataset.quoteKey === key) bkRenderStayRate(el, q); // ignore an older answer that arrives late
                return q;
            });
        }

        // Used by the Quick modify modal in a later <script> block (the SPA wraps each block on its own).
        window.bkStayRateQuote = bkStayRateQuote;
        window.bkRenderStayRate = bkRenderStayRate;
        window.bkStayRateDetails = bkStayRateDetails;
        window.bkRefreshStayRate = bkRefreshStayRate;

        document.getElementById('acd_new_checkout')?.addEventListener('change', function() {
            bkRefreshStayRate('acd_rate_preview', document.getElementById('acd_booking_id')?.value, '', this.value);
        });

        // ── Add / take off nights (owner 2026-11-13) ─────────────────────────
        // Step 1: a -/+ stepper (no amount field); every change asks the server for the quote (stay_nights_quote, the
        // same helper the save uses). Step 2: the review card (nights x booked rate, new total, new balance, money
        // back), a required reason, and for money going back the refund / credit note choice with the amount retyped.
        // Confirm posts the reviewed figures; the server re-quotes under the lock and refuses any difference.
        const snState = { id: 0, ref: '', guest: '', checkout: '', delta: 1, quote: null, seq: 0, busy: false };

        function snEl(id) { return document.getElementById(id); }
        // Show / hide with inline display (page CSS gives .btn and .alert a display that beats the hidden attribute).
        function snShow(el, on) { if (el) { el.hidden = !on; el.style.display = on ? '' : 'none'; } }
        function snMoney(n) { return fmt(n); }
        function snDeltaLabel(d) {
            const n = Math.abs(d);
            return (d > 0 ? '+' : '−') + n + (n === 1 ? ' night' : ' nights');
        }
        function snShowError(msg) {
            const el = snEl('sn_error');
            if (!el) return;
            el.textContent = msg || '';
            snShow(el, !!msg);
        }
        function snSetStep(step) {
            snEl('sn_step1').hidden = step !== 1;
            snEl('sn_step2').hidden = step !== 2;
            snShow(snEl('sn_review_btn'), step === 1);
            snShow(snEl('sn_back'), step === 2);
            snShow(snEl('sn_confirm_btn'), step === 2);
        }
        function snLine(parent, label, value, strong) {
            const row = document.createElement('div');
            const b = document.createElement('strong');
            b.textContent = label;
            row.appendChild(b);
            const v = document.createElement(strong ? 'strong' : 'span');
            v.textContent = ' ' + String(value == null ? '' : value);
            row.appendChild(v);
            parent.appendChild(row);
        }
        function snRenderLive(q, err) {
            const el = snEl('sn_live');
            el.textContent = '';
            snEl('sn_delta_text').textContent = snDeltaLabel(snState.delta);
            if (err) {
                const p = document.createElement('div');
                p.className = 'rh-text-danger';
                p.textContent = err;
                el.appendChild(p);
                snEl('sn_review_btn').disabled = true;
                return;
            }
            if (!q) {
                el.textContent = 'Working out the new total...';
                snEl('sn_review_btn').disabled = true;
                return;
            }
            snLine(el, 'New check-out:', q.new_check_out_fmt + ' (' + q.new_nights + (q.new_nights === 1 ? ' night' : ' nights') + ' in total)');
            snLine(el, 'Room after the change:', q.new_text || snMoney(q.new_total));
            snLine(el, 'Difference:', (q.total_delta >= 0 ? '+' : '−') + snMoney(Math.abs(q.total_delta)));
            if (q.delta > 0 && q.available === false) {
                const p = document.createElement('div');
                p.className = 'rh-text-danger';
                p.textContent = 'Not available: ' + (q.availability_error || 'the room is taken on the added night(s).');
                el.appendChild(p);
            }
            snEl('sn_review_btn').disabled = (q.delta > 0 && q.available === false);
        }
        function snFetchQuote() {
            const seq = ++snState.seq;
            snState.quote = null;
            snRenderLive(null, '');
            const fd = new FormData();
            fd.append('action', 'stay_nights_quote');
            fd.append('booking_id', String(snState.id));
            fd.append('delta', String(snState.delta));
            return postBookingAction(fd, 'Could not work out the new total.', 20000)
                .then(d => {
                    if (seq !== snState.seq) return;
                    snState.quote = d.quote || null;
                    snRenderLive(snState.quote, snState.quote ? '' : 'Could not work out the new total.');
                })
                .catch(err => {
                    if (seq !== snState.seq) return;
                    snRenderLive(null, err.message || 'Could not work out the new total.');
                });
        }
        let snTimer = null;
        function snStep(by) {
            let d = snState.delta + by;
            if (d === 0) d += by; // there is no "0 nights" change
            if (d > 60 || d < -60) return;
            snState.delta = d;
            snShowError('');
            snEl('sn_delta_text').textContent = snDeltaLabel(d);
            clearTimeout(snTimer);
            snTimer = setTimeout(snFetchQuote, 250);
        }
        function snRenderReview(q) {
            const el = snEl('sn_review');
            el.textContent = '';
            const n = Math.abs(q.delta);
            const nTxt = n + (n === 1 ? ' night' : ' nights');
            if (q.plan_note) {
                // A discount the new stay no longer qualifies for is removed: every remaining night is repriced, so
                // "N nights x rate" would be wrong. Show the signed change to the bill and why.
                const d = parseFloat(q.total_delta || 0);
                const change = d > 0.01 ? 'the bill goes up by ' + snMoney(d)
                    : (d < -0.01 ? 'the bill goes down by ' + snMoney(Math.abs(d)) : 'the bill does not change');
                snLine(el, q.delta > 0 ? 'Adding:' : 'Taking off:', nTxt + ': ' + change + ' because the discount no longer applies to the new stay.', true);
            } else {
                snLine(el, q.delta > 0 ? 'Adding:' : 'Taking off:', nTxt + ' at ' + snMoney(q.per_night) + ' a night (booked rate) = ' + snMoney(Math.abs(q.total_delta)), true);
            }
            snLine(el, 'Check-out:', q.old_check_out_fmt + ' → ' + q.new_check_out_fmt);
            snLine(el, 'Nights:', q.old_nights + ' → ' + q.new_nights);
            if (q.current_text) snLine(el, 'Room now:', q.current_text);
            if (q.new_text) snLine(el, 'Room after:', q.new_text);
            snLine(el, 'Room total:', snMoney(q.old_total) + ' → ' + snMoney(q.new_total), true);
            if (q.folio > 0.01) snLine(el, 'Extras on the bill (unchanged):', snMoney(q.folio));
            snLine(el, 'Paid so far:', snMoney(q.paid));
            snLine(el, 'Balance owed:', snMoney(q.old_due) + ' → ' + snMoney(q.new_due), true);
            if (q.plan_note) snLine(el, 'Discount:', q.plan_note);
            if (q.pay_later) snLine(el, 'Pay-later booking:', 'the balance stays owed under the agreed arrangement; the guest is not chased.');
            if (q.delta > 0) snLine(el, 'Room availability:', q.available === false ? 'NOT available' : 'checked - the room is free on the added night(s).');

            const mb = snEl('sn_moneyback');
            const back = parseFloat(q.money_back || 0);
            mb.hidden = !(back > 0.01);
            snEl('sn_typed').value = '';
            snEl('sn_mode_refund').checked = false;
            snEl('sn_mode_credit').checked = false;
            if (back > 0.01) {
                snEl('sn_moneyback_title').textContent = snMoney(back) + ' was paid for the nights taken off and goes back to the guest.';
                snEl('sn_mode_refund').disabled = !q.can_refund;
                snShow(snEl('sn_refund_noperm'), !q.can_refund);
                snEl('sn_typed').placeholder = 'Type ' + back.toFixed(2);
            }
        }
        function snGoReview() {
            const q = snState.quote;
            if (!q) return;
            snShowError('');
            snRenderReview(q);
            snSetStep(2);
            snEl('sn_reason').focus();
        }
        function snConfirm() {
            const q = snState.quote;
            if (!q || snState.busy) return;
            const reason = String(snEl('sn_reason').value || '').trim();
            if (reason.length < 3) {
                snShowError('Say why the nights are changing (the reason is required).');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'stay_nights_apply');
            fd.append('booking_id', String(snState.id));
            fd.append('delta', String(q.delta));
            fd.append('expected_new_total', String(q.new_total));
            fd.append('expected_total_delta', String(q.total_delta));
            fd.append('reason', reason);
            const back = parseFloat(q.money_back || 0);
            if (back > 0.01) {
                const mode = document.querySelector('#extendStayForm input[name="sn_mode"]:checked');
                if (!mode) {
                    snShowError('Choose how the ' + snMoney(back) + ' goes back: refund or credit note.');
                    return;
                }
                const typed = parseFloat(String(snEl('sn_typed').value || '').replace(/[,\s]/g, ''));
                if (!(Math.abs(typed - back) <= 0.01)) {
                    snShowError('Type the amount going back exactly as shown (' + back.toFixed(2) + ') to confirm.');
                    return;
                }
                fd.append('money_back_mode', mode.value);
                fd.append('money_back_typed', String(typed));
            }
            snState.busy = true;
            const btn = snEl('sn_confirm_btn');
            setButtonLoading(btn, true);
            postBookingAction(fd, 'The change was not saved.', 45000)
                .then(data => {
                    snState.busy = false;
                    closeExtendStayModal();
                    reloadWithBookingActionMessage(data, 'Stay updated.');
                })
                .catch(err => {
                    snState.busy = false;
                    try { setButtonLoading(btn, false); } catch (_) {}
                    snShowError(err.message || 'The change was not saved.');
                });
        }

        function openExtendStayModal(bookingId, bookingRef, currentCheckout, guestName) {
            const modal = document.getElementById('extendStayModal');
            if (!modal) return;
            snState.id = parseInt(bookingId, 10) || 0;
            snState.ref = String(bookingRef || '');
            snState.guest = String(guestName || '');
            snState.checkout = String(currentCheckout || '');
            snState.quote = null;
            snState.busy = false;
            document.getElementById('extend_booking_id').value = bookingId;
            document.getElementById('extend_booking_ref').value = bookingRef;
            document.getElementById('extend_guest_name').value = guestName;
            document.getElementById('extend_current_checkout').value = currentCheckout;
            snEl('sn_who').textContent = snState.ref + (snState.guest ? ' · ' + snState.guest : '') + ' · booked check-out ' + snState.checkout;
            // Default: one more night; an overdue stay (check-out already passed) defaults to leaving tomorrow.
            const now = new Date();
            const todayUtc = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
            const p = snState.checkout.split('-');
            const coUtc = Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
            const daysToTomorrow = Math.round((todayUtc - coUtc) / 86400000) + 1;
            snState.delta = Math.max(1, isFinite(daysToTomorrow) ? daysToTomorrow : 1);
            snEl('sn_reason').value = '';
            snShowError('');
            snSetStep(1);
            setBookingPageModalOpen(modal, true);
            snFetchQuote();
        }

        function closeExtendStayModal() {
            const modal = document.getElementById('extendStayModal');
            setBookingPageModalOpen(modal, false);
        }

        snEl('sn_minus')?.addEventListener('click', function() { snStep(-1); });
        snEl('sn_plus')?.addEventListener('click', function() { snStep(1); });
        snEl('sn_review_btn')?.addEventListener('click', snGoReview);
        snEl('sn_back')?.addEventListener('click', function() { snShowError(''); snSetStep(1); });
        snEl('sn_confirm_btn')?.addEventListener('click', snConfirm);

        // ── Admin Change Checkout Date Modal ──────────────────────────────────
        function openAdminChangeDateModal(bookingId, bookingRef, currentCheckout, guestName) {
            const modal = document.getElementById('adminChangeDateModal');
            if (!modal) return;
            document.getElementById('acd_booking_id').value = bookingId;
            document.getElementById('acd_booking_ref').value = bookingRef;
            document.getElementById('acd_guest_name').value = guestName;
            document.getElementById('acd_current_checkout').value = currentCheckout;
            document.getElementById('acd_new_checkout').value = currentCheckout;
            document.getElementById('acd_new_checkout').max = '';
            document.getElementById('acd_reason').value = '';
            bkRenderStayRate(document.getElementById('acd_rate_preview'), null);
            setBookingPageModalOpen(modal, true);
        }

        function closeAdminChangeDateModal() {
            const modal = document.getElementById('adminChangeDateModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // Explicit window exports: this page loads via the admin SPA, which
        // re-executes inline scripts inside an IIFE. Inline onclick attributes
        // (the overdue-checkout modal's Checkout Now / Extend Stay / Change Date
        // buttons) resolve against window, so bind these handlers there directly
        // rather than relying on the SPA's auto-export heuristic.
        window.openOverdueCheckoutsModal = openOverdueCheckoutsModal;
        window.closeOverdueCheckoutsModal = closeOverdueCheckoutsModal;
        window.openExtendStayModal = openExtendStayModal;
        window.closeExtendStayModal = closeExtendStayModal;
        window.openAdminChangeDateModal = openAdminChangeDateModal;
        window.closeAdminChangeDateModal = closeAdminChangeDateModal;

        document.getElementById('adminChangeDateForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const bookingRef = document.getElementById('acd_booking_ref')?.value || '';
            const currentCheckout = document.getElementById('acd_current_checkout')?.value || '';
            const newCheckout = document.getElementById('acd_new_checkout')?.value || '';
            const submitBtn = this.querySelector('button[type="submit"]');
            const form = this;
            bkStayRateQuote(formData.get('booking_id'), '', newCheckout).then(rateQ => confirmAdminAction({
                    title: 'Confirm checkout date change',
                    message: 'Update checkout date for booking ' + (bookingRef || 'this booking') + '?',
                    details: [
                        'Current: ' + (currentCheckout || '—'),
                        'New: ' + (newCheckout || '—')
                    ].concat(bkStayRateDetails(rateQ)),
                    confirmText: 'Save Change',
                    icon: 'fa-calendar-pen',
                    tone: 'warning'
                }))
                .then(confirmed => {
                    if (!confirmed) {
                        return;
                    }

                    if (submitBtn) setButtonLoading(submitBtn, true);
                    setModalActionLoading(form, true, 'Updating checkout date...');

                    return postBookingAction(formData, 'Failed to update checkout date.')
                        .then(data => {
                            closeAdminChangeDateModal();
                            reloadWithBookingActionMessage(data, 'Checkout date updated successfully.');
                        })
                        .catch(error => {
                            setModalActionLoading(form, false);
                            if (submitBtn) setButtonLoading(submitBtn, false);
                            Alert.show(error.message || 'An error occurred. Please try again.', 'error');
                        });
                });
        });

        // The nights modal never submits as a form: Review / Confirm are buttons (Enter must not save money).
        document.getElementById('extendStayForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
        });

        // ── Manual Consolidation Modal ────────────────────────────────────────
        function fmtMWK(n) {
            return _currencySymbol + ' ' + parseFloat(n || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function badgePay(status) {
            const map = {
                paid: '#28a745',
                partial: '#fd7e14',
                unpaid: '#dc3545'
            };
            return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:' + (map[status] || '#6c757d') + ';color:#fff;">' + (status || 'unknown').toUpperCase() + '</span>';
        }

        async function openConsolidationModal(bookingId, reference) {
            const modal = document.getElementById('consolidationModal');
            if (!modal) return;
            document.getElementById('con_body').innerHTML =
                '<div style="padding:20px;text-align:center;color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading financial summary...</div>';
            setBookingPageModalOpen(modal, true);

            const fd = new FormData();
            fd.append('action', 'consolidation_fetch');
            fd.append('id', bookingId);

            try {
                const data = await postBookingAction(fd, 'Failed to load financial data');
                const b = data.booking;
                const charges = data.charges || [];
                const payments = data.payments || [];
                const refunds = data.refunds || [];

                const roomLabel = [b.room_type, b.room_name || (b.room_number ? '#' + b.room_number : '')].filter(Boolean).join(' \u2014 ');

                // Charges table
                let chargesHtml = '';
                if (charges.length) {
                    chargesHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;"><thead><tr style="background:#f7f3ee;"><th style="padding:5px 8px;text-align:left;font-weight:600;">Description</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Qty</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Unit</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Total</th></tr></thead><tbody>';
                    charges.forEach(c => {
                        chargesHtml += '<tr style="border-bottom:1px solid #eee;"><td style="padding:5px 8px;">' + escHtml(c.description) + '</td><td style="padding:5px 8px;text-align:right;">' + parseFloat(c.quantity) + '</td><td style="padding:5px 8px;text-align:right;">' + fmtMWK(c.unit_price) + '</td><td style="padding:5px 8px;text-align:right;font-weight:600;">' + fmtMWK(c.line_total) + '</td></tr>';
                    });
                    chargesHtml += '</tbody></table>';
                } else {
                    chargesHtml = '<p style="color:#999;font-size:12px;margin:6px 0 0;">No additional folio charges.</p>';
                }

                // Payments table
                let paysHtml = '';
                if (payments.length) {
                    paysHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;"><thead><tr style="background:#f7f3ee;"><th style="padding:5px 8px;text-align:left;font-weight:600;">Reference</th><th style="padding:5px 8px;text-align:left;font-weight:600;">Date</th><th style="padding:5px 8px;text-align:left;font-weight:600;">Method</th><th style="padding:5px 8px;text-align:right;font-weight:600;">Amount</th></tr></thead><tbody>';
                    payments.forEach(p => {
                        paysHtml += '<tr style="border-bottom:1px solid #eee;"><td style="padding:5px 8px;">' + escHtml(p.payment_reference) + (p.notes ? '<br><small style="color:#888;">' + escHtml(p.notes) + '</small>' : '') + '</td><td style="padding:5px 8px;">' + escHtml(p.payment_date) + '</td><td style="padding:5px 8px;">' + escHtml(p.payment_method.replace(/_/g, ' ')) + '</td><td style="padding:5px 8px;text-align:right;font-weight:600;color:#28a745;">' + fmtMWK(p.total_amount) + '</td></tr>';
                    });
                    paysHtml += '</tbody></table>';
                    refunds.forEach(r => {
                        paysHtml += '<p style="font-size:11px;color:#dc3545;margin:4px 0 0;"><i class="fas fa-rotate-left"></i> Refund ' + escHtml(r.payment_reference) + ' \u2014 ' + fmtMWK(r.total_amount) + ' (' + escHtml(r.payment_date) + ')' + (r.refund_reason ? ': ' + escHtml(r.refund_reason) : '') + '</p>';
                    });
                } else {
                    paysHtml = '<p style="color:#999;font-size:12px;margin:6px 0 0;">No payments recorded yet.</p>';
                }

                // Summary bar
                const summaryBar =
                    '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">' +
                    '<div style="background:#f7f3ee;border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Total Charged</div><div style="font-size:18px;font-weight:700;color:#2a2723;margin-top:2px;">' + fmtMWK(b.total_with_vat + b.folio_charges) + '</div></div>' +
                    '<div style="background:#e8f5e9;border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Amount Paid</div><div style="font-size:18px;font-weight:700;color:#28a745;margin-top:2px;">' + fmtMWK(b.amount_paid) + '</div></div>' +
                    '<div style="background:' + (b.amount_due > 0.01 ? '#fff3f3' : '#e8f5e9') + ';border-radius:6px;padding:10px 12px;text-align:center;"><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em;">Balance Due</div><div style="font-size:18px;font-weight:700;color:' + (b.amount_due > 0.01 ? '#dc3545' : '#28a745') + ';margin-top:2px;">' + fmtMWK(b.amount_due) + '</div></div>' +
                    '</div>';

                // Record Payment form (only offered while something is owed; the server refuses it otherwise)
                const conDue = Math.max(0, parseFloat(b.amount_due) || 0);
                const conOwed = conDue > 0.01;
                const paymentForm = !conOwed
                    ? '<p style="margin:0 0 6px;padding:12px 14px;background:#e8f5e9;border-radius:6px;color:#1f5f2a;font-weight:600;"><i class="fas fa-circle-check"></i> Nothing is owed on this booking.</p>' +
                      '<div class="modal-actions" style="border-top:1px solid #eee;padding-top:14px;"><button type="button" class="btn btn-secondary" onclick="closeConsolidationModal()">Close</button></div>'
                    : '<form id="conPaymentForm" method="post" data-no-autofilter>' +
                    '<input type="hidden" name="action" value="consolidation_record">' +
                    '<input type="hidden" name="id" value="' + b.id + '">' +
                    // Company pays later: who paid, nothing preselected (company money is recorded as the company's).
                    (b.on_account
                        ? '<fieldset class="rh-choices" id="con_paid_by"><legend>Paid by * <small>' + escHtml(b.company_name) + ' owes ' + fmtMWK(b.company_due) + ' &middot; the guest owes ' + fmtMWK(b.guest_due) + '</small></legend>' +
                          '<label class="rh-choice"><input type="radio" name="paid_by" value="guest" required> <span>The guest</span></label>' +
                          '<label class="rh-choice"><input type="radio" name="paid_by" value="company" required> <span>' + escHtml(b.company_name) + ' (company payment)</span></label></fieldset>'
                        : '') +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Amount (' + _currencySymbol + ') <span style="color:#dc3545;">*</span></label><input type="number" name="amount" id="con_amount" class="form-control" min="0.01" step="0.01" value="' + conDue.toFixed(2) + '" required style="border-color:#8F6A35;"><small style="color:#6b6258;display:block;margin-top:4px;">Balance due ' + fmtMWK(conDue) + '</small>' +
                    '<div id="con_over_wrap" style="display:none;margin-top:6px;"><div id="con_over_warn" style="color:#8a4b00;font-size:12px;margin-bottom:4px;"></div><label style="display:flex;gap:6px;align-items:flex-start;font-size:12px;color:#8a4b00;cursor:pointer;"><input type="checkbox" name="confirm_overpay" value="1" id="con_over_cb" style="margin-top:2px;"> <span>The guest paid more than owed</span></label></div></div>' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment method <span style="color:#dc3545;">*</span></label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="credit_card">Credit Card</option><option value="debit_card">Debit Card</option><option value="mobile_money">Mobile Money</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div>' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment type</label><select name="payment_type" class="form-control"><option value="partial_payment">Partial payment</option><option value="full_payment">Full / final payment</option></select></div>' +
                    '<div class="form-group" style="margin:0;"><label style="font-weight:600;">Payment date</label><input type="date" name="payment_date" class="form-control" value="' + rhYmd(new Date()) + '"></div>' +
                    '</div>' +
                    '<div class="form-group" style="margin-bottom:10px;"><label>General notes</label><input type="text" name="notes" class="form-control" placeholder="e.g. Cash collected at front desk" maxlength="200"></div>' +
                    '<div class="form-group" style="margin-bottom:14px;"><label style="font-weight:600;"><i class="fas fa-calculator" style="color:#8F6A35;"></i> Accountant notes</label><input type="text" name="accountant_notes" class="form-control" placeholder="e.g. Reconciled against invoice RFD-20260514, balance cleared" maxlength="300" style="border-color:#8F6A35;"><small style="color:#888;display:block;margin-top:4px;">Stored as &ldquo;ACCT: &hellip;&rdquo; prefix in payment notes &mdash; visible on invoices and ledger reports.</small></div>' +
                    '<div class="modal-actions" style="border-top:1px solid #eee;padding-top:14px;">' +
                    '<button type="button" class="btn btn-secondary" onclick="closeConsolidationModal()">Close</button>' +
                    '<button type="submit" id="con_submit_btn" class="btn btn-primary"><i class="fas fa-scale-balanced"></i> Record Payment</button>' +
                    '</div></form>';

                document.getElementById('con_body').innerHTML =
                    '<div style="padding:16px 20px 0;">' +
                    '<div style="background:#f7f3ee;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:13px;line-height:1.7;">' +
                    '<strong>' + escHtml(b.guest_name) + '</strong>' +
                    (roomLabel ? ' &nbsp;|&nbsp; <strong>' + escHtml(roomLabel) + '</strong>' : '') +
                    '<br><span style="color:#666;">Ref: ' + escHtml(b.reference) + '</span>' +
                    ' &nbsp; <span style="color:#666;">' + escHtml(b.check_in) + ' \u2013 ' + escHtml(b.check_out) + ' (' + b.nights + ' nights)</span>' +
                    ' &nbsp; ' + badgePay(b.payment_status) +
                    '</div>' +
                    summaryBar +
                    '<details style="margin-bottom:12px;" open><summary style="font-size:12px;font-weight:600;color:#555;cursor:pointer;padding:4px 0;">Folio Charges</summary>' + chargesHtml + '</details>' +
                    '<details style="margin-bottom:16px;" open><summary style="font-size:12px;font-weight:600;color:#555;cursor:pointer;padding:4px 0;">Payment History</summary>' + paysHtml + '</details>' +
                    '<div style="border-top:2px solid #8F6A35;padding-top:14px;">' + (conOwed ? '<h4 style="margin:0 0 12px;font-size:14px;color:#2a2723;"><i class="fas fa-plus-circle"></i> Record New Payment</h4>' : '') + paymentForm + '</div></div>';

                const conPayForm = document.getElementById('conPaymentForm');
                if (!conPayForm) return; // nothing owed: no payment form
                const conAmt = document.getElementById('con_amount');
                const conIsCompany = function () {
                    const c = conPayForm.querySelector('input[name="paid_by"]:checked');
                    return !!(c && c.value === 'company');
                };
                conAmt.addEventListener('input', function() {
                    const extra = (parseFloat(conAmt.value) || 0) - conDue;
                    // A company payment can never be more than owed (refused on the server): no overpay box for it.
                    const over = extra > 0.01 && !conIsCompany();
                    document.getElementById('con_over_wrap').style.display = over ? '' : 'none';
                    document.getElementById('con_over_warn').textContent = over
                        ? 'That is ' + fmtMWK(extra) + ' more than owed. The extra will be issued to the guest as a credit note.' : '';
                    document.getElementById('con_over_cb').required = over;
                    if (!over) document.getElementById('con_over_cb').checked = false;
                });
                conPayForm.querySelectorAll('input[name="paid_by"]').forEach(function (r) {
                    r.addEventListener('change', function () {
                        // Prefill the share of whoever paid (guest share / company share), never the full balance.
                        const share = parseFloat(r.value === 'company' ? b.company_due : b.guest_due) || 0;
                        if (share > 0.01) conAmt.value = share.toFixed(2);
                        conAmt.dispatchEvent(new Event('input'));
                    });
                });
                conPayForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    // Submit guard: one payment per click, never a second post while the first is in flight
                    if (conPayForm.dataset.submitting === '1') return;
                    conPayForm.dataset.submitting = '1';
                    const fd2 = new FormData(this);
                    const btn = document.getElementById('con_submit_btn');
                    if (btn) setButtonLoading(btn, true);
                    showLoadingOverlay('Recording payment...');
                    try {
                        const result = await postBookingAction(fd2, 'Failed to record payment');
                        hideLoadingOverlay();
                        closeConsolidationModal();
                        Alert.show(result.message + ' Ref: ' + result.payment_reference, 'success');
                        setTimeout(() => window.location.reload(), 2000);
                    } catch (err) {
                        hideLoadingOverlay();
                        conPayForm.dataset.submitting = '';
                        if (btn) setButtonLoading(btn, false);
                        Alert.show(err.message || 'Failed to record payment.', 'error');
                    }
                });

            } catch (err) {
                document.getElementById('con_body').innerHTML =
                    '<div style="padding:20px;color:#dc3545;"><i class="fas fa-circle-exclamation"></i> ' + escHtml(err.message || 'Failed to load data.') + '</div>';
            }
        }

        function closeConsolidationModal() {
            const modal = document.getElementById('consolidationModal');
            if (modal) setBookingPageModalOpen(modal, false);
        }

        // ── Room type change (upgrade / downgrade) ───────────────────────────────
        // Options, quotes and the accounting effect come from the server (room_change_options), so the
        // modal never re-derives prices client-side. Requests go through postBookingAction(), which adds
        // the CSRF token (the old picker posted without it, the server rejected it and the stale-token
        // guard reloaded the page: that was the "modal closes by itself" bug).
        let roomChangeData = null;

        function rcMoney(v) {
            const cur = (roomChangeData && roomChangeData.currency) || 'MWK';
            const n = Number(v || 0);
            return cur + ' ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function rcEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        }

        function openUpgradeRoomModal(bookingId) {
            const modal = document.getElementById('upgradeRoomModal');
            roomChangeData = null;
            document.getElementById('upgrade_booking_id').value = bookingId;
            document.getElementById('rc_summary').textContent = 'Loading booking…';
            document.getElementById('rc_detail').hidden = true;
            document.getElementById('rc_submit').disabled = true;
            document.getElementById('rc_note').value = '';
            const select = document.getElementById('upgrade_new_room');
            select.innerHTML = '<option value="">Loading room types…</option>';
            setBookingPageModalOpen(modal, true);

            const fd = new FormData();
            fd.append('action', 'room_change_options');
            fd.append('booking_id', bookingId);
            postBookingAction(fd, 'Could not load room types.')
                .then(res => {
                    roomChangeData = res.data;
                    const b = roomChangeData.booking;
                    const inHouse = b.status === 'checked-in';
                    document.getElementById('rc_summary').innerHTML =
                        '<strong>' + rcEsc(b.reference) + '</strong> &middot; ' + rcEsc(b.guest_name) + '<br>' +
                        'Now: <strong>' + rcEsc(b.room_name) + '</strong> &middot; ' + rcEsc(b.check_in) + ' to ' + rcEsc(b.check_out) +
                        ' (' + b.nights + ' night' + (b.nights === 1 ? '' : 's') + ', ' + b.guests + ' guest' + (b.guests === 1 ? '' : 's') + ')' +
                        (inHouse ? '<br><em>Guest is in-house: the move applies from tonight (' + rcEsc(b.from) + '); nights already stayed keep the booked rate.</em>' : '');
                    select.innerHTML = '<option value="">-- Choose a room type --</option>';
                    let any = false;
                    roomChangeData.options.forEach(o => {
                        const q = o.quote;
                        const tag = q.direction === 'upgrade' ? 'Upgrade' : (q.direction === 'downgrade' ? 'Downgrade' : 'Same tier');
                        const opt = document.createElement('option');
                        opt.value = o.id;
                        opt.disabled = !o.available;
                        opt.textContent = o.name + ' — ' + tag + (o.available ? '' : ' (' + o.unavailable_reason + ')');
                        select.appendChild(opt);
                        if (o.available) any = true;
                    });
                    if (!any) {
                        document.getElementById('rc_type_hint').textContent = 'No other room type is free for this stay.';
                    }
                })
                .catch(err => {
                    document.getElementById('rc_summary').textContent = err.message || 'Could not load room types.';
                    select.innerHTML = '<option value="">Unavailable</option>';
                });
        }

        function closeUpgradeRoomModal() {
            setBookingPageModalOpen(document.getElementById('upgradeRoomModal'), false);
            roomChangeData = null;
            bkDeepCancel();
        }

        function rcSelectedOption() {
            if (!roomChangeData) return null;
            const id = parseInt(document.getElementById('upgrade_new_room').value, 10);
            return roomChangeData.options.find(o => o.id === id) || null;
        }

        function rcRender() {
            const o = rcSelectedOption();
            const detail = document.getElementById('rc_detail');
            const submit = document.getElementById('rc_submit');
            if (!o || !o.available) {
                detail.hidden = true;
                submit.disabled = true;
                return;
            }
            const q = o.quote;
            const b = roomChangeData.booking;
            const canKeep = !!roomChangeData.can_keep_price;
            const dir = q.direction;
            const perNight = Math.abs(q.new_rack - q.old_rack);
            const nightsTxt = q.affected + ' night' + (q.affected === 1 ? '' : 's');

            const dirEl = document.getElementById('rc_direction');
            dirEl.className = 'rc-direction rc-direction--' + dir;
            dirEl.textContent = dir === 'upgrade'
                ? 'Upgrade: ' + o.name + ' is ' + rcMoney(perNight) + ' a night more than ' + b.room_name + ' (standard rate).'
                : (dir === 'downgrade'
                    ? 'Downgrade: ' + o.name + ' is ' + rcMoney(perNight) + ' a night less than ' + b.room_name + ' (standard rate).'
                    : 'Same tier: ' + o.name + ' has the same standard rate. The bill does not change.');

            const keepWrap = document.getElementById('rc_keep_wrap');
            const keepInput = keepWrap.querySelector('input');
            const chargeInput = document.querySelector('input[name="rc_pricing"][value="charge"]');
            if (dir === 'upgrade') {
                document.getElementById('rc_charge_label').textContent = 'Paid upgrade — charge the difference';
                document.getElementById('rc_charge_hint').textContent = '+' + rcMoney(q.charge_delta) + ' for ' + nightsTxt + ', added on top of the guest\'s booked rate (their discount is kept).';
                document.getElementById('rc_keep_label').textContent = 'Complimentary upgrade — no charge';
                document.getElementById('rc_keep_hint').textContent = 'Bill unchanged. ' + rcMoney(q.rack_value_delta) + ' of upgrade value is recorded as a comp.' + (canKeep ? '' : ' Needs a manager (Edit Booking Financials).');
            } else if (dir === 'downgrade') {
                document.getElementById('rc_charge_label').textContent = 'Reduce the bill to the new room';
                document.getElementById('rc_charge_hint').textContent = rcMoney(q.charge_delta) + ' for ' + nightsTxt + '. Anything already overpaid becomes a credit to refund or carry forward.';
                document.getElementById('rc_keep_label').textContent = 'Keep the original rate';
                document.getElementById('rc_keep_hint').textContent = 'Bill unchanged (e.g. non-refundable rate, guest asked to move).' + (canKeep ? '' : ' Needs a manager (Edit Booking Financials).');
            }
            document.querySelector('.rc-pricing').hidden = dir === 'same';
            keepInput.disabled = dir !== 'same' && !canKeep;
            keepWrap.classList.toggle('is-disabled', keepInput.disabled);
            if (keepInput.disabled && keepInput.checked) chargeInput.checked = true;
            if (dir === 'same') chargeInput.checked = true;

            const reasonSel = document.getElementById('rc_reason');
            const prevReason = reasonSel.value;
            reasonSel.innerHTML = '<option value="">-- Choose a reason --</option>';
            Object.keys(o.reasons || {}).forEach(code => {
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = o.reasons[code];
                reasonSel.appendChild(opt);
            });
            if (prevReason && o.reasons && o.reasons[prevReason]) reasonSel.value = prevReason;

            rcUpdateTotals();
            detail.hidden = false;
        }

        function rcUpdateTotals() {
            const o = rcSelectedOption();
            if (!o) return;
            const q = o.quote;
            const pricing = (document.querySelector('input[name="rc_pricing"]:checked') || {}).value || 'charge';
            const keep = pricing === 'keep' || q.direction === 'same';
            const newTotal = keep ? q.current_total : q.charge_total;
            const delta = newTotal - q.current_total;
            document.getElementById('rc_current_total').textContent = rcMoney(q.current_total);
            document.getElementById('rc_new_total').textContent = rcMoney(newTotal);
            const deltaEl = document.getElementById('rc_delta');
            deltaEl.textContent = (delta > 0 ? '+' : '') + rcMoney(delta);
            deltaEl.style.color = delta > 0 ? '#b42318' : (delta < 0 ? '#1b5e20' : '#222');

            const reason = document.getElementById('rc_reason').value;
            const noteRequired = (keep && q.direction !== 'same') || q.direction === 'downgrade' || /_other$/.test(reason);
            document.getElementById('rc_note_req').hidden = !noteRequired;

            const paid = Number(roomChangeData.booking.amount_paid || 0);
            let acc = '';
            if (delta > 0) acc = 'The extra amount is added to the guest\'s balance due.';
            else if (delta < 0 && paid > newTotal) acc = 'The guest has paid ' + rcMoney(paid) + ', more than the new bill: the difference shows as a credit (refund it or issue a credit note).';
            else if (delta < 0) acc = 'The balance due goes down by ' + rcMoney(-delta) + '.';
            else if (keep && q.direction === 'upgrade') acc = 'No money moves. The comp value is written to the audit log and timeline.';
            document.getElementById('rc_accounting_note').textContent = acc;

            const note = document.getElementById('rc_note').value.trim();
            document.getElementById('rc_submit').disabled = !reason || (noteRequired && note.length < 5);
        }

        document.getElementById('upgrade_new_room').addEventListener('change', rcRender);
        document.getElementById('rc_reason').addEventListener('change', rcUpdateTotals);
        document.getElementById('rc_note').addEventListener('input', rcUpdateTotals);
        document.querySelectorAll('input[name="rc_pricing"]').forEach(r => r.addEventListener('change', rcUpdateTotals));

        function submitUpgradeRoom() {
            const o = rcSelectedOption();
            if (!o) {
                Alert.show('Choose the new room type.', 'error');
                return;
            }
            const submitBtn = document.getElementById('rc_submit');
            setButtonLoading(submitBtn, true);
            const fd = new FormData();
            fd.append('action', 'upgrade_room_type');
            fd.append('booking_id', document.getElementById('upgrade_booking_id').value);
            fd.append('new_room_id', o.id);
            fd.append('pricing', (document.querySelector('input[name="rc_pricing"]:checked') || {}).value || 'charge');
            fd.append('reason_code', document.getElementById('rc_reason').value);
            fd.append('note', document.getElementById('rc_note').value.trim());
            fd.append('send_email', document.getElementById('upgrade_send_email').checked ? '1' : '0');
            postBookingAction(fd, 'Could not change the room type.')
                .then(data => {
                    window.bkDeepLeaving = true; // closing the modal below is not a cancel
                    closeUpgradeRoomModal();
                    window.bkDeepLeaving = false;
                    reloadWithBookingActionMessage(data, 'Room type changed.');
                })
                .catch(err => {
                    setButtonLoading(submitBtn, false);
                    Alert.show(err.message || 'Could not change the room type.', 'error');
                });
        }

        // Close modal when clicking the backdrop
        document.getElementById('upgradeRoomModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeUpgradeRoomModal();
            }
        });

        // ── Delete / restore (recoverable; permission delete_booking) ────────────
        function closeDeleteGroupModal() {
            setBookingPageModalOpen(document.getElementById('deleteGroupModal'), false);
            document.getElementById('deleteGroupForm').reset();
        }

        document.getElementById('deleteGroupForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const reason = (document.getElementById('delete_group_reason').value || '').trim();
            if (reason.length < 5) {
                Alert.show('A reason of at least 5 characters is required to delete a booking.', 'error');
                return;
            }
            const formData = new FormData(this);
            formData.set('reason', reason);
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) setButtonLoading(submitBtn, true);
            postBookingAction(formData, 'Could not delete the booking.')
                .then(data => reloadWithBookingActionMessage(data, 'Booking deleted.'))
                .catch(err => {
                    if (submitBtn) setButtonLoading(submitBtn, false);
                    Alert.show(err.message || 'Could not delete the booking.', 'error');
                });
        });

        async function deleteBookingRecord(bookingId, bookingRef, guestName, status) {
            // A room booked together with others: ask whole group or only this room (default whole group).
            const groupInfo = await bkFetchGroupInfo(bookingId);
            if (groupInfo.count > 1) {
                document.getElementById('delete_group_booking_id').value = bookingId;
                document.getElementById('delete_group_ref').value = bookingRef;
                document.getElementById('delete_group_reason').value = '';
                bkApplyGroupChoice('delete_group_box', 'delete_group_label', 'delete_group_refs', groupInfo);
                setBookingPageModalOpen(document.getElementById('deleteGroupModal'), true);
                return;
            }
            const reason = await promptAdminAction({
                title: 'Delete booking ' + bookingRef,
                message: 'The booking moves to the Deleted filter. It can be restored later.',
                details: [
                    'Guest: ' + guestName + ' (status: ' + status + ')',
                    ['pending', 'tentative', 'confirmed'].includes(status) ? 'The room it holds is released straight away. No email is sent to the guest.' : 'Nothing is sent to the guest.',
                    'Your name, the exact time and this reason are recorded in the audit log.'
                ],
                inputLabel: 'Why is this booking being deleted? (required)',
                inputPlaceholder: 'e.g. Duplicate of RBH2026..., test booking, created in error',
                confirmText: 'Delete booking',
                tone: 'danger',
                icon: 'fa-trash-can'
            });
            if (reason === null) return;
            if (reason.trim().length < 5) {
                Alert.show('A reason of at least 5 characters is required to delete a booking.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'delete_booking');
            fd.append('booking_id', bookingId);
            fd.append('reason', reason.trim());
            postBookingAction(fd, 'Could not delete the booking.')
                .then(data => reloadWithBookingActionMessage(data, 'Booking deleted.'))
                .catch(err => Alert.show(err.message || 'Could not delete the booking.', 'error'));
        }

        async function restoreDeletedBooking(bookingId, bookingRef) {
            const note = await promptAdminAction({
                title: 'Restore booking ' + bookingRef,
                message: 'The booking gets back the status it had before it was deleted.',
                details: ['If it held a room, the room type must still be free for its dates.'],
                inputLabel: 'Note (optional)',
                inputPlaceholder: 'Why is it being restored?',
                confirmText: 'Restore booking',
                tone: 'success',
                icon: 'fa-trash-arrow-up'
            });
            if (note === null) return;
            const fd = new FormData();
            fd.append('action', 'restore_booking');
            fd.append('booking_id', bookingId);
            fd.append('note', note.trim());
            postBookingAction(fd, 'Could not restore the booking.')
                .then(data => reloadWithBookingActionMessage(data, 'Booking restored.'))
                .catch(err => Alert.show(err.message || 'Could not restore the booking.', 'error'));
        }

        // Delegated event listeners for action buttons
        function bindBookingsActionDelegates() {
            if (window.__bookingsActionDelegatesBound) return;
            window.__bookingsActionDelegatesBound = true;

            // Close modal when clicking outside for quickRoomAssignModal
            const quickRoomAssignModal = document.getElementById('quickRoomAssignModal');
            if (quickRoomAssignModal && !quickRoomAssignModal.dataset.overlayCloseBound) {
                quickRoomAssignModal.dataset.overlayCloseBound = '1';
                quickRoomAssignModal.addEventListener('click', function(event) {
                    if (event.target === this) {
                        closeQuickRoomAssignModal();
                    }
                });
            }

            document.addEventListener('click', function(event) {
                const button = event.target.closest('[data-action]');
                if (!button) return;

                try {
                    const action = button.dataset.action;
                    const bookingId = button.dataset.bookingId;
                    const bookingRef = button.dataset.bookingRef || '';

                    if (!bookingId) {
                        Alert.show('This booking action is missing its booking ID.', 'error');
                        return;
                    }

                    if (action === 'assign-room') {
                        // Prevent default behavior and stop propagation
                        event.preventDefault();
                        event.stopPropagation();

                        const checkIn = button.dataset.checkIn;
                        const checkOut = button.dataset.checkOut;
                        const roomId = button.dataset.roomId;

                        // bookingRef is optional for assign-room action
                        const effectiveBookingRef = bookingRef || button.dataset.bookingRef || 'N/A';

                        if (!checkIn || !checkOut || !roomId) {
                            Alert.show('Room assignment data is incomplete. Refresh and try again.', 'error');
                            return;
                        }

                        openQuickRoomAssignModal(bookingId, effectiveBookingRef, checkIn, checkOut, roomId);
                    } else if (action === 'make-tentative') {
                        const tentativeType = button.dataset.tentativeType; // 'make_tentative' or 'convert_to_tentative'
                        if (typeof openMakeTentativeModal === 'function') {
                            openMakeTentativeModal(bookingId, bookingRef, tentativeType);
                        }
                    } else if (action === 'check-in') {
                        event.preventDefault();
                        event.stopPropagation();
                        const guestName = button.dataset.guestName;
                        const checkInDate = button.dataset.checkInDate;
                        const paymentStatus = button.dataset.paymentStatus;
                        const roomAssigned = button.dataset.roomAssigned === 'true' || button.dataset.roomAssigned === '1';
                        const bookingStatus = button.dataset.bookingStatus;
                        if (typeof openCheckInModal === 'function') {
                            let lateInfo = null;
                            try { lateInfo = button.dataset.lateInfo ? JSON.parse(button.dataset.lateInfo) : null; } catch (lateErr) { lateInfo = null; }
                            openCheckInModal(bookingId, bookingRef, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, 'checkin', button.dataset.paymentGate || '', lateInfo);
                        }
                    } else if (action === 'no-show') {
                        event.preventDefault();
                        event.stopPropagation();
                        const guestName = button.dataset.guestName;
                        const checkInDate = button.dataset.checkInDate;
                        const paymentStatus = button.dataset.paymentStatus;
                        const roomAssigned = button.dataset.roomAssigned === 'true' || button.dataset.roomAssigned === '1';
                        const bookingStatus = button.dataset.bookingStatus;
                        if (typeof openCheckInModal === 'function') {
                            let lateInfoNs = null;
                            try { lateInfoNs = button.dataset.lateInfo ? JSON.parse(button.dataset.lateInfo) : null; } catch (lateErr) { lateInfoNs = null; }
                            openCheckInModal(bookingId, bookingRef, guestName, checkInDate, paymentStatus, roomAssigned, bookingStatus, 'noshow', '', lateInfoNs);
                        }
                    } else if (action === 'upgrade-room') {
                        event.preventDefault();
                        event.stopPropagation();
                        if (typeof _closeAllActionMenus === 'function') _closeAllActionMenus(null);
                        if (typeof openUpgradeRoomModal === 'function') {
                            openUpgradeRoomModal(bookingId);
                        }
                    }
                } catch (error) {
                    if (typeof Alert !== 'undefined' && Alert.show) {
                        Alert.show('An error occurred while opening the modal. Please try again.', 'error');
                    }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindBookingsActionDelegates);
        } else {
            bindBookingsActionDelegates();
        }

        // Deep link from booking-details.php: open the requested modal for that booking.
        (function() {
            const deepAction = <?php echo json_encode($deep_link_action); ?>;
            const deepId = <?php echo (int)$deep_link_booking_id; ?>;
            if (!deepAction || !deepId) return;
            // The action cannot run from here: say why on the booking page and go back to it.
            const bounce = function(message) {
                queueBookingActionMessage(message, 'warning');
                bkDeepCancel();
            };
            const run = function() {
                if (deepAction === 'room-change') {
                    if (document.querySelector('[data-action="upgrade-room"][data-booking-id="' + deepId + '"]')) {
                        openUpgradeRoomModal(deepId);
                    } else {
                        bounce('The room type of this booking cannot be changed in its current state.');
                    }
                } else if (deepAction === 'checkout') {
                    const row = document.getElementById('booking-' + deepId);
                    if (row && row.dataset.status === 'checked-in') {
                        checkoutBooking(deepId, row.querySelector('td strong') ? row.querySelector('td strong').textContent.trim() : '');
                    } else {
                        bounce('Only an in-house (checked-in) guest can be checked out.');
                    }
                } else if (deepAction === 'checkin') {
                    // From booking-details: a late arrival is checked in here, where the dialog asks what happened.
                    const ciBtn = document.querySelector('[data-action="check-in"][data-booking-id="' + deepId + '"]');
                    if (ciBtn && !ciBtn.classList.contains('disabled')) {
                        ciBtn.click();
                    } else if (ciBtn) {
                        bounce(ciBtn.getAttribute('title') || 'This booking cannot be checked in yet.');
                    } else {
                        bounce('This booking cannot be checked in from here (it must be confirmed, with the arrival date reached, and you need the check-in permission).');
                    }
                } else if (deepAction === 'assign-room') {
                    const btn = document.querySelector('[data-action="assign-room"][data-booking-id="' + deepId + '"]');
                    if (btn) {
                        btn.click();
                    } else {
                        bounce('You need permission to edit bookings to assign a room.');
                    }
                }
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => setTimeout(run, 50), { once: true });
            } else {
                setTimeout(run, 50);
            }
        })();
    </script>
    <script src="js/admin-components.js"></script>

    <!-- ===== Modify Booking Modal ===== -->
    <div id="modifyBookingModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-pen-to-square"></i> Modify Booking</h3>
                <button type="button" class="close-modal" onclick="closeModifyBookingModal()">&times;</button>
            </div>
            <form id="modifyBookingForm" method="POST" action="">
                <input type="hidden" name="action" value="modify_booking">
                <input type="hidden" name="id" id="mb_booking_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference</label>
                        <input type="text" id="mb_ref" class="form-control" readonly style="background:#f5f5f5;">
                    </div>

                    <div class="form-section-title">Guest</div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Full name</label>
                            <input type="text" name="guest_name" id="mb_guest_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="guest_email" id="mb_guest_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="guest_phone" id="mb_guest_phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="guest_country" id="mb_guest_country" class="form-control">
                        </div>
                    </div>

                    <div class="form-section-title">Stay</div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Check-in date</label>
                            <input type="date" name="check_in_date" id="mb_check_in" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out date</label>
                            <input type="date" name="check_out_date" id="mb_check_out" class="form-control" required>
                        </div>
                    </div>
                    <div id="mb_rate_preview" class="bk-rate-preview" hidden aria-live="polite" style="background:#f7f3ee;border-radius:6px;padding:10px 12px;margin-top:10px;font-size:13px;line-height:1.6;"></div>
                    <div class="grid-3">
                        <div class="form-group">
                            <label>Adults</label>
                            <input type="number" min="1" name="adult_guests" id="mb_adults" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Children</label>
                            <input type="number" min="0" name="child_guests" id="mb_children" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Total guests</label>
                            <input type="number" min="1" name="number_of_guests" id="mb_guests" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group is-financial-locked">
                        <small class="field-lock-hint"><i class="fas fa-lock"></i> Status, payment status and price are not edited here: use the booking's actions (confirm, check-in, checkout, cancel), record payments, and issue a credit note to correct a price. Date changes reprice at the guest's booked rate.</small>
                    </div>

                    <div class="form-group">
                        <label>Special requests / internal note</label>
                        <textarea name="special_requests" id="mb_special" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Audit note (why)</label>
                        <input type="text" name="note" id="mb_note" class="form-control" placeholder="e.g. Guest requested earlier check-in">
                    </div>

                    <div style="background:#fff8e1;padding:10px 12px;border-radius:8px;color:#7E684B;font-size:13px;">
                        <i class="fas fa-info-circle"></i>
                        Changes are recorded in the booking audit log. Use <a href="#" id="mb_full_edit_link">the full edit page</a> for occupancy or children (they change the price) and Upgrade / change room type for a different room type.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModifyBookingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Booking Audit Log Modal ===== -->
    <div id="bookingAuditModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="false">
        <div class="modal-content booking-audit-modal__dialog">
            <div class="modal-header booking-audit-modal__header">
                <h3 class="booking-audit-modal__title"><i class="fas fa-clock-rotate-left"></i> Booking Audit Timeline <small id="bal_ref" class="booking-audit-modal__ref"></small></h3>
                <button type="button" class="close-modal" onclick="closeBookingAuditModal()">&times;</button>
            </div>
            <div class="modal-body booking-audit-modal__content-wrap">
                <div id="bal_body" class="booking-audit-modal__body">
                    <div class="booking-audit-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookingAuditModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // ===== Modify Booking modal logic =====
        function setBookingPageModalOpen(modal, isOpen) {
            if (!modal) return;
            modal.style.transition = 'none';
            modal.style.display = isOpen ? 'flex' : 'none';
            modal.style.opacity = isOpen ? '1' : '0';
            modal.style.visibility = isOpen ? 'visible' : 'hidden';
            modal.classList.toggle('active', isOpen);
            modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            if (isOpen) {
                document.body.classList.add('modal-open');
            } else if (!document.querySelector('.modal-overlay.active, .modal.active')) {
                document.body.classList.remove('modal-open');
            }
        }

        function openModifyBookingModal(bookingId, bookingRef) {
            const modal = document.getElementById('modifyBookingModal');
            setBookingPageModalOpen(modal, true);
            document.getElementById('mb_booking_id').value = bookingId;
            document.getElementById('mb_ref').value = bookingRef || '';
            document.getElementById('mb_full_edit_link').href = 'edit-booking.php?id=' + encodeURIComponent(bookingId);

            const fd = new FormData();
            fd.append('action', 'get_booking_for_modify');
            fd.append('booking_id', bookingId);
            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    if (!j.success) {
                        Alert.show(j.message || 'Failed to load booking', 'error');
                        return;
                    }
                    const b = j.data;
                    const set = (id, v) => {
                        const el = document.getElementById(id);
                        if (el) el.value = (v ?? '');
                    };
                    set('mb_guest_name', b.guest_name);
                    set('mb_guest_email', b.guest_email);
                    set('mb_guest_phone', b.guest_phone);
                    set('mb_guest_country', b.guest_country);
                    set('mb_check_in', b.check_in_date);
                    set('mb_check_out', b.check_out_date);
                    modal.dataset.loadedIn = b.check_in_date || '';
                    modal.dataset.loadedOut = b.check_out_date || '';
                    bkRenderStayRate(document.getElementById('mb_rate_preview'), null);
                    set('mb_adults', b.adult_guests);
                    set('mb_children', b.child_guests);
                    set('mb_guests', b.number_of_guests);
                    const inHouse = b.status === 'checked-in';
                    ['mb_check_in', 'mb_check_out', 'mb_children'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.disabled = inHouse;
                    });
                    set('mb_special', b.special_requests);
                    set('mb_note', '');
                })
                .catch(() => Alert.show('Error loading booking', 'error'));
        }

        // Dates changed in Quick modify: show the booked rate x the new nights (nothing when the dates are unchanged).
        function mbDatesChanged() {
            const m = document.getElementById('modifyBookingModal');
            const ci = document.getElementById('mb_check_in')?.value || '';
            const co = document.getElementById('mb_check_out')?.value || '';
            return !!(m && ci && co && (ci !== (m.dataset.loadedIn || '') || co !== (m.dataset.loadedOut || '')));
        }
        ['mb_check_in', 'mb_check_out'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', function() {
                if (!mbDatesChanged()) {
                    bkRenderStayRate(document.getElementById('mb_rate_preview'), null);
                    return;
                }
                bkRefreshStayRate('mb_rate_preview', document.getElementById('mb_booking_id')?.value,
                    document.getElementById('mb_check_in')?.value, document.getElementById('mb_check_out')?.value);
            });
        });

        function closeModifyBookingModal() {
            const m = document.getElementById('modifyBookingModal');
            setBookingPageModalOpen(m, false);
        }
        document.getElementById('modifyBookingModal').addEventListener('click', function(e) {
            if (e.target === this) closeModifyBookingModal();
        });
        document.getElementById('modifyBookingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const getFieldValue = (fieldName, fieldId, fallback = '-') => {
                const posted = fd.get(fieldName);
                if (posted !== null && posted !== '') {
                    return posted;
                }
                const el = document.getElementById(fieldId);
                if (el && el.value !== '') {
                    return el.value;
                }
                return fallback;
            };
            const ref = document.getElementById('mb_ref').value || ('Booking #' + (fd.get('id') || ''));
            const mbRateQ = mbDatesChanged()
                ? await bkStayRateQuote(fd.get('id'), fd.get('check_in_date'), fd.get('check_out_date'))
                : null;
            const confirmed = await confirmAdminAction({
                title: 'Confirm booking changes',
                message: 'Review the main booking values before saving ' + ref + '.',
                details: [
                    'Stay: ' + (fd.get('check_in_date') || '-') + ' to ' + (fd.get('check_out_date') || '-'),
                    'Guests: ' + (fd.get('number_of_guests') || '-') + ' total, ' + (fd.get('adult_guests') || '-') + ' adult(s), ' + (fd.get('child_guests') || '0') + ' child guest(s)',
                    'Date changes reprice the stay at the booked rate.'
                ].concat(bkStayRateDetails(mbRateQ)),
                confirmText: 'Save Changes',
                icon: 'fa-pen-to-square'
            });
            if (!confirmed) return;
            const btn = this.querySelector('button[type=submit]');
            if (btn) setButtonLoading(btn, true);
            showLoadingOverlay('Saving changes…');
            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    hideLoadingOverlay();
                    if (j.success) {
                        Alert.show(j.message || 'Saved', 'success');
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                        if (btn) setButtonLoading(btn, false);
                        Alert.show(j.message || 'Failed to save', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    if (btn) setButtonLoading(btn, false);
                    Alert.show('Error saving changes', 'error');
                });
        });

        // ===== Refund flow =====
        function openRefundForBooking(bookingId, bookingRef) {
            const fd = new FormData();
            fd.append('action', 'find_payment_for_refund');
            fd.append('booking_id', bookingId);
            showLoadingOverlay('Looking up payment…');
            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    hideLoadingOverlay();
                    if (j.success && j.payment && j.payment.id) {
                        return confirmAdminAction({
                            title: 'Open refund form',
                            message: 'Open the refund form for this booking payment?',
                            details: [
                                'Booking: ' + bookingRef,
                                'Payment: ' + (j.payment.payment_reference || 'Payment #' + j.payment.id)
                            ],
                            confirmText: 'Open Refund',
                            icon: 'fa-rotate-left',
                            tone: 'warning'
                        }).then(confirmed => {
                            if (confirmed) {
                                window.location.href = 'payment-refund.php?id=' + encodeURIComponent(j.payment.id);
                            }
                        });
                    } else {
                        Alert.show(j.message || 'No refundable payment found.', 'error');
                    }
                })
                .catch(() => {
                    hideLoadingOverlay();
                    Alert.show('Error looking up payment', 'error');
                });
        }

        // ===== Audit log viewer =====
        const AUDIT_FIELD_LABELS = {
            check_in_date: 'Check-in date',
            check_out_date: 'Check-out date',
            status: 'Status',
            payment_status: 'Payment status',
            total_amount: 'Total amount',
            amount_paid: 'Amount paid',
            amount_due: 'Amount due',
            number_of_nights: 'Nights',
            number_of_guests: 'Guests',
            adult_guests: 'Adults',
            child_guests: 'Children',
            guest_name: 'Guest name',
            guest_email: 'Email',
            guest_phone: 'Phone',
            guest_country: 'Country',
            special_requests: 'Special requests',
            admin_note: 'Admin note',
            room_id: 'Room type',
            individual_room_id: 'Room number'
        };
        const AUDIT_ACTION_LABELS = {
            modified: 'Modified',
            cancelled: 'Cancelled',
            'checked-in': 'Checked In',
            'checked-out': 'Checked Out',
            'no-show': 'No Show',
            extended: 'Extended Stay',
            upgraded: 'Room Upgraded',
            payment_updated: 'Payment Updated',
            payment_consolidation: 'Payment Recorded',
            tentative: 'Made Tentative',
            confirmed: 'Confirmed',
            pending: 'Set to Pending',
            'check-in cancelled': 'Check-in Cancelled'
        };
        const AUDIT_CURRENCY = <?php echo json_encode((string)($currency_symbol ?? 'MWK')); ?>;

        function formatAuditDate(value) {
            if (!value) return '\u2014';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return escapeHtml(String(value));
            return escapeHtml(d.toLocaleString('en-GB', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }));
        }

        function formatAuditValue(field, value) {
            if (value === null || value === undefined || value === '') return '\u2014';
            if (/(amount|total|refund|vat)/i.test(field)) {
                const n = Number(value);
                if (!Number.isNaN(n)) return escapeHtml(AUDIT_CURRENCY + ' ' + n.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));
            }
            if (/(date|_at)$/i.test(field)) {
                return formatAuditDate(String(value));
            }
            if (typeof value === 'boolean') return value ? 'Yes' : 'No';
            return escapeHtml(String(value));
        }

        function prettifyKey(key) {
            return AUDIT_FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        function computeExtensionText(oldVals, newVals) {
            const oldCheckout = oldVals && oldVals.check_out_date ? String(oldVals.check_out_date) : '';
            const newCheckout = newVals && newVals.check_out_date ? String(newVals.check_out_date) : '';
            if (!oldCheckout || !newCheckout) return '';
            const oldDate = new Date(oldCheckout + 'T00:00:00');
            const newDate = new Date(newCheckout + 'T00:00:00');
            if (Number.isNaN(oldDate.getTime()) || Number.isNaN(newDate.getTime())) return '';
            const diffDays = Math.round((newDate.getTime() - oldDate.getTime()) / 86400000);
            if (diffDays > 0) return 'Extended by ' + diffDays + ' night' + (diffDays === 1 ? '' : 's');
            if (diffDays < 0) return 'Reduced by ' + Math.abs(diffDays) + ' night' + (Math.abs(diffDays) === 1 ? '' : 's');
            return '';
        }

        function renderAuditDiff(row) {
            const changed = Array.isArray(row.changed_fields) ? row.changed_fields : [];
            if (!changed.length) return '';
            const oldVals = row.old_values || {};
            const newVals = row.new_values || {};
            let html = '<div class="bal-item__section"><h5>Field Changes</h5><table class="alog-diff"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
            changed.forEach(field => {
                html += '<tr>' +
                    '<td><strong>' + escapeHtml(prettifyKey(field)) + '</strong></td>' +
                    '<td class="f-old">' + formatAuditValue(field, oldVals[field]) + '</td>' +
                    '<td class="f-new">' + formatAuditValue(field, newVals[field]) + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }

        function renderAuditEvent(row) {
            const changed = Array.isArray(row.changed_fields) ? row.changed_fields : [];
            const oldVals = row.old_values || {};
            const newVals = row.new_values || {};
            const extensionHint = computeExtensionText(oldVals, newVals);
            const isExtension = row.action === 'extended' || changed.includes('check_out_date') || changed.includes('number_of_nights');
            const title = isExtension && extensionHint ? 'Stay Extension' : (AUDIT_ACTION_LABELS[row.action] || prettifyKey(String(row.action || 'update')));
            const who = row.performed_by_name || ('User #' + (row.performed_by || '?'));
            const note = row.note ? '<div class="bal-note"><strong>Note:</strong> ' + escapeHtml(row.note) + '</div>' : '';
            const changeCount = changed.length ? '<span class="bal-chip">' + changed.length + ' field' + (changed.length === 1 ? '' : 's') + ' changed</span>' : '';
            const extensionChip = extensionHint ? '<span class="bal-chip bal-chip--accent">' + escapeHtml(extensionHint) + '</span>' : '';
            const ip = row.ip_address ? '<span class="bal-meta-pill">IP ' + escapeHtml(row.ip_address) + '</span>' : '';

            return '<article class="bal-item">' +
                '<div class="bal-item__head">' +
                '<h4>' + escapeHtml(title) + '</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.performed_at) + '</span>' +
                ip +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' + changeCount + extensionChip + '</div>' +
                renderAuditDiff(row) +
                note +
                '</article>';
        }

        function renderPaymentEvent(row) {
            const amountVal = Number(row.total_amount ?? row.payment_amount ?? row.refund_amount ?? 0);
            const amountText = AUDIT_CURRENCY + ' ' + amountVal.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const who = row.recorded_by_name || (row.recorded_by ? ('User #' + row.recorded_by) : 'System');
            const type = String(row.payment_type || 'payment').replace(/_/g, ' ');
            const status = row.payment_status ? String(row.payment_status) : 'completed';
            const ref = row.payment_reference ? '<span class="bal-meta-pill">Ref ' + escapeHtml(row.payment_reference) + '</span>' : '';
            const method = row.payment_method ? '<span class="bal-meta-pill">' + escapeHtml(String(row.payment_method).replace(/_/g, ' ')) + '</span>' : '';
            const statusChipClass = /refund/i.test(type) ? ' bal-chip--warn' : ' bal-chip--ok';
            const notes = row.notes ? '<div class="bal-note"><strong>Notes:</strong> ' + escapeHtml(row.notes) + '</div>' : '';
            const reason = row.refund_reason ? '<div class="bal-note"><strong>Refund reason:</strong> ' + escapeHtml(row.refund_reason) + '</div>' : '';

            return '<article class="bal-item bal-item--payment">' +
                '<div class="bal-item__head">' +
                '<h4>' + (/refund/i.test(type) ? 'Refund Recorded' : 'Payment Recorded') + '</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.created_at || row.payment_date) + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' +
                '<span class="bal-chip' + statusChipClass + '">' + escapeHtml(type) + '</span>' +
                '<span class="bal-chip">' + escapeHtml(status) + '</span>' +
                '<span class="bal-chip bal-chip--amount">' + escapeHtml(amountText) + '</span>' +
                method +
                ref +
                '</div>' +
                reason +
                notes +
                '</article>';
        }

        function renderTentativeEvent(row) {
            const action = String(row.action || 'tentative').replace(/_/g, ' ');
            const who = row.performed_by_name || (row.performed_by ? ('User #' + row.performed_by) : 'System');
            const reason = row.action_reason ? '<div class="bal-note"><strong>Reason:</strong> ' + escapeHtml(row.action_reason) + '</div>' : '';
            const prevExp = row.previous_expires_at ? '<span class="bal-meta-pill">Prev expiry: ' + formatAuditDate(row.previous_expires_at) + '</span>' : '';
            const newExp = row.new_expires_at ? '<span class="bal-meta-pill">New expiry: ' + formatAuditDate(row.new_expires_at) + '</span>' : '';

            return '<article class="bal-item bal-item--tentative">' +
                '<div class="bal-item__head">' +
                '<h4>Tentative Timeline</h4>' +
                '<div class="bal-item__meta">' +
                '<span>' + escapeHtml(who) + '</span>' +
                '<span>\u2022</span>' +
                '<span>' + formatAuditDate(row.created_at) + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="bal-item__chips">' +
                '<span class="bal-chip bal-chip--accent">' + escapeHtml(action) + '</span>' +
                prevExp +
                newExp +
                '</div>' +
                reason +
                '</article>';
        }

        function renderAuditSummary(snapshot, counts) {
            if (!snapshot) return '';
            const room = [snapshot.room_type_name || '', snapshot.room_number ? ('Room ' + snapshot.room_number) : ''].filter(Boolean).join(' \u2022 ');
            const chips = [
                '<span class="bal-chip">Status: ' + escapeHtml(snapshot.status || '\u2014') + '</span>',
                '<span class="bal-chip">Payment: ' + escapeHtml(snapshot.payment_status || '\u2014') + '</span>',
                '<span class="bal-chip">Stay: ' + formatAuditDate(snapshot.check_in_date) + ' -> ' + formatAuditDate(snapshot.check_out_date) + '</span>',
                '<span class="bal-chip">Nights: ' + escapeHtml(String(snapshot.number_of_nights ?? '\u2014')) + '</span>',
                '<span class="bal-chip bal-chip--amount">Total: ' + formatAuditValue('total_amount', snapshot.total_full ?? snapshot.total_amount) + '</span>',
                '<span class="bal-chip bal-chip--ok">Paid: ' + formatAuditValue('amount_paid', snapshot.amount_paid) + '</span>',
                '<span class="bal-chip bal-chip--warn">Due: ' + formatAuditValue('amount_due', snapshot.amount_due) + '</span>',
                '<span class="bal-chip">Events: ' + counts.total + '</span>'
            ];

            return '<section class="bal-summary">' +
                '<div class="bal-summary__top">' +
                '<h4>' + escapeHtml(snapshot.guest_name || 'Guest') + '</h4>' +
                '<div class="bal-summary__sub">' +
                (snapshot.guest_email ? '<span>' + escapeHtml(snapshot.guest_email) + '</span>' : '') +
                (snapshot.guest_phone ? '<span>' + escapeHtml(snapshot.guest_phone) + '</span>' : '') +
                (room ? '<span>' + escapeHtml(room) + '</span>' : '') +
                '</div>' +
                '</div>' +
                '<div class="bal-summary__chips">' + chips.join('') + '</div>' +
                '</section>';
        }

        function viewBookingAuditLog(bookingId, ref) {
            _closeAllActionMenus(null);
            document.getElementById('bal_ref').textContent = ref ? ('\u00b7 ' + ref) : '';
            const body = document.getElementById('bal_body');
            body.innerHTML = '<div class="booking-audit-modal__loading"><i class="fas fa-spinner fa-spin"></i> Loading timeline...</div>';
            const modal = document.getElementById('bookingAuditModal');
            setBookingPageModalOpen(modal, true);
            const fd = new FormData();
            fd.append('action', 'get_booking_audit_log');
            fd.append('booking_id', bookingId);
            fetch(rhBookingsPostUrl(), {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(j => {
                    if (!j.success) {
                        body.innerHTML = '<div class="bal-state bal-state--error">Failed to load booking timeline.</div>';
                        return;
                    }
                    const payload = Array.isArray(j.data) ? {
                        booking: null,
                        audit_logs: j.data,
                        payments: [],
                        tentative_log: []
                    } : (j.data || {});
                    const auditLogs = Array.isArray(payload.audit_logs) ? payload.audit_logs : [];
                    const paymentLogs = Array.isArray(payload.payments) ? payload.payments : [];
                    const tentativeLogs = Array.isArray(payload.tentative_log) ? payload.tentative_log : [];

                    const timeline = [];
                    auditLogs.forEach(row => timeline.push({
                        type: 'audit',
                        ts: row.performed_at || '',
                        html: renderAuditEvent(row)
                    }));
                    paymentLogs.forEach(row => timeline.push({
                        type: 'payment',
                        ts: row.created_at || row.payment_date || '',
                        html: renderPaymentEvent(row)
                    }));
                    tentativeLogs.forEach(row => timeline.push({
                        type: 'tentative',
                        ts: row.created_at || '',
                        html: renderTentativeEvent(row)
                    }));

                    timeline.sort((a, b) => {
                        const ta = new Date(String(a.ts).replace(' ', 'T')).getTime() || 0;
                        const tb = new Date(String(b.ts).replace(' ', 'T')).getTime() || 0;
                        return tb - ta;
                    });

                    if (!timeline.length) {
                        body.innerHTML = '<div class="bal-state">No audit entries yet.</div>';
                        return;
                    }

                    const counts = {
                        total: timeline.length,
                        audits: auditLogs.length,
                        payments: paymentLogs.length,
                        tentative: tentativeLogs.length
                    };

                    body.innerHTML = '<div class="bal-shell">' +
                        renderAuditSummary(payload.booking, counts) +
                        '<div class="bal-overview">' +
                        '<span class="bal-chip">Audit events: ' + counts.audits + '</span>' +
                        '<span class="bal-chip bal-chip--ok">Payment events: ' + counts.payments + '</span>' +
                        '<span class="bal-chip bal-chip--accent">Tentative events: ' + counts.tentative + '</span>' +
                        '</div>' +
                        '<div class="bal-timeline">' + timeline.map(item => item.html).join('') + '</div>' +
                        '</div>';
                })
                .catch(() => {
                    body.innerHTML = '<div class="bal-state bal-state--error">Error loading booking timeline.</div>';
                });
        }

        function closeBookingAuditModal() {
            const m = document.getElementById('bookingAuditModal');
            setBookingPageModalOpen(m, false);
        }
        document.getElementById('bookingAuditModal').addEventListener('click', function(e) {
            if (e.target === this && this.dataset.closeOnOverlay !== 'false') closeBookingAuditModal();
        });

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        // ===== Send invoice email from dropdown =====
        function sendInvoiceEmail(bookingId, ref, triggerEl, clickEvent) {
            if (clickEvent) clickEvent.stopPropagation();
            if (!bookingId) return;
            confirmAdminAction({
                title: 'Send invoice to guest',
                message: 'Generate if needed and email the invoice for ' + escapeHtml(ref) + ' to the guest?',
                confirmText: 'Send Invoice',
                icon: 'fa-file-invoice'
            }).then(confirmed => {
                if (!confirmed) return;
                showLoadingOverlay('Sending invoice…');
                const fd = new FormData();
                fd.append('action', 'send_invoice_email');
                fd.append('booking_id', bookingId);
                fetch(rhBookingsPostUrl(), {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(async r => {
                        const contentType = (r.headers.get('content-type') || '').toLowerCase();
                        if (!contentType.includes('application/json')) {
                            throw new Error('We could not confirm the invoice send result. Please sign in again and retry.');
                        }
                        const data = await r.json();
                        if (!r.ok || !data.success) {
                            throw new Error(data.message || 'We could not send the invoice right now.');
                        }
                        return data;
                    })
                    .then(j => {
                        hideLoadingOverlay();
                        showBookingActionMessage(j.message || 'Invoice email sent to the guest successfully.', 'success');
                    })
                    .catch((error) => {
                        hideLoadingOverlay();
                        showBookingActionMessage((error && error.message) ? error.message : 'Network error. Please try again.', 'error');
                    });
            });
        }

        // ===== Overflow menu toggling =====
        function _closeAllActionMenus(except) {
            document.querySelectorAll('.actions-more.open').forEach(el => {
                if (el === except) return;
                el.classList.remove('open');
                const m = el.querySelector('.actions-more-menu');
                if (m) m.removeAttribute('style');
            });
        }

        function _getActionMenuViewport() {
            const vv = window.visualViewport;
            if (vv) {
                return {
                    left: 0,
                    top: 0,
                    right: vv.width,
                    bottom: vv.height,
                    width: vv.width,
                    height: vv.height
                };
            }
            return {
                left: 0,
                top: 0,
                right: window.innerWidth,
                bottom: window.innerHeight,
                width: window.innerWidth,
                height: window.innerHeight
            };
        }

        function toggleActionsMore(btn, evt) {
            if (evt && typeof evt.preventDefault === 'function') {
                evt.preventDefault();
            }
            if (evt && typeof evt.stopPropagation === 'function') {
                evt.stopPropagation();
            }
            const wrap = btn.closest('.actions-more');
            if (!wrap) return;
            const menu = wrap.querySelector('.actions-more-menu');
            if (!menu) return;
            const isOpen = wrap.classList.contains('open');
            _closeAllActionMenus(null);
            if (isOpen) return; // was open → now closed, done

            // Shared placement (admin-components.js): dropdown in table view, full-width under the
            // actions row in card view.
            window.rhPlaceActionMenu(btn, menu);

            wrap.classList.add('open');
        }
        document.addEventListener('click', function(event) {
            const target = event.target;
            if (target && typeof target.closest === 'function' && target.closest('.actions-more')) {
                return;
            }
            _closeAllActionMenus(null);
        });
        // Also close on scroll so the menu doesn't drift away from its button
        document.addEventListener('scroll', function(event) {
            const target = event.target;
            if (target && typeof target.closest === 'function' && (target.closest('.actions-more-menu') || target.closest('.actions-more'))) {
                return;
            }
            _closeAllActionMenus(null);
        }, true);

        window.addEventListener('resize', function() {
            _closeAllActionMenus(null);
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function() {
                _closeAllActionMenus(null);
            });
            window.visualViewport.addEventListener('scroll', function() {
                _closeAllActionMenus(null);
            });
        }

        // Bind data-action="open-modify"
        document.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-action="open-modify"]');
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            openModifyBookingModal(btn.dataset.bookingId, btn.dataset.bookingRef);
        });

        // ── Booking-list quotation modal ──────────────────────────────────────────
        var _blqBookingId = 0;

        function openBookingListQuoteModal(bookingId, ref, guestName, guestEmail) {
            _blqBookingId = bookingId;
            var sumEl = document.getElementById('bl-quotation-summary');
            sumEl.innerHTML = '<strong>' + _blqEsc(guestName) + '</strong>' +
                ' &mdash; <span style="color:#555;">' + _blqEsc(guestEmail) + '</span>' +
                '<br><small style="color:#999;">Ref: ' + _blqEsc(ref) + '</small>';
            document.getElementById('bl-quotation-valid-days').value = '7';
            document.getElementById('bl-quotation-notes').value = '';
            var fb = document.getElementById('bl-quotation-feedback');
            fb.style.display = 'none';
            fb.innerHTML = '';
            var btn = document.getElementById('bl-quotation-send-btn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Quotation';
            document.getElementById('bl-quotation-modal').style.display = 'flex';
        }

        function closeBookingListQuoteModal() {
            document.getElementById('bl-quotation-modal').style.display = 'none';
            _blqBookingId = 0;
        }

        function _blqEsc(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }

        function sendBookingListQuotation() {
            if (!_blqBookingId) {
                return;
            }
            var btn = document.getElementById('bl-quotation-send-btn');
            var fb = document.getElementById('bl-quotation-feedback');
            var validDays = parseInt(document.getElementById('bl-quotation-valid-days').value, 10);
            var notes = document.getElementById('bl-quotation-notes').value.trim();
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
                        booking_id: _blqBookingId,
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
                    fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + _blqEsc(successMessage);
                    showBookingActionMessage(successMessage, 'success');
                    btn.innerHTML = '<i class="fas fa-check"></i> Sent';
                    setTimeout(function() {
                        closeBookingListQuoteModal();
                    }, 1800);
                })
                .catch(function(err) {
                    var friendlyMessage = (err && err.message) ? err.message : 'Network error. Please try again.';
                    fb.style.cssText = 'display:block;background:#F8D7DA;color:#721C24;padding:12px 14px;border-radius:4px;font-size:14px;';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + _blqEsc(friendlyMessage);
                    showBookingActionMessage(friendlyMessage, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Retry';
                });
        }

        function openBookingListQuotationWhatsApp(ref, guestName, guestPhone) {
            var phone = String(guestPhone || '').replace(/[^0-9]/g, '');
            if (!phone) {
                return;
            }

            var message = [
                'Hello ' + String(guestName || '').trim() + ',',
                'your quotation for booking reference ' + String(ref || '').trim() + ' is ready.',
                'Please let us know if you would like any changes or if you are ready to confirm.'
            ].join('\n\n');

            var waUrl = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
            var waWindow = window.open(waUrl, '_blank', 'noopener,noreferrer');
            if (waWindow) {
                showBookingActionMessage('Opening WhatsApp with the quotation draft message.', 'success');
            } else {
                showBookingActionMessage('WhatsApp window was blocked by your browser. Please allow pop-ups and try again.', 'warning');
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            var _blModal = document.getElementById('bl-quotation-modal');
            if (_blModal) {
                _blModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeBookingListQuoteModal();
                    }
                });
            }
        });
    </script>

    <!-- Quotation Modal (Bookings List) -->
    <div id="bl-quotation-modal" class="admin-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bl-quotation-modal-title">
        <div class="admin-modal admin-modal--md">
            <div class="admin-modal__header">
                <h3 id="bl-quotation-modal-title" class="admin-modal__title">
                    <i class="fas fa-file-invoice-dollar"></i> Send Quotation
                </h3>
                <button type="button" class="admin-modal__close" onclick="closeBookingListQuoteModal()" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal__body">
                <div id="bl-quotation-summary" style="background:#FAF6F0;border-radius:6px;padding:14px 16px;margin-bottom:18px;font-size:14px;"></div>
                <div class="form-group">
                    <label for="bl-quotation-valid-days" style="font-size:14px;font-weight:500;color:#333;">Quotation valid for</label>
                    <select id="bl-quotation-valid-days" class="form-control" style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;">
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
                    <label for="bl-quotation-notes" style="font-size:14px;font-weight:500;color:#333;">Note to guest <span style="color:#999;font-weight:400;">(optional)</span></label>
                    <textarea id="bl-quotation-notes" class="form-control" rows="3" placeholder="e.g. Rates include complimentary breakfast." style="width:100%;padding:9px 12px;border:1px solid #DDD;border-radius:4px;font-size:14px;margin-top:6px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
                <div id="bl-quotation-feedback" style="display:none;margin-top:14px;"></div>
            </div>
            <div class="admin-modal__footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookingListQuoteModal()">Cancel</button>
                <button type="button" id="bl-quotation-send-btn" class="btn btn-primary" onclick="sendBookingListQuotation()">
                    <i class="fas fa-paper-plane"></i> Send Quotation
                </button>
            </div>
        </div>
    </div>

    <?php rh_render_send_receipt_script($csrf_token ?? ''); ?>
    <?php require_once __DIR__ . '/includes/history-panel.php'; ?>
    <?php require_once 'includes/admin-footer.php'; ?>

