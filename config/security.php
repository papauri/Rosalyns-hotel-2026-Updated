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
 * IMAGE UPLOAD LIMITS
 * ============================================================================
 *
 * One cap for every image upload path in the admin. Before this, the six upload
 * handlers each carried their own number and they did not agree — room-pictures
 * 5 MB, gallery/events/conference 8 MB, room-management 20 MB, and
 * media-management had no cap at all, which is how an 8.1 MB JPEG reached
 * images/. Guest pages are latency-sensitive on mobile, so oversized originals
 * are a real conversion cost, not untidiness.
 *
 * Deliberately a SIZE CAP ONLY — no resizing. Resizing would need GD or Imagick,
 * which this codebase already treats as optional (see the fallback in
 * config/email.php for the gym member card), so a cap works everywhere and can
 * never silently corrupt an upload.
 *
 * Tune here, not in the handlers.
 */
if (!defined('RH_IMAGE_MAX_BYTES')) {
    define('RH_IMAGE_MAX_BYTES', 4 * 1024 * 1024);      // hard reject above 4 MB
}
if (!defined('RH_IMAGE_WARN_BYTES')) {
    define('RH_IMAGE_WARN_BYTES', 1536 * 1024);         // advise below 1.5 MB
}

if (!function_exists('rh_format_bytes_short')) {
    function rh_format_bytes_short(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }
}

/**
 * Validate an uploaded image against the shared size cap.
 *
 * @param array       $fileInput A single $_FILES entry.
 * @param string|null $warning   Set to advisory text when the file is allowed
 *                               but large enough to hurt page speed.
 * @return string|null Error message when the upload must be rejected, else null.
 */
if (!function_exists('rh_check_image_upload_size')) {
    function rh_check_image_upload_size(array $fileInput, ?string &$warning = null): ?string
    {
        $warning = null;
        $size = (int)($fileInput['size'] ?? 0);

        if ($size <= 0) {
            return null; // nothing uploaded; the caller's own checks handle this
        }

        if ($size > RH_IMAGE_MAX_BYTES) {
            return 'Image is too large ('
                . rh_format_bytes_short($size) . '). Maximum is '
                . rh_format_bytes_short(RH_IMAGE_MAX_BYTES)
                . '. Please compress it and try again.';
        }

        if ($size > RH_IMAGE_WARN_BYTES) {
            $warning = 'Image accepted at ' . rh_format_bytes_short($size)
                . ', which is large for a web page and will slow loading on mobile. '
                . 'Under ' . rh_format_bytes_short(RH_IMAGE_WARN_BYTES) . ' is recommended.';
        }

        return null;
    }
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
    // camera=(self) allows camera use on same-origin pages (required for barcode scanner)
    // Note: the canonical source for this header is .htaccess line ~80 which overrides this on Apache
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    
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
