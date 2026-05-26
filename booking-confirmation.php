<?php

/**
 * Booking Confirmation Page
 * Displays booking details after successful submission
 */

// Start session
session_start();
require_once 'config/base-url.php';
require_once 'config/database.php';

// Get booking reference from URL
$booking_reference = $_GET['ref'] ?? null;

if (!$booking_reference) {
    header('Location: ' . BASE_URL . 'booking.php');
    exit;
}

// Fetch booking details
$split_bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, r.name as room_name, r.image_url as room_image
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.booking_reference = ? OR b.booking_reference LIKE ?
        ORDER BY b.booking_reference ASC
    ");
    $stmt->execute([$booking_reference, $booking_reference . '-%']);
    $split_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $booking = $split_bookings[0] ?? null;

    if (!$booking) {
        $error = "Booking not found.";
    }
} catch (PDOException $e) {
    $error = "Unable to retrieve booking details.";
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$whatsapp_number = getSetting('whatsapp_number');
$payment_policy = getSetting('payment_policy');

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
    $seo_data = [
        'title' => 'Booking Confirmed | ' . $site_name,
        'description' => "Your booking at {$site_name} has been confirmed.",
        'noindex' => true,
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="confirmation-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/alert.php'; ?>

    <main id="main-content">
        <?php if (isset($error)): ?>
            <div class="main-content">
                <div class="confirmation-container">
                    <div class="confirmation-card">
                        <div style="text-align: center;">
                            <i class="fas fa-exclamation-circle" style="font-size: 60px; color: #dc3545; margin-bottom: 20px;"></i>
                            <h1>Error</h1>
                            <p><?php echo htmlspecialchars($error); ?></p>
                            <a href="booking.php" class="btn btn-primary" style="display: inline-block; margin-top: 20px;">
                                Back to Booking
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php
            $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
            $icon_class = $is_tentative ? 'fa-clock' : 'fa-check-circle';
            $icon_class_wrapper = $is_tentative ? 'tentative' : '';
            $heading = $is_tentative ? 'Tentative Booking Received!' : 'Booking Confirmed!';
            $subtitle = $is_tentative
                ? 'Your room has been placed on temporary hold. We\'ll send you a reminder before expiration.'
                : 'Thank you for choosing ' . htmlspecialchars($site_name) . '. Your reservation has been received.';
            $split_count = count($split_bookings);
            $group_total_amount = 0.0;
            $group_guest_count = 0;
            $group_adult_count = 0;
            $group_child_count = 0;
            $group_child_supplement_total = 0.0;
            $group_references = [];

            foreach ($split_bookings as $split_booking) {
                $group_total_amount += (float)($split_booking['total_amount'] ?? 0);
                $group_guest_count += (int)($split_booking['number_of_guests'] ?? 0);
                $group_adult_count += (int)($split_booking['adult_guests'] ?? 0);
                $group_child_count += (int)($split_booking['child_guests'] ?? 0);
                $group_child_supplement_total += (float)($split_booking['child_supplement_total'] ?? 0);
                $group_references[] = $split_booking['booking_reference'];
            }
            ?>
            <div class="main-content">
                <div class="confirmation-container">
                    <div class="success-icon <?php echo $icon_class_wrapper; ?>">
                        <i class="fas <?php echo $icon_class; ?>"></i>
                    </div>

                    <div class="confirmation-card">
                        <h1>
                            <?php echo $heading; ?>
                            <span class="booking-type-indicator <?php echo $is_tentative ? 'tentative' : 'standard'; ?>">
                                <?php echo $is_tentative ? 'Tentative' : 'Standard'; ?>
                            </span>
                        </h1>
                        <p class="subtitle"><?php echo $subtitle; ?></p>

                        <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                            <div class="tentative-badge">
                                <i class="fas fa-hourglass-half"></i>
                                Room on Hold
                            </div>
                        <?php endif; ?>

                        <div class="booking-reference-box">
                            <label><?php echo $split_count > 1 ? 'Primary Booking Reference' : 'Booking Reference'; ?></label>
                            <div class="reference-number"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
                            <?php if ($split_count > 1): ?>
                                <p style="margin-top: 10px; color: var(--color-text-secondary); font-size: 14px;">
                                    Group references: <?php echo htmlspecialchars(implode(', ', $group_references)); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="booking-details-grid">
                            <div class="detail-item full-width">
                                <label>Room</label>
                                <div class="value">
                                    <?php echo htmlspecialchars($booking['room_name']); ?>
                                    <?php if ($split_count > 1): ?>
                                        (<?php echo $split_count; ?> rooms)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <label>Guest Name</label>
                                <div class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                            </div>
                            <div class="detail-item full-width">
                                <label>Email</label>
                                <div class="value"><?php echo htmlspecialchars($booking['guest_email']); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Check-in</label>
                                <div class="value"><?php echo date('M j, Y', strtotime($booking['check_in_date'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Check-out</label>
                                <div class="value"><?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Number of Nights</label>
                                <div class="value"><?php echo $booking['number_of_nights']; ?> <?php echo $booking['number_of_nights'] == 1 ? 'night' : 'nights'; ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Number of Guests</label>
                                <div class="value"><?php echo $group_guest_count; ?> <?php echo $group_guest_count == 1 ? 'guest' : 'guests'; ?></div>
                            </div>
                            <?php
                            $child_guests = $group_child_count;
                            $adult_guests = $group_adult_count > 0 ? $group_adult_count : max(1, $group_guest_count - $child_guests);
                            $child_supplement_total = $group_child_supplement_total;
                            ?>
                            <div class="detail-item">
                                <label>Guest Split</label>
                                <div class="value">
                                    <?php echo $adult_guests; ?> adult<?php echo $adult_guests === 1 ? '' : 's'; ?>
                                    <?php if ($child_guests > 0): ?>
                                        + <?php echo $child_guests; ?> child<?php echo $child_guests === 1 ? '' : 'ren'; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($child_guests > 0): ?>
                                <div class="detail-item">
                                    <label>Child Supplement</label>
                                    <div class="value"><?php echo $currency_symbol; ?><?php echo number_format($child_supplement_total, 0); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="detail-item full-width">
                                <label>Total Amount</label>
                                <div class="value" style="font-size: 24px; color: var(--gold);">
                                    <?php echo $currency_symbol; ?><?php echo number_format($group_total_amount, 0); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <label>Check-in Time</label>
                                <div class="value"><?php echo htmlspecialchars(getSetting('check_in_time', '2:00 PM')); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Check-out Time</label>
                                <div class="value"><?php echo htmlspecialchars(getSetting('check_out_time', '11:00 AM')); ?></div>
                            </div>
                        </div>

                        <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                            <div class="tentative-info-box">
                                <h3><i class="fas fa-clock"></i> Tentative Booking Details</h3>
                                <p>
                                    Your room has been placed on temporary hold. You'll receive a reminder email before expiration.
                                    To confirm this booking, please contact us before the expiration time.
                                </p>
                                <div class="expires-at">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Expires: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="payment-info">
                            <h3><i class="fas fa-info-circle"></i> <?php echo $is_tentative ? 'Next Steps' : 'Payment Information'; ?></h3>
                            <p>
                                <?php if ($is_tentative): ?>
                                    <strong>1. Confirm your booking:</strong> Contact us before expiration to convert this to a confirmed reservation.<br>
                                    <strong>2. Payment:</strong> Once confirmed, payment of <?php echo $currency_symbol . number_format($group_total_amount, 0); ?> will be collected at check-in.<br>
                                    <strong>3. Reminder:</strong> You'll receive a reminder email <?php echo (int)getSetting('tentative_reminder_hours', 24); ?> hours before expiration.<br>
                                    <strong>4. Questions?</strong> Contact us anytime at <?php echo htmlspecialchars($phone_main); ?>.
                                <?php else: ?>
                                    <?php echo getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . $currency_symbol . number_format($group_total_amount, 0) . '</strong> with you.'); ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="action-buttons">
                            <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>" class="btn btn-secondary">
                                <i class="fas fa-phone"></i> Call Hotel
                            </a>
                            <a href="https://wa.me/<?php echo $whatsapp_number; ?>?text=Hi, I have a booking (<?php echo $booking['booking_reference']; ?>)" class="btn btn-whatsapp" target="_blank">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </a>
                            <a href="mailto:<?php echo $email_reservations; ?>?subject=Booking <?php echo $booking['booking_reference']; ?>" class="btn btn-secondary">
                                <i class="fas fa-envelope"></i> Email
                            </a>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <a href="index.php" class="btn btn-primary">
                                <i class="fas fa-home"></i> Back to Home
                            </a>
                        </div>


                        <div class="next-steps">
                            <h3>What Happens Next?</h3>
                            <ol>
                                <?php if ($is_tentative): ?>
                                    <li><strong>Tentative booking email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> - please check your inbox</li>
                                    <li><strong>Room is on hold</strong> until <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?></li>
                                    <li>You'll receive a <strong>reminder email</strong> <?php echo (int)getSetting('tentative_reminder_hours', 24); ?> hours before expiration</li>
                                    <li><strong>Contact us</strong> before expiration to confirm your booking and secure your reservation</li>
                                    <li>Once confirmed, payment of <strong><?php echo $currency_symbol; ?><?php echo number_format($group_total_amount, 0); ?></strong> will be collected at check-in</li>
                                <?php else: ?>
                                    <li><strong>Confirmation email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> - please check your inbox</li>
                                    <li>Our reception team will review your booking and may contact you to confirm details</li>
                                    <li>Please save your booking reference: <strong><?php echo $booking['booking_reference']; ?></strong></li>
                                    <li>Arrive on your check-in date and present your booking reference at reception</li>
                                    <li>Payment of <strong><?php echo $currency_symbol; ?><?php echo number_format($group_total_amount, 0); ?></strong> will be collected at check-in</li>
                                <?php endif; ?>
                            </ol>
                        </div>

                        <p style="text-align: center; margin-top: 32px; color: #999; font-size: 13px;">
                            <i class="fas fa-question-circle"></i> Questions? Contact us at <?php echo htmlspecialchars($phone_main); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
