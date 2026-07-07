<?php

/**
 * Enhanced Cache Management System
 * Easy cache control with toggles, scheduling, and bulk operations
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to user, log instead
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php-errors.log');

require_once 'admin-init.php';

$csrf_token = $csrf_token ?? generateCsrfToken();

// Set a custom error handler to prevent blank screens
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("Cache Management Error: [$errno] $errstr in $errfile:$errline");
    return true; // Prevent PHP error handler
});

// Set exception handler
set_exception_handler(function ($exception) {
    error_log("Cache Management Exception: " . $exception->getMessage());
    echo "<div style='padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px;'>";
    echo "<strong>Error:</strong> An unexpected error occurred. Please check the error log.";
    echo "</div>";
});

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

$message = '';
$error = '';
$success = false;

$allowedCacheTypes = ['email', 'settings', 'rooms', 'tables', 'images', 'pages', 'content'];
$allowedBulkCacheTypes = array_merge($allowedCacheTypes, ['all']);
$allowedScheduleIntervals = ['30sec', '1min', '5min', '15min', '30min', 'hourly', '6hours', '12hours', 'daily', 'weekly', 'custom'];

// Include alert.php for showAlert function
require_once __DIR__ . '/../includes/alert.php';

// Handle success message from redirect
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        switch ($action) {
            case 'toggle_cache':
                $cache_type = $_POST['cache_type'] ?? '';
                if (!in_array($cache_type, $allowedCacheTypes, true)) {
                    throw new RuntimeException('Invalid cache type selected.');
                }
                $enabled = (isset($_POST['enabled']) && (int)$_POST['enabled'] === 1) ? 1 : 0;

                // Update or insert cache setting
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute(["cache_{$cache_type}_enabled", $enabled, $enabled]);

                $message = "Cache '{$cache_type}' " . ($enabled ? 'enabled' : 'disabled') . " successfully!";
                $success = true;
                rh_log_event('cache_management', 'info', 'Cache type toggled', ['cache_type' => $cache_type, 'enabled' => $enabled]);

                // Redirect to force fresh read of database
                header('Location: cache-management.php?msg=' . urlencode($message));
                exit;
                break;

            case 'clear_cache':
                $cache_types = $_POST['cache_types'] ?? [];
                $cache_types = is_array($cache_types) ? $cache_types : [];
                $cache_types = array_values(array_unique(array_intersect($cache_types, $allowedBulkCacheTypes)));
                if (in_array('all', $cache_types, true)) {
                    // Avoid duplicate counting and repeated clear calls when ALL is selected.
                    $cache_types = ['all'];
                }

                if (empty($cache_types)) {
                    $error = 'Please select at least one cache type to clear.';
                } else {
                    require_once __DIR__ . '/../config/cache.php';
                    require_once __DIR__ . '/../config/page-cache.php';
                    $cleared = 0;
                    $files_cleared = 0;

                    foreach ($cache_types as $type) {
                        switch ($type) {
                            case 'all':
                                $before = count(glob(CACHE_DIR . '/*.cache'));
                                $image_before = countDirectoryFiles(IMAGE_CACHE_DIR);
                                $page_before = countDirectoryFiles(PAGE_CACHE_DIR);
                                clearCache();
                                clearPageCache();
                                $files_cleared += $before + $image_before + $page_before;
                                $cleared++;
                                break;
                            case 'email':
                                $before = count(glob(CACHE_DIR . '/email_*.cache'))
                                    + count(glob(CACHE_DIR . '/email_setting_*.cache'))
                                    + count(glob(CACHE_DIR . '/booking_email_template_*.cache'));
                                clearEmailCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'settings':
                                $before = count(glob(CACHE_DIR . '/setting_*.cache'))
                                    + count(glob(CACHE_DIR . '/settings_group_*.cache'));
                                clearSettingsCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'rooms':
                                $before = count(glob(CACHE_DIR . '/rooms_*.cache'))
                                    + count(glob(CACHE_DIR . '/room_*.cache'))
                                    + count(glob(CACHE_DIR . '/facilities_*.cache'))
                                    + count(glob(CACHE_DIR . '/gallery_*.cache'))
                                    + count(glob(CACHE_DIR . '/hero_*.cache'));
                                $image_before = countDirectoryFiles(IMAGE_CACHE_DIR);
                                clearRoomCache();
                                $files_cleared += $before + $image_before;
                                $cleared++;
                                break;
                            case 'tables':
                                $before = count(glob(CACHE_DIR . '/table_*.cache'));
                                clearCacheByPattern('table_*');
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'images':
                                $before = countDirectoryFiles(IMAGE_CACHE_DIR);
                                clearImageCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'pages':
                                $before = countDirectoryFiles(PAGE_CACHE_DIR);
                                clearPageCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'content':
                                $before = count(glob(CACHE_DIR . '/testimonials_*.cache'))
                                    + count(glob(CACHE_DIR . '/policies_*.cache'))
                                    + count(glob(CACHE_DIR . '/about_us_*.cache'));
                                clearContentCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                        }
                    }

                    $message = "Successfully cleared {$files_cleared} cache files in {$cleared} cache type(s)!";
                    $success = true;
                    rh_log_event('cache_management', 'info', 'Cache cleared', ['cache_types' => $cache_types, 'files_cleared' => $files_cleared]);
                }
                break;

            case 'set_schedule':
                $enabled = isset($_POST['schedule_enabled']) ? 1 : 0;
                $interval = $_POST['schedule_interval'] ?? 'daily';
                $time = $_POST['schedule_time'] ?? '00:00';
                $custom_seconds = isset($_POST['custom_seconds']) ? (int)$_POST['custom_seconds'] : 60;

                if (!in_array($interval, $allowedScheduleIntervals, true)) {
                    $interval = 'daily';
                }
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$time)) {
                    $time = '00:00';
                }

                // Validate custom seconds (minimum 10 seconds, maximum 86400 seconds/24 hours)
                if ($custom_seconds < 10) $custom_seconds = 10;
                if ($custom_seconds > 86400) $custom_seconds = 86400;

                // Update schedule settings
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute(['cache_schedule_enabled', $enabled, $enabled]);
                $stmt->execute(['cache_schedule_interval', $interval, $interval]);
                $stmt->execute(['cache_schedule_time', $time, $time]);
                $stmt->execute(['cache_custom_seconds', $custom_seconds, $custom_seconds]);

                $message = "Cache clearing schedule " . ($enabled ? 'enabled' : 'disabled') . " (" . $interval . ")!";
                $success = true;
                rh_log_event('cache_management', 'info', 'Cache schedule updated', ['enabled' => $enabled, 'interval' => $interval, 'time' => $time, 'custom_seconds' => $custom_seconds]);
                break;

            case 'set_global_cache':
                $enabled = isset($_POST['global_cache_enabled']) ? 1 : 0;

                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES ('cache_global_enabled', ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$enabled, $enabled]);

                $message = "Global caching " . ($enabled ? 'enabled' : 'disabled') . "!";
                $success = true;
                rh_log_event('cache_management', 'info', 'Global cache setting updated', ['enabled' => $enabled]);
                break;

            case 'purge_seo_favicon':
                // Purge SEO & Favicon related caches: page HTML, proxied logo, and bump asset version
                require_once __DIR__ . '/../config/cache.php';
                require_once __DIR__ . '/../config/page-cache.php';

                // Build absolute logo URL similar to includes/seo-meta.php
                $site_url = getSetting('site_url');
                $base_url = $site_url ?: ('https://' . $_SERVER['HTTP_HOST']);
                $site_logo = getSetting('site_logo');
                $logo_abs = '';
                if (!empty($site_logo)) {
                    $logo_abs = (strpos($site_logo, 'http') === 0) ? $site_logo : ($base_url . $site_logo);
                }

                // Use the granular helpers when available; fall back to legacy combined helper
                $pageCleared = false;
                $proxyDeleted = false;
                $newVersion = null;

                if (function_exists('purge_all_page_caches') && function_exists('purge_proxied_image_for_url') && function_exists('bump_seo_asset_version')) {
                    $pageCleared = (bool) purge_all_page_caches();
                    if (!empty($logo_abs)) {
                        $proxyDeleted = (bool) purge_proxied_image_for_url($logo_abs);
                    }
                    $newVersion = bump_seo_asset_version();
                } elseif (function_exists('purgeSeoAndFaviconCaches')) {
                    $res = purgeSeoAndFaviconCaches($logo_abs);
                    $pageCleared = !empty($res['page_cache_cleared']);
                    $proxyDeleted = !empty($res['proxied_logo_deleted']);
                    $newVersion = $res['new_version'] ?? null;
                } else {
                    // Minimal fallback if helpers are unavailable
                    if (function_exists('clearPageCache')) {
                        $pageCleared = (bool) clearPageCache();
                    }
                }

                $message = sprintf(
                    "Purged SEO & Favicon caches. Page HTML cleared: %s. Proxied logo deleted: %s. New asset version: %s.",
                    $pageCleared ? 'yes' : 'no',
                    $proxyDeleted ? 'yes' : 'no',
                    $newVersion ?: 'n/a'
                );
                $success = true;
                rh_log_event('cache_management', 'info', 'SEO and favicon cache purge completed', ['page_cleared' => $pageCleared, 'proxy_deleted' => $proxyDeleted, 'new_version' => $newVersion]);
                break;

            case 'bump_sw':
                // Bump SW_VERSION stamp in both public and admin service workers so
                // all browsers discard their PWA cache on next visit.
                // Include time + nonce so admins can trigger a fresh bump multiple
                // times in the same day when needed.
                $bumpStamp  = date('Y-m-d-His') . '-' . random_int(1000, 9999);
                $swBumped   = [];
                $swMissing  = [];
                $swFiles    = [
                    'Public SW'  => __DIR__ . '/../public-sw.js',
                    'Admin SW'   => __DIR__ . '/sw.js',
                ];
                foreach ($swFiles as $label => $swPath) {
                    if (!file_exists($swPath)) continue;
                    $swContent = file_get_contents($swPath);
                    $newContent = preg_replace(
                        "/const\\s+SW_VERSION\\s*=\\s*'([^']*?)(?:-\\d{4}-\\d{2}-\\d{2}(?:-\\d{6})?(?:-\\d{4})?)?';/",
                        "const SW_VERSION = '\$1-{$bumpStamp}';",
                        $swContent,
                        -1,
                        $swCount
                    );
                    if ($swCount > 0 && is_string($newContent) && $newContent !== $swContent) {
                        if (file_put_contents($swPath, $newContent) !== false) {
                            $swBumped[] = $label;
                        } else {
                            $swMissing[] = $label;
                        }
                    } else {
                        $swMissing[] = $label;
                    }
                }
                if (!empty($swBumped)) {
                    $message = 'Service Worker versions bumped to ' . $bumpStamp . ' (' . implode(', ', $swBumped) . '). All devices will refetch cached assets on next visit.';
                    $success = true;
                } else {
                    $error = 'Could not update SW version strings for: ' . implode(', ', $swMissing) . '.';
                }
                rh_log_event('cache_management', 'info', 'SW versions bumped', ['stamp' => $bumpStamp, 'bumped' => $swBumped, 'failed' => $swMissing]);
                break;

            default:
                $error = 'Unsupported cache action requested.';
                rh_log_event('cache_management', 'warning', 'Unsupported cache action requested', ['action' => $action]);
                break;
        }
    } catch (Throwable $e) {
        $error = 'Cache action failed: ' . $e->getMessage();
        rh_log_event('cache_management', 'error', 'Cache action failed', ['action' => $action, 'error' => $e->getMessage()]);
    }
}

// Get current cache settings
$cache_settings = [];
try {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM site_settings
        WHERE setting_key LIKE 'cache_%' OR setting_key LIKE '%_cache_%'
    ");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($settings as $setting) {
        $cache_settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (PDOException $e) {
    $error = 'Error fetching cache settings: ' . $e->getMessage();
}

// Get cache statistics (with error handling)
require_once __DIR__ . '/../config/cache.php';
require_once __DIR__ . '/../config/page-cache.php';

try {
    $stats = getCacheStats();
} catch (Exception $e) {
    error_log("Cache stats error: " . $e->getMessage());
    // Default stats if cache is disabled or error occurs
    $stats = [
        'total_files' => 0,
        'active_files' => 0,
        'expired_files' => 0,
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'oldest_file' => null,
        'newest_file' => null,
        'caches' => [],
        'main_cache' => ['files' => 0, 'size' => 0, 'size_formatted' => '0 B'],
        'image_cache' => ['files' => 0, 'size' => 0, 'size_formatted' => '0 B'],
        'page_cache' => ['files' => 0, 'size' => 0, 'size_formatted' => '0 B']
    ];
}

try {
    $caches = listAllCache();
} catch (Exception $e) {
    error_log("Cache list error: " . $e->getMessage());
    // Empty cache list if error occurs
    $caches = [];
}

// Read current SW version strings for display
$pwa_public_version = 'unknown';
$pwa_admin_version  = 'unknown';
$pwaPublicPath = __DIR__ . '/../public-sw.js';
$pwaAdminPath  = __DIR__ . '/sw.js';
if (file_exists($pwaPublicPath)) {
    preg_match("/const SW_VERSION = '([^']+)'/", file_get_contents($pwaPublicPath), $pwaM);
    $pwa_public_version = $pwaM[1] ?? 'unknown';
}
if (file_exists($pwaAdminPath)) {
    preg_match("/const SW_VERSION = '([^']+)'/", file_get_contents($pwaAdminPath), $pwaM);
    $pwa_admin_version = $pwaM[1] ?? 'unknown';
}

// Helper function to safely count cache files by pattern
function countCacheByPattern(array $caches, array $patterns)
{
    try {
        $count = 0;
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';
            foreach ($caches as $cache) {
                if (preg_match($regex, $cache['key'])) {
                    $count++;
                }
            }
        }
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

// Helper function to safely count regular files inside a directory
function countDirectoryFiles(string $directory)
{
    if (!is_dir($directory)) {
        return 0;
    }

    $items = @scandir($directory);
    if ($items === false) {
        return 0;
    }

    $count = 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            $count++;
        }
    }

    return $count;
}

// Define cache types - based on actual cache patterns in the system
$cache_types = [
    'email' => [
        'name' => 'Email Settings',
        'icon' => 'fa-envelope',
        'description' => 'Email configuration and SMTP settings',
        'patterns' => ['email_*']
    ],
    'settings' => [
        'name' => 'Site Settings',
        'icon' => 'fa-cog',
        'description' => 'General site settings and configuration',
        'patterns' => ['setting_*']
    ],
    'rooms' => [
        'name' => 'Rooms & Images',
        'icon' => 'fa-bed',
        'description' => 'Room data, prices, facilities, and image cache',
        'patterns' => ['rooms_*', 'room_*', 'facilities_*', 'gallery_*', 'hero_*']
    ],
    'tables' => [
        'name' => 'Database Tables',
        'icon' => 'fa-database',
        'description' => 'Cached database table data',
        'patterns' => ['table_*']
    ],
    'images' => [
        'name' => 'Image Cache',
        'icon' => 'fa-image',
        'description' => 'Cached processed images',
        'patterns' => ['image_*']
    ],
    'pages' => [
        'name' => 'Page HTML Cache',
        'icon' => 'fa-file-code',
        'description' => 'Full-page HTML output cache for frontend pages',
        'patterns' => []
    ],
    'content' => [
        'name' => 'Content & Reviews',
        'icon' => 'fa-star',
        'description' => 'Testimonials, policies, about-us section cache',
        'patterns' => ['testimonials_*', 'policies', 'about_us']
    ]
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Cache Management - Admin Panel</title>

    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="shortcut icon" href="../favicon.ico">
    <link rel="manifest" href="../manifest.php">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">

    <link rel="stylesheet" href="css/cache-management.css?v=<?php echo @filemtime(__DIR__ . '/css/cache-management.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content cache-management-page">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-bolt"></i> Cache Management
            </h2>
            <p class="text-muted">Control data, image, and page-output caching for current frontend sections and assets</p>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Cache Statistics Overview -->
        <div class="cache-overview">
            <div class="cache-stat-card">
                <div class="cache-stat-icon primary">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['total_files']; ?></h3>
                    <p>Total Cache Files</p>
                </div>
            </div>

            <div class="cache-stat-card">
                <div class="cache-stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['active_files']; ?></h3>
                    <p>Active Caches</p>
                </div>
            </div>

            <div class="cache-stat-card">
                <div class="cache-stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['expired_files']; ?></h3>
                    <p>Expired Caches</p>
                </div>
            </div>

            <div class="cache-stat-card">
                <div class="cache-stat-icon danger">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['total_size_formatted']; ?></h3>
                    <p>Total Size</p>
                </div>
            </div>
        </div>

        <!-- Global Cache Control -->
        <div class="cache-section">
            <h2><i class="fas fa-power-off"></i> Global Cache Control</h2>
            <form method="POST" class="cache-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_global_cache">

                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="global_cache_enabled"
                            <?php echo !isset($cache_settings['cache_global_enabled']) || (string)$cache_settings['cache_global_enabled'] !== '0' ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <span class="switch-label">
                        Enable All Caching
                    </span>
                </div>

                <button type="submit" class="btn-action btn-save">
                    <i class="fas fa-save"></i> Save Setting
                </button>
            </form>
        </div>

        <!-- Individual Cache Toggles -->
        <div class="cache-section">
            <h2><i class="fas fa-toggle-on"></i> Individual Cache Controls</h2>
            <div class="cache-toggle-grid">
                <?php foreach ($cache_types as $type => $info): ?>
                    <?php
                    $enabled = isset($cache_settings["cache_{$type}_enabled"])
                        ? (int)$cache_settings["cache_{$type}_enabled"]
                        : 1; // Default enabled
                    ?>
                    <div class="cache-toggle-item <?php echo $enabled ? 'active' : 'inactive'; ?>">
                        <div class="cache-toggle-header">
                            <div class="cache-toggle-name">
                                <i class="fas <?php echo $info['icon']; ?>"></i>
                                <?php echo $info['name']; ?>
                            </div>
                            <span class="cache-toggle-status <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                                <?php echo $enabled ? 'ON' : 'OFF'; ?>
                            </span>
                        </div>
                        <p class="cache-toggle-desc"><?php echo $info['description']; ?></p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="toggle_cache">
                            <input type="hidden" name="cache_type" value="<?php echo $type; ?>">
                            <input type="hidden" name="enabled" value="<?php echo $enabled ? 0 : 1; ?>">
                            <button type="submit" class="cache-toggle-btn <?php echo $enabled ? 'disable' : 'enable'; ?>">
                                <i class="fas fa-power-off"></i>
                                <?php echo $enabled ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Bulk Cache Clearing -->
        <div class="cache-section">
            <h2><i class="fas fa-eraser"></i> Bulk Cache Clearing</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="clear_cache">

                <div class="bulk-clear-form">
                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="email">
                        <span><i class="fas fa-envelope"></i> Email Settings (<?php echo count(array_filter($caches, function ($c) {
                                                                                    return strpos($c['key'], 'email_') === 0;
                                                                                })); ?> files)</span>
                    </label>

                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="settings">
                        <span><i class="fas fa-cog"></i> Site Settings (<?php echo count(array_filter($caches, function ($c) {
                                                                            return strpos($c['key'], 'setting_') === 0;
                                                                        })); ?> files)</span>
                    </label>

                    <label class="cache-checkbox-item cache-option-rooms">
                        <input type="checkbox" name="cache_types[]" value="rooms">
                        <span><i class="fas fa-bed"></i> <strong>Rooms & Prices</strong> (<?php
                                                                                            $room_count = count(array_filter($caches, function ($c) {
                                                                                                return strpos($c['key'], 'rooms_') === 0 || strpos($c['key'], 'room_') === 0 ||
                                                                                                    strpos($c['key'], 'facilities_') === 0 || strpos($c['key'], 'gallery_') === 0 ||
                                                                                                    strpos($c['key'], 'hero_') === 0;
                                                                                            }));
                                                                                            echo $room_count; ?> files)</span>
                    </label>

                    <label class="cache-checkbox-item cache-option-images">
                        <input type="checkbox" name="cache_types[]" value="images">
                        <span><i class="fas fa-image"></i> <strong>Image Cache</strong> (<?php
                                                                                            echo $stats['image_cache']['files']; ?> images, <?php echo $stats['image_cache']['size_formatted']; ?>)</span>
                    </label>

                    <label class="cache-checkbox-item cache-option-pages">
                        <input type="checkbox" name="cache_types[]" value="pages">
                        <span><i class="fas fa-file-code"></i> <strong>Page HTML Cache</strong> (<?php
                                                                                                    echo $stats['page_cache']['files']; ?> files, <?php echo $stats['page_cache']['size_formatted']; ?>)</span>
                    </label>

                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="tables">
                        <span><i class="fas fa-database"></i> Database Tables (<?php echo count(array_filter($caches, function ($c) {
                                                                                    return strpos($c['key'], 'table_') === 0;
                                                                                })); ?> files)</span>
                    </label>

                    <label class="cache-checkbox-item cache-option-all">
                        <input type="checkbox" name="cache_types[]" value="all">
                        <span><i class="fas fa-trash"></i> <strong>ALL CACHES (<?php echo $stats['total_files']; ?> files, <?php echo $stats['total_size_formatted']; ?>)</strong></span>
                    </label>
                </div>

                <button type="submit" class="btn-action btn-delete"
                    onclick="return confirm('Are you sure you want to clear the selected caches?');">
                    <i class="fas fa-eraser"></i> Clear Selected Caches
                </button>
            </form>
        </div>

        <!-- Quick Actions: SEO & Favicon Purge -->
        <div class="cache-section">
            <h2><i class="fas fa-wand-magic-sparkles"></i> Quick Actions</h2>
            <form method="POST" onsubmit="return confirm('This will clear ALL page HTML caches and bump favicon/meta asset versions so browsers refetch them. Proceed?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="purge_seo_favicon">
                <p class="cache-toggle-desc cache-list-intro">
                    Runs a targeted purge to ensure favicon and SEO/meta changes are reflected immediately:
                </p>
                <ul class="cache-bullet-list">
                    <li>Clear all Page HTML caches (refreshes meta tags across the site)</li>
                    <li>Delete cached proxied logo (if logo is external and proxied)</li>
                    <li>Bump a version parameter on favicon and touch-icon links to bypass CDN/browser cache</li>
                </ul>
                <button type="submit" class="btn-action btn-primary">
                    <i class="fas fa-broom"></i> Purge SEO &amp; Favicon
                </button>
            </form>
        </div>

        <!-- PWA / Service Worker Version -->
        <div class="cache-section">
            <h2><i class="fas fa-mobile-screen-button"></i> PWA Service Worker</h2>
            <p class="text-muted cache-section-intro">
                Bumping the SW version forces all browsers and installed PWA instances to discard their cached assets and reload fresh copies on their next visit.
                Do this after a significant release or when you update JS, CSS, or images.
            </p>
            <div class="sw-version-grid">
                <div class="sw-version-card">
                    <div class="sw-version-label">Public Site SW</div>
                    <code class="sw-version-code"><?php echo htmlspecialchars($pwa_public_version, ENT_QUOTES, 'UTF-8'); ?></code>
                </div>
                <div class="sw-version-card">
                    <div class="sw-version-label">Admin SW</div>
                    <code class="sw-version-code"><?php echo htmlspecialchars($pwa_admin_version, ENT_QUOTES, 'UTF-8'); ?></code>
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('Bump both SW versions now? All browsers will re-download cached assets on next visit.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="bump_sw">
                <button type="submit" class="btn-action btn-primary">
                    <i class="fas fa-arrow-up-right-dots"></i> Bump SW Version Now
                </button>
            </form>
        </div>

        <!-- Scheduled Cache Clearing -->
        <div class="cache-section">
            <h2><i class="fas fa-clock"></i> Scheduled Cache Clearing</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_schedule">

                <div class="schedule-form">
                    <div class="switch-container">
                        <label class="switch">
                            <input type="checkbox" name="schedule_enabled"
                                <?php echo isset($cache_settings['cache_schedule_enabled']) && $cache_settings['cache_schedule_enabled'] ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">
                            Enable Auto-Clear
                        </span>
                    </div>

                    <div class="form-group">
                        <label>Clear Frequency</label>
                        <select name="schedule_interval" id="schedule_interval">
                            <option value="30sec"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '30sec') ? 'selected' : ''; ?>>
                                Every 30 Seconds
                            </option>
                            <option value="1min"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '1min') ? 'selected' : ''; ?>>
                                Every 1 Minute
                            </option>
                            <option value="5min"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '5min') ? 'selected' : ''; ?>>
                                Every 5 Minutes
                            </option>
                            <option value="15min"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '15min') ? 'selected' : ''; ?>>
                                Every 15 Minutes
                            </option>
                            <option value="30min"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '30min') ? 'selected' : ''; ?>>
                                Every 30 Minutes
                            </option>
                            <option value="hourly"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'hourly') ? 'selected' : ''; ?>>
                                Every Hour
                            </option>
                            <option value="6hours"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '6hours') ? 'selected' : ''; ?>>
                                Every 6 Hours
                            </option>
                            <option value="12hours"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '12hours') ? 'selected' : ''; ?>>
                                Every 12 Hours
                            </option>
                            <option value="daily"
                                <?php echo (!isset($cache_settings['cache_schedule_interval']) || $cache_settings['cache_schedule_interval'] == 'daily') ? 'selected' : ''; ?>>
                                Daily
                            </option>
                            <option value="weekly"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'weekly') ? 'selected' : ''; ?>>
                                Weekly
                            </option>
                            <option value="custom"
                                <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'custom') ? 'selected' : ''; ?>>
                                Custom Interval
                            </option>
                        </select>
                    </div>

                    <div class="form-group custom-interval-group" id="custom_interval_group">
                        <label>Custom Interval (seconds)</label>
                        <input type="number" name="custom_seconds" id="custom_seconds"
                            value="<?php echo $cache_settings['cache_custom_seconds'] ?? '60'; ?>"
                            min="10" max="86400" step="1">
                        <small class="form-help-text">
                            Min: 10 seconds (0.17 mins) | Max: 86400 seconds (24 hours)
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Clear At Time</label>
                        <input type="time" name="schedule_time"
                            value="<?php echo $cache_settings['cache_schedule_time'] ?? '00:00'; ?>"
                            min="00:00" max="23:59">
                    </div>

                    <button type="submit" class="btn-action btn-save">
                        <i class="fas fa-save"></i> Save Schedule
                    </button>
                </div>
            </form>

            <div class="schedule-note">
                <i class="fas fa-info-circle"></i>
                <strong>Note:</strong> Scheduled cache clearing requires a cron job (Linux/Mac) or Task Scheduler (Windows) to be set up.
                <br><br>
                <strong>Cron Setup (Linux/Mac):</strong><br>
                For intervals &lt; 1 minute: <code>* * * * * php scripts/scheduled-cache-clear.php</code> (runs every minute)<br>
                For other intervals: Script will check if it should run based on your settings.<br>
                <br>
                <strong>Windows Task Scheduler:</strong><br>
                Set trigger to run every 1 minute for best accuracy with short intervals.
            </div>

            <script>
                function toggleCustomInterval() {
                    const intervalSelect = document.getElementById('schedule_interval');
                    const customGroup = document.getElementById('custom_interval_group');
                    const timeInput = document.querySelector('input[name="schedule_time"]');
                    const timeGroup = timeInput ? timeInput.closest('.form-group') : null;

                    if (!intervalSelect || !customGroup || !timeGroup) {
                        return;
                    }

                    const interval = intervalSelect.value;
                    const hideTimeGroup = interval === 'custom' || ['30sec', '1min', '5min', '15min', '30min', 'hourly'].includes(interval);
                    const showCustomGroup = interval === 'custom';

                    // Batch style writes in one frame to avoid repeated sync layout work.
                    requestAnimationFrame(function() {
                        customGroup.style.display = showCustomGroup ? 'block' : 'none';
                        timeGroup.style.display = hideTimeGroup ? 'none' : 'block';
                    });
                }

                // Run on page load
                document.addEventListener('DOMContentLoaded', function() {
                    const intervalSelect = document.getElementById('schedule_interval');
                    if (intervalSelect) {
                        intervalSelect.addEventListener('change', toggleCustomInterval);
                    }
                    toggleCustomInterval();
                });
            </script>
        </div>

        <!-- Cache Files List -->
        <?php if (!empty($caches)): ?>
            <div class="cache-section">
                <h2><i class="fas fa-list"></i> Current Cache Files (<?php echo count($caches); ?>)</h2>
                <div class="cache-table-wrap">
                    <table class="cache-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>File Name</th>
                                <th>Cache Key</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($caches as $cache): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-<?php echo $cache['source']; ?>">
                                            <?php echo htmlspecialchars($cache['source_label']); ?>
                                        </span>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($cache['file']); ?></code></td>
                                    <td><?php echo htmlspecialchars($cache['key']); ?></td>
                                    <td><?php echo $cache['size_formatted']; ?></td>
                                    <td><?php echo $cache['created_formatted']; ?></td>
                                    <td><?php echo $cache['expires_formatted']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $cache['expired'] ? 'badge-expired' : 'badge-active'; ?>">
                                            <?php echo $cache['expired'] ? 'Expired' : 'Active'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

