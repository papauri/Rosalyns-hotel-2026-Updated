<?php

/**
 * Review Responses API
 * Hotel Website - Admin API for managing admin responses to reviews
 *
 * Endpoints:
 * - GET: Fetch responses for a specific review
 * - POST: Add a new admin response to a review
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

// Include database configuration (corrected relative path)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';

// Include email configuration (corrected relative path)
require_once __DIR__ . '/../../config/email.php';

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

    // Validate admin_id if provided
    if (isset($data['admin_id']) && $data['admin_id'] !== null && $data['admin_id'] !== '') {
        if (!is_numeric($data['admin_id']) || (int)$data['admin_id'] < 1) {
            $errors['admin_id'] = 'Invalid admin ID';
        }
    }

    // Validate response length
    if (isset($data['response'])) {
        $response_length = strlen(trim($data['response']));
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
                    au.username as admin_username,
                    au.email as admin_email
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
            $response_text = trim($input['response']);

            // Default admin_id to logged-in admin, but allow override via input
            $admin_id = isset($input['admin_id']) && $input['admin_id'] !== '' ? (int)$input['admin_id'] : null;
            if ($admin_id === null && isset($_SESSION['admin_user_id'])) {
                $admin_id = (int)$_SESSION['admin_user_id'];
            }

            // Validate review exists
            $stmt = $pdo->prepare("SELECT id, status FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                sendError('Review not found', 404);
            }

            // Validate admin_id if provided
            if ($admin_id !== null) {
                $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE id = ?");
                $stmt->execute([$admin_id]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    sendError('Admin user not found', 404);
                }
            }

            // Get review details for email
            $stmt = $pdo->prepare("SELECT id, guest_name, guest_email, title, comment FROM reviews WHERE id = ?");
            $stmt->execute([$review_id]);
            $review_details = $stmt->fetch(PDO::FETCH_ASSOC);

            // Insert response
            $sql = "
                INSERT INTO review_responses (review_id, admin_id, response)
                VALUES (?, ?, ?)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$review_id, $admin_id, $response_text]);

            $response_id = $pdo->lastInsertId();

            // Fetch the created response with admin details
            $sql = "
                SELECT
                    rr.*,
                    au.username as admin_username,
                    au.email as admin_email
                FROM review_responses rr
                LEFT JOIN admin_users au ON rr.admin_id = au.id
                WHERE rr.id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$response_id]);
            $new_response = $stmt->fetch(PDO::FETCH_ASSOC);

            // Prepare response with email status
            $response_data = [
                'success' => true,
                'message' => 'Response added successfully',
                'data' => $new_response,
                'email_sent' => false,
                'email_status' => 'not_attempted'
            ];

            // Send email notification to guest
            if (!empty($review_details['guest_email'])) {
                $response_data['email_status'] = 'attempting';

                $site_name = getSetting('site_name');
                $site_url = getSetting('site_url', '');

                $email_subject = "Response to your review at {$site_name}";

                $email_body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <style>
                        body { font-family: 'Jost', Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #8B7355 0%, #A08B6D 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                        .header h1 { color: #fff; margin: 0; font-size: 24px; }
                        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                        .review-box { background: #fff; padding: 20px; border-left: 4px solid #8B7355; margin: 20px 0; }
                        .response-box { background: #fff8e1; padding: 20px; border-left: 4px solid #A08B6D; margin: 20px 0; }
                        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 14px; }
                        .btn { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #8B7355 0%, #A08B6D 100%); color: #fff; text-decoration: none; border-radius: 50px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1>{$site_name}</h1>
                        </div>
                        <div class='content'>
                            <h2>Thank You for Your Feedback!</h2>
                            <p>Dear " . htmlspecialchars($review_details['guest_name']) . ",</p>
                            <p>Thank you for taking the time to share your experience at {$site_name}. We value your feedback and have responded to your review.</p>

                            <div class='review-box'>
                                <h3>Your Review:</h3>
                                <p><strong>" . htmlspecialchars($review_details['title']) . "</strong></p>
                                <p>" . htmlspecialchars(substr($review_details['comment'], 0, 200)) . (strlen($review_details['comment']) > 200 ? '...' : '') . "</p>
                            </div>

                            <div class='response-box'>
                                <h3>Our Response:</h3>
                                <p>" . nl2br(htmlspecialchars($response_text)) . "</p>
                            </div>

                            <p>We hope to welcome you back to {$site_name} soon!</p>

                            <a href='{$site_url}' class='btn'>Visit Our Website</a>

                            <div class='footer'>
                                <p>&copy; " . date('Y') . " {$site_name}. All rights reserved.</p>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
                ";

                $text_body = "Thank you for your review at {$site_name}.\n\n";
                $text_body .= "We have responded to your review titled: " . $review_details['title'] . "\n\n";
                $text_body .= "Our Response:\n" . strip_tags($response_text) . "\n\n";
                $text_body .= "Visit us at: {$site_url}\n";

                // Log email attempt
                error_log("Attempting to send review response email to: " . $review_details['guest_email']);

                try {
                    $result = sendEmail(
                        $review_details['guest_email'],
                        $review_details['guest_name'],
                        $email_subject,
                        $email_body,
                        $text_body
                    );

                    if ($result['success']) {
                        error_log("Review response email sent successfully to: " . $review_details['guest_email']);
                        $response_data['email_sent'] = true;
                        $response_data['email_status'] = 'sent';
                        $response_data['message'] .= ' Email notification sent to guest.';
                    } else {
                        error_log("Review response email failed: " . $result['message']);
                        $response_data['email_sent'] = false;
                        $response_data['email_status'] = 'failed';
                        $response_data['email_error'] = $result['message'];
                        $response_data['message'] .= ' Note: Email could not be sent - ' . $result['message'];

                        // Add preview if available
                        if (isset($result['preview'])) {
                            $response_data['email_preview'] = $result['preview'];
                        }
                    }
                } catch (Exception $e) {
                    error_log("Exception sending review response email: " . $e->getMessage());
                    error_log("Email error trace: " . $e->getTraceAsString());
                    $response_data['email_sent'] = false;
                    $response_data['email_status'] = 'exception';
                    $response_data['email_error'] = $e->getMessage();
                    $response_data['message'] .= ' Note: Email error occurred - ' . $e->getMessage();
                }
            } else {
                $response_data['email_status'] = 'no_guest_email';
                $response_data['message'] .= ' No guest email on file.';
            }

            sendResponse($response_data, 201);
            break;

        default:
            sendError('Method not allowed', 405);
            break;
    }
} catch (PDOException $e) {
    error_log("Database error in review-responses.php: " . $e->getMessage());
    sendError('Database error occurred', 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Error in review-responses.php: " . $e->getMessage());
    sendError('An error occurred', 500, $e->getMessage());
}

