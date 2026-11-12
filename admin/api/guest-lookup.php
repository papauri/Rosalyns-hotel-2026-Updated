<?php
declare(strict_types=1);
require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/guest-history.php';

header('Content-Type: application/json; charset=utf-8');

requireApiPermission('bookings');

$q    = trim($_GET['q'] ?? '');
$csrf = (string)($_GET['csrf'] ?? '');

if (!function_exists('validateCsrfToken') || !validateCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

if (mb_strlen($q) < 2) {
    echo json_encode(['guests' => []]);
    exit;
}

$like     = '%' . $q . '%';
$currency = getSetting('currency_symbol', 'MWK');
// Money figures follow the booking page: whoever can open a booking there sees its Paid figure (page permission
// 'bookings'), so the same people see what a guest has paid here. Checked explicitly so it stays tied to that rule.
$canSeeMoney = hasPermission((int)($user['id'] ?? 0), 'bookings');

// 1. The guests the search matches: up to 10 distinct emails (trimmed, case-insensitive), newest booking first.
//    Counts and money come from the shared guest-history rule below, over ALL of each guest's bookings, not only
//    the bookings the search text happened to match.
try {
    $stmt = $pdo->prepare("
        SELECT LOWER(TRIM(b.guest_email)) AS email_key, MAX(b.check_in_date) AS last_check_in
        FROM bookings b
        WHERE TRIM(COALESCE(b.guest_email, '')) <> ''
          AND b.deleted_at IS NULL
          AND (b.guest_name  LIKE :q1
            OR b.guest_email LIKE :q2
            OR b.guest_phone LIKE :q3)
        GROUP BY LOWER(TRIM(b.guest_email))
        ORDER BY last_check_in DESC
        LIMIT 10
    ");
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Contact details from each guest's latest booking (one row, so name/phone/address belong together).
    $contactStmt = $pdo->prepare("
        SELECT TRIM(b.guest_email) AS guest_email, b.guest_name, b.guest_phone,
               COALESCE(b.guest_country, '') AS guest_country, COALESCE(b.guest_address, '') AS guest_address
        FROM bookings b
        WHERE b.deleted_at IS NULL AND " . rh_guest_history_email_sql('b') . "
        ORDER BY b.check_in_date DESC, b.id DESC
        LIMIT 1
    ");
} catch (\Throwable $e) {
    error_log('Guest lookup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

$guests = [];
foreach ($keys as $key) {
    try {
        $contactStmt->execute([(string)$key]);
        $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('Guest lookup contact: ' . $e->getMessage());
        $contact = false;
    }
    if (!$contact) {
        continue;
    }
    $h = rh_guest_history($pdo, (string)$contact['guest_email'], 0, $canSeeMoney, 0);
    $guests[] = rh_guest_history_lookup_row($contact, $h, (string)$currency);
}

echo json_encode(['guests' => $guests], JSON_UNESCAPED_UNICODE);
exit;
