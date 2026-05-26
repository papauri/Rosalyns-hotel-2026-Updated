<?php
/**
 * Security Configuration and Utilities
 * Hotel Website - Basic Security Layer
 * 
 * Features:
 * - Security Headers (including CSP for Font Awesome)
 * - Input Sanitization Helpers
 * 
 * @version 3.0 (Simplified)
 * @date 2026-02-01
 */

// Ensure this file is only included, not accessed directly
if (!defined('SECURITY_INCLUDED')) {
    define('SECURITY_INCLUDED', true);
}

/**
 * ============================================================================
 * SECURITY HEADERS
 * ============================================================================
 */

/**
 * Send security headers
 * Call this at the beginning of each page
 * Includes CSP fix for Font Awesome
 */
function sendSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Enable XSS filter (browser-side)
    header('X-XSS-Protection: 1; mode=block');
    
    // Content Security Policy with Font Awesome fix, video support, and Flatpickr CDN
    // This allows Font Awesome and Flatpickr to load properly and enables video embeds from popular platforms
    // Added media-src for Getty Images videos and other external media
    // Added cdn.jsdelivr.net to connect-src for Bootstrap source maps
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https: https://api.qrserver.com; connect-src 'self' https://cdn.jsdelivr.net; media-src 'self' https: blob:; frame-src 'self' https://www.youtube.com https://youtube.com https://player.vimeo.com https://vimeo.com https://www.dailymotion.com https://dai.ly https://media.gettyimages.com https://*.gettyimages.com;");
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions Policy (formerly Feature-Policy)
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // HSTS (only on HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * ============================================================================
 * INPUT SANITIZATION HELPERS
 * ============================================================================
 */

/**
 * Sanitize input string
 * 
 * @param string $input Input string to sanitize
 * @return string Sanitized string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize input array (GET, POST, etc.)
 * 
 * @param array $input Input array to sanitize
 * @return array Sanitized array
 */
function sanitizeInputArray($input) {
    $sanitized = [];
    
    foreach ($input as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeInputArray($value);
        } else {
            $sanitized[$key] = sanitizeInput($value);
        }
    }
    
    return $sanitized;
}

/**
 * ============================================================================
 * CSRF PROTECTION
 * ============================================================================
 */

/**
 * Generate CSRF token for forms
 *
 * @return string CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 *
 * @param string $token Token to validate
 * @return bool True if valid, false otherwise
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF field HTML for forms
 *
 * @return string HTML hidden input field with CSRF token
 */
function getCsrfField() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Require CSRF validation for POST requests
 * Throws an exception if validation fails
 *
 * @throws Exception if CSRF token is invalid or missing
 */
function requireCsrfValidation() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        
        // Diagnostic logging
        $logDir = __DIR__ . '/../logs/security';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/csrf-debug.log';
        
        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'session_status' => session_status(),
            'session_id' => session_id(),
            'session_exists' => isset($_SESSION),
            'csrf_token_in_session' => isset($_SESSION['csrf_token']) ? 'YES' : 'NO',
            'csrf_token_value' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) . '...' : 'N/A',
            'received_token' => !empty($token) ? substr($token, 0, 10) . '...' : 'EMPTY',
            'tokens_match' => isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token) ? 'YES' : 'NO',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'post_fields' => array_keys($_POST)
        ];
        
        file_put_contents($logFile, json_encode($debugInfo) . "\n", FILE_APPEND | LOCK_EX);
        
        if (!validateCsrfToken($token)) {
            throw new Exception('CSRF token validation failed. Please refresh the page and try again.');
        }
    }
}

/**
 * ============================================================================
 * SECURITY EVENT LOGGING (Optional)
 * ============================================================================
 */

/**
 * Log security event for audit trail
 * 
 * @param string $event Type of security event
 * @param array $details Event details
 */
function logSecurityEvent($event, $details = []) {
    $logDir = __DIR__ . '/../logs/security';
    
    // Create log directory if it doesn't exist
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/security-' . date('Y-m-d') . '.log';
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'details' => $details
    ];
    
    // Append to log file
    file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
}
