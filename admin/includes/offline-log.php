<?php
/**
 * offline-log.php — server-side helper used by endpoints that accept offline-queued POSTs.
 *
 *  Endpoints (POS park/place_order, payments, KDS bumps, void, etc.) call
 *  rh_log_offline_replay() whenever a POST arrives with a `client_uuid` AND a
 *  `client_queued_at` (which the offline-queue.js injects only when the form
 *  was actually queued offline). Online submissions skip logging.
 *
 *  The helper is idempotent on (client_uuid, endpoint) — replaying the same
 *  queued request will not insert duplicate rows.
 */

if (!function_exists('rh_log_offline_replay')) {
    /**
     * @param PDO    $pdo
     * @param string $endpoint   e.g. 'pos.php?action=park' or '/api/void-order.php'
     * @param array  $opts       optional: action, entity_type, entity_id, entity_reference,
     *                           response_status, response_summary, details
     * @return int|null  inserted row id (or null if not an offline replay / duplicate)
     */
    function rh_log_offline_replay(PDO $pdo, string $endpoint, array $opts = []): ?int
    {
        $clientUuid = $_POST['client_uuid'] ?? $_REQUEST['client_uuid'] ?? null;
        $queuedAt   = $_POST['client_queued_at'] ?? $_REQUEST['client_queued_at'] ?? null;
        // Only log if both markers are present — otherwise it's a normal online submission.
        if (!$clientUuid || !$queuedAt) { return null; }
        $clientUuid = substr(trim((string)$clientUuid), 0, 64);
        $queuedAt   = substr(trim((string)$queuedAt), 0, 19);
        if (strlen($clientUuid) < 6) { return null; }
        // Validate timestamp loosely (YYYY-MM-DD HH:MM:SS)
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $queuedAt)) { $queuedAt = null; }

        $userId   = $_SESSION['admin_user_id']  ?? null;
        $username = $_SESSION['admin_username'] ?? null;

        // De-dupe on (client_uuid, endpoint)
        try {
            $chk = $pdo->prepare("SELECT id FROM offline_replay_log WHERE client_uuid = ? AND endpoint = ? LIMIT 1");
            $chk->execute([$clientUuid, $endpoint]);
            if ($id = $chk->fetchColumn()) { return (int)$id; }
        } catch (Throwable $e) { return null; }

        try {
            $st = $pdo->prepare("INSERT INTO offline_replay_log
                (client_uuid, user_id, username, endpoint, action, entity_type, entity_id, entity_reference, client_queued_at, replayed_at, response_status, response_summary, details_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
            $st->execute([
                $clientUuid,
                $userId ? (int)$userId : null,
                $username ? substr((string)$username, 0, 64) : null,
                substr($endpoint, 0, 255),
                isset($opts['action']) ? substr((string)$opts['action'], 0, 64) : null,
                isset($opts['entity_type']) ? substr((string)$opts['entity_type'], 0, 48) : null,
                isset($opts['entity_id']) ? (int)$opts['entity_id'] : null,
                isset($opts['entity_reference']) ? substr((string)$opts['entity_reference'], 0, 64) : null,
                $queuedAt,
                isset($opts['response_status']) ? (int)$opts['response_status'] : 200,
                isset($opts['response_summary']) ? substr((string)$opts['response_summary'], 0, 500) : null,
                isset($opts['details']) ? json_encode($opts['details'], JSON_UNESCAPED_SLASHES) : null,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[offline-log] insert failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rh_stamp_order_offline')) {
    /**
     * Stamps stock_orders.offline_queued_at + offline_replayed_at for an order
     * created from an offline-queued POST. Safe to call even if the order has
     * no client_queued_at (then it just stamps replayed_at = NOW for the row).
     */
    function rh_stamp_order_offline(PDO $pdo, int $orderId): void
    {
        $queuedAt = $_POST['client_queued_at'] ?? $_REQUEST['client_queued_at'] ?? null;
        $clientUuid = $_POST['client_uuid'] ?? $_REQUEST['client_uuid'] ?? null;
        if (!$clientUuid || !$queuedAt) { return; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', substr($queuedAt, 0, 19))) { return; }
        try {
            $st = $pdo->prepare("UPDATE stock_orders SET offline_queued_at = ?, offline_replayed_at = NOW() WHERE id = ? AND offline_replayed_at IS NULL");
            $st->execute([substr($queuedAt, 0, 19), $orderId]);
        } catch (Throwable $e) { error_log('[offline-log] stamp failed: ' . $e->getMessage()); }
    }
}

