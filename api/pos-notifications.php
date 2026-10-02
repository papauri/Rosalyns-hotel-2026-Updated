<?php

/**
 * api/pos-notifications.php — POS ready-order notification endpoint.
 *
 * Auth: admin session (pos_till or kds_view permission).
 * Method: POST
 * Actions:
 *   poll   — return unseen notifications for the current user + global POS notifications.
 *            Delivery is confirmed by the client, not assumed: see the note on the
 *            poll branch below.
 *   ack    — mark notification(s) as seen for this user {id} or {ids}.
 * Used by admin/pos.php to drive browser Notification + vibration when an order is ready.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/station-hours.php';
require_once __DIR__ . '/../admin/includes/permissions.php';
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

function pn_err(string $m, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}
function pn_ok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pn_err('POST only', 405);
if (empty($_SESSION['admin_user'])) pn_err('Not authenticated', 401);
$user = $_SESSION['admin_user'];
$userId = (int)$user['id'];

$csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!validateCsrfToken($csrfToken)) pn_err('Invalid CSRF token', 403);

/* Lazy-create notifications table if it doesn't exist yet */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_ready_notifications (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id     INT UNSIGNED NOT NULL,
        `reference`  VARCHAR(50)  NOT NULL DEFAULT '',
        table_label  VARCHAR(80)  NOT NULL DEFAULT '',
        station      VARCHAR(30)  NOT NULL DEFAULT 'kitchen',
        placed_by    INT UNSIGNED NULL COMMENT 'user_id who placed the order — gets vibrate flag',
        message      VARCHAR(255) NOT NULL DEFAULT '',
        for_role     VARCHAR(20)  NOT NULL DEFAULT 'all' COMMENT 'all | specific — all = broadcast to every POS session',
        seen_by      TEXT NULL COMMENT 'JSON array of user_ids that have already seen this',
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (order_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    error_log('pos-notifications: table create failed: ' . $e->getMessage());
}

$action = trim((string)($_POST['action'] ?? ($_GET['action'] ?? 'poll')));
if ($action === '') $action = 'poll';

if ($action === 'poll') {
    /* Whose orders this till follows: its own, or everyone's with pos_all_tabs. */
    $seesAllTabs = hasPermission($userId, 'pos_all_tabs');

    /* ── Change feed ────────────────────────────────────────────────────
     * Every KDS move (start, ready, collect, serve, void, recall) is a row in
     * stock_kds_events, and every order-level change (payment, void, edit) a row
     * in stock_order_audit. The till sends the last ids it saw; we return what
     * happened since, for the orders it follows, so My orders, Tabs, an open
     * tab and its timeline refresh within one poll instead of on reload. The
     * first poll (cursor 0) only learns where the cursors are. */
    $changes = ['kds_cursor' => 0, 'audit_cursor' => 0, 'orders' => [], 'items' => []];
    try {
        $sinceKds = max(0, (int)($_POST['since_kds'] ?? 0));
        $sinceAudit = max(0, (int)($_POST['since_audit'] ?? 0));
        $changes['kds_cursor'] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM stock_kds_events")->fetchColumn();
        $changes['audit_cursor'] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM stock_order_audit")->fetchColumn();
        $changedOrders = [];

        if ($sinceKds > 0 && $changes['kds_cursor'] > $sinceKds) {
            $ev = $pdo->prepare("SELECT e.id, e.order_id, e.order_item_id, e.event, e.to_status,
                                        o.reference, o.table_number, o.order_type, o.created_by,
                                        i.item_name, i.quantity, i.station,
                                        (SELECT COUNT(*) FROM stock_order_items x
                                          WHERE x.order_id = e.order_id AND x.station = i.station AND x.kds_status <> 'void') AS station_total,
                                        (SELECT COUNT(*) FROM stock_order_items x
                                          WHERE x.order_id = e.order_id AND x.station = i.station
                                            AND x.kds_status IN ('ready','collection','served')) AS station_ready
                                   FROM stock_kds_events e
                                   JOIN stock_orders o ON o.id = e.order_id
                              LEFT JOIN stock_order_items i ON i.id = e.order_item_id
                                  WHERE e.id > ? AND (o.created_by = ? OR ? = 1)
                               ORDER BY e.id ASC
                                  LIMIT 200");
            $ev->execute([$sinceKds, $userId, $seesAllTabs ? 1 : 0]);
            foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $changedOrders[(int)$row['order_id']] = true;
                if ($row['order_item_id'] === null || ($row['to_status'] ?? '') !== 'ready') continue;
                $changes['items'][] = [
                    'order_id'      => (int)$row['order_id'],
                    'reference'     => (string)$row['reference'],
                    'table_number'  => (string)($row['table_number'] ?? ''),
                    'order_type'    => (string)($row['order_type'] ?? ''),
                    'mine'          => (int)$row['created_by'] === $userId,
                    'item_name'     => (string)($row['item_name'] ?? ''),
                    'quantity'      => (float)($row['quantity'] ?? 1),
                    'station'       => (string)($row['station'] ?? 'kitchen'),
                    'station_total' => (int)$row['station_total'],
                    'station_ready' => (int)$row['station_ready'],
                ];
            }
        }

        if ($sinceAudit > 0 && $changes['audit_cursor'] > $sinceAudit) {
            $au = $pdo->prepare("SELECT DISTINCT a.order_id
                                   FROM stock_order_audit a
                                   JOIN stock_orders o ON o.id = a.order_id
                                  WHERE a.id > ? AND (o.created_by = ? OR ? = 1)
                                  LIMIT 200");
            $au->execute([$sinceAudit, $userId, $seesAllTabs ? 1 : 0]);
            foreach ($au->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                $changedOrders[(int)$oid] = true;
            }
        }
        $changes['orders'] = array_keys($changedOrders);
    } catch (Throwable $e) {
        error_log('pos-notifications changes: ' . $e->getMessage());
    }

    /* Return current business-day notifications that are still valid and unseen for this user. */
    try {
        $notificationWindow = rh_station_union_business_window();
        $windowStart = (string)$notificationWindow['start_sql'];
        $windowEnd = (string)$notificationWindow['end_sql'];
        $st = $pdo->prepare("SELECT n.id, n.order_id, n.`reference`, n.table_label, n.station, n.placed_by, n.message, n.seen_by,
                                (SELECT COUNT(*)
                                     FROM stock_order_items soi
                                    WHERE soi.order_id = n.order_id
                                        AND soi.station COLLATE utf8mb4_unicode_ci = n.station COLLATE utf8mb4_unicode_ci
                                        AND soi.kds_status <> 'void') AS item_count,
                                (SELECT GROUP_CONCAT(CONCAT(CAST(soi.quantity AS CHAR), 'x ', soi.item_name) ORDER BY soi.id SEPARATOR ', ')
                                     FROM stock_order_items soi
                                    WHERE soi.order_id = n.order_id
                                        AND soi.station COLLATE utf8mb4_unicode_ci = n.station COLLATE utf8mb4_unicode_ci
                                        AND soi.kds_status <> 'void') AS items_summary
                        FROM pos_ready_notifications n
                        WHERE n.created_at >= ?
                            AND n.created_at < ?
                            AND NOT EXISTS (
                                        SELECT 1
                                            FROM stock_order_items pending
                                         WHERE pending.order_id = n.order_id
                                             AND pending.station COLLATE utf8mb4_unicode_ci = n.station COLLATE utf8mb4_unicode_ci
                                             AND pending.kds_status NOT IN ('ready','collection','served','void')
                            )
                        ORDER BY n.id ASC");
        $st->execute([$windowStart, $windowEnd]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $unseen = [];
        foreach ($rows as $row) {
            $seenBy = json_decode($row['seen_by'] ?? '[]', true) ?: [];
            if (in_array($userId, $seenBy, true)) continue; // already seen by this user
            /* "Ready" goes to the till of the person who placed the order, and to
               supervisors who follow every tab — not to every cashier on shift. */
            $placedBy = (int)($row['placed_by'] ?? 0);
            if ($placedBy > 0 && $placedBy !== $userId && !$seesAllTabs) continue;
            $unseen[] = [
                'id'          => (int)$row['id'],
                'order_id'    => (int)$row['order_id'],
                'reference'   => $row['reference'],
                'table_label' => $row['table_label'],
                'station'     => $row['station'],
                'message'     => $row['message'],
                'item_count'   => (int)($row['item_count'] ?? 0),
                'items_summary' => (string)($row['items_summary'] ?? ''),
                'vibrate'     => ((int)$row['placed_by'] === $userId), // vibrate only for the order's cashier
            ];
        }

        /* Deliberately NOT marked seen here.
         *
         * This branch used to stamp every returned row as seen before the response
         * left the server. "Your order is ready" is the one POS alert a runner must
         * not miss, and any response lost on the way back — tab closed mid-flight,
         * Wi-Fi drop on a tablet walking the floor, browser discarding a background
         * tab — destroyed that alert permanently: the row was seen, so no later poll
         * would ever return it again, and the food sat at the pass.
         *
         * Delivery is now confirmed by the client, which calls action=ack once the
         * card is actually on screen. Re-delivery in the meantime is harmless: the
         * till keeps its own localStorage seen-set keyed by order+station, so a
         * repeat arriving before the ack lands is suppressed there rather than
         * re-alerting the cashier. Rows stay bounded by the business-day window in
         * the query above, so nothing accumulates past the shift. */
        pn_ok(['notifications' => $unseen, 'changes' => $changes]);
    } catch (Throwable $e) {
        error_log('pos-notifications poll: ' . $e->getMessage());
        pn_err('Could not load notifications.', 500);
    }
}

if ($action === 'ack') {
    /* Accepts a single id or a comma-separated list so a batch of alerts rendered
     * together is confirmed in one round-trip rather than one request each. */
    $rawIds = trim((string)($_POST['ids'] ?? $_POST['id'] ?? ''));
    $nIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $rawIds)),
        static fn(int $v): bool => $v > 0
    )));
    if (!$nIds) pn_err('Missing id');
    if (count($nIds) > 100) $nIds = array_slice($nIds, 0, 100);
    try {
        $place = implode(',', array_fill(0, count($nIds), '?'));
        $sel = $pdo->prepare("SELECT id, seen_by FROM pos_ready_notifications WHERE id IN ($place)");
        $sel->execute($nIds);
        $existing = $sel->fetchAll(PDO::FETCH_KEY_PAIR);
        $upd = $pdo->prepare("UPDATE pos_ready_notifications SET seen_by=? WHERE id=?");
        $pdo->beginTransaction();
        try {
            foreach ($nIds as $nId) {
                if (!array_key_exists($nId, $existing)) continue;
                $current = json_decode($existing[$nId] ?? '[]', true) ?: [];
                if (!in_array($userId, $current, true)) {
                    $current[] = $userId;
                    $upd->execute([json_encode($current), $nId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pn_ok(['acked' => count($nIds)]);
    } catch (Throwable $e) {
        error_log('pos-notifications ack: ' . $e->getMessage());
        pn_err('Could not acknowledge notifications.', 500);
    }
}

pn_err("Unknown action: $action");
