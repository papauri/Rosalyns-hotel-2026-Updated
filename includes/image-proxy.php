<?php
/**
 * Image Proxy - Bypass hotlink protection for external images (Facebook, etc.)
 *
 * Usage: <img src="/includes/image-proxy.php?url=[encoded_url]" />
 */

// Production error handling - log critical errors only
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Get the image URL from query parameter
if (!isset($_GET['url'])) {
    error_log("Image Proxy Error: Missing URL parameter");
    header('HTTP/1.0 400 Bad Request');
    exit('Missing URL parameter');
}

$imageUrl = $_GET['url'];

// Security: Validate URL format
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    error_log("Image Proxy Error: Invalid URL format - " . substr($imageUrl, 0, 100));
    header('HTTP/1.0 400 Bad Request');
    exit('Invalid URL');
}

// Security: only http(s) on standard ports, and only hosts that ARE (or are subdomains of)
// the allowed domains. Substring matching would allow e.g. facebook.com.evil.test (SSRF).
$urlParts  = parse_url($imageUrl);
$urlScheme = strtolower((string)($urlParts['scheme'] ?? ''));
$urlHost   = strtolower((string)($urlParts['host'] ?? ''));
$urlPort   = $urlParts['port'] ?? null;
if (!in_array($urlScheme, ['http', 'https'], true) || $urlHost === ''
    || isset($urlParts['user']) || isset($urlParts['pass'])
    || ($urlPort !== null && !in_array((int)$urlPort, [80, 443], true))) {
    header('HTTP/1.0 400 Bad Request');
    exit('Invalid URL');
}

$allowed = false;
$proxyDomains = ['fbcdn.net', 'facebook.com', 'instagram.com', 'fb.com', 'fbsbx.com'];
foreach ($proxyDomains as $domain) {
    if ($urlHost === $domain || substr($urlHost, -strlen($domain) - 1) === '.' . $domain) {
        $allowed = true;
        break;
    }
}

// Not a proxied domain: refuse (never act as an open redirect / open fetcher).
if (!$allowed) {
    header('HTTP/1.0 403 Forbidden');
    exit('Host not allowed');
}

// Cache directory - use absolute path
$cacheDir = dirname(__DIR__) . '/data/image-cache/';

// Create cache directory if it doesn't exist
if (!file_exists($cacheDir)) {
    if (!mkdir($cacheDir, 0755, true)) {
        error_log("Image Proxy Error: Failed to create cache directory: " . $cacheDir);
        header('HTTP/1.0 500 Internal Server Error');
        exit('Cannot create cache directory');
    }
}

// Check if cache directory is writable
if (!is_writable($cacheDir)) {
    error_log("Image Proxy Error: Cache directory not writable: " . $cacheDir);
    header('HTTP/1.0 500 Internal Server Error');
    exit('Cache directory not writable');
}

// Generate cache filename from URL hash
$cacheFile = $cacheDir . md5($imageUrl) . '.jpg';

// Check if cached file exists and is fresh (7 days)
$cacheTime = 7 * 24 * 60 * 60; // 7 days in seconds
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    // Serve cached file
    $imageInfo = @getimagesize($cacheFile);
    if ($imageInfo) {
        header('Content-Type: ' . $imageInfo['mime']);
        header('Content-Length: ' . filesize($cacheFile));
        header('Cache-Control: public, max-age=604800'); // 7 days
        header('X-Image-Cache: HIT');
        readfile($cacheFile);
        exit;
    }
}

// Fetch the image
header('X-Image-Cache: MISS');

$imageData = null;
$contentType = null;
$httpCode = 200;

// Method 1: Try cURL first (faster and better for large files)
if (function_exists('curl_init')) {
    error_log("Image Proxy: Using cURL method");
    $ch = @curl_init($imageUrl);
    
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 10485760,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_REFERER => 'https://www.facebook.com/',
        ]);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Image Proxy: cURL - HTTP Code = " . $httpCode . ", Error = " . ($error ?: 'none'));
        
        if ($error || $httpCode != 200 || empty($imageData)) {
            $imageData = null; // Fall back to file_get_contents
        }
    }
}

// Method 2: Fallback to file_get_contents (works without cURL)
if ($imageData === null) {
    error_log("Image Proxy: Using file_get_contents method");
    
    // Create stream context with proper headers
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://www.facebook.com/',
            ],
            'timeout' => 30,
            'follow_location' => false,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ];
    
    $context = stream_context_create($opts);
    $imageData = @file_get_contents($imageUrl, false, $context);
    
    if ($imageData === false) {
        error_log("Image Proxy: file_get_contents failed");
        $error = error_get_last();
        header('HTTP/1.0 502 Bad Gateway');
        exit('Failed to fetch image: ' . ($error['message'] ?? 'Unknown error'));
    }
    
    error_log("Image Proxy: Image fetched successfully with file_get_contents");
}

// Only ever cache/serve genuine images (never HTML/JS from a spoofed response)
$imgCheck = @getimagesizefromstring((string)$imageData);
if (!$imgCheck || strlen((string)$imageData) > 10485760) {
    header('HTTP/1.0 502 Bad Gateway');
    exit('Not an image');
}
$contentType = $imgCheck['mime'];

// Save to cache
@file_put_contents($cacheFile, $imageData);

// Detect content type if not provided
if (empty($contentType)) {
    $imageInfo = @getimagesizefromstring($imageData);
    if ($imageInfo) {
        $contentType = $imageInfo['mime'];
    } else {
        $contentType = 'image/jpeg';
    }
}

// Serve the image
header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: public, max-age=604800'); // 7 days
header('X-Content-Type-Options: nosniff');

echo $imageData;
