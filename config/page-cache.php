<?php
/**
 * Page-Level Caching System
 * Caches complete HTML output to dramatically reduce page load times
 */

require_once __DIR__ . '/cache.php';

// Page cache configuration
define('PAGE_CACHE_DIR', __DIR__ . '/../cache/pages');
define('PAGE_CACHE_ENABLED', true);
define('PAGE_CACHE_DEFAULT_TTL', 300); // 5 minutes default TTL

/**
 * Check page-cache toggle from the cache management settings.
 */
function isPageCacheEnabled() {
    return PAGE_CACHE_ENABLED && (!function_exists('isCacheEnabled') || isCacheEnabled('pages'));
}

/**
 * Start page output buffering for caching
 */
function startPageCache(string $key, int $ttl = PAGE_CACHE_DEFAULT_TTL) {
    if (!isPageCacheEnabled()) {
        return false;
    }
    
    // Check for existing cached page
    $cachedContent = getPageCache($key);
    if ($cachedContent !== null) {
        // Serve cached page immediately
        echo $cachedContent;
        exit;
    }
    
    // Start output buffering
    ob_start();
    return true;
}

/**
 * End page output buffering and save to cache
 */
function endPageCache(string $key, int $ttl = PAGE_CACHE_DEFAULT_TTL) {
    if (!isPageCacheEnabled()) {
        ob_end_flush();
        return false;
    }
    
    // Get buffered content
    $content = ob_get_clean();
    
    // Save to cache
    setPageCache($key, $content, $ttl);
    
    // Output content
    echo $content;
    return true;
}

/**
 * Get cached page content
 */
function getPageCache(string $key, $default = null) {
    if (!isPageCacheEnabled()) {
        return $default;
    }

    $cacheFile = PAGE_CACHE_DIR . '/' . md5($key) . '.html';
    
    if (!file_exists($cacheFile)) {
        return $default;
    }
    
    $data = file_get_contents($cacheFile);
    if ($data === false) {
        return $default;
    }
    
    $cache = json_decode($data, true);
    if (!$cache || !isset($cache['content']) || !isset($cache['expiry'])) {
        return $default;
    }
    
    // Check if expired
    if (time() > $cache['expiry']) {
        @unlink($cacheFile);
        return $default;
    }
    
    return $cache['content'];
}

/**
 * Set cached page content
 */
function setPageCache(string $key, string $content, int $ttl = PAGE_CACHE_DEFAULT_TTL) {
    if (!isPageCacheEnabled()) {
        return false;
    }

    // Create cache directory if it doesn't exist
    if (!file_exists(PAGE_CACHE_DIR)) {
        @mkdir(PAGE_CACHE_DIR, 0755, true);
    }
    
    $cacheFile = PAGE_CACHE_DIR . '/' . md5($key) . '.html';
    $cacheData = [
        'content' => $content,
        'expiry' => time() + $ttl,
        'created' => time()
    ];
    
    $data = json_encode($cacheData);
    return file_put_contents($cacheFile, $data, LOCK_EX) !== false;
}

/**
 * Delete cached page
 */
function deletePageCache(string $key) {
    $cacheFile = PAGE_CACHE_DIR . '/' . md5($key) . '.html';
    if (file_exists($cacheFile)) {
        return @unlink($cacheFile);
    }
    return false;
}

/**
 * Clear all page cache
 */
function clearPageCache() {
    if (!is_dir(PAGE_CACHE_DIR)) {
        return 0;
    }

    $files = glob(PAGE_CACHE_DIR . '/*.html');
    $cleared = 0;
    if ($files) {
        foreach ($files as $file) {
            if (@unlink($file)) {
                $cleared++;
            }
        }
    }
    return $cleared;
}

/**
 * Generate cache key for current page
 */
function generatePageCacheKey() {
    $key = $_SERVER['REQUEST_URI'];
    
    // Add query parameters for different variations
    if (!empty($_GET)) {
        $key .= '?' . http_build_query($_GET);
    }
    
    // Add user-specific variations if needed (e.g., logged in users)
    // if (isset($_SESSION['user_id'])) {
    //     $key .= '|user:' . $_SESSION['user_id'];
    // }
    
    return $key;
}

/**
 * Invalidate cache when content changes
 */
function invalidatePageCache() {
    clearPageCache();
    
    // Also clear related caches
    clearCache();
}
?>