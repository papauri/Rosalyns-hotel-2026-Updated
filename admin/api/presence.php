<?php

/**
 * Staff presence endpoint (GET only).
 *
 *   GET presence.php            -> who is online now. Administrators and managers only (403 for
 *                                  everyone else); IPs are included for administrators only.
 *   GET presence.php?ping=1&page=bookings.php
 *                               -> heartbeat from an open admin tab while the person is using it.
 *                                  Writes only the caller's own row and needs the
 *                                  X-Requested-With header, which a cross-site request cannot
 *                                  send, so it needs no CSRF token. Being a GET it does not
 *                                  reset the 8-hour idle sign-out clock (only real page loads do).
 *   GET presence.php?booking=1&ci=2026-11-12&co=2026-11-14&rooms=3,5&age=90[&idle=1]
 *                               -> the "someone else is booking" banner on Create Booking. For anyone
 *                                  who can open create-booking.php (403 otherwise, nothing written):
 *                                  records the caller's own note (dates, room types, when the page was
 *                                  opened; skipped when idle=1 so an abandoned tab ages out) and returns
 *                                  the OTHER people with that page open right now. Same
 *                                  X-Requested-With rule as the ping.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/presence-lib.php';
/** @var array $user */
/** @var PDO $pdo */

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

if (isset($_GET['ping'])) {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Bad request']);
        exit;
    }
    rh_presence_heartbeat($pdo, $userId, (string)($_GET['page'] ?? ''));
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['booking'])) {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Bad request']);
        exit;
    }
    if (!rhCanOpenPage($userId, 'create-booking.php')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    $ctx = rh_presence_booking_ctx_clean(
        (string)($_GET['ci'] ?? ''),
        (string)($_GET['co'] ?? ''),
        (string)($_GET['rooms'] ?? ''),
        (int)($_GET['age'] ?? 0)
    );
    if (empty($_GET['idle'])) {
        rh_presence_booking_write($pdo, $userId, $ctx);
    }
    $others = rh_presence_booking_others($pdo, $userId, $ctx);
    echo json_encode([
        'success' => true,
        'data' => ['others' => $others, 'overlap' => (bool)array_filter($others, function ($o) { return $o['overlap']; })],
    ]);
    exit;
}

if (!rh_presence_can_view($role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => ['online' => rh_presence_rows($pdo, rh_presence_can_see_ip($role))],
]);
