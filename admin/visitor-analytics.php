<?php

/**
 * Admin - Visitor Analytics
 * View website visitor sessions: who visited, from where, which device, etc.
 */

require_once 'admin-init.php';

$user = $user ?? ['id' => 0];
$csrf_token = $csrf_token ?? generateCsrfToken();

if (!hasPermission((int)$user['id'], 'visitor_analytics')) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

$site_name = getSetting('site_name');
$filter_device = $_GET['device'] ?? '';
$filter_range = $_GET['range'] ?? '7days';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'cleanup_analytics') {
            $retentionDays = (int)($_POST['retention_days'] ?? 90);
            if (!in_array($retentionDays, [30, 60, 90, 180, 365], true)) {
                throw new RuntimeException('Invalid retention period selected.');
            }

            $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));
            $pdo->beginTransaction();

            $siteDeleted = 0;
            $sessionDeleted = 0;
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'site_visitors'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $deleteSite = $pdo->prepare("DELETE FROM site_visitors WHERE created_at < ?");
                $deleteSite->execute([$cutoff]);
                $siteDeleted = $deleteSite->rowCount();
            }

            $sessionTableCheck = $pdo->query("SHOW TABLES LIKE 'session_logs'");
            if ($sessionTableCheck && $sessionTableCheck->rowCount() > 0) {
                $deleteSession = $pdo->prepare("DELETE FROM session_logs WHERE last_activity < ?");
                $deleteSession->execute([$cutoff]);
                $sessionDeleted = $deleteSession->rowCount();
            }

            $pdo->commit();
            $message = "Visitor analytics cleaned successfully. Removed {$siteDeleted} visitor rows and {$sessionDeleted} session rows older than {$retentionDays} days.";
            rh_log_event('visitor_analytics', 'info', 'Visitor analytics cleanup completed', [
                'retention_days' => $retentionDays,
                'site_visitors_deleted' => $siteDeleted,
                'session_logs_deleted' => $sessionDeleted,
            ]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        rh_log_event('visitor_analytics', 'error', 'Visitor analytics cleanup failed', ['error' => $e->getMessage()]);
    }
}

if (!in_array($filter_device, ['', 'all', 'desktop', 'mobile', 'tablet', 'bot', 'unknown'], true)) {
    $filter_device = 'all';
}

if (!in_array($filter_range, ['today', '7days', '30days', 'custom'], true)) {
    $filter_range = '7days';
}

function normalizeCountryLabel(?string $country): string
{
    $country = trim((string)$country);
    if ($country === '') {
        return 'Unknown';
    }
    if (stripos($country, ',') !== false) {
        $country = trim((string)substr($country, (int)strrpos($country, ',') + 1));
    }
    return $country !== '' ? $country : 'Unknown';
}

function sectionFromPageUrl(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return 'Unknown';
    }
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $path = trim((string)$path, '/');
    if ($path === '') {
        return 'Home';
    }

    $last = basename($path);
    $slug = strtolower((string)preg_replace('/\.php$/i', '', $last));
    $slug = trim((string)preg_replace('/[^a-z0-9_-]+/i', '-', $slug), '-');
    if ($slug === '' || $slug === 'index') {
        return 'Home';
    }
    return ucwords(str_replace(['-', '_'], ' ', $slug));
}

// Build date range
switch ($filter_range) {
    case 'today':
        $date_start = date('Y-m-d 00:00:00');
        $date_end = date('Y-m-d 23:59:59');
        break;
    case '7days':
        $date_start = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $date_end = date('Y-m-d 23:59:59');
        break;
    case '30days':
        $date_start = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $date_end = date('Y-m-d 23:59:59');
        break;
    case 'custom':
        $date_start = ($_GET['date_start'] ?? date('Y-m-d')) . ' 00:00:00';
        $date_end = ($_GET['date_end'] ?? date('Y-m-d')) . ' 23:59:59';
        break;
    default:
        $date_start = date('Y-m-d 00:00:00');
        $date_end = date('Y-m-d 23:59:59');
}

$table_exists = false;
$stats = ['total_views' => 0, 'unique_sessions' => 0, 'unique_ips' => 0, 'new_visitors' => 0];
$devices = [];
$browsers = [];
$operating_systems = [];
$top_pages = [];
$all_pages = [];
$top_sections = [];
$country_breakdown = [];
$top_ips = [];
$referrers = [];
$visitors = [];
$hourly_data = array_fill(0, 24, 0);
$total_visitor_rows = 0;
$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$total_pages = 1;

// Sales & Marketing insight defaults
$funnel = ['homepage' => 0, 'rooms' => 0, 'booking' => 0, 'confirmation' => 0];
$entry_pages = [];
$exit_pages = [];
$room_views = [];
$abandoned_bookings = 0;
$visitor_loyalty = ['new_unique' => 0, 'returning' => 0];
$traffic_sources = [];
$avg_pages_per_session = 0;
$booking_hours = array_fill(0, 24, 0);
$geo_opportunities = [];
$bounce_rate = 0;
$total_sessions = 0;
$bounced_sessions = 0;
$exit_rates = [];
$traffic_source_details = [];
$traffic_by_category = [];
$problem_pages = [];

try {
    // Check if table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'site_visitors'");
    $table_exists = $table_check->rowCount() > 0;

    if ($table_exists) {
        $where = "created_at BETWEEN ? AND ?";
        $params = [$date_start, $date_end];

        if ($filter_device && $filter_device !== 'all') {
            $where .= " AND device_type = ?";
            $params[] = $filter_device;
        }

        // Summary stats
        $stats_sql = "SELECT
            COUNT(*) as total_views,
            COUNT(DISTINCT session_id) as unique_sessions,
            COUNT(DISTINCT ip_address) as unique_ips,
            SUM(is_first_visit) as new_visitors
            FROM site_visitors WHERE {$where}";

        $stats_stmt = $pdo->prepare($stats_sql);
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;

        // Device breakdown
        $device_stmt = $pdo->prepare("
            SELECT device_type, COUNT(*) as count, COUNT(DISTINCT session_id) as sessions
            FROM site_visitors WHERE {$where}
            GROUP BY device_type ORDER BY count DESC
        ");
        $device_stmt->execute($params);
        $devices = $device_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Browser breakdown
        $browser_stmt = $pdo->prepare("
            SELECT browser, COUNT(*) as count
            FROM site_visitors WHERE {$where}
            GROUP BY browser ORDER BY count DESC LIMIT 10
        ");
        $browser_stmt->execute($params);
        $browsers = $browser_stmt->fetchAll(PDO::FETCH_ASSOC);

        // OS breakdown
        $os_stmt = $pdo->prepare("
            SELECT os, COUNT(*) as count
            FROM site_visitors WHERE {$where}
            GROUP BY os ORDER BY count DESC LIMIT 10
        ");
        $os_stmt->execute($params);
        $operating_systems = $os_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top pages
        $pages_stmt = $pdo->prepare("
            SELECT page_url, COUNT(*) as views, COUNT(DISTINCT session_id) as unique_views
            FROM site_visitors WHERE {$where}
            GROUP BY page_url ORDER BY views DESC LIMIT 15
        ");
        $pages_stmt->execute($params);
        $top_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Most visited sections (derived from page URL)
        $all_pages_stmt = $pdo->prepare("
            SELECT page_url, COUNT(*) as views, COUNT(DISTINCT session_id) as unique_views
            FROM site_visitors
            WHERE {$where}
            GROUP BY page_url
            ORDER BY views DESC
        ");
        $all_pages_stmt->execute($params);
        $all_pages = $all_pages_stmt->fetchAll(PDO::FETCH_ASSOC);

        $sectionAccumulator = [];
        foreach ($all_pages as $pg) {
            $section = sectionFromPageUrl($pg['page_url'] ?? '');
            if (!isset($sectionAccumulator[$section])) {
                $sectionAccumulator[$section] = ['section' => $section, 'views' => 0, 'unique_views' => 0];
            }
            $sectionAccumulator[$section]['views'] += (int)$pg['views'];
            $sectionAccumulator[$section]['unique_views'] += (int)$pg['unique_views'];
        }
        $top_sections = array_values($sectionAccumulator);
        usort($top_sections, static function (array $a, array $b): int {
            return $b['views'] <=> $a['views'];
        });
        $top_sections = array_slice($top_sections, 0, 12);

        // Country breakdown
        $country_stmt = $pdo->prepare("
            SELECT country, COUNT(*) as count, COUNT(DISTINCT session_id) as sessions
            FROM site_visitors
            WHERE {$where}
            GROUP BY country
            ORDER BY count DESC
            LIMIT 15
        ");
        $country_stmt->execute($params);
        $country_rows = $country_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($country_rows as $row) {
            $label = normalizeCountryLabel($row['country'] ?? null);
            if (!isset($country_breakdown[$label])) {
                $country_breakdown[$label] = ['country' => $label, 'count' => 0, 'sessions' => 0];
            }
            $country_breakdown[$label]['count'] += (int)$row['count'];
            $country_breakdown[$label]['sessions'] += (int)$row['sessions'];
        }
        $country_breakdown = array_values($country_breakdown);
        usort($country_breakdown, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        // Top referrers
        $ref_stmt = $pdo->prepare("
            SELECT referrer_domain, COUNT(*) as count
            FROM site_visitors WHERE {$where} AND referrer_domain != '' AND referrer_domain IS NOT NULL
            GROUP BY referrer_domain ORDER BY count DESC LIMIT 10
        ");
        $ref_stmt->execute($params);
        $referrers = $ref_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top visitor IPs
        $ip_stmt = $pdo->prepare("
            SELECT ip_address, country, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
            FROM site_visitors
            WHERE {$where}
            GROUP BY ip_address, country
            ORDER BY views DESC
            LIMIT 20
        ");
        $ip_stmt->execute($params);
        $top_ips = $ip_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent visitors (paginated by distinct IP — each IP = one row in the table)
        $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip_address) FROM site_visitors WHERE {$where}");
        $count_stmt->execute($params);
        $total_visitor_rows = (int)$count_stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_visitor_rows / $per_page));
        if ($page_num > $total_pages) {
            $page_num = $total_pages;
        }
        $offset = ($page_num - 1) * $per_page;

        // Get the distinct IPs for this page, ordered by most-recent activity
        $ips_stmt = $pdo->prepare("SELECT ip_address FROM site_visitors WHERE {$where} GROUP BY ip_address ORDER BY MAX(created_at) DESC LIMIT ? OFFSET ?");
        $ips_stmt->execute(array_merge($params, [$per_page, $offset]));
        $page_ips = $ips_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch all page-visits for those IPs in one query, then group in PHP
        $grouped_visitors = [];
        if (!empty($page_ips)) {
            $in_ph = implode(',', array_fill(0, count($page_ips), '?'));
            $vis_stmt = $pdo->prepare("SELECT * FROM site_visitors WHERE {$where} AND ip_address IN ({$in_ph}) ORDER BY created_at DESC");
            $vis_stmt->execute(array_merge($params, $page_ips));
            foreach ($vis_stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $v['country_display'] = normalizeCountryLabel($v['country'] ?? null);
                $grouped_visitors[$v['ip_address']][] = $v;
            }
            // Preserve page order (same order as $page_ips)
            $ordered_grouped = [];
            foreach ($page_ips as $ip) {
                if (isset($grouped_visitors[$ip])) {
                    $ordered_grouped[$ip] = $grouped_visitors[$ip];
                }
            }
            $grouped_visitors = $ordered_grouped;
        }

        // Hourly distribution
        $hourly_stmt = $pdo->prepare("
            SELECT HOUR(created_at) as hour, COUNT(*) as count
            FROM site_visitors WHERE {$where}
            GROUP BY HOUR(created_at) ORDER BY hour
        ");
        $hourly_stmt->execute($params);
        $hourly = $hourly_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hourly as $h) {
            $hourly_data[$h['hour']] = (int)$h['count'];
        }

        // ========== SALES & MARKETING INSIGHTS ==========

        // Conversion Funnel: Homepage → Rooms → Booking → Confirmation
        $funnel = [
            'homepage' => 0,
            'rooms' => 0,
            'booking' => 0,
            'confirmation' => 0
        ];
        $funnel_stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN page_url LIKE '%/index.php%' OR page_url = '/' OR page_url = '' THEN 1 ELSE 0 END) as homepage,
                SUM(CASE WHEN page_url LIKE '%room%' OR page_url LIKE '%accommodation%' THEN 1 ELSE 0 END) as rooms,
                SUM(CASE WHEN page_url LIKE '%booking.php%' THEN 1 ELSE 0 END) as booking,
                SUM(CASE WHEN page_url LIKE '%booking-confirmation%' THEN 1 ELSE 0 END) as confirmation
            FROM site_visitors WHERE {$where}
        ");
        $funnel_stmt->execute($params);
        $funnel = $funnel_stmt->fetch(PDO::FETCH_ASSOC) ?: $funnel;

        // CRITICAL: Bounce rate analysis (single-page sessions)
        $bounce_stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT session_id) as total_sessions,
                COUNT(DISTINCT CASE WHEN page_count = 1 THEN session_id END) as bounced_sessions
            FROM (
                SELECT session_id, COUNT(*) as page_count
                FROM site_visitors
                WHERE {$where}
                GROUP BY session_id
            ) as session_pages
        ");
        $bounce_stmt->execute($params);
        $bounce_data = $bounce_stmt->fetch(PDO::FETCH_ASSOC);
        $total_sessions = (int)($bounce_data['total_sessions'] ?? 0);
        $bounced_sessions = (int)($bounce_data['bounced_sessions'] ?? 0);
        $bounce_rate = $total_sessions > 0 ? round(($bounced_sessions / $total_sessions) * 100, 1) : 0;

        // CRITICAL: Exit rate by page (where people leave)
        $exit_rate_stmt = $pdo->prepare("
            SELECT
                page_url,
                COUNT(*) as total_views,
                COUNT(DISTINCT CASE
                    WHEN created_at = (
                        SELECT MAX(created_at)
                        FROM site_visitors sv2
                        WHERE sv2.session_id = site_visitors.session_id
                    ) THEN session_id
                END) as exits
            FROM site_visitors
            WHERE {$where}
            GROUP BY page_url
            HAVING total_views > 5
            ORDER BY exits DESC
            LIMIT 10
        ");
        $exit_rate_stmt->execute($params);
        $exit_rates = $exit_rate_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate exit rate percentage
        foreach ($exit_rates as &$er) {
            $er['exit_rate'] = $er['total_views'] > 0 ? round(($er['exits'] / $er['total_views']) * 100, 1) : 0;
        }
        unset($er);

        // Traffic source DETAILED breakdown with conversion tracking
        $traffic_source_detail_stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN referrer_domain IS NULL OR referrer_domain = '' THEN 'Direct Traffic'
                    WHEN referrer_domain LIKE '%google%' OR referrer_domain LIKE '%bing%' OR referrer_domain LIKE '%yahoo%' OR referrer_domain LIKE '%duckduckgo%' THEN 'Search Engines'
                    WHEN referrer_domain LIKE '%facebook%' OR referrer_domain LIKE '%instagram%' OR referrer_domain LIKE '%twitter%' OR referrer_domain LIKE '%linkedin%' OR referrer_domain LIKE '%tiktok%' THEN 'Social Media'
                    WHEN referrer_domain LIKE '%booking%' OR referrer_domain LIKE '%tripadvisor%' OR referrer_domain LIKE '%expedia%' THEN 'Travel Sites'
                    ELSE 'Referral Sites'
                END as source_category,
                referrer_domain,
                COUNT(*) as visits,
                COUNT(DISTINCT session_id) as sessions,
                COUNT(DISTINCT ip_address) as unique_visitors,
                AVG(CASE
                    WHEN session_id IN (
                        SELECT DISTINCT session_id FROM site_visitors WHERE page_url LIKE '%booking-confirmation%'
                    ) THEN 100
                    ELSE 0
                END) as conversion_rate
            FROM site_visitors
            WHERE {$where}
            GROUP BY source_category, referrer_domain
            ORDER BY visits DESC
            LIMIT 20
        ");
        $traffic_source_detail_stmt->execute($params);
        $traffic_source_details = $traffic_source_detail_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by category for summary
        $traffic_by_category = [];
        foreach ($traffic_source_details as $tsd) {
            $cat = $tsd['source_category'];
            if (!isset($traffic_by_category[$cat])) {
                $traffic_by_category[$cat] = [
                    'category' => $cat,
                    'visits' => 0,
                    'sessions' => 0,
                    'unique_visitors' => 0,
                    'conversions' => 0,
                    'sources' => []
                ];
            }
            $traffic_by_category[$cat]['visits'] += (int)$tsd['visits'];
            $traffic_by_category[$cat]['sessions'] += (int)$tsd['sessions'];
            $traffic_by_category[$cat]['unique_visitors'] += (int)$tsd['unique_visitors'];
            $traffic_by_category[$cat]['conversions'] += round((float)$tsd['conversion_rate']);
            $traffic_by_category[$cat]['sources'][] = $tsd;
        }

        // Calculate conversion rate per category
        foreach ($traffic_by_category as &$tbc) {
            $tbc['conversion_rate'] = count($tbc['sources']) > 0 ? round($tbc['conversions'] / count($tbc['sources']), 1) : 0;
        }
        unset($tbc);

        // Sort by visits
        usort($traffic_by_category, function ($a, $b) {
            return $b['visits'] <=> $a['visits'];
        });

        // PROBLEM DETECTION: Pages with high traffic but low conversions
        $problem_pages_stmt = $pdo->prepare("
            SELECT
                page_url,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as sessions,
                COUNT(DISTINCT CASE
                    WHEN session_id IN (
                        SELECT DISTINCT session_id
                        FROM site_visitors
                        WHERE page_url LIKE '%booking%'
                    ) THEN session_id
                END) as booking_attempts
            FROM site_visitors
            WHERE {$where}
            AND page_url NOT LIKE '%confirmation%'
            GROUP BY page_url
            HAVING views > 10
            ORDER BY views DESC
            LIMIT 15
        ");
        $problem_pages_stmt->execute($params);
        $problem_pages = $problem_pages_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate conversion efficiency
        foreach ($problem_pages as &$pp) {
            $pp['conversion_efficiency'] = $pp['sessions'] > 0 ? round(($pp['booking_attempts'] / $pp['sessions']) * 100, 1) : 0;
        }
        unset($pp);

        // Sort by lowest conversion first (problems at top)
        usort($problem_pages, function ($a, $b) {
            return $a['conversion_efficiency'] <=> $b['conversion_efficiency'];
        });

        // Entry pages (first page in session)
        $entry_stmt = $pdo->prepare("
            SELECT page_url, COUNT(DISTINCT session_id) as sessions
            FROM site_visitors sv1
            WHERE {$where}
            AND created_at = (
                SELECT MIN(created_at)
                FROM site_visitors sv2
                WHERE sv2.session_id = sv1.session_id
            )
            GROUP BY page_url
            ORDER BY sessions DESC
            LIMIT 10
        ");
        $entry_stmt->execute($params);
        $entry_pages = $entry_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Exit pages (last page in session)
        $exit_stmt = $pdo->prepare("
            SELECT page_url, COUNT(DISTINCT session_id) as sessions
            FROM site_visitors sv1
            WHERE {$where}
            AND created_at = (
                SELECT MAX(created_at)
                FROM site_visitors sv2
                WHERE sv2.session_id = sv1.session_id
            )
            GROUP BY page_url
            ORDER BY sessions DESC
            LIMIT 10
        ");
        $exit_stmt->execute($params);
        $exit_pages = $exit_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Most viewed room types (if room.php?id=X pattern exists)
        $room_views_stmt = $pdo->prepare("
            SELECT page_url, COUNT(*) as views, COUNT(DISTINCT session_id) as unique_visitors
            FROM site_visitors
            WHERE {$where}
            AND page_url LIKE '%room.php%'
            GROUP BY page_url
            ORDER BY views DESC
            LIMIT 10
        ");
        $room_views_stmt->execute($params);
        $room_views = $room_views_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Booking abandonment: sessions that hit booking page but not confirmation
        $abandonment_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT session_id) as abandoned_bookings
            FROM site_visitors
            WHERE {$where}
            AND session_id IN (
                SELECT DISTINCT session_id
                FROM site_visitors
                WHERE page_url LIKE '%booking.php%' AND {$where}
            )
            AND session_id NOT IN (
                SELECT DISTINCT session_id
                FROM site_visitors
                WHERE page_url LIKE '%booking-confirmation%' AND {$where}
            )
        ");
        $abandonment_stmt->execute(array_merge($params, $params, $params));
        $abandoned_bookings = (int)$abandonment_stmt->fetchColumn();

        // Return visitor rate (IPs that appear multiple times across date range)
        $return_stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN visit_count = 1 THEN ip_address END) as new_unique,
                COUNT(DISTINCT CASE WHEN visit_count > 1 THEN ip_address END) as returning
            FROM (
                SELECT ip_address, COUNT(DISTINCT DATE(created_at)) as visit_count
                FROM site_visitors
                WHERE {$where}
                GROUP BY ip_address
            ) as ip_visits
        ");
        $return_stmt->execute($params);
        $visitor_loyalty = $return_stmt->fetch(PDO::FETCH_ASSOC) ?: ['new_unique' => 0, 'returning' => 0];

        // Traffic source quality (direct vs referral)
        $source_stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN referrer_domain IS NULL OR referrer_domain = '' THEN 'Direct'
                    WHEN referrer_domain LIKE '%google%' OR referrer_domain LIKE '%bing%' OR referrer_domain LIKE '%yahoo%' THEN 'Search Engine'
                    WHEN referrer_domain LIKE '%facebook%' OR referrer_domain LIKE '%twitter%' OR referrer_domain LIKE '%instagram%' OR referrer_domain LIKE '%linkedin%' THEN 'Social Media'
                    ELSE 'Referral'
                END as source_type,
                COUNT(*) as visits,
                COUNT(DISTINCT session_id) as sessions
            FROM site_visitors
            WHERE {$where}
            GROUP BY source_type
            ORDER BY visits DESC
        ");
        $source_stmt->execute($params);
        $traffic_sources = $source_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Average pages per session
        $pages_per_session_stmt = $pdo->prepare("
            SELECT AVG(page_count) as avg_pages
            FROM (
                SELECT session_id, COUNT(*) as page_count
                FROM site_visitors
                WHERE {$where}
                GROUP BY session_id
            ) as session_pages
        ");
        $pages_per_session_stmt->execute($params);
        $avg_pages_per_session = round((float)$pages_per_session_stmt->fetchColumn(), 1);

        // Peak booking hours (from actual bookings table)
        $booking_hours = array_fill(0, 24, 0);
        $booking_hours_stmt = $pdo->prepare("
            SELECT HOUR(created_at) as hour, COUNT(*) as count
            FROM bookings
            WHERE created_at BETWEEN ? AND ?
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $booking_hours_stmt->execute($params);
        foreach ($booking_hours_stmt->fetchAll(PDO::FETCH_ASSOC) as $bh) {
            $booking_hours[$bh['hour']] = (int)$bh['count'];
        }

        // Geographic opportunity: high traffic, check if correlates with bookings
        $geo_opportunity_stmt = $pdo->prepare("
            SELECT
                sv.country,
                COUNT(DISTINCT sv.session_id) as visitor_sessions,
                COUNT(DISTINCT b.id) as bookings
            FROM site_visitors sv
            LEFT JOIN bookings b ON DATE(sv.created_at) = DATE(b.created_at)
            WHERE sv.created_at BETWEEN ? AND ?
            GROUP BY sv.country
            HAVING visitor_sessions > 5
            ORDER BY visitor_sessions DESC
            LIMIT 10
        ");
        $geo_opportunity_stmt->execute($params);
        $geo_opportunities = $geo_opportunity_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $table_exists = false;
    $error = 'Visitor analytics could not load. Check System Logs for details.';
    error_log('Visitor analytics error: ' . $e->getMessage());
    rh_log_event('visitor_analytics', 'error', 'Visitor analytics dashboard failed to load', ['error' => $e->getMessage()]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Analytics - <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/visitor-analytics.css?v=<?php echo @filemtime(__DIR__ . '/css/visitor-analytics.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="analytics-container">
            <header class="rh-page-head">
                <div class="rh-page-head__main">
                    <div class="rh-page-head__title"><h1>Visitor Analytics</h1></div>
                    <p class="rh-page-head__meta">Monitor your website traffic and visitor behavior</p>
                </div>
                <div class="rh-page-head__actions">
                    <form method="POST" class="cleanup-inline-form">
                        <input type="hidden" name="action" value="cleanup_analytics">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <label for="retention_days" class="cleanup-inline-label">Cleanup older than</label>
                        <select id="retention_days" name="retention_days" class="cleanup-inline-select">
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">365 days</option>
                        </select>
                        <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Cleanup old visitor analytics data now?')">
                            <i class="fas fa-broom"></i> Cleanup
                        </button>
                    </form>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="alert alert-success va-alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error va-alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!$table_exists): ?>
                <div class="no-data">
                    <i class="fas fa-database"></i>
                    <h3>Visitor tracking not yet initialized</h3>
                    <p>The tracking table will be created automatically when the first visitor accesses your website.</p>
                    <p class="va-note">Or run the migration: <code>Database/migrations/002_create_site_visitors.sql</code></p>
                </div>
            <?php else: ?>

                <!-- Filters -->
                <form class="filter-bar" method="GET">
                    <label class="va-filter-label"><i class="fas fa-filter"></i> Period:</label>
                    <select name="range" onchange="toggleCustomDates(this.value)">
                        <option value="7days" <?php echo $filter_range === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="today" <?php echo $filter_range === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="30days" <?php echo $filter_range === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="custom" <?php echo $filter_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                    <div id="customDates" class="va-custom-dates" style="display: <?php echo $filter_range === 'custom' ? 'flex' : 'none'; ?>;">
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($_GET['date_start'] ?? date('Y-m-d')); ?>">
                        <span>to</span>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($_GET['date_end'] ?? date('Y-m-d')); ?>">
                    </div>
                    <label class="va-filter-label">Device:</label>
                    <select name="device">
                        <option value="all" <?php echo $filter_device === 'all' || empty($filter_device) ? 'selected' : ''; ?>>All Devices</option>
                        <option value="desktop" <?php echo $filter_device === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                        <option value="mobile" <?php echo $filter_device === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                        <option value="tablet" <?php echo $filter_device === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                        <option value="bot" <?php echo $filter_device === 'bot' ? 'selected' : ''; ?>>Bots</option>
                    </select>
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply</button>
                </form>

                <!-- Summary strip -->
                <div class="rh-strip" role="group" aria-label="Traffic summary">
                    <div class="rh-strip__cell">
                        <span class="rh-strip__label">Page views</span>
                        <strong class="rh-strip__value"><?php echo number_format($stats['total_views'] ?? 0); ?></strong>
                    </div>
                    <div class="rh-strip__cell">
                        <span class="rh-strip__label">Unique sessions</span>
                        <strong class="rh-strip__value"><?php echo number_format($stats['unique_sessions'] ?? 0); ?></strong>
                    </div>
                    <div class="rh-strip__cell">
                        <span class="rh-strip__label">Unique IPs</span>
                        <strong class="rh-strip__value"><?php echo number_format($stats['unique_ips'] ?? 0); ?></strong>
                    </div>
                    <div class="rh-strip__cell">
                        <span class="rh-strip__label">New visitors</span>
                        <strong class="rh-strip__value"><?php echo number_format($stats['new_visitors'] ?? 0); ?></strong>
                    </div>
                    <div class="rh-strip__cell">
                        <span class="rh-strip__label">Bounce rate</span>
                        <strong class="rh-strip__value <?php echo $bounce_rate > 40 ? 'rh-strip__value--alert' : 'rh-strip__value--ok'; ?>"><?php echo $bounce_rate; ?>%</strong>
                        <span class="rh-strip__sub"><?php echo $bounce_rate > 60 ? 'Critical' : ($bounce_rate > 40 ? 'High' : 'Good'); ?></span>
                    </div>
                </div>

                <!-- Issues needing action -->
                <?php if ($bounce_rate > 50 || $abandoned_bookings > 0 || !empty($problem_pages)): ?>
                    <section class="rh-panel">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">Needs attention</h2>
                        </div>
                        <ul class="va-issues">
                            <?php if ($bounce_rate > 50): ?>
                                <li class="va-issue">
                                    <i class="fas fa-user-slash"></i>
                                    <div>
                                        <strong>High bounce rate</strong>
                                        <span><?php echo $bounce_rate; ?>% of visitors leave after viewing only one page. Fix: improve landing page content, add clear CTAs, ensure mobile responsiveness.</span>
                                    </div>
                                </li>
                            <?php endif; ?>
                            <?php if ($abandoned_bookings > 0): ?>
                                <li class="va-issue">
                                    <i class="fas fa-shopping-cart"></i>
                                    <div>
                                        <strong>Cart abandonment</strong>
                                        <span><?php echo number_format($abandoned_bookings); ?> visitors started booking but didn't complete. Fix: simplify the booking form, add trust badges, send follow-up emails.</span>
                                    </div>
                                </li>
                            <?php endif; ?>
                            <?php if (!empty($problem_pages)):
                                $worst_page = $problem_pages[0];
                            ?>
                                <li class="va-issue">
                                    <i class="fas fa-chart-line-down"></i>
                                    <div>
                                        <strong>Low conversion pages</strong>
                                        <span><?php echo count($problem_pages); ?> pages have high traffic but low booking conversions. Worst: <?php echo htmlspecialchars(basename($worst_page['page_url'])); ?> (<?php echo $worst_page['conversion_efficiency']; ?>% conversion).</span>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <!-- Traffic sources by category -->
                <section class="rh-panel rh-panel--flush">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Where your traffic comes from</h2>
                    </div>
                    <?php if (empty($traffic_by_category)): ?>
                        <p class="rh-empty">No traffic source data yet. As visitors arrive, their sources will appear here.</p>
                    <?php else: ?>
                        <div class="rh-panel__body">
                            <table class="visitors-table va-table no-auto-pagination">
                                <thead>
                                    <tr>
                                        <th>Source</th>
                                        <th class="num">Visits</th>
                                        <th class="num">Share</th>
                                        <th class="num">Visitors</th>
                                        <th class="num">Sessions</th>
                                        <th class="num">Conversion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_all_visits = max(1, array_sum(array_column($traffic_by_category, 'visits')));
                                    foreach ($traffic_by_category as $tbc):
                                        $pct = round(($tbc['visits'] / $total_all_visits) * 100, 1);
                                        $conv_class = $tbc['conversion_rate'] > 5 ? 'ok' : ($tbc['conversion_rate'] > 2 ? 'warn' : 'alert');
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($tbc['category']); ?></strong></td>
                                            <td class="num"><?php echo number_format($tbc['visits']); ?></td>
                                            <td class="num"><?php echo $pct; ?>%</td>
                                            <td class="num"><?php echo number_format($tbc['unique_visitors']); ?></td>
                                            <td class="num"><?php echo number_format($tbc['sessions']); ?></td>
                                            <td class="num"><span class="rh-pill rh-pill--<?php echo $conv_class; ?>"><?php echo $tbc['conversion_rate']; ?>%</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Detailed Traffic Sources -->
                        <details class="va-details">
                            <summary>
                                <i class="fas fa-chevron-right"></i>
                                View detailed traffic sources (<?php echo count($traffic_source_details); ?> sources)
                            </summary>
                            <div class="va-details__scroll">
                                <table class="visitors-table va-table no-auto-pagination">
                                    <thead>
                                        <tr>
                                            <th>Source</th>
                                            <th class="num">Visits</th>
                                            <th class="num">Sessions</th>
                                            <th class="num">Conv. rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($traffic_source_details as $tsd):
                                            $tsd_class = $tsd['conversion_rate'] > 5 ? 'ok' : ($tsd['conversion_rate'] > 2 ? 'warn' : 'alert');
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($tsd['source_category']); ?></strong>
                                                    <?php if ($tsd['referrer_domain']): ?>
                                                        <small class="va-muted"><?php echo htmlspecialchars($tsd['referrer_domain']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="num"><?php echo number_format($tsd['visits']); ?></td>
                                                <td class="num"><?php echo number_format($tsd['sessions']); ?></td>
                                                <td class="num"><span class="rh-pill rh-pill--<?php echo $tsd_class; ?>"><?php echo round($tsd['conversion_rate'], 1); ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>

                <!-- Where visitors are lost -->
                <?php if (!empty($exit_rates) || !empty($problem_pages)): ?>
                    <div class="va-grid">
                        <?php if (!empty($exit_rates)): ?>
                            <section class="rh-panel rh-panel--flush">
                                <div class="rh-panel__head">
                                    <h2 class="rh-panel__title">High exit pages</h2>
                                    <span class="va-muted">Pages where visitors leave most often</span>
                                </div>
                                <div class="rh-panel__body">
                                    <table class="visitors-table va-table no-auto-pagination">
                                        <thead>
                                            <tr><th>Page</th><th class="num">Views</th><th class="num">Exit rate</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($exit_rates, 0, 5) as $er):
                                                $er_class = $er['exit_rate'] > 60 ? 'alert' : ($er['exit_rate'] > 40 ? 'warn' : 'ok');
                                            ?>
                                                <tr>
                                                    <td class="va-cell-trunc"><?php echo htmlspecialchars(basename($er['page_url'])); ?></td>
                                                    <td class="num"><?php echo number_format($er['total_views']); ?></td>
                                                    <td class="num"><span class="rh-pill rh-pill--<?php echo $er_class; ?>"><?php echo $er['exit_rate']; ?>%</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if (!empty($problem_pages)): ?>
                            <section class="rh-panel rh-panel--flush">
                                <div class="rh-panel__head">
                                    <h2 class="rh-panel__title">Low conversion pages</h2>
                                    <span class="va-muted">High traffic but not converting to bookings</span>
                                </div>
                                <div class="rh-panel__body">
                                    <table class="visitors-table va-table no-auto-pagination">
                                        <thead>
                                            <tr><th>Page</th><th class="num">Views</th><th class="num">Sessions</th><th class="num">To booking</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($problem_pages, 0, 5) as $pp):
                                                $pp_class = $pp['conversion_efficiency'] > 10 ? 'ok' : ($pp['conversion_efficiency'] > 5 ? 'warn' : 'alert');
                                            ?>
                                                <tr>
                                                    <td class="va-cell-trunc"><?php echo htmlspecialchars(basename($pp['page_url'])); ?></td>
                                                    <td class="num"><?php echo number_format($pp['views']); ?></td>
                                                    <td class="num"><?php echo number_format($pp['sessions']); ?></td>
                                                    <td class="num"><span class="rh-pill rh-pill--<?php echo $pp_class; ?>"><?php echo $pp['conversion_efficiency']; ?>%</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Hourly Traffic -->
                <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Hourly Traffic Distribution</h2></div>
                    <?php $max_hourly = max(1, max($hourly_data)); ?>
                    <div class="hourly-chart">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <div class="hourly-bar" style="height: <?php echo max(2, ($hourly_data[$h] / $max_hourly) * 100); ?>%;">
                                <span class="tooltip"><?php echo sprintf('%02d:00', $h); ?> - <?php echo $hourly_data[$h]; ?> visits</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="hourly-labels">
                        <span>12am</span><span>3am</span><span>6am</span><span>9am</span>
                        <span>12pm</span><span>3pm</span><span>6pm</span><span>9pm</span>
                    </div>
                </div>

                <!-- Conversion Funnel -->
                <section class="rh-panel rh-panel--flush">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title">Booking conversion funnel</h2>
                        <span class="va-muted">Visitor journey from homepage to booking confirmation</span>
                    </div>
                    <?php
                    $funnel_max = max(1, max(array_values($funnel ?? [])));
                    $funnel_steps = [
                        ['key' => 'homepage', 'label' => 'Homepage visits'],
                        ['key' => 'rooms', 'label' => 'Viewed rooms'],
                        ['key' => 'booking', 'label' => 'Started booking'],
                        ['key' => 'confirmation', 'label' => 'Completed booking']
                    ];
                    ?>
                    <div class="rh-panel__body">
                        <table class="visitors-table va-table no-auto-pagination">
                            <thead>
                                <tr><th>Step</th><th class="num">Visitors</th><th>Of top step</th><th class="num">Drop-off</th><th class="num">Conversion</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($funnel_steps as $idx => $step):
                                    $value = (int)($funnel[$step['key']] ?? 0);
                                    $pct = $funnel_max > 0 ? round(($value / $funnel_max) * 100) : 0;
                                    $prev_value = $idx > 0 ? (int)($funnel[$funnel_steps[$idx - 1]['key']] ?? 0) : $value;
                                    $drop_rate = $prev_value > 0 ? round((($prev_value - $value) / $prev_value) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo $step['label']; ?></strong></td>
                                        <td class="num"><?php echo number_format($value); ?></td>
                                        <td>
                                            <div class="va-meter"><div class="va-meter__fill" style="width: <?php echo $pct; ?>%;"></div></div>
                                        </td>
                                        <td class="num"><?php echo ($idx > 0 && $drop_rate > 0) ? '<span class="va-bad">' . $drop_rate . '%</span>' : '&mdash;'; ?></td>
                                        <td class="num"><?php echo ($idx > 0 && $prev_value > 0) ? round(($value / $prev_value) * 100, 1) . '%' : '&mdash;'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($abandoned_bookings > 0): ?>
                        <p class="va-footnote"><i class="fas fa-exclamation-triangle"></i> <strong><?php echo number_format($abandoned_bookings); ?> abandoned bookings</strong> &mdash; visitors who reached the booking page but didn't complete. Consider retargeting or follow-up campaigns.</p>
                    <?php endif; ?>
                </section>

                <!-- Two-column layout for additional insights -->
                <div class="va-grid">

                    <!-- Traffic Sources -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Traffic Sources</h2></div>
                        <p class="va-note">Where your visitors come from</p>
                        <?php if (empty($traffic_sources)): ?>
                            <p class="rh-empty">No traffic source data</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_traffic = max(1, array_sum(array_column($traffic_sources, 'visits')));
                                $source_colors = [
                                    'Direct' => '#4CAF50',
                                    'Search Engine' => '#2196F3',
                                    'Social Media' => '#E91E63',
                                    'Referral' => '#FF9800'
                                ];
                                foreach ($traffic_sources as $ts):
                                    $pct = round(($ts['visits'] / $total_traffic) * 100);
                                    $color = $source_colors[$ts['source_type']] ?? '#999';
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <span style="font-weight: 500; color: <?php echo $color; ?>;"><?php echo htmlspecialchars($ts['source_type']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo number_format($ts['visits']); ?> <small style="color: #999;">(<?php echo $pct; ?>%)</small></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Visitor Loyalty -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Visitor Loyalty</h2></div>
                        <p class="va-note">New vs returning visitors</p>
                        <?php
                        $new_vis = (int)($visitor_loyalty['new_unique'] ?? 0);
                        $ret_vis = (int)($visitor_loyalty['returning'] ?? 0);
                        $total_loyalty = max(1, $new_vis + $ret_vis);
                        $new_pct = round(($new_vis / $total_loyalty) * 100);
                        $ret_pct = round(($ret_vis / $total_loyalty) * 100);
                        ?>
                        <table class="rh-kv no-auto-pagination">
                            <tbody>
                                <tr><th scope="row">New visitors</th><td><?php echo number_format($new_vis); ?> <small class="va-muted"><?php echo $new_pct; ?>%</small></td></tr>
                                <tr><th scope="row">Returning visitors</th><td><?php echo number_format($ret_vis); ?> <small class="va-muted"><?php echo $ret_pct; ?>%</small></td></tr>
                            </tbody>
                        </table>
                        <p class="va-note"><i class="fas fa-info-circle"></i> Returning visitors indicate brand loyalty and satisfaction</p>
                    </div>

                    <!-- Entry Pages -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Top Entry Pages</h2></div>
                        <p class="va-note">Where visitors first land on your site</p>
                        <?php if (empty($entry_pages)): ?>
                            <p class="rh-empty">No entry page data</p>
                        <?php else: ?>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach (array_slice($entry_pages, 0, 5) as $ep): ?>
                                    <li style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 13px; color: #333; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <i class="fas fa-link" style="color: var(--gold);"></i> <?php echo htmlspecialchars($ep['page_url']); ?>
                                        </span>
                                        <strong style="color: var(--gold); margin-left: 12px;"><?php echo number_format($ep['sessions']); ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Exit Pages -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Top Exit Pages</h2></div>
                        <p class="va-note">Where visitors leave your site</p>
                        <?php if (empty($exit_pages)): ?>
                            <p class="rh-empty">No exit page data</p>
                        <?php else: ?>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach (array_slice($exit_pages, 0, 5) as $exp): ?>
                                    <li style="padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 13px; color: #333; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <i class="fas fa-link" style="color: #f44336;"></i> <?php echo htmlspecialchars($exp['page_url']); ?>
                                        </span>
                                        <strong style="color: #f44336; margin-left: 12px;"><?php echo number_format($exp['sessions']); ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="font-size: 12px; color: #666; margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                                <i class="fas fa-lightbulb" style="color: #FF9800;"></i> High exit rates may indicate content gaps or technical issues
                            </p>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Peak Booking Hours -->
                <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Peak Booking Hours</h2></div>
                    <p class="va-note">When guests actually complete bookings - optimize your marketing campaigns around these times</p>
                    <?php $max_booking_hourly = max(1, max($booking_hours)); ?>
                    <div class="hourly-chart">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <div class="hourly-bar" style="height: <?php echo max(2, ($booking_hours[$h] / $max_booking_hourly) * 100); ?>%; background: linear-gradient(to top, #766550, #8F6A35);">
                                <span class="tooltip"><?php echo sprintf('%02d:00', $h); ?> - <?php echo $booking_hours[$h]; ?> bookings</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="hourly-labels">
                        <span>12am</span><span>3am</span><span>6am</span><span>9am</span>
                        <span>12pm</span><span>3pm</span><span>6pm</span><span>9pm</span>
                    </div>
                </div>

                <!-- Room Interest & Geographic Opportunities -->
                <div class="va-grid">

                    <!-- Most Viewed Rooms -->
                    <?php if (!empty($room_views)): ?>
                        <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Most Viewed Rooms</h2></div>
                            <p class="va-note">Rooms attracting the most interest</p>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach (array_slice($room_views, 0, 5) as $rv):
                                    $room_id = '';
                                    if (preg_match('/room\.php\?id=(\d+)/', $rv['page_url'], $matches)) {
                                        $room_id = $matches[1];
                                    }
                                ?>
                                    <li style="padding: 12px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; color: #333; margin-bottom: 4px;">
                                                Room #<?php echo htmlspecialchars($room_id ?: 'Unknown'); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #999;">
                                                <?php echo number_format($rv['views']); ?> views • <?php echo number_format($rv['unique_visitors']); ?> unique
                                            </div>
                                        </div>
                                        <i class="fas fa-fire" style="color: #FF9800; font-size: 18px;"></i>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Geographic Opportunities -->
                    <?php if (!empty($geo_opportunities)): ?>
                        <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Geographic Opportunities</h2></div>
                            <p class="va-note">High-traffic countries - potential for targeted campaigns</p>
                            <ul style="list-style: none; padding: 0; margin: 0;">
                                <?php foreach (array_slice($geo_opportunities, 0, 6) as $geo):
                                    $conv_rate = $geo['visitor_sessions'] > 0 ? round(($geo['bookings'] / $geo['visitor_sessions']) * 100, 1) : 0;
                                    $label = normalizeCountryLabel($geo['country']);
                                ?>
                                    <li style="padding: 12px; border-bottom: 1px solid #eee;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                            <strong style="color: #333;"><?php echo htmlspecialchars($label); ?></strong>
                                            <span style="font-size: 12px; padding: 3px 8px; background: <?php echo $conv_rate > 5 ? '#4CAF50' : '#FF9800'; ?>; color: #fff; border-radius: 10px;">
                                                <?php echo $conv_rate; ?>% conversion
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #666;">
                                            <?php echo number_format($geo['visitor_sessions']); ?> sessions • <?php echo number_format($geo['bookings']); ?> bookings
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Engagement Metric -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Engagement</h2></div>
                        <p class="va-note">How deeply visitors explore your site</p>
                        <table class="rh-kv no-auto-pagination">
                            <tbody>
                                <tr><th scope="row">Pages per session</th><td><?php echo number_format($avg_pages_per_session, 1); ?></td></tr>
                                <tr><th scope="row">Assessment</th><td>
                                    <?php if ($avg_pages_per_session >= 3): ?>
                                        <span class="rh-pill rh-pill--ok">Excellent engagement</span>
                                    <?php elseif ($avg_pages_per_session >= 2): ?>
                                        <span class="rh-pill rh-pill--warn">Good engagement</span>
                                    <?php else: ?>
                                        <span class="rh-pill rh-pill--muted">Room for improvement</span>
                                    <?php endif; ?>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>

                </div>

                <!-- Breakdowns -->
                <div class="analytics-grid">
                    <!-- Country Breakdown -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Countries</h2></div>
                        <?php if (empty($country_breakdown)): ?>
                            <p class="rh-empty">No country data yet</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_country = max(1, array_sum(array_column($country_breakdown, 'count')));
                                foreach ($country_breakdown as $c):
                                    $pct = round(($c['count'] / $total_country) * 100);
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <span><?php echo htmlspecialchars($c['country']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo number_format($c['count']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Device Breakdown -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Devices</h2></div>
                        <?php if (empty($devices)): ?>
                            <p class="rh-empty">No data yet</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_device = max(1, array_sum(array_column($devices, 'count')));
                                foreach ($devices as $d):
                                    $pct = round(($d['count'] / $total_device) * 100);
                                    $icons = ['desktop' => 'fa-desktop', 'mobile' => 'fa-mobile-alt', 'tablet' => 'fa-tablet-alt', 'bot' => 'fa-robot', 'unknown' => 'fa-question-circle'];
                                    $icon = $icons[$d['device_type']] ?? 'fa-question-circle';
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <i class="fas <?php echo $icon; ?>" style="color: var(--gold); width: 20px;"></i>
                                            <span><?php echo ucfirst($d['device_type']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo $d['count']; ?> (<?php echo $pct; ?>%)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Browser Breakdown -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Browsers</h2></div>
                        <?php if (empty($browsers)): ?>
                            <p class="rh-empty">No data yet</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_browser = max(1, array_sum(array_column($browsers, 'count')));
                                foreach ($browsers as $b):
                                    $pct = round(($b['count'] / $total_browser) * 100);
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <span><?php echo htmlspecialchars($b['browser']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo $b['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- OS Breakdown -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Operating Systems</h2></div>
                        <?php if (empty($operating_systems)): ?>
                            <p class="rh-empty">No data yet</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_os = max(1, array_sum(array_column($operating_systems, 'count')));
                                foreach ($operating_systems as $o):
                                    $pct = round(($o['count'] / $total_os) * 100);
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <span><?php echo htmlspecialchars($o['os']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo $o['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Top Referrers -->
                    <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Top Referrers</h2></div>
                        <?php if (empty($referrers)): ?>
                            <p class="rh-empty">No referrer data yet</p>
                        <?php else: ?>
                            <ul class="breakdown-list">
                                <?php
                                $total_ref = max(1, array_sum(array_column($referrers, 'count')));
                                foreach ($referrers as $r):
                                    $pct = round(($r['count'] / $total_ref) * 100);
                                ?>
                                    <li>
                                        <div class="breakdown-bar">
                                            <span><?php echo htmlspecialchars($r['referrer_domain']); ?></span>
                                            <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <span class="breakdown-count"><?php echo $r['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Most Visited Sections -->
                <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Most Visited Sections</h2></div>
                    <div class="table-wrapper">
                        <table class="visitors-table tablet-table no-scroll">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Views</th>
                                    <th>Unique Sessions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_sections)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999; padding: 40px;">No section data yet</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_sections as $section): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($section['section']); ?></td>
                                            <td><strong><?php echo number_format($section['views']); ?></strong></td>
                                            <td><?php echo number_format($section['unique_views']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Visitor IPs -->
                <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Top Visitor IPs</h2></div>
                    <div class="table-wrapper">
                        <table class="visitors-table tablet-table no-scroll">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Country</th>
                                    <th>Views</th>
                                    <th>Sessions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_ips)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: #999; padding: 40px;">No IP data yet</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_ips as $ip_row): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($ip_row['ip_address']); ?></code></td>
                                            <td><?php echo htmlspecialchars(normalizeCountryLabel($ip_row['country'] ?? null)); ?></td>
                                            <td><strong><?php echo number_format((int)$ip_row['views']); ?></strong></td>
                                            <td><?php echo number_format((int)$ip_row['sessions']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Pages -->
                <div class="rh-panel"><div class="rh-panel__head"><h2 class="rh-panel__title">Top Pages</h2></div>
                    <div class="table-wrapper">
                        <table class="visitors-table tablet-table no-scroll">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Views</th>
                                    <th>Unique</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_pages)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999; padding: 40px;">No page data yet</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_pages as $pg): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($pg['page_url']); ?></td>
                                            <td><strong><?php echo number_format($pg['views']); ?></strong></td>
                                            <td><?php echo number_format($pg['unique_views']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Visitors Log -->
                <div class="rh-panel" id="visitor-log-container"><div class="rh-panel__head"><h2 class="rh-panel__title">Recent Visitor Log</h2></div>
                    <div class="table-wrapper">
                        <table class="visitors-table tablet-table no-scroll">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Visits</th>
                                    <th>Latest Time</th>
                                    <th>Country</th>
                                    <th>Device</th>
                                    <th>Browser</th>
                                    <th>OS</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($grouped_visitors)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: #999; padding: 40px;">No visitor data for this period</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($grouped_visitors as $ip => $visits):
                                        $latest = $visits[0]; // Most recent visit
                                        $visit_count = count($visits);
                                    ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($ip); ?></code></td>
                                            <td><strong><?php echo $visit_count; ?></strong> page<?php echo $visit_count > 1 ? 's' : ''; ?></td>
                                            <td style="white-space: nowrap;"><?php echo date('H:i:s', strtotime($latest['created_at'])); ?><br><small style="color:#999;"><?php echo date('M j', strtotime($latest['created_at'])); ?></small></td>
                                            <td><?php echo htmlspecialchars($latest['country_display'] ?? 'Unknown'); ?></td>
                                            <td><span class="device-badge device-<?php echo $latest['device_type']; ?>"><?php echo ucfirst($latest['device_type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($latest['browser']); ?></td>
                                            <td><?php echo htmlspecialchars($latest['os']); ?></td>
                                            <td>
                                                <details style="cursor: pointer;">
                                                    <summary style="color: var(--color-primary, #766550); font-weight: 500; list-style: none; display: flex; align-items: center; gap: 6px;">
                                                        <i class="fas fa-chevron-right" style="transition: transform 0.2s; font-size: 10px;"></i>
                                                        View Pages
                                                    </summary>
                                                    <div style="margin-top: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                                                        <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                                                            <thead style="background: #fff; position: sticky; top: 0; z-index: 2;">
                                                                <tr>
                                                                    <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd;">Time</th>
                                                                    <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd;">Page URL</th>
                                                                    <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd;">Referrer</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($visits as $v): ?>
                                                                    <tr>
                                                                        <td style="padding: 6px 8px; white-space: nowrap; border-bottom: 1px solid #eee;"><?php echo date('H:i:s', strtotime($v['created_at'])); ?></td>
                                                                        <td style="padding: 6px 8px; border-bottom: 1px solid #eee; word-break: break-word;"><?php echo htmlspecialchars($v['page_url']); ?></td>
                                                                        <td style="padding: 6px 8px; border-bottom: 1px solid #eee; word-break: break-word; max-width: 200px;"><?php echo htmlspecialchars($v['referrer_domain'] ?: '-'); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page_num > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page_num - 1])); ?>">&laquo; Prev</a>
                            <?php endif; ?>
                            <a class="active" href="#">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?></a>
                            <?php if ($page_num < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page_num + 1])); ?>">Next &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleCustomDates(val) {
            document.getElementById('customDates').style.display = val === 'custom' ? 'flex' : 'none';
        }

        // ========== INTERACTIVE ENHANCEMENTS ==========
        document.addEventListener('DOMContentLoaded', function() {

            // Rotate chevron icon when details is opened/closed
            document.querySelectorAll('details').forEach(function(details) {
                details.addEventListener('toggle', function() {
                    const icon = this.querySelector('summary i.fa-chevron-right');
                    if (icon) {
                        icon.style.transform = this.open ? 'rotate(90deg)' : 'rotate(0deg)';
                    }
                });
            });

            // Add hover effects to all funnel steps
            document.querySelectorAll('.analytics-card > div > div[style*="flex: 1"]').forEach(function(step) {
                step.style.transition = 'all 0.3s ease';
                step.style.cursor = 'pointer';

                step.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px)';
                    this.style.boxShadow = '0 8px 16px rgba(0,0,0,0.15)';
                });

                step.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });

            // Make breakdown list items interactive
            document.querySelectorAll('.breakdown-list li').forEach(function(item) {
                item.style.transition = 'background 0.2s ease, padding-left 0.2s ease';
                item.style.cursor = 'pointer';

                item.addEventListener('mouseenter', function() {
                    this.style.background = 'rgba(138, 119, 95, 0.05)';
                    this.style.paddingLeft = '12px';
                });

                item.addEventListener('mouseleave', function() {
                    this.style.background = 'transparent';
                    this.style.paddingLeft = '0';
                });
            });

            // Add tooltips to hourly bars
            document.querySelectorAll('.hourly-bar').forEach(function(bar) {
                const tooltip = bar.querySelector('.tooltip');
                if (!tooltip) return;

                bar.addEventListener('mouseenter', function() {
                    tooltip.style.display = 'block';
                    tooltip.style.opacity = '1';
                    bar.style.opacity = '0.8';
                });

                bar.addEventListener('mouseleave', function() {
                    tooltip.style.display = 'none';
                    tooltip.style.opacity = '0';
                    bar.style.opacity = '1';
                });
            });

            // Make stat cards interactive
            document.querySelectorAll('.stat-card').forEach(function(card) {
                card.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                card.style.cursor = 'pointer';

                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.boxShadow = '0 12px 24px rgba(0,0,0,0.15)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '';
                });

                // Add click to copy value
                card.addEventListener('click', function() {
                    const value = this.querySelector('.stat-value')?.textContent;
                    const label = this.querySelector('.stat-label')?.textContent;
                    if (value && label) {
                        copyToClipboard(label + ': ' + value);
                        showToast('📋 Copied: ' + label, 'success');
                    }
                });
            });

            // Make analytics cards slightly interactive
            document.querySelectorAll('.analytics-card').forEach(function(card) {
                card.style.transition = 'box-shadow 0.3s ease';

                card.addEventListener('mouseenter', function() {
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.boxShadow = '';
                });
            });

            // Add click-to-copy for IP addresses and codes
            document.querySelectorAll('td code, .breakdown-list code').forEach(function(code) {
                code.style.cursor = 'pointer';
                code.title = 'Click to copy';
                code.style.transition = 'background 0.2s ease';

                code.addEventListener('click', function(e) {
                    e.stopPropagation();
                    copyToClipboard(this.textContent);
                    showToast('📋 Copied: ' + this.textContent, 'success');

                    // Visual feedback
                    const original = this.style.background;
                    this.style.background = 'rgba(76, 175, 80, 0.2)';
                    setTimeout(() => {
                        this.style.background = original;
                    }, 500);
                });
            });

            // Enhanced interaction for list items with session data
            document.querySelectorAll('.analytics-card ul li').forEach(function(li) {
                if (li.querySelector('strong') && li.textContent.includes('sessions')) {
                    li.style.transition = 'all 0.2s ease';
                    li.style.cursor = 'pointer';
                    li.style.borderRadius = '4px';

                    li.addEventListener('mouseenter', function() {
                        this.style.background = 'rgba(138, 119, 95, 0.08)';
                        this.style.paddingLeft = '16px';
                        this.style.paddingRight = '16px';
                    });

                    li.addEventListener('mouseleave', function() {
                        this.style.background = 'transparent';
                        this.style.paddingLeft = '12px';
                        this.style.paddingRight = '12px';
                    });
                }
            });

            // Make traffic source cards interactive with hover effects
            document.querySelectorAll('.traffic-source-card').forEach(function(card) {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-6px)';
                    this.style.boxShadow = '0 12px 24px rgba(0,0,0,0.2)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '';
                });
            });

            // Animate numbers on first load
            animateNumbers();

            // Make device badges clickable to filter by device
            document.querySelectorAll('.device-badge').forEach(function(badge) {
                badge.style.cursor = 'pointer';
                badge.style.transition = 'transform 0.2s ease, box-shadow 0.2s ease';
                badge.title = 'Click to filter by this device type';

                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.1)';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.2)';
                });

                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    this.style.boxShadow = '';
                });

                badge.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const device = this.textContent.toLowerCase().trim();
                    const url = new URL(window.location);
                    url.searchParams.set('device', device);
                    url.searchParams.set('page', '1'); // Reset to page 1
                    window.location = url.toString();
                });
            });

            // Make table rows interactive
            document.querySelectorAll('.visitors-table tbody tr').forEach(function(row) {
                if (!row.textContent.includes('No visitor data') && !row.textContent.includes('No data yet')) {
                    row.style.transition = 'background 0.2s ease';

                    row.addEventListener('mouseenter', function() {
                        this.style.background = 'rgba(138, 119, 95, 0.05)';
                    });

                    row.addEventListener('mouseleave', function() {
                        this.style.background = '';
                    });
                }
            });

            // Make conversion rate badges interactive
            document.querySelectorAll('[style*="border-radius: 10px"]').forEach(function(badge) {
                if (badge.textContent.includes('%')) {
                    badge.style.cursor = 'pointer';
                    badge.style.transition = 'transform 0.2s ease';
                    badge.title = 'Conversion rate';

                    badge.addEventListener('mouseenter', function() {
                        this.style.transform = 'scale(1.1)';
                    });

                    badge.addEventListener('mouseleave', function() {
                        this.style.transform = 'scale(1)';
                    });
                }
            });

            // Add filter info banner
            const filterBar = document.querySelector('.filter-bar');
            if (filterBar) {
                const rangeSelect = filterBar.querySelector('select[name="range"]');
                const deviceSelect = filterBar.querySelector('select[name="device"]');

                if (rangeSelect || deviceSelect) {
                    const banner = document.createElement('div');
                    banner.style.cssText = 'position: fixed; bottom: 20px; left: 20px; background: rgba(138, 119, 95, 0.95); color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); z-index: 1000; display: none;';
                    banner.innerHTML = '<i class="fas fa-filter"></i> <strong>Active Filters:</strong> <span id="activeFilters"></span>';
                    document.body.appendChild(banner);

                    const updateFilterBanner = function() {
                        const range = rangeSelect.options[rangeSelect.selectedIndex].text;
                        const device = deviceSelect.value;
                        const filters = [];
                        if (range !== 'Last 7 Days') filters.push(range);
                        if (device && device !== 'all') filters.push(device.charAt(0).toUpperCase() + device.slice(1));

                        if (filters.length > 0) {
                            document.getElementById('activeFilters').textContent = filters.join(' • ');
                            banner.style.display = 'block';
                        } else {
                            banner.style.display = 'none';
                        }
                    };

                    updateFilterBanner();
                    rangeSelect?.addEventListener('change', updateFilterBanner);
                    deviceSelect?.addEventListener('change', updateFilterBanner);
                }
            }
        });

        // AJAX pagination for visitor log — only replace the table section, no full page reload
        document.addEventListener('click', function(e) {
            const link = e.target.closest('#visitor-log-container .pagination a');
            if (!link || !link.href) return;
            e.preventDefault();
            const container = document.getElementById('visitor-log-container');
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            fetch(link.href, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const fresh = doc.getElementById('visitor-log-container');
                    if (fresh) {
                        container.innerHTML = fresh.innerHTML;
                        // Re-attach chevron toggle for newly loaded details elements
                        container.querySelectorAll('details').forEach(function(d) {
                            d.addEventListener('toggle', function() {
                                const icon = this.querySelector('summary i.fa-chevron-right');
                                if (icon) icon.style.transform = this.open ? 'rotate(90deg)' : 'rotate(0)';
                            });
                        });
                        // Re-attach row hover
                        container.querySelectorAll('.visitors-table tbody tr').forEach(function(row) {
                            row.addEventListener('mouseenter', function() {
                                this.style.background = 'rgba(138,119,95,0.05)';
                            });
                            row.addEventListener('mouseleave', function() {
                                this.style.background = '';
                            });
                        });
                    }
                })
                .catch(function() {
                    window.location = link.href;
                })
                .finally(function() {
                    container.style.opacity = '1';
                    container.style.pointerEvents = '';
                });
        });

        // Utility: Copy to clipboard
        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).catch(function() {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Copy failed:', err);
            }
            document.body.removeChild(textarea);
        }

        // Utility: Show toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.textContent = message;
            toast.style.cssText = 'position: fixed; bottom: 24px; right: 24px; background: ' +
                (type === 'success' ? '#4CAF50' : '#333') +
                '; color: white; padding: 12px 20px; border-radius: 6px; font-size: 14px; ' +
                'box-shadow: 0 4px 12px rgba(0,0,0,0.2); z-index: 10000; animation: slideIn 0.3s ease;';

            document.body.appendChild(toast);

            setTimeout(function() {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(function() {
                    document.body.removeChild(toast);
                }, 300);
            }, 2500);
        }

        // Utility: Animate numbers on page load
        function animateNumbers() {
            document.querySelectorAll('.stat-value, .analytics-card [style*="font-size: 32px"], .analytics-card [style*="font-size: 48px"]').forEach(function(el) {
                const text = el.textContent.trim();
                const match = text.match(/[\d,]+/);
                if (match) {
                    const num = parseInt(match[0].replace(/,/g, ''), 10);
                    if (isNaN(num) || num === 0) return;

                    let current = 0;
                    const increment = Math.ceil(num / 30);
                    const timer = setInterval(function() {
                        current += increment;
                        if (current >= num) {
                            current = num;
                            clearInterval(timer);
                        }
                        el.textContent = text.replace(/[\d,]+/, current.toLocaleString());
                    }, 30);
                }
            });
        }

        // Add CSS animations and styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(400px); opacity: 0; }
            }
            .hourly-bar {
                position: relative;
                transition: opacity 0.2s ease, transform 0.2s ease !important;
            }
            .hourly-bar:hover {
                transform: scaleY(1.05);
                filter: brightness(1.1);
            }
            .hourly-bar .tooltip {
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.9);
                color: white;
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 12px;
                white-space: nowrap;
                pointer-events: none;
                display: none;
                opacity: 0;
                transition: opacity 0.2s ease;
                z-index: 1000;
                margin-bottom: 8px;
            }
            .hourly-bar .tooltip::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 5px solid transparent;
                border-top-color: rgba(0, 0, 0, 0.9);
            }
            code:hover {
                background: rgba(138, 119, 95, 0.15) !important;
            }
            details summary {
                transition: color 0.2s ease;
            }
            details summary:hover {
                color: #8F6A35 !important;
            }
            details summary i {
                transition: transform 0.2s ease;
            }
            .stat-card {
                position: relative;
                overflow: hidden;
            }
            .stat-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, var(--gold, #766550), transparent);
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .stat-card:hover::before {
                transform: translateX(0);
            }
            .device-badge {
                user-select: none;
            }
        `;
        document.head.appendChild(style);
    </script>
    <script src="js/admin-components.js"></script>
    <?php require_once 'includes/admin-footer.php'; ?>

