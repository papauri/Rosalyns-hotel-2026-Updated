<?php
/**
 * Review Statistics API
 * Hotel Website - Admin API for review statistics and analytics
 * 
 * Endpoints:
 * - GET: Get comprehensive review statistics
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON response header
header('Content-Type: application/json');

// Include database configuration
require_once __DIR__ . '/../../config/database.php';

// Start session for admin authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to send error response
function sendError($message, $statusCode = 400, $details = null) {
    $response = [
        'success' => false,
        'message' => $message
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    sendResponse($response, $statusCode);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Require admin authentication for all operations
if (!isset($_SESSION['admin_user'])) {
    sendError('Authentication required', 401);
}

try {
    switch ($method) {
        case 'GET':
            // Get comprehensive review statistics
            
            // 1. Total reviews count by status
            $statusSql = "
                SELECT 
                    status,
                    COUNT(*) as count
                FROM reviews
                GROUP BY status
            ";
            $statusStmt = $pdo->query($statusSql);
            $status_counts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to associative array
            $reviews_by_status = [
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0
            ];
            foreach ($status_counts as $row) {
                $reviews_by_status[$row['status']] = (int)$row['count'];
            }
            $total_reviews = array_sum($reviews_by_status);
            
            // 2. Average overall rating (approved reviews only)
            $avgRatingSql = "
                SELECT 
                    AVG(rating) as avg_rating,
                    COUNT(*) as count
                FROM reviews
                WHERE status = 'approved'
            ";
            $avgRatingStmt = $pdo->query($avgRatingSql);
            $avg_rating_data = $avgRatingStmt->fetch(PDO::FETCH_ASSOC);
            $average_overall_rating = $avg_rating_data['avg_rating'] !== null 
                ? round((float)$avg_rating_data['avg_rating'], 1) 
                : 0;
            
            // 3. Average ratings by category (approved reviews only)
            $categorySql = "
                SELECT 
                    AVG(service_rating) as avg_service,
                    AVG(cleanliness_rating) as avg_cleanliness,
                    AVG(location_rating) as avg_location,
                    AVG(value_rating) as avg_value
                FROM reviews
                WHERE status = 'approved'
            ";
            $categoryStmt = $pdo->query($categorySql);
            $category_data = $categoryStmt->fetch(PDO::FETCH_ASSOC);
            
            $average_category_ratings = [
                'service' => $category_data['avg_service'] !== null 
                    ? round((float)$category_data['avg_service'], 1) 
                    : 0,
                'cleanliness' => $category_data['avg_cleanliness'] !== null 
                    ? round((float)$category_data['avg_cleanliness'], 1) 
                    : 0,
                'location' => $category_data['avg_location'] !== null 
                    ? round((float)$category_data['avg_location'], 1) 
                    : 0,
                'value' => $category_data['avg_value'] !== null 
                    ? round((float)$category_data['avg_value'], 1) 
                    : 0
            ];
            
            // 4. Rating distribution (1-5 stars)
            $distributionSql = "
                SELECT 
                    rating,
                    COUNT(*) as count
                FROM reviews
                WHERE status = 'approved'
                GROUP BY rating
                ORDER BY rating DESC
            ";
            $distributionStmt = $pdo->query($distributionSql);
            $distribution_data = $distributionStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $rating_distribution = [
                5 => 0,
                4 => 0,
                3 => 0,
                2 => 0,
                1 => 0
            ];
            foreach ($distribution_data as $row) {
                $rating_distribution[(int)$row['rating']] = (int)$row['count'];
            }
            
            // 5. Recent reviews (last 5 approved)
            $recentSql = "
                SELECT 
                    id,
                    guest_name,
                    rating,
                    title,
                    comment,
                    created_at,
                    room_id,
                    (SELECT name FROM rooms WHERE id = reviews.room_id) as room_name
                FROM reviews
                WHERE status = 'approved'
                ORDER BY created_at DESC
                LIMIT 5
            ";
            $recentStmt = $pdo->query($recentSql);
            $recent_reviews = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 6. Reviews by room (top 5 rooms with most reviews)
            $roomSql = "
                SELECT 
                    r.room_id,
                    rm.name as room_name,
                    COUNT(*) as review_count,
                    AVG(r.rating) as avg_rating
                FROM reviews r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                WHERE r.status = 'approved' AND r.room_id IS NOT NULL
                GROUP BY r.room_id, rm.name
                ORDER BY review_count DESC
                LIMIT 5
            ";
            $roomStmt = $pdo->query($roomSql);
            $reviews_by_room = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format room data
            foreach ($reviews_by_room as &$room) {
                $room['review_count'] = (int)$room['review_count'];
                $room['avg_rating'] = $room['avg_rating'] !== null 
                    ? round((float)$room['avg_rating'], 1) 
                    : 0;
            }
            
            // 7. Reviews over time (last 30 days)
            $timeSql = "
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM reviews
                WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ";
            $timeStmt = $pdo->query($timeSql);
            $reviews_over_time = $timeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 8. Calculate overall average (all categories combined)
            $all_categories = array_values($average_category_ratings);
            $non_null_categories = array_filter($all_categories, function($val) { return $val > 0; });
            $overall_category_average = !empty($non_null_categories) 
                ? round(array_sum($non_null_categories) / count($non_null_categories), 1) 
                : 0;
            
            // Compile all statistics
            $stats = [
                'total_reviews' => $total_reviews,
                'reviews_by_status' => $reviews_by_status,
                'average_overall_rating' => $average_overall_rating,
                'average_category_ratings' => $average_category_ratings,
                'overall_category_average' => $overall_category_average,
                'rating_distribution' => $rating_distribution,
                'recent_reviews' => $recent_reviews,
                'reviews_by_room' => $reviews_by_room,
                'reviews_over_time' => $reviews_over_time,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            sendResponse([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        default:
            sendError('Method not allowed', 405);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Database error in review-stats.php: " . $e->getMessage());
    sendError('Database error occurred', 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Error in review-stats.php: " . $e->getMessage());
    sendError('An error occurred', 500, $e->getMessage());
}
