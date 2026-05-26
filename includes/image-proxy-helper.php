<?php
/**
 * Image Proxy Helper Functions
 * 
 * Helper functions to automatically proxy external images that have hotlink protection
 */

/**
 * Check if a URL needs proxying (external domains with hotlink protection)
 * 
 * @param string $url The image URL to check
 * @return bool True if URL needs proxying
 */
function needsImageProxy($url) {
    if (empty($url) || strpos($url, 'http') !== 0) {
        return false;
    }
    
    $host = parse_url($url, PHP_URL_HOST);
    
    // Local images don't need proxy
    if (empty($host) || strpos($host, $_SERVER['HTTP_HOST']) !== false) {
        return false;
    }
    
    // Domains that need proxy (hotlink protection)
    $proxyDomains = [
        'fbcdn.net',
        'facebook.com',
        'instagram.com',
        'fb.com',
        'fbsbx.com',
    ];
    
    foreach ($proxyDomains as $domain) {
        if (strpos($host, $domain) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Convert image URL to use proxy if needed
 * 
 * @param string $url The original image URL
 * @return string The proxied URL or original URL if not needed
 */
function proxyImageUrl($url) {
    if (empty($url)) {
        return $url;
    }
    
    // Check if URL needs proxying
    if (!needsImageProxy($url)) {
        return $url;
    }
    
    // Return proxy URL
    return '/includes/image-proxy.php?url=' . urlencode($url);
}

/**
 * Output an image tag with automatic proxy support
 * 
 * @param string $url The image URL
 * @param string $alt Alt text for the image
 * @param array $attrs Additional HTML attributes
 * @return string HTML img tag
 */
function proxyImageTag($url, $alt = '', $attrs = []) {
    $proxiedUrl = proxyImageUrl($url);
    
    $attrString = '';
    foreach ($attrs as $key => $value) {
        $attrString .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }
    
    return '<img src="' . htmlspecialchars($proxiedUrl) . '" alt="' . htmlspecialchars($alt) . '"' . $attrString . '>';
}

/**
 * Convert CSS background-image URL to use proxy if needed
 * 
 * @param string $url The image URL
 * @return string CSS url() value
 */
function proxyBackgroundUrl($url) {
    if (empty($url)) {
        return $url;
    }
    
    // Remove url() wrapper if present
    $cleanUrl = $url;
    if (preg_match('/^url\([\'"]?(.+?)[\'"]?\)$/i', $url, $matches)) {
        $cleanUrl = $matches[1];
    }
    
    $proxiedUrl = proxyImageUrl($cleanUrl);
    
    return 'url(\'' . $proxiedUrl . '\')';
}
