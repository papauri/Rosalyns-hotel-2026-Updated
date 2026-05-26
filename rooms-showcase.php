<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/page-guard.php';
require_once 'includes/reviews-display.php';
require_once 'includes/section-headers.php';
require_once 'includes/booking-functions.php';

$site_name = (string) getSetting('site_name', 'Rosalyns Hotel');
$currency_symbol = (string) getSetting('currency_symbol', 'MWK');
$phone_main = (string) getSetting('phone_main', '');
$email_reservations = (string) getSetting('email_reservations', '');

$rooms = [];
try {
    $stmt = $pdo->query(
        "SELECT id, slug, name, short_description, description, image_url, price_per_night,
                max_guests, size_sqm, bed_type, amenities, badge, is_featured
         FROM rooms
         WHERE is_active = 1
         ORDER BY is_featured DESC, display_order ASC, id ASC"
    );
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rooms) && function_exists('applyManagedMediaOverrides')) {
        foreach ($rooms as &$roomRow) {
            $roomRow = applyManagedMediaOverrides($roomRow, 'rooms', $roomRow['id'] ?? '', ['image_url']);
        }
        unset($roomRow);
    }
} catch (PDOException $e) {
    $rooms = [];
}

$requested_room_slug = trim((string)($_GET['room'] ?? ''));
$spotlight_room = !empty($rooms) ? $rooms[0] : null;

if ($requested_room_slug !== '' && !empty($rooms)) {
    foreach ($rooms as $candidate) {
        if ((string)($candidate['slug'] ?? '') === $requested_room_slug) {
            $spotlight_room = $candidate;
            break;
        }
    }
}

$spotlight_gallery = [];
if (!empty($spotlight_room['id'])) {
    try {
        $galleryStmt = $pdo->prepare(
            "SELECT id, title, image_url
             FROM gallery
             WHERE room_id = ?
               AND is_active = 1
               AND image_url IS NOT NULL
               AND image_url != ''
             ORDER BY display_order ASC, id ASC
             LIMIT 4"
        );
        $galleryStmt->execute([(int)$spotlight_room['id']]);
        $spotlight_gallery = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($spotlight_gallery) && function_exists('applyManagedMediaOverrides')) {
            foreach ($spotlight_gallery as &$galleryRow) {
                $galleryRow = applyManagedMediaOverrides($galleryRow, 'gallery', $galleryRow['id'] ?? '', ['image_url']);
            }
            unset($galleryRow);
        }
    } catch (PDOException $e) {
        $spotlight_gallery = [];
    }

    if (empty($spotlight_gallery) && !empty($spotlight_room['image_url'])) {
        $spotlight_gallery[] = [
            'id' => 0,
            'title' => $spotlight_room['name'],
            'image_url' => $spotlight_room['image_url'],
        ];
    }
}

$badge_map = ['all-rooms' => 'All Rooms'];
foreach ($rooms as $room) {
    $badge_label = trim((string)($room['badge'] ?? ''));
    if ($badge_label === '') {
        continue;
    }
    $badge_key = preg_replace('/[^a-z0-9]+/', '-', strtolower($badge_label));
    $badge_key = trim((string)$badge_key, '-');
    if ($badge_key === '') {
        continue;
    }
    $badge_map[$badge_key] = $badge_label;
}

$seo_data = [
    'title' => $site_name . ' | Rooms Showcase',
    'description' => "Explore all available rooms at {$site_name}, view details, and book instantly.",
    'type' => 'website',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php require_once 'includes/seo-meta.php'; ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <link rel="stylesheet" href="css/main.css">
</head>

<body class="rooms-page rooms-showcase-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <?php include 'includes/hero.php'; ?>

        <section class="rooms-showcase-spotlight editorial-section landing-section" data-lazy-reveal>
            <div class="container">
                <?php if ($spotlight_room): ?>
                    <div class="rooms-showcase-spotlight__layout">
                        <div class="rooms-showcase-spotlight__gallery">
                            <?php foreach ($spotlight_gallery as $index => $img): ?>
                                <figure class="rooms-showcase-spotlight__figure">
                                    <img
                                        src="<?php echo htmlspecialchars((string)$img['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars((string)($img['title'] ?? $spotlight_room['name']), ENT_QUOTES, 'UTF-8'); ?>"
                                        loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"
                                        class="rooms-showcase-spotlight__image">
                                    <figcaption class="rooms-showcase-spotlight__caption">
                                        <?php echo htmlspecialchars((string)($img['title'] ?? $spotlight_room['name']), ENT_QUOTES, 'UTF-8'); ?>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>

                        <div class="rooms-showcase-spotlight__summary">
                            <span class="rooms-showcase-spotlight__label">Featured Room</span>
                            <h1 class="rooms-showcase-spotlight__title"><?php echo htmlspecialchars((string)$spotlight_room['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                            <p class="rooms-showcase-spotlight__description"><?php echo htmlspecialchars((string)($spotlight_room['description'] ?: $spotlight_room['short_description']), ENT_QUOTES, 'UTF-8'); ?></p>

                            <div class="rooms-showcase-spotlight__meta">
                                <span><i class="fas fa-users" aria-hidden="true"></i> Up to <?php echo (int)($spotlight_room['max_guests'] ?? 2); ?> guests</span>
                                <span><i class="fas fa-ruler-combined" aria-hidden="true"></i> <?php echo htmlspecialchars((string)($spotlight_room['size_sqm'] ?? 40), ENT_QUOTES, 'UTF-8'); ?> sqm</span>
                                <span><i class="fas fa-bed" aria-hidden="true"></i> <?php echo htmlspecialchars((string)($spotlight_room['bed_type'] ?? 'Standard Bed'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><i class="fas fa-tag" aria-hidden="true"></i> <?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format((float)($spotlight_room['price_per_night'] ?? 0), 0); ?> / night</span>
                            </div>

                            <?php
                            $spotlight_amenities = array_values(array_filter(array_map('trim', explode(',', (string)($spotlight_room['amenities'] ?? '')))));
                            ?>
                            <?php if (!empty($spotlight_amenities)): ?>
                                <div class="rooms-showcase-spotlight__amenities" aria-label="Room amenities">
                                    <?php foreach (array_slice($spotlight_amenities, 0, 8) as $amenity): ?>
                                        <span class="rooms-showcase-spotlight__amenity"><i class="fas fa-check" aria-hidden="true"></i> <?php echo htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="rooms-showcase-spotlight__actions">
                                <a class="btn btn-outline" href="room.php?room=<?php echo urlencode((string)$spotlight_room['slug']); ?>">Book Details</a>
                                <?php if (isBookingEnabled()): ?>
                                    <a class="btn btn-primary" href="booking.php?room_id=<?php echo (int)$spotlight_room['id']; ?>">Book Now</a>
                                <?php else: ?>
                                    <button class="btn btn-primary" type="button" disabled aria-disabled="true">Booking Disabled</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="editorial-no-events mt-50">
                        <i class="fas fa-bed"></i>
                        <h3>Rooms are not available yet</h3>
                        <p>Please check again shortly or contact reservations for assistance.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="rooms-showcase-collection editorial-section landing-section" id="collection" data-lazy-reveal>
            <div class="container">
                <?php renderSectionHeader('rooms_collection', 'rooms-showcase', [
                    'label' => 'Stay Collection',
                    'title' => 'Choose Your Perfect Room',
                    'description' => 'Browse each room, open detailed specs, and book instantly with the correct reservation flow.'
                ]); ?>

                <?php if (!empty($rooms)): ?>
                    <div class="rooms-showcase-filter" role="toolbar" aria-label="Filter rooms by category">
                        <?php foreach ($badge_map as $badge_key => $badge_label): ?>
                            <button
                                type="button"
                                class="chip rooms-showcase-filter__chip<?php echo $badge_key === 'all-rooms' ? ' active' : ''; ?>"
                                data-filter="<?php echo htmlspecialchars($badge_key, ENT_QUOTES, 'UTF-8'); ?>"
                                aria-pressed="<?php echo $badge_key === 'all-rooms' ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($badge_label, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="card-grid rooms-showcase-grid" data-room-count="<?php echo (int)count($rooms); ?>">
                        <?php foreach ($rooms as $room): ?>
                            <?php
                            $room_badge_label = trim((string)($room['badge'] ?? ''));
                            $room_badge_key = $room_badge_label !== ''
                                ? trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($room_badge_label)), '-')
                                : '';
                            $room_filter_tags = 'all-rooms' . ($room_badge_key !== '' ? ' ' . $room_badge_key : '');
                            $room_amenities = array_values(array_filter(array_map('trim', explode(',', (string)($room['amenities'] ?? '')))));
                            $room_amenities = array_slice($room_amenities, 0, 4);
                            ?>
                            <article
                                class="card room-card rooms-showcase-card<?php echo !empty($room['is_featured']) ? ' featured-card' : ''; ?>"
                                data-filter-tags="<?php echo htmlspecialchars($room_filter_tags, ENT_QUOTES, 'UTF-8'); ?>">
                                <a class="room-card-image" href="room.php?room=<?php echo urlencode((string)$room['slug']); ?>" aria-label="Open details for <?php echo htmlspecialchars((string)$room['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <img
                                        src="<?php echo htmlspecialchars((string)$room['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars((string)$room['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        class="room-card-image-img"
                                        loading="lazy">
                                    <?php if ($room_badge_label !== ''): ?>
                                        <span class="room-card-badge"><?php echo htmlspecialchars($room_badge_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <span class="room-card-price">
                                        <span class="room-card-price-amount"><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format((float)($room['price_per_night'] ?? 0), 0); ?></span>
                                        <small class="room-card-price-label">per night</small>
                                    </span>
                                </a>

                                <div class="room-card-content">
                                    <h3 class="room-card-title"><?php echo htmlspecialchars((string)$room['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p class="room-card-description"><?php echo htmlspecialchars((string)($room['short_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

                                    <div class="rooms-showcase-card__rating room-tile__rating" data-room-id="<?php echo (int)$room['id']; ?>">
                                        <div class="compact-rating compact-rating--loading">
                                            <i class="fas fa-spinner fa-spin"></i>
                                        </div>
                                    </div>

                                    <div class="room-card-meta">
                                        <span><i class="fas fa-user-friends"></i> <?php echo htmlspecialchars((string)($room['max_guests'] ?? 2), ENT_QUOTES, 'UTF-8'); ?> guests</span>
                                        <span><i class="fas fa-ruler-combined"></i> <?php echo htmlspecialchars((string)($room['size_sqm'] ?? 40), ENT_QUOTES, 'UTF-8'); ?> sqm</span>
                                        <span><i class="fas fa-bed"></i> <?php echo htmlspecialchars((string)($room['bed_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>

                                    <?php if (!empty($room_amenities)): ?>
                                        <div class="room-card-amenities">
                                            <?php foreach ($room_amenities as $amenity): ?>
                                                <span class="room-card-amenity"><i class="fas fa-check"></i> <?php echo htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="rooms-showcase-card__actions">
                                        <a class="btn btn-outline rooms-showcase-card__btn" href="room.php?room=<?php echo urlencode((string)$room['slug']); ?>">Book Details</a>
                                        <?php if (isBookingEnabled()): ?>
                                            <a class="btn btn-primary rooms-showcase-card__btn" href="booking.php?room_id=<?php echo (int)$room['id']; ?>">Book Now</a>
                                        <?php else: ?>
                                            <button class="btn btn-primary rooms-showcase-card__btn" type="button" disabled aria-disabled="true">Booking Disabled</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="editorial-no-events mt-50">
                        <i class="fas fa-door-closed"></i>
                        <h3>No rooms are currently listed</h3>
                        <p>Our room collection is being updated. Please contact reservations for immediate support.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($spotlight_room): ?>
            <section class="booking-cta rooms-showcase-booking" id="book" data-lazy-reveal>
                <div class="container booking-cta__grid" id="booking-cta-grid">
                    <div class="booking-cta__content">
                        <div class="pill">Direct Booking</div>
                        <h2>Ready to reserve your stay?</h2>
                        <p>Choose your preferred room and complete your reservation directly on our secure booking page.</p>
                        <div class="booking-cta__actions">
                            <?php if ($phone_main !== ''): ?>
                                <a class="btn btn-primary" href="tel:<?php echo htmlspecialchars((string)preg_replace('/[^0-9+]/', '', $phone_main), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-phone"></i> Call Reservations</a>
                            <?php endif; ?>
                            <?php if ($email_reservations !== ''): ?>
                                <a class="btn btn-outline" href="mailto:<?php echo htmlspecialchars($email_reservations, ENT_QUOTES, 'UTF-8'); ?>?subject=Room%20Reservation"><i class="fas fa-envelope"></i> Email Booking</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="booking-cta__card">
                        <div class="booking-cta__row">
                            <span>Selected Room</span>
                            <strong><?php echo htmlspecialchars((string)$spotlight_room['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="booking-cta__row">
                            <span>Nightly Rate</span>
                            <strong><?php echo htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format((float)($spotlight_room['price_per_night'] ?? 0), 0); ?></strong>
                        </div>
                        <div class="booking-cta__row">
                            <span>Capacity</span>
                            <strong><?php echo (int)($spotlight_room['max_guests'] ?? 2); ?> guests</strong>
                        </div>
                        <div class="booking-cta__row">
                            <span>Floor Space</span>
                            <strong><?php echo htmlspecialchars((string)($spotlight_room['size_sqm'] ?? 40), ENT_QUOTES, 'UTF-8'); ?> sqm</strong>
                        </div>
                        <?php if (isBookingEnabled()): ?>
                            <a class="btn btn-primary" href="booking.php?room_id=<?php echo (int)$spotlight_room['id']; ?>">Proceed to Booking</a>
                        <?php else: ?>
                            <button class="btn btn-primary" type="button" disabled aria-disabled="true">Booking Disabled</button>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>
    <?php include 'includes/modal.php'; ?>

    <script src="js/modal.js" defer></script>
    <script src="js/main.js" defer></script>
    <script src="js/page-transitions.js" defer></script>
    <script src="js/scroll-reveal.js" defer></script>
    <script src="js/parallax-cards.js" defer></script>
    <script src="js/cursor-follower.js" defer></script>

    <script>
        (function() {
            var chips = document.querySelectorAll('.rooms-showcase-filter__chip');
            var cards = document.querySelectorAll('.rooms-showcase-card');

            if (!chips.length || !cards.length) {
                return;
            }

            function applyFilter(filterTag) {
                cards.forEach(function(card) {
                    var tags = String(card.getAttribute('data-filter-tags') || '').split(/\s+/).filter(Boolean);
                    var matches = filterTag === 'all-rooms' || tags.indexOf(filterTag) !== -1;
                    card.hidden = !matches;
                });

                chips.forEach(function(chip) {
                    var isActive = chip.getAttribute('data-filter') === filterTag;
                    chip.classList.toggle('active', isActive);
                    chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            }

            chips.forEach(function(chip) {
                chip.addEventListener('click', function() {
                    applyFilter(chip.getAttribute('data-filter') || 'all-rooms');
                });
            });
        }());

        (function() {
            var ratingContainers = document.querySelectorAll('.room-tile__rating');
            if (!ratingContainers.length) {
                return;
            }

            fetch('admin/api/all-room-ratings.php')
                .then(function(response) {
                    return response.json();
                })
                .then(function(result) {
                    if (!result || !result.success || !result.data) {
                        throw new Error('Ratings unavailable');
                    }

                    var ratings = result.data;
                    ratingContainers.forEach(function(container) {
                        var roomId = parseInt(container.getAttribute('data-room-id') || '0', 10);
                        var ratingData = ratings[roomId];

                        if (!ratingData || ratingData.review_count <= 0) {
                            container.innerHTML =
                                '<div class="compact-rating compact-rating--no-reviews">' +
                                '<i class="far fa-star"></i><span>No reviews</span>' +
                                '</div>';
                            return;
                        }

                        var avgRating = Number(ratingData.avg_rating || 0);
                        var totalCount = Number(ratingData.review_count || 0);
                        var fullStars = Math.floor(avgRating);
                        var hasHalfStar = (avgRating - fullStars) >= 0.5;
                        var emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
                        var starsHtml = '';

                        for (var i = 0; i < fullStars; i++) {
                            starsHtml += '<i class="fas fa-star"></i>';
                        }
                        if (hasHalfStar) {
                            starsHtml += '<i class="fas fa-star-half-alt"></i>';
                        }
                        for (var j = 0; j < emptyStars; j++) {
                            starsHtml += '<i class="far fa-star"></i>';
                        }

                        container.innerHTML =
                            '<div class="compact-rating">' +
                            '<div class="compact-rating__stars">' + starsHtml + '</div>' +
                            '<div class="compact-rating__info">' +
                            '<span class="compact-rating__score">' + avgRating.toFixed(1) + '</span>' +
                            '<span class="compact-rating__count">(' + totalCount + ')</span>' +
                            '</div>' +
                            '</div>';
                    });
                })
                .catch(function() {
                    ratingContainers.forEach(function(container) {
                        container.innerHTML =
                            '<div class="compact-rating compact-rating--no-reviews">' +
                            '<i class="far fa-star"></i><span>No reviews</span>' +
                            '</div>';
                    });
                });
        }());
    </script>
</body>

</html>
