<?php
/**
 * Offline Activity Log
 *
 * Shows every offline-queued action (orders, payments, voids, KDS bumps) that
 * was replayed against the server when connectivity returned. Each row exposes:
 *   - WHO created the action
 *   - WHEN they queued it (offline) vs. WHEN it actually replayed (online)
 *   - the queue lag, the endpoint, and a deep link to the related entity.
 *
 * Permission: offline_log_view (auto-granted to admin/manager).
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/permissions.php';

if (!hasPermission((int)$_SESSION['admin_user_id'], 'offline_log_view')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name');

/* Filters */
$filter_user = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : 0;
$filter_endpoint = trim($_GET['endpoint'] ?? '');
$filter_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_to   = $_GET['to']   ?? date('Y-m-d');
if (!strtotime($filter_from)) { $filter_from = date('Y-m-d', strtotime('-7 days')); }
if (!strtotime($filter_to))   { $filter_to   = date('Y-m-d'); }

$where = ['DATE(replayed_at) BETWEEN ? AND ?'];
$args  = [$filter_from, $filter_to];
if ($filter_user > 0)         { $where[] = 'user_id = ?';     $args[] = $filter_user; }
if ($filter_endpoint !== '')  { $where[] = 'endpoint LIKE ?'; $args[] = '%' . $filter_endpoint . '%'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* Summary KPIs */
$kpiSt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    COUNT(DISTINCT client_uuid) AS unique_actions,
    COUNT(DISTINCT user_id) AS users,
    AVG(TIMESTAMPDIFF(SECOND, client_queued_at, replayed_at)) AS avg_lag,
    MAX(TIMESTAMPDIFF(SECOND, client_queued_at, replayed_at)) AS max_lag
    FROM offline_replay_log $whereSql");
$kpiSt->execute($args);
$kpi = $kpiSt->fetch(PDO::FETCH_ASSOC) ?: [];

/* Top offenders / endpoints */
$topEpSt = $pdo->prepare("SELECT endpoint, COUNT(*) AS n FROM offline_replay_log $whereSql GROUP BY endpoint ORDER BY n DESC LIMIT 5");
$topEpSt->execute($args);
$topEndpoints = $topEpSt->fetchAll(PDO::FETCH_ASSOC);

/* Rows */
$listSt = $pdo->prepare("SELECT id, client_uuid, user_id, username, endpoint, action, entity_type, entity_id, entity_reference,
    client_queued_at, replayed_at, response_status, response_summary,
    TIMESTAMPDIFF(SECOND, client_queued_at, replayed_at) AS lag_seconds
    FROM offline_replay_log $whereSql
    ORDER BY replayed_at DESC LIMIT 2000");
$listSt->execute($args);
$rows = $listSt->fetchAll(PDO::FETCH_ASSOC);

/* Distinct users for filter dropdown */
$users = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active=1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

function fmt_lag(?int $s): string {
    if ($s === null) return '—';
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s/60) . 'm ' . ($s%60) . 's';
    return floor($s/3600) . 'h ' . floor(($s%3600)/60) . 'm';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline Activity Log | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/offline-log.css?v=<?php echo @filemtime(__DIR__ . '/css/offline-log.css'); ?>">
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <h2 class="section-title"><i class="fas fa-cloud-arrow-up"></i> Offline Activity Log</h2>
        <p style="color:#6c757d; margin-bottom:18px; max-width:780px;">
            Every action saved on a device while offline (POS orders, payments, KDS bumps, voids) is replayed
            here when the connection returns. The log shows <strong>when the user queued</strong> the action vs.
            <strong>when the server received</strong> it — useful for reconciliation, audits, and confirming
            offline shifts processed cleanly.
        </p>

        <!-- KPIs -->
        <div class="ol-kpis">
            <div class="ol-kpi"><div class="lbl">Replayed actions</div><div class="val"><?php echo (int)($kpi['total'] ?? 0); ?></div></div>
            <div class="ol-kpi"><div class="lbl">Unique submissions</div><div class="val"><?php echo (int)($kpi['unique_actions'] ?? 0); ?></div></div>
            <div class="ol-kpi"><div class="lbl">Distinct users</div><div class="val"><?php echo (int)($kpi['users'] ?? 0); ?></div></div>
            <div class="ol-kpi"><div class="lbl">Avg sync lag</div><div class="val"><?php echo fmt_lag(isset($kpi['avg_lag']) && $kpi['avg_lag'] !== null ? (int)$kpi['avg_lag'] : null); ?></div></div>
            <div class="ol-kpi"><div class="lbl">Max sync lag</div><div class="val"><?php echo fmt_lag(isset($kpi['max_lag']) ? (int)$kpi['max_lag'] : null); ?></div></div>
        </div>

        <!-- Filters -->
        <form method="get" class="ol-filters">
            <label>From <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from); ?>"></label>
            <label>To <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to); ?>"></label>
            <label>User
                <select name="user_id">
                    <option value="">— Anyone —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $filter_user === (int)$u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Endpoint contains <input type="text" name="endpoint" value="<?php echo htmlspecialchars($filter_endpoint); ?>" placeholder="e.g. void-order"></label>
            <button type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a href="offline-log.php" style="color:#6c757d; font-size:13px; text-decoration:none;">Reset</a>
        </form>

        <?php if ($topEndpoints): ?>
            <div style="background:#fff; border:1px solid #e6e9ee; border-radius:10px; padding:12px 16px; margin-bottom:14px;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:#6c757d; font-weight:600;">Most replayed endpoints</div>
                <div class="topEndpoint-list">
                    <?php foreach ($topEndpoints as $ep): ?>
                        <span class="ol-pill"><?php echo htmlspecialchars($ep['endpoint']); ?> · <strong><?php echo (int)$ep['n']; ?></strong></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Log -->
        <?php
        $rows_per_page    = 10;
        $rows_page        = max(1, (int)($_GET['rows_page'] ?? 1));
        $rows_total       = count($rows);
        $rows_total_pages = $rows_total > 0 ? (int)ceil($rows_total / $rows_per_page) : 1;
        $rows_display     = array_slice($rows, ($rows_page - 1) * $rows_per_page, $rows_per_page);
        ?>
        <div style="overflow-x:auto;">
        <table class="ol-table">
            <thead>
                <tr>
                    <th>When queued</th>
                    <th>When synced</th>
                    <th>Lag</th>
                    <th>User</th>
                    <th>Endpoint / Action</th>
                    <th>Entity</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" style="text-align:center; padding:40px 20px; color:#6c757d;">
                        <i class="fas fa-cloud" style="font-size:36px; color:#dee2e6; display:block; margin-bottom:10px;"></i>
                        No offline activity in the selected range. When a user submits an order/action while their device is offline,
                        it will appear here once the device reconnects.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows_display as $r): ?>
                        <?php
                            $lag = $r['lag_seconds'] !== null ? (int)$r['lag_seconds'] : null;
                            $lagClass = $lag === null ? '' : ($lag > 600 ? 'lag-late' : ($lag > 60 ? 'lag-warn' : 'ok'));
                            $entityLink = '';
                            if ($r['entity_type'] === 'stock_order' && $r['entity_id']) {
                                $entityLink = 'order-lifecycle.php?id=' . (int)$r['entity_id'];
                            }
                        ?>
                        <tr>
                            <td data-label="Queued"><?php echo htmlspecialchars($r['client_queued_at'] ?: '—'); ?></td>
                            <td data-label="Synced"><?php echo htmlspecialchars($r['replayed_at']); ?></td>
                            <td data-label="Lag"><span class="ol-pill <?php echo $lagClass; ?>"><?php echo fmt_lag($lag); ?></span></td>
                            <td data-label="User"><?php echo htmlspecialchars($r['username'] ?: ('#' . (int)$r['user_id'])); ?></td>
                            <td data-label="Action">
                                <strong><?php echo htmlspecialchars($r['action'] ?: '—'); ?></strong><br>
                                <code><?php echo htmlspecialchars($r['endpoint']); ?></code>
                            </td>
                            <td data-label="Entity">
                                <?php if ($r['entity_reference']): ?>
                                    <?php if ($entityLink): ?>
                                        <a href="<?php echo htmlspecialchars($entityLink); ?>" target="_blank" rel="noopener" style="color:#8B7355; text-decoration:none;">
                                            <?php echo htmlspecialchars($r['entity_reference']); ?> <i class="fas fa-external-link-alt" style="font-size:10px;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($r['entity_reference']); ?>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td data-label="Result">
                                <span class="ol-pill <?php echo (int)$r['response_status'] === 200 ? 'ok' : 'warn'; ?>">HTTP <?php echo (int)$r['response_status']; ?></span>
                                <?php if ($r['response_summary']): ?>
                                    <div style="font-size:11px; color:#6c757d; margin-top:4px;"><?php echo htmlspecialchars($r['response_summary']); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($rows_total_pages > 1): ?>
        <nav style="display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0;flex-wrap:wrap;">
            <?php for ($pg = 1; $pg <= $rows_total_pages; $pg++):
                $pgHref = 'offline-log.php?' . http_build_query(['user_id' => $filter_user ?: '', 'endpoint' => $filter_endpoint, 'from' => $filter_from, 'to' => $filter_to, 'rows_page' => $pg]);
                $pgActive = ($pg === $rows_page);
            ?>
            <a href="<?php echo htmlspecialchars($pgHref, ENT_QUOTES, 'UTF-8'); ?>"
               style="padding:6px 12px;border:1px solid <?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#dee2e6'; ?>;background:<?php echo $pgActive ? 'var(--color-primary,#8A775F)' : '#fff'; ?>;color:<?php echo $pgActive ? '#fff' : '#374151'; ?>;border-radius:4px;font-size:13px;text-decoration:none;"
            ><?php echo $pg; ?></a>
            <?php endfor; ?>
            <span style="padding:6px 8px;font-size:12px;color:#888;">
                Showing <?php echo (($rows_page - 1) * $rows_per_page) + 1; ?>–<?php echo min($rows_page * $rows_per_page, $rows_total); ?> of <?php echo $rows_total; ?>
            </span>
        </nav>
        <?php endif; ?>
    </div>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

