<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/reviews-display.php';
require_once 'includes/video-display.php';
require_once 'includes/section-headers.php';
require_once 'includes/image-proxy-helper.php';
require_once 'includes/booking-functions.php';

$room_slug = isset($_GET['room']) ? trim($_GET['room']) : null;
if (!$room_slug && !defined('API_REQUEST')) {
    header('Location: ' . BASE_URL);
    exit;
}

$site_name = getSetting('site_name');
$site_tagline = getSetting('site_tagline');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$email_reservations = getSetting('email_reservations');
$phone_main = getSetting('phone_main');
$booking_notification_email = getSetting('booking_notification_email');
if (empty($booking_notification_email)) {
    $booking_notification_email = $email_reservations;
}

// Configurable fallback image (no hardcoded paths)
$default_room_image = getSetting('default_room_image');
if (empty($default_room_image)) {
    $default_room_image = $site_logo; // fall back to site logo if no dedicated default set
}

// Define base URL for use in SEO data
$base_url = siteUrl('');

function resolveImageUrl(?string $path): string
{
    if (!$path) return '';
    $trimmed = trim($path);
    // Absolute external URL
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        return $trimmed;
    }
    // Normalize relative path
    $relative = ltrim($trimmed, '/\\');
    $abs = __DIR__ . DIRECTORY_SEPARATOR . $relative;
    if (!file_exists($abs)) {
        // Use configurable fallback (DB-driven) to avoid hardcoded paths
        global $default_room_image;
        return $default_room_image ?: $relative;
    }
    return $relative;
}

$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $policies = [];
}

$contact_settings = getSettingsByGroup('contact');
$contact = [];
foreach ($contact_settings as $setting) {
    $contact[$setting['setting_key']] = $setting['setting_value'];
}

$social_settings = getSettingsByGroup('social');
$social = [];
foreach ($social_settings as $setting) {
    $social[$setting['setting_key']] = $setting['setting_value'];
}

$footer_links_raw = [];
try {
    $footerStmt = $pdo->query("SELECT column_name, link_text, link_url FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order");
    $footer_links_raw = $footerStmt->fetchAll();
} catch (PDOException $e) {
    $footer_links_raw = [];
}

$footer_links = [];
foreach ($footer_links_raw as $link) {
    $footer_links[$link['column_name']][] = $link;
}

$room = null;
$room_images = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE slug = ? AND is_active = 1");
    $stmt->execute([$room_slug]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($room) && function_exists('applyManagedMediaOverrides')) {
        $room = applyManagedMediaOverrides($room, 'rooms', $room['id'] ?? '', ['image_url', 'video_path']);
    }

    if (!$room) {
        header('Location: ' . BASE_URL);
        exit;
    }

    $galleryStmt = $pdo->prepare("SELECT id, title, description, image_url FROM gallery WHERE room_id = ? AND is_active = 1 AND image_url IS NOT NULL AND image_url != '' ORDER BY display_order ASC, id ASC");
    $galleryStmt->execute([$room['id']]);
    $room_images = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('applyManagedMediaOverrides') && !empty($room_images)) {
        foreach ($room_images as &$roomImageRow) {
            $roomImageRow = applyManagedMediaOverrides($roomImageRow, 'gallery', $roomImageRow['id'] ?? '', ['image_url']);
        }
        unset($roomImageRow);
    }
} catch (PDOException $e) {
    error_log('Error fetching room details: ' . $e->getMessage());
}

$room_images = array_values(array_filter($room_images, function ($img) {
    return !empty($img['image_url']) && trim($img['image_url']) !== '';
}));

if (empty($room_images) && !empty($room['image_url'])) {
    $room_images[] = [
        'id' => 0,
        'title' => $room['name'],
        'description' => '',
        'image_url' => $room['image_url']
    ];
}

$hero_image = proxyImageUrl(resolveImageUrl($room_images[0]['image_url'] ?? $room['image_url']));
$amenities = array_filter(array_map('trim', explode(',', $room['amenities'] ?? '')));

// Build SEO data for room page
$seo_data = [
    'title' => $room['name'],
    'description' => $room['short_description'] ?? $site_tagline,
    'image' => $hero_image,
    'type' => 'hotel',
    'tags' => 'luxury room, ' . $room['name'] . ', ' . implode(', ', array_slice($amenities, 0, 5)),
    'breadcrumbs' => [
        ['name' => 'Home', 'url' => $base_url . '/'],
        ['name' => 'Rooms', 'url' => $base_url . '/rooms-gallery.php'],
        ['name' => $room['name'], 'url' => $base_url . '/room.php?room=' . urlencode($room['slug'])]
    ],
    'structured_data' => [
        '@context' => 'https://schema.org',
        '@type' => 'HotelRoom',
        'name' => $room['name'],
        'description' => $room['short_description'] ?? $site_tagline,
        'image' => $base_url . $hero_image,
        'numberOfBeds' => 1,
        'bed' => [
            '@type' => 'BedType',
            'name' => $room['bed_type']
        ],
        'amenityFeature' => array_map(function ($amenity) {
            return [
                '@type' => 'LocationFeatureSpecification',
                'name' => $amenity,
                'value' => true
            ];
        }, array_slice($amenities, 0, 10)),
        'occupancy' => [
            '@type' => 'QuantitativeValue',
            'maxValue' => $room['max_guests'] ?? 2
        ],
        'floorSize' => [
            '@type' => 'QuantitativeValue',
            'value' => $room['size_sqm'] ?? 40,
            'unitCode' => 'MTK'
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => $room['price_per_night'],
            'priceCurrency' => getSetting('currency_code', 'USD'),
            'availability' => $room['rooms_available'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
            'url' => $base_url . '/booking.php?room_id=' . $room['id']
        ],
        'containedInPlace' => [
            '@type' => 'Hotel',
            'name' => $site_name,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $contact['address_line2'] ?? '',
                'addressCountry' => $contact['address_country'] ?? ''
            ]
        ]
    ]
];

// Fetch room reviews for structured data
try {
    $reviews_stmt = $pdo->prepare("
        SELECT rating, comment, guest_name, created_at
        FROM reviews
        WHERE room_id = ? AND status = 'approved'
        LIMIT 5
    ");
    $reviews_stmt->execute([$room['id']]);
    $room_reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($room_reviews)) {
        $seo_data['structured_data']['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => array_sum(array_column($room_reviews, 'rating')) / count($room_reviews),
            'reviewCount' => count($room_reviews),
            'bestRating' => 5,
            'worstRating' => 1
        ];
    }
} catch (PDOException $e) {
    // Ignore review errors
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <!-- SEO Meta Tags -->
    <?php require_once 'includes/seo-meta.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/base/critical.css">
    <link rel="stylesheet" href="css/main.css">

    <!-- Swiper CSS for Modern Carousel -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">

</head>

<body class="rooms-page">
    <?php include 'includes/loader.php'; ?>

    <?php include 'includes/header.php'; ?>

    <main id="main-content">
        <section class="rooms-hero rh-reveal">
            <!-- Themed background only; no image or video media elements -->

            <div class="rooms-hero__overlay"></div>
            <div class="container">
                <div class="rooms-hero__grid">
                    <div class="rooms-hero__content">
                        <?php if (!empty($room['badge'])): ?>
                            <div class="pill"><?php echo htmlspecialchars($room['badge']); ?></div>
                        <?php endif; ?>
                        <h1><?php echo htmlspecialchars($room['name']); ?></h1>
                        <p><?php echo htmlspecialchars($room['short_description'] ?? $site_tagline); ?></p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section room-detail-below">
            <div class="container">

                <?php
                // Check if room has a video
                $room_has_video = !empty($room['video_path']);
                $has_gallery_content = !empty($room_images) || $room_has_video;

                if ($has_gallery_content):
                ?>

                    <!-- Modern Room Gallery Carousel -->
                    <div class="room-gallery-carousel">
                        <div class="room-gallery-header">
                            <h3 class="room-gallery-title">Room Gallery</h3>
                            <div class="room-gallery-counter">
                                <span class="current-slide">1</span> / <span class="total-slides"><?php echo count($room_images) + ($room_has_video ? 1 : 0); ?></span>
                            </div>
                        </div>

                        <div class="swiper room-gallery-swiper">
                            <div class="swiper-wrapper">
                                <?php
                                $slide_index = 0;

                                // Display room video first if available
                                if ($room_has_video):
                                    $slide_index++;
                                ?>
                                    <div class="swiper-slide room-gallery-slide room-gallery-slide--video">
                                        <div class="room-gallery-media">
                                            <?php echo renderVideoEmbed($room['video_path'], $room['video_type'], [
                                                'autoplay' => false,
                                                'muted' => false,
                                                'controls' => true,
                                                'loop' => false,
                                                'class' => 'room-gallery-video',
                                                'lazy' => true
                                            ]); ?>
                                        </div>
                                        <div class="room-gallery-caption">
                                            <span class="caption-icon"><i class="fas fa-video"></i></span>
                                            <span class="caption-text">Room Video Tour</span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($room_images as $img):
                                    $slide_index++;
                                ?>
                                    <div class="swiper-slide room-gallery-slide">
                                        <div class="room-gallery-media">
                                            <img
                                                src="<?php echo htmlspecialchars(proxyImageUrl(resolveImageUrl($img['image_url']))); ?>"
                                                alt="<?php echo htmlspecialchars($img['title']); ?>"
                                                loading="lazy"
                                                decoding="async">
                                        </div>
                                        <?php if (!empty($img['title'])): ?>
                                            <div class="room-gallery-caption">
                                                <span class="caption-text"><?php echo htmlspecialchars($img['title']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Navigation Buttons -->
                            <button class="swiper-button-prev room-gallery-nav" aria-label="Previous slide">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="swiper-button-next room-gallery-nav" aria-label="Next slide">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>

                        <!-- Thumbnail Navigation -->
                        <div class="swiper room-gallery-thumbs" thumbsSlider="">
                            <div class="swiper-wrapper">
                                <?php
                                // Thumbnail for video
                                if ($room_has_video):
                                ?>
                                    <div class="swiper-slide room-gallery-thumb room-gallery-thumb--video">
                                        <div class="thumb-icon"><i class="fas fa-play"></i></div>
                                        <span>Video</span>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($room_images as $thumb): ?>
                                    <div class="swiper-slide room-gallery-thumb">
                                        <img
                                            src="<?php echo htmlspecialchars(proxyImageUrl(resolveImageUrl($thumb['image_url']))); ?>"
                                            alt="<?php echo htmlspecialchars($thumb['title']); ?>"
                                            loading="lazy"
                                            decoding="async">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Room Details Section - Moved below gallery -->
                <aside class="room-detail-info room-detail-info--horizontal" aria-label="Room details">
                    <div class="room-detail-header">
                        <h2 class="room-detail-title">About the Room</h2>
                        <?php renderBookingButton($room['id'], $room['name'], 'btn-primary btn-booking'); ?>
                    </div>
                    <p class="room-detail-description"><?php echo htmlspecialchars($room['description'] ?? $room['short_description']); ?></p>
                    <div class="room-detail-specs">
                        <div class="spec-item"><i class="fas fa-users"></i>
                            <div class="spec-label">Guests</div>
                            <div class="spec-value">Up to <?php echo htmlspecialchars($room['max_guests'] ?? 2); ?></div>
                        </div>
                        <div class="spec-item"><i class="fas fa-ruler-combined"></i>
                            <div class="spec-label">Floor Space</div>
                            <div class="spec-value"><?php echo htmlspecialchars($room['size_sqm']); ?> sqm</div>
                        </div>
                        <div class="spec-item"><i class="fas fa-bed"></i>
                            <div class="spec-label">Bed Type</div>
                            <div class="spec-value"><?php echo htmlspecialchars($room['bed_type']); ?></div>
                        </div>
                        <div class="spec-item"><i class="fas fa-tag"></i>
                            <div class="spec-label">Nightly Rate</div>
                            <div class="spec-value"><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($room['price_per_night'], 0); ?></div>
                        </div>
                        <?php
                        $available = $room['rooms_available'] ?? 0;
                        $total = $room['total_rooms'] ?? 0;
                        if ($total > 0):
                            $availability_class = $available == 0 ? 'unavailable' : ($available <= 2 ? 'low' : 'good');
                        ?>
                            <div class="spec-item availability-<?php echo $availability_class; ?>">
                                <i class="fas fa-door-open"></i>
                                <div class="spec-label">Availability</div>
                                <div class="spec-value"><?php echo $available; ?>/<?php echo $total; ?> rooms</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($amenities)): ?>
                        <div class="amenities-list">
                            <h3 class="amenities-list__title">Room Amenities</h3>
                            <div class="amenities-list__chips">
                                <?php foreach ($amenities as $amenity): ?>
                                    <span class="amenities-chip"><i class="fas fa-check"></i><?php echo htmlspecialchars($amenity); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </section>

        <section class="booking-cta rh-reveal" id="book">
            <div class="container booking-cta__grid" id="booking-cta-grid">
                <div class="booking-cta__content">
                    <div class="pill">Direct Booking</div>
                    <h2>Ready to reserve your stay?</h2>
                    <p>Pick your preferred suite and we will secure it instantly. Share your dates and guest count and our team will confirm right away.</p>
                    <div class="booking-cta__actions">
                        <a class="btn btn-primary" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone_main)); ?>"><i class="fas fa-phone"></i> Call Reservations</a>
                        <a class="btn btn-outline" href="mailto:<?php echo htmlspecialchars($booking_notification_email); ?>?subject=Room%20Reservation"><i class="fas fa-envelope"></i> Email Booking</a>
                    </div>
                </div>
                <div class="booking-cta__card">
                    <div class="booking-cta__row"><span>Selected Room</span><strong><?php echo htmlspecialchars($room['name']); ?></strong></div>
                    <div class="booking-cta__row"><span>Nightly Rate</span><strong><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($room['price_per_night'], 0); ?></strong></div>
                    <div class="booking-cta__row"><span>Capacity</span><strong><?php echo htmlspecialchars($room['max_guests'] ?? 2); ?> guests</strong></div>
                    <div class="booking-cta__row"><span>Floor Space</span><strong><?php echo htmlspecialchars($room['size_sqm'] ?? 40); ?> sqm</strong></div>
                    <?php renderBookingButton($room['id'], $room['name'], 'btn-primary'); ?>
                </div>
            </div>
        </section>


        <!-- Passalacqua-Inspired Editorial Reviews Section: Borderless, Large Serif Quotes, Gold Divider -->
        <section class="editorial-testimonials-section rh-reveal" id="reviews" data-room-id="<?php echo $room['id']; ?>">
            <div class="container">
                <div class="editorial-testimonials-header">
                    <?php
                    $reviews_header = getSectionHeader('hotel_reviews', 'global', ['title' => 'Guest Reviews']);
                    ?>
                    <h2 class="editorial-testimonials-title"><?php echo htmlspecialchars($reviews_header['title']); ?></h2>
                    <a class="editorial-btn-write-review" href="submit-review.php?room_id=<?php echo $room['id']; ?>">
                        <i class="fas fa-pen-fancy"></i>
                        <span>Write a Review</span>
                        <i class="fas fa-arrow-right btn-arrow"></i>
                    </a>
                </div>
                <div class="editorial-testimonials-grid" id="reviewsList">
                    <div class="editorial-testimonial-card editorial-testimonial-loading">
                        <div class="editorial-testimonial-quote">“</div>
                        <p class="editorial-testimonial-text"><i class="fas fa-spinner fa-spin"></i> Loading reviews...</p>
                    </div>
                </div>
                <div class="editorial-testimonials-pagination" id="reviewsPagination">
                    <button class="editorial-testimonials-pagination-btn editorial-testimonials-pagination-btn--prev" disabled>
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <div class="editorial-testimonials-pagination-info">
                        Page <span id="currentPage">1</span> of <span id="totalPages">1</span>
                    </div>
                    <button class="editorial-testimonials-pagination-btn editorial-testimonials-pagination-btn--next" disabled>
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="editorial-testimonials-empty" id="reviewsEmpty">
                    <i class="fas fa-comment-slash"></i>
                    <h3>No Reviews Yet</h3>
                    <p>Be the first to share your experience!</p>
                </div>
            </div>
        </section>

        <?php $room_reviews_js_v = file_exists(__DIR__ . '/js/room-reviews.js') ? filemtime(__DIR__ . '/js/room-reviews.js') : 1; ?>
        <script src="js/room-reviews.js?v=<?php echo $room_reviews_js_v; ?>" defer></script>
    </main>

    <!-- Scripts -->
    <script src="js/main.js" defer></script>

    <!-- Scroll Reveal Animation Handler -->
    <script>
        // Scroll Reveal for elements with .rh-reveal class
        document.addEventListener('DOMContentLoaded', function() {
            const revealElements = document.querySelectorAll('.rh-reveal');

            if (revealElements.length === 0) return;

            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-revealed');
                        // Optionally stop observing after reveal
                        // revealObserver.unobserve(entry.target);
                    }
                });
            }, {
                root: null,
                rootMargin: '0px 0px -80px 0px',
                threshold: 0.1
            });

            revealElements.forEach(el => {
                revealObserver.observe(el);
            });

            // Fallback: reveal all elements after page load if IntersectionObserver not triggered
            setTimeout(() => {
                revealElements.forEach(el => {
                    const rect = el.getBoundingClientRect();
                    if (rect.top < window.innerHeight && rect.bottom > 0) {
                        el.classList.add('is-revealed');
                    }
                });
            }, 500);
        });
    </script>

    <!-- Swiper JS for Carousel -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>

    <script>
        // Initialize Room Gallery Carousel
        document.addEventListener('DOMContentLoaded', function() {
            const galleryCarousel = document.querySelector('.room-gallery-swiper');
            if (!galleryCarousel || typeof Swiper === 'undefined') {
                return;
            }

            const thumbsCarousel = document.querySelector('.room-gallery-thumbs');
            const currentSlideEl = document.querySelector('.current-slide');
            const totalSlidesEl = document.querySelector('.total-slides');
            const nextButton = galleryCarousel.querySelector('.swiper-button-next');
            const prevButton = galleryCarousel.querySelector('.swiper-button-prev');

            function updateGalleryCounter(swiperInstance) {
                if (!swiperInstance) {
                    return;
                }

                if (currentSlideEl) {
                    currentSlideEl.textContent = String((swiperInstance.activeIndex || 0) + 1);
                }

                if (totalSlidesEl) {
                    totalSlidesEl.textContent = String(swiperInstance.slides ? swiperInstance.slides.length : 0);
                }

                const currentSlide = swiperInstance.slides && swiperInstance.slides[swiperInstance.activeIndex];
                if (currentSlide) {
                    const image = currentSlide.querySelector('img[loading="lazy"]');
                    if (image) {
                        image.loading = 'eager';
                    }
                }
            }

            let thumbsSwiper = null;
            if (thumbsCarousel) {
                thumbsSwiper = new Swiper(thumbsCarousel, {
                    spaceBetween: 8,
                    slidesPerView: 'auto',
                    freeMode: true,
                    watchSlidesProgress: true,
                    centerInsufficientSlides: true,
                    slideToClickedSlide: true,
                    watchOverflow: true
                });
            }

            const mainSwiper = new Swiper(galleryCarousel, {
                spaceBetween: 0,
                slidesPerView: 1,
                navigation: {
                    nextEl: nextButton,
                    prevEl: prevButton
                },
                thumbs: thumbsSwiper ? {
                    swiper: thumbsSwiper
                } : undefined,
                effect: 'fade',
                fadeEffect: {
                    crossFade: true
                },
                speed: 600,
                loop: false,
                grabCursor: true,
                watchOverflow: true,
                keyboard: {
                    enabled: true,
                    onlyInViewport: true
                },
                a11y: {
                    prevSlideMessage: 'Previous slide',
                    nextSlideMessage: 'Next slide'
                },
                on: {
                    init: function() {
                        updateGalleryCounter(this);
                    },
                    slideChange: function() {
                        updateGalleryCounter(this);
                    }
                }
            });

            updateGalleryCounter(mainSwiper);
        });
    </script>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>
    <?php include 'includes/modal.php'; ?>
</body>

</html>
