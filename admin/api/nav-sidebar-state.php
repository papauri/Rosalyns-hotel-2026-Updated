<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api-init.php';
/** @var array $user */

function nsbs_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function nsbs_success(array $data): void
{
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    nsbs_error('Method not allowed', 405);
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    nsbs_error('Not authenticated', 401);
}

const NSBS_KEY_COLLAPSED = 'admin_sidebar_collapsed_v1';
const NSBS_KEY_WIDTH     = 'admin_sidebar_width_v1';
const NSBS_KEY_GROUPS    = 'admin_nav_collapsed_groups_v1';

try {
    // Ensure the shared preferences table exists (created by nav-favorites.php on first use,
    // but we create it here too so this endpoint works independently).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_user_preferences (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_admin_user_pref (user_id, preference_key),
            KEY idx_admin_user_preferences_user (user_id),
            CONSTRAINT fk_nsbs_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $createEx) {
        // Table already exists with existing constraint name — ignore.
    }

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT preference_key, preference_value
             FROM admin_user_preferences
             WHERE user_id = ? AND preference_key IN (?, ?, ?)'
        );
        $stmt->execute([$userId, NSBS_KEY_COLLAPSED, NSBS_KEY_WIDTH, NSBS_KEY_GROUPS]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[(string)$row['preference_key']] = (string)$row['preference_value'];
        }

        // sidebar_collapsed: null = no preference saved, true/false = explicit preference
        $collapsed = null;
        if (array_key_exists(NSBS_KEY_COLLAPSED, $rows)) {
            $collapsed = $rows[NSBS_KEY_COLLAPSED] === '1';
        }

        // sidebar_width: null = use default (292px)
        $width = null;
        if (array_key_exists(NSBS_KEY_WIDTH, $rows)) {
            $w = (int)$rows[NSBS_KEY_WIDTH];
            if ($w >= 220 && $w <= 480) {
                $width = $w;
            }
        }

        // collapsed_groups: array of nav-group data-nav-group values
        $groups = [];
        if (!empty($rows[NSBS_KEY_GROUPS])) {
            $decoded = json_decode($rows[NSBS_KEY_GROUPS], true);
            if (is_array($decoded)) {
                foreach ($decoded as $g) {
                    if (is_string($g) && preg_match('/^[a-z0-9\-]+$/', $g)) {
                        $groups[] = $g;
                    }
                }
            }
        }

        nsbs_success([
            'sidebar_collapsed' => $collapsed,
            'sidebar_width'     => $width,
            'collapsed_groups'  => $groups,
        ]);
    }

    // POST — save any subset of {sidebar_collapsed, sidebar_width, collapsed_groups}
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
        nsbs_error('Invalid CSRF token', 403);
    }

    $upsert = $pdo->prepare(
        'INSERT INTO admin_user_preferences (user_id, preference_key, preference_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()'
    );

    $saved = [];

    if (array_key_exists('sidebar_collapsed', $payload)) {
        $raw = $payload['sidebar_collapsed'];
        $val = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') ? '1' : '0';
        $upsert->execute([$userId, NSBS_KEY_COLLAPSED, $val]);
        $saved[] = 'sidebar_collapsed';
    }

    if (array_key_exists('sidebar_width', $payload)) {
        $w = max(220, min(480, (int)$payload['sidebar_width']));
        $upsert->execute([$userId, NSBS_KEY_WIDTH, (string)$w]);
        $saved[] = 'sidebar_width';
    }

    if (isset($payload['collapsed_groups']) && is_array($payload['collapsed_groups'])) {
        $groups = [];
        foreach ($payload['collapsed_groups'] as $g) {
            if (is_string($g) && preg_match('/^[a-z0-9\-]+$/', $g)) {
                $groups[] = $g;
            }
        }
        $upsert->execute([$userId, NSBS_KEY_GROUPS, json_encode($groups, JSON_UNESCAPED_SLASHES)]);
        $saved[] = 'collapsed_groups';
    }

    nsbs_success(['saved' => $saved]);
} catch (Throwable $e) {
    error_log('nav-sidebar-state.php: ' . $e->getMessage());
    nsbs_error('Unable to save sidebar preferences', 500);
}

