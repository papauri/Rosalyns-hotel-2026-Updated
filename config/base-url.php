<?php
/**
 * Base URL Confeditorial-galleryiguration
 * Automatically detects the complete base URL including:
 * - Protocol (http or https)
 * - Host/domain name
 * - Subdirectory path (if any)
 * - Port (if non-standard)
 *
 * This file should be included before any HTML output
 *
 * MANUAL OVERRIDE: Set BASE_URL_OVERRIDE before including this file
 * to bypass auto-detection:
 * define('BASE_URL_OVERRIDE', 'https://example.com/subdir/');
 */

/**
 * Detect the protocol (http or https)
 *
 * @return string The detected protocol (http:// or https://)
 */
function detectProtocol() {
    // Check various indicators for HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https://';
    }
    
    // Check for request scheme (Apache 2.4+)
    if (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
        return 'https://';
    }
    
    // Check for SSL protocol in frontend HTTP headers
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return 'https://';
    }
    
    // Check for forwarded SSL
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        return 'https://';
    }
    
    // Default to http
    return 'http://';
}

/**
 * Detect the host name including port if non-standard
 *
 * @return string The detected host (e.g., example.com:8080 or example.com)
 */
function detectHost() {
    $host = '';
    
    // Use HTTP_HOST if available (includes port)
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = $_SERVER['SERVER_NAME'];
        // Add port if non-standard
        $port = detectPort();
        if ($port && $port !== '80' && $port !== '443') {
            $host .= ':' . $port;
        }
    }
    
    return $host;
}

/**
 * Detect the server port
 *
 * @return string|null The port number or null if standard
 */
function detectPort() {
    if (!empty($_SERVER['SERVER_PORT'])) {
        return $_SERVER['SERVER_PORT'];
    }
    return null;
}

/**
 * Detect the base path (subdirectory if any)
 *
 * @return string The base path (e.g., /subdir or empty string for root)
 */
function detectBasePath() {
    // Get the script name
    $scriptName = $_SERVER['SCRIPT_NAME'];

    // Define known subdirectories that should NOT be part of the base path
    $knownSubdirs = ['admin', 'api', 'includes', 'css', 'js', 'images', 'data'];

    // Split the path into segments
    $segments = explode('/', trim($scriptName, '/'));

    // Remove PHP file segments (e.g., index.php, booking.php)
    // This ensures we only keep actual directory segments
    while (!empty($segments)) {
        $lastSegment = end($segments);
        // Remove .php files OR explicitly index.php if it appears (though it should end in .php)
        if (stripos($lastSegment, '.php') !== false) {
            array_pop($segments);
        } else {
            break;
        }
    }

    // Remove known application subdirectories from the end after the file name is gone.
    // /admin/pos.php and /api/foo.php should both resolve to the project root.
    while (!empty($segments)) {
        $lastSegment = end($segments);
        if (in_array($lastSegment, $knownSubdirs, true)) {
            array_pop($segments);
        } else {
            break;
        }
    }

    // Double check that we don't have index.php as the last segment (common issue)
    if (!empty($segments) && end($segments) === 'index.php') {
        array_pop($segments);
    }

    // If no segments left, we're in root
    if (empty($segments)) {
        return '';
    }

    // Reconstruct the base path
    $basePath = '/' . implode('/', $segments);

    // Remove trailing slash if present
    return rtrim($basePath, '/');
}

/**
 * Auto-detect the complete base URL
 *
 * @return string The complete base URL with trailing slash
 */
function detectBaseUrl() {
    $protocol = detectProtocol();
    $host = detectHost();
    $path = detectBasePath();
    
    // Build the base URL
    $baseUrl = $protocol . $host;
    
    // Add path if not empty
    if (!empty($path)) {
        $baseUrl .= $path;
    }
    
    // Ensure trailing slash
    $baseUrl = rtrim($baseUrl, '/') . '/';
    
    return $baseUrl;
}

/**
 * Get the base path from the current script location
 * This handles installations in subdirectories like /rosalyns/
 *
 * @return string The base path without leading/trailing slashes
 */
function getBasePath() {
    return detectBasePath();
}

// Define BASE_URL constant
if (!defined('BASE_URL')) {
    // Check for manual override first
    if (defined('BASE_URL_OVERRIDE')) {
        define('BASE_URL', constant('BASE_URL_OVERRIDE'));
    } else {
        // Auto-detect the base URL
        define('BASE_URL', detectBaseUrl());
    }
}

// Define base path constant (backward compatibility)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', getBasePath());
}

/**
 * Helper function to generate full URLs
 *
 * @param string $path The path to append to the base URL
 * @return string The complete URL
 */
function siteUrl($path = '') {
    $baseUrl = BASE_URL;
    $path = ltrim($path, '/');
    
    if (empty($path)) {
        return rtrim($baseUrl, '/');
    }
    
    return rtrim($baseUrl, '/') . '/' . $path;
}

/**
 * Helper function to generate asset URLs
 *
 * @param string $path The asset path
 * @return string The complete asset URL
 */
function assetUrl($path) {
    return siteUrl($path);
}

/**
 * Helper function to get the current URL
 *
 * @return string The current page URL
 */
function currentUrl() {
    $protocol = detectProtocol();
    $host = detectHost();
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    
    return $protocol . $host . $uri;
}
?>
