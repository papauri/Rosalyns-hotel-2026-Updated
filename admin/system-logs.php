<?php

/**
 * System Logs
 * Aggregates operational logs for the admin portal.
 */

require_once 'admin-init.php';

$user = $user ?? ['id' => 0];

if (!hasPermission((int)$user['id'], 'system_logs')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name');
$sourceFilter = trim((string)($_GET['source'] ?? 'all'));
$levelFilter = trim((string)($_GET['level'] ?? 'all'));
$limit = min(500, max(20, (int)($_GET['limit'] ?? 50)));
$loginScanLimit = max(120, min(2000, $limit * 6));
$autoRefresh = isset($_GET['auto']) && $_GET['auto'] === '1';

$validLevels = ['all', 'debug', 'info', 'warning', 'error', 'critical'];
if (!in_array($levelFilter, $validLevels, true)) {
    $levelFilter = 'all';
}

function rh_logs_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM information_schema.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rh_tail_file(string $path, int $lines = 80): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    try {
        $file = new SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines);
        $output = [];
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = rtrim((string)$file->current(), "\r\n");
            if ($line !== '') {
                $output[] = $line;
            }
            $file->next();
        }
        return array_reverse($output);
    } catch (Throwable $e) {
        return [];
    }
}

function rh_log_level_class(string $level): string
{
    $level = strtolower($level);
    if (in_array($level, ['error', 'critical'], true)) return 'danger';
    if ($level === 'warning') return 'warning';
    if ($level === 'debug') return 'muted';
    return 'success';
}

function rh_login_browser_label(string $ua): string
{
    $browserLabel = 'Unknown';
    if (stripos($ua, 'Edg/') !== false) {
        $browserLabel = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false) {
        $browserLabel = 'Opera';
    } elseif (stripos($ua, 'Chrome/') !== false) {
        $browserLabel = 'Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browserLabel = 'Firefox';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $browserLabel = 'Safari';
    }

    if (stripos($ua, 'Windows') !== false) {
        $browserLabel .= ' / Windows';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $browserLabel .= ' / macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $browserLabel .= ' / Linux';
    } elseif (stripos($ua, 'Android') !== false) {
        $browserLabel .= ' / Android';
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $browserLabel .= ' / iOS';
    }

    return $browserLabel;
}

$dbEvents = [];
$stats = [
    'system_events' => 0,
    'admin_activity' => 0,
    'api_calls' => 0,
    'offline_replays' => 0,
    'errors' => 0,
    'pos_orders' => 0,
    'kds_events' => 0,
];

try {
    rh_ensure_system_event_log_table($pdo);

    // Log the page view before querying so it appears in the current result set.
    rh_log_event('system_logs', 'debug', 'System logs viewed', ['source' => $sourceFilter, 'level' => $levelFilter, 'limit' => $limit]);

    if (rh_logs_table_exists($pdo, 'system_event_log')) {
        $where = [];
        $args = [];
        if ($sourceFilter !== '' && $sourceFilter !== 'all') {
            $where[] = 'source = ?';
            $args[] = $sourceFilter;
        }
        if ($levelFilter !== 'all') {
            $where[] = 'level = ?';
            $args[] = $levelFilter;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $pdo->prepare("SELECT * FROM system_event_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $stmt->execute($args);
        $dbEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats['system_events'] = (int)$pdo->query("SELECT COUNT(*) FROM system_event_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        $stats['errors'] = (int)$pdo->query("SELECT COUNT(*) FROM system_event_log WHERE level IN ('error','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }

    if (rh_logs_table_exists($pdo, 'admin_activity_log')) {
        $stats['admin_activity'] = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    if (rh_logs_table_exists($pdo, 'api_usage_logs')) {
        $stats['api_calls'] = (int)$pdo->query("SELECT COUNT(*) FROM api_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    if (rh_logs_table_exists($pdo, 'offline_replay_log')) {
        $stats['offline_replays'] = (int)$pdo->query("SELECT COUNT(*) FROM offline_replay_log WHERE replayed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    if (rh_logs_table_exists($pdo, 'stock_orders')) {
        $stats['pos_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
    if (rh_logs_table_exists($pdo, 'stock_kds_events')) {
        $stats['kds_events'] = (int)$pdo->query("SELECT COUNT(*) FROM stock_kds_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    }
} catch (Throwable $e) {
    rh_log_event('system_logs', 'error', 'Failed to load system log summary', ['error' => $e->getMessage()]);
}

$adminActivity = [];
if (rh_logs_table_exists($pdo, 'admin_activity_log')) {
    try {
        $stmt = $pdo->prepare("SELECT user_id, username, action, details, ip_address, created_at FROM admin_activity_log ORDER BY created_at DESC LIMIT {$limit}");
        $stmt->execute();
        $adminActivity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$bookingAuditEvents = [];
if (rh_logs_table_exists($pdo, 'booking_audit_log')) {
    try {
        $stmt = $pdo->prepare("SELECT bal.*, au.username AS performed_by_username FROM booking_audit_log bal LEFT JOIN admin_users au ON au.id = bal.performed_by ORDER BY bal.performed_at DESC LIMIT {$limit}");
        $stmt->execute();
        $bookingAuditEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stats['booking_changes'] = (int)$pdo->query("SELECT COUNT(*) FROM booking_audit_log WHERE performed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Throwable $e) {
        $stats['booking_changes'] = 0;
    }
} else {
    $stats['booking_changes'] = 0;
}

$apiActivity = [];
if (rh_logs_table_exists($pdo, 'api_usage_logs')) {
    try {
        $stmt = $pdo->prepare("
            SELECT l.endpoint, l.method, l.ip_address, l.response_code, l.response_time, l.created_at, k.client_name
            FROM api_usage_logs l
            LEFT JOIN api_keys k ON k.id = l.api_key_id
            ORDER BY l.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $apiActivity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$offlineActivity = [];
if (rh_logs_table_exists($pdo, 'offline_replay_log')) {
    try {
        $stmt = $pdo->prepare("
            SELECT username, endpoint, action, entity_reference, response_status, response_summary, replayed_at
            FROM offline_replay_log
            ORDER BY replayed_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $offlineActivity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$recentLogins = [];
$recentLoginBundles = [];
if (rh_logs_table_exists($pdo, 'admin_activity_log')) {
    try {
        $stmt = $pdo->prepare("
            SELECT user_id, username, action, details, ip_address, user_agent, created_at
            FROM admin_activity_log
            WHERE action LIKE 'login%'
            ORDER BY created_at DESC
            LIMIT {$loginScanLimit}
        ");
        $stmt->execute();
        $recentLogins = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userIds = [];
        foreach ($recentLogins as $loginRow) {
            $uid = (int)($loginRow['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }

        if ($userIds) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $metaStmt = $pdo->prepare("SELECT id, full_name, role FROM admin_users WHERE id IN ({$placeholders})");
            $metaStmt->execute(array_keys($userIds));

            $userMeta = [];
            foreach (($metaStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $metaRow) {
                $userMeta[(int)$metaRow['id']] = [
                    'full_name' => (string)($metaRow['full_name'] ?? ''),
                    'role' => (string)($metaRow['role'] ?? ''),
                ];
            }

            foreach ($recentLogins as &$loginRow) {
                $uid = (int)($loginRow['user_id'] ?? 0);
                $loginRow['full_name'] = $userMeta[$uid]['full_name'] ?? '';
                $loginRow['role'] = $userMeta[$uid]['role'] ?? '';
            }
            unset($loginRow);
        }

        // Bundle consecutive login events from the same source (IP + session-like signature)
        // into one expandable row so repeated retries don't flood the table.
        $bundleWindowSeconds = 45 * 60;
        foreach ($recentLogins as $loginRow) {
            $timestamp = strtotime((string)($loginRow['created_at'] ?? '')) ?: 0;
            $usernameNorm = strtolower(trim((string)($loginRow['username'] ?? '')));
            $ipNorm = trim((string)($loginRow['ip_address'] ?? ''));
            $userAgent = (string)($loginRow['user_agent'] ?? '');
            $detailsText = (string)($loginRow['details'] ?? '');

            $sessionKey = $usernameNorm . '|' . $ipNorm . '|' . sha1($userAgent);
            if (preg_match('/sid:([a-f0-9]{8,64})/i', $detailsText, $sidMatch)) {
                $sessionKey = 'sid:' . strtolower((string)$sidMatch[1]);
            }

            $lastIndex = count($recentLoginBundles) - 1;
            $canMerge = false;
            if ($lastIndex >= 0) {
                $lastBundle = $recentLoginBundles[$lastIndex];
                $gapSeconds = $lastBundle['latest_ts'] - $timestamp;
                $canMerge = $lastBundle['session_key'] === $sessionKey
                    && $gapSeconds >= 0
                    && $gapSeconds <= $bundleWindowSeconds;
            }

            if (!$canMerge) {
                $recentLoginBundles[] = [
                    'session_key' => $sessionKey,
                    'latest_ts' => $timestamp,
                    'latest_at' => (string)($loginRow['created_at'] ?? ''),
                    'oldest_at' => (string)($loginRow['created_at'] ?? ''),
                    'username' => (string)($loginRow['username'] ?? ''),
                    'full_name' => (string)($loginRow['full_name'] ?? ''),
                    'role' => (string)($loginRow['role'] ?? ''),
                    'ip_address' => (string)($loginRow['ip_address'] ?? ''),
                    'browser_label' => rh_login_browser_label($userAgent),
                    'success_count' => 0,
                    'failed_count' => 0,
                    'blocked_count' => 0,
                    'attempts' => [],
                ];
                $lastIndex = count($recentLoginBundles) - 1;
            }

            $attemptAction = (string)($loginRow['action'] ?? '');
            if ($attemptAction === 'login_success') {
                $recentLoginBundles[$lastIndex]['success_count']++;
            } elseif ($attemptAction === 'login_blocked') {
                $recentLoginBundles[$lastIndex]['blocked_count']++;
            } else {
                $recentLoginBundles[$lastIndex]['failed_count']++;
            }

            $recentLoginBundles[$lastIndex]['oldest_at'] = (string)($loginRow['created_at'] ?? $recentLoginBundles[$lastIndex]['oldest_at']);
            $recentLoginBundles[$lastIndex]['attempts'][] = [
                'created_at' => (string)($loginRow['created_at'] ?? ''),
                'action' => $attemptAction,
                'details' => $detailsText,
            ];
        }

        $recentLoginBundles = array_slice($recentLoginBundles, 0, $limit);
        foreach ($recentLoginBundles as &$bundle) {
            $bundle['attempt_count'] = count($bundle['attempts']);
            $typeCount = 0;
            if ($bundle['success_count'] > 0) $typeCount++;
            if ($bundle['failed_count'] > 0) $typeCount++;
            if ($bundle['blocked_count'] > 0) $typeCount++;

            if ($typeCount > 1) {
                $bundle['result_class'] = 'warning';
                $bundle['result_label'] = 'Mixed';
            } elseif ($bundle['success_count'] > 0) {
                $bundle['result_class'] = 'success';
                $bundle['result_label'] = 'Success';
            } elseif ($bundle['blocked_count'] > 0) {
                $bundle['result_class'] = 'warning';
                $bundle['result_label'] = 'Blocked';
            } else {
                $bundle['result_class'] = 'danger';
                $bundle['result_label'] = 'Failed';
            }
        }
        unset($bundle);
    } catch (Throwable $e) {
    }
}

// ── POS: recent orders ──────────────────────────────────────────────────────
$posOrders = [];
$posOrderAudit = [];
$currencyCode = getSetting('currency_code', '');
if (rh_logs_table_exists($pdo, 'stock_orders')) {
    try {
        $stmt = $pdo->prepare("
            SELECT so.id, so.reference, so.order_type, so.status, so.table_number, so.room_number,
                   so.total_amount, so.payment_method, so.voided_at, so.void_reason,
                   so.paid_at, so.created_at, so.opened_as_tab, so.is_priority,
                   au.username AS created_by_name
            FROM stock_orders so
            LEFT JOIN admin_users au ON au.id = so.created_by
            ORDER BY so.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $posOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}
if (rh_logs_table_exists($pdo, 'stock_order_audit')) {
    try {
        $stmt = $pdo->prepare("
            SELECT soa.id, soa.order_id, soa.actor_name, soa.event, soa.details, soa.ip_address, soa.created_at,
                   so.reference
            FROM stock_order_audit soa
            LEFT JOIN stock_orders so ON so.id = soa.order_id
            ORDER BY soa.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $posOrderAudit = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}
// Group audit rows by order_id for dropdown lookup in the template
$posAuditByOrder = [];
foreach ($posOrderAudit as $auditRow) {
    $posAuditByOrder[(int)$auditRow['order_id']][] = $auditRow;
}

// ── Display Screens: KDS / BDS / CDS events ─────────────────────────────────
$kdsEvents = [];
$stationMessages = [];
$shiftCloses = [];
if (rh_logs_table_exists($pdo, 'stock_kds_events')) {
    try {
        $stmt = $pdo->prepare("
            SELECT ske.id, ske.event, ske.from_status, ske.to_status, ske.user_name, ske.ip_address, ske.created_at,
                   so.reference, so.table_number,
                   soi.item_name, soi.station
            FROM stock_kds_events ske
            LEFT JOIN stock_orders so ON so.id = ske.order_id
            LEFT JOIN stock_order_items soi ON soi.id = ske.order_item_id
            ORDER BY ske.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $kdsEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}
if (rh_logs_table_exists($pdo, 'station_messages')) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, station, message, sent_by_name, source, priority, is_acknowledged, order_ref, created_at
            FROM station_messages
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $stationMessages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}
if (rh_logs_table_exists($pdo, 'stock_shift_closes')) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, user_name, shift_date, closed_at, total_revenue, orders_count,
                   voids_count, voids_amount, declared_cash, variance_cash, notes
            FROM stock_shift_closes
            ORDER BY closed_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $shiftCloses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
}

$logDir = dirname(__DIR__) . '/logs';
$fileLogs = [
    'system-events.log' => rh_tail_file($logDir . '/system-events.log', 80),
    'php-errors.log' => rh_tail_file($logDir . '/php-errors.log', 80),
    'backup.log' => rh_tail_file($logDir . '/backup.log', 80),
    'visitor-sessions.log' => rh_tail_file($logDir . '/visitor-sessions.log', 80),
    'whatsapp-log.txt' => rh_tail_file($logDir . '/whatsapp-log.txt', 80),
];

$sources = ['all'];
foreach ($dbEvents as $event) {
    $src = (string)($event['source'] ?? '');
    if ($src !== '' && !in_array($src, $sources, true)) {
        $sources[] = $src;
    }
}
sort($sources);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/system-logs.css?v=<?php echo @filemtime(__DIR__ . '/css/system-logs.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-clipboard-list" style="color:var(--gold)"></i> System Logs</h1>
            <p class="text-muted">Live operational view across admin actions, API calls, offline replay, backups, visitors, WhatsApp and PHP errors.</p>
        </div>

        <div class="log-grid">
            <div class="log-card"><small>System events, 24h</small><strong><?php echo number_format($stats['system_events']); ?></strong></div>
            <div class="log-card"><small>Admin activity, 24h</small><strong><?php echo number_format($stats['admin_activity']); ?></strong></div>
            <div class="log-card"><small>Booking changes, 24h</small><strong><?php echo number_format($stats['booking_changes']); ?></strong></div>
            <div class="log-card"><small>POS orders, 24h</small><strong><?php echo number_format($stats['pos_orders']); ?></strong></div>
            <div class="log-card"><small>KDS/DS events, 24h</small><strong><?php echo number_format($stats['kds_events']); ?></strong></div>
            <div class="log-card"><small>API calls, 24h</small><strong><?php echo number_format($stats['api_calls']); ?></strong></div>
            <div class="log-card"><small>Offline replays, 24h</small><strong><?php echo number_format($stats['offline_replays']); ?></strong></div>
            <div class="log-card"><small>Errors, 24h</small><strong><?php echo number_format($stats['errors']); ?></strong></div>
        </div>

        <form method="get" class="log-toolbar">
            <label>Source
                <select name="source">
                    <?php foreach ($sources as $source): ?>
                        <option value="<?php echo htmlspecialchars($source); ?>" <?php echo $sourceFilter === $source ? 'selected' : ''; ?>><?php echo htmlspecialchars($source); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Level
                <select name="level">
                    <?php foreach ($validLevels as $level): ?>
                        <option value="<?php echo htmlspecialchars($level); ?>" <?php echo $levelFilter === $level ? 'selected' : ''; ?>><?php echo htmlspecialchars($level); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Rows
                <input type="number" name="limit" min="20" max="500" value="<?php echo (int)$limit; ?>">
            </label>
            <label style="flex-direction:row;align-items:center;gap:8px;margin-bottom:4px;">
                <input type="checkbox" name="auto" value="1" <?php echo $autoRefresh ? 'checked' : ''; ?>> Auto-refresh 15s
            </label>
            <button type="submit"><i class="fas fa-filter"></i> Apply</button>
            <a href="system-logs.php"><i class="fas fa-rotate-left"></i> Reset</a>
        </form>

        <div class="log-section">
            <h3><i class="fas fa-stream"></i> System Event Stream</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Source</th>
                            <th>Level</th>
                            <th>Message</th>
                            <th>User</th>
                            <th>Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$dbEvents): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:#6b7280;padding:30px;">No system events yet. New admin actions will appear here automatically.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dbEvents as $event): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$event['created_at']); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)$event['source']); ?></code></td>
                                    <td><span class="log-pill <?php echo rh_log_level_class((string)$event['level']); ?>"><?php echo htmlspecialchars((string)$event['level']); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)$event['message']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($event['username'] ?: ($event['user_id'] ? '#' . $event['user_id'] : '-'))); ?></td>
                                    <td class="context-json"><?php echo htmlspecialchars((string)($event['context_json'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-clock-rotate-left"></i> Booking Changes Audit</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Booking</th>
                            <th>Action</th>
                            <th>By</th>
                            <th>Changed Fields</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$bookingAuditEvents): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:#6b7280;padding:30px;">No booking audit entries yet. Status changes, modifications and cancellations will appear here.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bookingAuditEvents as $ev): ?>
                                <?php
                                $cf = json_decode($ev['changed_fields'] ?? '[]', true) ?: [];
                                $oldV = json_decode($ev['old_values'] ?? 'null', true);
                                $newV = json_decode($ev['new_values'] ?? 'null', true);
                                $diffParts = [];
                                foreach ($cf as $f) {
                                    $oldStr = isset($oldV[$f]) ? htmlspecialchars((string)$oldV[$f]) : '—';
                                    $newStr = isset($newV[$f]) ? htmlspecialchars((string)$newV[$f]) : '—';
                                    $diffParts[] = '<span style="font-size:11px;"><strong>' . htmlspecialchars($f) . '</strong>: <span style="color:#b9201d;">' . $oldStr . '</span> → <span style="color:#137a3b;">' . $newStr . '</span></span>';
                                }
                                $actionClass = in_array($ev['action'], ['cancelled', 'no-show'], true) ? 'danger' : (in_array($ev['action'], ['checked-in', 'confirmed'], true) ? 'success' : (in_array($ev['action'], ['checked-out'], true) ? 'muted' : 'warning'));
                                ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$ev['performed_at']); ?></td>
                                    <td><a href="booking-details.php?id=<?php echo (int)$ev['booking_id']; ?>" style="text-decoration:none;color:#8B7355;font-weight:600;"><?php echo htmlspecialchars($ev['booking_reference'] ?? ('Booking #' . $ev['booking_id'])); ?></a></td>
                                    <td><span class="log-pill <?php echo $actionClass; ?>"><?php echo htmlspecialchars((string)$ev['action']); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($ev['performed_by_name'] ?? $ev['performed_by_username'] ?? ('#' . $ev['performed_by']))); ?></td>
                                    <td><?php echo $diffParts ? implode('<br>', $diffParts) : '<span style="color:#aaa;">—</span>'; ?></td>
                                    <td style="color:#aaa;font-size:12px;"><?php echo htmlspecialchars((string)($ev['ip_address'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-right-to-bracket"></i> Recent Logins</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Latest Time</th>
                            <th>User</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Result</th>
                            <th>IP Address</th>
                            <th>Country</th>
                            <th>Session Bundle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentLoginBundles): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;color:#6b7280;padding:20px;">No login attempts recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLoginBundles as $bundle):
                                $rangeLabel = $bundle['latest_at'];
                                if ($bundle['oldest_at'] !== $bundle['latest_at']) {
                                    $rangeLabel .= ' to ' . $bundle['oldest_at'];
                                }
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;">
                                        <strong><?php echo htmlspecialchars((string)$bundle['latest_at']); ?></strong>
                                        <?php if ($bundle['oldest_at'] !== $bundle['latest_at']): ?>
                                            <div style="font-size:11px;color:#6b7280;">Range: <?php echo htmlspecialchars((string)$rangeLabel); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars((string)$bundle['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string)($bundle['full_name'] ?: '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($bundle['role'] ?: '—')); ?></td>
                                    <td>
                                        <span class="log-pill <?php echo htmlspecialchars((string)$bundle['result_class']); ?>"><?php echo htmlspecialchars((string)$bundle['result_label']); ?></span>
                                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                                            S:<?php echo (int)$bundle['success_count']; ?>
                                            F:<?php echo (int)$bundle['failed_count']; ?>
                                            B:<?php echo (int)$bundle['blocked_count']; ?>
                                        </div>
                                    </td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($bundle['ip_address'] ?: '—')); ?></td>
                                    <td style="font-size:12px;min-width:110px;"><?php $lgIp = (string)($bundle['ip_address'] ?? '');
                                                                                if ($lgIp !== '' && $lgIp !== '—' && $lgIp !== '127.0.0.1' && $lgIp !== '::1'): ?><span class="ip-geo-country" data-ip="<?php echo htmlspecialchars($lgIp, ENT_QUOTES); ?>">…</span><?php else: ?>—<?php endif; ?></td>
                                    <td style="font-size:12px;color:#6b7280;min-width:280px;">
                                        <div style="margin-bottom:6px;"><?php echo htmlspecialchars((string)$bundle['browser_label']); ?></div>
                                        <details>
                                            <summary style="cursor:pointer;font-weight:600;"><?php echo (int)$bundle['attempt_count']; ?> attempt<?php echo (int)$bundle['attempt_count'] === 1 ? '' : 's'; ?></summary>
                                            <div style="margin-top:8px;display:grid;gap:6px;">
                                                <?php foreach ($bundle['attempts'] as $attempt):
                                                    $attemptAction = (string)($attempt['action'] ?? '');
                                                    $attemptClass = $attemptAction === 'login_success' ? 'success' : ($attemptAction === 'login_blocked' ? 'warning' : 'danger');
                                                    $attemptLabel = $attemptAction === 'login_success' ? 'Success' : ($attemptAction === 'login_blocked' ? 'Blocked' : 'Failed');
                                                ?>
                                                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-top:1px dashed #e2e8f0;padding-top:6px;">
                                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                                            <span class="log-pill <?php echo $attemptClass; ?>"><?php echo $attemptLabel; ?></span>
                                                            <span style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars((string)($attempt['details'] ?: 'No details')); ?></span>
                                                        </div>
                                                        <span style="font-size:11px;color:#64748b;white-space:nowrap;"><?php echo htmlspecialchars((string)$attempt['created_at']); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-user-shield"></i> Recent Admin Activity</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP</th>
                            <th>Country</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminActivity as $row): ?>
                            <?php $actIp = (string)($row['ip_address'] ?? ''); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['created_at']); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['username'] ?: '#' . $row['user_id'])); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['action']); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['details']); ?></td>
                                <td><?php echo htmlspecialchars($actIp); ?></td>
                                <td style="font-size:12px;"><?php if ($actIp !== '' && $actIp !== '127.0.0.1' && $actIp !== '::1'): ?><span class="ip-geo-country" data-ip="<?php echo htmlspecialchars($actIp, ENT_QUOTES); ?>">…</span><?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$adminActivity): ?><tr>
                                <td colspan="6" style="text-align:center;color:#6b7280;padding:20px;">No admin activity found.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        function rh_pos_status_class(string $s): string
        {
            return match ($s) {
                'paid', 'completed' => 'success',
                'placed', 'pending', 'confirmed' => 'warning',
                'voided', 'cancelled' => 'danger',
                default => 'muted',
            };
        }
        function rh_kds_event_class(string $e): string
        {
            return match ($e) {
                'ready', 'collected', 'served', 'bumped' => 'success',
                'fired', 'started' => 'warning',
                'voided', 'recalled' => 'danger',
                default => 'muted',
            };
        }
        ?>

        <div class="log-section">
            <h3><i class="fas fa-cash-register"></i> POS Orders</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>User <span id="pos-filter-clear" style="display:none;cursor:pointer;font-size:11px;color:#8B7355;font-weight:400;margin-left:4px;">(clear)</span></th>
                            <th>Audit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$posOrders): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;color:#6b7280;padding:30px;">No POS orders found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posOrders as $order):
                                $orderStatus = (string)($order['status'] ?? '');
                                $orderType = str_replace('_', ' ', (string)($order['order_type'] ?? ''));
                                $location = $order['table_number'] ? 'Table ' . htmlspecialchars((string)$order['table_number']) : ($order['room_number'] ? 'Room ' . htmlspecialchars((string)$order['room_number']) : '—');
                                $amountFormatted = ($currencyCode ? $currencyCode . ' ' : '') . number_format((float)($order['total_amount'] ?? 0), 2);
                                $payMethod = str_replace('_', ' ', (string)($order['payment_method'] ?? '—'));
                                $auditRows = $posAuditByOrder[(int)$order['id']] ?? [];
                                $flags = [];
                                if (!empty($order['is_priority'])) $flags[] = '<span style="color:#e53e3e;font-size:11px;font-weight:600;">PRIORITY</span>';
                                if (!empty($order['opened_as_tab'])) $flags[] = '<span style="color:#8B7355;font-size:11px;">TAB</span>';
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$order['created_at']); ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)($order['reference'] ?? '#' . $order['id'])); ?></strong><?php echo $flags ? '<div style="margin-top:3px;">' . implode(' ', $flags) . '</div>' : ''; ?></td>
                                    <td style="text-transform:capitalize;"><?php echo htmlspecialchars($orderType); ?></td>
                                    <td><?php echo $location; ?></td>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars($amountFormatted); ?></td>
                                    <td><span class="log-pill <?php echo rh_pos_status_class($orderStatus); ?>"><?php echo htmlspecialchars($orderStatus); ?></span></td>
                                    <td style="text-transform:capitalize;"><?php echo htmlspecialchars($payMethod); ?></td>
                                    <td class="pos-user-cell" data-user="<?php echo htmlspecialchars(strtolower((string)($order['created_by_name'] ?? '')), ENT_QUOTES); ?>" style="cursor:pointer;" title="Click to filter by this user"><?php echo htmlspecialchars((string)($order['created_by_name'] ?: '—')); ?></td>
                                    <td style="min-width:160px;">
                                        <?php if ($auditRows): ?>
                                            <details>
                                                <summary style="cursor:pointer;font-weight:600;font-size:12px;"><?php echo count($auditRows); ?> event<?php echo count($auditRows) === 1 ? '' : 's'; ?></summary>
                                                <div style="margin-top:6px;display:grid;gap:4px;">
                                                    <?php foreach ($auditRows as $ar): ?>
                                                        <div style="border-top:1px dashed #e2e8f0;padding-top:4px;display:flex;flex-direction:column;gap:2px;">
                                                            <div style="display:flex;justify-content:space-between;gap:8px;">
                                                                <strong style="font-size:11px;"><?php echo htmlspecialchars((string)$ar['event']); ?></strong>
                                                                <span style="font-size:11px;color:#6b7280;white-space:nowrap;"><?php echo htmlspecialchars((string)$ar['created_at']); ?></span>
                                                            </div>
                                                            <span style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars((string)($ar['actor_name'] ?? '')); ?></span>
                                                            <?php if (!empty($ar['details'])): ?>
                                                                <span style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars((string)$ar['details']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span style="color:#aaa;font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-display"></i> Display Screens — KDS / BDS / CDS</h3>

            <h4 style="margin:0 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Station Events</h4>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Order</th>
                            <th>Item</th>
                            <th>Station</th>
                            <th>Event</th>
                            <th>From</th>
                            <th>To</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$kdsEvents): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;color:#6b7280;padding:30px;">No KDS/BDS/CDS events found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($kdsEvents as $ev):
                                $evEvent = (string)($ev['event'] ?? '');
                                $tableLabel = $ev['table_number'] ? 'Table ' . htmlspecialchars((string)$ev['table_number']) : '';
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$ev['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($ev['reference'] ?? '—')); ?><?php if ($tableLabel): ?><div style="font-size:11px;color:#6b7280;"><?php echo $tableLabel; ?></div><?php endif; ?></td>
                                    <td><?php echo htmlspecialchars((string)($ev['item_name'] ?? '—')); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)($ev['station'] ?? '—')); ?></code></td>
                                    <td><span class="log-pill <?php echo rh_kds_event_class($evEvent); ?>"><?php echo htmlspecialchars($evEvent); ?></span></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($ev['from_status'] ?? '—')); ?></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($ev['to_status'] ?? '—')); ?></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($ev['user_name'] ?? '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h4 style="margin:24px 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Station Messages</h4>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Station</th>
                            <th>Priority</th>
                            <th>Source</th>
                            <th>Message</th>
                            <th>Order</th>
                            <th>ACK</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$stationMessages): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;color:#6b7280;padding:20px;">No station messages found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stationMessages as $msg):
                                $priorityClass = (string)($msg['priority'] ?? '') === 'urgent' ? 'danger' : 'muted';
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$msg['created_at']); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)$msg['station']); ?></code></td>
                                    <td><span class="log-pill <?php echo $priorityClass; ?>"><?php echo htmlspecialchars((string)($msg['priority'] ?? 'normal')); ?></span></td>
                                    <td style="font-size:12px;"><?php echo htmlspecialchars((string)($msg['source'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string)$msg['message']); ?></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($msg['order_ref'] ?: '—')); ?></td>
                                    <td><?php if ($msg['is_acknowledged']): ?><span class="log-pill success" style="font-size:11px;">ACK</span><?php else: ?><span class="log-pill muted" style="font-size:11px;">—</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($shiftCloses): ?>
                <h4 style="margin:24px 0 12px;font-size:14px;color:var(--text-secondary,#5E554D);">Shift Closes</h4>
                <div style="overflow-x:auto;">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Shift Date</th>
                                <th>Closed At</th>
                                <th>User</th>
                                <th>Orders</th>
                                <th>Revenue</th>
                                <th>Voids</th>
                                <th>Cash Variance</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shiftCloses as $sc):
                                $varianceClass = abs((float)($sc['variance_cash'] ?? 0)) > 0.01 ? 'danger' : 'success';
                                $revenueFormatted = ($currencyCode ? $currencyCode . ' ' : '') . number_format((float)($sc['total_revenue'] ?? 0), 2);
                                $voidsFormatted = ($currencyCode ? $currencyCode . ' ' : '') . number_format((float)($sc['voids_amount'] ?? 0), 2);
                                $varianceFormatted = ($currencyCode ? $currencyCode . ' ' : '') . number_format((float)($sc['variance_cash'] ?? 0), 2);
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$sc['shift_date']); ?></td>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)$sc['closed_at']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$sc['user_name']); ?></td>
                                    <td><?php echo (int)($sc['orders_count'] ?? 0); ?></td>
                                    <td style="white-space:nowrap;"><?php echo htmlspecialchars($revenueFormatted); ?></td>
                                    <td style="white-space:nowrap;"><?php echo (int)($sc['voids_count'] ?? 0); ?> (<?php echo htmlspecialchars($voidsFormatted); ?>)</td>
                                    <td><span class="log-pill <?php echo $varianceClass; ?>" style="font-size:11px;"><?php echo htmlspecialchars($varianceFormatted); ?></span></td>
                                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars((string)($sc['notes'] ?: '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-key"></i> Recent API Calls</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Client</th>
                            <th>Method</th>
                            <th>Endpoint</th>
                            <th>HTTP</th>
                            <th>Time</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apiActivity as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['created_at']); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['client_name'] ?: '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['method']); ?></td>
                                <td><code><?php echo htmlspecialchars((string)$row['endpoint']); ?></code></td>
                                <td><span class="log-pill <?php echo ((int)$row['response_code'] >= 400) ? 'danger' : 'success'; ?>"><?php echo (int)$row['response_code']; ?></span></td>
                                <td><?php echo htmlspecialchars((string)$row['response_time']); ?>s</td>
                                <td><?php echo htmlspecialchars((string)$row['ip_address']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$apiActivity): ?><tr>
                                <td colspan="7" style="text-align:center;color:#6b7280;padding:20px;">No API calls found.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-cloud-arrow-up"></i> Recent Offline Sync</h3>
            <div style="overflow-x:auto;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Synced</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Endpoint</th>
                            <th>Entity</th>
                            <th>HTTP</th>
                            <th>Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offlineActivity as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['replayed_at']); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['username'] ?: '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['action']); ?></td>
                                <td><code><?php echo htmlspecialchars((string)$row['endpoint']); ?></code></td>
                                <td><?php echo htmlspecialchars((string)($row['entity_reference'] ?: '-')); ?></td>
                                <td><span class="log-pill <?php echo ((int)$row['response_status'] >= 400) ? 'danger' : 'success'; ?>"><?php echo (int)$row['response_status']; ?></span></td>
                                <td><?php echo htmlspecialchars((string)$row['response_summary']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$offlineActivity): ?><tr>
                                <td colspan="7" style="text-align:center;color:#6b7280;padding:20px;">No offline sync entries found.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="log-section">
            <h3><i class="fas fa-file-lines"></i> File Log Tails</h3>
            <div class="log-files">
                <?php foreach ($fileLogs as $name => $lines): ?>
                    <div class="log-file">
                        <h4><?php echo htmlspecialchars($name); ?></h4>
                        <pre><?php echo $lines ? htmlspecialchars(implode("\n", $lines)) : 'No readable entries.'; ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
    <script>
        (function() {
            'use strict';

            function withInlinePaginationLoader(controlsEl, message, renderFn) {
                if (typeof renderFn !== 'function') return;
                if (!controlsEl || !controlsEl.parentNode) {
                    renderFn();
                    return;
                }

                var loaderWrap = document.createElement('div');
                loaderWrap.className = 'log-table-pagination-loader-wrap';
                loaderWrap.innerHTML = [
                    '<div class="admin-pagination-loader" role="status" aria-live="polite" aria-label="' + (message || 'Loading results') + '">',
                    '<span class="admin-pagination-loader__spinner" aria-hidden="true"></span>',
                    '<span class="admin-pagination-loader__text">' + (message || 'Loading next page...') + '</span>',
                    '</div>'
                ].join('');

                controlsEl.insertAdjacentElement('afterend', loaderWrap);

                window.setTimeout(function() {
                    try {
                        renderFn();
                    } finally {
                        if (loaderWrap.parentNode) {
                            loaderWrap.parentNode.removeChild(loaderWrap);
                        }
                    }
                }, 170);
            }

            // ── POS Orders — click-to-filter by User + pagination ────────────────────
            var posUserCellEl = document.querySelector('td.pos-user-cell');
            if (posUserCellEl) {
                var posSection = posUserCellEl.closest('.log-section');
                var tbody = posUserCellEl.closest('tbody');
                var clearBtn = document.getElementById('pos-filter-clear');
                var activeUser = '';
                var posPage = 1;
                var posPageSize = 10;
                var posBusy = false;

                // Tag each data row
                if (tbody) {
                    tbody.querySelectorAll('tr').forEach(function(tr) {
                        var cell = tr.querySelector('.pos-user-cell');
                        if (!cell) return;
                        tr.setAttribute('data-pos-row', '1');
                        tr.setAttribute('data-pos-user', cell.dataset.user || '');
                    });
                }

                // Pagination controls for POS — inserted after table's overflow wrapper
                var posTableEl = posSection ? posSection.querySelector('table.log-table') : null;
                var posControls = null;
                if (posTableEl) {
                    posControls = document.createElement('div');
                    posControls.className = 'log-table-pagination';
                    posTableEl.parentNode.insertBefore(posControls, posTableEl.nextSibling);
                }

                function queuePosRender(nextPage) {
                    if (posBusy) return;
                    posBusy = true;
                    if (posControls) posControls.classList.add('is-loading');

                    withInlinePaginationLoader(posControls, 'Loading POS results...', function() {
                        renderPOS(activeUser, nextPage);
                        if (posControls) posControls.classList.remove('is-loading');
                        posBusy = false;
                    });
                }

                function renderPOS(user, page) {
                    if (!tbody) return;
                    activeUser = user;
                    var allRows = Array.from(tbody.querySelectorAll('tr[data-pos-row]'));
                    var filteredRows = user ?
                        allRows.filter(function(tr) {
                            return (tr.dataset.posUser || '').toLowerCase() === user;
                        }) :
                        allRows;
                    var total = filteredRows.length;
                    var totalPages = Math.ceil(total / posPageSize) || 1;
                    posPage = Math.min(Math.max(1, page), totalPages);
                    var from = (posPage - 1) * posPageSize;

                    allRows.forEach(function(tr) {
                        var idx = filteredRows.indexOf(tr);
                        if (idx === -1) {
                            tr.style.display = 'none';
                        } else {
                            tr.style.display = (idx >= from && idx < from + posPageSize) ? '' : 'none';
                        }
                    });

                    if (clearBtn) clearBtn.style.display = user ? 'inline' : 'none';

                    if (posControls) {
                        if (totalPages <= 1) {
                            posControls.innerHTML = '';
                        } else {
                            var prev = document.createElement('button');
                            prev.className = 'pg-btn';
                            prev.textContent = '← Prev';
                            prev.disabled = posPage <= 1;
                            prev.onclick = function() {
                                queuePosRender(posPage - 1);
                            };

                            var info = document.createElement('span');
                            info.className = 'pg-summary';
                            info.textContent = 'Page ' + posPage + ' of ' + totalPages + '  (' + total + ' rows)';

                            var next = document.createElement('button');
                            next.className = 'pg-btn';
                            next.textContent = 'Next →';
                            next.disabled = posPage >= totalPages;
                            next.onclick = function() {
                                queuePosRender(posPage + 1);
                            };

                            posControls.innerHTML = '';
                            posControls.appendChild(prev);
                            posControls.appendChild(info);
                            posControls.appendChild(next);
                        }
                    }
                }

                function applyPosFilter(user) {
                    renderPOS(user, 1);
                }

                // Click handler on user cells
                if (tbody) {
                    tbody.addEventListener('click', function(e) {
                        var cell = e.target.closest('.pos-user-cell');
                        if (!cell) return;
                        var user = (cell.dataset.user || '').toLowerCase();
                        if (!user || user === '—') return;
                        applyPosFilter(activeUser === user ? '' : user);
                    });
                }

                // Clear button
                if (clearBtn) {
                    clearBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        applyPosFilter('');
                    });
                }

                // Initial render — show first 10 rows
                renderPOS('', 1);
            }

            // ── IP Geolocation (ipwho.is — free, HTTPS, no API key) ──────────────────
            (function resolveIpCountries() {
                var spans = document.querySelectorAll('span.ip-geo-country[data-ip]');
                if (!spans.length) return;
                // Collect unique IPs → list of matching spans
                var ipMap = {};
                spans.forEach(function(span) {
                    var ip = span.getAttribute('data-ip') || '';
                    if (!ip) return;
                    if (!ipMap[ip]) ipMap[ip] = [];
                    ipMap[ip].push(span);
                });
                Object.keys(ipMap).forEach(function(ip) {
                    var cacheKey = 'ipgeo2_' + ip;
                    try {
                        var cached = sessionStorage.getItem(cacheKey);
                        if (cached) {
                            ipMap[ip].forEach(function(s) {
                                s.textContent = cached;
                            });
                            return;
                        }
                    } catch (e) {}
                    fetch('https://ipwho.is/' + encodeURIComponent(ip))
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(d) {
                            var label = (d && d.success !== false && d.country) ?
                                ((d.flag && d.flag.emoji ? d.flag.emoji + '\u00a0' : '') + d.country) :
                                ip;
                            try {
                                sessionStorage.setItem(cacheKey, label);
                            } catch (e) {}
                            ipMap[ip].forEach(function(s) {
                                s.textContent = label;
                            });
                        })
                        .catch(function() {
                            ipMap[ip].forEach(function(s) {
                                s.textContent = ip;
                            });
                        });
                });
            }());

        })();
    </script>
    <?php if ($autoRefresh): ?>
        <script>
            setTimeout(function() {
                window.location.reload();
            }, 15000);
        </script>
    <?php endif; ?>
</body>

</html>

