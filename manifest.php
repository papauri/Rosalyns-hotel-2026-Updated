<?php

/**
 * manifest.php — Dynamic Web App Manifest for Rosalyn's Hotel public website.
 * Served as application/manifest+json, pulling site_name + logo from site_settings.
 */
require_once 'config/database.php';
require_once 'config/base-url.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');

$name       = getSetting('site_name') ?: 'Hotel';
$short_name = getSetting('site_short_name') ?: getSetting('site_name') ?: 'Hotel';
$logo       = getSetting('site_logo') ?: '/images/logo/logo.png';

// Build absolute URL, resolving relative paths against BASE_URL
if (strpos($logo, 'http') === 0) {
    $icon_url = $logo;
} else {
    $icon_url = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    // Fall back to canonical logo path if the stored path doesn't exist on disk
    $disk_path = realpath(__DIR__ . '/' . ltrim($logo, '/'));
    if (!$disk_path || !file_exists($disk_path)) {
        $fallback = realpath(__DIR__ . '/images/logo/logo.png');
        if ($fallback && file_exists($fallback)) {
            $icon_url = rtrim(BASE_URL, '/') . '/images/logo/logo.png';
        }
    }
}

// Chrome/Android require at least one explicit 192px and one 512px entry to trigger
// the Add-to-Home-Screen / install prompt. A single "sizes: any" entry alone is
// insufficient. We emit all three: 192, 512, and the catch-all "any".
$siteRoot = rtrim(BASE_URL, '/') . '/';

$manifest = [
    'name'             => $name,
    'short_name'       => $short_name,
    'description'      => getSetting('site_tagline') ?: 'Luxury hotel.',
    'id'               => $siteRoot,
    'start_url'        => $siteRoot,
    'scope'            => $siteRoot,
    'display'          => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation'      => 'any',
    'background_color' => '#F7F3EE',
    'theme_color'      => '#8A775F',
    'lang'             => 'en',
    'icons'            => [
        [
            'src'     => $icon_url,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $icon_url,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => $icon_url,
            'sizes'   => 'any',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name'       => 'Book a Room',
            'short_name' => 'Book',
            'url'        => siteUrl('booking.php'),
            'icons'      => [['src' => $icon_url, 'sizes' => 'any']],
        ],
        [
            'name'       => 'Our Rooms',
            'short_name' => 'Rooms',
            'url'        => siteUrl('rooms-showcase.php'),
            'icons'      => [['src' => $icon_url, 'sizes' => 'any']],
        ],
    ],
    'categories'   => ['travel', 'food', 'lifestyle'],
    'screenshots'  => [],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
