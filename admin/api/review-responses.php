<?php

/**
 * Review Responses API
 * Hotel Website - Admin API for managing admin responses to reviews
 *
 * Endpoints:
 * - GET: Fetch responses for a specific review
 * - POST: Add a new admin response to a review (optionally emails the guest)
 * - DELETE: Remove a response (?response_id=N)
 */

// Start session FIRST before any includes
require_once __DIR__ . '/../../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON response header
header('Content-Type: application/json');

// Include database configuration (corrected relative path)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php'; // validateCsrfToken()
require_once __DIR__ . '/../includes/permissions.php';

// Include email configuration (corrected relative path)
require_once __DIR__ . '/../../config/email.php';
require_once __DIR__ . '/../../config/cache.php';
require_once __DIR__ . '/../../includes/reviews-display.php'; // rh_clear_review_caches()

// Helper function to send JSON response
function sendResponse(array $data, int $statusCode = 200): never
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

// Helper function to validate response data
function validateResponseData(array $data): array
{
    $errors = [];

    // Required fields
    $required_fields = ['review_id', 'response'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }

    // Validate review_id
    if (isset($data['review_id'])) {
        if (!is_numeric($data['review_id']) || (int)$data['review_id'] < 1) {
            $errors['review_id'] = 'Invalid review ID';
        }
    }

    // Validate response length
    if (isset($data['response'])) {
        $response_length = mb_strlen(trim((string)$data['response']), 'UTF-8');
        if ($response_length < 10) {
            $errors['response'] = 'Response must be at least 10 characters long';
        }
        if ($response_length > 5000) {
            $errors['response'] = 'Response must not exceed 5000 characters';
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

// Parse request body for POST requests
$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true) ?? [];
    }
    // Also merge with $_POST for form data
    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }
}

// ---- CSRF ----------------------------------------------------------------
// These endpoints mutate review data (INSERT / UPDATE status / DELETE) on nothing
// more than a session cookie, and carried no CSRF check at all. PUT and DELETE are
// shielded in practice by the CORS preflight, but the POST path is a classic
// JSON-via-form-post target: an attacker's page can submit a body that
// json_decode() accepts, and the browser attaches the admin's cookies.
// Token accepted from the X-CSRF-Token header (so DELETE, which sends no body,
// is covered too) or from `_csrf` in the payload.
if (in_array($method, ['POST', 'DELETE'], true)) {
    $csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_csrf'] ?? '');
    if (!validateCsrfToken($csrfToken)) {
        sendError('Invalid CSRF token', 403);
    }
}

try {
    switch ($method) {
        case 'GET':
            // Fetch responses for a specific review
            if (!isset($_GET['review_id'])) {
                sendError('review_id parameter is required', 400);
            }

            $review_id = (int)$_GET['review_id'];

            // Validate review exists
            $stmt = $pdo->prepare("SELECT id, guest_name, title FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                sendError('Review not found', 404);
            }

            // Fetch responses with admin details
            $sql = "
                SELECT
                    rr.*,
                    au.username as admin_username
                FROM review_responses rr
                LEFT JOIN admin_users au ON rr.admin_id = au.id
                WHERE rr.review_id = ?
                ORDER BY rr.created_at ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$review_id]);
            $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse([
                'success' => true,
                'data' => [
                    'review' => $review,
                    'responses' => $responses
                ]
            ]);
            break;

        case 'POST':
            // Add a new admin response to a review
            $validation = validateResponseData($input);
            if (!$validation['valid']) {
                sendError('Validation failed', 400, $validation['errors']);
            }

            $review_id = (int)$input['review_id'];
            $response_text = trim((string)$input['response']);
            // Always attribute to the signed-in admin — the client can't choose who "said" it.
            $admin_id = (int)$_SESSION['admin_user_id'];
            // Emailing is opt-out: replies to imported web feedback, or public-only
            // replies, shouldn't necessarily land in someone's inbox.
            $notify_guest = !isset($input['notify_guest']) || !in_array(strtolower((string)$input['notify_guest']), ['0', 'false', 'no', 'off', ''], true);

            $stmt = $pdo->prepare("SELECT id, status, guest_name, guest_email, title, comment FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review_details = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review_details) {
                sendError('Review not found', 404);
            }

            $stmt = $pdo->prepare("INSERT INTO review_responses (review_id, admin_id, response) VALUES (?, ?, ?)");
            $stmt->execute([$review_id, $admin_id, $response_text]);
            $response_id = $pdo->lastInsertId();

            // The homepage review strip caches the latest reply alongside each review.
            rh_clear_review_caches();

            $stmt = $pdo->prepare("
                SELECT rr.*, au.username as admin_username
                FROM review_responses rr
                LEFT JOIN admin_users au ON rr.admin_id = au.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$response_id]);
            $new_response = $stmt->fetch(PDO::FETCH_ASSOC);

            $response_data = [
                'success' => true,
                'message' => 'Response added successfully',
                'data' => $new_response,
                'email_sent' => false,
                'email_status' => 'not_attempted'
            ];

            $guest_email = trim((string)($review_details['guest_email'] ?? ''));
            if (!$notify_guest) {
                $response_data['email_status'] = 'skipped';
            } elseif ($guest_email === '' || !filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
                $response_data['email_status'] = 'no_guest_email';
            } else {
                try {
                    $result = sendReviewResponseEmail(
                        (string)$review_details['guest_name'],
                        $guest_email,
                        (string)$review_details['title'],
                        rh_public_review_text($review_details['comment'] ?? ''),
                        $response_text
                    );
                    if (!empty($result['success'])) {
                        $response_data['email_sent'] = true;
                        $response_data['email_status'] = 'sent';
                    } else {
                        error_log('Review response email failed: ' . ($result['message'] ?? 'unknown'));
                        $response_data['email_status'] = 'failed';
                        $response_data['email_error'] = (string)($result['message'] ?? 'Email could not be sent');
                    }
                } catch (Throwable $e) {
                    error_log('Exception sending review response email: ' . $e->getMessage());
                    $response_data['email_status'] = 'failed';
                    $response_data['email_error'] = 'Email error occurred';
                }
            }

            sendResponse($response_data, 201);
            break;

        case 'DELETE':
            $response_id = isset($_GET['response_id']) ? (int)$_GET['response_id'] : 0;
            if ($response_id < 1) {
                sendError('response_id parameter is required', 400);
            }

            $stmt = $pdo->prepare("DELETE FROM review_responses WHERE id = ?");
            $stmt->execute([$response_id]);
            if ($stmt->rowCount() === 0) {
                sendError('Response not found', 404);
            }

            rh_clear_review_caches();

            sendResponse([
                'success' => true,
                'message' => 'Response removed'
            ]);
            break;

        default:
            sendError('Method not allowed', 405);
            break;
    }
} catch (PDOException $e) {
    error_log("Database error in review-responses.php: " . $e->getMessage());
    sendError('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Error in review-responses.php: " . $e->getMessage());
    sendError('An error occurred', 500);
}

