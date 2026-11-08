<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
/** @var array $user */

function nav_favorites_error(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function nav_favorites_success(array $favorites): void
{
    echo json_encode(['success' => true, 'data' => ['favorites' => $favorites]]);
    exit;
}

function nav_favorites_ensure_table(PDO $pdo): void
{
    // Created once, only when missing (no DDL on normal requests: it takes metadata/FK locks). No migration owns this table.
    try {
        if ($pdo->query("SHOW TABLES LIKE 'admin_user_preferences'")->fetchColumn()) {
            return;
        }
    } catch (Throwable $e) {
        // fall through to the create attempt
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_user_preferences (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        preference_key VARCHAR(100) NOT NULL,
        preference_value TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_admin_user_pref (user_id, preference_key),
        KEY idx_admin_user_preferences_user (user_id),
        CONSTRAINT fk_admin_user_preferences_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function nav_favorites_sanitize_keys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $seen = [];
    $clean = [];

    foreach ($value as $raw) {
        if (!is_string($raw)) {
            continue;
        }
        $key = trim($raw);
        if ($key === '') {
            continue;
        }
        if (strlen($key) > 120) {
            continue;
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $key)) {
            continue;
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = $key;
        if (count($clean) >= 80) {
            break;
        }
    }

    return $clean;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    nav_favorites_error('Method not allowed', 405);
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    nav_favorites_error('Not authenticated', 401);
}

$preferenceKey = 'admin_nav_favorites_v1';

// A read needs nothing more from the session: release its lock so the next page request is never queued behind this one.
if ($method === 'GET') {
    session_write_close();
}

try {
    // Cosmetic setting: never wait long on a locked row.
    try { $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3'); } catch (Throwable $ignore) { /* keep default */ }
    nav_favorites_ensure_table($pdo);

    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT preference_value FROM admin_user_preferences WHERE user_id = ? AND preference_key = ? LIMIT 1');
        $stmt->execute([$userId, $preferenceKey]);
        $stored = $stmt->fetchColumn();

        $favorites = [];
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            $favorites = nav_favorites_sanitize_keys($decoded);
        }

        nav_favorites_success($favorites);
    }

    $payload = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if (!empty($_POST)) {
        $payload = array_merge($payload, $_POST);
    }

    if (!validateCsrfToken((string)($payload['csrf_token'] ?? ''))) {
        nav_favorites_error('Invalid CSRF token', 403);
    }
    session_write_close(); // token checked; the rest only touches the database

    $favorites = nav_favorites_sanitize_keys($payload['favorites'] ?? []);
    $encoded = json_encode($favorites, JSON_UNESCAPED_SLASHES);

    $upsert = $pdo->prepare('INSERT INTO admin_user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()');
    $upsert->execute([$userId, $preferenceKey, $encoded]);

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/nav-favorites', 'info', 'Admin nav favorites updated', [
            'user_id' => $userId,
            'favorites_count' => count($favorites)
        ]);
    }

    nav_favorites_success($favorites);
} catch (Throwable $e) {
    error_log('nav-favorites.php: ' . $e->getMessage());
    // Cosmetic setting: answer quietly (202, not saved) instead of a 500 in the console.
    http_response_code(202);
    echo json_encode(['success' => false, 'error' => 'Unable to save favorites right now']);
    exit;
}

