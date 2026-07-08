<?php
// Production error handling - log errors, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';
require_once 'includes/booking-functions.php';
require_once 'includes/page-guard.php';
requireEventsEnabled();
require_once 'includes/image-proxy-helper.php';
require_once 'includes/section-headers.php';
require_once 'config/email.php';
require_once 'config/invoice.php';
require_once 'includes/validation.php';
require_once 'includes/modal.php';
require_once 'includes/public-csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$events_csrf_token = pub_csrf_generate('events');

// Handle event booking/RSVP form submission
$bookingError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_booking_form'])) {
    if (!pub_csrf_validate($_POST['csrf_token'] ?? '', 'events')) {
        $bookingError = 'Security token invalid. Please refresh the page and try again.';
    } elseif (!pub_rate_limit('event_booking_form', 5, 600)) {
        $bookingError = 'Too many submissions. Please wait a few minutes before trying again.';
    } else {
        $validation_errors = [];
        $sanitized_data = [];

        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) {
            $validation_errors['event_id'] = 'Please select an event to book.';
        }

        $name_validation = validateName($_POST['full_name'] ?? '', 2, true);
        if (!$name_validation['valid']) {
            $validation_errors['full_name'] = $name_validation['error'];
        } else {
            $sanitized_data['full_name'] = sanitizeString($name_validation['value'], 100);
        }

        $email_validation = validateEmail($_POST['email'] ?? '');
        if (!$email_validation['valid']) {
            $validation_errors['email'] = $email_validation['error'];
        } else {
            $sanitized_data['email'] = $_POST['email'];
        }

        $phone_validation = validatePhone($_POST['phone'] ?? '');
        if (!$phone_validation['valid']) {
            $validation_errors['phone'] = $phone_validation['error'];
        } else {
            $sanitized_data['phone'] = $phone_validation['sanitized'];
        }

        $guests_validation = validateNumber($_POST['guests'] ?? '', 1, 20, false);
        if (!$guests_validation['valid']) {
            $validation_errors['guests'] = $guests_validation['error'];
        } else {
            $sanitized_data['guests'] = $guests_validation['value'] ?? 1;
        }

        $message_validation = validateText($_POST['message'] ?? '', 0, 1000, false);
        if (!$message_validation['valid']) {
            $validation_errors['message'] = $message_validation['error'];
        } else {
            $sanitized_data['message'] = sanitizeString($message_validation['value'], 1000);
        }

        $consent = isset($_POST['consent']);
        if (!$consent) {
            $validation_errors['consent'] = 'You must accept consent to proceed.';
        }

        if (!empty($validation_errors)) {
            $error_messages = [];
            foreach ($validation_errors as $field => $msg) {
                $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $msg;
            }
            $bookingError = implode('; ', $error_messages);
        } else {
            $bookingReference = 'EVT-' . strtoupper(substr(uniqid(), -8));

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO event_inquiries (
                        reference_number, event_id, name, email, phone, guests, message, consent, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $bookingReference,
                    $event_id,
                    $sanitized_data['full_name'],
                    $sanitized_data['email'],
                    $sanitized_data['phone'],
                    $sanitized_data['guests'] ?? 1,
                    $sanitized_data['message'] ?? '',
                    $consent ? 1 : 0,
                ]);

                $eventTitleStmt = $pdo->prepare("SELECT title, event_date FROM events WHERE id = ? LIMIT 1");
                $eventTitleStmt->execute([$event_id]);
                $eventRow = $eventTitleStmt->fetch(PDO::FETCH_ASSOC);

                $email_data = [
                    'reference_number' => $bookingReference,
                    'name' => $sanitized_data['full_name'],
                    'email' => $sanitized_data['email'],
                    'phone' => $sanitized_data['phone'],
                    'guests' => $sanitized_data['guests'] ?? 1,
                    'event_title' => $eventRow['title'] ?? '',
                    'event_date' => $eventRow['event_date'] ?? null,
                ];

                $email_result = sendEventBookingConfirmedEmail($email_data);
                if (!$email_result['success']) {
                    error_log('Failed to send event booking confirmation email: ' . $email_result['message']);
                }
            } catch (PDOException $e) {
                error_log('Failed to save event inquiry to database: ' . $e->getMessage());
                $bookingError = 'We could not save your booking request. Please try again or contact us directly.';
            }

            if ($bookingError === '') {
                header('Location: events-confirmation.php?ref=' . urlencode($bookingReference));
                exit;
            }
        }
    }
}

function resolveEventImagePath(?string $imagePath): string
{
    if (empty($imagePath)) {
        return 'images/hero/slide1.jpeg';
    }

    if (preg_match('/^https?:\/\//i', $imagePath) === 1) {
        return $imagePath;
    }

    $normalized = ltrim($imagePath, '/');
    if (file_exists(__DIR__ . '/' . $normalized)) {
        return $normalized;
    }

    return 'images/hero/slide1.jpeg';
}


// Fetch all events (both upcoming and expired)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM events
        WHERE is_active = 1
        ORDER BY event_date DESC, start_time DESC
    ");
    $stmt->execute();
    $all_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Separate into upcoming and expired
    $upcoming_events = [];
    $expired_events = [];
    $today = date('Y-m-d');

    foreach ($all_events as $event) {
        if (function_exists('applyManagedMediaOverrides')) {
            $event = applyManagedMediaOverrides($event, 'events', $event['id'] ?? '', ['image_path', 'video_path']);
        }

        $eventDate = (string)($event['event_date'] ?? '');
        $event['is_expired'] = ($eventDate !== '' && $eventDate < $today);

        if (!$event['is_expired']) {
            $upcoming_events[] = $event;
        } else {
            $expired_events[] = $event;
        }
    }

    // Sort upcoming events ascending
    usort($upcoming_events, function ($a, $b) {
        return strtotime($a['event_date']) - strtotime($b['event_date']);
    });
} catch (PDOException $e) {
    $upcoming_events = [];
    $expired_events = [];
    error_log("Events fetch error: " . $e->getMessage());
}

// Include video display helper for renderVideoEmbed function
require_once 'includes/video-display.php';

$currency_symbol = getSetting('currency_symbol');
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Upcoming Events - ' . $site_name,
        'description' => "Join us for memorable celebrations and special gatherings at {$site_name}. Check out our upcoming events, live music, and special occasions.",
        'image' => '/images/hero/slide1.jpeg',
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    </noscript>

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">
</head>

<body class="events-page">
    <?php include 'includes/loader.php'; ?>

    <?php include 'includes/header.php'; ?>
    <!-- Mobile menu overlay is now included in header.php -->

    <main id="main-content">
        <!-- Hero Section -->
        <?php include 'includes/hero.php'; ?>


        <!-- Passalacqua-Inspired Editorial Events Section -->
        <section class="editorial-events-section events-showcase" id="events" data-lazy-reveal>
            <div class="container">
                <?php renderSectionHeader('events_overview', 'events', [
                    'label' => 'Upcoming Events',
                    'title' => 'Special Events & Occasions',
                    'description' => 'Join us for memorable celebrations and special gatherings'
                ], 'text-center'); ?>
                <?php if (empty($upcoming_events)): ?>
                    <div class="editorial-no-events">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Upcoming Events</h3>
                        <p>Check back soon for exciting events and special occasions!</p>
                    </div>
                <?php else: ?>
                    <div class="editorial-events-grid events-showcase__grid" id="editorial-events-grid">
                        <?php foreach ($upcoming_events as $event): ?>
                            <?php
                            $event_date = new DateTime($event['event_date']);
                            $day = $event_date->format('d');
                            $month = $event_date->format('M');
                            $formatted_date = $event_date->format('F j, Y');
                            $start_time = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
                            $end_time = !empty($event['end_time']) ? date('g:i A', strtotime($event['end_time'])) : '';
                            $event_image = proxyImageUrl(resolveEventImagePath($event['image_path'] ?? ''));
                            ?>
                            <article id="event-<?php echo (int)$event['id']; ?>" class="editorial-event-card events-showcase__card <?php echo $event['is_featured'] ? 'featured' : ''; ?>" data-event-status="upcoming">
                                <div class="editorial-event-image-container events-showcase__media">
                                    <?php if (!empty($event['video_path'])): ?>
                                        <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], [
                                            'autoplay' => true,
                                            'muted' => true,
                                            'controls' => true,
                                            'loop' => true,
                                            'class' => 'editorial-event-image',
                                            'style' => 'width: 100%; height: 100%; object-fit: cover; display: block;'
                                        ]); ?>
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($event_image); ?>"
                                            alt="<?php echo htmlspecialchars($event['title']); ?>"
                                            class="editorial-event-image"
                                            loading="lazy"
                                            width="600" height="375"
                                            onerror="this.src='images/hero/slide1.jpeg'">
                                    <?php endif; ?>
                                    <div class="editorial-event-date-badge">
                                        <span class="editorial-event-date-day"><?php echo $day; ?></span>
                                        <span class="editorial-event-date-month"><?php echo $month; ?></span>
                                    </div>
                                    <?php if ($event['is_featured']): ?>
                                        <div class="editorial-featured-badge">
                                            <i class="fas fa-star"></i> Featured
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="editorial-event-content">
                                    <h3 class="editorial-event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                    <div class="editorial-event-meta">
                                        <div class="editorial-event-meta-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo $formatted_date; ?></span>
                                        </div>
                                        <?php if ($start_time && $end_time): ?>
                                            <div class="editorial-event-meta-item">
                                                <i class="fas fa-clock"></i>
                                                <span><?php echo $start_time . ' - ' . $end_time; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['location'])): ?>
                                            <div class="editorial-event-meta-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($event['location']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($event['capacity']): ?>
                                            <div class="editorial-event-meta-item">
                                                <i class="fas fa-users"></i>
                                                <span>Limited to <?php echo $event['capacity']; ?> guests</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <p class="editorial-event-description"><?php echo htmlspecialchars($event['description']); ?></p>
                                    <div class="editorial-event-footer">
                                        <div class="editorial-event-price <?php echo $event['ticket_price'] == 0 ? 'free' : ''; ?>">
                                            <?php if ($event['ticket_price'] == 0): ?>
                                                <span class="editorial-price-label">Free</span>
                                                <span class="editorial-price-value">Event</span>
                                            <?php else: ?>
                                                <span class="editorial-price-label">From</span>
                                                <span class="editorial-price-value"><?php echo $currency_symbol . number_format($event['ticket_price'], 0); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm" data-open-event-booking data-event-id="<?php echo (int)$event['id']; ?>" data-event-title="<?php echo htmlspecialchars($event['title'], ENT_QUOTES); ?>">
                                            <i class="fas fa-calendar-check"></i> Book This Event
                                        </button>
                                        <a href="contact-us.php?subject=Events&event=<?php echo rawurlencode($event['title']); ?>" class="btn btn-outline btn-sm">
                                            <i class="fas fa-envelope"></i> Enquire
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <!-- Expired Events Section -->
                <?php if (!empty($expired_events)): ?>
                    <div class="editorial-expired-events-section" id="editorial-expired-events-section">
                        <h2 class="editorial-expired-section-title">Past Events</h2>
                        <p class="editorial-expired-section-subtitle">Events that have already taken place</p>
                        <div class="editorial-events-grid events-showcase__grid" id="editorial-expired-events-grid">
                            <?php foreach ($expired_events as $event): ?>
                                <?php
                                $event_date = new DateTime($event['event_date']);
                                $day = $event_date->format('d');
                                $month = $event_date->format('M');
                                $formatted_date = $event_date->format('F j, Y');
                                $start_time = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
                                $end_time = !empty($event['end_time']) ? date('g:i A', strtotime($event['end_time'])) : '';
                                $event_image = proxyImageUrl(resolveEventImagePath($event['image_path'] ?? ''));
                                ?>
                                <article id="event-<?php echo (int)$event['id']; ?>" class="editorial-event-card events-showcase__card is-expired" data-event-status="expired">
                                    <div class="event-expired-ribbon" aria-label="Expired event">
                                        <span>Expired</span>
                                    </div>
                                    <div class="editorial-event-image-container events-showcase__media">
                                        <?php if (!empty($event['video_path'])): ?>
                                            <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], [
                                                'autoplay' => false,
                                                'muted' => true,
                                                'controls' => false,
                                                'loop' => false,
                                                'class' => 'editorial-event-image',
                                                'style' => 'width: 100%; height: 100%; object-fit: cover; display: block;'
                                            ]); ?>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($event_image); ?>"
                                                alt="<?php echo htmlspecialchars($event['title']); ?>"
                                                class="editorial-event-image"
                                                loading="lazy"
                                                width="600" height="375"
                                                onerror="this.src='images/hero/slide1.jpeg'">
                                        <?php endif; ?>
                                        <div class="editorial-event-date-badge">
                                            <span class="editorial-event-date-day"><?php echo $day; ?></span>
                                            <span class="editorial-event-date-month"><?php echo $month; ?></span>
                                        </div>
                                    </div>
                                    <div class="editorial-event-content">
                                        <h3 class="editorial-event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <div class="editorial-event-meta">
                                            <div class="editorial-event-meta-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span><?php echo $formatted_date; ?></span>
                                            </div>
                                            <?php if ($start_time && $end_time): ?>
                                                <div class="editorial-event-meta-item">
                                                    <i class="fas fa-clock"></i>
                                                    <span><?php echo $start_time . ' - ' . $end_time; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($event['location'])): ?>
                                                <div class="editorial-event-meta-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($event['location']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="editorial-event-description"><?php echo htmlspecialchars($event['description']); ?></p>
                                        <div class="editorial-event-footer">
                                            <div class="editorial-event-price <?php echo $event['ticket_price'] == 0 ? 'free' : ''; ?>">
                                                <?php if ($event['ticket_price'] == 0): ?>
                                                    <span class="editorial-price-label">Free</span>
                                                    <span class="editorial-price-value">Event</span>
                                                <?php else: ?>
                                                    <span class="editorial-price-label">Was</span>
                                                    <span class="editorial-price-value"><?php echo $currency_symbol . number_format($event['ticket_price'], 0); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Event Booking Modal -->
        <div class="modal modal--lg" id="eventBookingModal" data-booking-modal role="dialog" aria-modal="true" aria-labelledby="eventBookingModal-title">
            <div class="modal__backdrop" data-close-event-booking></div>
            <div class="modal__wrapper">
                <div class="modal__container">
                    <button class="modal__close" aria-label="Close booking form" data-close-event-booking>
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <div class="modal__header">
                        <div class="modal__header-content">
                            <span class="booking-pill">Event Booking</span>
                            <h3 class="modal__title" id="eventBookingModal-title">Book <span id="eventBookingModalEventName">This Event</span></h3>
                            <p>Complete the form and our team will confirm your booking via email.</p>
                        </div>
                    </div>
                    <div class="modal__body">
                        <?php if ($bookingError): ?>
                        <div class="alert alert-error" style="margin-bottom:16px;"><?php echo htmlspecialchars($bookingError); ?></div>
                        <?php endif; ?>
                        <form method="POST" class="booking-form" novalidate>
                            <input type="hidden" name="event_booking_form" value="1">
                            <input type="hidden" name="event_id" id="eventBookingEventId" value="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($events_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="event_full_name">Full Name *</label>
                                    <input type="text" id="event_full_name" name="full_name" autocomplete="name" autocapitalize="words" required>
                                </div>
                                <div class="form-group">
                                    <label for="event_email">Email *</label>
                                    <input type="email" id="event_email" name="email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" required>
                                </div>
                                <div class="form-group">
                                    <label for="event_phone">Phone *</label>
                                    <input type="tel" id="event_phone" name="phone" autocomplete="tel" inputmode="tel" required>
                                </div>
                                <div class="form-group">
                                    <label for="event_guests">Guests</label>
                                    <input type="number" id="event_guests" name="guests" min="1" max="20" inputmode="numeric" placeholder="1">
                                </div>
                                <div class="form-group full">
                                    <label for="event_message">Message / Special Requests</label>
                                    <textarea id="event_message" name="message" rows="4" placeholder="Any special requests or questions"></textarea>
                                </div>
                                <div class="form-consent full">
                                    <label class="checkbox">
                                        <input type="checkbox" name="consent" required>
                                        <span>I agree to be contacted about this booking request.</span>
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary full-width" id="eventBookingSubmitBtn" disabled>Send Booking Request</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script src="js/main.js"></script>
    <script>
        (function () {
            const bookingModal = document.getElementById('eventBookingModal');
            const eventIdField = document.getElementById('eventBookingEventId');
            const eventNameLabel = document.getElementById('eventBookingModalEventName');
            const openButtons = document.querySelectorAll('[data-open-event-booking]');
            const closeButtons = document.querySelectorAll('[data-close-event-booking]');

            function openModal(eventId, eventTitle) {
                if (!bookingModal) return;
                if (eventIdField) eventIdField.value = eventId || '';
                if (eventNameLabel) eventNameLabel.textContent = eventTitle || 'This Event';
                bookingModal.classList.add('modal--active');
                document.body.classList.add('modal-open');
            }

            function closeModal() {
                if (!bookingModal) return;
                bookingModal.classList.remove('modal--active');
                document.body.classList.remove('modal-open');
            }

            openButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openModal(btn.getAttribute('data-event-id'), btn.getAttribute('data-event-title'));
                });
            });
            closeButtons.forEach(function (btn) { btn.addEventListener('click', closeModal); });
            document.addEventListener('keyup', function (e) {
                if (e.key === 'Escape') closeModal();
            });

            <?php if ($bookingError): ?>
            // Re-open the modal automatically if the last submission failed validation
            openModal(<?php echo json_encode((int)($_POST['event_id'] ?? 0)); ?>, '');
            <?php endif; ?>

            const consentCheckbox = bookingModal ? bookingModal.querySelector('input[name="consent"]') : null;
            const submitBtn = document.getElementById('eventBookingSubmitBtn');

            if (consentCheckbox && submitBtn) {
                submitBtn.disabled = !consentCheckbox.checked;
                submitBtn.style.opacity = consentCheckbox.checked ? '1' : '0.6';
                submitBtn.style.cursor = consentCheckbox.checked ? 'pointer' : 'not-allowed';

                consentCheckbox.addEventListener('change', function () {
                    submitBtn.disabled = !this.checked;
                    submitBtn.style.opacity = this.checked ? '1' : '0.6';
                    submitBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
                });
            }

            const bookingForm = bookingModal ? bookingModal.querySelector('.booking-form') : null;
            if (bookingForm && submitBtn) {
                bookingForm.addEventListener('submit', function () {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                    submitBtn.style.cursor = 'not-allowed';
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                });
            }
        })();
    </script>
</body>

</html>
