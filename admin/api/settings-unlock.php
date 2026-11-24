<?php
declare(strict_types=1);

/**
 * Unlock one high-value settings group (VAT, bank details, tourism levy, service charge) for 10 minutes with the
 * signed-in person's own password (owner 2026-11-25; includes/settings-lock.php). POST + csrf_token + group + password.
 * finance_settings only. Wrong passwords are counted per session (5 per 15 minutes). The password is never stored,
 * echoed or logged; the session is written (token / fail count) before the reply.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/settings-lock.php';
/** @var array $user */
/** @var PDO $pdo */

function settings_unlock_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    settings_unlock_out(405, ['success' => false, 'error' => 'Method not allowed']);
}
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    settings_unlock_out(401, ['success' => false, 'error' => 'Not authenticated']);
}
// 200 + this wording: admin-main.js reloads the page once to get a fresh token.
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    settings_unlock_out(200, ['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
}
if (!hasPermission($userId, 'finance_settings')) {
    $GLOBALS['rh_sa_refused'] = true;
    settings_unlock_out(403, ['success' => false, 'error' => 'You do not have permission to change these settings.']);
}
$group = (string)($_POST['group'] ?? '');
if (!rh_settings_lock_valid_group($group)) {
    $GLOBALS['rh_sa_refused'] = true;
    settings_unlock_out(400, ['success' => false, 'error' => 'Unknown settings group.']);
}

// Caught here so a database fault never reaches the uncaught-error log, whose trace could carry the password.
try {
    [$ok, $msg, $ttl] = rh_settings_lock_unlock($pdo, $userId, $group, (string)($_POST['password'] ?? ''));
} catch (Throwable $e) {
    unset($_POST['password'], $e);
    $GLOBALS['rh_sa_refused'] = true;
    settings_unlock_out(500, ['success' => false, 'error' => 'Could not check the password right now. Nothing was unlocked. Try again.']);
}
unset($_POST['password']);
if (!$ok) {
    $GLOBALS['rh_sa_refused'] = true; // a refused unlock is not staff work (activity hook)
}
session_write_close(); // token / fail count saved before the reply
settings_unlock_out(200, ['success' => $ok, $ok ? 'message' : 'error' => $msg, 'expires_in' => $ttl]);
