<?php

/**
 * Enhanced Cache Management System
 * Supports instant clearing, disabling, and automatic invalidation
 */

// Cache directory configuration
// Use dirname(__DIR__) to get the parent directory of config/
define('CACHE_DIR', dirname(__DIR__) . '/cache');
if (!defined('IMAGE_CACHE_DIR')) {
    define('IMAGE_CACHE_DIR', dirname(__DIR__) . '/data/image-cache'); // tests may point it at a scratch folder first
}

// Global cache enable/disable flag
define('CACHE_ENABLED', true); // This can be overridden by database setting

/**
 * Check if caching is globally enabled
 */
function isCacheEnabled($type = null)
{
    try {
        global $pdo;
        if (!$pdo) {
            return CACHE_ENABLED;
        }

        // The on/off switches are read once per request (one query, not two per cache read, which
        // cost more than the cache saved) and re-read if the connection object changes.
        $memo = $GLOBALS['_RH_CACHE_FLAGS'] ?? null;
        if (!is_array($memo) || ($memo['pdo'] ?? null) !== spl_object_id($pdo)) {
            $flags = [];
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cache%enabled'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $flags[$row['setting_key']] = (string)$row['setting_value'];
            }
            $memo = ['pdo' => spl_object_id($pdo), 'flags' => $flags];
            $GLOBALS['_RH_CACHE_FLAGS'] = $memo;
        }

        if (($memo['flags']['cache_global_enabled'] ?? '1') === '0') {
            return false;
        }

        // Check specific cache type
        if ($type && ($memo['flags']["cache_{$type}_enabled"] ?? '1') === '0') {
            return false;
        }
    } catch (Throwable $e) {
        // If database query fails, default to enabled
        error_log("Cache enable check failed: " . $e->getMessage());
    }

    return CACHE_ENABLED;
}

/**
 * Forget the per-request copy of the cache on/off switches (call after changing them).
 */
function rhCacheFlagsReset(): void
{
    unset($GLOBALS['_RH_CACHE_FLAGS']);
}

/**
 * Which per-type switch (Cache Management) governs a cache key. Callers rarely pass a type, so the
 * key decides: an Email/Rooms/Tables/Content switch now really stops that group from being cached.
 * Unrecognised keys stay under 'settings' as before.
 */
function cacheTypeForKey(string $key, string $type = 'settings'): string
{
    if ($type !== 'settings') {
        return $type;
    }
    static $prefixes = [
        'email_' => 'email', 'booking_email_template_' => 'email',
        'rooms_' => 'rooms', 'room_' => 'rooms', 'facilities_' => 'rooms', 'gallery_images' => 'rooms',
        'page_hero' => 'rooms', 'hero_' => 'rooms',
        'table_' => 'tables',
        'testimonials_' => 'content', 'policies' => 'content', 'about_us' => 'content',
    ];
    foreach ($prefixes as $prefix => $t) {
        if (strpos($key, $prefix) === 0) {
            return $t;
        }
    }
    return 'settings';
}

/**
 * Image Cache switch for includes/image-proxy.php, which must not start the whole site (database,
 * maintenance mode, booking housekeeping) just to serve a picture. So the switch is mirrored as a
 * flag file: present = the Image Cache or the global cache switch is off. It lives next to, not
 * inside, data/image-cache so "Clear image cache" never removes it. Missing file = cache on.
 */
define('IMAGE_CACHE_OFF_FLAG', dirname(__DIR__) . '/data/image-cache-off.flag');

/**
 * Where includes/image-proxy.php may keep (and read) a proxied image, or null while the off flag
 * exists, in which case the proxy neither serves from nor writes to data/image-cache.
 * Same file name as before (md5 of the source URL). No database access.
 */
function imageProxyCacheFile(string $url): ?string
{
    if (is_file(IMAGE_CACHE_OFF_FLAG)) {
        return null;
    }
    return IMAGE_CACHE_DIR . '/' . md5($url) . '.jpg';
}

/**
 * Bring the off flag in line with the saved switches (cache_images_enabled / cache_global_enabled).
 * Called when Cache Management saves either switch and whenever its page loads, so a switch already
 * saved in the database takes effect without re-saving. Returns true when the image cache is on.
 * If the flag cannot be written the cache stays on (fail open) and the problem is logged.
 */
function rhSyncImageCacheFlag(): bool
{
    rhCacheFlagsReset();
    $on = isCacheEnabled('images');
    // isCacheEnabled() fails open when the switches cannot be read; do not act on a guess, so a
    // database hiccup never removes an existing off flag.
    if (!isset($GLOBALS['_RH_CACHE_FLAGS']['flags'])) {
        return !is_file(IMAGE_CACHE_OFF_FLAG);
    }
    $has = is_file(IMAGE_CACHE_OFF_FLAG);
    if (!$on && !$has) {
        if (!is_dir(dirname(IMAGE_CACHE_OFF_FLAG))) {
            @mkdir(dirname(IMAGE_CACHE_OFF_FLAG), 0755, true);
        }
        if (@file_put_contents(IMAGE_CACHE_OFF_FLAG, "off\n") === false) {
            error_log('Image cache off flag could not be written: ' . IMAGE_CACHE_OFF_FLAG);
        }
    } elseif ($on && $has) {
        if (!@unlink(IMAGE_CACHE_OFF_FLAG)) {
            error_log('Image cache off flag could not be removed: ' . IMAGE_CACHE_OFF_FLAG);
        }
    }
    return $on;
}

/**
 * Cache files are JSON and can hold decrypted settings (e.g. SMTP credentials), so the folder must
 * never be web-served. The root .htaccess denies /cache/; this also drops a deny-all .htaccess in
 * the folder itself if it is missing (cache/ is not in git, so it cannot ship with the code).
 */
function ensureCacheDirProtected(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $ht = CACHE_DIR . '/.htaccess';
    if (is_dir(CACHE_DIR) && !file_exists($ht)) {
        @file_put_contents($ht, "# Cache files may contain credentials: never serve them.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
}

/**
 * Get cached value with readable filename
 * Respects cache enable/disable settings
 */
function getCache(string $key, mixed $default = null, string $type = 'settings'): mixed
{
    try {
        // Check if caching is enabled for this type
        // (the key decides which per-type switch applies)
        if (!isCacheEnabled(cacheTypeForKey($key, $type))) {
            return $default;
        }

        // Ensure cache directory exists
        if (!file_exists(CACHE_DIR)) {
            return $default;
        }

        // Generate readable cache filename with prefix
        $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);

        if (!file_exists($cacheFile)) {
            return $default;
        }

        $data = @file_get_contents($cacheFile);
        if ($data === false) {
            return $default;
        }

        $cache = json_decode($data, true);
        if (!$cache || !isset($cache['data']) || !isset($cache['expiry'])) {
            return $default;
        }

        // Check if expired
        if (time() > $cache['expiry']) {
            @unlink($cacheFile);
            return $default;
        }

        return $cache['data'];
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Generate a human-readable cache filename with prefix
 */
function getReadableCacheFilename(string $key): string
{
    // Sanitize key to be filesystem-safe
    $sanitized = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $key), 0, 150); // keep the file name under OS limits
    // Generate short hash for uniqueness
    $shortHash = substr(md5($key), 0, 8);
    return "{$sanitized}_{$shortHash}.cache";
}

/**
 * Set cached value with readable filename
 * Respects cache enable/disable settings
 */
function setCache(string $key, mixed $value, int $ttl = 3600, string $type = 'settings'): bool
{
    // Don't cache if disabled
    if (!isCacheEnabled(cacheTypeForKey($key, $type))) {
        return false;
    }

    // Create cache directory if it doesn't exist
    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    ensureCacheDirProtected();

    $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);

    $cacheData = [
        'key' => $key,
        'data' => $value,
        'created' => time(),
        'expiry' => time() + $ttl,
        'ttl' => $ttl
    ];

    $data = json_encode($cacheData);
    if ($data === false) {
        return false; // value cannot be stored as JSON: never write an empty file
    }
    return @file_put_contents($cacheFile, $data, LOCK_EX) !== false;
}

/**
 * Delete cached value with readable filename
 */
function deleteCache(string $key): bool
{
    $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);
    if (file_exists($cacheFile)) {
        return @unlink($cacheFile);
    }
    return false;
}

/**
 * Recursively delete a directory and its contents
 */
function recursiveDelete(string $dir): bool
{
    if (!file_exists($dir)) {
        return true;
    }

    // A link is removed, never followed into whatever it points at.
    if (is_link($dir)) {
        return @unlink($dir) || @rmdir($dir);
    }

    if (!is_dir($dir)) {
        return @unlink($dir);
    }

    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        recursiveDelete("$dir/$file");
    }

    return @rmdir($dir);
}

/**
 * Count regular files under a directory (any depth), ignoring dotfiles such as .htaccess.
 */
function countCacheFiles(string $dir): int
{
    if (!is_dir($dir) || is_link($dir)) {
        return 0;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return 0;
    }
    $count = 0;
    foreach ($items as $item) {
        if ($item[0] === '.') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            $count += countCacheFiles($path);
        } elseif (is_file($path)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Clear directory contents (keep the directory itself and any dotfile such as the
 * deny-all .htaccess). Returns how many files were removed.
 */
function emptyDirectory(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $count = 0;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        if ($file[0] === '.') {
            continue;
        }
        $path = "$dir/$file";
        if (is_dir($path) && !is_link($path)) {
            $inside = countCacheFiles($path);
            if (recursiveDelete($path)) {
                $count += $inside;
            }
        } else {
            if (@unlink($path)) {
                $count++;
            }
        }
    }
    return $count;
}

/**
 * Clear all cache files instantly
 * Uses recursive deletion to ensure everything is gone
 */
function clearCache()
{
    $cleared = 0;

    // Clear main cache directory
    if (is_dir(CACHE_DIR)) {
        $cleared += emptyDirectory(CACHE_DIR);
    }

    // Also clear image cache
    $cleared += clearImageCache();

    // Clear in-memory cache
    global $_SITE_SETTINGS;
    if (isset($_SITE_SETTINGS)) {
        $_SITE_SETTINGS = [];
    }

    return $cleared;
}

/**
 * Clear image cache
 */
function clearImageCache()
{
    if (!is_dir(IMAGE_CACHE_DIR)) {
        return 0;
    }
    return emptyDirectory(IMAGE_CACHE_DIR);
}

/**
 * Delete a specific proxied image from the image cache by its source URL
 * The image proxy stores files as md5(url).jpg in IMAGE_CACHE_DIR
 */
function deleteImageProxyCacheByUrl(string $url): bool
{
    if (empty($url)) return false;
    $cacheFile = IMAGE_CACHE_DIR . '/' . md5($url) . '.jpg';
    if (file_exists($cacheFile)) {
        return @unlink($cacheFile);
    }
    return false;
}

// --- Extensions for SEO/Favicon purge integration (idempotent) ---
if (!function_exists('purge_all_page_caches')) {
    /**
     * Purge all page caches used by the page-cache system.
     * Attempts to use clearPageCache() from config/page-cache.php, otherwise
     * falls back to deleting files within the default pages dir.
     */
    function purge_all_page_caches()
    {
        // Ensure page-cache config is loaded for constants and helpers
        $pageConfig = __DIR__ . '/page-cache.php';
        if (file_exists($pageConfig)) {
            require_once $pageConfig;
        }

        // Use provided helper if available
        if (function_exists('clearPageCache')) {
            return (bool) clearPageCache();
        }

        // Fallback: try to clear PAGE_CACHE_DIR if constant is defined
        if (defined('PAGE_CACHE_DIR') && is_dir(PAGE_CACHE_DIR)) {
            $files = glob(PAGE_CACHE_DIR . '/*');
            $ok = true;
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file) && !@unlink($file)) {
                        $ok = false;
                    }
                    if (is_dir($file)) {
                        // Defensive: remove nested directories if any
                        recursiveDelete($file);
                    }
                }
            }
            return $ok;
        }

        // Last resort: attempt default location used in config/page-cache.php
        $defaultDir = dirname(__DIR__) . '/cache/pages';
        if (is_dir($defaultDir)) {
            $files = glob($defaultDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) @unlink($file);
                    if (is_dir($file)) recursiveDelete($file);
                }
            }
            return true;
        }

        // Nothing to clear
        return false;
    }
}

if (!function_exists('purge_proxied_image_for_url')) {
    /**
     * Remove cached proxied image file(s) corresponding to an absolute URL.
     * Uses IMAGE_CACHE_DIR and md5 hash file mapping, falls back gracefully.
     */
    function purge_proxied_image_for_url(string $absoluteUrl): bool
    {
        if (empty($absoluteUrl)) return false;

        // Prefer existing helper if present
        if (function_exists('deleteImageProxyCacheByUrl')) {
            return (bool) deleteImageProxyCacheByUrl($absoluteUrl);
        }

        // Manual fallback mirroring includes/image-proxy.php
        $dir = defined('IMAGE_CACHE_DIR') ? IMAGE_CACHE_DIR : (dirname(__DIR__) . '/data/image-cache');
        if (!is_dir($dir)) return false;
        $target = $dir . '/' . md5($absoluteUrl) . '.jpg';
        if (file_exists($target)) {
            return @unlink($target);
        }
        return false;
    }
}

if (!function_exists('bump_seo_asset_version')) {
    /**
     * Bump version string used on favicon/SEO assets, to force cache refresh.
     * Tries to use bumpSeoAssetVersion() if available; otherwise attempts a
     * direct DB upsert via $pdo. Returns the new version string on success
     * or false on failure.
     */
    function bump_seo_asset_version()
    {
        // Prefer the existing implementation if defined
        if (function_exists('bumpSeoAssetVersion')) {
            return bumpSeoAssetVersion();
        }

        $newVersion = (string) time();
        try {
            if (!function_exists('setSetting')) {
                /**
                 * Set a site setting in the database.
                 * @param string $key
                 * @param string $value
                 * @return bool
                 */
                function setSetting($key, $value)
                {
                    global $pdo;
                    if (!$pdo) return false;
                    try {
                        $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                        return $stmt->execute([$key, $value]);
                    } catch (Exception $e) {
                        error_log('setSetting failed: ' . $e->getMessage());
                        return false;
                    }
                }
            }
            // If a higher-level setter exists, use it
            $ok = setSetting('seo_asset_version', $newVersion);
            return $ok ? $newVersion : false;

            // Fallback: write directly via PDO if available
            global $pdo;
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('seo_asset_version', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                $stmt->execute([$newVersion]);
                // Invalidate any cached setting variant
                if (function_exists('deleteCache')) {
                    @deleteCache('setting_seo_asset_version');
                }
                return $newVersion;
            }
        } catch (Exception $e) {
            error_log('bump_seo_asset_version fallback failed: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Bump the SEO asset version setting so browsers request fresh favicons/meta-related assets
 * Returns the new version string.
 */
function bumpSeoAssetVersion($newVersion = null)
{
    $version = $newVersion ?? (string) time();
    try {
        global $pdo;
        if ($pdo) {
            $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('seo_asset_version', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute([$version]);
        }
    } catch (Exception $e) {
        error_log('bumpSeoAssetVersion failed: ' . $e->getMessage());
    }
    // Invalidate cached setting
    deleteCache('setting_seo_asset_version');
    return $version;
}

/**
 * Purge SEO & Favicon related caches in one call
 * - Clears all page HTML cache (to refresh meta tags)
 * - Deletes proxied logo cache if present
 * - Bumps SEO asset version
 */
function purgeSeoAndFaviconCaches($logoAbsoluteUrl = null)
{
    $result = [
        'page_cache_cleared' => false,
        'proxied_logo_deleted' => false,
        'new_version' => null,
    ];

    // Clear all page caches if page cache functions are available
    if (function_exists('clearPageCache')) {
        $result['page_cache_cleared'] = clearPageCache();
    }

    // Remove proxied logo cache if provided
    if (!empty($logoAbsoluteUrl)) {
        $result['proxied_logo_deleted'] = deleteImageProxyCacheByUrl($logoAbsoluteUrl);
    }

    // Bump version for asset URLs used in meta
    $result['new_version'] = bumpSeoAssetVersion();

    // Also clear relevant setting caches so templates see fresh values
    clearCacheByPattern('setting_site_*');
    clearCacheByPattern('setting_default_*');

    return $result;
}

/**
 * List all cache files with their details
 */
function listCache()
{
    if (!is_dir(CACHE_DIR)) {
        return [];
    }

    $files = glob(CACHE_DIR . '/*.cache');
    $caches = [];

    if ($files) {
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = json_decode($data, true);
                if ($cache) {
                    $caches[] = [
                        'file' => basename($file),
                        'key' => $cache['key'] ?? 'unknown',
                        'size' => filesize($file),
                        'size_formatted' => formatBytes(filesize($file)),
                        'created' => $cache['created'] ?? null,
                        'created_formatted' => $cache['created'] ? date('Y-m-d H:i:s', $cache['created']) : 'unknown',
                        'expires' => $cache['expiry'] ?? null,
                        'expires_formatted' => $cache['expiry'] ? date('Y-m-d H:i:s', $cache['expiry']) : 'unknown',
                        'expired' => ($cache['expiry'] ?? 0) < time(),
                        'ttl' => ($cache['expiry'] ?? time()) - time()
                    ];
                }
            }
        }
    }

    // Sort by file name for easier reading
    usort($caches, function ($a, $b) {
        return strcmp($a['file'], $b['file']);
    });

    return $caches;
}

/**
 * List all cache files from all cache directories (main, image, page)
 * Returns a unified array with source directory indicator
 */
function listAllCache()
{
    $allCaches = [];

    // Process main cache directory
    if (is_dir(CACHE_DIR)) {
        $files = glob(CACHE_DIR . '/*.cache');
        if ($files) {
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data) {
                    $cache = @json_decode($data, true);
                    if ($cache) {
                        $allCaches[] = [
                            'file' => basename($file),
                            'key' => $cache['key'] ?? 'unknown',
                            'size' => filesize($file),
                            'size_formatted' => formatBytes(filesize($file)),
                            'created' => $cache['created'] ?? null,
                            'created_formatted' => $cache['created'] ? date('Y-m-d H:i:s', $cache['created']) : 'unknown',
                            'expires' => $cache['expiry'] ?? null,
                            'expires_formatted' => $cache['expiry'] ? date('Y-m-d H:i:s', $cache['expiry']) : 'unknown',
                            'expired' => ($cache['expiry'] ?? 0) < time(),
                            'ttl' => ($cache['expiry'] ?? time()) - time(),
                            'source' => 'main',
                            'source_label' => 'Main Cache'
                        ];
                    }
                }
            }
        }
    }

    // Process image cache directory
    if (is_dir(IMAGE_CACHE_DIR)) {
        $items = @scandir(IMAGE_CACHE_DIR);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = IMAGE_CACHE_DIR . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) {
                    $created = filemtime($path);
                    $allCaches[] = [
                        'file' => $item,
                        'key' => 'image/' . $item,
                        'size' => filesize($path),
                        'size_formatted' => formatBytes(filesize($path)),
                        'created' => $created,
                        'created_formatted' => date('Y-m-d H:i:s', $created),
                        'expires' => $created + 7 * 86400,
                        'expires_formatted' => date('Y-m-d H:i:s', $created + 7 * 86400),
                        'expired' => (time() - $created) > 7 * 86400,
                        'ttl' => ($created + 7 * 86400) - time(),
                        'source' => 'image',
                        'source_label' => 'Image Cache'
                    ];
                }
            }
        }
    }

    // Process page cache directory
    if (is_dir(PAGE_CACHE_DIR)) {
        $files = glob(PAGE_CACHE_DIR . '/*.html');
        if ($files) {
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data) {
                    $cache = @json_decode($data, true);
                    $created = $cache['created'] ?? filemtime($file);
                    $expiry = $cache['expiry'] ?? null;
                    $allCaches[] = [
                        'file' => basename($file),
                        'key' => 'page/' . basename($file, '.html'),
                        'size' => filesize($file),
                        'size_formatted' => formatBytes(filesize($file)),
                        'created' => $created,
                        'created_formatted' => date('Y-m-d H:i:s', $created),
                        'expires' => $expiry,
                        'expires_formatted' => $expiry ? date('Y-m-d H:i:s', $expiry) : 'N/A',
                        'expired' => $expiry ? ($expiry < time()) : false,
                        'ttl' => $expiry ? ($expiry - time()) : null,
                        'source' => 'page',
                        'source_label' => 'Page Cache'
                    ];
                }
            }
        }
    }

    // Sort by source, then by file name for easier reading
    usort($allCaches, function ($a, $b) {
        $sourceCompare = strcmp($a['source'], $b['source']);
        if ($sourceCompare !== 0) {
            return $sourceCompare;
        }
        return strcmp($a['file'], $b['file']);
    });

    return $allCaches;
}

/**
 * Clear cache by key pattern (supports wildcards)
 */
function clearCacheByPattern(string $pattern): int
{
    if (!is_dir(CACHE_DIR)) {
        return 0;
    }

    $files = glob(CACHE_DIR . '/*.cache');
    $cleared = 0;

    if ($files) {
        // Convert pattern to regex
        $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';

        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = json_decode($data, true);
                if ($cache && isset($cache['key'])) {
                    if (preg_match($regex, $cache['key'])) {
                        if (@unlink($file)) {
                            $cleared++;
                        }
                    }
                }
            }
        }
    }

    return $cleared;
}

/**
 * Get cache statistics (comprehensive - includes all cache directories)
 */
function getCacheStats()
{
    // Ensure cache directories exist
    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    if (!file_exists(IMAGE_CACHE_DIR)) {
        @mkdir(IMAGE_CACHE_DIR, 0755, true);
    }
    if (!file_exists(PAGE_CACHE_DIR)) {
        @mkdir(PAGE_CACHE_DIR, 0755, true);
    }
    ensureCacheDirProtected();

    $stats = [
        'total_files' => 0,
        'active_files' => 0,
        'expired_files' => 0,
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'oldest_file' => null,
        'newest_file' => null,
        'caches' => [],
        // Breakdown by cache type
        'main_cache' => [
            'files' => 0,
            'size' => 0,
            'size_formatted' => '0 B'
        ],
        'image_cache' => [
            'files' => 0,
            'size' => 0,
            'size_formatted' => '0 B'
        ],
        'page_cache' => [
            'files' => 0,
            'size' => 0,
            'size_formatted' => '0 B'
        ]
    ];

    $now = time();
    $oldest = PHP_INT_MAX;
    $newest = 0;

    // Process main cache directory
    $files = @glob(CACHE_DIR . '/*.cache');
    if ($files === false) {
        $files = [];
    }

    if ($files) {
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = @json_decode($data, true);
                if ($cache) {
                    $stats['total_files']++;
                    $stats['main_cache']['files']++;
                    $fileSize = filesize($file);
                    $stats['total_size'] += $fileSize;
                    $stats['main_cache']['size'] += $fileSize;

                    $created = $cache['created'] ?? 0;
                    if ($created < $oldest) {
                        $oldest = $created;
                        $stats['oldest_file'] = $cache['key'];
                    }
                    if ($created > $newest) {
                        $newest = $created;
                        $stats['newest_file'] = $cache['key'];
                    }

                    if (($cache['expiry'] ?? 0) < $now) {
                        $stats['expired_files']++;
                    } else {
                        $stats['active_files']++;
                    }
                }
            }
        }
    }

    // Process image cache directory
    $imageFiles = @scandir(IMAGE_CACHE_DIR);
    if ($imageFiles !== false) {
        foreach ($imageFiles as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = IMAGE_CACHE_DIR . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                $stats['total_files']++;
                $stats['image_cache']['files']++;
                $fileSize = filesize($path);
                $stats['total_size'] += $fileSize;
                $stats['image_cache']['size'] += $fileSize;

                $created = filemtime($path);
                // Proxied images are served for 7 days (includes/image-proxy.php), then re-fetched.
                if (($now - $created) > 7 * 86400) {
                    $stats['expired_files']++;
                } else {
                    $stats['active_files']++;
                }
                if ($created < $oldest) {
                    $oldest = $created;
                    $stats['oldest_file'] = 'image/' . $file;
                }
                if ($created > $newest) {
                    $newest = $created;
                    $stats['newest_file'] = 'image/' . $file;
                }
            }
        }
    }

    // Process page cache directory
    $pageFiles = @glob(PAGE_CACHE_DIR . '/*.html');
    if ($pageFiles === false) {
        $pageFiles = [];
    }

    if ($pageFiles) {
        foreach ($pageFiles as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = @json_decode($data, true);
                $stats['total_files']++;
                $stats['page_cache']['files']++;
                $fileSize = filesize($file);
                $stats['total_size'] += $fileSize;
                $stats['page_cache']['size'] += $fileSize;

                $created = $cache['created'] ?? filemtime($file);
                if ($created < $oldest) {
                    $oldest = $created;
                    $stats['oldest_file'] = 'page/' . basename($file, '.html');
                }
                if ($created > $newest) {
                    $newest = $created;
                    $stats['newest_file'] = 'page/' . basename($file, '.html');
                }

                if (($cache['expiry'] ?? 0) < $now) {
                    $stats['expired_files']++;
                } else {
                    $stats['active_files']++;
                }
            }
        }
    }

    // Format sizes
    $stats['total_size_formatted'] = formatBytes($stats['total_size']);
    $stats['main_cache']['size_formatted'] = formatBytes($stats['main_cache']['size']);
    $stats['image_cache']['size_formatted'] = formatBytes($stats['image_cache']['size']);
    $stats['page_cache']['size_formatted'] = formatBytes($stats['page_cache']['size']);

    return $stats;
}

/**
 * Format bytes to human-readable format
 */
function formatBytes(int|float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Clear specific cache by exact key
 * Alias for deleteCache() for consistency
 */
function clearSpecificCache(string $key): bool
{
    return deleteCache($key);
}

/**
 * Clear all room-related cache instantly
 * Call this when rooms, prices, or images are updated
 */
function clearRoomCache()
{
    // Clear all room-related caches
    $patterns = [
        'rooms_*',
        'table_rooms_*',
        'room_*',
        'facilities_*',
        'gallery_images',
        'page_hero*'
    ];

    $total = 0;
    foreach ($patterns as $pattern) {
        $total += clearCacheByPattern($pattern);
    }

    // Also clear image cache
    $total += clearImageCache();

    // Clear in-memory cache
    global $_SITE_SETTINGS;
    if (isset($_SITE_SETTINGS)) {
        unset($_SITE_SETTINGS['rooms']);
    }

    return $total;
}

/**
 * Clear all settings cache instantly
 * Call this when site settings are updated
 */
function clearSettingsCache()
{
    $cleared = clearCacheByPattern('setting_*');
    // Also clear settings-group grouped caches (different key prefix: settings_group_*)
    $cleared += clearCacheByPattern('settings_group_*');
    // And the in-memory copy getSetting() keeps for the rest of this request, or the old value
    // would still be returned right after a save.
    global $_SITE_SETTINGS;
    $_SITE_SETTINGS = [];
    return $cleared;
}

/**
 * Clear all email cache instantly
 * Call this when email settings are updated
 */
function clearEmailCache()
{
    $cleared = clearCacheByPattern('email_*');
    // Booking email templates also live under a different prefix
    $cleared += clearCacheByPattern('booking_email_template_*');
    // Table-existence flags so stale schema checks are re-run
    deleteCache('table_email_settings');
    deleteCache('table_booking_email_templates');
    return $cleared;
}

/**
 * Clear content caches — testimonials, policies, about-us
 * Call this when any public-facing content is updated
 */
function clearContentCache()
{
    $cleared = clearCacheByPattern('testimonials_*');
    $cleared += clearCacheByPattern('policies');
    $cleared += clearCacheByPattern('about_us');
    return $cleared;
}

/**
 * Force cache refresh by clearing and immediately rebuilding
 * Useful for ensuring data is fresh
 */
function forceCacheRefresh(string $key, callable $callback, int $ttl = 3600, string $type = 'settings'): mixed
{
    deleteCache($key);
    $data = $callback();
    if ($data !== null) {
        setCache($key, $data, $ttl, $type);
    }
    return $data;
}
