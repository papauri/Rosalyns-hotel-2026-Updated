<?php
/**
 * Booking System Functions
 *
 * Modular booking functions that can be easily migrated to any website.
 * All booking logic is centralized here for easy maintenance and portability.
 *
 * @package BookingSystem
 * @version 1.0.0
 */

// Include base URL configuration for proper redirects
if (file_exists(__DIR__ . '/../config/base-url.php')) {
    require_once __DIR__ . '/../config/base-url.php';
}

// Prevent direct access
if (!defined('BOOKING_SYSTEM_LOADED')) {
    define('BOOKING_SYSTEM_LOADED', true);
}

/**
 * Check if the booking system is enabled
 * 
 * @return bool True if booking system is enabled, false otherwise
 */
/**
 * A feature can be manually switched off via its own setting, but it must
 * also respect the installation's business-preset module state (Module
 * Settings admin page) — a Bar/Restaurant preset that turns "conference"
 * off should hide the guest-facing conference pages too, regardless of
 * the legacy per-feature setting.
 */
function rh_module_and_setting_enabled(string $moduleKey, string $settingKey): bool {
    if (function_exists('moduleEnabled') && !moduleEnabled($moduleKey)) {
        return false;
    }
    return getSetting($settingKey, '1') === '1';
}

/**
 * Single source of truth mapping a public page file to the business
 * module/feature that governs it. Used by both the page guard (front-end
 * access control) and Page Management (admin UI) so the two never drift.
 *
 * Returns null for global pages (home, contact, policies) that every preset
 * shows. Otherwise returns:
 *   ['module' => <module key>, 'label' => <human label>, 'enabled' => <bool>]
 * where 'enabled' reflects BOTH the preset module state and the legacy
 * per-feature setting (via the is*Enabled() helpers).
 */
function rh_front_page_feature(string $filePath): ?array {
    $file = basename(trim(str_replace('\\', '/', $filePath)));

    // file => [module key, display label, resolver function]
    static $map = [
        'rooms-gallery.php'      => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'rooms-showcase.php'     => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'room.php'               => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'booking.php'            => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'booking-lookup.php'     => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'check-availability.php' => ['bookings',   'Rooms & Booking', 'isBookingEnabled'],
        'restaurant.php'         => ['restaurant', 'Restaurant',      'isRestaurantEnabled'],
        'menu-pdf.php'           => ['restaurant', 'Restaurant',      'isRestaurantEnabled'],
        'gym.php'                => ['gym',        'Gym & Fitness',   'isGymEnabled'],
        'gym-schedule.php'       => ['gym',        'Gym & Fitness',   'isGymEnabled'],
        'conference.php'         => ['conference', 'Conference',      'isConferenceEnabled'],
        'events.php'             => ['events',     'Events',          'isEventsEnabled'],
    ];

    if (!isset($map[$file])) {
        return null;
    }

    [$moduleKey, $label, $resolver] = $map[$file];
    $enabled = function_exists($resolver) ? (bool)$resolver() : true;

    return ['module' => $moduleKey, 'label' => $label, 'enabled' => $enabled];
}

function isBookingEnabled(): bool {
    return rh_module_and_setting_enabled('bookings', 'booking_system_enabled');
}

function isConferenceEnabled(): bool {
    return rh_module_and_setting_enabled('conference', 'conference_system_enabled');
}

function isGymEnabled(): bool {
    return rh_module_and_setting_enabled('gym', 'gym_system_enabled');
}

/**
 * Unlike bookings/conference/gym, the POS module doesn't map 1:1 to "we run
 * a public restaurant" — Retail/Shop, Supermarket and Gym/Fitness presets
 * all keep POS on (for till sales) without a guest-facing dining page.
 * Whether the restaurant page shows is decided purely by this setting,
 * which the business-preset apply flow sets explicitly per preset.
 */
function isRestaurantEnabled(): bool {
    return getSetting('restaurant_system_enabled', '1') === '1';
}

/**
 * Admin-side revenue/reporting labels for POS-driven income. The POS module
 * is on for retail/gym/supermarket presets too (till sales), not just an
 * actual restaurant — calling that revenue "F&B" is misleading when there's
 * no public dining page. Same show/hide principle as everywhere else: never
 * hide the money, just word it accurately for the active preset.
 */
function rh_pos_category_label(): string {
    return isRestaurantEnabled() ? 'F&B / Restaurant (POS)' : 'POS / Till Sales';
}

function rh_pos_short_label(): string {
    return isRestaurantEnabled() ? 'F&B' : 'POS';
}

function rh_pos_cogs_label(): string {
    return isRestaurantEnabled() ? 'F&B COGS' : 'POS COGS';
}

/**
 * booking_type values (payments ledger vocabulary) whose owning module is
 * enabled for this installation. Drives the DEFAULT scoping of finance list
 * pages: a Gym preset's invoice/payment lists open showing gym/event/till
 * rows, not room-booking history. Data is never deleted — pages offer a
 * "show all history" escape hatch and explicit ?booking_type= deep links
 * bypass scoping entirely.
 */
function rh_enabled_booking_types(): array {
    $types = [];
    $mod = static function (string $m): bool {
        return function_exists('moduleEnabled') && moduleEnabled($m);
    };
    if ($mod('bookings'))   { $types[] = 'room'; }
    if ($mod('conference')) { $types[] = 'conference'; }
    if ($mod('pos'))        { $types[] = 'restaurant'; }
    if ($mod('gym'))        { $types[] = 'gym'; }
    if (function_exists('isEventsEnabled') && isEventsEnabled()) { $types[] = 'event'; }
    return $types;
}

/**
 * Given a raw link URL from an admin-managed link list (e.g. footer_links),
 * decide whether it points to a page whose feature/module is currently
 * switched off. Keeps freeform admin-editable link lists (which have no
 * structured page/module association) in sync with the same module state
 * that already gates the header nav and the pages themselves.
 */
function rh_is_feature_link_hidden(string $rawHref): bool {
    $slug = strtolower(trim($rawHref));
    $slug = preg_replace('#^api/#i', '', $slug);
    $slug = ltrim($slug, '/#');
    $slug = preg_replace('/[?#].*$/', '', $slug);
    $slug = preg_replace('/\.php$/', '', (string)$slug);
    $slug = trim((string)$slug, '/');

    $map = [
        'restaurant'    => 'isRestaurantEnabled',
        'menu'          => 'isRestaurantEnabled',
        'menu-pdf'      => 'isRestaurantEnabled',
        'gym'           => 'isGymEnabled',
        'gym-schedule'  => 'isGymEnabled',
        'conference'    => 'isConferenceEnabled',
        'booking'       => 'isBookingEnabled',
        'rooms-gallery' => 'isBookingEnabled',
        'rooms'         => 'isBookingEnabled',
        'room'          => 'isBookingEnabled',
        'booking-lookup' => 'isBookingEnabled',
        'events'        => 'isEventsEnabled',
        'events-confirmation' => 'isEventsEnabled',
    ];

    if (!isset($map[$slug])) {
        return false;
    }

    $checker = $map[$slug];
    return function_exists($checker) && !$checker();
}

/**
 * Given a policy slug (e.g. "dining-policy", "cancellation-policy"), decide
 * whether it should be hidden because it only applies to a feature that's
 * currently switched off (module preset or per-feature setting).
 */
function rh_is_policy_hidden(string $slug): bool {
    $slug = strtolower(trim($slug));

    $map = [
        'booking-policy'      => 'isBookingEnabled',
        'cancellation-policy' => 'isBookingEnabled',
        'dining-policy'       => 'isRestaurantEnabled',
        'restaurant-policy'   => 'isRestaurantEnabled',
        'conference-policy'   => 'isConferenceEnabled',
        'gym-policy'          => 'isGymEnabled',
        'membership-policy'   => 'isGymEnabled',
    ];

    if (!isset($map[$slug])) {
        return false;
    }

    $checker = $map[$slug];
    return function_exists($checker) && !$checker();
}

/**
 * Get the action to take when booking is disabled
 * 
 * @return string One of: 'message', 'contact', 'redirect'
 */
function getBookingDisabledAction(): string {
    return getSetting('booking_disabled_action', 'message');
}

/**
 * Get the custom message to display when booking is disabled
 * 
 * @return string HTML message
 */
function getBookingDisabledMessage(): string {
    $message = getSetting('booking_disabled_message', 'For booking inquiries, please contact us directly.');
    
    // Replace placeholders with actual contact info
    $phone = getSetting('phone_main', '');
    $email = getSetting('email_reservations', '');
    
    $message = str_replace('[contact info]', "Phone: {$phone} | Email: {$email}", $message);
    $message = str_replace('[phone]', $phone, $message);
    $message = str_replace('[email]', $email, $message);
    
    return $message;
}

/**
 * Render booking disabled content based on settings
 * 
 * @param string $size Size of the message: 'full', 'widget', or 'button'
 * @return string HTML content
 */
function renderBookingDisabledContent(string $size = 'full'): string {
    $action = getBookingDisabledAction();
    $message = getBookingDisabledMessage();
    $phone = getSetting('phone_main', '');
    $email = getSetting('email_reservations', '');
    $siteName = getSetting('site_name', 'Our Hotel');
    
    $html = '';
    
    if ($size === 'button') {
        // Compact button replacement
        $html = '<a href="mailto:' . htmlspecialchars($email) . '" class="btn btn-primary booking-disabled-btn">';
        $html .= '<i class="fas fa-envelope"></i> Contact for Availability';
        $html .= '</a>';
    } elseif ($size === 'widget') {
        // Widget-sized message
        $html = '<div class="booking-disabled-widget">';
        $html .= '<div class="booking-disabled-icon"><i class="fas fa-calendar-times"></i></div>';
        $html .= '<h4>Online Booking Temporarily Unavailable</h4>';
        $html .= '<p>' . $message . '</p>';
        if ($phone) {
            $html .= '<a href="tel:' . preg_replace('/[^0-9+]/', '', $phone) . '" class="booking-disabled-contact">';
            $html .= '<i class="fas fa-phone"></i> ' . htmlspecialchars($phone);
            $html .= '</a>';
        }
        if ($email) {
            $html .= '<a href="mailto:' . htmlspecialchars($email) . '" class="booking-disabled-contact">';
            $html .= '<i class="fas fa-envelope"></i> ' . htmlspecialchars($email);
            $html .= '</a>';
        }
        $html .= '</div>';
    } else {
        // Full page message
        $html = '<div class="booking-disabled-container">';
        $html .= '<div class="booking-disabled-content">';
        $html .= '<div class="booking-disabled-icon"><i class="fas fa-concierge-bell"></i></div>';
        $html .= '<h2>Reservations</h2>';
        $html .= '<p class="booking-disabled-subtitle">We\'d love to help you with your reservation</p>';
        $html .= '<div class="booking-disabled-message">' . $message . '</div>';
        
        if ($action === 'contact' || $action === 'message') {
            $html .= '<div class="booking-disabled-contacts">';
            if ($phone) {
                $html .= '<a href="tel:' . preg_replace('/[^0-9+]/', '', $phone) . '" class="booking-contact-card">';
                $html .= '<i class="fas fa-phone-alt"></i>';
                $html .= '<span>Call Us</span>';
                $html .= '<strong>' . htmlspecialchars($phone) . '</strong>';
                $html .= '</a>';
            }
            if ($email) {
                $html .= '<a href="mailto:' . htmlspecialchars($email) . '" class="booking-contact-card">';
                $html .= '<i class="fas fa-envelope"></i>';
                $html .= '<span>Email Us</span>';
                $html .= '<strong>' . htmlspecialchars($email) . '</strong>';
                $html .= '</a>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div></div>';
    }
    
    return $html;
}

/**
 * Get booking system settings
 * 
 * @return array All booking-related settings
 */
function getBookingSettings(): array {
    return [
        'enabled' => isBookingEnabled(),
        'disabled_action' => getBookingDisabledAction(),
        'disabled_message' => getBookingDisabledMessage(),
        'max_advance_days' => (int)getSetting('max_advance_booking_days', 30),
        'currency_symbol' => getSetting('currency_symbol', '$'),
        'payment_policy' => getSetting('payment_policy', ''),
        'tentative_duration_hours' => (int)getSetting('tentative_duration_hours', 48),
        'vat_enabled' => getSetting('vat_enabled', '0') === '1',
        'vat_rate' => (float)getSetting('vat_rate', 0),
        'booking_reference_prefix' => getSetting('booking_reference_prefix', 'BK'),
    ];
}

/**
 * Output booking button or disabled message
 * 
 * @param int $roomId Room ID to book
 * @param string $roomName Room name for display
 * @param string $class Additional CSS classes
 * @return void
 */
function renderBookingButton(int $roomId, string $roomName = '', string $class = ''): void {
    if (isBookingEnabled()) {
        $url = 'booking.php?room_id=' . $roomId;
        echo '<a href="' . htmlspecialchars($url) . '" class="btn btn-primary ' . htmlspecialchars($class) . '">';
        echo '<i class="fas fa-calendar-check"></i> Book Now';
        echo '</a>';
    }
}

/**
 * Output booking widget or disabled message
 * 
 * @return void
 */
function renderBookingWidget(): void {
    if (isBookingEnabled()) {
        include __DIR__ . '/booking-widget.php';
    }
}

/**
 * Check if user can access booking page
 * Redirects to home if booking is disabled
 * 
 * @return void
 */
function requireBookingEnabled(): void {
    if (!isBookingEnabled()) {
        // Log attempt
        error_log('Booking page accessed while booking system disabled');
        
        // Redirect based on action
        $action = getBookingDisabledAction();
        if ($action === 'redirect') {
            $redirectUrl = getSetting('booking_disabled_redirect_url', defined('BASE_URL') ? BASE_URL : '/');
            header('Location: ' . $redirectUrl);
        } else {
            // Show message page
            http_response_code(503);
            echo '<!DOCTYPE html><html><head><title>Booking Unavailable</title>';
            echo '';
            echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">';
            echo '</head><body>';
            echo '<div class="container">';
            echo '<div class="icon"><i class="fas fa-calendar-times"></i></div>';
            echo '<h1>Online Booking Unavailable</h1>';
            echo '<div>' . getBookingDisabledMessage() . '</div>';
            echo '<p style="margin-top: 20px;"><a href="/"><i class="fas fa-arrow-left"></i> Return to Homepage</a></p>';
            echo '</div></body></html>';
        }
        exit;
    }
}

/**
 * Render an on-brand "temporarily unavailable" state for a guest-facing
 * feature page (conference, gym) whose module has been switched off in
 * the admin Module Settings page, or disabled via its own setting.
 * Renders inline (keeps the site header/footer) rather than redirecting
 * the visitor away with no explanation.
 */
function renderFeatureDisabledPage(string $icon, string $heading, string $subtitle, string $message): void {
    $phone = getSetting('phone_main', '');
    $email = getSetting('email_reservations', '');

    echo '<div class="feature-disabled-container">';
    echo '<div class="feature-disabled-content">';
    echo '<div class="feature-disabled-icon"><i class="' . htmlspecialchars($icon) . '"></i></div>';
    echo '<h1>' . htmlspecialchars($heading) . '</h1>';
    echo '<p class="feature-disabled-subtitle">' . htmlspecialchars($subtitle) . '</p>';
    echo '<p class="feature-disabled-message">' . htmlspecialchars($message) . '</p>';

    if ($phone || $email) {
        echo '<div class="feature-disabled-contacts">';
        if ($phone) {
            echo '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone)) . '" class="feature-disabled-contact-card">';
            echo '<i class="fas fa-phone-alt"></i><span>Call Us</span><strong>' . htmlspecialchars($phone) . '</strong>';
            echo '</a>';
        }
        if ($email) {
            echo '<a href="mailto:' . htmlspecialchars($email) . '" class="feature-disabled-contact-card">';
            echo '<i class="fas fa-envelope"></i><span>Email Us</span><strong>' . htmlspecialchars($email) . '</strong>';
            echo '</a>';
        }
        echo '</div>';
    }

    echo '<a href="' . htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/') . '" class="feature-disabled-back">';
    echo '<i class="fas fa-arrow-left"></i> Back to Home';
    echo '</a>';
    echo '</div></div>';
}

function requireRestaurantEnabled(): void {
    if (!isRestaurantEnabled()) {
        error_log('Restaurant page accessed while restaurant system disabled');
        http_response_code(503);
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'));
        exit;
    }
}

function isEventsEnabled(): bool {
    return getSetting('events_system_enabled', '1') === '1';
}

function requireEventsEnabled(): void {
    if (!isEventsEnabled()) {
        error_log('Events page accessed while events system disabled');
        http_response_code(503);
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'));
        exit;
    }
}

function isGuestServicesEnabled(): bool {
    return getSetting('guest_services_system_enabled', '1') === '1';
}

function requireGuestServicesEnabled(): void {
    if (!isGuestServicesEnabled()) {
        error_log('Guest services page accessed while guest services disabled');
        http_response_code(503);
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/'));
        exit;
    }
}

/**
 * Output CSS for booking disabled states
 * 
 * @return void
 */
function outputBookingDisabledStyles(): void {
    if (isBookingEnabled()) {
        return;
    }
    ?><?php
}

// ============================================================================
// AVAILABILITY AND VALIDATION FUNCTIONS (Extracted for portability)
// ============================================================================

/**
 * Check room availability
 * This function wraps the main availability check for portability
 */
function checkAvailability(int $roomId, string $checkIn, string $checkOut): array {
    // Use existing function from database.php if available
    if (function_exists('checkRoomAvailability')) {
        return checkRoomAvailability($roomId, $checkIn, $checkOut);
    }
    
    // Fallback implementation
    global $pdo;
    
    $result = [
        'available' => true,
        'conflicts' => [],
        'room' => null
    ];
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            $result['available'] = false;
            $result['error'] = 'Room not found';
            return $result;
        }
        
        $result['room'] = $room;

        // Check for blocked dates
        $blockedStmt = $pdo->prepare("
            SELECT COUNT(*) FROM blocked_dates
            WHERE block_date >= ? AND block_date < ?
            AND (room_id = ? OR room_id IS NULL)
        ");
        $blockedStmt->execute([$checkIn, $checkOut, $roomId]);
        if ($blockedStmt->fetchColumn() > 0) {
            $result['available'] = false;
            $result['error'] = 'Selected dates are blocked';
            return $result;
        }

        // Check for overlapping bookings
        // Note: 'tentative' bookings do NOT block availability (can be overwritten)
        // Note: 'cancelled' bookings do NOT block availability (free up the room)
        $bookingsStmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE room_id = ?
            AND status IN ('pending', 'confirmed', 'checked-in')
            AND NOT (check_out_date <= ? OR check_in_date >= ?)
        ");
        $bookingsStmt->execute([$roomId, $checkIn, $checkOut]);
        $overlappingBookings = (int)$bookingsStmt->fetchColumn();
        
        if ($overlappingBookings >= $room['rooms_available']) {
            $result['available'] = false;
            $result['error'] = 'No rooms available for selected dates';
        }
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Availability check error: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Database error';
        return $result;
    }
}

/**
 * Create a pending refund record for a no-show paid booking.
 *
 * Driven by site setting 'no_show_refund_policy':
 *   none        — no automatic refund (default)
 *   first_night — retain one night's rate; refund the remainder
 *   full        — refund the entire amount_paid
 *
 * @return array{created: bool, refund_ref: string, refund_amount: float, policy: string}
 */
function createNoShowRefund(array $booking, int $adminUserId, PDO $pdo): array
{
    $amountPaid = (float)($booking['amount_paid'] ?? 0);
    if ($amountPaid <= 0) {
        return ['created' => false, 'refund_ref' => '', 'refund_amount' => 0.0, 'policy' => 'none'];
    }

    $policy = getSetting('no_show_refund_policy', 'none');

    $refundAmount = 0.0;
    if ($policy === 'full') {
        $refundAmount = $amountPaid;
    } elseif ($policy === 'first_night') {
        $nights      = max(1, (int)($booking['number_of_nights'] ?? 1));
        $nightlyRate = (float)($booking['total_amount'] ?? 0) / $nights;
        $refundAmount = max(0.0, round($amountPaid - $nightlyRate, 2));
    }

    if ($refundAmount <= 0) {
        return ['created' => false, 'refund_ref' => '', 'refund_amount' => 0.0, 'policy' => $policy];
    }

    // Find the most recent completed payment for this booking
    $payStmt = $pdo->prepare("
        SELECT id, payment_method, booking_type, booking_reference, vat_rate
        FROM payments
        WHERE booking_id = ? AND COALESCE(payment_type, '') != 'refund'
          AND payment_status IN ('completed','paid')
        ORDER BY payment_date DESC, id DESC
        LIMIT 1
    ");
    $payStmt->execute([(int)$booking['id']]);
    $originalPayment = $payStmt->fetch(PDO::FETCH_ASSOC);

    $vatRate   = (float)($originalPayment['vat_rate'] ?? 0);
    $vatAmount = round($refundAmount * ($vatRate / (100 + $vatRate)), 2);
    $payAmount = $refundAmount - $vatAmount;
    $refundRef = 'REF-' . date('Y') . '-' . str_pad((string)rand(1, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status,
            original_payment_id, refund_reason, refund_status, refund_amount, refund_notes,
            recorded_by, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, 'refund', 'pending',
            ?, 'cancellation', 'pending', ?, ?,
            ?, NOW()
        )
    ")->execute([
        $refundRef,
        $originalPayment['booking_type'] ?? 'room',
        (int)$booking['id'],
        $booking['booking_reference'],
        date('Y-m-d'),
        $payAmount, $vatRate, $vatAmount, $refundAmount,
        $originalPayment['payment_method'] ?? 'other',
        $originalPayment['id'] ?? null,
        $refundAmount,
        'No-show auto-refund (' . $policy . ' policy) — pending admin review and processing.',
        $adminUserId,
    ]);

    if (function_exists('rh_log_event')) {
        rh_log_event('noshow-refund', 'info', 'Pending refund created for no-show booking', [
            'booking_id'    => $booking['id'],
            'booking_ref'   => $booking['booking_reference'],
            'refund_ref'    => $refundRef,
            'refund_amount' => $refundAmount,
            'policy'        => $policy,
            'admin_id'      => $adminUserId,
        ]);
    }

    return [
        'created'       => true,
        'refund_ref'    => $refundRef,
        'refund_amount' => $refundAmount,
        'policy'        => $policy,
    ];
}
