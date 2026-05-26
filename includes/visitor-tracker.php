<?php
/**
 * Visitor Tracker
 * Lightweight visitor/session logger for public pages.
 * Include this file in footer.php so it runs on every public page.
 * Does NOT track admin pages, API calls, or AJAX requests.
 *
 * Records: IP, user agent, device type, browser, OS, referrer, page URL.
 * Logs to BOTH database (site_visitors table) AND log file (logs/visitor-sessions.log).
 * Uses session to avoid duplicate inserts on rapid page loads.
 * Respects cookie consent — only full tracking if consent is 'all' or 'essential'.
 */

// Only track if we have a DB connection and sessions
if (!isset($pdo) || session_status() !== PHP_SESSION_ACTIVE) return;

require_once __DIR__ . '/system-logger.php';

// Don't track admin pages, API endpoints, or AJAX requests
$_vt_script = $_SERVER['SCRIPT_FILENAME'] ?? '';
$_vt_file = basename($_SERVER['PHP_SELF']);
if (strpos($_vt_script, DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR) !== false
    || strpos($_vt_script, DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR) !== false
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
    return;
}

// ── Early local/private IP detection (before consent check) ──
// This lets localhost / LAN dev testing bypass cookie-consent so
// analytics always work when developing locally.
$_vt_ip_early = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (strpos($_vt_ip_early, ',') !== false) {
    $_vt_ip_early = trim(explode(',', $_vt_ip_early)[0]);
}
$_vt_is_local = in_array($_vt_ip_early, ['127.0.0.1', '::1', '::ffff:127.0.0.1', '0.0.0.0'], true)
    || preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $_vt_ip_early);

// Check cookie consent — skip analytics tracking if user declined.
// Local/private IPs (localhost, LAN) always bypass this so dev testing is always tracked.
$_vt_consent = $_COOKIE['cookie_consent'] ?? null;
if ($_vt_consent === 'declined' && !$_vt_is_local) {
    return; // User explicitly declined tracking (remote visitors only)
}

if (!function_exists('rh_vt_column_exists')) {
    function rh_vt_column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM information_schema.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?\n            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('rh_vt_ensure_column')) {
    function rh_vt_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        if (rh_vt_column_exists($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        } catch (Throwable $e) {
            if (function_exists('rh_log_event')) {
                rh_log_event('visitor_analytics', 'warning', 'Could not add analytics column', [
                    'table' => $table,
                    'column' => $column,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

// Rate limit: max 1 log per page per 2 seconds per session
$_vt_key = 'vt_last_' . md5($_vt_file);
if (isset($_SESSION[$_vt_key]) && (time() - $_SESSION[$_vt_key]) < 2) {
    return;
}

try {
    // Check if table exists and ensure older schemas have the columns used below.
    if (!isset($_SESSION['_vt_table_ok_v2'])) {
        $check = $pdo->query("SHOW TABLES LIKE 'site_visitors'");
        if ($check->rowCount() === 0) {
            // Auto-create table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `site_visitors` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `session_id` VARCHAR(128) NOT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `user_agent` TEXT,
                    `device_type` ENUM('desktop', 'tablet', 'mobile', 'bot', 'unknown') DEFAULT 'unknown',
                    `browser` VARCHAR(100) DEFAULT NULL,
                    `os` VARCHAR(100) DEFAULT NULL,
                    `referrer` TEXT DEFAULT NULL,
                    `referrer_domain` VARCHAR(255) DEFAULT NULL,
                    `country` VARCHAR(100) DEFAULT NULL,
                    `page_url` VARCHAR(500) NOT NULL,
                    `page_title` VARCHAR(255) DEFAULT NULL,
                    `is_first_visit` TINYINT(1) DEFAULT 0,
                    `visit_duration` INT DEFAULT NULL COMMENT 'Seconds on page',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_session` (`session_id`),
                    INDEX `idx_ip` (`ip_address`),
                    INDEX `idx_created` (`created_at`),
                    INDEX `idx_page` (`page_url`(191)),
                    INDEX `idx_device` (`device_type`),
                    INDEX `idx_country` (`country`(50))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        rh_vt_ensure_column($pdo, 'site_visitors', 'page_title', "`page_title` VARCHAR(255) DEFAULT NULL AFTER `page_url`");
        rh_vt_ensure_column($pdo, 'site_visitors', 'visit_duration', "`visit_duration` INT DEFAULT NULL COMMENT 'Seconds on page' AFTER `is_first_visit`");
        rh_vt_ensure_column($pdo, 'site_visitors', 'consent_level', "`consent_level` VARCHAR(20) DEFAULT NULL AFTER `visit_duration`");
        rh_vt_ensure_column($pdo, 'site_visitors', 'referrer_domain', "`referrer_domain` VARCHAR(255) DEFAULT NULL AFTER `referrer`");
        rh_vt_ensure_column($pdo, 'site_visitors', 'country', "`country` VARCHAR(100) DEFAULT NULL AFTER `referrer_domain`");
        $_SESSION['_vt_table_ok_v2'] = true;
    }

    // Gather visitor data
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    // ── Country geolocation via IP (cached per session) ──
    $country = null;
    if (!isset($_SESSION['_vt_country'])) {
        // Only look up for non-local IPs
        if (!in_array($ip, ['127.0.0.1', '::1', '0.0.0.0']) && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)) {
            try {
                $geo_ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
                $geo_json = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city,regionName', false, $geo_ctx);
                if ($geo_json) {
                    $geo = json_decode($geo_json, true);
                    if (isset($geo['status']) && $geo['status'] === 'success') {
                        $parts = [];
                        if (!empty($geo['city'])) $parts[] = $geo['city'];
                        if (!empty($geo['regionName'])) $parts[] = $geo['regionName'];
                        if (!empty($geo['country'])) $parts[] = $geo['country'];
                        $country = implode(', ', $parts) ?: ($geo['country'] ?? null);
                    }
                }
            } catch (Exception $e) {
                // Silently fail — geolocation is non-critical
            }
        } else {
            $country = 'Local';
        }
        $_SESSION['_vt_country'] = $country;
    } else {
        $country = $_SESSION['_vt_country'];
    }

    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $referrer_domain = '';
    if (!empty($referrer)) {
        $parsed = parse_url($referrer);
        $referrer_domain = $parsed['host'] ?? '';
    }

    $page_url = $_SERVER['REQUEST_URI'] ?? '/' . $_vt_file;
    $page_title = $GLOBALS['page_title'] ?? $GLOBALS['pageTitle'] ?? $GLOBALS['title'] ?? '';
    if (!is_string($page_title) || trim($page_title) === '') {
        $page_title = function_exists('getSetting') ? (string)getSetting('site_name', '') : '';
    }
    $session_id = session_id();

    // Detect device type
    $device_type = 'desktop';
    if (preg_match('/bot|crawl|spider|slurp|googlebot|bingbot|yandex|baidu/i', $ua)) {
        $device_type = 'bot';
    } elseif (preg_match('/mobile|android.*mobile|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) {
        $device_type = 'mobile';
    } elseif (preg_match('/tablet|ipad|playbook|silk|kindle/i', $ua)) {
        $device_type = 'tablet';
    } elseif (empty($ua)) {
        $device_type = 'unknown';
    }

    // Detect browser
    $browser = 'Unknown';
    if (preg_match('/Edg\//i', $ua)) $browser = 'Edge';
    elseif (preg_match('/OPR\//i', $ua)) $browser = 'Opera';
    elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox\//i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'IE';
    elseif (preg_match('/bot|crawl|spider/i', $ua)) $browser = 'Bot';

    // Detect OS
    $os = 'Unknown';
    if (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/Windows NT/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua) && preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $os = 'iOS';
    elseif (preg_match('/CrOS/i', $ua)) $os = 'Chrome OS';

    // Check if first visit in this session
    $is_first = 0;
    if (!isset($_SESSION['_vt_first_logged'])) {
        $is_first = 1;
        $_SESSION['_vt_first_logged'] = true;
    }

    $consent_level = $_COOKIE['cookie_consent'] ?? 'pending';

    // Insert visitor record into site_visitors table
    $stmt = $pdo->prepare("
        INSERT INTO site_visitors
        (session_id, ip_address, user_agent, device_type, browser, os, referrer, referrer_domain, country, page_url, page_title, is_first_visit, consent_level, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $session_id, $ip, substr($ua, 0, 1000), $device_type, $browser, $os,
        substr($referrer, 0, 1000), $referrer_domain, $country ? substr($country, 0, 100) : null,
        substr($page_url, 0, 500), substr($page_title, 0, 255), $is_first, $consent_level
    ]);

    // ── Also insert into session_logs table (dedicated session tracking) ──
    if (!isset($_SESSION['_vt_slog_ok_v2'])) {
        $slog_check = $pdo->query("SHOW TABLES LIKE 'session_logs'");
        if ($slog_check->rowCount() === 0) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `session_logs` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `session_id` VARCHAR(128) NOT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `device_type` VARCHAR(20) DEFAULT 'unknown',
                    `browser` VARCHAR(100) DEFAULT NULL,
                    `os` VARCHAR(100) DEFAULT NULL,
                    `page_url` VARCHAR(500) NOT NULL,
                    `referrer_domain` VARCHAR(255) DEFAULT NULL,
                    `country` VARCHAR(100) DEFAULT NULL,
                    `session_start` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `page_count` INT DEFAULT 1,
                    `consent_level` VARCHAR(20) DEFAULT NULL,
                    UNIQUE KEY `uq_session_id` (`session_id`),
                    INDEX `idx_sl_ip` (`ip_address`),
                    INDEX `idx_sl_start` (`session_start`),
                    INDEX `idx_sl_device` (`device_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        rh_vt_ensure_column($pdo, 'session_logs', 'consent_level', "`consent_level` VARCHAR(20) DEFAULT NULL AFTER `page_count`");
        rh_vt_ensure_column($pdo, 'session_logs', 'country', "`country` VARCHAR(100) DEFAULT NULL AFTER `referrer_domain`");
        $_SESSION['_vt_slog_ok_v2'] = true;
    }

    // Upsert session_logs: update page_count & last_activity if session exists, else insert
    $slog_stmt = $pdo->prepare("
        INSERT INTO session_logs (session_id, ip_address, device_type, browser, os, page_url, referrer_domain, country, consent_level, session_start, last_activity, page_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ON DUPLICATE KEY UPDATE
            last_activity = NOW(),
            page_count = page_count + 1,
            page_url = VALUES(page_url),
            country = COALESCE(VALUES(country), country),
            consent_level = VALUES(consent_level)
    ");
    $slog_stmt->execute([
        $session_id, $ip, $device_type, $browser, $os,
        substr($page_url, 0, 500), $referrer_domain, $country ? substr($country, 0, 100) : null,
        $consent_level
    ]);

    // ── Write to log file (logs/visitor-sessions.log) ──
    $_vt_log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($_vt_log_dir)) {
        @mkdir($_vt_log_dir, 0755, true);
    }
    $_vt_log_file = $_vt_log_dir . '/visitor-sessions.log';

    // Format: [TIMESTAMP] SESSION_ID | IP | COUNTRY | DEVICE | BROWSER | OS | PAGE | TITLE | REFERRER | FIRST_VISIT | CONSENT
    $_vt_log_line = sprintf(
        "[%s] %s | IP: %s | Country: %s | Device: %s | Browser: %s | OS: %s | Page: %s | Title: %s | Ref: %s | First: %s | Consent: %s\n",
        date('Y-m-d H:i:s'),
        $session_id,
        $ip,
        $country ?: 'Unknown',
        $device_type,
        $browser,
        $os,
        substr($page_url, 0, 200),
        substr($page_title, 0, 120),
        $referrer_domain ?: 'direct',
        $is_first ? 'YES' : 'NO',
        $consent_level
    );
    @file_put_contents($_vt_log_file, $_vt_log_line, FILE_APPEND | LOCK_EX);

    // Auto-rotate log file if > 5MB
    if (@filesize($_vt_log_file) > 5 * 1024 * 1024) {
        @rename($_vt_log_file, $_vt_log_dir . '/visitor-sessions-' . date('Y-m-d-His') . '.log');
    }

    $_SESSION[$_vt_key] = time();

} catch (Exception $e) {
    // Silently fail — visitor tracking should never break the page
    error_log('Visitor tracker error: ' . $e->getMessage());
    if (function_exists('rh_log_event')) {
        rh_log_event('visitor_analytics', 'error', 'Visitor tracker failed', ['error' => $e->getMessage()]);
    }
}
