<?php
declare(strict_types=1);
require_once __DIR__ . '/api-init.php';

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
$currency = getSetting('currency_symbol', 'K');
$rows     = [];

// Primary query — joins payments for accurate lifetime spend
try {
    $stmt = $pdo->prepare("
        SELECT
            b.guest_email,
            MAX(b.guest_name)                     AS guest_name,
            MAX(b.guest_phone)                    AS guest_phone,
            MAX(COALESCE(b.guest_country, ''))    AS guest_country,
            MAX(COALESCE(b.guest_address, ''))    AS guest_address,
            COUNT(*)                              AS total_bookings,
            SUM(CASE WHEN b.status NOT IN ('cancelled','no-show','expired')
                     THEN 1 ELSE 0 END)           AS completed_stays,
            MAX(b.check_in_date)                  AS last_check_in,
            MAX(b.check_out_date)                 AS last_check_out,
            (SELECT b2.booking_reference
             FROM bookings b2
             WHERE b2.guest_email = b.guest_email
               AND b2.status NOT IN ('cancelled','no-show','expired')
               AND b2.deleted_at IS NULL
             ORDER BY b2.check_in_date DESC LIMIT 1) AS last_booking_ref,
            COALESCE((
                SELECT SUM(p.payment_amount)
                FROM payments p
                INNER JOIN bookings b3 ON b3.id = p.booking_id
                WHERE b3.guest_email = b.guest_email
                  AND p.payment_status IN ('completed','paid')
            ), 0) AS lifetime_spend
        FROM bookings b
        WHERE b.guest_email != ''
          AND b.deleted_at IS NULL
          AND (b.guest_name  LIKE :q1
            OR b.guest_email LIKE :q2
            OR b.guest_phone LIKE :q3)
        GROUP BY b.guest_email
        ORDER BY last_check_in DESC
        LIMIT 10
    ");
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback — simpler query without deleted_at or payments join
    try {
        $stmt = $pdo->prepare("
            SELECT
                guest_email,
                MAX(guest_name)                   AS guest_name,
                MAX(guest_phone)                  AS guest_phone,
                MAX(COALESCE(guest_country, ''))  AS guest_country,
                MAX(COALESCE(guest_address, ''))  AS guest_address,
                COUNT(*)                          AS total_bookings,
                SUM(CASE WHEN status NOT IN ('cancelled','no-show','expired')
                         THEN 1 ELSE 0 END)       AS completed_stays,
                MAX(check_in_date)                AS last_check_in,
                MAX(check_out_date)               AS last_check_out,
                MAX(booking_reference)            AS last_booking_ref,
                COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','no-show','expired')
                                  THEN total_amount ELSE 0 END), 0) AS lifetime_spend
            FROM bookings
            WHERE guest_email != ''
              AND (guest_name  LIKE :q1
                OR guest_email LIKE :q2
                OR guest_phone LIKE :q3)
            GROUP BY guest_email
            ORDER BY last_check_in DESC
            LIMIT 10
        ");
        $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e2) {
        error_log('Guest lookup fallback: ' . $e2->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
        exit;
    }
}

$guests = array_map(function (array $r) use ($currency): array {
    $stays  = (int)$r['completed_stays'];
    $lastIn = $r['last_check_in'] ?? '';
    return [
        'email'            => (string)$r['guest_email'],
        'name'             => (string)$r['guest_name'],
        'phone'            => (string)$r['guest_phone'],
        'country'          => (string)$r['guest_country'],
        'address'          => (string)$r['guest_address'],
        'total_bookings'   => (int)$r['total_bookings'],
        'completed_stays'  => $stays,
        'last_check_in'    => $lastIn,
        'last_check_out'   => (string)($r['last_check_out'] ?? ''),
        'last_booking_ref' => (string)($r['last_booking_ref'] ?? ''),
        'lifetime_spend'   => (float)$r['lifetime_spend'],
        'currency'         => $currency,
        'is_returning'     => $stays >= 1,
        'stay_label'       => $stays === 0 ? 'No completed stays'
            : ($stays === 1 ? '1 previous stay' : $stays . ' previous stays'),
        'last_stay_label'  => $lastIn ? date('M j, Y', strtotime($lastIn)) : '',
    ];
}, $rows);

echo json_encode(['guests' => $guests], JSON_UNESCAPED_UNICODE);
exit;
