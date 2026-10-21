<?php
/**
 * Cookie Consent API
 * Logs user's cookie consent decision to database and log file.
 */
header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/public-csrf.php';

// Unauthenticated endpoint: limit per IP so it cannot be used to fill the table.
if (!pub_ip_rate_limit('cookie_consent', 20, 600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

$consent_level = $_POST['consent_level'] ?? '';
if (!in_array($consent_level, ['all', 'essential', 'declined'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid consent level']);
    exit;
}

// REMOTE_ADDR only: X-Forwarded-For is client-controlled and would let anyone write any "IP".
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$timestamp = date('Y-m-d H:i:s');

try {
    // Auto-create consent log table if needed
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `cookie_consent_log` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45) NOT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `consent_level` ENUM('all', 'essential', 'declined') NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // One row per IP, per choice, per day.
    $dupe = $pdo->prepare("SELECT 1 FROM cookie_consent_log WHERE ip_address = ? AND consent_level = ? AND created_at >= CURDATE() LIMIT 1");
    $dupe->execute([$ip, $consent_level]);
    if ($dupe->fetchColumn()) {
        echo json_encode(['success' => true]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO cookie_consent_log (ip_address, user_agent, consent_level) VALUES (?, ?, ?)");
    $stmt->execute([$ip, substr($ua, 0, 500), $consent_level]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Cookie consent log error: ' . $e->getMessage());
    echo json_encode(['success' => true]); // Don't expose errors
}
