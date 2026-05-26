<?php
/**
 * Header Component
 * Rosalyn's Hotel 2026
 * Clean, modern header with mobile-first navigation
 */

// Load base URL helper
require_once __DIR__ . '/../config/base-url.php';
if (!function_exists('isBookingEnabled')) {
    require_once __DIR__ . '/booking-functions.php';
}

// Ensure $site_name and $site_logo are available regardless of the including page
/** @var string $site_name */
$site_name = isset($site_name) ? (string) $site_name : (function_exists('getSetting') ? (string) getSetting('site_name', 'Hotel') : 'Hotel');
/** @var string $site_logo */
$site_logo = isset($site_logo) ? (string) $site_logo : (function_exists('getSetting') ? (string) getSetting('site_logo', '') : '');

// Header logo kicker/tagline source
$header_logo_kicker = '';

if (isset($site_tagline) && is_string($site_tagline)) {
    $header_logo_kicker = trim($site_tagline);
}

if ($header_logo_kicker === '' && function_exists('getSetting')) {
    $header_logo_kicker = trim((string) getSetting('site_tagline', ''));
}

if ($header_logo_kicker === '') {
    $header_logo_kicker = isset($site_name) ? trim((string) $site_name) : '';
}
?>
<!-- Skip to content link for accessibility -->
<a href="#main-content" class="skip-to-content">Skip to main content</a>

<header class="header" role="banner">
    <div class="header__container">
        <nav class="header__nav" role="navigation" aria-label="Main navigation">
            <div class="header__brand">
                <a href="<?php echo siteUrl('/'); ?>" class="header__logo" aria-label="<?php echo htmlspecialchars($site_name); ?> - Go to home">
                    <span class="header__logo-media" aria-hidden="true">
                        <?php if (!empty($site_logo)): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="" class="header__logo-image" loading="eager" decoding="async" fetchpriority="high" />
                        <?php endif; ?>
                    </span>
                    <span class="header__logo-copy">
                        <span class="header__logo-kicker"><?php echo htmlspecialchars($header_logo_kicker); ?></span>
                        <span class="header__logo-text"><?php echo htmlspecialchars($site_name); ?></span>
                    </span>
                </a>
            </div>

            <?php
            // Determine current page for active nav highlighting
            $current_file = basename($_SERVER['PHP_SELF']);

            // Function to check if nav link is active
            function is_nav_active(string $link_file): bool {
                global $current_file;
                $link_base = basename($link_file);

                if ($current_file === $link_base) {
                    return true;
                }

                // Special case: room.php highlights "Rooms" nav
                if ($current_file === 'room.php' && $link_base === 'rooms-gallery.php') {
                    return true;
                }

                return false;
            }

            // Load pages from site_pages table
            $_nav_pages = [];
            $_nav_booking = null;

            try {
                if (isset($pdo)) {
                    $nav_stmt = $pdo->query("
                        SELECT page_key, title, file_path, icon
                        FROM site_pages
                        WHERE is_enabled = 1 AND show_in_nav = 1
                        ORDER BY nav_position ASC
                    ");
                    $all_nav = $nav_stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($all_nav as $np) {
                        // Fix incorrect file_path values - remove 'api/' prefix if present
                        // Files like conference.php, restaurant.php, gym.php, events.php are in root, not api/
                        if (strpos($np['file_path'], 'api/') === 0) {
                            $np['file_path'] = substr($np['file_path'], 4); // Remove 'api/' prefix
                        }

                        if ($np['page_key'] === 'booking') {
                            $_nav_booking = $np;
                        } else {
                            $_nav_pages[] = $np;
                        }
                    }
                }
            } catch (PDOException $e) {
                $_nav_pages = null;
            }

            // Fallback to hardcoded nav
            if (empty($_nav_pages) && $_nav_pages !== []) {
                $_nav_pages = [
                    ['page_key' => 'home',       'title' => 'Home',       'file_path' => 'index.php',        'icon' => 'fa-home'],
                    ['page_key' => 'rooms',      'title' => 'Rooms',      'file_path' => 'rooms-gallery.php','icon' => 'fa-bed'],
                    ['page_key' => 'restaurant', 'title' => 'Restaurant', 'file_path' => 'restaurant.php',   'icon' => 'fa-utensils'],
                    ['page_key' => 'gym',        'title' => 'Gym',        'file_path' => 'gym.php',          'icon' => 'fa-dumbbell'],
                    ['page_key' => 'conference', 'title' => 'Conference', 'file_path' => 'conference.php',   'icon' => 'fa-briefcase'],
                    ['page_key' => 'events',     'title' => 'Events',     'file_path' => 'events.php',       'icon' => 'fa-calendar-alt'],
                ];
                $_nav_booking = ['page_key' => 'booking', 'title' => 'Book Now', 'file_path' => 'booking.php', 'icon' => 'fa-calendar-check'];
            }

            // Apply feature toggles from booking/settings module
            $bookingEnabled = function_exists('isBookingEnabled') ? isBookingEnabled() : true;
            $conferenceEnabled = function_exists('isConferenceEnabled') ? isConferenceEnabled() : true;
            $gymEnabled = function_exists('isGymEnabled') ? isGymEnabled() : true;
            $restaurantEnabled = function_exists('isRestaurantEnabled') ? isRestaurantEnabled() : true;

            $_nav_pages = array_values(array_filter($_nav_pages, function ($navp) use ($conferenceEnabled, $gymEnabled, $restaurantEnabled) {
                $key = $navp['page_key'] ?? '';
                if ($key === 'conference' && !$conferenceEnabled) {
                    return false;
                }
                if ($key === 'gym' && !$gymEnabled) {
                    return false;
                }
                if ($key === 'restaurant' && !$restaurantEnabled) {
                    return false;
                }
                return true;
            }));

            if (!$bookingEnabled) {
                $_nav_booking = null;
            }
            ?>

            <!-- Desktop Navigation -->
            <ul class="header__menu">
                <?php foreach ($_nav_pages as $navp): ?>
                <li class="header__menu-item">
                    <a href="<?php echo siteUrl($navp['file_path']); ?>"
                       class="header__menu-link <?php echo is_nav_active($navp['file_path']) ? 'header__menu-link--active' : ''; ?>">
                        <?php echo htmlspecialchars($navp['title']); ?>
                    </a>
                </li>
                <?php endforeach; ?>

            </ul>

            <div class="header__actions">
                <?php if ($_nav_booking): ?>
                <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>"
                   class="header__cta <?php echo is_nav_active($_nav_booking['file_path']) ? 'header__cta--active' : ''; ?>"
                   aria-label="<?php echo htmlspecialchars($_nav_booking['title']); ?>">
                    <?php if (!empty($_nav_booking['icon'])): ?>
                    <i class="fas <?php echo htmlspecialchars($_nav_booking['icon']); ?>" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($_nav_booking['title']); ?></span>
                </a>
                <?php endif; ?>

                <!-- Mobile Menu Toggle -->
                <button class="header__toggle"
                        type="button"
                        aria-controls="mobile-menu"
                        aria-expanded="false"
                        aria-label="Toggle navigation menu"
                        data-mobile-toggle>
                    <span class="header__toggle-icon" aria-hidden="true">
                        <span class="header__toggle-line"></span>
                        <span class="header__toggle-line"></span>
                        <span class="header__toggle-line"></span>
                    </span>
                </button>
            </div>
        </nav>
    </div>
</header>

<!-- Mobile Menu Overlay -->
<div class="header__overlay" data-mobile-overlay aria-hidden="true"></div>

<!-- Mobile Menu Panel -->
<div class="header__mobile" id="mobile-menu" role="dialog" aria-modal="true" aria-label="Navigation menu">
    <div class="header__mobile-header">
        <span class="header__mobile-title">Navigate</span>
        <button class="header__mobile-close"
                type="button"
                aria-label="Close menu"
                data-mobile-close>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>

    <nav class="header__mobile-nav" aria-label="Mobile navigation">
        <p class="header__mobile-eyebrow"><?php echo htmlspecialchars($site_name); ?></p>
        <ul class="header__mobile-list">
            <?php foreach ($_nav_pages as $navp): ?>
            <li class="header__mobile-item">
                <a href="<?php echo siteUrl($navp['file_path']); ?>"
                   class="header__mobile-link <?php echo is_nav_active($navp['file_path']) ? 'header__mobile-link--active' : ''; ?>">
                    <?php echo htmlspecialchars($navp['title']); ?>
                </a>
            </li>
            <?php endforeach; ?>

        </ul>

        <?php if ($_nav_booking): ?>
        <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>"
           class="header__mobile-link header__mobile-link--cta <?php echo is_nav_active($_nav_booking['file_path']) ? 'header__mobile-link--active' : ''; ?>">
            <?php if (!empty($_nav_booking['icon'])): ?>
            <i class="fas <?php echo htmlspecialchars($_nav_booking['icon']); ?>" aria-hidden="true"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($_nav_booking['title']); ?>
        </a>
        <?php endif; ?>
    </nav>
</div>
