<?php

/**
 * Guest Services Page
 * Central hub for all hotel guest services - links to gym, conference, restaurant,
 * events, booking, and other amenities. Each section includes a hero, description,
 * and proper redirect to the dedicated service page.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/booking-functions.php';
require_once 'includes/page-guard.php';
requireGuestServicesEnabled();
require_once 'includes/modal.php';
require_once 'includes/section-headers.php';
require_once 'includes/image-proxy-helper.php';

// Fetch site settings
$site_name      = getSetting('site_name');
$site_logo      = getSetting('site_logo');
$email_main     = getSetting('email_main');
$phone_main     = getSetting('phone_main');
$currency_symbol = getSetting('currency_symbol');

// Feature toggles
$bookingEnabled    = isBookingEnabled();
$conferenceEnabled = isConferenceEnabled();
$gymEnabled        = isGymEnabled();
$restaurantEnabled = isRestaurantEnabled();
$eventsEnabled     = isEventsEnabled();

// Ensure guest_services table exists (check once, not on every request)
if (empty($_SESSION['_gs_table_checked'])) {
    try {
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'guest_services'")->rowCount();
        if (!$tableExists) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS guest_services (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_key VARCHAR(50) NOT NULL,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                icon_class VARCHAR(100) DEFAULT 'fas fa-concierge-bell',
                image_path VARCHAR(500) DEFAULT NULL,
                link_url VARCHAR(500) DEFAULT NULL,
                link_text VARCHAR(100) DEFAULT 'Learn More',
                display_order INT UNSIGNED DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_service_key (service_key),
                KEY idx_active_order (is_active, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $_SESSION['_gs_table_checked'] = true;
    } catch (PDOException $e) {
        error_log("guest_services table check: " . $e->getMessage());
    }
}

// Fetch services from DB
$dbServices = [];
try {
    $stmt = $pdo->query("SELECT * FROM guest_services WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $dbServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table may be empty or just created
}

// Fetch facilities from DB (to merge with services)
$dbFacilities = [];
try {
    // Check if table exists first to avoid errors
    $tableExists = $pdo->query("SHOW TABLES LIKE 'facilities'")->rowCount() > 0;
    if ($tableExists) {
        $stmt = $pdo->query("SELECT * FROM facilities WHERE is_active = 1 ORDER BY display_order ASC");
        $dbFacilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignore if facilities table issue
}

// Merge and normalize data
$unifiedServices = [];

// 1. Add Guest Services from DB
foreach ($dbServices as $service) {
    $unifiedServices[] = [
        'type'        => 'service',
        'key'         => $service['service_key'],
        'title'       => $service['title'],
        'description' => $service['description'],
        'icon'        => $service['icon_class'],
        'image'       => $service['image_path'],
        'link'        => $service['link_url'],
        'cta'         => $service['link_text'] ?? 'Learn More',
        'order'       => $service['display_order'] ?? 999
    ];
}

// 2. Add Facilities from DB
foreach ($dbFacilities as $facility) {
    $unifiedServices[] = [
        'type'        => 'facility',
        'key'         => 'facility_' . $facility['id'],
        'title'       => $facility['name'],
        'description' => $facility['short_description'],
        'icon'        => $facility['icon_class'] ?? 'fas fa-star',
        'image'       => $facility['image_url'],
        'link'        => $facility['page_url'] ?? null,
        'cta'         => 'View Details',
        'order'       => ($facility['display_order'] ?? 999) + 100 // Offset to put after primary services
    ];
}

// 3. Fallback/Hardcoded Services if empty
if (empty($unifiedServices)) {
    if ($restaurantEnabled) {
        $unifiedServices[] = [
            'type'        => 'core',
            'key'         => 'restaurant',
            'title'       => 'Fine Dining Restaurant',
            'description' => 'Experience exquisite cuisine crafted by our talented chefs. From local delicacies to international dishes, our restaurant offers a culinary journey like no other.',
            'icon'        => 'fas fa-utensils',
            'image'       => 'images/restaurant/image.png',
            'link'        => 'restaurant.php',
            'cta'         => 'View Restaurant',
            'order'       => 10
        ];
    }

    if ($gymEnabled) {
        $unifiedServices[] = [
            'type'        => 'core',
            'key'         => 'gym',
            'title'       => 'Fitness & Wellness Center',
            'description' => 'Stay fit during your stay with our fully equipped fitness center. Personal training, group classes, and wellness packages available for all guests.',
            'icon'        => 'fas fa-dumbbell',
            'image'       => 'images/gym/fitness-center.jpg',
            'link'        => 'gym.php',
            'cta'         => 'Explore Gym',
            'order'       => 20
        ];
    }

    if ($conferenceEnabled) {
        $unifiedServices[] = [
            'type'        => 'core',
            'key'         => 'conference',
            'title'       => 'Conference & Meeting Rooms',
            'description' => 'Host your corporate events, meetings, and conferences in our state-of-the-art venues. Full AV equipment, catering, and dedicated event coordination.',
            'icon'        => 'fas fa-briefcase',
            'image'       => 'images/conference/conference_room.jpeg',
            'link'        => 'conference.php',
            'cta'         => 'Book Conference',
            'order'       => 30
        ];
    }

    if ($eventsEnabled) {
        $unifiedServices[] = [
            'type'        => 'core',
            'key'         => 'events',
            'title'       => 'Events & Entertainment',
            'description' => 'Discover upcoming events, live entertainment, and special occasions at our hotel. From business breakfasts to gala dinners, there is always something happening.',
            'icon'        => 'fas fa-calendar-alt',
            'image'       => 'images/hero/slide1.jpeg',
            'link'        => 'events.php',
            'cta'         => 'View Events',
            'order'       => 40
        ];
    }

    if ($bookingEnabled) {
        $unifiedServices[] = [
            'type'        => 'core',
            'key'         => 'rooms',
            'title'       => 'Rooms & Accommodation',
            'description' => 'Discover our luxurious rooms and suites, each designed for comfort and elegance. Book your perfect stay with us today.',
            'icon'        => 'fas fa-bed',
            'image'       => 'images/rooms/Deluxe_Room.jpg',
            'link'        => 'booking.php',
            'cta'         => 'Book a Room',
            'order'       => 50
        ];
    }

    $unifiedServices[] = [
        'type'        => 'core',
        'key'         => 'concierge',
        'title'       => 'Concierge Services',
        'description' => 'Our dedicated concierge team is available 24/7 to assist with transportation, tours, restaurant reservations, and any special requests to make your stay memorable.',
        'icon'        => 'fas fa-concierge-bell',
        'image'       => null,
        'link'        => 'contact-us.php',
        'cta'         => 'Contact Concierge',
        'order'       => 60
    ];
}

// Sort by order
usort($unifiedServices, function ($a, $b) {
    return $a['order'] <=> $b['order'];
});

// Contact info for CTA
$contact_phone = getSetting('phone_main');
$contact_email = getSetting('email_main');
$whatsapp      = getSetting('whatsapp_number');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php
    $seo_data = [
        'title' => 'Guest Services - ' . $site_name,
        'description' => "Explore all guest services at {$site_name}. Restaurant, gym, conference rooms, events, concierge, and more.",
        'image' => '/images/hero/slide1.jpeg',
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
    <link rel="stylesheet" href="css/main.css">

    <!-- Session & JS core -->
    <script src="js/session-handler.js" defer></script>

</head>

<body class="guest-services-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <main id="main-content">
        <!-- Services Grid -->
        <section class="gs-section" id="services">
            <div class="container">
                <?php renderSectionHeader('guest_services_main', 'guest-services', [
                    'label' => 'At Your Service',
                    'title' => 'Curated Experiences',
                    'description' => 'Discover the exceptional amenities and personalized services designed to make your stay unforgettable.'
                ], 'text-center'); ?>

                <div class="gs-grid" id="gs-grid">
                    <?php foreach ($unifiedServices as $index => $service):
                        // Calculate delay for scroll reveal
                        $delay = ($index % 3) * 100;
                    ?>
                        <div class="card room-card gs-variant reveal-on-scroll" data-scroll-delay="<?php echo $delay; ?>">
                            <div class="room-card-image">
                                <?php if (!empty($service['image'])): ?>
                                    <img src="<?php echo htmlspecialchars(function_exists('proxyImageUrl') ? proxyImageUrl($service['image']) : $service['image']); ?>"
                                        alt="<?php echo htmlspecialchars($service['title']); ?>"
                                        loading="lazy" decoding="async"
                                        class="room-card-image-img">
                                <?php else: ?>
                                    <div class="room-card-image-placeholder" style="width:100%; height:100%; background:#f5f5f5; display:flex; align-items:center; justify-content:center;">
                                        <i class="<?php echo htmlspecialchars($service['icon']); ?>" style="font-size:3rem; color:#8B7355; opacity:0.3;"></i>
                                    </div>
                                <?php endif; ?>

                                <!-- Badge for type distinction -->
                                <?php if ($service['type'] === 'facility'): ?>
                                    <div class="room-card-badge">Facility</div>
                                <?php endif; ?>
                            </div>

                            <div class="room-card-content">
                                <div class="room-card-header">
                                    <h3 class="room-card-title"><?php echo htmlspecialchars($service['title']); ?></h3>
                                    <div class="room-card-meta">
                                        <span><i class="<?php echo htmlspecialchars($service['icon']); ?>"></i> Service</span>
                                    </div>
                                </div>

                                <p class="room-card-description"><?php echo htmlspecialchars($service['description']); ?></p>

                                <div class="room-card-actions">
                                    <?php if (!empty($service['link'])): ?>
                                        <a href="<?php echo htmlspecialchars($service['link']); ?>" class="editorial-btn-primary">
                                            <?php echo htmlspecialchars($service['cta']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="editorial-btn-primary disabled" style="opacity:0.7; cursor:default;">
                                            Available on Request
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Contact Banner -->
        <section class="gs-contact-banner reveal-on-scroll rh-reveal" id="gs-contact-banner">
            <div class="container">
                <h2>Concierge at Your Service</h2>
                <p>Our dedicated team is available 24/7 to fulfill any request, from restaurant reservations to private excursions.</p>
                <div class="gs-contact-actions">
                    <a href="contact-us.php" class="btn-premium-gold">
                        <i class="fas fa-concierge-bell"></i> Contact Concierge
                    </a>
                    <?php if (!empty($contact_phone)): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact_phone)); ?>" class="btn-premium-outline">
                            <i class="fas fa-phone-alt"></i> Call Front Desk
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($whatsapp)): ?>
                        <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $whatsapp)); ?>" target="_blank" rel="noopener" class="btn-premium-outline">
                            <i class="fab fa-whatsapp"></i> WhatsApp Us
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </main>

    <?php include 'includes/footer.php'; ?>
    <?php include 'includes/modal.php'; ?>
    <script src="js/scroll-reveal.js" defer></script>
</body>

</html>
