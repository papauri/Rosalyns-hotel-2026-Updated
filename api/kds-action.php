<?php

/**
 * api/kds-action.php — Kitchen Display System action endpoint.
 *
 * Auth: session-based admin (kds_view permission).
 * Methods: POST only.
 * Actions:
 *   start_item    {item_id}            : pending/preparing -> preparing
 *   ready_item    {item_id}            : preparing -> ready
 *   serve_item    {item_id}            : ready -> served
 *   bump_ticket   {order_id}           : mark all remaining items served + order kitchen_status='served'
 *   recall_ticket {order_id}           : flip back to in_progress (undo bump within 5 minutes)
 *   start_ticket  {order_id}           : mark order in_progress + all pending items -> preparing
 * All requests require `csrf_token`.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function jerr(string $m, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}
function jok(array $extra = []): void
{
    // Auto-log offline-queue replays (client_uuid + client_queued_at). No-op for online calls.
    if (function_exists('rh_log_offline_replay') && isset($GLOBALS['pdo'])) {
        $action = $_POST['action'] ?? '';
        rh_log_offline_replay($GLOBALS['pdo'], '/api/kds-action.php', [
            'action' => $action,
            'entity_type' => isset($extra['order_id']) ? 'stock_order' : null,
            'entity_id'   => isset($extra['order_id']) ? (int)$extra['order_id'] : null,
            'response_status' => 200,
            'response_summary' => $action . (isset($extra['order_status']) ? ' → ' . $extra['order_status'] : ''),
        ]);
    }
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('POST only', 405);
if (empty($_SESSION['admin_user'])) jerr('Not authenticated', 401);
$user = $_SESSION['admin_user'];

$csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!validateCsrfToken($csrfToken)) jerr('Invalid CSRF token', 403);

require_once __DIR__ . '/../admin/includes/permissions.php';
require_once __DIR__ . '/../admin/includes/offline-log.php';
require_once __DIR__ . '/../includes/station-hours.php';

/* Resolve which station this user is acting on. */
$STATION_ALLOWED = ['kitchen', 'bar', 'coffee_bar'];
$role = $user['role'] ?? '';
$isPrivileged = in_array($role, ['admin', 'manager'], true);
$reqStation = $_POST['station'] ?? null;
if ($reqStation !== null && !in_array($reqStation, $STATION_ALLOWED, true)) jerr('Invalid station');

$action = trim((string)($_POST['action'] ?? ($_GET['action'] ?? '')));

// Map role -> default station + required permission
$rolePerm = match ($role) {
    'chef'         => ['kitchen',    'kds_view'],
    'bar_staff'    => ['bar',        'bds_view'],
    'coffee_staff' => ['coffee_bar', 'cds_view'],
    default        => ['kitchen',    'kds_view'],
};
[$defaultStation, $defaultPerm] = $rolePerm;
$STATION = $reqStation ?: $defaultStation;

// Permission check: privileged users see/act on any station; staff are pinned to their station's permission
if (in_array($action, ['send_message', 'get_pos_inbox', 'get_order_station_messages', 'station_reply', 'get_my_orders', 'send_to_pos', 'ack_pos_message', 'update_order_note', 'toggle_priority_pos'], true)) {
    $canSendStationNote = hasPermission((int)$user['id'], 'pos_till')
        || hasPermission((int)$user['id'], 'stock_orders')
        || hasPermission((int)$user['id'], 'kds_view')
        || hasPermission((int)$user['id'], 'bds_view')
        || hasPermission((int)$user['id'], 'cds_view');
    if (!$canSendStationNote) jerr('Forbidden', 403);
} elseif ($isPrivileged) {
    if (!hasPermission((int)$user['id'], 'kds_view') && !hasPermission((int)$user['id'], 'bds_view') && !hasPermission((int)$user['id'], 'cds_view')) jerr('Forbidden', 403);
} else {
    $needed = match ($STATION) {
        'kitchen' => 'kds_view',
        'bar' => 'bds_view',
        'coffee_bar' => 'cds_view'
    };
    if (!hasPermission((int)$user['id'], $needed)) jerr('Forbidden', 403);
    if ($STATION !== $defaultStation) jerr('Forbidden: station mismatch', 403);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$stationWindow = rh_station_business_window($STATION);
// Use the restaurant-wide union window as the ticket cutoff so orders fired before this
// station opens (e.g. a drink order placed at 10:55 before the bar opens at 11:00) still
// appear on all relevant station displays.  Per-station window is kept for served/allday.
$unionWindow = rh_station_union_business_window();

function kds_log(PDO $pdo, int $orderId, ?int $itemId, string $event, ?string $from, ?string $to, array $user, ?string $ip): void
{
    $pdo->prepare("INSERT INTO stock_kds_events (order_id, order_item_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$orderId, $itemId, $event, $from, $to, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $ip]);
}

function kds_recompute_order_status(PDO $pdo, int $orderId): string
{
    // Look at all non-void items across ALL stations — the order is only fully 'served'
    // when every station is done. Per-station displays still see only their own work.
    $st = $pdo->prepare("SELECT kds_status FROM stock_order_items WHERE order_id=? AND kds_status<>'void'");
    $st->execute([$orderId]);
    $statuses = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$statuses) return 'served';
    $hasPending    = in_array('pending',    $statuses, true);
    $hasPreparing  = in_array('preparing',  $statuses, true);
    $hasReady      = in_array('ready',      $statuses, true);
    $hasCollection = in_array('collection', $statuses, true);
    $hasServed     = in_array('served',     $statuses, true);
    // All items done (served only, no pending/preparing/ready/collection)
    if (!$hasPending && !$hasPreparing && !$hasReady && !$hasCollection) return 'served';
    // All ready or awaiting collection (nothing still being prepped)
    if (!$hasPending && !$hasPreparing) return 'ready';
    // Mixed — some items still in preparation
    if ($hasPreparing || $hasReady || $hasCollection || $hasServed) return 'in_progress';
    return 'new';
}

/**
 * Fire a ready-collection notification when all items at $station for $orderId
 * are now either 'ready' or 'served'. Idempotent for the same order+station pair.
 */
function kds_maybe_notify_ready(PDO $pdo, int $orderId, string $station): void
{
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_ready_notifications (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id     INT UNSIGNED NOT NULL,
            `reference`  VARCHAR(50)  NOT NULL DEFAULT '',
            table_label  VARCHAR(80)  NOT NULL DEFAULT '',
            station      VARCHAR(30)  NOT NULL DEFAULT 'kitchen',
            placed_by    INT UNSIGNED NULL,
            message      VARCHAR(255) NOT NULL DEFAULT '',
            for_role     VARCHAR(20)  NOT NULL DEFAULT 'all',
            seen_by      TEXT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Are ALL non-void items for this station now ready/served?
        $check = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items
            WHERE order_id=? AND station=? AND kds_status NOT IN ('ready','collection','served','void')");
        $check->execute([$orderId, $station]);
        if ((int)$check->fetchColumn() > 0) return; // still items in progress

        // Are there any items at all for this station?
        $hasItems = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items
            WHERE order_id=? AND station=? AND kds_status<>'void'");
        $hasItems->execute([$orderId, $station]);
        if (!(int)$hasItems->fetchColumn()) return;

        // Idempotency: notify once per order+station pair.
        $dup = $pdo->prepare("SELECT id FROM pos_ready_notifications
            WHERE order_id=? AND station=? LIMIT 1");
        $dup->execute([$orderId, $station]);
        if ($dup->fetchColumn()) return;

        // Fetch order details for the message
        $ord = $pdo->prepare("SELECT reference, table_number, order_type, created_by FROM stock_orders WHERE id=?");
        $ord->execute([$orderId]);
        $o = $ord->fetch(PDO::FETCH_ASSOC);
        if (!$o) return;

        $tableLabel = $o['table_number'] ?: strtoupper(str_replace('_', ' ', $o['order_type'] ?? 'order'));
        $stationLabel = match ($station) {
            'bar' => 'Bar',
            'coffee_bar' => 'Coffee Bar',
            default => 'Kitchen'
        };
        $message = $stationLabel . ': order ' . $o['reference'] . ' (' . $tableLabel . ') is ready for collection.';

        $pdo->prepare("INSERT INTO pos_ready_notifications
            (order_id, `reference`, table_label, station, placed_by, message, for_role, seen_by)
            VALUES (?, ?, ?, ?, ?, ?, 'all', '[]')")
            ->execute([$orderId, $o['reference'], $tableLabel, $station, $o['created_by'] ?: null, $message]);
    } catch (Throwable $e) {
        error_log('kds_maybe_notify_ready: ' . $e->getMessage());
    }
}

function kds_station_label(string $station): string
{
    return match ($station) {
        'bar' => 'Bar',
        'coffee_bar' => 'Coffee Bar',
        default => 'Kitchen',
    };
}

function kds_notify_pos_collection(PDO $pdo, int $orderId, string $station, string $itemName, array $actor): void
{
    try {
        $orderStmt = $pdo->prepare("SELECT reference, created_by, table_number, order_type FROM stock_orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return;

        $toUserId = (int)($order['created_by'] ?? 0);
        if ($toUserId <= 0) return;

        kds_ensure_station_messages_table($pdo);

        $stationLabel = kds_station_label($station);
        $locationLabel = (string)($order['table_number'] ?? '');
        if ($locationLabel === '') {
            $locationLabel = strtoupper(str_replace('_', ' ', (string)($order['order_type'] ?? 'ORDER')));
        }

        $safeItemName = trim($itemName) !== '' ? trim($itemName) : 'An item';

        /* Collapse into ONE outstanding collection note per order+station instead of one per
         * item. Marking six items on a table individually used to raise six separate URGENT
         * notes for the same trip to the same pass, which buried the genuinely distinct
         * messages next to them. If an unacknowledged note for this order+station already
         * exists, restate it with the running item count and bump it to the top instead. */
        $pendingStmt = $pdo->prepare("SELECT id FROM station_messages
            WHERE order_id = ? AND station = ? AND source = 'station'
              AND COALESCE(pos_acknowledged, 0) = 0
              AND message LIKE '%READY FOR COLLECTION%'
            ORDER BY id DESC LIMIT 1");
        $pendingStmt->execute([$orderId, $station]);
        $existingNoteId = (int)$pendingStmt->fetchColumn();

        $collectedStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items
            WHERE order_id = ? AND station = ? AND kds_status = 'collection'");
        $collectedStmt->execute([$orderId, $station]);
        $collectedCount = (int)$collectedStmt->fetchColumn();

        $subject = $collectedCount > 1
            ? $collectedCount . ' items'
            : $safeItemName;
        $message = $stationLabel . ' READY FOR COLLECTION: ' . $subject . ' on order ' . (string)($order['reference'] ?? '') . ' (' . $locationLabel . ').';

        if ($existingNoteId > 0) {
            $pdo->prepare("UPDATE station_messages SET message = ?, created_at = NOW(), seen_at = NULL WHERE id = ?")
                ->execute([$message, $existingNoteId]);
            return;
        }

        $pdo->prepare("INSERT INTO station_messages
            (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at)
            VALUES (?, ?, ?, ?, 'station', 'urgent', ?, ?, ?, 0, NULL)")
            ->execute([
                $station,
                $message,
                (int)($actor['id'] ?? 0),
                (string)($actor['full_name'] ?? $actor['username'] ?? 'Kitchen'),
                $orderId,
                (string)($order['reference'] ?? ''),
                $toUserId,
            ]);
    } catch (Throwable $e) {
        error_log('kds_notify_pos_collection: ' . $e->getMessage());
    }
}

function kds_recipe_requirements(PDO $pdo, int $menuItemId, string $menuType, float $portions): array
{
    if ($portions <= 0 || $menuType === '' || !ensureStockTablesExist()) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT i.id AS ingredient_id, i.name, i.unit, i.current_quantity,
                                  ((sri.quantity_per_portion * ?) / (GREATEST(sri.yield_percent, 0.1) / 100)) AS required_qty
                             FROM stock_recipes sr
                             INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                             INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
                            WHERE sr.menu_item_id = ?
                              AND sr.menu_type = ?
                              AND sri.quantity_per_portion > 0
                            FOR UPDATE");
    $stmt->execute([$portions, $menuItemId, $menuType]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kds_stock_shortages_for_items(PDO $pdo, array $items): array
{
    $requirements = [];

    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $menuType = (string)($item['menu_type'] ?? '');
        $quantity = (float)($item['quantity'] ?? 0);

        foreach (kds_recipe_requirements($pdo, $menuItemId, $menuType, $quantity) as $line) {
            $ingredientId = (int)$line['ingredient_id'];
            if (!isset($requirements[$ingredientId])) {
                $requirements[$ingredientId] = [
                    'name' => (string)$line['name'],
                    'unit' => (string)($line['unit'] ?? ''),
                    'current_quantity' => (float)$line['current_quantity'],
                    'required_qty' => 0.0,
                ];
            }
            $requirements[$ingredientId]['required_qty'] += (float)$line['required_qty'];
        }
    }

    $shortages = [];
    foreach ($requirements as $requirement) {
        $requiredQty = (float)$requirement['required_qty'];
        $currentQty = (float)$requirement['current_quantity'];
        if ($currentQty + 0.0001 < $requiredQty) {
            $requirement['short_qty'] = round($requiredQty - $currentQty, 3);
            $shortages[] = $requirement;
        }
    }

    return $shortages;
}

function kds_stock_shortage_message(array $shortages): string
{
    $parts = [];
    foreach (array_slice($shortages, 0, 3) as $shortage) {
        $unit = trim((string)($shortage['unit'] ?? ''));
        $parts[] = trim((string)$shortage['name'] . ' short by ' . number_format((float)$shortage['short_qty'], 3) . ($unit !== '' ? ' ' . $unit : ''));
    }

    $extra = count($shortages) > 3 ? ' and ' . (count($shortages) - 3) . ' more ingredient(s)' : '';
    return 'Stock check stopped this action: ' . implode(', ', $parts) . $extra . '. Receive stock, adjust the recipe, or 86 the item before marking it ready.';
}

function kds_control_keys(string $station): array
{
    return [
        'paused' => 'station_' . $station . '_online_paused',
        'wait' => 'station_' . $station . '_estimated_wait_minutes',
        'reason' => 'station_' . $station . '_pause_reason',
    ];
}

function kds_get_station_control(PDO $pdo, string $station): array
{
    $keys = kds_control_keys($station);
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?, ?, ?)");
    $stmt->execute([$keys['paused'], $keys['wait'], $keys['reason']]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $waitMinutes = max(5, min(180, (int)($rows[$keys['wait']] ?? 20)));

    return [
        'paused' => in_array((string)($rows[$keys['paused']] ?? '0'), ['1', 'true', 'on'], true),
        'wait_minutes' => $waitMinutes,
        'reason' => (string)($rows[$keys['reason']] ?? ''),
    ];
}

function kds_save_station_control(PDO $pdo, string $station, bool $paused, int $waitMinutes, string $reason): array
{
    $keys = kds_control_keys($station);
    $waitMinutes = max(5, min(180, $waitMinutes));
    $reason = mb_substr(trim($reason), 0, 160);
    $upsert = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $upsert->execute([$keys['paused'], $paused ? '1' : '0']);
    $upsert->execute([$keys['wait'], (string)$waitMinutes]);
    $upsert->execute([$keys['reason'], $reason]);

    return kds_get_station_control($pdo, $station);
}

function kds_ensure_column(PDO $pdo, string $table, string $col, string $def): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
    }
}

function kds_ensure_station_messages_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        station VARCHAR(30) NOT NULL,
        message VARCHAR(255) NOT NULL,
        sent_by INT UNSIGNED NULL,
        sent_by_name VARCHAR(120) NOT NULL DEFAULT '',
        source VARCHAR(20) NOT NULL DEFAULT 'pos',
        is_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
        acknowledged_by INT UNSIGNED NULL,
        acknowledged_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_station_messages_station_created (station, created_at),
        INDEX idx_station_messages_ack (is_acknowledged)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* New columns added in v2 — safe to run every time, no-op if already present */
    kds_ensure_column($pdo, 'station_messages', 'priority',        "ENUM('normal','urgent') NOT NULL DEFAULT 'normal'");
    kds_ensure_column($pdo, 'station_messages', 'seen_at',         "DATETIME NULL");
    kds_ensure_column($pdo, 'station_messages', 'reply_message',   "VARCHAR(255) NULL");
    kds_ensure_column($pdo, 'station_messages', 'replied_at',      "DATETIME NULL");
    kds_ensure_column($pdo, 'station_messages', 'replied_by_name', "VARCHAR(120) NULL");
    /* v3 — order linkage */
    kds_ensure_column($pdo, 'station_messages', 'order_id',        "INT UNSIGNED NULL");
    kds_ensure_column($pdo, 'station_messages', 'order_ref',       "VARCHAR(40) NULL");
    /* v4 — direct station→POS messaging targeted at a specific cashier */
    kds_ensure_column($pdo, 'station_messages', 'to_user_id',      "INT UNSIGNED NULL");
    /* v5 — FOH action tracking for direct station→POS notes */
    kds_ensure_column($pdo, 'station_messages', 'pos_acknowledged',    "TINYINT(1) NOT NULL DEFAULT 0");
    kds_ensure_column($pdo, 'station_messages', 'pos_acknowledged_at', "DATETIME NULL");
    kds_ensure_column($pdo, 'station_messages', 'pos_acknowledged_by', "INT UNSIGNED NULL");
}

function kds_recent_station_messages(PDO $pdo, string $station): array
{
    kds_ensure_station_messages_table($pdo);
    /* Mark messages as seen by the station on first retrieval. No age cap: it must cover
     * exactly what the SELECT below returns, or an old unacknowledged message renders
     * permanently "unseen" because the marker skipped it while the list still showed it. */
    $pdo->prepare("UPDATE station_messages SET seen_at = NOW() WHERE station = ? AND seen_at IS NULL AND is_acknowledged = 0")
        ->execute([$station]);
    /* An UNACKNOWLEDGED message is outstanding work and stays on the board however old it is —
     * dropping it after 6 hours hid the notes nobody had dealt with, which are precisely the
     * ones that still needed dealing with. The 6-hour recency window now applies only to the
     * already-replied informational rows, which are just context once handled. */
    $stmt = $pdo->prepare("SELECT id, station, message, sent_by, sent_by_name, source, priority, seen_at,
            reply_message, replied_at, replied_by_name, order_id, order_ref, created_at
        FROM station_messages
                WHERE station = ?
                    AND (
                                is_acknowledged = 0
                                OR (source = 'station' AND reply_message IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR))
                            )
        ORDER BY priority DESC, created_at DESC, id DESC
                LIMIT 25");
    $stmt->execute([$station]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kds_message_signature(array $messages): string
{
    return implode(',', array_map(static function (array $message): string {
        return implode(':', [
            (string)($message['id'] ?? ''),
            (string)($message['priority'] ?? ''),
            (string)($message['seen_at'] ?? ''),
            (string)($message['replied_at'] ?? ''),
            substr(sha1((string)($message['reply_message'] ?? '')), 0, 10),
        ]);
    }, $messages));
}

function kds_all_day_summary(PDO $pdo, string $station, array $window): array
{
    $stmt = $pdo->prepare("SELECT oi.item_name, SUM(oi.quantity) AS quantity
        FROM stock_order_items oi
        INNER JOIN stock_orders o ON o.id = oi.order_id
        WHERE oi.station = ?
          AND oi.kds_status IN ('pending', 'preparing')
          AND o.fired_at >= ?
          AND o.fired_at < ?
          AND o.status NOT IN ('voided', 'cancelled')
        GROUP BY oi.item_name
        ORDER BY quantity DESC, oi.item_name ASC
        LIMIT 12");
    $stmt->execute([$station, $window['start_sql'], $window['end_sql']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kds_requested_window(string $station, string $day): array
{
    $current = rh_station_business_window($station);
    if ($day === 'yesterday') {
        return rh_station_previous_business_window($station, $current);
    }
    if ($day === 'after_hours') {
        return kds_after_hours_window($station);
    }

    return $current;
}

function kds_after_hours_window(string $station): array
{
    $now = new DateTimeImmutable('now', rh_site_timezone());
    $current = rh_station_business_window($station, $now);

    if ($now < $current['start']) {
        $previous = rh_station_previous_business_window($station, $current);
        $start = $previous['end'];
        $end = $current['start'];
        $businessDate = $previous['business_date'];
    } else {
        $nextDate = (new DateTimeImmutable($current['business_date']))->modify('+1 day')->format('Y-m-d');
        $next = rh_station_window_for_date($station, $nextDate);
        $start = $current['end'];
        $end = $next['start'];
        $businessDate = $current['business_date'];
    }

    return [
        'station' => $current['station'],
        'label' => $current['label'] . ' After Hours',
        'short_label' => $current['short_label'],
        'opens_at' => $start->format('H:i'),
        'closes_at' => $end->format('H:i'),
        'crosses_midnight' => $end->format('Y-m-d') !== $start->format('Y-m-d'),
        'business_date' => $businessDate,
        'start' => $start,
        'end' => $end,
        'start_sql' => $start->format('Y-m-d H:i:s'),
        'end_sql' => $end->format('Y-m-d H:i:s'),
        'hours_label' => 'After ' . $start->format('H:i') . ' until ' . $end->format('H:i') . ($end->format('Y-m-d') !== $start->format('Y-m-d') ? ' (+1)' : ''),
        'window_label' => $start->format('M j H:i') . ' - ' . $end->format('M j H:i'),
        'is_open_now' => false,
    ];
}

try {
    if ($action === 'send_message') {
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 255);
        if ($message === '') jerr('Message required');
        $priority = in_array($_POST['priority'] ?? 'normal', ['normal', 'urgent'], true) ? (string)($_POST['priority'] ?? 'normal') : 'normal';
        $linkedOrderId = (int)($_POST['order_id'] ?? 0) ?: null;
        /* Validate the linked order exists and belongs to the same sender's context */
        $linkedOrderRef = null;
        if ($linkedOrderId !== null) {
            $chk = $pdo->prepare("SELECT reference FROM stock_orders WHERE id = ? LIMIT 1");
            $chk->execute([$linkedOrderId]);
            $linkedOrderRef = $chk->fetchColumn() ?: null;
            if ($linkedOrderRef === false || $linkedOrderRef === null) {
                $linkedOrderId = null;
            }
        }
        kds_ensure_station_messages_table($pdo);
        $stmt = $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $source = hasPermission((int)$user['id'], 'pos_till') ? 'pos' : 'station';
        $stmt->execute([$STATION, $message, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $source, $priority, $linkedOrderId, $linkedOrderRef]);
        if (function_exists('rh_log_event')) {
            rh_log_event('api/kds-action', 'info', 'Station note sent', ['station' => $STATION, 'priority' => $priority, 'order_ref' => $linkedOrderRef, 'user' => $user['username'] ?? '', 'source' => $source]);
        }
        jok(['message_id' => (int)$pdo->lastInsertId(), 'station' => $STATION]);
    }

    if ($action === 'ack_message') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) jerr('Missing message_id');
        $reply = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, 255);
        kds_ensure_station_messages_table($pdo);
        if ($reply !== '') {
            $stmt = $pdo->prepare("UPDATE station_messages SET is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW(), reply_message = ?, replied_at = NOW(), replied_by_name = ? WHERE id = ? AND station = ?");
            $stmt->execute([(int)$user['id'], $reply, $user['full_name'] ?? $user['username'] ?? '', $messageId, $STATION]);
        } else {
            $stmt = $pdo->prepare("UPDATE station_messages SET is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND station = ?");
            $stmt->execute([(int)$user['id'], $messageId, $STATION]);
        }
        jok(['message_id' => $messageId, 'replied' => $reply !== '']);
    }

    if ($action === 'station_reply') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) jerr('Missing message_id');
        $reply = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, 255);
        if ($reply === '') jerr('Reply message required');
        kds_ensure_station_messages_table($pdo);
        $stmt = $pdo->prepare("UPDATE station_messages SET reply_message = ?, replied_at = NOW(), replied_by_name = ? WHERE id = ? AND station = ?");
        $stmt->execute([$reply, $user['full_name'] ?? $user['username'] ?? '', $messageId, $STATION]);
        jok(['message_id' => $messageId, 'replied_at' => date('Y-m-d H:i:s')]);
    }

    if ($action === 'get_pos_inbox') {
        kds_ensure_station_messages_table($pdo);
        $userId = (int)$user['id'];
        $businessStart = (string)$unionWindow['start_sql'];
        $businessEnd = (string)$unionWindow['end_sql'];
        /* Pull (a) recent messages this user initiated,
           (b) recent inbound non-station rows,
           (c) station→POS direct notes that still require FOH action. */
        $stmt = $pdo->prepare("SELECT sm.id, sm.station, sm.message, sm.source, sm.sent_by_name, sm.priority,
                sm.order_id, sm.order_ref, sm.created_at, sm.seen_at, sm.is_acknowledged, sm.acknowledged_at,
                sm.reply_message, sm.replied_at, sm.replied_by_name, sm.to_user_id,
                sm.pos_acknowledged, sm.pos_acknowledged_at,
                o.order_type, o.table_number, o.room_number, o.customer_name,
                oi.order_items_summary
            FROM station_messages sm
            LEFT JOIN stock_orders o ON o.id = sm.order_id
            LEFT JOIN (
                SELECT soi.order_id,
                    GROUP_CONCAT(CONCAT(soi.quantity, 'x ', soi.item_name) ORDER BY soi.id SEPARATOR ', ') AS order_items_summary
                FROM stock_order_items soi
                GROUP BY soi.order_id
            ) oi ON oi.order_id = sm.order_id
            WHERE (
                (sm.sent_by = ? AND sm.source <> 'station' AND sm.reply_message IS NOT NULL AND sm.created_at >= ? AND sm.created_at < ?)
                OR
                (sm.to_user_id = ? AND sm.source <> 'station' AND sm.created_at >= ? AND sm.created_at < ?)
                OR
                (sm.to_user_id = ? AND sm.source = 'station' AND COALESCE(sm.pos_acknowledged, 0) = 0)
            )
            ORDER BY sm.created_at DESC
            LIMIT 50");
        /* Clause (c) deliberately carries NO time bound. It is the only clause covering notes
         * that still REQUIRE FOH action, and an unacknowledged note does not stop mattering
         * because a business window rolled over — bounding it by $businessStart silently hid
         * exactly the notes someone still had to act on (same failure the KDS board had). The
         * pos_acknowledged flag, not the clock, is what removes a note from this list. The two
         * informational clauses above stay window-bounded: those are already-handled traffic. */
        $stmt->execute([$userId, $businessStart, $businessEnd, $userId, $businessStart, $businessEnd, $userId]);
        jok(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get_order_station_messages') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('order_id required');
        kds_ensure_station_messages_table($pdo);
        $userId = (int)$user['id'];
        $limit = max(10, min(80, (int)($_POST['limit'] ?? 40)));

        $stmt = $pdo->prepare("SELECT sm.id, sm.station, sm.message, sm.source, sm.sent_by_name, sm.priority,
                sm.order_id, sm.order_ref, sm.created_at, sm.seen_at, sm.is_acknowledged, sm.acknowledged_at,
                sm.reply_message, sm.replied_at, sm.replied_by_name, sm.to_user_id,
                sm.pos_acknowledged, sm.pos_acknowledged_at
            FROM station_messages sm
            WHERE sm.order_id = ?
              AND (
                    (sm.sent_by = ? AND sm.source <> 'station')
                    OR
                    (sm.to_user_id = ?)
                  )
            ORDER BY sm.created_at DESC, sm.id DESC
            LIMIT " . (int)$limit);
        $stmt->execute([$orderId, $userId, $userId]);
        jok(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'ack_pos_message') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) jerr('Missing message_id');
        kds_ensure_station_messages_table($pdo);
        $userId = (int)$user['id'];

        $stmt = $pdo->prepare("UPDATE station_messages
            SET pos_acknowledged = 1, pos_acknowledged_at = NOW(), pos_acknowledged_by = ?
            WHERE id = ? AND to_user_id = ? AND source = 'station'");
        $stmt->execute([$userId, $messageId, $userId]);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT id FROM station_messages WHERE id = ? AND to_user_id = ? AND source = 'station' LIMIT 1");
            $check->execute([$messageId, $userId]);
            if (!$check->fetchColumn()) jerr('Message not found', 404);
        }

        if (function_exists('rh_log_event')) {
            rh_log_event('api/kds-action', 'info', 'POS acknowledged station note', [
                'message_id' => $messageId,
                'user' => $user['username'] ?? '',
                'station' => $STATION,
            ]);
        }
        jok(['message_id' => $messageId, 'pos_acknowledged' => true]);
    }

    if ($action === 'send_to_pos') {
        /* Station-initiated note about a specific order, routed to the cashier who placed it. */
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 255);
        if ($message === '') jerr('Message required');
        $linkedOrderId = (int)($_POST['order_id'] ?? 0);
        if ($linkedOrderId <= 0) jerr('order_id required');
        $priority = in_array($_POST['priority'] ?? 'normal', ['normal', 'urgent'], true) ? (string)($_POST['priority'] ?? 'normal') : 'normal';
        $chk = $pdo->prepare("SELECT reference, created_by FROM stock_orders WHERE id = ? LIMIT 1");
        $chk->execute([$linkedOrderId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) jerr('Order not found', 404);
        $toUserId = (int)($row['created_by'] ?? 0) ?: null;
        if (!$toUserId) jerr('Order has no cashier to notify');
        kds_ensure_station_messages_table($pdo);
        $stmt = $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'station', ?, ?, ?, ?, 1, NOW())");
        /* is_acknowledged=1 so it doesn't show on the station's own board — it's an outgoing message. */
        $stmt->execute([$STATION, $message, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $priority, $linkedOrderId, (string)$row['reference'], $toUserId]);
        if (function_exists('rh_log_event')) {
            rh_log_event('api/kds-action', 'info', 'Station→POS note sent', ['station' => $STATION, 'order_ref' => $row['reference'], 'to_user_id' => $toUserId, 'user' => $user['username'] ?? '']);
        }
        jok(['message_id' => (int)$pdo->lastInsertId(), 'to_user_id' => $toUserId]);
    }

    if ($action === 'get_my_orders') {
        /* Recent orders the cashier has fired/paid. Returns lifecycle status from the
           kitchen's perspective (placed → preparing → ready → served) plus payment
           status, age, and per-station progress so the till can show a live tracker.
           Also returns is_priority (RUSH flag) and per-station item breakdown. */
        $businessStart = (string)$unionWindow['start_sql'];
        $businessEnd = (string)$unionWindow['end_sql'];
        $stmt = $pdo->prepare("
            SELECT o.id, o.reference, o.order_type, o.status, o.total_amount,
                   o.payment_method, o.table_number, o.customer_name,
                   o.created_at, o.fired_at, o.opened_as_tab, o.notes,
                   COALESCE(o.is_priority, 0) AS is_priority,
                   COUNT(oi.id) AS item_total,
                   SUM(CASE WHEN oi.kds_status <> 'void' THEN 1 ELSE 0 END) AS active_item_total,
                   /* quantity is DECIMAL, so a plain CAST renders 1.000x English Breakfast.
                      Trim trailing zeros and any bare decimal point so the till reads 1x for
                      whole numbers while still showing 1.5x where the fraction matters. */
                   GROUP_CONCAT(CASE WHEN oi.kds_status <> 'void' THEN CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(oi.quantity AS CHAR))), 'x ', oi.item_name) END ORDER BY oi.id SEPARATOR ', ') AS items_summary,
                   SUM(CASE WHEN oi.kds_status = 'pending'   THEN 1 ELSE 0 END) AS items_pending,
                   SUM(CASE WHEN oi.kds_status = 'preparing' THEN 1 ELSE 0 END) AS items_preparing,
                   SUM(CASE WHEN oi.kds_status = 'ready'     THEN 1 ELSE 0 END) AS items_ready,
                   SUM(CASE WHEN oi.kds_status = 'collection' THEN 1 ELSE 0 END) AS items_collection,
                   SUM(CASE WHEN oi.kds_status = 'served'    THEN 1 ELSE 0 END) AS items_served,
                   SUM(CASE WHEN oi.station = 'kitchen'  AND oi.kds_status NOT IN ('served','void') THEN 1 ELSE 0 END) AS kitchen_pending,
                   SUM(CASE WHEN oi.station = 'kitchen'  AND oi.kds_status = 'served' THEN 1 ELSE 0 END) AS kitchen_served,
                   SUM(CASE WHEN oi.station = 'bar'       AND oi.kds_status NOT IN ('served','void') THEN 1 ELSE 0 END) AS bar_pending,
                   SUM(CASE WHEN oi.station = 'bar'       AND oi.kds_status = 'served' THEN 1 ELSE 0 END) AS bar_served,
                   SUM(CASE WHEN oi.station = 'coffee_bar' AND oi.kds_status NOT IN ('served','void') THEN 1 ELSE 0 END) AS coffee_pending,
                   SUM(CASE WHEN oi.station = 'coffee_bar' AND oi.kds_status = 'served' THEN 1 ELSE 0 END) AS coffee_served
            FROM stock_orders o
            LEFT JOIN stock_order_items oi ON oi.order_id = o.id
            WHERE o.created_by = ?
                            AND o.created_at >= ?
                            AND o.created_at < ?
            GROUP BY o.id
                        ORDER BY COALESCE(o.is_priority,0) DESC,
                                         CASE WHEN o.status = 'placed' THEN 0 WHEN o.status = 'paid' THEN 1 ELSE 2 END,
                                         o.created_at DESC
                        LIMIT 120
        ");
        $stmt->execute([(int)$user['id'], $businessStart, $businessEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /* Compute a single overall kitchen-status per order for easy badge rendering */
        foreach ($rows as &$r) {
            $orderStatus = (string)($r['status'] ?? '');
            $total     = (int)($r['active_item_total'] ?? 0);
            $served    = (int)$r['items_served'];
            $ready     = (int)$r['items_ready'];
            $collection = (int)$r['items_collection'];
            $preparing = (int)$r['items_preparing'];
            $pending   = (int)$r['items_pending'];
            if (in_array($orderStatus, ['voided', 'cancelled'], true)) $r['kitchen_status'] = $orderStatus;
            elseif ($total === 0)                      $r['kitchen_status'] = 'empty';
            elseif ($served === $total)                $r['kitchen_status'] = 'served';
            elseif (($ready + $collection + $served) === $total) $r['kitchen_status'] = 'ready';
            elseif ($preparing > 0 || $ready > 0 || $collection > 0) $r['kitchen_status'] = 'preparing';
            else                                        $r['kitchen_status'] = 'placed';
            $r['progress_percent'] = $total > 0
                ? (int)round((($served * 1.0) + ($collection * 0.9) + ($ready * 0.85) + ($preparing * 0.5)) / $total * 100)
                : 0;
            // Build per-station status summary for multi-station orders
            $stations = [];
            if ((int)$r['kitchen_pending'] + (int)$r['kitchen_served'] > 0) {
                $stations[] = [
                    'station' => 'kitchen',
                    'label' => 'Kitchen',
                    'done' => (int)$r['kitchen_pending'] === 0,
                    'pending' => (int)$r['kitchen_pending']
                ];
            }
            if ((int)$r['bar_pending'] + (int)$r['bar_served'] > 0) {
                $stations[] = [
                    'station' => 'bar',
                    'label' => 'Bar',
                    'done' => (int)$r['bar_pending'] === 0,
                    'pending' => (int)$r['bar_pending']
                ];
            }
            if ((int)$r['coffee_pending'] + (int)$r['coffee_served'] > 0) {
                $stations[] = [
                    'station' => 'coffee_bar',
                    'label' => 'Coffee',
                    'done' => (int)$r['coffee_pending'] === 0,
                    'pending' => (int)$r['coffee_pending']
                ];
            }
            $r['stations'] = $stations;
        }
        unset($r);
        jok(['orders' => $rows, 'server_time' => date('Y-m-d H:i:s')]);
    }

    if ($action === 'update_order_note') {
        /* POS cashier can patch the order-level notes field on a fired (open) order.
           The change propagates to the KDS on the next fingerprint check (since we
           now include item notes in the fingerprint for per-item notes, but the order
           note lives on stock_orders.notes which is fetched in kds_feed). */
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');
        $newNote = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);

        $pdo->beginTransaction();
        $ord = $pdo->prepare("SELECT id, created_by, reference, table_number, order_type, status FROM stock_orders WHERE id = ? FOR UPDATE");
        $ord->execute([$orderId]);
        $order = $ord->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            jerr('Order not found', 404);
        }
        if (!in_array((string)$order['status'], ['placed', 'paid'], true)) {
            $pdo->rollBack();
            jerr('Cannot update note on a voided or cancelled order');
        }
        // Only the cashier who placed the order or a privileged user may edit the note
        if ((int)$order['created_by'] !== (int)$user['id'] && !$isPrivileged) {
            $pdo->rollBack();
            jerr('Not authorised to edit this order note', 403);
        }

        $pdo->prepare("UPDATE stock_orders SET notes = ?, updated_at = NOW() WHERE id = ?")->execute([$newNote ?: null, $orderId]);
        kds_log($pdo, $orderId, null, 'note_updated', null, null, $user, $ip);
        $pdo->commit();

        // Push an urgent station message so KDS staff see the updated note without waiting for the next poll
        try {
            kds_ensure_station_messages_table($pdo);
            $tableLabel = $order['table_number'] ?: strtoupper(str_replace('_', ' ', (string)($order['order_type'] ?? '')));
            $noteMsg = 'Note updated on order ' . $order['reference'] . ' (' . $tableLabel . '): ' . ($newNote ?: '(cleared)');
            // Target all active stations by inserting one message per active station
            foreach (['kitchen', 'bar', 'coffee_bar'] as $stn) {
                $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'pos', 'urgent', ?, ?, 0, NULL)")
                    ->execute([$stn, mb_substr($noteMsg, 0, 255), (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $orderId, (string)$order['reference']]);
            }
        } catch (Throwable $e) {
            error_log('update_order_note station msg: ' . $e->getMessage());
        }

        jok(['order_id' => $orderId, 'note' => $newNote]);
    }

    if ($action === 'toggle_priority_pos') {
        /* Same as toggle_priority but callable by POS cashier (no station context required).
           Allows FOH to mark an order as RUSH before KDS even sees it. */
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');

        $pdo->beginTransaction();
        $ord = $pdo->prepare("SELECT id, reference, table_number, order_type, is_priority, created_by, status FROM stock_orders WHERE id = ? FOR UPDATE");
        $ord->execute([$orderId]);
        $order = $ord->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            jerr('Order not found', 404);
        }
        if ((int)$order['created_by'] !== (int)$user['id'] && !$isPrivileged) {
            $pdo->rollBack();
            jerr('Not authorised', 403);
        }
        if (!in_array((string)$order['status'], ['placed', 'paid'], true)) {
            $pdo->rollBack();
            jerr('Cannot change priority on a closed order');
        }

        $newPriority = (int)$order['is_priority'] ? 0 : 1;
        $pdo->prepare("UPDATE stock_orders SET is_priority = ?, updated_at = NOW() WHERE id = ?")->execute([$newPriority, $orderId]);
        kds_log($pdo, $orderId, null, $newPriority ? 'rush_set' : 'rush_cleared', null, null, $user, $ip);
        $pdo->commit();

        // Notify all kitchen stations
        try {
            kds_ensure_station_messages_table($pdo);
            $tableLabel = $order['table_number'] ?: strtoupper(str_replace('_', ' ', (string)($order['order_type'] ?? '')));
            $rushMsg = $newPriority
                ? 'RUSH flagged by cashier on order ' . $order['reference'] . ' (' . $tableLabel . ') — priority prep required.'
                : 'Rush cleared by cashier on order ' . $order['reference'] . ' (' . $tableLabel . ') — standard service timing.';
            foreach (['kitchen', 'bar', 'coffee_bar'] as $stn) {
                $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'pos', ?, ?, ?, 0, NULL)")
                    ->execute([$stn, mb_substr($rushMsg, 0, 255), (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $newPriority ? 'urgent' : 'normal', $orderId, (string)$order['reference']]);
            }
        } catch (Throwable $e) {
            error_log('toggle_priority_pos station msg: ' . $e->getMessage());
        }

        jok(['order_id' => $orderId, 'is_priority' => $newPriority]);
    }

    if ($action === 'stock_snapshot') {
        /* Return live max-portions per recipe-backed menu item.  Used by POS to
           refresh OOS state immediately after a sale instead of waiting for a
           full page reload. */
        $snap = $pdo->query("
            SELECT sr.menu_item_id, sr.menu_type,
                   MIN(FLOOR(GREATEST(0, i.current_quantity) / (sri.quantity_per_portion / (GREATEST(sri.yield_percent, 0.1)/100)))) AS max_portions
            FROM stock_recipes sr
            INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
            INNER JOIN stock_ingredients i ON i.id = sri.ingredient_id
            WHERE sri.quantity_per_portion > 0
            GROUP BY sr.menu_item_id, sr.menu_type
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($snap as $s) $out[$s['menu_type'] . ':' . $s['menu_item_id']] = (int)$s['max_portions'];
        jok(['snapshot' => $out]);
    }

    if ($action === 'update_station_control') {
        $paused = in_array((string)($_POST['paused'] ?? '0'), ['1', 'true', 'on'], true);
        $waitMinutes = (int)($_POST['wait_minutes'] ?? 20);
        $reason = (string)($_POST['reason'] ?? '');
        $control = kds_save_station_control($pdo, $STATION, $paused, $waitMinutes, $reason);
        if (function_exists('rh_log_event')) {
            rh_log_event('api/kds-action', 'info', 'Station throttle updated', ['station' => $STATION, 'paused' => $paused, 'wait_minutes' => $control['wait_minutes'], 'user' => $user['username'] ?? '']);
        }
        jok(['station' => $STATION, 'control' => $control]);
    }

    if ($action === 'recipe_card') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0) jerr('Missing item_id');
        $stmt = $pdo->prepare("SELECT soi.id, soi.order_id, soi.menu_item_id, soi.menu_type, soi.item_name, soi.quantity, soi.notes, soi.station
            FROM stock_order_items soi
            WHERE soi.id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) jerr('Item not found', 404);
        if (!$isPrivileged) {
            $visible = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id = ? AND station = ?");
            $visible->execute([(int)$item['order_id'], $STATION]);
            if (!(int)$visible->fetchColumn()) jerr('Item not visible to this station', 403);
        }

        $menuStmt = $pdo->prepare("SELECT mi.item_name, mi.description, mi.category FROM menu_items mi WHERE mi.id = ?");
        $menuStmt->execute([(int)$item['menu_item_id']]);
        $menu = $menuStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $recipeStmt = $pdo->prepare("SELECT id, portions_per_recipe, notes FROM stock_recipes WHERE menu_item_id = ? AND menu_type = ? LIMIT 1");
        $recipeStmt->execute([(int)$item['menu_item_id'], $item['menu_type']]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);
        $ingredients = [];
        if ($recipe) {
            $lineStmt = $pdo->prepare("SELECT i.name, i.unit, i.category, ri.quantity_per_portion, ri.yield_percent
                FROM stock_recipe_ingredients ri
                INNER JOIN stock_ingredients i ON i.id = ri.ingredient_id
                WHERE ri.recipe_id = ?
                ORDER BY i.category, i.name");
            $lineStmt->execute([(int)$recipe['id']]);
            $ingredients = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        jok([
            'item' => $item,
            'menu' => $menu,
            'recipe' => $recipe ?: null,
            'ingredients' => $ingredients,
            'station_label' => kds_station_label((string)$item['station']),
        ]);
    }

    /* ── void_item: 86 a single order item ─────────────────────────── *
     * Marks one item as void, restores stock (if deducted), and sends
     * an urgent station→POS note so FOH can apologise to the guest.    */
    if ($action === 'void_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0) jerr('Missing item_id');

        $pdo->beginTransaction();
        $row = $pdo->prepare("SELECT id, order_id, kds_status, menu_item_id, menu_type, quantity, item_name, station, stock_deducted FROM stock_order_items WHERE id=? FOR UPDATE");
        $row->execute([$itemId]);
        $it = $row->fetch(PDO::FETCH_ASSOC);
        if (!$it) {
            $pdo->rollBack();
            jerr('Item not found', 404);
        }
        if ((string)$it['kds_status'] === 'void') {
            $pdo->rollBack();
            jerr('Already voided');
        }
        if ((string)$it['kds_status'] === 'served') {
            $pdo->rollBack();
            jerr('Cannot void an already-served item');
        }

        // Permission: non-privileged must have an item at their station
        if (!$isPrivileged && (string)$it['station'] !== $STATION) {
            $pdo->rollBack();
            jerr('Item does not belong to your station', 403);
        }

        $orderId = (int)$it['order_id'];
        $from = (string)$it['kds_status'];

        // Restore stock if it was deducted at ready_item time
        // Use item ID as source_id (matches how deduction was recorded — per item, not per order)
        if ((int)$it['stock_deducted']) {
            restoreStockForMenuItem((int)$it['menu_item_id'], (string)$it['menu_type'], (float)$it['quantity'], 'KDS 86 - item voided', (int)$user['id'], $itemId, 'pos_order');
        }

        $pdo->prepare("UPDATE stock_order_items SET kds_status='void', served_at=NOW(), bumped_by=?, stock_deducted=0 WHERE id=?")
            ->execute([(int)$user['id'], $itemId]);

        $newOrderStatus = kds_recompute_order_status($pdo, $orderId);
        $pdo->prepare("UPDATE stock_orders SET kitchen_status=? WHERE id=?")->execute([$newOrderStatus, $orderId]);

        kds_log($pdo, $orderId, $itemId, 'voided_86', $from, 'void', $user, $ip);

        // Audit trail
        try {
            $pdo->prepare("INSERT INTO stock_order_audit (order_id, actor_id, actor_name, event, details, ip_address) VALUES (?, ?, ?, '86_item', ?, ?)")
                ->execute([$orderId, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', '86: ' . $it['item_name'], $ip]);
        } catch (Throwable $ignored) {
        }

        $pdo->commit();

        // Notify POS cashier via station_messages
        try {
            kds_ensure_station_messages_table($pdo);
            $ordRow = $pdo->prepare("SELECT reference, created_by, table_number FROM stock_orders WHERE id=?");
            $ordRow->execute([$orderId]);
            $ord = $ordRow->fetch(PDO::FETCH_ASSOC);
            if ($ord && (int)($ord['created_by'] ?? 0) > 0) {
                $stnLbl = kds_station_label($STATION);
                $notifMsg = '86 ' . $it['item_name'] . ' on order ' . ($ord['reference'] ?? '') . ' (' . ($ord['table_number'] ?: strtoupper($STATION)) . ') — inform guest and offer alternative.';
                $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'station', 'urgent', ?, ?, ?, 0, NULL)")
                    ->execute([$STATION, $notifMsg, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $orderId, $ord['reference'] ?? '', (int)$ord['created_by']]);
            }
        } catch (Throwable $e) {
            error_log('void_item station msg: ' . $e->getMessage());
        }

        jok(['item_id' => $itemId, 'order_id' => $orderId, 'item_status' => 'void', 'order_status' => $newOrderStatus]);
    }

    /* ── toggle_priority: Rush / un-rush a ticket ───────────────────── *
     * Flips is_priority on stock_orders. Rush tickets bubble to top of
     * the board and show a RUSH badge. POS is notified of rush state.  */
    if ($action === 'toggle_priority') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');

        // Ensure column exists (idempotent)
        kds_ensure_column($pdo, 'stock_orders', 'is_priority', "TINYINT(1) NOT NULL DEFAULT 0");

        $pdo->beginTransaction();
        $ordRow = $pdo->prepare("SELECT id, reference, is_priority, created_by, table_number, order_type FROM stock_orders WHERE id=? FOR UPDATE");
        $ordRow->execute([$orderId]);
        $ord = $ordRow->fetch(PDO::FETCH_ASSOC);
        if (!$ord) {
            $pdo->rollBack();
            jerr('Order not found', 404);
        }

        // Non-privileged: must have at least one item at their station
        if (!$isPrivileged) {
            $vis = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND station=?");
            $vis->execute([$orderId, $STATION]);
            if (!(int)$vis->fetchColumn()) {
                $pdo->rollBack();
                jerr('Order not visible to your station', 403);
            }
        }

        $newPriority = (int)$ord['is_priority'] ? 0 : 1;
        $pdo->prepare("UPDATE stock_orders SET is_priority=?, updated_at=NOW() WHERE id=?")->execute([$newPriority, $orderId]);
        kds_log($pdo, $orderId, null, $newPriority ? 'rush_set' : 'rush_cleared', null, null, $user, $ip);
        $pdo->commit();

        // Notify POS cashier when rush is set or cleared
        if ((int)($ord['created_by'] ?? 0) > 0) {
            try {
                kds_ensure_station_messages_table($pdo);
                $tableLabel = $ord['table_number'] ?: strtoupper(str_replace('_', ' ', (string)($ord['order_type'] ?? '')));
                if ($newPriority) {
                    $rushMsg = 'RUSH requested for order ' . $ord['reference'] . ' (' . $tableLabel . ') by ' . kds_station_label($STATION) . ' — please inform guest of priority handling.';
                } else {
                    $rushMsg = 'Rush cleared on order ' . $ord['reference'] . ' (' . $tableLabel . ') by ' . kds_station_label($STATION) . ' — standard service timing applies.';
                }
                $pdo->prepare("INSERT INTO station_messages (station, message, sent_by, sent_by_name, source, priority, order_id, order_ref, to_user_id, is_acknowledged, acknowledged_at) VALUES (?, ?, ?, ?, 'station', ?, ?, ?, ?, 0, NULL)")
                    ->execute([$STATION, $rushMsg, (int)$user['id'], $user['full_name'] ?? $user['username'] ?? '', $newPriority ? 'urgent' : 'normal', $orderId, (string)$ord['reference'], (int)$ord['created_by']]);
            } catch (Throwable $e) {
                error_log('toggle_priority station msg: ' . $e->getMessage());
            }
        }

        jok(['order_id' => $orderId, 'is_priority' => $newPriority]);
    }

    if (in_array($action, ['start_item', 'ready_item', 'collect_item', 'serve_item'], true)) {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0) jerr('Missing item_id');

        $pdo->beginTransaction();
        $row = $pdo->prepare("SELECT id, order_id, item_name, kds_status, menu_item_id, menu_type, quantity, stock_deducted FROM stock_order_items WHERE id=? FOR UPDATE");
        $row->execute([$itemId]);
        $it = $row->fetch(PDO::FETCH_ASSOC);
        if (!$it) {
            $pdo->rollBack();
            jerr('Item not found', 404);
        }

        $orderId = (int)$it['order_id'];
        $from = $it['kds_status'];
        $to = '';

        if ($action === 'start_item') {
            if (!in_array($from, ['pending', 'preparing'], true)) {
                $pdo->rollBack();
                jerr("Cannot start from $from");
            }
            $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=COALESCE(started_at,NOW()) WHERE id=?")->execute([$itemId]);
            kds_log($pdo, $orderId, $itemId, 'started', $from, 'preparing', $user, $ip);
            $to = 'preparing';
        } elseif ($action === 'ready_item') {
            if (in_array($from, ['served', 'collection'], true)) {
                $pdo->rollBack();
                jerr('Cannot mark ready from ' . $from);
            }
            $shouldDeductStock = empty($it['stock_deducted']);
            if ($shouldDeductStock) {
                $shortages = kds_stock_shortages_for_items($pdo, [$it]);
                if ($shortages) {
                    $pdo->rollBack();
                    jerr(kds_stock_shortage_message($shortages));
                }
            }
            // Deduct stock first (using item ID as source_id to avoid false-dup across items sharing an ingredient)
            $stockDeductedFlag = (int)!$shouldDeductStock; // already deducted = keep 1; needs deduction = check result
            if ($shouldDeductStock) {
                $stockOk = deductStockForMenuItem((int)$it['menu_item_id'], (string)$it['menu_type'], (float)$it['quantity'], 'pos_order', $itemId, (int)$user['id']);
                if (!$stockOk) {
                    rh_log_event('kds', 'warning', "Stock deduction failed for item #{$itemId} (order #{$orderId})", ['item_id' => $itemId, 'order_id' => $orderId]);
                } else {
                    $stockDeductedFlag = 1;
                }
            }
            // Set KDS status; stock_deducted reflects actual deduction result
            $pdo->prepare("UPDATE stock_order_items SET kds_status='ready', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), stock_deducted=? WHERE id=?")->execute([$stockDeductedFlag, $itemId]);
            kds_log($pdo, $orderId, $itemId, 'ready', $from, 'ready', $user, $ip);
            $to = 'ready';
        } elseif ($action === 'collect_item') {
            if ($from !== 'ready') {
                $pdo->rollBack();
                jerr('Can only collect from ready state (current: ' . $from . ')');
            }
            $pdo->prepare("UPDATE stock_order_items SET kds_status='collection' WHERE id=?")->execute([$itemId]);
            kds_log($pdo, $orderId, $itemId, 'collected', $from, 'collection', $user, $ip);
            $to = 'collection';
        } elseif ($action === 'serve_item') {
            if ($from === 'served') {
                $pdo->rollBack();
                jerr('Already served');
            }
            $pdo->prepare("UPDATE stock_order_items SET kds_status='served', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), served_at=NOW(), bumped_by=? WHERE id=?")
                ->execute([(int)$user['id'], $itemId]);
            kds_log($pdo, $orderId, $itemId, 'served', $from, 'served', $user, $ip);
            $to = 'served';
        } else {
            $pdo->rollBack();
            jerr('Unknown item action');
        }

        // Fetch which station this item belongs to so we can check ready-to-collect
        $itemStation = $pdo->prepare("SELECT station FROM stock_order_items WHERE id=?");
        $itemStation->execute([$itemId]);
        $thisStation = (string)($itemStation->fetchColumn() ?: $STATION);

        $newOrderStatus = kds_recompute_order_status($pdo, $orderId);
        $pdo->prepare("UPDATE stock_orders SET kitchen_status=?, served_at=CASE WHEN ?='served' THEN NOW() ELSE served_at END WHERE id=?")
            ->execute([$newOrderStatus, $newOrderStatus, $orderId]);
        $pdo->commit();

        // After commit: notify POS when this station's items are all ready/collected/served
        if (in_array($to, ['ready', 'collection', 'served'], true)) {
            kds_maybe_notify_ready($pdo, $orderId, $thisStation);
        }

        if ($to === 'collection') {
            kds_notify_pos_collection($pdo, $orderId, $thisStation, (string)($it['item_name'] ?? ''), $user);
        }

        jok(['item_id' => $itemId, 'order_id' => $orderId, 'item_status' => $to, 'order_status' => $newOrderStatus]);
    }

    if ($action === 'start_ticket') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');
        $pdo->beginTransaction();
        // Only operate on items belonging to the caller's station (admins: all)
        if ($isPrivileged && !$reqStation) {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=COALESCE(started_at,NOW()) WHERE order_id=? AND kds_status='pending'")->execute([$orderId]);
        } else {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=COALESCE(started_at,NOW()) WHERE order_id=? AND station=? AND kds_status='pending'")->execute([$orderId, $STATION]);
        }
        $newOrderStatus = kds_recompute_order_status($pdo, $orderId);
        $pdo->prepare("UPDATE stock_orders SET kitchen_status=? WHERE id=? AND kitchen_status IN ('new','recalled','in_progress')")->execute([$newOrderStatus, $orderId]);
        kds_log($pdo, $orderId, null, 'started', null, 'in_progress', $user, $ip);
        $pdo->commit();
        jok(['order_id' => $orderId, 'order_status' => $newOrderStatus]);
    }

    if ($action === 'bump_ticket') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');
        $pdo->beginTransaction();

        // Deduct stock for any items that haven't been deducted yet (skipped ready_item step)
        if ($isPrivileged && !$reqStation) {
            $undeducted = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity FROM stock_order_items WHERE order_id=? AND kds_status NOT IN ('served','void') AND stock_deducted=0");
            $undeducted->execute([$orderId]);
        } else {
            $undeducted = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity FROM stock_order_items WHERE order_id=? AND station=? AND kds_status NOT IN ('served','void') AND stock_deducted=0");
            $undeducted->execute([$orderId, $STATION]);
        }
        $toDeduct = $undeducted->fetchAll(PDO::FETCH_ASSOC);
        $shortages = kds_stock_shortages_for_items($pdo, $toDeduct);
        if ($shortages) {
            $pdo->rollBack();
            jerr(kds_stock_shortage_message($shortages));
        }
        $deductedItemIds = [];
        foreach ($toDeduct as $tdi) {
            // Pass item ID (not order ID) to avoid false idempotency skip for items sharing an ingredient
            $stockOk = deductStockForMenuItem((int)$tdi['menu_item_id'], (string)$tdi['menu_type'], (float)$tdi['quantity'], 'pos_order', (int)$tdi['id'], (int)$user['id']);
            if ($stockOk) {
                $deductedItemIds[] = (int)$tdi['id'];
            } else {
                rh_log_event('kds', 'warning', "Bump: stock deduction failed for item #{$tdi['id']} (order #{$orderId})", ['item_id' => (int)$tdi['id'], 'order_id' => $orderId]);
            }
        }
        if (!empty($deductedItemIds)) {
            $placeholders = implode(',', array_fill(0, count($deductedItemIds), '?'));
            $pdo->prepare("UPDATE stock_order_items SET stock_deducted=1 WHERE id IN ({$placeholders})")->execute($deductedItemIds);
        }

        if ($isPrivileged && !$reqStation) {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='served', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), served_at=NOW(), bumped_by=? WHERE order_id=? AND kds_status NOT IN ('served','void')")
                ->execute([(int)$user['id'], $orderId]);
        } else {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='served', started_at=COALESCE(started_at,NOW()), ready_at=COALESCE(ready_at,NOW()), served_at=NOW(), bumped_by=? WHERE order_id=? AND station=? AND kds_status NOT IN ('served','void')")
                ->execute([(int)$user['id'], $orderId, $STATION]);
        }
        $newOrderStatus = kds_recompute_order_status($pdo, $orderId);
        $pdo->prepare("UPDATE stock_orders SET kitchen_status=?, served_at=CASE WHEN ?='served' THEN NOW() ELSE served_at END WHERE id=?")
            ->execute([$newOrderStatus, $newOrderStatus, $orderId]);
        kds_log($pdo, $orderId, null, 'bumped', null, $newOrderStatus, $user, $ip);
        $pdo->commit();
        // Bumping a station's items marks them served — notify POS
        kds_maybe_notify_ready($pdo, $orderId, $STATION);
        jok(['order_id' => $orderId, 'order_status' => $newOrderStatus]);
    }

    if ($action === 'recall_ticket') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');
        $pdo->beginTransaction();
        // Only allow recall within 10 minutes of bump
        $st = $pdo->prepare("SELECT served_at FROM stock_orders WHERE id=? FOR UPDATE");
        $st->execute([$orderId]);
        $srv = $st->fetchColumn();
        if (!$srv) {
            $pdo->rollBack();
            jerr('Recall window expired (10 minutes).');
        }
        $servedTimestamp = 0;
        $nowTimestamp = 0;
        try {
            $servedAt = new DateTimeImmutable((string)$srv, new DateTimeZone('UTC'));
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $servedTimestamp = $servedAt->getTimestamp();
            $nowTimestamp = $nowUtc->getTimestamp();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jerr('Recall window expired (10 minutes).');
        }
        if ($servedTimestamp < $nowTimestamp - 600) {
            $pdo->rollBack();
            jerr('Recall window expired (10 minutes).');
        }

        // Restore stock for items that were deducted (at ready_item time) and are now being recalled
        if ($isPrivileged && !$reqStation) {
            $recallStmt = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity FROM stock_order_items WHERE order_id=? AND kds_status IN ('served','collection') AND stock_deducted=1");
            $recallStmt->execute([$orderId]);
        } else {
            $recallStmt = $pdo->prepare("SELECT id, menu_item_id, menu_type, quantity FROM stock_order_items WHERE order_id=? AND station=? AND kds_status IN ('served','collection') AND stock_deducted=1");
            $recallStmt->execute([$orderId, $STATION]);
        }
        $itemsToRestore = $recallStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itemsToRestore as $ri) {
            // Use item ID as source_id (matches deduction record — per item, not per order)
            restoreStockForMenuItem((int)$ri['menu_item_id'], (string)$ri['menu_type'], (float)$ri['quantity'], 'KDS recall - stock restored', (int)$user['id'], (int)$ri['id'], 'pos_order');
        }

        // Recall served and collection items back to preparing; clear stock_deducted flag
        if ($isPrivileged && !$reqStation) {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', served_at=NULL, stock_deducted=0 WHERE order_id=? AND kds_status IN ('served','collection')")->execute([$orderId]);
        } else {
            $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', served_at=NULL, stock_deducted=0 WHERE order_id=? AND station=? AND kds_status IN ('served','collection')")->execute([$orderId, $STATION]);
        }
        $pdo->prepare("UPDATE stock_orders SET kitchen_status='recalled', served_at=NULL WHERE id=?")->execute([$orderId]);

        /* Clear the ready-for-collection notification for this order+station. Without this the
         * recall silently breaks the POS notification for the rest of the order's life:
         * kds_maybe_notify_ready() dedupes on (order_id, station) with no expiry, so once the
         * food is re-made and bumped ready a SECOND time it finds the stale row and returns
         * early — the waiter is never told the re-fired order is ready. Deleting the row here
         * restores the "notify once per ready cycle" behaviour the dedupe was meant to give. */
        if ($isPrivileged && !$reqStation) {
            $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id=?")->execute([$orderId]);
        } else {
            $pdo->prepare("DELETE FROM pos_ready_notifications WHERE order_id=? AND station=?")->execute([$orderId, $STATION]);
        }

        kds_log($pdo, $orderId, null, 'recalled', 'served', 'recalled', $user, $ip);
        $pdo->commit();
        jok(['order_id' => $orderId, 'order_status' => 'recalled', 'stock_restored' => count($itemsToRestore)]);
    }

    if ($action === 'served_today') {
        $requestedDayRaw = (string)($_POST['day'] ?? 'current');
        $requestedDay = in_array($requestedDayRaw, ['current', 'yesterday', 'after_hours'], true) ? $requestedDayRaw : 'current';
        $window = kds_requested_window($STATION, $requestedDay);

        $st = $pdo->prepare("SELECT o.id, o.reference, o.table_number, o.customer_name, o.order_type,
                                    o.created_at, o.created_by, o.fired_at, o.served_at, o.total_amount, o.kitchen_status,
                                    COALESCE(NULLIF(MAX(u.full_name), ''), MAX(u.username), 'Unknown') AS ordered_by,
                                    SUM(oi.quantity) AS qty,
                                    SUM(oi.line_total) AS revenue,
                                    COUNT(oi.id) AS line_count,
                                    GROUP_CONCAT(DISTINCT oi.station ORDER BY oi.station SEPARATOR ',') AS stations,
                                    MIN(oi.started_at) AS first_started_at,
                                    MAX(oi.ready_at) AS last_ready_at,
                                    MAX(oi.served_at) AS last_served_at,
                                    TIMESTAMPDIFF(SECOND, o.fired_at, MAX(oi.served_at)) AS prep_seconds
                               FROM stock_orders o
                               JOIN stock_order_items oi ON oi.order_id=o.id
                               LEFT JOIN admin_users u ON u.id = o.created_by
                              WHERE oi.kds_status='served'
                                                                AND oi.station = ?
                                                                AND oi.served_at >= ?
                                                                AND oi.served_at < ?
                              GROUP BY o.id
                              ORDER BY MAX(oi.served_at) DESC");
        $st->execute([$STATION, $window['start_sql'], $window['end_sql']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $itemsByOrder = [];
        if ($rows) {
            $orderIds = array_map(static fn($row) => (int)$row['id'], $rows);
            $place = implode(',', array_fill(0, count($orderIds), '?'));
            $itemStmt = $pdo->prepare("SELECT order_id, id, item_name, quantity, notes, line_total, kds_status, station, menu_type,
                                             started_at, ready_at, served_at,
                                             TIMESTAMPDIFF(SECOND, started_at, served_at) AS item_seconds
                                        FROM stock_order_items
                                       WHERE order_id IN ($place)
                                         AND station = ?
                                         AND kds_status = 'served'
                                         AND served_at >= ?
                                         AND served_at < ?
                                    ORDER BY served_at DESC, id ASC");
            $itemStmt->execute(array_merge($orderIds, [$STATION, $window['start_sql'], $window['end_sql']]));
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
                $itemsByOrder[(int)$itemRow['order_id']][] = $itemRow;
            }
            foreach ($rows as &$servedRow) {
                $servedRow['items'] = $itemsByOrder[(int)$servedRow['id']] ?? [];
            }
            unset($servedRow);
        }

        $totalQty = 0;
        $totalRev = 0.0;
        $totalSec = 0;
        $sample = 0;
        foreach ($rows as $r) {
            $totalQty += (float)$r['qty'];
            $totalRev += (float)$r['revenue'];
            if ((int)$r['prep_seconds'] > 0 && (int)$r['prep_seconds'] < 21600) {
                $totalSec += (int)$r['prep_seconds'];
                $sample++;
            }
        }

        $logSql = "SELECT e.id, e.event, e.from_status, e.to_status, e.user_name, e.created_at,
                          o.reference, oi.item_name, oi.station
                     FROM stock_kds_events e
                     LEFT JOIN stock_orders o ON o.id = e.order_id
                     LEFT JOIN stock_order_items oi ON oi.id = e.order_item_id
                    WHERE e.created_at >= ?
                      AND e.created_at < ?
                      AND (
                          oi.station = ?
                          OR (e.order_item_id IS NULL AND EXISTS (
                              SELECT 1 FROM stock_order_items soi WHERE soi.order_id = e.order_id AND soi.station = ?
                          ))
                      )
                 ORDER BY e.created_at DESC, e.id DESC
                    LIMIT 80";
        $logStmt = $pdo->prepare($logSql);
        $logStmt->execute([$window['start_sql'], $window['end_sql'], $STATION, $STATION]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        jok([
            'tickets'    => $rows,
            'logs'       => $logs,
            'total_qty'  => $totalQty,
            'revenue'    => $totalRev,
            'avg_seconds' => $sample ? (int)round($totalSec / $sample) : 0,
            'scope'      => $STATION,
            'day'        => $requestedDay,
            'window'     => [
                'business_date' => $window['business_date'],
                'start' => $window['start_sql'],
                'end' => $window['end_sql'],
                'label' => $window['window_label'],
                'hours' => $window['hours_label'],
            ],
            'now'        => date('c'),
        ]);
    }

    if ($action === 'view_order') {
        // Cross-station read-only peek — returns ALL lines on the order regardless of station,
        // so a chef can see what the bar is also preparing for table 5, etc.
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) jerr('Missing order_id');
        $st = $pdo->prepare("SELECT id, reference, table_number, customer_name, order_type, kitchen_status,
                                    fired_at, kitchen_printed_at, served_at, notes, total_amount, status
                               FROM stock_orders WHERE id=?");
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) jerr('Order not found', 404);

        // Visibility guard for non-privileged: must have at least one item on this order at their station.
        if (!$isPrivileged) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND station=?");
            $check->execute([$orderId, $STATION]);
            if (!(int)$check->fetchColumn()) jerr('Order not visible to this station', 403);
        }

        $iSt = $pdo->prepare("SELECT id, item_name, quantity, notes, kds_status, station, menu_type,
                                     line_total, started_at, ready_at, served_at
                                FROM stock_order_items
                               WHERE order_id=?
                            ORDER BY station, id");
        $iSt->execute([$orderId]);
        $order['items'] = $iSt->fetchAll(PDO::FETCH_ASSOC);
        jok(['order' => $order]);
    }

    if ($action === 'feed') {
        // Lightweight feed used by the display auto-refresh — returns counts + ticket payload as JSON.
        // Filtered by station for staff; admin/manager see everything.
        // Use union window start (earliest station open) so orders fired before this station's
        // opening time are still included (e.g. drinks ordered before bar opens at 11:00).
        // Ensure is_priority column exists before querying it (first-time migration safety)
        kds_ensure_column($pdo, 'stock_orders', 'is_priority', "TINYINT(1) NOT NULL DEFAULT 0");
        $allDay = kds_all_day_summary($pdo, $STATION, $stationWindow);
        $messages = kds_recent_station_messages($pdo, $STATION);
        $messageSig = kds_message_signature($messages);
        $control = kds_get_station_control($pdo, $STATION);
        $servedCountStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id)
            FROM stock_orders o
            INNER JOIN stock_order_items oi ON oi.order_id = o.id
            WHERE oi.station = ?
              AND oi.kds_status = 'served'
              AND oi.served_at >= ?
              AND oi.served_at < ?");
        $servedCountStmt->execute([$STATION, $stationWindow['start_sql'], $stationWindow['end_sql']]);
        $servedToday = (int)$servedCountStmt->fetchColumn();

        /* No fired_at window here: kitchen_status IN ('new','in_progress','ready','recalled')
         * already scopes this to unfinished tickets only — a fully served/voided order can
         * never match it. Gating on top of that by "fired within the current business window"
         * meant an order fired before the window opened (a late-running previous shift, a
         * kitchen item nobody bumped in time) simply vanished from every station board with
         * no way to see it, finish it, or settle its tab. The window is still used everywhere
         * else (served-today counts, Z-report, accounting) — only this "what still needs
         * doing" query drops it. */
        if ($isPrivileged && !$reqStation) {
            $st = $pdo->prepare("SELECT o.id, o.reference, o.table_number, o.customer_name, o.order_type, o.kitchen_status, o.fired_at, o.kitchen_printed_at, o.served_at, o.notes, o.created_at, o.created_by, COALESCE(o.is_priority,0) AS is_priority,
                                                                             COALESCE(NULLIF(u.full_name, ''), u.username, 'POS') AS ordered_by
                                                                    FROM stock_orders o
                                                                    LEFT JOIN admin_users u ON u.id = o.created_by
                                                                 WHERE o.kitchen_status IN ('new','in_progress','ready','recalled')
                                                                     AND o.fired_at IS NOT NULL
                                                            ORDER BY COALESCE(o.is_priority,0) DESC, o.fired_at ASC");
            $st->execute();
        } else {
            $st = $pdo->prepare("SELECT o.id, o.reference, o.table_number, o.customer_name, o.order_type, o.kitchen_status, o.fired_at, o.kitchen_printed_at, o.served_at, o.notes, o.created_at, o.created_by, COALESCE(o.is_priority,0) AS is_priority,
                                                                             COALESCE(NULLIF(u.full_name, ''), u.username, 'POS') AS ordered_by
                                  FROM stock_orders o
                                                                    LEFT JOIN admin_users u ON u.id = o.created_by
                                 WHERE o.kitchen_status IN ('new','in_progress','ready','recalled')
                                   AND o.fired_at IS NOT NULL
                                   AND EXISTS (SELECT 1 FROM stock_order_items oi WHERE oi.order_id = o.id AND oi.station = ? AND oi.kds_status NOT IN ('served','void'))
                              ORDER BY COALESCE(o.is_priority,0) DESC, o.fired_at ASC");
            $st->execute([$STATION]);
        }
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
        /* Accept a board fingerprint from the client so we can skip the heavy
           JSON payload + DOM rebuild when nothing has changed since last poll. */
        $clientFp = trim((string)($_POST['fingerprint'] ?? ''));

        if (!$orders) {
            /* Compute a lightweight fingerprint even for the empty-board case
               so message arrivals and station-control changes still force a re-render. */
            $emptyFp = md5(
                'empty|msgs:' . $messageSig .
                    '|ctrl:' . (($control['paused'] ?? false) ? '1' : '0') . ':' . ($control['wait_minutes'] ?? '20') .
                    '|sc:' . ($servedToday)
            );
            if ($clientFp !== '' && $clientFp === $emptyFp) {
                jok([
                    'unchanged' => true,
                    'fingerprint' => $emptyFp,
                    'now' => date('c'),
                    'station_control' => $control,
                    'business_window' => ['label' => $stationWindow['window_label'], 'hours' => $stationWindow['hours_label'], 'is_open_now' => $stationWindow['is_open_now']]
                ]);
            }
            jok([
                'tickets' => [],
                'served_today' => $servedToday,
                'all_day' => $allDay,
                'messages' => $messages,
                'station_control' => $control,
                'fingerprint' => $emptyFp,
                'now' => date('c'),
                'station' => $STATION,
                'business_window' => ['label' => $stationWindow['window_label'], 'hours' => $stationWindow['hours_label'], 'is_open_now' => $stationWindow['is_open_now']],
            ]);
        }

        $ids = array_column($orders, 'id');
        $place = implode(',', array_fill(0, count($ids), '?'));
        // Always return ALL items on matching orders so each station can see the full context.
        // Each item carries an `is_mine` flag (1/0) so the frontend can highlight vs grey-out.
        $itemSql = "SELECT id, order_id, item_name, quantity, notes, kds_status, started_at, ready_at, menu_type, station,
                           IF(station = ?, 1, 0) AS is_mine
                      FROM stock_order_items
                     WHERE order_id IN ($place)
                     ORDER BY order_id, (station <> ?) ASC, id ASC";
        $params = [$STATION, ...$ids, $STATION];
        $iSt = $pdo->prepare($itemSql);
        $iSt->execute($params);
        $items = $iSt->fetchAll(PDO::FETCH_ASSOC);
        $byOrder = [];
        foreach ($items as $it) $byOrder[(int)$it['order_id']][] = $it;
        foreach ($orders as &$o) $o['items'] = $byOrder[(int)$o['id']] ?? [];

        /* Fingerprint covers every visible board field, plus messages/control/served counts. */
        $fpParts = array_map(static function (array $order): string {
            $itemParts = array_map(static function (array $item): string {
                return implode(':', [
                    (string)($item['id'] ?? ''),
                    (string)($item['station'] ?? ''),
                    (string)($item['kds_status'] ?? ''),
                    (string)($item['quantity'] ?? ''),
                    substr(sha1((string)($item['item_name'] ?? '')), 0, 10),
                    substr(sha1((string)($item['notes'] ?? '')), 0, 10),
                    (string)($item['started_at'] ?? ''),
                    (string)($item['ready_at'] ?? ''),
                ]);
            }, $order['items'] ?? []);

            return implode(':', [
                (string)($order['id'] ?? ''),
                (string)($order['reference'] ?? ''),
                (string)($order['kitchen_status'] ?? ''),
                (string)($order['fired_at'] ?? ''),
                (string)($order['served_at'] ?? ''),
                (string)($order['is_priority'] ?? 0),
                substr(sha1((string)($order['notes'] ?? '')), 0, 10),
                implode(',', $itemParts),
            ]);
        }, $orders);
        $fingerprint = md5(
            implode('|', $fpParts) .
                '|msgs:' . $messageSig .
                '|all:' . substr(sha1((string)json_encode($allDay)), 0, 12) .
                '|ctrl:' . (($control['paused'] ?? false) ? '1' : '0') . ':' . ($control['wait_minutes'] ?? '20') . ':' . substr(sha1((string)($control['reason'] ?? '')), 0, 10) .
                '|sc:' . $servedToday
        );

        /* Return a minimal response when the board hasn't changed — client skips DOM rebuild. */
        if ($clientFp !== '' && $clientFp === $fingerprint) {
            jok([
                'unchanged' => true,
                'fingerprint' => $fingerprint,
                'now' => date('c'),
                'station_control' => $control,
                'business_window' => ['label' => $stationWindow['window_label'], 'hours' => $stationWindow['hours_label'], 'is_open_now' => $stationWindow['is_open_now']]
            ]);
        }

        jok([
            'tickets' => $orders,
            'served_today' => $servedToday,
            'all_day' => $allDay,
            'messages' => $messages,
            'station_control' => $control,
            'fingerprint' => $fingerprint,
            'now' => date('c'),
            'station' => $STATION,
            'business_window' => ['label' => $stationWindow['window_label'], 'hours' => $stationWindow['hours_label'], 'is_open_now' => $stationWindow['is_open_now']],
        ]);
    }

    jerr("Unknown action: $action");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jerr($e->getMessage(), 500);
}
