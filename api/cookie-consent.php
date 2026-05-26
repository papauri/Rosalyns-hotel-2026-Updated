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

$consent_level = $_POST['consent_level'] ?? '';
if (!in_array($consent_level, ['all', 'essential', 'declined'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid consent level']);
    exit;
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}
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

    $stmt = $pdo->prepare("INSERT INTO cookie_consent_log (ip_address, user_agent, consent_level) VALUES (?, ?, ?)");
    $stmt->execute([$ip, substr($ua, 0, 500), $consent_level]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Cookie consent log error: ' . $e->getMessage());
    echo json_encode(['success' => true]); // Don't expose errors
}
