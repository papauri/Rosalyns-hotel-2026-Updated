<?php
/**
 * Batch API - Fetch All Room Ratings
 * Eliminates N+1 query problem by fetching all room ratings in a single query
 */

require_once '../../config/database.php';

header('Content-Type: application/json');

try {
    // Fetch average rating and count for all active rooms in one query
    $sql = "
        SELECT 
            r.id as room_id,
            r.slug,
            COUNT(DISTINCT rev.id) as review_count,
            AVG(rev.rating) as avg_rating,
            AVG(rev.service_rating) as avg_service,
            AVG(rev.cleanliness_rating) as avg_cleanliness,
            AVG(rev.location_rating) as avg_location,
            AVG(rev.value_rating) as avg_value
        FROM rooms r
        LEFT JOIN reviews rev ON r.id = rev.room_id AND rev.status = 'approved'
        WHERE r.is_active = 1
        GROUP BY r.id, r.slug
        ORDER BY r.display_order ASC, r.id ASC
    ";
    
    $stmt = $pdo->query($sql);
    $roomRatings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format ratings to 1 decimal place
    $ratings = [];
    foreach ($roomRatings as $rating) {
        $ratings[$rating['room_id']] = [
            'room_id' => (int)$rating['room_id'],
            'slug' => $rating['slug'],
            'avg_rating' => $rating['avg_rating'] !== null ? round((float)$rating['avg_rating'], 1) : 0,
            'review_count' => (int)$rating['review_count'],
            'avg_service' => $rating['avg_service'] !== null ? round((float)$rating['avg_service'], 1) : null,
            'avg_cleanliness' => $rating['avg_cleanliness'] !== null ? round((float)$rating['avg_cleanliness'], 1) : null,
            'avg_location' => $rating['avg_location'] !== null ? round((float)$rating['avg_location'], 1) : null,
            'avg_value' => $rating['avg_value'] !== null ? round((float)$rating['avg_value'], 1) : null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $ratings
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching all room ratings: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch room ratings'
    ]);
}
?>