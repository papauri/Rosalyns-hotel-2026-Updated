<?php

/**
 * api/licence-ping.php — the licence panel's nudge: "something changed for this business, check now".
 *
 * No authentication on purpose: the ping carries no data and grants nothing. The site then fetches and verifies the
 * panel's SIGNED answer itself (includes/licence.php), exactly like the hourly check. At most one check a minute, run
 * after this reply is sent. Answers the same thing whatever the licence state, so it reveals nothing.
 * Does nothing at all on a site that is not on a licence.
 */

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/licence.php';

try {
    rh_licence_nudge();
} catch (Throwable $e) {
    error_log('licence ping: ' . $e->getMessage());
}
http_response_code(202);
echo '{"ok":true}';
