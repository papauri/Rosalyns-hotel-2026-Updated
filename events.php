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
    </main>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>
</body>

</html>
