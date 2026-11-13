<?php

/**
 * Booking Lookup Page
 * Allows guests to check their booking status using reference number and email
 * Also supports booking cancellation by guest
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session-boot.php'; rh_session_start();
}

header('Cache-Control: no-store, private');

require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/public-csrf.php';

/** @var array|null $booking */
$booking = null;
$error = null;
$success = null;
$search_performed = false;

// Generate per-session CSRF token for this form
$csrf_token = pub_csrf_generate('lookup');

// Handle booking lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup'])) {
    $search_performed = true;

    // CSRF validation
    if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'lookup')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (!pub_rate_limit('lookup_form', 10, 600) || !pub_ip_rate_limit('lookup_form', 20, 600)) {
        // Rate limiting: 10 lookups per 10 minutes per session
        $error = 'Too many lookup attempts. Please wait a few minutes before trying again.';
    } else {
        $reference = trim($_POST['booking_reference'] ?? '');
        $email = trim($_POST['guest_email'] ?? '');

        if (empty($reference) || empty($email)) {
            $error = 'Please enter both your booking reference and email address.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name, r.image_url as room_image,
                           r.short_description as room_description
                    FROM bookings b
                    JOIN rooms r ON b.room_id = r.id
                    WHERE b.booking_reference = ? AND b.guest_email = ? AND b.deleted_at IS NULL
                ");
                $stmt->execute([$reference, $email]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $error = 'No booking found with that reference and email combination. Please check your details and try again.';
                }
            } catch (PDOException $e) {
                error_log("Booking lookup error: " . $e->getMessage());
                $error = 'Unable to look up booking. Please try again later.';
            }
        }
    }
}

// Handle guest cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    // CSRF validation
    if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'lookup')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (!pub_rate_limit('cancel_booking', 3, 600) || !pub_ip_rate_limit('cancel_booking', 5, 3600)) {
        // Rate limiting: 3 cancellations per 10 minutes per session
        $error = 'Too many cancellation attempts. Please contact us directly.';
    } else {
        $reference = trim($_POST['booking_reference'] ?? '');
        $email = trim($_POST['guest_email'] ?? '');
        $cancel_reason = trim((string)($_POST['cancel_reason'] ?? ''));
        if ($cancel_reason === '') {
            $cancel_reason = 'Cancelled by guest';
        }
        $cancel_reason = function_exists('mb_substr') ? mb_substr($cancel_reason, 0, 300) : substr($cancel_reason, 0, 300);

        if (!empty($reference) && !empty($email)) {
            try {
                // Verify booking exists and belongs to this guest
                $stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name
                    FROM bookings b
                    JOIN rooms r ON b.room_id = r.id
                    WHERE b.booking_reference = ? AND b.guest_email = ? AND b.deleted_at IS NULL
                ");
                $stmt->execute([$reference, $email]);
                $cancel_booking = $stmt->fetch(PDO::FETCH_ASSOC);

                $cancel_party = [];
                if (!$cancel_booking) {
                    $error = 'Booking not found.';
                } elseif (in_array($cancel_booking['status'], ['cancelled', 'expired', 'checked-in', 'checked-out', 'no-show'])) {
                    $error = 'This booking cannot be cancelled (current status: ' . ucfirst($cancel_booking['status']) . ').';
                } elseif (rh_guest_cancel_company_billed($pdo, $cancel_booking)) {
                    // Company-billed parties are never cancelled online (owner 2026-11-14): the company arranges it.
                    $error = 'This booking is billed to a company account, so it cannot be cancelled online. Please contact us directly.';
                } else {
                    // The whole party is cancelled together (owner 2026-11-14): every room of this booking that carries the
                    // email the guest proved, not deleted, not yet checked in.
                    $cancel_party = rh_guest_cancel_party($pdo, $cancel_booking, $email);
                    if (count($cancel_party) <= 1) {
                        $cancel_party = [$cancel_booking]; // one room: exactly the row looked up, as before
                    }
                    // Enforce cancellation notice window before proceeding (on the party's earliest check-in)
                    $cancelNoticeDays = (int)getSetting('cancellation_notice_days', 0);
                    if ($cancelNoticeDays > 0) {
                        // Compare calendar dates in the hotel timezone so a same-day check-in is inside the window.
                        // An unreadable date is never blocked (rh_days_before_arrival gives PHP_INT_MAX).
                        $daysUntil = min(array_map(static fn ($r) => rh_days_before_arrival((string)$r['check_in_date']), $cancel_party));
                        if ($daysUntil !== PHP_INT_MAX && $daysUntil < $cancelNoticeDays) {
                            $error = 'Online cancellations are not available within ' . $cancelNoticeDays
                                . ' day(s) of check-in. Please contact us directly.';
                        }
                    }
                    // Cancellation policy: a fee needs the guest's explicit "I accept" tick (owner 2026-11-14, Q1).
                    if (empty($error)) {
                        $partyFee = 0.0;
                        foreach ($cancel_party as $pr) {
                            $partyFee += (float)rh_cancellation_fee_quote($pdo, $pr)['amount'];
                        }
                        if (round($partyFee, 2) > BALANCE_TOLERANCE && (string)($_POST['accept_fee'] ?? '') !== '1') {
                            $error = 'Please tick the box to accept the cancellation fee, then cancel again.';
                        }
                    }
                }
                if (empty($error) && $cancel_booking) {
                    // Cancel through the shared settled path (status, room release, bill treatment and refund per the
                    // active cancellation mode, all in one transaction; a party all-or-nothing). Ownership was verified above.
                    $guestRefundStatus = rh_refund_rule('guest_self_cancel_refund') === 'auto' ? 'completed' : 'pending';
                    $guestQuoted = trim((string)($_POST['fee_quoted'] ?? ''));
                    $guestFeeChoice = $guestQuoted !== '' ? ['quoted' => $guestQuoted] : null; // never an override
                    $guestRefundTotal = 0.0;
                    if (count($cancel_party) > 1) {
                        $grpSettled = rh_cancel_booking_group($pdo, array_column($cancel_party, 'id'), 0, 'Guest cancelled: ' . $cancel_reason, $guestRefundStatus, $guestFeeChoice);
                        $settledOk = !empty($grpSettled['success']);
                        $settledErr = (string)($grpSettled['error'] ?? '');
                        foreach (($grpSettled['cancelled'] ?? []) as $gc) {
                            $guestRefundTotal += (float)($gc['refund_total'] ?? 0);
                        }
                    } else {
                        $settled = cancelRoomBookingSettled($pdo, (int)$cancel_booking['id'], 0, 'Guest cancelled: ' . $cancel_reason, $guestRefundStatus, $guestFeeChoice);
                        $settledOk = !empty($settled['success']);
                        $settledErr = (string)($settled['error'] ?? '');
                        $guestRefundTotal = (float)($settled['refund_total'] ?? 0);
                    }
                    if (!$settledOk) {
                        error_log('Guest self-cancel failed for booking ' . (int)$cancel_booking['id'] . ': ' . $settledErr);
                        if (strpos($settledErr, 'The cancellation fee changed') !== false || strpos($settledErr, 'Review the cancellation fee') !== false) {
                            // Nothing changed: the fee moved since the page was shown (e.g. a day passed). Show it again.
                            $error = 'The cancellation fee has changed since this page was opened. Nothing was cancelled. Please look up your booking again to see the current fee.';
                        } else {
                            throw new RuntimeException('cancel_failed');
                        }
                    }
                }
                if (empty($error) && $cancel_booking) {
                    // Send the cancellation email and log each cancelled room
                    require_once 'config/email.php';
                    foreach ($cancel_party as $pr) {
                        $email_result = sendBookingCancelledEmail($pr, $cancel_reason);
                        logCancellationToDatabase(
                            $pr['id'],
                            $pr['booking_reference'],
                            'room',
                            $pr['guest_email'],
                            0, // guest-initiated (no admin user)
                            'Guest cancelled: ' . $cancel_reason,
                            $email_result['success'],
                            $email_result['message']
                        );
                    }

                    $success = count($cancel_party) > 1
                        ? 'Your booking (all ' . count($cancel_party) . ' rooms) has been cancelled successfully. A confirmation email has been sent to your email address.'
                        : 'Your booking has been cancelled successfully. A confirmation email has been sent to your email address.';
                    if ($guestRefundTotal > 0 && rh_refund_rule('guest_self_cancel_refund') !== 'auto') {
                        $success .= ' Any refund due is pending approval by our team and will be processed shortly.';
                    }
                    $booking = null; // Clear the booking display
                }
            } catch (Throwable $e) {
                error_log("Booking cancellation error: " . $e->getMessage());
                $error = 'Unable to cancel booking. Please contact us directly.';
            }
        }
    }
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$whatsapp_number = getSetting('whatsapp_number');

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    // Private, per-guest page: gets the favicon/meta set but is kept out of search results.
    $seo_data = [
        'title' => 'Check Booking Status',
        'description' => 'Look up your booking with your reference number and email address.',
        'type' => 'website',
        'noindex' => true,
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="lookup-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <div class="lookup-container">
            <div class="section-header lookup-header" data-lazy-reveal>
                <span class="section-header__label">Booking Lookup</span>
                <h1 class="section-header__title"><i class="fas fa-search" style="color: var(--gold);"></i> Check Booking Status</h1>
                <p class="section-header__description">Enter your booking reference and email to view your reservation details.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="lookup-card" data-lazy-reveal>
                <form method="POST">
                    <input type="hidden" name="lookup" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(pub_csrf_generate('lookup'), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group">
                        <label for="booking_reference"><i class="fas fa-hashtag" style="color: var(--gold);"></i> Booking Reference</label>
                        <input type="text" id="booking_reference" name="booking_reference" class="form-control"
                            placeholder="e.g., BK20260001" required
                            value="<?php echo htmlspecialchars($_POST['booking_reference'] ?? $_GET['ref'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="guest_email"><i class="fas fa-envelope" style="color: var(--gold);"></i> Email Address</label>
                        <input type="email" id="guest_email" name="guest_email" class="form-control"
                            placeholder="The email used during booking" required
                            value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>">
                    </div>

                    <button type="submit" class="btn-lookup">
                        <i class="fas fa-search"></i> Look Up Booking
                    </button>
                </form>
            </div>

            <?php if ($booking): ?>
                <div class="booking-result">
                    <div class="result-header">
                        <h2>Your Booking</h2>
                        <div class="result-ref"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
                    </div>

                    <div class="result-grid">
                        <?php
                        // One party in several rooms (linked by primary_booking_id, same or different types): list every
                        // room. Soft-deleted rooms are left out; cancelled rooms stay listed with their status but do not
                        // count in the party's guests or total.
                        $lookup_group_rooms = [];
                        try {
                            $lookup_lead_id = (int)($booking['primary_booking_id'] ?: $booking['id']);
                            $lookup_group_stmt = $pdo->prepare("
                                SELECT b.id, b.booking_reference, b.status, b.number_of_guests, b.total_with_vat, b.total_amount, b.vat_amount, r.name AS room_name
                                FROM bookings b JOIN rooms r ON b.room_id = r.id
                                WHERE (b.id = ? OR b.primary_booking_id = ?) AND b.guest_email = ? AND b.deleted_at IS NULL
                                ORDER BY b.id ASC
                            ");
                            $lookup_group_stmt->execute([$lookup_lead_id, $lookup_lead_id, $booking['guest_email']]);
                            $lookup_group_rooms = $lookup_group_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            error_log('Booking lookup group query: ' . $e->getMessage());
                        }
                        $lookup_is_group = count($lookup_group_rooms) > 1;
                        // The rooms that count in the party's guests and total: every room that is not cancelled. If every
                        // room is cancelled, all of them (as a single cancelled booking shows its own total).
                        $lookup_counted_rooms = array_values(array_filter($lookup_group_rooms, static function ($row) {
                            return ($row['status'] ?? '') !== 'cancelled';
                        }));
                        if (!$lookup_counted_rooms) {
                            $lookup_counted_rooms = $lookup_group_rooms;
                        }
                        ?>
                        <div class="result-item">
                            <label>Room</label>
                            <?php if ($lookup_is_group): ?>
                                <div class="value">
                                    <?php foreach ($lookup_group_rooms as $lookup_room_line): ?>
                                        <div><?php echo htmlspecialchars($lookup_room_line['room_name']); ?>
                                            <small>(<?php echo (int)$lookup_room_line['number_of_guests']; ?> guest<?php echo (int)$lookup_room_line['number_of_guests'] === 1 ? '' : 's'; ?>, <?php echo htmlspecialchars($lookup_room_line['booking_reference']); ?>)</small>
                                            <?php if (($lookup_room_line['status'] ?? '') === 'cancelled'): ?>
                                                <span class="status-badge status-cancelled">Cancelled</span>
                                            <?php endif; ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="value"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="result-item">
                            <label>Guest Name</label>
                            <div class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                        </div>
                        <div class="result-item">
                            <label>Check-in</label>
                            <div class="value"><?php echo date('D, M j, Y', strtotime($booking['check_in_date'])); ?></div>
                        </div>
                        <div class="result-item">
                            <label>Check-out</label>
                            <div class="value"><?php echo date('D, M j, Y', strtotime($booking['check_out_date'])); ?></div>
                        </div>
                        <div class="result-item">
                            <label>Nights</label>
                            <div class="value"><?php echo $booking['number_of_nights']; ?> night<?php echo $booking['number_of_nights'] > 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="result-item">
                            <label>Guests</label>
                            <div class="value"><?php echo $lookup_is_group ? (int)array_sum(array_column($lookup_counted_rooms, 'number_of_guests')) : (int)$booking['number_of_guests']; ?></div>
                        </div>
                        <div class="result-item">
                            <label>Total Amount</label>
                            <div class="value" style="color: var(--gold);"><?php echo $currency_symbol; ?><?php
                                // What the guest owes (VAT-inclusive; older rows without it: total_amount + VAT).
                                $lookup_owed = rh_booking_full_total($booking);
                                if ($lookup_is_group) { // the whole party: every room that is not cancelled
                                    $lookup_owed = 0.0;
                                    foreach ($lookup_counted_rooms as $lookup_room_line) {
                                        $lookup_owed += rh_booking_full_total($lookup_room_line);
                                    }
                                }
                                echo number_format($lookup_owed, 0);
                            ?></div>
                        </div>
                        <div class="result-item">
                            <label>Booking Status</label>
                            <div class="value">
                                <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst(str_replace('-', ' ', $booking['status'])); ?></span>
                            </div>
                        </div>
                        <div class="result-item">
                            <label>Payment Status</label>
                            <div class="value">
                                <span class="status-badge status-<?php echo $booking['payment_status']; ?>">
                                    <?php echo ucfirst($booking['payment_status'] ?: 'unpaid'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="result-item">
                            <label>Booked On</label>
                            <div class="value"><?php echo date('M j, Y', strtotime($booking['created_at'])); ?></div>
                        </div>
                    </div>

                    <?php if ($booking['special_requests']): ?>
                        <div style="margin-top: 20px;">
                            <label style="display: block; font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">Special Requests</label>
                            <div style="font-size: 14px; color: #333; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($booking['status'] === 'tentative' && $booking['tentative_expires_at']): ?>
                        <div style="margin-top: 20px; padding: 16px; background: #fff8e1; border-radius: 10px; border-left: 4px solid var(--gold);">
                            <strong style="color: var(--navy);"><i class="fas fa-clock" style="color: var(--gold);"></i> Tentative Booking</strong>
                            <p style="margin: 8px 0 0; color: #666; font-size: 14px;">
                                This booking expires on <strong><?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?></strong>.
                                Please contact us to confirm before expiry.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php
                    // The cancellation policy this booking was made under (its stored wording; nothing for older bookings).
                    $lookup_policy_text = rh_booking_policy_text($booking);
                    if ($lookup_policy_text !== ''): ?>
                        <div style="margin-top: 20px;">
                            <label style="display: block; font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">Cancellation Policy</label>
                            <div style="font-size: 14px; color: #333; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($lookup_policy_text)); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($booking['status'], ['pending', 'confirmed', 'tentative'])): ?>
                        <?php
                        // Whole-party cancel (owner 2026-11-14): every room of this booking with the email the guest proved,
                        // not yet checked in. Company-billed parties are cancelled by the hotel, never online.
                        $lookup_company = rh_guest_cancel_company_billed($pdo, $booking);
                        $lookup_party = $lookup_company ? [] : rh_guest_cancel_party($pdo, $booking, (string)$booking['guest_email']);
                        if (count($lookup_party) <= 1) {
                            $lookup_party = [$booking];
                        }
                        $lookup_room_names = array_column($lookup_group_rooms, 'room_name', 'id');
                        $lookup_fee = 0.0;
                        $lookup_refund = 0.0;
                        $lookup_credit = 0.0;
                        $lookup_has_policy = false;
                        $lookup_lines = [];
                        foreach ($lookup_party as $lookup_pr) {
                            $lookup_pv = rh_cancel_policy_preview($pdo, $lookup_pr);
                            $lookup_has_policy = $lookup_has_policy || $lookup_pv['source'] === 'policy';
                            $lookup_fee = round($lookup_fee + (float)$lookup_pv['amount'], 2);
                            $lookup_refund = round($lookup_refund + (float)$lookup_pv['refund'], 2);
                            $lookup_credit = round($lookup_credit + (float)$lookup_pv['credit'], 2);
                            $lookup_lines[] = ['ref' => (string)$lookup_pr['booking_reference'], 'room' => (string)($lookup_room_names[$lookup_pr['id']] ?? ($lookup_pr['room_name'] ?? '')), 'pv' => $lookup_pv];
                        }
                        $lookup_money = static fn (float $v): string => (string)$currency_symbol . number_format($v, 2);
                        ?>
                        <div class="cancel-section">
                            <h4><i class="fas fa-exclamation-triangle"></i> Need to Cancel?</h4>
                            <?php if ($lookup_company): ?>
                                <p style="color: #666; font-size: 14px; margin-bottom: 0;">
                                    This booking is billed to a company account, so it cannot be cancelled online. Please contact us using the details below.
                                </p>
                            <?php else: ?>
                            <p style="color: #666; font-size: 14px; margin-bottom: 16px;">
                                If you need to cancel your reservation, please provide a reason below.
                            </p>
                            <?php if (count($lookup_party) > 1 || $lookup_has_policy): ?>
                                <div style="font-size: 14px; color: #333; line-height: 1.6; margin-bottom: 14px;">
                                    <?php if (count($lookup_party) > 1): ?>
                                        <p style="margin: 0 0 6px;">Cancelling cancels all <?php echo (int)count($lookup_party); ?> rooms of this booking:</p>
                                    <?php endif; ?>
                                    <ul style="margin: 0 0 8px; padding-left: 18px;">
                                        <?php foreach ($lookup_lines as $lookup_ln): ?>
                                            <li><?php echo htmlspecialchars(($lookup_ln['room'] !== '' ? $lookup_ln['room'] . ' - ' : '') . $lookup_ln['ref']); ?>
                                                <?php if ($lookup_ln['pv']['source'] === 'policy'): ?>
                                                    <small>(<?php echo htmlspecialchars($lookup_ln['pv']['tier_label'] . ((float)$lookup_ln['pv']['amount'] > BALANCE_TOLERANCE ? ': ' . $lookup_ln['pv']['fee_text'] : '')); ?>)</small>
                                                <?php endif; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($lookup_has_policy): ?>
                                        <p style="margin: 0;"><strong>Cancellation fee: <?php echo htmlspecialchars($lookup_fee > BALANCE_TOLERANCE ? $lookup_money($lookup_fee) : 'none'); ?></strong></p>
                                        <p style="margin: 0;">
                                            <?php if ($lookup_credit > BALANCE_TOLERANCE): ?>
                                                Money paid above the fee (<?php echo htmlspecialchars($lookup_money($lookup_credit)); ?>) stays as credit with us.
                                            <?php else: ?>
                                                Refund: <?php echo htmlspecialchars($lookup_money($lookup_refund)); ?><?php echo $lookup_refund > BALANCE_TOLERANCE && rh_refund_rule('guest_self_cancel_refund') !== 'auto' ? ' (checked by our team before it is paid)' : ''; ?>.
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($lookup_fee > BALANCE_TOLERANCE): ?>
                                            <p style="margin: 0; color: #666; font-size: 13px;">The fee is a % of the whole stay (booked rate x nights, incl. VAT and tourism levy) under the cancellation policy shown above. Extras already used stay payable.</p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <form method="POST" id="cancel-form">
                                <input type="hidden" name="cancel_booking" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(pub_csrf_generate('lookup'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="booking_reference" value="<?php echo htmlspecialchars($booking['booking_reference']); ?>">
                                <input type="hidden" name="guest_email" value="<?php echo htmlspecialchars($booking['guest_email']); ?>">
                                <?php if ($lookup_has_policy): ?>
                                    <input type="hidden" name="fee_quoted" value="<?php echo htmlspecialchars(number_format($lookup_fee, 2, '.', ''), ENT_QUOTES); ?>">
                                <?php endif; ?>
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <textarea name="cancel_reason" class="form-control" rows="2" placeholder="Reason for cancellation (optional)" style="width:100%; box-sizing:border-box; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: 'Jost', sans-serif;"></textarea>
                                </div>
                                <?php if ($lookup_has_policy && $lookup_fee > BALANCE_TOLERANCE): ?>
                                    <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 14px; margin-bottom: 12px; cursor: pointer;">
                                        <input type="checkbox" name="accept_fee" value="1" required style="margin-top: 4px; min-width: 18px; min-height: 18px;">
                                        <span>I accept the cancellation fee of <?php echo htmlspecialchars($lookup_money($lookup_fee)); ?>.</span>
                                    </label>
                                <?php endif; ?>
                                <button type="submit" class="btn-cancel-booking">
                                    <i class="fas fa-times"></i> <?php echo count($lookup_party) > 1 ? 'Cancel My Booking (all ' . (int)count($lookup_party) . ' rooms)' : 'Cancel My Booking'; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="contact-info">
                <p>Can't find your booking? Contact us:</p>
                <p>
                    <a href="mailto:<?php echo htmlspecialchars($email_reservations); ?>"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($email_reservations); ?></a>
                    &nbsp;|&nbsp;
                    <a href="tel:<?php echo htmlspecialchars($phone_main); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone_main); ?></a>
                    <?php if ($whatsapp_number): ?>
                        &nbsp;|&nbsp;
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $whatsapp_number); ?>" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>

    </main>
    <?php include 'includes/footer.php'; ?>
    <script src="js/session-handler.js" defer></script>
    <script src="js/scroll-reveal.js" defer></script>
    <script src="js/booking-lookup.js" defer></script>
</body>

</html>
