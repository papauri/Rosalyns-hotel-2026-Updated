<?php

/**
 * Conference Booking Page with Enhanced Security
 * Features:
 * - CSRF protection
 * - Secure session management
 * - Input validation
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'includes/booking-functions.php';
require_once 'config/email.php';
require_once 'includes/modal.php';
require_once 'includes/validation.php';
require_once 'includes/section-headers.php';
require_once 'includes/public-csrf.php';

$conferenceEnabled = isConferenceEnabled();

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $policies = [];
}

// Fetch active conference rooms
try {
    $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE is_active = 1 ORDER BY display_order ASC");
    $stmt->execute();
    $conference_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('applyManagedMediaOverrides') && !empty($conference_rooms)) {
        foreach ($conference_rooms as &$conferenceRoom) {
            $conferenceRoom = applyManagedMediaOverrides($conferenceRoom, 'conference_rooms', $conferenceRoom['id'] ?? '', ['image_path']);
        }
        unset($conferenceRoom);
    }
} catch (PDOException $e) {
    $conference_rooms = [];
    error_log("Conference rooms fetch error: " . $e->getMessage());
}

// Generate per-session CSRF token for this form
$conference_csrf_token = pub_csrf_generate('conference');

// Handle inquiry submission
$inquiry_success = false;
$inquiry_error = '';
$inquiry_email_warning = '';
$success_reference = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF validation — must pass before any processing
        if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'conference')) {
            throw new Exception('Security token invalid. Please refresh the page and try again.');
        }

        // Initialize validation errors array
        $validation_errors = [];
        $sanitized_data = [];

        // Validate conference_room_id
        $room_validation = validateConferenceRoomId($_POST['conference_room_id'] ?? '');
        if (!$room_validation['valid']) {
            $validation_errors['conference_room_id'] = $room_validation['error'];
        } else {
            $sanitized_data['conference_room_id'] = $room_validation['room']['id'];
        }

        // Validate company_name
        $company_validation = validateText($_POST['company_name'] ?? '', 2, 200, true);
        if (!$company_validation['valid']) {
            $validation_errors['company_name'] = $company_validation['error'];
        } else {
            $sanitized_data['company_name'] = sanitizeString($company_validation['value'], 200);
        }

        // Validate contact_person
        $contact_validation = validateName($_POST['contact_person'] ?? '', 2, true);
        if (!$contact_validation['valid']) {
            $validation_errors['contact_person'] = $contact_validation['error'];
        } else {
            $sanitized_data['contact_person'] = sanitizeString($contact_validation['value'], 100);
        }

        // Validate email
        $email_validation = validateEmail($_POST['email'] ?? '');
        if (!$email_validation['valid']) {
            $validation_errors['email'] = $email_validation['error'];
        } else {
            // Use validated email directly - no need to sanitize as validation already ensures it's safe
            $sanitized_data['email'] = $_POST['email'];
        }

        // Validate phone
        $phone_validation = validatePhone($_POST['phone'] ?? '');
        if (!$phone_validation['valid']) {
            $validation_errors['phone'] = $phone_validation['error'];
        } else {
            $sanitized_data['phone'] = $phone_validation['sanitized'];
        }

        // Get booking time buffer from settings (default to 60 minutes if not set)
        $booking_buffer = (int)getSetting('booking_time_buffer_minutes', 60);

        // Validate event_date and start_time together
        $datetime_validation = validateDateTime(
            $_POST['event_date'] ?? '',
            $_POST['start_time'] ?? '',
            false,  // Don't allow past dates
            $booking_buffer  // Use configurable buffer
        );

        if (!$datetime_validation['valid']) {
            $validation_errors['event_date'] = $datetime_validation['error'];
        } else {
            $sanitized_data['event_date'] = $datetime_validation['datetime']->format('Y-m-d');
            $sanitized_data['start_time'] = $datetime_validation['datetime']->format('H:i');
        }

        // Validate end_time separately
        $end_time_validation = validateTime($_POST['end_time'] ?? '');
        if (!$end_time_validation['valid']) {
            $validation_errors['end_time'] = $end_time_validation['error'];
        } else {
            $sanitized_data['end_time'] = $end_time_validation['time'];
        }

        // Validate time range — only when both start and end time passed their own validations
        if (empty($validation_errors['event_date']) && empty($validation_errors['end_time'])
            && isset($sanitized_data['start_time'], $sanitized_data['end_time'])) {
            $time_range_validation = validateTimeRange($sanitized_data['start_time'], $sanitized_data['end_time']);
            if (!$time_range_validation['valid']) {
                $validation_errors['time_range'] = $time_range_validation['error'];
            }
        }

        // Validate number_of_attendees
        $attendees_validation = validateNumber($_POST['number_of_attendees'] ?? '', 1, 500, true);
        if (!$attendees_validation['valid']) {
            $validation_errors['number_of_attendees'] = $attendees_validation['error'];
        } else {
            $sanitized_data['number_of_attendees'] = $attendees_validation['value'];
        }

        // Validate event_type (optional)
        $allowed_event_types = ['', 'Meeting', 'Conference', 'Workshop', 'Seminar', 'Training', 'Other'];
        $type_validation = validateSelectOption($_POST['event_type'] ?? '', $allowed_event_types, false);
        if (!$type_validation['valid']) {
            $validation_errors['event_type'] = $type_validation['error'];
        } else {
            $sanitized_data['event_type'] = $type_validation['value'];
        }

        // Validate special_requirements (optional)
        $requirements_validation = validateText($_POST['special_requirements'] ?? '', 0, 1000, false);
        if (!$requirements_validation['valid']) {
            $validation_errors['special_requirements'] = $requirements_validation['error'];
        } else {
            $sanitized_data['special_requirements'] = sanitizeString($requirements_validation['value'], 1000);
        }

        // Validate av_equipment (optional)
        $av_validation = validateText($_POST['av_equipment'] ?? '', 0, 500, false);
        if (!$av_validation['valid']) {
            $validation_errors['av_equipment'] = $av_validation['error'];
        } else {
            $sanitized_data['av_equipment'] = sanitizeString($av_validation['value'], 500);
        }

        // Validate catering_required (optional)
        $sanitized_data['catering_required'] = isset($_POST['catering_required']) ? 1 : 0;

        // Check for validation errors
        if (!empty($validation_errors)) {
            $error_messages = [];
            foreach ($validation_errors as $field => $message) {
                $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
            }
            throw new Exception(implode('; ', $error_messages));
        }

        // Prepare booking data for email functions
        $booking_data = [
            'conference_room_id' => $sanitized_data['conference_room_id'],
            'company_name' => $sanitized_data['company_name'],
            'contact_person' => $sanitized_data['contact_person'],
            'email' => $sanitized_data['email'],
            'phone' => $sanitized_data['phone'],
            'event_date' => $sanitized_data['event_date'],
            'start_time' => $sanitized_data['start_time'],
            'end_time' => $sanitized_data['end_time'],
            'number_of_attendees' => $sanitized_data['number_of_attendees'],
            'event_type' => $sanitized_data['event_type'],
            'special_requirements' => $sanitized_data['special_requirements'],
            'catering_required' => $sanitized_data['catering_required'],
            'av_equipment' => $sanitized_data['av_equipment']
        ];

        // Log booking data for diagnostics
        error_log("Conference enquiry data prepared: " . print_r($booking_data, true));

        $room_id = $sanitized_data['conference_room_id'];
        $company_name = $sanitized_data['company_name'];
        $contact_person = $sanitized_data['contact_person'];
        $email = $sanitized_data['email'];
        $phone = $sanitized_data['phone'];
        $event_date = $sanitized_data['event_date'];
        $start_time = $sanitized_data['start_time'];
        $end_time = $sanitized_data['end_time'];
        $attendees = $sanitized_data['number_of_attendees'];
        $event_type = $sanitized_data['event_type'];
        $special_requirements = $sanitized_data['special_requirements'];
        $catering = $sanitized_data['catering_required'];
        $av_equipment = $sanitized_data['av_equipment'];

        // Get room details for pricing
        $room_stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ? AND is_active = 1");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($room) && function_exists('applyManagedMediaOverrides')) {
            $room = applyManagedMediaOverrides($room, 'conference_rooms', $room['id'] ?? '', ['image_path']);
        }

        if (!$room) {
            throw new Exception('Selected conference room is not available.');
        }

        // Use full day rate for pricing
        $total_amount = $room['daily_rate'];

        // Generate unique inquiry reference
        do {
            $inquiry_reference = 'CONF-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $ref_check = $pdo->prepare("SELECT id FROM conference_inquiries WHERE inquiry_reference = ?");
            $ref_check->execute([$inquiry_reference]);
        } while ($ref_check->rowCount() > 0);

        // Insert inquiry
        $insert_stmt = $pdo->prepare("
            INSERT INTO conference_inquiries (
                inquiry_reference, conference_room_id, company_name, contact_person,
                email, phone, event_date, start_time, end_time, number_of_attendees,
                event_type, special_requirements, catering_required, av_equipment, total_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insert_stmt->execute([
            $inquiry_reference,
            $room_id,
            $company_name,
            $contact_person,
            $email,
            $phone,
            $event_date,
            $start_time,
            $end_time,
            $attendees,
            $event_type,
            $special_requirements,
            $catering,
            $av_equipment,
            $total_amount
        ]);

        // Set success and generate reference after validation passes
        $inquiry_success = true;
        $success_reference = $inquiry_reference;

        // Prepare enquiry data for email functions
        $enquiry_data = [
            'id' => $pdo->lastInsertId(),
            'inquiry_reference' => $inquiry_reference,
            'conference_room_id' => $room_id,
            'company_name' => $company_name,
            'contact_person' => $contact_person,
            'email' => $email,
            'phone' => $phone,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'number_of_attendees' => $attendees,
            'event_type' => $event_type,
            'special_requirements' => $special_requirements,
            'catering_required' => $catering,
            'av_equipment' => $av_equipment,
            'total_amount' => $total_amount
        ];

        // Send confirmation email to customer
        $customer_result = sendConferenceEnquiryEmail($enquiry_data);
        if (!$customer_result['success']) {
            error_log("Failed to send conference enquiry confirmation email: " . $customer_result['message']);
        } else {
            error_log("Conference customer email sent successfully to: " . $sanitized_data['email']);
        }

        // Send notification email to admin
        $admin_result = sendConferenceAdminNotificationEmail($enquiry_data);
        if (!$admin_result['success']) {
            error_log("Failed to send conference admin notification: " . $admin_result['message']);
        } else {
            error_log("Conference admin notification sent successfully");
        }

        // Check if both emails failed - add warning to success message
        $email_warning = '';
        if (!$customer_result['success'] && !$admin_result['success']) {
            $email_warning = ' Please note: We had trouble sending confirmation emails. Your enquiry has been saved and our team will contact you shortly.';
        } elseif (!$customer_result['success']) {
            $email_warning = ' Please note: We had trouble sending your confirmation email. Your enquiry has been saved and our team will contact you shortly.';
        } elseif (!$admin_result['success']) {
            $email_warning = ' Please note: We had trouble sending internal notification. Your enquiry has been saved and our team will contact you shortly.';
        }

        // Store email warning for display in result modal
        if (!empty($email_warning)) {
            $inquiry_email_warning = $email_warning;
        }

        error_log("Conference enquiry submitted successfully from: " . $sanitized_data['email'] . " with reference: " . $inquiry_reference);

        // Redirect to dedicated confirmation page
        header('Location: conference-confirmation.php?ref=' . urlencode($inquiry_reference));
        exit;
    } catch (\Throwable $e) {
        $inquiry_error = $e->getMessage();
        error_log('Conference inquiry error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}

$currency_symbol = getSetting('currency_symbol');
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$site_tagline = getSetting('site_tagline');

function resolveConferenceImage(?string $imagePath): string
{
    if (!empty($imagePath)) {
        $normalized = ltrim($imagePath, '/');
        $fullPath = __DIR__ . '/' . $normalized;
        if (file_exists($fullPath)) {
            return $normalized;
        }
    }

    return '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Conference Facilities - ' . $site_name,
        'description' => "Host your corporate events, meetings, and conferences at {$site_name}. State-of-the-art venues, full AV equipment, and dedicated event coordination.",
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/feature-disabled.css">
</head>

<body class="conference-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <?php if (!$conferenceEnabled): ?>
    <main id="main-content">
        <?php renderFeatureDisabledPage(
            'fas fa-briefcase',
            'Conference & Meeting Spaces',
            'Our corporate booking service is temporarily unavailable',
            'Our conference and meeting facilities are not currently bookable online. Please contact us directly and our team will be glad to help with your corporate event.'
        ); ?>
    </main>
    <?php else: ?>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <main id="main-content">
        <!-- Passalacqua-Inspired Editorial Conference Rooms Section -->
        <section class="editorial-events-section editorial-conference-section conference-showcase" id="conference">
            <div class="container">
                <?php renderSectionHeader('conference_overview', 'conference', [
                    'label' => 'Our Meeting Spaces',
                    'title' => 'Professional Conference Facilities',
                    'description' => 'State-of-the-art venues for your business meetings and events'
                ], 'text-center'); ?>
                <?php if (empty($conference_rooms)): ?>
                    <div class="editorial-no-events">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Conference Rooms Available</h3>
                        <p>Our team is preparing the conference lineup. Please check back soon or contact us for tailored corporate options.</p>
                    </div>
                <?php else: ?>
                    <div class="editorial-events-grid editorial-conference-grid conference-showcase__grid" id="editorial-conference-grid">
                        <?php foreach ($conference_rooms as $room): ?>
                            <?php
                            $amenities = !empty($room['amenities']) ? explode(',', $room['amenities']) : [];
                            $image_path = resolveConferenceImage($room['image_path'] ?? '');
                            ?>
                            <article class="editorial-event-card editorial-conference-card conference-showcase__card">
                                <div class="editorial-event-image-container editorial-conference-image-container conference-showcase__media">
                                    <?php if (!empty($image_path)): ?>
                                        <img src="<?php echo htmlspecialchars($image_path); ?>"
                                            alt="<?php echo htmlspecialchars($room['name']); ?>"
                                            class="editorial-event-image editorial-conference-image">
                                    <?php else: ?>
                                        <div class="editorial-event-image editorial-conference-image" style="background: linear-gradient(135deg, #e0e0e0 0%, #f5f5f5 100%); display: flex; align-items: center; justify-content: center; color: #999; min-height: 180px;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="editorial-event-content editorial-conference-content conference-showcase__content">
                                    <div class="editorial-event-meta editorial-conference-meta">
                                        <div class="editorial-event-meta-item">
                                            <i class="fas fa-users"></i>
                                            <span>Up to <?php echo $room['capacity']; ?> People</span>
                                        </div>
                                        <div class="editorial-event-meta-item">
                                            <i class="fas fa-expand-arrows-alt"></i>
                                            <span><?php echo number_format($room['size_sqm'], 0); ?> sqm</span>
                                        </div>
                                    </div>
                                    <h3 class="editorial-event-title editorial-conference-title"><?php echo htmlspecialchars($room['name']); ?></h3>
                                    <p class="editorial-event-description editorial-conference-description"><?php echo htmlspecialchars($room['description']); ?></p>
                                    <?php if (!empty($amenities)): ?>
                                        <div class="editorial-event-meta editorial-conference-amenities">
                                            <?php foreach ($amenities as $amenity): ?>
                                                <span class="editorial-featured-badge editorial-conference-amenity"><i class="fas fa-check"></i> <?php echo trim(htmlspecialchars($amenity)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="editorial-event-footer editorial-conference-footer">
                                        <div class="editorial-event-price editorial-conference-price">
                                            <span class="editorial-price-label">Full Day Rate</span>
                                            <span class="editorial-price-value"><?php echo $currency_symbol . number_format($room['daily_rate'], 0); ?>/day</span>
                                        </div>
                                        <button class="editorial-btn-primary editorial-conference-inquire" onclick="openInquiryModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name']); ?>')">
                                            <i class="fas fa-envelope"></i> Send Inquiry
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Inquiry Modal -->
        <?php
        $modalContent = '
        <form method="POST" action="" id="inquiryForm">
            <input type="hidden" name="conference_room_id" id="selectedRoomId">
            <input type="hidden" name="csrf_token" value="' . htmlspecialchars($conference_csrf_token, ENT_QUOTES, 'UTF-8') . '">

            <div class="form-group">
                <label>Conference Room</label>
                <input type="text" id="selectedRoomName" disabled style="background: #f5f5f5;">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" autocomplete="organization" autocapitalize="words" required>
                </div>
                <div class="form-group">
                    <label>Contact Person *</label>
                    <input type="text" name="contact_person" autocomplete="name" autocapitalize="words" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" required>
                </div>
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" name="phone" autocomplete="tel" inputmode="tel" required>
                </div>
            </div>

            <div class="form-group">
                <label>Event Date *</label>
                <input type="date" name="event_date" id="event_date" min="' . date('Y-m-d') . '" required>
                <small class="field-error" id="event_date_error"></small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" id="start_time" required>
                    <small class="field-error" id="start_time_error"></small>
                </div>
                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Number of Attendees *</label>
                    <input type="number" name="number_of_attendees" min="1" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label>Event Type</label>
                    <select name="event_type">
                        <option value="">Select type...</option>
                        <option value="Meeting">Meeting</option>
                        <option value="Conference">Conference</option>
                        <option value="Workshop">Workshop</option>
                        <option value="Seminar">Seminar</option>
                        <option value="Training">Training</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>AV Equipment Requirements</label>
                <input type="text" name="av_equipment" placeholder="e.g., Projector, Microphones, Sound System">
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="catering_required" id="catering">
                <label for="catering" style="margin-bottom: 0;">Catering Required</label>
            </div>

            <div class="form-group">
                <label>Special Requirements</label>
                <textarea name="special_requirements" rows="4" placeholder="Any additional requests or requirements..."></textarea>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="consent" id="consentCheckbox" required>
                <label for="consentCheckbox" style="margin-bottom: 0;">I agree to be contacted about this enquiry request.</label>
            </div>
        </form>
    ';

        renderModal('inquiryModal', 'Conference Room Inquiry', $modalContent, [
            'size' => 'lg',
            'footer' => '
            <button type="submit" form="inquiryForm" id="conferenceSubmitBtn" class="btn-inquire" style="width: 100%;" disabled>
                <i class="fas fa-paper-plane"></i> Submit Inquiry
            </button>
        '
        ]);
        ?>

        <!-- Result Modal for Success/Error Messages -->
        <?php
        $resultModalContent = '';
        if ($inquiry_success) {
            $email_warning_html = '';
            if (!empty($inquiry_email_warning)) {
                $email_warning_html = '<div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
                <p style="color: #856404; margin: 0; font-size: 14px;"><i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>' . htmlspecialchars($inquiry_email_warning) . '</p>
            </div>';
            }

            $resultModalContent = '<div style="text-align: center; padding: 20px;">
            <i class="fas fa-check-circle" style="font-size: 64px; color: #28a745;"></i>
            <h2 style="color: var(--navy); margin: 20px 0 15px 0; font-size: 28px; font-weight: 700;">Conference Enquiry Submitted Successfully!</h2>
            <p style="color: #666; margin: 0 0 25px 0; font-size: 16px; line-height: 1.6;">Thank you for your conference enquiry. Our events team will review your request and contact you within 24 hours to confirm availability and finalize details.</p>
            <div style="background: linear-gradient(135deg, rgba(139, 115, 85, 0.15), rgba(139, 115, 85, 0.05)); padding: 20px 30px; border-radius: 12px; margin: 25px 0; border: 2px solid rgba(139, 115, 85, 0.35);">
                <p style="color: var(--navy); margin: 0; font-size: 14px; font-weight: 600;">Your Reference Number:</p>
                <p style="color: var(--navy); margin: 8px 0 0 0; font-size: 24px; font-weight: 700; letter-spacing: 1px;">' . htmlspecialchars($success_reference) . '</p>
            </div>
            ' . $email_warning_html . '
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px; line-height: 1.6;">
                <i class="fas fa-envelope" style="color: var(--gold);"></i> A confirmation email has been sent to your email address.<br>
                <i class="fas fa-info-circle" style="color: var(--gold);"></i> Please save this reference number for your records.
            </p>
        </div>';
        } elseif ($inquiry_error) {
            $resultModalContent = '<div style="text-align: center; padding: 20px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #dc3545;"></i>
            <h2 style="color: var(--navy); margin: 20px 0 15px 0; font-size: 28px; font-weight: 700;">Enquiry Submission Failed</h2>
            <p style="color: #666; margin: 0 0 25px 0; font-size: 16px; line-height: 1.6;">' . htmlspecialchars($inquiry_error) . '</p>
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px;">
                <i class="fas fa-phone" style="color: var(--gold);"></i> Please try again or contact our events team directly for assistance.
            </p>
        </div>';
        }

        renderModal('conferenceBookingResult', '', $resultModalContent, ['size' => 'md']);
        ?>

        <!-- Scripts -->
        <script src="js/main.js"></script>
        <script>
            // Inquiry Modal - target by specific ID, not generic selector
            const inquiryModal = document.getElementById('inquiryModal');
            const inquiryModalBackdrop = inquiryModal?.querySelector('.modal__backdrop');

            function openInquiryModal(roomId, roomName) {
                document.getElementById('selectedRoomId').value = roomId;
                document.getElementById('selectedRoomName').value = roomName;

                if (inquiryModal) {
                    inquiryModal.classList.add('modal--active');
                    document.body.classList.add('modal-open');
                }
            }

            function closeInquiryModal() {
                if (inquiryModal) {
                    inquiryModal.classList.remove('modal--active');
                    document.body.classList.remove('modal-open');
                }
            }

            // Close inquiry modal on backdrop click
            if (inquiryModalBackdrop) {
                inquiryModalBackdrop.addEventListener('click', closeInquiryModal);
            }

            // Close inquiry modal close buttons (scoped to inquiry modal only)
            const inquiryCloseButtons = document.querySelectorAll('#inquiryModal [data-modal-close]');
            inquiryCloseButtons.forEach(btn => btn.addEventListener('click', closeInquiryModal));

            // Result Modal - close handling (scoped to result modal only)
            const resultModal = document.getElementById('conferenceBookingResult');
            const resultModalBackdrop = resultModal?.querySelector('.modal__backdrop');

            function closeResultModal() {
                if (resultModal) {
                    resultModal.classList.remove('modal--active');
                    document.body.classList.remove('modal-open');
                }
            }

            if (resultModalBackdrop) {
                resultModalBackdrop.addEventListener('click', closeResultModal);
            }

            const resultCloseButtons = document.querySelectorAll('#conferenceBookingResult [data-modal-close]');
            resultCloseButtons.forEach(btn => btn.addEventListener('click', closeResultModal));

            // Close any active modal on escape key
            document.addEventListener('keyup', (e) => {
                if (e.key === 'Escape') {
                    if (resultModal && resultModal.classList.contains('modal--active')) {
                        closeResultModal();
                    } else if (inquiryModal && inquiryModal.classList.contains('modal--active')) {
                        closeInquiryModal();
                    }
                }
            });

            // Consent checkbox validation - grey out submit button until consent is checked
            const consentCheckbox = document.getElementById('consentCheckbox');
            const conferenceSubmitBtn = document.getElementById('conferenceSubmitBtn');

            if (consentCheckbox && conferenceSubmitBtn) {
                // Initialize button state
                conferenceSubmitBtn.disabled = !consentCheckbox.checked;
                conferenceSubmitBtn.style.opacity = consentCheckbox.checked ? '1' : '0.6';
                conferenceSubmitBtn.style.cursor = consentCheckbox.checked ? 'pointer' : 'not-allowed';

                // Handle checkbox change
                consentCheckbox.addEventListener('change', function() {
                    conferenceSubmitBtn.disabled = !this.checked;
                    conferenceSubmitBtn.style.opacity = this.checked ? '1' : '0.6';
                    conferenceSubmitBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
                });
            }

            // Form submission handling - grey out submit button
            const inquiryForm = document.getElementById('inquiryForm');

            if (inquiryForm && conferenceSubmitBtn) {
                inquiryForm.addEventListener('submit', function() {
                    // Disable and grey out the submit button
                    conferenceSubmitBtn.disabled = true;
                    conferenceSubmitBtn.style.opacity = '0.6';
                    conferenceSubmitBtn.style.cursor = 'not-allowed';
                    conferenceSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                });
            }

            // Date/Time validation - ensure selected time is not in the past for today's date
            const eventDate = document.getElementById('event_date');
            const startTime = document.getElementById('start_time');
            const dateError = document.getElementById('event_date_error');
            const timeError = document.getElementById('start_time_error');

            // Booking buffer in minutes from settings (kept in sync with server validation)
            const bookingBufferMinutes = <?php echo (int)getSetting('booking_time_buffer_minutes', 60); ?>;

            function validateConferenceDateTime() {
                if (!eventDate || !startTime) return true;
                if (!eventDate.value || !startTime.value) {
                    if (dateError) dateError.style.display = 'none';
                    if (timeError) timeError.style.display = 'none';
                    return true;
                }

                const selectedDateTime = new Date(eventDate.value + 'T' + startTime.value);
                const now = new Date();
                const minAllowed = new Date(now.getTime() + bookingBufferMinutes * 60000);

                // Clear previous errors
                if (dateError) dateError.style.display = 'none';
                if (timeError) timeError.style.display = 'none';

                if (selectedDateTime < minAllowed) {
                    // Check if it's a past date
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const selectedDayOnly = new Date(eventDate.value);
                    selectedDayOnly.setHours(0, 0, 0, 0);

                    if (selectedDayOnly < today) {
                        if (dateError) {
                            dateError.textContent = 'Event date cannot be in the past';
                            dateError.style.display = 'block';
                        }
                        if (eventDate) eventDate.style.borderColor = '#dc3545';
                    } else {
                        // It's today but time is too soon
                        const bufferHours = Math.floor(bookingBufferMinutes / 60);
                        const bufferMins = bookingBufferMinutes % 60;
                        let timeMsg = 'For today, please select a start time at least ';
                        if (bufferHours > 0 && bufferMins > 0) {
                            timeMsg += bufferHours + ' hour(s) and ' + bufferMins + ' minutes from now';
                        } else if (bufferHours > 0) {
                            timeMsg += bufferHours + ' hour(s) from now';
                        } else {
                            timeMsg += bookingBufferMinutes + ' minutes from now';
                        }
                        if (timeError) {
                            timeError.textContent = timeMsg;
                            timeError.style.display = 'block';
                        }
                        if (startTime) startTime.style.borderColor = '#dc3545';
                    }
                    return false;
                }

                // Valid - reset borders
                if (eventDate) eventDate.style.borderColor = '';
                if (startTime) startTime.style.borderColor = '';
                return true;
            }

            // Add event listeners for real-time validation
            if (eventDate && startTime) {
                eventDate.addEventListener('change', validateConferenceDateTime);
                startTime.addEventListener('change', validateConferenceDateTime);

                // Also validate on form submission
                if (inquiryForm) {
                    inquiryForm.addEventListener('submit', function(e) {
                        if (!validateConferenceDateTime()) {
                            e.preventDefault();
                            return false;
                        }
                    });
                }
            }

            <?php if ($inquiry_success || $inquiry_error): ?>
                // Auto-open result modal on page load when there's a result
                window.addEventListener('load', function() {
                    const resultModalEl = document.getElementById('conferenceBookingResult');
                    if (resultModalEl) {
                        setTimeout(function() {
                            resultModalEl.classList.add('modal--active');
                            document.body.classList.add('modal-open');
                        }, 600);
                    }
                });
            <?php endif; ?>
        </script>

    </main>
    <?php endif; ?>
    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>
</body>

</html>
