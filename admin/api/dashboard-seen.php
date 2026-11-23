<?php
declare(strict_types=1);

/**
 * The dashboard's "seen it" actions (plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md C2). POST + csrf_token, any signed-in
 * user, only ever the caller's own rows (no user id is read from the request):
 *   action=handover_read, note_id   mark a handover note read: the note must exist and not have expired; marking it
 *                                   again is a no-op. Logged once as "Read a handover note: Note #ID" (id only).
 *                                   Answers with the next unread note (or none) so the dashboard can show it.
 *   action=welcome_dismissed        "Got it" on the first-login welcome card (admin_user_preferences). Not logged.
 * On the view-only / licence read-only allowlists (personal bookkeeping, like nav-favorites.php), so the generic
 * activity hook skips it too. Before migration 091 has run: a plain refusal, nothing logged.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/dashboard-firstlogin.php';
require_once __DIR__ . '/../includes/dashboard-since-data.php';
/** @var array $user */

function dashboard_seen_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dashboard_seen_out(405, ['success' => false, 'error' => 'Method not allowed']);
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    dashboard_seen_out(401, ['success' => false, 'error' => 'Not authenticated']);
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    dashboard_seen_out(403, ['success' => false, 'error' => 'Your session has expired. Reload the page and try again.']);
}
session_write_close(); // token checked; the rest only touches the database

$action = (string)($_POST['action'] ?? '');

if ($action === 'handover_read') {
    $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($noteId === false) {
        dashboard_seen_out(400, ['success' => false, 'error' => 'Unknown note']);
    }
    $added = false;
    try {
        $st = $pdo->prepare('SELECT id FROM shift_handover_notes WHERE id = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$noteId]);
        if ($st->fetchColumn() === false) {
            dashboard_seen_out(404, ['success' => false, 'error' => 'That note is no longer there.']);
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO shift_handover_reads (note_id, user_id, read_at) VALUES (?, ?, NOW())');
        $ins->execute([$noteId, $userId]);
        $added = $ins->rowCount() === 1;
    } catch (Throwable $e) {
        if (rh_dash_table_missing($e)) {
            dashboard_seen_out(503, ['success' => false, 'error' => 'Handover notes are not set up on this system yet.']);
        }
        error_log('dashboard-seen.php handover_read: ' . $e->getMessage());
        dashboard_seen_out(500, ['success' => false, 'error' => 'Could not mark the note read. Please try again.']);
    }
    if ($added) {
        logActivity($userId, 'handover_note_read', 'Note #' . $noteId);
    }
    $next = rh_dash_handover_unread($pdo, $userId);
    $nextNote = null;
    if (is_array($next) && $next['note'] !== null) {
        $n = $next['note'];
        $nextNote = [
            'id' => (int)$n['id'],
            'body' => (string)$n['body'],
            'from' => rh_dash_handover_from_text((string)$n['from'], (string)$n['at'], date('Y-m-d'), (int)$next['more']),
        ];
    }
    dashboard_seen_out(200, ['success' => true, 'data' => ['next' => $nextNote]]);
}

if ($action === 'welcome_dismissed') {
    try {
        $up = $pdo->prepare("INSERT INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES (?, 'dashboard_welcome_dismissed', ?)
            ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()");
        $up->execute([$userId, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        if (!rh_dash_table_missing($e)) {
            error_log('dashboard-seen.php welcome_dismissed: ' . $e->getMessage());
        }
        dashboard_seen_out(503, ['success' => false, 'error' => 'Could not save that right now.']);
    }
    dashboard_seen_out(200, ['success' => true]);
}

dashboard_seen_out(400, ['success' => false, 'error' => 'Unknown action']);
