<?php

/**
 * manifest.php — Dynamic Web App Manifest for Rosalyn's Hotel public website.
 * Served as application/manifest+json, pulling site_name + logo from site_settings.
 */
require_once 'config/database.php';
require_once 'config/base-url.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$name       = getSetting('site_name') ?: 'Hotel';
$short_name = getSetting('site_short_name') ?: getSetting('site_name') ?: 'Hotel';
$logo       = getSetting('site_logo') ?: '/images/logo/logo.png';
// Ensure absolute URL for icon src
$icon_url   = (strpos($logo, 'http') === 0)
    ? $logo
    : rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');

$manifest = [
    'name'             => $name,
    'short_name'       => $short_name,
    'description'      => getSetting('site_tagline') ?: 'Luxury hotel.',
    'start_url'        => '/',
    'scope'            => '/',
    'display'          => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation'      => 'any',
    'background_color' => '#F7F3EE',
    'theme_color'      => '#8A775F',
    'lang'             => 'en',
    'icons'            => [
        [
            'src'     => $icon_url,
            'sizes'   => 'any',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name'      => 'Book a Room',
            'short_name' => 'Book',
            'url'       => '/booking.php',
            'icons'     => [['src' => $icon_url, 'sizes' => 'any']],
        ],
        [
            'name'      => 'Our Rooms',
            'short_name' => 'Rooms',
            'url'       => '/rooms-showcase.php',
            'icons'     => [['src' => $icon_url, 'sizes' => 'any']],
        ],
    ],
    'categories'   => ['travel', 'food', 'lifestyle'],
    'screenshots'  => [],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
