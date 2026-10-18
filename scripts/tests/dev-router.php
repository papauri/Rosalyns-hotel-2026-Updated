<?php
/**
 * Router for PHP's built-in server, for local browser checks only:
 *   php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php
 * /__devlogin?to=<admin page> signs the browser in as the first active admin (no password) and
 * redirects to admin/<page>. Refuses to run anywhere but the built-in server, and only for
 * requests from this machine. NEVER tunnel or port-forward this port (ngrok, WSL/Docker
 * forwarding, a reverse proxy): forwarded visitors look like 127.0.0.1 and would get admin.
 */
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('local dev server only');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__devlogin') {
    chdir($_SERVER['DOCUMENT_ROOT']);
    require $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session-boot.php';
    $admin = $pdo->query("SELECT id, username, full_name, role FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        http_response_code(500);
        exit('no active admin user');
    }
    rh_session_start();
    $_SESSION['admin_user_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['admin_full_name'] = $admin['full_name'];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_user'] = ['id' => (int)$admin['id'], 'username' => $admin['username'], 'role' => $admin['role'], 'full_name' => $admin['full_name']];
    header('Location: /admin/' . preg_replace('#[^A-Za-z0-9_\-./?=&%]#', '', (string)($_GET['to'] ?? 'dashboard.php')));
    exit;
}
return false;
