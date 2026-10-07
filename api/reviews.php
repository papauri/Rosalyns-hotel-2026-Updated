<?php
// Public Reviews API (read-only)
// Returns approved reviews for a given room_id
// Safety: PDO prepared statements; hard-coded allowed status; integer-cast limit

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reviews-display.php'; // rh_public_review_text()

function respond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (function_exists('moduleEnabled') && !moduleEnabled('website_cms')) {
        respond([ 'success' => true, 'count' => 0, 'reviews' => [] ]);
    }

    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
    if ($roomId <= 0) {
        respond([ 'success' => false, 'error' => 'Invalid room_id' ], 400);
    }

    $status = 'approved';
    if (isset($_GET['status'])) {
        $s = strtolower(trim((string)$_GET['status']));
        if ($s === 'approved') { $status = 'approved'; }
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 200) { $limit = 100; }

    // Build query with integer-inlined LIMIT (can’t bind LIMIT in some PDO drivers)
    $sql = "SELECT r.id, r.rating, r.title, r.comment, r.guest_name, r.created_at,
                   (SELECT rr.response FROM review_responses rr
                     WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) AS latest_response,
                   (SELECT rr.created_at FROM review_responses rr
                     WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) AS latest_response_date
            FROM reviews r
            WHERE r.room_id = :room_id AND r.status = :status
            ORDER BY r.created_at DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':room_id' => $roomId,
        ':status'  => $status,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Imported web feedback stores "Source: <url> / User Email: ..." in the comment
    // body. Never ship that to the browser — same trim the server-rendered pages use.
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['rating'] = (int)$row['rating'];
        $row['comment'] = rh_public_review_text($row['comment'] ?? '');
    }
    unset($row);

    respond([
        'success' => true,
        'count'   => count($rows),
        'reviews' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('api/reviews.php error: ' . $e->getMessage());
    respond([ 'success' => false, 'error' => 'Server error' ], 500);
}

