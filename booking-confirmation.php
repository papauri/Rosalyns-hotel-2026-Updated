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

// Fetch extras / packages for this booking group
$booking_packages = [];
if (!isset($error) && !empty($split_bookings)) {
    try {
        $bookingIds = array_column($split_bookings, 'id');
        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $pkgStmt = $pdo->prepare(
            "SELECT package_name, price_type, price_amount, quantity, total_cost
             FROM booking_packages WHERE booking_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $pkgStmt->execute($bookingIds);
        $booking_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Confirmation page: packages query error: " . $e->getMessage());
    }
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
        <div class="conf-wrap">
        <?php if (isset($error)): ?>
            <div class="conf-card">
                <div class="conf-card-body conf-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <h1>Booking Not Found</h1>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <a href="booking.php" class="conf-btn conf-btn--primary">Back to Booking</a>
                </div>
            </div>
        <?php else: ?>
            <?php
            $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
            $icon_class   = $is_tentative ? 'fa-clock' : 'fa-check-circle';
            $heading      = $is_tentative ? 'Tentative Booking Received' : 'Booking Confirmed';
            $subtitle     = $is_tentative
                ? 'Your room has been placed on a temporary hold. We\'ll send you a reminder before it expires.'
                : 'Thank you for choosing ' . htmlspecialchars($site_name) . '. Your reservation has been received and is being reviewed.';
            $split_count  = count($split_bookings);
            $group_total_amount           = 0.0;
            $group_guest_count            = 0;
            $group_adult_count            = 0;
            $group_child_count            = 0;
            $group_child_supplement_total = 0.0;
            $group_references             = [];
            foreach ($split_bookings as $split_booking) {
                $group_total_amount           += (float)($split_booking['total_amount'] ?? 0);
                $group_guest_count            += (int)($split_booking['number_of_guests'] ?? 0);
                $group_adult_count            += (int)($split_booking['adult_guests'] ?? 0);
                $group_child_count            += (int)($split_booking['child_guests'] ?? 0);
                $group_child_supplement_total += (float)($split_booking['child_supplement_total'] ?? 0);
                $group_references[]            = $split_booking['booking_reference'];
            }
            $child_guests   = $group_child_count;
            $adult_guests   = $group_adult_count > 0 ? $group_adult_count : max(1, $group_guest_count - $child_guests);
            $nights         = (int)$booking['number_of_nights'];
            $check_in_fmt   = date('D, M j, Y', strtotime($booking['check_in_date']));
            $check_out_fmt  = date('D, M j, Y', strtotime($booking['check_out_date']));
            $check_in_time  = htmlspecialchars(getSetting('check_in_time', '2:00 PM'));
            $check_out_time = htmlspecialchars(getSetting('check_out_time', '11:00 AM'));
            ?>

            <!-- ── Hero ── -->
            <div class="conf-hero">
                <div class="conf-icon-ring <?php echo $is_tentative ? 'tentative' : ''; ?>">
                    <i class="fas <?php echo $icon_class; ?>"></i>
                </div>
                <h1 class="conf-heading"><?php echo $heading; ?></h1>
                <p class="conf-subtitle"><?php echo $subtitle; ?></p>
                <span class="conf-type-pill <?php echo $is_tentative ? 'tentative' : 'standard'; ?>">
                    <i class="fas <?php echo $is_tentative ? 'fa-clock' : 'fa-check-circle'; ?>"></i>
                    <?php echo $is_tentative ? 'Tentative Hold' : 'Standard Booking'; ?>
                </span>
            </div>

            <!-- ── Expiry banner (tentative only) ── -->
            <?php if ($is_tentative && !empty($booking['tentative_expires_at'])): ?>
            <div class="conf-expiry-banner">
                <i class="fas fa-hourglass-half"></i>
                <div>
                    <strong>Room on Hold</strong>
                    Expires <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?>
                    &nbsp;·&nbsp; Contact us before expiration to confirm.
                </div>
            </div>
            <?php endif; ?>

            <!-- ── 2-col body grid: main (stay + steps) | aside (ref + guest) ── -->
            <div class="conf-body-grid">

                <!-- LEFT / MAIN column -->
                <div class="conf-col-main">

                    <!-- Stay details -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-bed"></i> Your Stay</div>
                            <div class="conf-room-name">
                                <?php echo htmlspecialchars($booking['room_name']); ?>
                                <?php if ($split_count > 1): ?><span style="font-size:0.85rem;color:#736149;"> (<?php echo $split_count; ?> rooms)</span><?php endif; ?>
                            </div>
                            <div class="conf-dates-row">
                                <div class="conf-date-block">
                                    <div class="conf-date-label"><i class="fas fa-sign-in-alt"></i> Check-in</div>
                                    <div class="conf-date-val"><?php echo $check_in_fmt; ?></div>
                                    <div class="conf-date-time">from <?php echo $check_in_time; ?></div>
                                </div>
                                <div class="conf-nights-pill">
                                    <span class="conf-nights-num"><?php echo $nights; ?></span>
                                    <span class="conf-nights-lbl"><?php echo $nights === 1 ? 'night' : 'nights'; ?></span>
                                </div>
                                <div class="conf-date-block conf-date-block--right">
                                    <div class="conf-date-label"><i class="fas fa-sign-out-alt"></i> Check-out</div>
                                    <div class="conf-date-val"><?php echo $check_out_fmt; ?></div>
                                    <div class="conf-date-time">by <?php echo $check_out_time; ?></div>
                                </div>
                            </div>
                            <div class="conf-stay-meta">
                                <div class="conf-guests-line">
                                    <i class="fas fa-users"></i>
                                    <?php echo $adult_guests; ?> adult<?php echo $adult_guests === 1 ? '' : 's'; ?>
                                    <?php if ($child_guests > 0): ?> + <?php echo $child_guests; ?> child<?php echo $child_guests === 1 ? '' : 'ren'; ?><?php endif; ?>
                                </div>
                                <div class="conf-total-block">
                                    <div class="conf-total-label">Total</div>
                                    <div class="conf-total-amount"><?php echo $currency_symbol; ?><?php echo number_format($group_total_amount, 0); ?></div>
                                </div>
                            </div>

                            <?php
                            // Occupancy type + rate plan meta row
                            $occupancy_type  = trim((string)($booking['occupancy_type'] ?? ''));
                            $rate_plan_label = trim((string)($booking['rate_plan_label'] ?? ''));
                            if ($occupancy_type !== '' || $rate_plan_label !== ''):
                            ?>
                            <div class="conf-stay-tags">
                                <?php if ($occupancy_type !== ''): ?>
                                    <span class="conf-stay-tag"><i class="fas fa-bed"></i> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $occupancy_type))); ?></span>
                                <?php endif; ?>
                                <?php if ($rate_plan_label !== ''): ?>
                                    <span class="conf-stay-tag"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($rate_plan_label); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($booking_packages)): ?>
                            <div class="conf-extras">
                                <div class="conf-extras-title"><i class="fas fa-plus-circle"></i> Extras &amp; Packages</div>
                                <?php foreach ($booking_packages as $pkg):
                                    $suffix = $pkg['price_type'] === 'per_night' ? '/night' : '';
                                    $qty    = (int)$pkg['quantity'];
                                ?>
                                <div class="conf-extras-row">
                                    <span class="conf-extras-name">
                                        <?php echo htmlspecialchars($pkg['package_name']); ?>
                                        <?php if ($qty > 1 || $suffix): ?>
                                            <em><?php echo ($qty > 1 ? "×{$qty}" : '') . ($suffix ? " {$suffix}" : ''); ?></em>
                                        <?php endif; ?>
                                    </span>
                                    <span class="conf-extras-cost"><?php echo $currency_symbol . number_format((float)$pkg['total_cost'], 0); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- Payment & Next steps -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-list-check"></i> <?php echo $is_tentative ? 'What Happens Next' : 'Payment &amp; Next Steps'; ?></div>
                            <ol class="conf-steps">
                                <?php if ($is_tentative): ?>
                                    <li><strong>Tentative booking email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> — please check your inbox.</li>
                                    <li><strong>Room is on hold</strong> until <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?>.</li>
                                    <li>You'll receive a <strong>reminder email</strong> <?php echo (int)getSetting('tentative_reminder_hours', 24); ?> hours before expiration.</li>
                                    <li><strong>Contact us</strong> before expiration to convert this to a confirmed reservation.</li>
                                    <li>Once confirmed, payment of <strong><?php echo $currency_symbol . number_format($group_total_amount, 0); ?></strong> is collected at check-in.</li>
                                <?php else: ?>
                                    <li><strong>Confirmation email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> — please check your inbox.</li>
                                    <li>Our team will review your booking and may contact you to confirm details.</li>
                                    <li>Save your reference number: <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong>.</li>
                                    <li>Arrive on your check-in date and present your reference at reception.</li>
                                    <li>Payment of <strong><?php echo $currency_symbol . number_format($group_total_amount, 0); ?></strong> is collected at check-in.</li>
                                <?php endif; ?>
                            </ol>
                            <?php if (!$is_tentative && $payment_policy): ?>
                            <p class="conf-payment-text" style="margin-top:16px;padding-top:14px;border-top:1px solid rgba(139,115,85,0.08);">
                                <?php echo $payment_policy; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /.conf-col-main -->

                <!-- RIGHT / ASIDE column -->
                <div class="conf-col-aside">

                    <!-- Booking reference -->
                    <div class="conf-card conf-ref-card">
                        <div class="conf-ref-body">
                            <div class="conf-ref-label"><?php echo $split_count > 1 ? 'Primary Booking Reference' : 'Booking Reference'; ?></div>
                            <div class="conf-ref-number"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
                            <?php if ($split_count > 1): ?>
                                <div class="conf-ref-group">Group references: <?php echo htmlspecialchars(implode(' · ', $group_references)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Guest details -->
                    <div class="conf-card">
                        <div class="conf-card-body">
                            <div class="conf-card-title"><i class="fas fa-user"></i> Guest Details</div>
                            <div class="conf-detail-list">
                                <div class="conf-detail-row">
                                    <span>Name</span>
                                    <span><?php echo htmlspecialchars($booking['guest_name']); ?></span>
                                </div>
                                <div class="conf-detail-row">
                                    <span>Email</span>
                                    <span><?php echo htmlspecialchars($booking['guest_email']); ?></span>
                                </div>
                                <?php if ($child_guests > 0): ?>
                                <div class="conf-detail-row">
                                    <span>Child Supplement</span>
                                    <span><?php echo $currency_symbol . number_format($group_child_supplement_total, 0); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="conf-detail-row conf-detail-row--total">
                                    <span>Total Amount</span>
                                    <span><?php echo $currency_symbol . number_format($group_total_amount, 0); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /.conf-col-aside -->

            </div><!-- /.conf-body-grid -->

            <!-- ── Action buttons ── -->
            <div class="conf-actions">
                <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-phone"></i> Call Hotel
                </a>
                <a href="https://wa.me/<?php echo rawurlencode(preg_replace('/[^0-9+]/', '', (string)$whatsapp_number)); ?>?text=<?php echo rawurlencode('Hi, I have a booking (' . $booking['booking_reference'] . ')'); ?>" class="conf-btn conf-btn--whatsapp" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
                <a href="mailto:<?php echo $email_reservations; ?>?subject=Booking+<?php echo $booking['booking_reference']; ?>" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-envelope"></i> Email
                </a>
                <button onclick="window.print()" class="conf-btn conf-btn--ghost">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="index.php" class="conf-btn conf-btn--home">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>

            <p class="conf-contact-note">
                <i class="fas fa-question-circle"></i>
                Questions? Call us at <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>"><?php echo htmlspecialchars($phone_main); ?></a>
            </p>

        <?php endif; ?>
        </div><!-- /.conf-wrap -->
    </main>

    <script src="js/main.js"></script>

    <?php include 'includes/footer.php'; ?>
</body>

</html>
