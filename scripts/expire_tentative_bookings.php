<?php
/**
 * scripts/expire_tentative_bookings.php
 *
 * Sweep tentative bookings whose tentative_expires_at has passed and mark
 * them as 'expired'. Releases the room from blocking-status so the inventory
 * is available again.
 *
 * Run from cron every 15 minutes:
 *   *\/15 * * * *  /usr/bin/php /path/to/scripts/expire_tentative_bookings.php >> /path/to/logs/tentative-expiry.log 2>&1
 *
 * CLI-only by default. Refuses to run from a web request unless
 * ?web=1 with a valid admin session is supplied (kept off by default).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $__webRole = $_SESSION['admin_role'] ?? '';
    if (
        empty($_SESSION['admin_user_id']) ||
        !in_array($__webRole, ['admin', 'manager'], true) ||
        ($_GET['web'] ?? '') !== '1'
    ) {
        http_response_code(403);
        echo 'Forbidden — CLI only.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] Tentative-booking expiry sweep starting...\n";

$expired = getExpiredTentativeBookings();
$count = count($expired);
echo "Found {$count} expired tentative booking(s).\n";

$ok = 0;
$failed = 0;
foreach ($expired as $b) {
    $bid = (int)$b['id'];
    $ref = (string)($b['booking_reference'] ?? '');
    $hours = (int)($b['hours_since_expiration'] ?? 0);
    if (markTentativeBookingExpired($bid)) {
        $ok++;
        echo "  OK  #{$bid} {$ref} (expired {$hours}h ago)\n";
        // Best-effort guest + admin notification — never fail the sweep on email errors.
        try {
            if (function_exists('sendTentativeBookingExpiredEmail')) {
                @sendTentativeBookingExpiredEmail($b);
            }
            if (function_exists('sendAdminBookingExpiredNotification')) {
                @sendAdminBookingExpiredNotification($b, 'tentative');
            }
        } catch (Throwable $e) {
            error_log('expire_tentative_bookings notification failed for #' . $bid . ': ' . $e->getMessage());
        }
    } else {
        $failed++;
        echo "  ERR #{$bid} {$ref} — failed to mark expired\n";
    }
}

$finishedAt = date('Y-m-d H:i:s');
echo "[{$finishedAt}] Done. {$ok} expired, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
