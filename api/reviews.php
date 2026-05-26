<?php
// Public Reviews API (read-only)
// Returns approved reviews for a given room_id
// Safety: PDO prepared statements; hard-coded allowed status; integer-cast limit

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

function respond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
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
    $sql = "SELECT rating, comment, guest_name, created_at
            FROM reviews
            WHERE room_id = :room_id AND status = :status
            ORDER BY created_at DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':room_id' => $roomId,
        ':status'  => $status,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    respond([
        'success' => true,
        'count'   => count($rows),
        'reviews' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('api/reviews.php error: ' . $e->getMessage());
    respond([ 'success' => false, 'error' => 'Server error' ], 500);
}

