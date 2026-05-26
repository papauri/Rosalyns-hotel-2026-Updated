<?php
/**
 * Reviews Display Functions
 * Hotel Website - Reusable functions for displaying reviews and ratings
 */

/**
 * Display star rating (1-5 stars)
 * 
 * @param float $rating The rating value (1-5)
 * @param int $size Size of stars in pixels (default: 16)
 * @param bool $showEmpty Whether to show empty stars (default: true)
 * @return string HTML for star rating display
 */
function displayStarRating($rating, $size = 16, $showEmpty = true) {
    $rating = max(1, min(5, (float)$rating));
    $fullStars = floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = $showEmpty ? (5 - $fullStars - ($hasHalfStar ? 1 : 0)) : 0;
    
    $html = '<div class="star-rating" role="img" aria-label="' . $rating . ' out of 5 stars" data-rating="' . $rating . '">';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star" style="font-size: ' . $size . 'px;"></i>';
    }
    
    // Half star
    if ($hasHalfStar) {
        $html .= '<i class="fas fa-star-half-alt" style="font-size: ' . $size . 'px;"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star" style="font-size: ' . $size . 'px;"></i>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * Display rating summary with average and count
 * 
 * @param array $averages Array containing avg_rating and total_count
 * @param bool $showCount Whether to show review count (default: true)
 * @return string HTML for rating summary
 */
function displayRatingSummary($averages, $showCount = true) {
    $avgRating = isset($averages['avg_rating']) ? (float)$averages['avg_rating'] : 0;
    $totalCount = isset($averages['total_count']) ? (int)$averages['total_count'] : 0;
    
    $html = '<div class="rating-summary" data-rating-summary>';
    $html .= '<div class="rating-summary__stars">' . displayStarRating($avgRating, 20) . '</div>';
    $html .= '<div class="rating-summary__info">';
    $html .= '<span class="rating-summary__average">' . number_format($avgRating, 1) . '</span>';
    if ($showCount) {
        $html .= '<span class="rating-summary__count">(' . $totalCount . ' review' . ($totalCount !== 1 ? 's' : '') . ')</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Display category ratings (service, cleanliness, location, value)
 * 
 * @param array $review Review data containing category ratings
 * @return string HTML for category ratings
 */
function displayCategoryRatings($review) {
    $categories = [
        'service_rating' => ['label' => 'Service', 'icon' => 'fa-concierge-bell'],
        'cleanliness_rating' => ['label' => 'Cleanliness', 'icon' => 'fa-broom'],
        'location_rating' => ['label' => 'Location', 'icon' => 'fa-map-marker-alt'],
        'value_rating' => ['label' => 'Value', 'icon' => 'fa-tag']
    ];
    
    $html = '<div class="category-ratings" data-category-ratings>';
    $hasRatings = false;
    
    foreach ($categories as $key => $category) {
        if (isset($review[$key]) && $review[$key] !== null && $review[$key] !== '') {
            $hasRatings = true;
            $rating = (int)$review[$key];
            $html .= '<div class="category-rating" data-category-rating="' . htmlspecialchars($category['label']) . '">';
            $html .= '<div class="category-rating__label">';
            $html .= '<i class="fas ' . $category['icon'] . '" aria-hidden="true"></i>';
            $html .= '<span>' . htmlspecialchars($category['label']) . '</span>';
            $html .= '</div>';
            $html .= '<div class="category-rating__stars">' . displayStarRating($rating, 14) . '</div>';
            $html .= '</div>';
        }
    }
    
    $html .= '</div>';
    return $hasRatings ? $html : '';
}

/**
 * Display admin response
 * 
 * @param string $response Admin response text
 * @param string $responseDate Date of response
 * @return string HTML for admin response
 */
function displayAdminResponse($response, $responseDate = null) {
    if (empty($response)) {
        return '';
    }
    
    $html = '<div class="admin-response">';
    $html .= '<div class="admin-response__header">';
    $html .= '<i class="fas fa-reply"></i>';
    $html .= '<span class="admin-response__label">Hotel Response</span>';
    if ($responseDate) {
        $html .= '<span class="admin-response__date">' . date('M j, Y', strtotime($responseDate)) . '</span>';
    }
    $html .= '</div>';
    $html .= '<p class="admin-response__text">' . nl2br(htmlspecialchars($response)) . '</p>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Display a single review card
 * 
 * @param array $review Review data
 * @param bool $showRoom Whether to show room name (default: false)
 * @return string HTML for review card
 */
function displayReviewCard($review, $showRoom = false) {
    $guestNameRaw = trim((string)($review['guest_name'] ?? ''));
    $email = trim((string)($review['guest_email'] ?? ''));
    if ($guestNameRaw === '' && $email !== '' && strpos($email, '@') !== false) {
        $guestNameRaw = explode('@', $email)[0];
    }

    $guestName = htmlspecialchars($guestNameRaw);
    $rating = (int)($review['rating'] ?? 5);
    $title = htmlspecialchars(trim((string)($review['title'] ?? '')));
    $comment = nl2br(htmlspecialchars($review['comment'] ?? ''));
    $reviewDate = isset($review['created_at']) ? date('M j, Y', strtotime($review['created_at'])) : '';
    $adminResponse = $review['latest_response'] ?? '';
    $adminResponseDate = $review['latest_response_date'] ?? null;
    
    // Mask email for privacy
    $maskedEmail = '';
    if (!empty($email)) {
        $parts = explode('@', $email);
        $maskedEmail = substr($parts[0], 0, 2) . '***@' . $parts[1];
    }
    
    $html = '<article class="review-card" data-review-id="' . ($review['id'] ?? '') . '">';
    $html .= '<div class="review-card__header">';
    $html .= '<div class="review-card__author">';
    $html .= '<div class="review-card__avatar">';
    $html .= '<i class="fas fa-user"></i>';
    $html .= '</div>';
    $html .= '<div class="review-card__author-info">';
    if ($guestName !== '') {
        $html .= '<span class="review-card__name">' . $guestName . '</span>';
    }
    if (!empty($maskedEmail)) {
        $html .= '<span class="review-card__email">' . $maskedEmail . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="review-card__rating">' . displayStarRating($rating, 16) . '</div>';
    $html .= '</div>';
    
    $html .= '<div class="review-card__content">';
    if ($title !== '') {
        $html .= '<h4 class="review-card__title">' . $title . '</h4>';
    }
    $html .= '<p class="review-card__comment">' . $comment . '</p>';
    
    // Category ratings
    $categoryRatings = displayCategoryRatings($review);
    if (!empty($categoryRatings)) {
        $html .= $categoryRatings;
    }
    
    // Admin response
    if (!empty($adminResponse)) {
        $html .= displayAdminResponse($adminResponse, $adminResponseDate);
    }
    
    $html .= '</div>';
    
    $html .= '<div class="review-card__footer">';
    $html .= '<span class="review-card__date"><i class="far fa-calendar-alt"></i> ' . $reviewDate . '</span>';
    if ($showRoom && !empty($review['room_name'])) {
        $html .= '<span class="review-card__room"><i class="fas fa-bed"></i> ' . htmlspecialchars($review['room_name']) . '</span>';
    }
    $html .= '</div>';
    
    $html .= '</article>';
    
    return $html;
}

/**
 * Display compact rating for room cards
 * 
 * @param float $averageRating Average rating
 * @param int $reviewCount Number of reviews
 * @param string $roomSlug Room slug for linking
 * @return string HTML for compact rating display
 */
function displayCompactRating($averageRating, $reviewCount, $roomSlug = null) {
    $html = '<div class="compact-rating">';
    $html .= '<div class="compact-rating__stars">' . displayStarRating($averageRating, 14) . '</div>';
    $html .= '<div class="compact-rating__info">';
    $html .= '<span class="compact-rating__average">' . number_format($averageRating, 1) . '</span>';
    $html .= '<span class="compact-rating__count">' . $reviewCount . '</span>';
    $html .= '</div>';
    if ($roomSlug) {
        $html .= '<a href="room.php?room=' . urlencode($roomSlug) . '#reviews" class="compact-rating__link" aria-label="Read all reviews">';
        $html .= '<i class="fas fa-chevron-right"></i>';
        $html .= '</a>';
    }
    $html .= '</div>';
    
    return $html;
}

/**
 * Display reviews section for room detail page
 * 
 * @param int $roomId Room ID
 * @param array $reviews Array of reviews
 * @param array $averages Average ratings data
 * @return string HTML for reviews section
 */
function displayReviewsSection($roomId, $reviews = [], $averages = []) {
    $avgRating = isset($averages['avg_rating']) ? (float)$averages['avg_rating'] : 0;
    $totalCount = isset($averages['total_count']) ? (int)$averages['total_count'] : 0;
    
    $html = '<section class="reviews-section" id="reviews">';
    $html .= '<div class="container">';
    
    // Section header
    $html .= '<div class="reviews-section__header">';
    $html .= '<div class="reviews-section__title-group">';
    $html .= '<h2 class="reviews-section__title">Guest Reviews</h2>';
    $html .= '<p class="reviews-section__subtitle">See what our guests are saying about their stay</p>';
    $html .= '</div>';
    $html .= '<a class="btn btn-primary" href="submit-review.php?room_id=' . $roomId . '">';
    $html .= '<i class="fas fa-pen"></i> Write a Review';
    $html .= '</a>';
    $html .= '</div>';
    
    // Rating summary
    $html .= '<div class="reviews-section__summary">';
    $html .= '<div class="rating-summary-large">';
    $html .= '<div class="rating-summary-large__score">' . number_format($avgRating, 1) . '</div>';
    $html .= '<div class="rating-summary-large__stars">' . displayStarRating($avgRating, 28) . '</div>';
    $html .= '<div class="rating-summary-large__count">Based on ' . $totalCount . ' review' . ($totalCount !== 1 ? 's' : '') . '</div>';
    $html .= '</div>';
    
    // Category averages
    if (!empty($averages)) {
        $html .= '<div class="rating-breakdown">';
        $categories = [
            'avg_service' => ['label' => 'Service', 'icon' => 'fa-concierge-bell'],
            'avg_cleanliness' => ['label' => 'Cleanliness', 'icon' => 'fa-broom'],
            'avg_location' => ['label' => 'Location', 'icon' => 'fa-map-marker-alt'],
            'avg_value' => ['label' => 'Value', 'icon' => 'fa-tag']
        ];
        
        foreach ($categories as $key => $category) {
            if (isset($averages[$key]) && $averages[$key] !== null) {
                $rating = (float)$averages[$key];
                $html .= '<div class="rating-breakdown__item">';
                $html .= '<div class="rating-breakdown__label">';
                $html .= '<i class="fas ' . $category['icon'] . '"></i>';
                $html .= '<span>' . $category['label'] . '</span>';
                $html .= '</div>';
                $html .= '<div class="rating-breakdown__bar">';
                $html .= '<div class="rating-breakdown__fill" style="width: ' . ($rating * 20) . '%;"></div>';
                $html .= '</div>';
                $html .= '<span class="rating-breakdown__value">' . number_format($rating, 1) . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Filter options
    $html .= '<div class="reviews-section__filters">';
    $html .= '<div class="filter-group">';
    $html .= '<label class="filter-label">Sort by:</label>';
    $html .= '<select class="filter-select" id="review-sort">';
    $html .= '<option value="newest">Newest First</option>';
    $html .= '<option value="highest">Highest Rated</option>';
    $html .= '<option value="lowest">Lowest Rated</option>';
    $html .= '</select>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Reviews list
    $html .= '<div class="reviews-section__list" id="reviews-list">';
    
    if (empty($reviews)) {
        $html .= '<div class="reviews-empty">';
        $html .= '<i class="fas fa-comment-slash"></i>';
        $html .= '<h3>No reviews yet</h3>';
        $html .= '<p>Be the first to share your experience!</p>';
        $html .= '<a class="btn btn-outline" href="submit-review.php?room_id=' . $roomId . '">Write a Review</a>';
        $html .= '</div>';
    } else {
        foreach ($reviews as $review) {
            $html .= displayReviewCard($review);
        }
    }
    
    $html .= '</div>';
    
    // Load more button (if more reviews available)
    if (count($reviews) >= 10) {
        $html .= '<div class="reviews-section__pagination">';
        $html .= '<button class="btn btn-outline" id="load-more-reviews" data-room-id="' . $roomId . '" data-offset="10">';
        $html .= '<i class="fas fa-plus"></i> Load More Reviews';
        $html .= '</button>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</section>';
    
    return $html;
}

/**
 * Fetch reviews from database directly (avoids HTTP request deadlock)
 *
 * @param int $roomId Room ID (optional)
 * @param string $status Review status filter (default: 'approved')
 * @param int $limit Number of reviews to fetch (default: 10)
 * @param int $offset Offset for pagination (default: 0)
 * @return array|false Returns array with reviews and averages, or false on failure
 */
function fetchReviews($roomId = null, $status = 'approved', $limit = 10, $offset = 0) {
    global $pdo;
    
    try {
        // Validate status
        $valid_statuses = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $valid_statuses)) {
            return false;
        }
        
        // Build query
        $sql = "
            SELECT
                r.*,
                (SELECT COUNT(*) FROM review_responses rr WHERE rr.review_id = r.id) as response_count,
                (SELECT response FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) as latest_response,
                (SELECT created_at FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) as latest_response_date,
                rm.name as room_name
            FROM reviews r
            LEFT JOIN rooms rm ON r.room_id = rm.id
            WHERE r.status = ?
        ";
        $params = [$status];
        
        if ($roomId !== null) {
            $sql .= " AND r.room_id = ?";
            $params[] = $roomId;
        }
        
        $sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hide guest_email for non-admin requests
        $is_admin = isset($_SESSION['admin_user']);
        if (!$is_admin) {
            foreach ($reviews as &$review) {
                unset($review['guest_email']);
            }
        }
        
        // Calculate average ratings
        $avgSql = "
            SELECT
                AVG(rating) as avg_rating,
                AVG(service_rating) as avg_service,
                AVG(cleanliness_rating) as avg_cleanliness,
                AVG(location_rating) as avg_location,
                AVG(value_rating) as avg_value,
                COUNT(*) as total_count
            FROM reviews
            WHERE status = 'approved'
        ";
        $avgStmt = $pdo->query($avgSql);
        $averages = $avgStmt->fetch(PDO::FETCH_ASSOC);
        
        // Format averages to 1 decimal place
        foreach ($averages as $key => $value) {
            if ($value !== null) {
                $averages[$key] = round((float)$value, 1);
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'reviews' => $reviews,
                'averages' => $averages
            ]
        ];
        
    } catch (PDOException $e) {
        error_log("fetchReviews: Database error: " . $e->getMessage());
        return false;
    }
}
?>
