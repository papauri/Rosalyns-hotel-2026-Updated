<?php
/**
 * Dynamic Sitemap Generator
 * Generates XML sitemap with all actual pages and database content
 */

require_once 'config/database.php';

header('Content-Type: application/xml; charset=utf-8');

// Get site URL from database, fallback to current host
$site_url = getSetting('site_url');
$base_url = $site_url ?: 'https://' . $_SERVER['HTTP_HOST'];
$current_date = date('Y-m-d');

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// Static pages
$static_pages = [
    ['url' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
    ['url' => '/booking.php', 'priority' => '1.0', 'changefreq' => 'daily'],
    ['url' => '/rooms-gallery.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['url' => '/rooms-showcase.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['url' => '/restaurant.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/gym.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/conference.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/events.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/check-availability.php', 'priority' => '0.7', 'changefreq' => 'daily'],
    ['url' => '/contact-us.php', 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['url' => '/guest-services.php', 'priority' => '0.7', 'changefreq' => 'monthly'],
];

foreach ($static_pages as $page) {
    echo '<url>';
    echo '<loc>' . htmlspecialchars($base_url . $page['url']) . '</loc>';
    echo '<lastmod>' . $current_date . '</lastmod>';
    echo '<changefreq>' . $page['changefreq'] . '</changefreq>';
    echo '<priority>' . $page['priority'] . '</priority>';
    echo '</url>';
}

// Dynamic room pages
try {
    $stmt = $pdo->query("SELECT slug, updated_at FROM rooms WHERE is_active = 1");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rooms as $room) {
        $lastmod = !empty($room['updated_at']) ? date('Y-m-d', strtotime($room['updated_at'])) : $current_date;
        
        echo '<url>';
        echo '<loc>' . htmlspecialchars($base_url . '/room.php?room=' . urlencode($room['slug'])) . '</loc>';
        echo '<lastmod>' . $lastmod . '</lastmod>';
        echo '<changefreq>weekly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
} catch (PDOException $e) {
    // Silently fail if database error
}

// Dynamic event pages
try {
    $stmt = $pdo->query("SELECT id, updated_at FROM events WHERE is_active = 1");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($events as $event) {
        $lastmod = !empty($event['updated_at']) ? date('Y-m-d', strtotime($event['updated_at'])) : $current_date;
        
        echo '<url>';
        echo '<loc>' . htmlspecialchars($base_url . '/event.php?id=' . $event['id']) . '</loc>';
        echo '<lastmod>' . $lastmod . '</lastmod>';
        echo '<changefreq>weekly</changefreq>';
        echo '<priority>0.6</priority>';
        echo '</url>';
    }
} catch (PDOException $e) {
    // Silently fail if database error
}

echo '</urlset>';
?>
