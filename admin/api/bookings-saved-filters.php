<?php
declare(strict_types=1);

/**
 * Saved filter sets on the Bookings list (plan .claude/BOOKINGS_LIST_PLAN.md T9). POST + csrf_token only:
 *   action=save,   name, query   save the current list filters under a name (same name replaces it)
 *   action=delete, name          delete the set with that name
 * Only ever the signed-in person's own admin_user_preferences row (no user id is read from the request). The person must
 * be able to open bookings.php: the page's own permission and module check (admin-init.php), which api-init.php does
 * not do. Not on the view-only / licence read-only allowlists, so the guard in api-init.php refuses both actions for a
 * view-only person, a view-only trial and a read-only licence; refused here as well for a view-only person.
 * The list itself is read by bookings.php while it renders. Answers {success, data: {sets: [{name, url}]}}.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/bookings-saved-filters.php';
/** @var array $user */
/** @var PDO $pdo */

function bk_saved_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bk_saved_out(405, ['success' => false, 'error' => 'Method not allowed']);
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    bk_saved_out(401, ['success' => false, 'error' => 'Not authenticated']);
}

// Same gate as the Bookings page: its permission, then its module (admins: the licence's modules).
if (!rhHasPagePermission($userId, 'bookings.php')) {
    api_json_forbidden('You cannot open the Bookings list.');
}
rhApiModuleGate('bookings.php');

if (function_exists('rh_view_only_reason') && rh_view_only_reason() !== '') {
    bk_saved_out(403, ['success' => false, 'error' => rh_view_only_message(rh_view_only_reason()), 'view_only' => true]);
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    bk_saved_out(403, ['success' => false, 'error' => 'Your session has expired. Reload the page and try again.']);
}
session_write_close(); // token checked; the rest only touches the database

$action = (string)($_POST['action'] ?? '');
if ($action !== 'save' && $action !== 'delete') {
    bk_saved_out(400, ['success' => false, 'error' => 'Unknown action']);
}

// Personal setting: never wait long on a locked row (as nav-favorites.php).
try { $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3'); } catch (Throwable $ignore) { /* keep default */ }

$r = rh_bk_saved_write($pdo, $userId, $action, [
    'name'  => $_POST['name'] ?? null,
    'query' => $_POST['query'] ?? null,
]);
if (!$r['ok']) {
    bk_saved_out($r['status'], ['success' => false, 'error' => $r['error']]);
}
bk_saved_out(200, ['success' => true, 'data' => ['sets' => rh_bk_saved_links($r['list'])]]);
