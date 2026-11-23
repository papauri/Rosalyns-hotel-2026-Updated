<?php
declare(strict_types=1);

/**
 * Shift handover notes: write one (dashboard "Handover note" button; plan .claude/DASHBOARD_FIRSTLOGIN_PLAN.md C2).
 * POST action=write, body (1-500 characters after trim), csrf_token. Any signed-in user. The note shows on everyone's
 * dashboard until each person marks it read (admin/api/dashboard-seen.php) and stops showing 48 hours after it was
 * written. The author's own read row is written with the note, so the author never sees it.
 * System Logs get "Wrote a handover note: Note #ID" (the id only, never the text: it may hold guest details); the
 * generic activity hook skips this page (admin/includes/activity-hook.php) so one note = one log row.
 * Not on the view-only / licence read-only allowlists: refused while view only.
 * Before migration 091 has run on a database: a plain refusal, nothing logged.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/dashboard-firstlogin.php';
require_once __DIR__ . '/../includes/dashboard-since-data.php';
/** @var array $user */

function handover_notes_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    handover_notes_out(405, ['success' => false, 'error' => 'Method not allowed']);
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    handover_notes_out(401, ['success' => false, 'error' => 'Not authenticated']);
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    handover_notes_out(403, ['success' => false, 'error' => 'Your session has expired. Reload the page and try again.']);
}
session_write_close(); // token checked; the rest only touches the database

if ((string)($_POST['action'] ?? '') !== 'write') {
    handover_notes_out(400, ['success' => false, 'error' => 'Unknown action']);
}

$body = rh_dash_handover_clean_body(is_string($_POST['body'] ?? null) ? $_POST['body'] : '');
if ($body === null) {
    handover_notes_out(400, ['success' => false, 'error' => 'Write a note of 1 to 500 characters.']);
}

$authorName = trim((string)($user['full_name'] ?? ''));
if ($authorName === '') {
    $authorName = (string)($user['username'] ?? '');
}
$authorName = mb_substr($authorName, 0, 255, 'UTF-8');

$noteId = 0;
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO shift_handover_notes (body, created_by, created_by_name, created_at, expires_at)
        VALUES (?, ?, ?, NOW(), NOW() + INTERVAL 48 HOUR)');
    $ins->execute([$body, $userId, $authorName]);
    $noteId = (int)$pdo->lastInsertId();
    if ($noteId <= 0) {
        throw new RuntimeException('no note id');
    }
    $pdo->prepare('INSERT IGNORE INTO shift_handover_reads (note_id, user_id, read_at) VALUES (?, ?, NOW())')->execute([$noteId, $userId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (rh_dash_table_missing($e)) {
        handover_notes_out(503, ['success' => false, 'error' => 'Handover notes are not set up on this system yet.']);
    }
    error_log('handover-notes.php: ' . $e->getMessage());
    handover_notes_out(500, ['success' => false, 'error' => 'The note could not be saved. Please try again.']);
}

logActivity($userId, 'handover_note_written', 'Note #' . $noteId);

handover_notes_out(200, ['success' => true, 'data' => ['note_id' => $noteId]]);
