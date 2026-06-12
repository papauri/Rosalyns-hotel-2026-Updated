<?php

/**
 * Reviews API
 * Hotel Website - Admin API for managing guest reviews
 *
 * Endpoints:
 * - GET: Fetch reviews with optional filtering
 * - POST: Submit a new review
 * - PUT: Update review status (moderation)
 * - DELETE: Delete a review
 */

// Start session FIRST before any includes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON response header
header('Content-Type: application/json');

// Include database configuration - FIXED PATH
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';

// Include cache configuration - FIXED PATH
require_once __DIR__ . '/../../config/cache.php';

// Helper function to send JSON response
function sendResponse(mixed $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to send error response
function sendError(string $message, int $statusCode = 400, mixed $details = null): never
{
    $response = [
        'success' => false,
        'message' => $message
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    sendResponse($response, $statusCode);
}

// Helper function to validate review data
function validateReviewData(array $data, bool $isUpdate = false): array
{
    $errors = [];

    // Required fields for new reviews
    if (!$isUpdate) {
        $required_fields = ['guest_name', 'guest_email', 'rating', 'title', 'comment'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
    }

    // Email validation
    if (!empty($data['guest_email'])) {
        if (!filter_var($data['guest_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['guest_email'] = 'Invalid email address';
        }
    }

    // Rating validation (1-5)
    if (isset($data['rating'])) {
        $rating = (int)$data['rating'];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Rating must be between 1 and 5';
        }
    }

    // Optional category ratings validation (1-5)
    $category_ratings = ['service_rating', 'cleanliness_rating', 'location_rating', 'value_rating'];
    foreach ($category_ratings as $field) {
        if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
            $rating = (int)$data[$field];
            if ($rating < 1 || $rating > 5) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be between 1 and 5';
            }
        }
    }

    // Status validation for updates
    if (isset($data['status'])) {
        $valid_statuses = ['pending', 'approved', 'rejected'];
        if (!in_array($data['status'], $valid_statuses)) {
            $errors['status'] = 'Status must be one of: pending, approved, rejected';
        }
    }

    // Review type validation
    if (isset($data['review_type']) && $data['review_type'] !== '') {
        $valid_types = ['general', 'room', 'restaurant', 'spa', 'conference', 'gym', 'service'];
        if (!in_array($data['review_type'], $valid_types)) {
            $errors['review_type'] = 'Review type must be one of: general, room, restaurant, spa, conference, gym, service';
        }
    }

    // Validate room_id if provided
    if (isset($data['room_id']) && $data['room_id'] !== null && $data['room_id'] !== '') {
        if (!is_numeric($data['room_id']) || (int)$data['room_id'] < 1) {
            $errors['room_id'] = 'Invalid room ID';
        }
    }

    // Validate booking_id if provided
    if (isset($data['booking_id']) && $data['booking_id'] !== null && $data['booking_id'] !== '') {
        if (!is_numeric($data['booking_id']) || (int)$data['booking_id'] < 1) {
            $errors['booking_id'] = 'Invalid booking ID';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Require admin authentication for all operations
if (!isset($_SESSION['admin_user_id'])) {
    sendError('Authentication required', 401);
}

if (!hasPermission((int)$_SESSION['admin_user_id'], 'reviews')) {
    sendError('Access denied', 403);
}

// Parse request body for PUT/POST requests
$input = [];
if ($method === 'POST' || $method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true) ?? [];
    }
    // Also merge with $_POST for form data
    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }
}

try {
    switch ($method) {
        case 'GET':
            // Fetch reviews with optional filtering
            $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            // Validate status if provided
            if ($status !== null) {
                $valid_statuses = ['pending', 'approved', 'rejected'];
                if (!in_array($status, $valid_statuses)) {
                    sendError('Invalid status parameter. Must be one of: pending, approved, rejected', 400);
                }
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
                WHERE 1=1
            ";
            $params = [];

            if ($room_id !== null) {
                $sql .= " AND r.room_id = ?";
                $params[] = $room_id;
            }

            if ($status !== null) {
                $sql .= " AND r.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY r.created_at DESC";

            if ($limit !== null) {
                $sql .= " LIMIT ?";
                $params[] = $limit;
            }

            if ($offset > 0) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
            }

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

            sendResponse([
                'success' => true,
                'data' => [
                    'reviews' => $reviews,
                    'averages' => $averages
                ]
            ]);
            break;

        case 'POST':
            // Submit a new review
            $validation = validateReviewData($input);
            if (!$validation['valid']) {
                sendError('Validation failed', 400, $validation['errors']);
            }

            // Sanitize inputs
            $guest_name = trim($input['guest_name']);
            $guest_email = trim($input['guest_email']);
            $rating = (int)$input['rating'];
            $title = trim($input['title']);
            $comment = trim($input['comment']);
            $review_type = isset($input['review_type']) && $input['review_type'] !== '' ? trim($input['review_type']) : 'general';
            $room_id = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
            $booking_id = isset($input['booking_id']) && $input['booking_id'] !== '' ? (int)$input['booking_id'] : null;
            $service_rating = isset($input['service_rating']) && $input['service_rating'] !== '' ? (int)$input['service_rating'] : null;
            $cleanliness_rating = isset($input['cleanliness_rating']) && $input['cleanliness_rating'] !== '' ? (int)$input['cleanliness_rating'] : null;
            $location_rating = isset($input['location_rating']) && $input['location_rating'] !== '' ? (int)$input['location_rating'] : null;
            $value_rating = isset($input['value_rating']) && $input['value_rating'] !== '' ? (int)$input['value_rating'] : null;

            // Validate room exists if provided
            if ($room_id !== null) {
                $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
                $stmt->execute([$room_id]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    sendError('Room not found', 404);
                }
            }

            // Validate booking exists if provided
            if ($booking_id !== null) {
                $stmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ?");
                $stmt->execute([$booking_id]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    sendError('Booking not found', 404);
                }
            }

            // Insert review
            $sql = "
                INSERT INTO reviews (
                    booking_id, room_id, review_type, guest_name, guest_email, rating, title, comment,
                    service_rating, cleanliness_rating, location_rating, value_rating, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $booking_id,
                $room_id,
                $review_type,
                $guest_name,
                $guest_email,
                $rating,
                $title,
                $comment,
                $service_rating,
                $cleanliness_rating,
                $location_rating,
                $value_rating
            ]);

            $review_id = $pdo->lastInsertId();

            // Fetch the created review
            $stmt = $pdo->prepare("SELECT * FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'success' => true,
                'message' => 'Review submitted successfully. It will be visible after moderation.',
                'data' => $review
            ], 201);
            break;

        case 'PUT':
            // Update review status (moderation)
            if (!isset($input['review_id'])) {
                sendError('review_id is required', 400);
            }

            if (!isset($input['status'])) {
                sendError('status is required', 400);
            }

            $review_id = (int)$input['review_id'];
            $status = $input['status'];

            // Validate status
            $validation = validateReviewData(['status' => $status], true);
            if (!$validation['valid']) {
                sendError('Validation failed', 400, $validation['errors']);
            }

            // Check if review exists
            $stmt = $pdo->prepare("SELECT id, status FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                sendError('Review not found', 404);
            }

            // Update status
            $stmt = $pdo->prepare("UPDATE reviews SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $review_id]);

            // Clear review caches when status changes to approved
            if ($status === 'approved') {
                // Clear hotel-wide review caches
                deleteCache('hotel_reviews_6');
                deleteCache('hotel_reviews_10');

                // Clear room-specific cache if this is a room review
                $stmt = $pdo->prepare("SELECT room_id FROM reviews WHERE id = ?");
                $stmt->execute([$review_id]);
                $review_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($review_data && $review_data['room_id']) {
                    deleteCache('room_reviews_' . $review_data['room_id']);
                }

                // Clear all review-related caches with wildcard pattern
                deleteCache('reviews_count_*');
                deleteCache('avg_rating_*');
            }

            // Fetch updated review
            $stmt = $pdo->prepare("SELECT * FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $updated_review = $stmt->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'success' => true,
                'message' => 'Review status updated successfully',
                'data' => $updated_review
            ]);
            break;

        case 'DELETE':
            // Delete a review
            if (!isset($_GET['review_id'])) {
                sendError('review_id parameter is required', 400);
            }

            $review_id = (int)$_GET['review_id'];

            // Check if review exists
            $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                sendError('Review not found', 404);
            }

            // Delete review (cascade will delete responses)
            $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);

            sendResponse([
                'success' => true,
                'message' => 'Review deleted successfully'
            ]);
            break;

        default:
            sendError('Method not allowed', 405);
            break;
    }
} catch (PDOException $e) {
    error_log("Database error in reviews.php: " . $e->getMessage());
    sendError('Database error occurred', 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Error in reviews.php: " . $e->getMessage());
    sendError('An error occurred', 500, $e->getMessage());
}

