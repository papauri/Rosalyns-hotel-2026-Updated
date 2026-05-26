<?php
/**
 * Hotel Website - Site Settings API Endpoint
 *
 * Returns a curated set of public-safe site settings from database.
 * SECURITY: Only explicitly listed keys are returned. Credentials,
 * API keys, SMTP passwords, and internal config are never exposed.
 *
 * RATE LIMITING: Max 30 requests per minute per IP to prevent abuse
 */

// Include database connection
require_once __DIR__ . '/../config/database.php';

// Set JSON response header
header('Content-Type: application/json');

// Rate limiting: max 30 requests per minute per IP
session_start();
$rate_key = 'api_site_settings';
if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = [];
}
$_SESSION[$rate_key] = array_filter($_SESSION[$rate_key], function($t) {
    return $t > time() - 60;
});
if (count($_SESSION[$rate_key]) >= 30) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded. Please try again in a moment.',
        'code' => 429
    ]);
    exit;
}
$_SESSION[$rate_key][] = time();

// Whitelist of setting keys that are safe to expose publicly.
// Never add: smtp_*, whatsapp_*, api_key*, *_password, *_secret, *_token
$PUBLIC_SETTING_KEYS = [
    'site_name', 'site_tagline', 'site_url', 'site_logo',
    'phone_main', 'phone_reservations',
    'email_main', 'email_reservations',
    'address_line1', 'address_line2', 'address_country', 'working_hours',
    'check_in_time', 'check_out_time', 'booking_change_policy',
    'currency_symbol', 'currency_code',
    'facebook_url', 'instagram_url', 'twitter_url', 'linkedin_url',
    'footer_credits', 'copyright_text',
];

try {
    // Fetch only the whitelisted keys
    $placeholders = implode(',', array_fill(0, count($PUBLIC_SETTING_KEYS), '?'));
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ({$placeholders}) ORDER BY setting_key"
    );
    $stmt->execute($PUBLIC_SETTING_KEYS);
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $s = [];
    foreach ($settings as $row) {
        $s[$row['setting_key']] = $row['setting_value'];
    }

    // Build structured public response
    $response = [
        'success' => true,
        'message' => 'Site settings retrieved successfully',
        'data' => [
            'hotel' => [
                'name'    => $s['site_name']    ?? 'Hotel Website',
                'tagline' => $s['site_tagline'] ?? '',
                'url'     => $s['site_url']     ?? '',
                'logo'    => $s['site_logo']    ?? '',
            ],
            'contact' => [
                'phone_main'         => $s['phone_main']         ?? '',
                'phone_reservations' => $s['phone_reservations'] ?? '',
                'email_main'         => $s['email_main']         ?? '',
                'email_reservations' => $s['email_reservations'] ?? '',
                'address_line1'      => $s['address_line1']      ?? '',
                'address_line2'      => $s['address_line2']      ?? '',
                'address_country'    => $s['address_country']    ?? '',
                'working_hours'      => $s['working_hours']      ?? '24/7 Available',
            ],
            'booking' => [
                'check_in_time'  => $s['check_in_time']         ?? '2:00 PM',
                'check_out_time' => $s['check_out_time']        ?? '11:00 AM',
                'change_policy'  => $s['booking_change_policy'] ?? '',
            ],
            'currency' => [
                'symbol' => $s['currency_symbol'] ?? 'MWK',
                'code'   => $s['currency_code']   ?? 'MWK',
            ],
            'social' => [
                'facebook'  => $s['facebook_url']  ?? '',
                'instagram' => $s['instagram_url'] ?? '',
                'twitter'   => $s['twitter_url']   ?? '',
                'linkedin'  => $s['linkedin_url']  ?? '',
            ],
            'legal' => [
                'copyright_text' => $s['footer_credits'] ?? $s['copyright_text']
                    ?? (date('Y') . ' ' . ($s['site_name'] ?? 'Hotel Website') . '. All rights reserved.'),
            ],
        ],
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Site Settings API Error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve site settings',
        'code' => 500
    ]);
}
