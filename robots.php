<?php
/**
 * Dynamic Robots.txt Generator
 * Generates robots.txt content from database settings
 * 
 * This file creates a dynamic robots.txt that uses the site URL
 * from the database instead of hardcoded values.
 */

// Set content type to plain text
header('Content-Type: text/plain; charset=UTF-8');

// Get base URL from database or use current domain
$site_url = '';
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    
    if (function_exists('getSetting')) {
        $site_url = getSetting('site_url', '');
    }
}

// If no site URL in database, use current domain
if (empty($site_url)) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $site_url = $protocol . '://' . $host;
}

// Ensure URL doesn't end with slash
$site_url = rtrim($site_url, '/');

// Output robots.txt content
echo "User-agent: *\n";
echo "Disallow: /admin/\n";
echo "Disallow: /private/\n";
echo "Disallow: /tmp/\n";
echo "Disallow: /cache/\n";
echo "Disallow: /sessions/\n";
echo "Disallow: /logs/\n";
echo "Disallow: /config/\n";
echo "Disallow: /Database/\n";
echo "Disallow: /scripts/\n";
echo "Disallow: /templates/\n";
echo "Disallow: /invoices/\n";
echo "Disallow: /plans/\n";
echo "Disallow: /.git/\n";
echo "\n";
echo "Sitemap: {$site_url}/sitemap.xml\n";
?>
