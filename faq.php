<?php

/**
 * Frequently Asked Questions Page
 * Static guest-facing FAQ. Dynamically pulls site name/contact details from settings.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'includes/booking-functions.php';

$site_name = getSetting('site_name', 'Our Hotel');
$site_email = getSetting('email_main', 'info@example.com');
$site_address = getSetting('address_line1', '');
$site_phone = getSetting('phone_main', '');
$current_page = 'faq';
$page_title = 'Frequently Asked Questions';

// Amenity links — only surface pages whose module/feature is currently on, so
// an installation with the Gym module switched off doesn't advertise a gym or
// a gym schedule. Mirrors the header nav and footer link gating.
$faq_amenities = [];
if (function_exists('isRestaurantEnabled') && isRestaurantEnabled()) {
    $faq_amenities[] = ['label' => 'Restaurant', 'href' => 'restaurant.php'];
}
if (function_exists('isGymEnabled') && isGymEnabled()) {
    $faq_amenities[] = ['label' => 'Gym', 'href' => 'gym.php', 'schedule_href' => 'gym-schedule.php'];
}
if (function_exists('isConferenceEnabled') && isConferenceEnabled()) {
    $faq_amenities[] = ['label' => 'Conference', 'href' => 'conference.php'];
}
if (function_exists('isEventsEnabled') && isEventsEnabled()) {
    $faq_amenities[] = ['label' => 'Events', 'href' => 'events.php'];
}

$seo_data = [
    'title' => "Frequently Asked Questions - $site_name",
    'description' => "Answers to common questions about booking, payment, cancellations, reviews, and amenities at $site_name.",
    'type' => 'website',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php require_once 'includes/seo-meta.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php require_once 'includes/loader.php'; ?>
    <?php require_once 'includes/header.php'; ?>

    <main class="privacy-page" id="main-content">
        <div class="privacy-container">

            <div class="privacy-header">
                <h1><i class="fas fa-question-circle text-old"></i> Frequently Asked Questions</h1>
                <p class="subtitle">Answers to common questions about staying with <?php echo htmlspecialchars($site_name); ?></p>
            </div>

            <!-- Table of Contents -->
            <div class="privacy-nav">
                <h3>Contents</h3>
                <ul>
                    <li><a href="#booking"><i class="fas fa-chevron-right"></i> How Booking Works</a></li>
                    <li><a href="#payment"><i class="fas fa-chevron-right"></i> Payment</a></li>
                    <li><a href="#manage-booking"><i class="fas fa-chevron-right"></i> Managing or Cancelling a Booking</a></li>
                    <li><a href="#reviews"><i class="fas fa-chevron-right"></i> Leaving a Review</a></li>
                    <li><a href="#amenities"><i class="fas fa-chevron-right"></i> On-Site Amenities</a></li>
                    <li><a href="#contact-us"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
                </ul>
            </div>

            <!-- Section 1: How Booking Works -->
            <section class="policy-section" id="booking">
                <h2><i class="fas fa-calendar-check"></i> How Booking Works</h2>
                <h3>How do I make a booking?</h3>
                <p>Submit your stay dates and details through our booking form. Your booking is created as <strong>tentative/pending</strong> while we review availability.</p>
                <h3>When is my booking confirmed?</h3>
                <p>Once your booking has been reviewed and accepted, you will receive a <strong>confirmation email</strong>. Keep this email as your reference for your stay.</p>
                <h3>What if I don't receive a confirmation?</h3>
                <p>If some time has passed and you have not received a confirmation email, please check your spam folder, then contact the front desk using the details below.</p>
            </section>

            <!-- Section 2: Payment -->
            <section class="policy-section" id="payment">
                <h2><i class="fas fa-money-check-alt"></i> Payment</h2>
                <h3>Do I pay online when I book?</h3>
                <p>No. We do not process payment through an online payment gateway at the time of booking.</p>
                <h3>How and when do I pay?</h3>
                <p>Payment is settled manually with the front desk at check-in. Contact the front desk for current accepted payment methods and any deposit details.</p>
            </section>

            <!-- Section 3: Managing or Cancelling a Booking -->
            <section class="policy-section" id="manage-booking">
                <h2><i class="fas fa-edit"></i> Managing or Cancelling a Booking</h2>
                <h3>Can I look up my booking after submitting it?</h3>
                <p>Yes. Use our <a href="booking-lookup.php">Booking Lookup</a> page with your booking reference and details to view your booking status.</p>
                <h3>How do I cancel or change my booking?</h3>
                <p>Start from the <a href="booking-lookup.php">Booking Lookup</a> page to find your booking, then follow the options available there. Contact the front desk for current cancellation policy details.</p>
            </section>

            <!-- Section 4: Leaving a Review -->
            <section class="policy-section" id="reviews">
                <h2><i class="fas fa-star"></i> Leaving a Review</h2>
                <h3>How do I leave a review of my stay?</h3>
                <p>You can submit a review at any time via our <a href="submit-review.php">Submit a Review</a> page.</p>
                <h3>Will I be reminded to leave a review?</h3>
                <p>Yes. Guests automatically receive a pre-arrival reminder email ahead of their stay, and a post-stay review-request email inviting them to share feedback after checkout.</p>
            </section>

            <!-- Section 5: On-Site Amenities -->
            <section class="policy-section" id="amenities">
                <h2><i class="fas fa-concierge-bell"></i> On-Site Amenities</h2>
                <?php if (!empty($faq_amenities)): ?>
                    <h3>What amenities are available on-site?</h3>
                    <p>
                        <?php echo htmlspecialchars($site_name); ?> offers
                        <?php foreach ($faq_amenities as $_i => $_am): ?>
                            <?php if ($_i > 0) { echo $_i === count($faq_amenities) - 1 ? ' and ' : ', '; } ?>
                            <a href="<?php echo htmlspecialchars($_am['href']); ?>"><?php echo htmlspecialchars(strtolower($_am['label'])); ?></a><?php if (!empty($_am['schedule_href'])): ?> (see also the <a href="<?php echo htmlspecialchars($_am['schedule_href']); ?>">gym schedule</a>)<?php endif; ?>
                        <?php endforeach; ?>
                        facilities.
                    </p>
                    <h3>Can I book or enquire about these directly?</h3>
                    <p>Yes. Each amenity has its own page (linked above) where you can view details and submit a booking or enquiry.</p>
                <?php else: ?>
                    <h3>What amenities are available on-site?</h3>
                    <p>Please contact the front desk for the facilities currently available at <?php echo htmlspecialchars($site_name); ?>.</p>
                <?php endif; ?>
                <p>Contact the front desk for current hours, availability, and any specific policy details for these facilities.</p>
            </section>

            <!-- Section 6: Contact -->
            <section class="policy-section" id="contact-us">
                <h2><i class="fas fa-envelope"></i> Contact Us</h2>
                <p>Still have a question? Get in touch using our <a href="contact-us.php">Contact Us</a> page, or reach us directly:</p>
                <div class="contact-card">
                    <p><i class="fas fa-building"></i> <strong><?php echo htmlspecialchars($site_name); ?></strong></p>
                    <?php if (!empty($site_address)): ?>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($site_address); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($site_email)): ?>
                        <p><i class="fas fa-envelope"></i> <a href="mailto:<?php echo htmlspecialchars($site_email); ?>"><?php echo htmlspecialchars($site_email); ?></a></p>
                    <?php endif; ?>
                    <?php if (!empty($site_phone)): ?>
                        <p><i class="fas fa-phone"></i> <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $site_phone)); ?>"><?php echo htmlspecialchars($site_phone); ?></a></p>
                    <?php endif; ?>
                </div>
            </section>

        </div>
    </main>

    <script src="js/main.js" defer></script>

    <?php require_once 'includes/footer.php'; ?>
</body>

</html>
