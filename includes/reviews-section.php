<?php
/**
 * Hotel Reviews Section - Editorial Redesign
 * Minimalist, typography-focused layout
 * 
 * Required Variables:
 * - $hotel_reviews: Array of review records
 * - $site_name: Site name for admin response
 */

// Load section headers helper (if needed)
require_once __DIR__ . '/section-headers.php';

// If hotel_reviews is not available, try to fetch it
if (!isset($hotel_reviews) || empty($hotel_reviews)) {
    try {
        require_once __DIR__ . '/../config/database.php';
        // Fetch from database if not cached/provided
        require_once __DIR__ . '/reviews-display.php';
        $reviews_data = fetchReviews(null, 'approved', 6, 0);
        if (isset($reviews_data['data'])) {
            $hotel_reviews = $reviews_data['data']['reviews'] ?? [];
        } else {
            $hotel_reviews = $reviews_data['reviews'] ?? [];
        }
    } catch (Exception $e) {
        $hotel_reviews = [];
        error_log("Error fetching hotel reviews: " . $e->getMessage());
    }
}
?>

<section class="editorial-section editorial-reviews landing-section" id="reviews" data-lazy-reveal>
    <div class="editorial-container">
        <!-- Section Header -->
        <div class="scroll-reveal">
            <?php renderSectionHeader('hotel_reviews', 'global', [
                'label' => 'Guest Impressions',
                'title' => 'Stories from Our Guests',
                'description' => 'Hear from those who have experienced our exceptional hospitality'
            ], 'editorial-header section-header--editorial'); ?>
        </div>
        
        <?php if (!empty($hotel_reviews)): ?>
        <!-- Reviews Grid -->
        <div class="editorial-reviews-grid" data-reviews-grid>
            <?php foreach ($hotel_reviews as $index => $review): ?>
            <div class="editorial-review-card scroll-reveal" data-review-card>
                <div class="editorial-review-card__rating">
                    <?php for ($i = 0; $i < $review['rating']; $i++): ?>
                    <i class="fas fa-star" aria-hidden="true"></i>
                    <?php endfor; ?>
                </div>
                
                <blockquote class="editorial-review-card__quote">
                    <?php echo htmlspecialchars($review['comment']); ?>
                </blockquote>
                
                <div class="editorial-review-card__author">
                    <span class="editorial-review-card__name"><?php echo htmlspecialchars($review['guest_name']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="editorial-reviews-cta text-center scroll-reveal">
            <a href="submit-review.php" class="editorial-btn-primary">
                Share Your Story
            </a>
        </div>
        
        <?php else: ?>
        <div class="no-reviews-message text-center">
            <p>Be the first to share your experience with us.</p>
            <a href="submit-review.php" class="editorial-btn-primary mt-3">Write a Review</a>
        </div>
        <?php endif; ?>
    </div>
</section>
