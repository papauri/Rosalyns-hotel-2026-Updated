<?php

/**
 * Contact Us Page
 * Dedicated contact page with form, map, and contact info from database.
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'config/email.php';
require_once 'includes/validation.php';
require_once 'includes/modal.php';
require_once 'includes/section-headers.php';
require_once 'includes/public-csrf.php';

// Fetch site settings
$site_name   = getSetting('site_name');
$site_logo   = getSetting('site_logo');
$email_main  = getSetting('email_main');
$phone_main  = getSetting('phone_main');

// Fetch contact settings
$contact = [
    'phone_main'     => getSetting('phone_main'),
    'phone_secondary' => getSetting('phone_secondary'),
    'email_main'     => getSetting('email_main'),
    'email_reservations' => getSetting('email_reservations'),
    'address_line1'  => getSetting('address_line1'),
    'address_line2'  => getSetting('address_line2'),
    'address_region' => getSetting('address_region'),
    'address_country' => getSetting('address_country'),
    'working_hours'  => getSetting('working_hours'),
    'whatsapp_number' => getSetting('whatsapp_number'),
    'google_maps_embed' => getSetting('google_maps_embed'),
];

// Fetch social links
$social = [
    'facebook_url' => getSetting('facebook_url'),
    'instagram_url' => getSetting('instagram_url'),
    'twitter_url'   => getSetting('twitter_url'),
    'linkedin_url' => getSetting('linkedin_url'),
];

// Ensure contact_inquiries table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_inquiries (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        reference_number VARCHAR(30) NOT NULL,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        consent TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reference (reference_number),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log("contact_inquiries table check: " . $e->getMessage());
}

// Generate per-session CSRF token for this form
$contact_csrf_token = pub_csrf_generate('contact');

// Handle contact form submission
$formSuccess = false;
$formError   = '';
$formReference = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_form'])) {
    // CSRF validation
    if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'contact')) {
        $formError = 'Security token invalid. Please refresh the page and try again.';
    } elseif (!pub_rate_limit('contact_form', 5, 600)) {
        // Rate limiting: 5 submissions per 10 minutes per session
        $formError = 'Too many submissions. Please wait a few minutes before trying again.';
    } else {

        $validation_errors = [];
        $sanitized = [];

        // Validate name
        $name_v = validateName($_POST['name'] ?? '', 2, true);
        if (!$name_v['valid']) {
            $validation_errors['name'] = $name_v['error'];
        } else {
            $sanitized['name'] = sanitizeString($name_v['value'], 150);
        }

        // Validate email
        $email_v = validateEmail($_POST['email'] ?? '');
        if (!$email_v['valid']) {
            $validation_errors['email'] = $email_v['error'];
        } else {
            $sanitized['email'] = $_POST['email'];
        }

        // Validate phone (optional)
        if (!empty($_POST['phone'])) {
            $phone_v = validatePhone($_POST['phone']);
            if (!$phone_v['valid']) {
                $validation_errors['phone'] = $phone_v['error'];
            } else {
                $sanitized['phone'] = $phone_v['sanitized'];
            }
        } else {
            $sanitized['phone'] = null;
        }

        // Validate subject
        $subject_v = validateText($_POST['subject'] ?? '', 2, 255, true);
        if (!$subject_v['valid']) {
            $validation_errors['subject'] = $subject_v['error'];
        } else {
            $sanitized['subject'] = sanitizeString($subject_v['value'], 255);
        }

        // Validate message
        $message_v = validateText($_POST['message'] ?? '', 10, 5000, true);
        if (!$message_v['valid']) {
            $validation_errors['message'] = $message_v['error'];
        } else {
            $sanitized['message'] = sanitizeString($message_v['value'], 5000);
        }

        // Consent
        if (!isset($_POST['consent'])) {
            $validation_errors['consent'] = 'You must accept consent to proceed.';
        }

        if (!empty($validation_errors)) {
            $msgs = [];
            foreach ($validation_errors as $field => $msg) {
                $msgs[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $msg;
            }
            $formError = implode('; ', $msgs);
        } else {
            $formReference = 'CTQ-' . strtoupper(substr(uniqid(), -8));

            try {
                $stmt = $pdo->prepare("
                INSERT INTO contact_inquiries (reference_number, name, email, phone, subject, message, consent, status)
                VALUES (?, ?, ?, ?, ?, ?, 1, 'new')
            ");
                $stmt->execute([
                    $formReference,
                    $sanitized['name'],
                    $sanitized['email'],
                    $sanitized['phone'],
                    $sanitized['subject'],
                    $sanitized['message'],
                ]);

                $formSuccess = true;

                // Send notification email to hotel
                if (function_exists('sendEmail')) {
                    $adminEmail = $email_main;
                    if (!empty($adminEmail)) {
                        $emailBody = "<h2>New Contact Inquiry</h2>";
                        $emailBody .= "<p><strong>Reference:</strong> {$formReference}</p>";
                        $emailBody .= "<p><strong>Name:</strong> " . htmlspecialchars($sanitized['name']) . "</p>";
                        $emailBody .= "<p><strong>Email:</strong> " . htmlspecialchars($sanitized['email']) . "</p>";
                        $emailBody .= "<p><strong>Phone:</strong> " . htmlspecialchars($sanitized['phone'] ?? 'N/A') . "</p>";
                        $emailBody .= "<p><strong>Subject:</strong> " . htmlspecialchars($sanitized['subject']) . "</p>";
                        $emailBody .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($sanitized['message'])) . "</p>";

                        sendEmail(
                            $adminEmail,
                            getSetting('site_name', 'Hotel'),
                            'New Contact Inquiry [' . $formReference . '] - ' . htmlspecialchars($sanitized['subject']),
                            $emailBody
                        );
                    }
                }
            } catch (PDOException $e) {
                error_log("Contact form error: " . $e->getMessage());
                $formError = 'There was an error submitting your inquiry. Please try again or call us directly.';
            }
        } // end if (!empty($validation_errors)) else
    } // end CSRF/rate-limit else block
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Contact Us - ' . $site_name,
        'description' => "Get in touch with {$site_name}. Contact us for reservations, inquiries, or any questions about our services.",
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    </noscript>

    <!-- Main CSS -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/sections/contact.css">
</head>

<body class="contact-us-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <!-- Hero Section -->
        <?php include 'includes/hero.php'; ?>

        <!-- Contact Section -->
        <section class="contact-page-section" id="contact-info">
            <div class="container">
                <?php renderSectionHeader('contact_main', 'contact-us', [
                    'label' => 'Get In Touch',
                    'title' => 'Contact Us',
                    'description' => 'We are here to help. Reach out to us with any questions, reservations, or special requests.'
                ], 'text-center'); ?>

                <div class="contact-grid" id="contact-grid">
                    <!-- Left: Contact Info -->
                    <div class="contact-info-card revealed">
                        <h3>Our Contact Details</h3>

                        <?php if (!empty($contact['phone_main'])): ?>
                            <div class="contact-detail">
                                <div class="contact-detail-icon"><i class="fas fa-phone-alt"></i></div>
                                <div class="contact-detail-content">
                                    <strong>Phone</strong>
                                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['phone_main'])); ?>"><?php echo htmlspecialchars($contact['phone_main']); ?></a>
                                    <?php if (!empty($contact['phone_secondary'])): ?>
                                        <br><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['phone_secondary'])); ?>"><?php echo htmlspecialchars($contact['phone_secondary']); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($contact['email_main'])): ?>
                            <div class="contact-detail">
                                <div class="contact-detail-icon"><i class="fas fa-envelope"></i></div>
                                <div class="contact-detail-content">
                                    <strong>Email</strong>
                                    <a href="mailto:<?php echo htmlspecialchars($contact['email_main']); ?>"><?php echo htmlspecialchars($contact['email_main']); ?></a>
                                    <?php if (!empty($contact['email_reservations']) && $contact['email_reservations'] !== $contact['email_main']): ?>
                                        <br><a href="mailto:<?php echo htmlspecialchars($contact['email_reservations']); ?>">Reservations: <?php echo htmlspecialchars($contact['email_reservations']); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($contact['address_line1'])): ?>
                            <div class="contact-detail">
                                <div class="contact-detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                                <div class="contact-detail-content">
                                    <strong>Address</strong>
                                    <a href="https://www.google.com/maps/search/<?php echo urlencode($contact['address_line1'] . ' ' . ($contact['address_line2'] ?? '')); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($contact['address_line1']); ?>
                                        <?php if (!empty($contact['address_line2'])): ?><br><?php echo htmlspecialchars($contact['address_line2']); ?><?php endif; ?>
                                            <?php if (!empty($contact['address_region'])): ?><br><?php echo htmlspecialchars($contact['address_region']); ?><?php endif; ?>
                                                <?php if (!empty($contact['address_country'])): ?>, <?php echo htmlspecialchars($contact['address_country']); ?><?php endif; ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($contact['working_hours'])): ?>
                            <div class="contact-detail">
                                <div class="contact-detail-icon"><i class="fas fa-clock"></i></div>
                                <div class="contact-detail-content">
                                    <strong>Working Hours</strong>
                                    <span><?php echo htmlspecialchars($contact['working_hours']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Quick Action Buttons -->
                        <div class="contact-quick-actions">
                            <?php if (!empty($contact['phone_main'])): ?>
                                <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['phone_main'])); ?>" class="btn-call">
                                    <i class="fas fa-phone-alt"></i> Call Us
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($contact['email_main'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($contact['email_main']); ?>" class="btn-email">
                                    <i class="fas fa-envelope"></i> Email Us
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($contact['whatsapp_number'])): ?>
                                <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['whatsapp_number'])); ?>" target="_blank" rel="noopener" class="btn-whatsapp">
                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Social Links -->
                        <?php
                        $hasSocial = !empty($social['facebook_url']) || !empty($social['instagram_url']) || !empty($social['twitter_url']) || !empty($social['linkedin_url']);
                        if ($hasSocial):
                        ?>
                            <h3 style="margin-top: 30px;">Follow Us</h3>
                            <div class="contact-social-links">
                                <?php if (!empty($social['facebook_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($social['facebook_url']); ?>" target="_blank" rel="noopener" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($social['instagram_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($social['instagram_url']); ?>" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($social['twitter_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($social['twitter_url']); ?>" target="_blank" rel="noopener" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                                <?php endif; ?>
                                <?php if (!empty($social['linkedin_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($social['linkedin_url']); ?>" target="_blank" rel="noopener" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Contact Form -->
                    <div class="contact-form-card revealed">
                        <h3>Send Us a Message</h3>

                        <?php if ($formSuccess): ?>
                            <div class="contact-form-success">
                                <i class="fas fa-check-circle"></i>
                                <h3>Message Sent Successfully!</h3>
                                <p>Thank you for reaching out. Our team will get back to you within 24 hours.</p>
                                <div class="ref-badge">Reference: <?php echo htmlspecialchars($formReference); ?></div>
                            </div>
                        <?php else: ?>

                            <?php if (!empty($formError)): ?>
                                <div class="contact-form-error">
                                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($formError); ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" class="contact-form" id="contactForm">
                                <input type="hidden" name="contact_form" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($contact_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="form-group">
                                    <label for="contact-name">Full Name *</label>
                                    <input type="text" id="contact-name" name="name" required minlength="2" maxlength="150" placeholder="Your full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="contact-email">Email Address *</label>
                                    <input type="email" id="contact-email" name="email" required maxlength="255" placeholder="your@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="contact-phone">Phone Number</label>
                                        <input type="tel" id="contact-phone" name="phone" maxlength="50" placeholder="+1 234 567 890" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="contact-subject">Subject *</label>
                                        <select id="contact-subject" name="subject" required>
                                            <option value="">Select a subject</option>
                                            <option value="General Inquiry" <?php echo ($_POST['subject'] ?? '') === 'General Inquiry' ? 'selected' : ''; ?>>General Inquiry</option>
                                            <option value="Room Reservation" <?php echo ($_POST['subject'] ?? '') === 'Room Reservation' ? 'selected' : ''; ?>>Room Reservation</option>
                                            <option value="Conference Booking" <?php echo ($_POST['subject'] ?? '') === 'Conference Booking' ? 'selected' : ''; ?>>Conference Booking</option>
                                            <option value="Restaurant Reservation" <?php echo ($_POST['subject'] ?? '') === 'Restaurant Reservation' ? 'selected' : ''; ?>>Restaurant Reservation</option>
                                            <option value="Gym & Wellness" <?php echo ($_POST['subject'] ?? '') === 'Gym & Wellness' ? 'selected' : ''; ?>>Gym & Wellness</option>
                                            <option value="Events" <?php echo ($_POST['subject'] ?? '') === 'Events' ? 'selected' : ''; ?>>Events</option>
                                            <option value="Feedback" <?php echo ($_POST['subject'] ?? '') === 'Feedback' ? 'selected' : ''; ?>>Feedback</option>
                                            <option value="Complaint" <?php echo ($_POST['subject'] ?? '') === 'Complaint' ? 'selected' : ''; ?>>Complaint</option>
                                            <option value="Other" <?php echo ($_POST['subject'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="contact-message">Message *</label>
                                    <textarea id="contact-message" name="message" required minlength="10" maxlength="5000" placeholder="How can we help you?"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="consent-label">
                                        <input type="checkbox" name="consent" required>
                                        <span>I agree to be contacted regarding this inquiry and accept the hotel's privacy policy.</span>
                                    </label>
                                </div>

                                <button type="submit" class="btn-submit">
                                    <i class="fas fa-paper-plane"></i> Send Message
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Map Section -->
        <?php if (!empty($contact['google_maps_embed']) || !empty($contact['address_line1'])): ?>
            <section class="contact-map-section" id="map">
                <div class="container">
                    <div class="contact-map-wrap" id="contact-map-wrap">
                        <?php if (!empty($contact['google_maps_embed'])): ?>
                            <?php echo $contact['google_maps_embed']; ?>
                        <?php else: ?>
                            <iframe
                                src="https://maps.google.com/maps?q=<?php echo urlencode($contact['address_line1'] . ' ' . ($contact['address_line2'] ?? '')); ?>&output=embed"
                                allowfullscreen
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                title="Hotel Location Map"></iframe>
                        <?php endif; ?>
                    </div>
                </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Scripts -->
        <script src="js/modal.js" defer></script>
    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>
