<?php
/**
 * Lightweight endpoint used by guide-init.js to fetch hotel branding from the DB.
 * Returns: { "site_name": "...", "site_short_name": "..." }
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: same-origin');

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/cache.php';

    echo json_encode([
        'site_name'       => getSetting('site_name')       ?: 'Hotel',
        'site_short_name' => getSetting('site_short_name') ?: 'Hotel',
    ]);
} catch (Throwable $e) {
    // Fail gracefully — guides fall back to their static text
    http_response_code(200);
    echo json_encode(['site_name' => '', 'site_short_name' => '']);
}
