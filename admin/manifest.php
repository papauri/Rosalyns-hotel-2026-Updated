<?php

/**
 * admin/manifest.php — Dynamic Web App Manifest for Rosalyn's Hotel Admin.
 * Served as application/manifest+json, pulling site_name + logo from site_settings.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/base-url.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$name       = getSetting('site_name') ?: 'Hotel';
$short_name = getSetting('site_short_name') ?: getSetting('site_name') ?: 'Hotel';
$logo       = getSetting('site_logo') ?: '/images/logo/logo.png';
$icon_url   = (strpos($logo, 'http') === 0)
    ? $logo
    : rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');

$manifest = [
    'name'             => $name . ' Admin',
    'short_name'       => $short_name,
    'description'      => $name . ' — admin, POS, KDS & operations.',
    'id'               => '/admin/',
    'start_url'        => '/admin/pos.php',
    'scope'            => '/admin/',
    'display'          => 'fullscreen',
    'orientation'      => 'any',
    'background_color' => '#1f1f24',
    'theme_color'      => '#8A775F',
    'lang'             => 'en',
    'icons'            => [
        ['src' => $icon_url, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon_url, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon_url, 'sizes' => 'any',     'type' => 'image/png', 'purpose' => 'any'],
    ],
    'shortcuts' => [
        [
            'name'      => 'POS Till',
            'short_name' => 'POS',
            'url'       => '/admin/pos.php',
            'icons'     => [['src' => $icon_url, 'sizes' => 'any']],
        ],
        [
            'name'      => 'Kitchen Display',
            'short_name' => 'KDS',
            'url'       => '/admin/kds.php',
            'icons'     => [['src' => $icon_url, 'sizes' => 'any']],
        ],
        [
            'name'      => 'Dashboard',
            'short_name' => 'Dash',
            'url'       => '/admin/dashboard.php',
            'icons'     => [['src' => $icon_url, 'sizes' => 'any']],
        ],
    ],
    'categories' => ['business', 'productivity'],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
