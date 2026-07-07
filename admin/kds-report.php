<?php

/**
 * KDS / BDS / CDS Daily Production Report
 *
 *  - HTML report of all served (and voided) tickets for a station on a given day,
 *    with full timeline timestamps so management can reconcile what came out of
 *    the kitchen / bar / coffee bar.
 *  - CSV export        : ?format=csv
 *  - Email this report : ?action=email&to=user@example.com
 *  - Filters: ?station=kitchen|bar|coffee_bar|all   ?date=YYYY-MM-DD
 *
 * Permission: kds_reports (admin / manager / chef / bar_staff / coffee_staff).
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/station-hours.php';

if (!hasPermission((int)$_SESSION['admin_user_id'], 'kds_reports')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];
$currency_symbol = getSetting('currency_symbol') ?: 'MWK';
$site_name = getSetting('site_name') ?: 'Hotel';

/* === Filters === */
// Full label map keeps historical rows readable even if a station was later
// switched off; the filter dropdown only offers the stations this preset runs.
$STATION_LABEL = ['kitchen' => 'Kitchen', 'bar' => 'Bar', 'coffee_bar' => 'Coffee Bar'];
$STATION_OPTIONS = ['all' => 'All Stations'];
foreach (rh_enabled_station_definitions() as $stationKey => $definition) {
    $STATION_OPTIONS[$stationKey] = $definition['label'];
}

// Staff are pinned to their own station, admin/manager can pick anything.
$role = $user['role'] ?? '';
$forcedStation = match ($role) {
    'chef'         => 'kitchen',
    'bar_staff'    => 'bar',
    'coffee_staff' => 'coffee_bar',
    default        => null,
};
$reqStation = $_GET['station'] ?? ($forcedStation ?: 'all');
if ($forcedStation) $reqStation = $forcedStation;
if (!array_key_exists($reqStation, $STATION_OPTIONS)) $reqStation = 'all';

$reqDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) $reqDate = date('Y-m-d');

$reportWindow = $reqStation === 'all'
    ? rh_station_union_window_for_date($reqDate)
    : rh_station_window_for_date($reqStation, $reqDate);
$dayStart = $reportWindow['start_sql'];
$dayEnd   = $reportWindow['end_sql'];

/* === Pull served + voided items for the day === */
$wheres = ["o.created_at >= ? AND o.created_at < ?"];
$params = [$dayStart, $dayEnd];
if ($reqStation !== 'all') {
    $wheres[] = "oi.station = ?";
    $params[] = $reqStation;
}
$whereSql = implode(' AND ', $wheres);

$itemSql = "SELECT
        oi.id              AS item_id,
        oi.order_id,
        oi.item_name,
        oi.menu_type,
        oi.station,
        oi.quantity,
        oi.unit_price,
        oi.line_total,
        oi.kds_status,
        oi.started_at,
        oi.ready_at,
        oi.served_at,
        oi.notes           AS item_notes,
        o.reference,
        o.order_type,
        o.table_number,
        o.customer_name,
        o.kitchen_status,
        o.fired_at,
        o.created_at       AS order_created_at,
        o.status           AS order_status,
        au.full_name       AS bumped_by_name,
        au_open.full_name  AS opened_by_name
    FROM stock_order_items oi
    JOIN stock_orders o ON o.id = oi.order_id
    LEFT JOIN admin_users au ON au.id = oi.bumped_by
    LEFT JOIN admin_users au_open ON au_open.id = o.created_by
    WHERE {$whereSql}
    ORDER BY oi.served_at ASC, oi.id ASC";
$st = $pdo->prepare($itemSql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* === Aggregates === */
$totalItems = 0;
$totalQty = 0;
$totalRevenue = 0.0;
$totalVoid = 0;
$totalVoidValue = 0.0;
$prepTimes = []; // seconds from fired→served
$byStation = []; // station => [items, qty, revenue]
$byCategory = []; // item_name → counts (for top-items)
foreach ($rows as $r) {
    $st = $r['station'] ?: 'unknown';
    if (!isset($byStation[$st])) $byStation[$st] = ['items' => 0, 'qty' => 0, 'revenue' => 0, 'voids' => 0, 'avg_seconds' => []];
    $byStation[$st]['items']++;
    if ($r['kds_status'] === 'void' || $r['order_status'] === 'voided' || $r['order_status'] === 'cancelled') {
        $totalVoid++;
        $totalVoidValue += (float)$r['line_total'];
        $byStation[$st]['voids']++;
        continue;
    }
    $totalItems++;
    $totalQty += (float)$r['quantity'];
    $totalRevenue += (float)$r['line_total'];
    $byStation[$st]['qty'] += (float)$r['quantity'];
    $byStation[$st]['revenue'] += (float)$r['line_total'];
    if ($r['fired_at'] && $r['served_at']) {
        $secs = strtotime($r['served_at']) - strtotime($r['fired_at']);
        if ($secs >= 0 && $secs < 6 * 3600) {
            $prepTimes[] = $secs;
            $byStation[$st]['avg_seconds'][] = $secs;
        }
    }
    $key = $r['item_name'];
    if (!isset($byCategory[$key])) $byCategory[$key] = ['name' => $r['item_name'], 'station' => $r['station'], 'qty' => 0, 'revenue' => 0];
    $byCategory[$key]['qty'] += (float)$r['quantity'];
    $byCategory[$key]['revenue'] += (float)$r['line_total'];
}
$avgPrep = $prepTimes ? round(array_sum($prepTimes) / count($prepTimes)) : 0;
$minPrep = $prepTimes ? min($prepTimes) : 0;
$maxPrep = $prepTimes ? max($prepTimes) : 0;
foreach ($byStation as $s => &$d) {
    $d['avg_seconds'] = $d['avg_seconds'] ? round(array_sum($d['avg_seconds']) / count($d['avg_seconds'])) : 0;
}
unset($d);
uasort($byCategory, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
$topItems = array_slice($byCategory, 0, 20, true);

/* === Helpers === */
function rh_fmt_dur(int $s): string
{
    if ($s <= 0) return '—';
    $m = intdiv($s, 60);
    $r = $s % 60;
    return $m > 0 ? sprintf('%dm %02ds', $m, $r) : sprintf('%ds', $r);
}
function rh_fmt_dt(?string $iso): string
{
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? date('H:i:s', $t) : '—';
}

/* === CSV export === */
if (($_GET['format'] ?? '') === 'csv') {
    $filename = sprintf('kds-report-%s-%s.csv', $reqStation, $reqDate);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', $reqDate, 'Station', $STATION_OPTIONS[$reqStation]]);
    fputcsv($out, ['Total items served', $totalItems, 'Revenue', number_format($totalRevenue, 2)]);
    fputcsv($out, ['Avg prep (s)', $avgPrep, 'Voids', $totalVoid]);
    fputcsv($out, []);
    fputcsv($out, ['Order Ref', 'Type', 'Table/Customer', 'Item', 'Qty', 'Unit Price', 'Line Total', 'Station', 'Status', 'Created', 'Fired', 'Started', 'Ready', 'Served', 'Prep (mm:ss)', 'Bumped by', 'Opened by', 'Item Notes']);
    foreach ($rows as $r) {
        $prep = ($r['fired_at'] && $r['served_at']) ? rh_fmt_dur((int)(strtotime($r['served_at']) - strtotime($r['fired_at']))) : '';
        fputcsv($out, [
            $r['reference'],
            $r['order_type'],
            $r['table_number'] ?: $r['customer_name'],
            $r['item_name'],
            $r['quantity'],
            $r['unit_price'],
            $r['line_total'],
            $r['station'],
            $r['kds_status'],
            $r['order_created_at'],
            $r['fired_at'],
            $r['started_at'],
            $r['ready_at'],
            $r['served_at'],
            $prep,
            $r['bumped_by_name'],
            $r['opened_by_name'],
            $r['item_notes'],
        ]);
    }
    fclose($out);
    exit;
}

/* === Email report === */
$emailMsg = '';
$emailErr = '';
if (($_POST['action'] ?? '') === 'email_report') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $emailErr = 'Security token invalid.';
    } else {
        $to = trim($_POST['to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $emailErr = 'Provide a valid recipient email.';
        } else {
            $subject = sprintf('[%s] Station Report — %s — %s', $site_name, $STATION_OPTIONS[$reqStation], $reqDate);
            ob_start();
            include __DIR__ . '/includes/kds-report-email.php';
            $html = ob_get_clean();
            $result = sendEmail($to, $to, $subject, $html, '');
            if (!empty($result['success'])) {
                $emailMsg = "Report emailed to {$to}.";
            } else {
                $emailErr = 'Email failed: ' . ($result['message'] ?? 'unknown error');
            }
        }
    }
}

$csrf_token = generateCsrfToken();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Report — <?php echo htmlspecialchars($STATION_OPTIONS[$reqStation]); ?> — <?php echo $reqDate; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/kds-report.css?v=<?php echo @filemtime(__DIR__ . '/css/kds-report.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content kds-report-page">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-file-chart-line" style="color:var(--admin-accent);"></i>
                Station Report
                <small style="font-weight:400; color:#6c757d; font-size:14px;">— <?php echo htmlspecialchars($STATION_OPTIONS[$reqStation]); ?> · <?php echo htmlspecialchars(date('D, d M Y', strtotime($reqDate))); ?></small>
            </h2>
        </div>

        <?php if ($emailMsg): ?><div class="alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($emailMsg); ?></div><?php endif; ?>
        <?php if ($emailErr): ?><div class="alert error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($emailErr); ?></div><?php endif; ?>

        <form method="GET" class="filter-bar">
            <div>
                <label>Station</label>
                <select name="station" <?php echo $forcedStation ? 'disabled' : ''; ?>>
                    <?php foreach ($STATION_OPTIONS as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $reqStation === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($forcedStation): ?><input type="hidden" name="station" value="<?php echo $forcedStation; ?>"><?php endif; ?>
            </div>
            <div>
                <label>Date</label>
                <input type="date" name="date" value="<?php echo htmlspecialchars($reqDate); ?>" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="btns">
                <button type="submit" class="primary"><i class="fas fa-filter"></i> Apply</button>
                <a class="btn csv" href="?station=<?php echo urlencode($reqStation); ?>&date=<?php echo urlencode($reqDate); ?>&format=csv"><i class="fas fa-file-csv"></i> CSV</a>
                <button type="button" class="email" onclick="openKdsEmailModal()"><i class="fas fa-envelope"></i> Email</button>
                <button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </form>

        <div class="business-window-note">
            <i class="fas fa-clock"></i>
            <strong><?php echo htmlspecialchars($reportWindow['hours_label']); ?></strong>
            <span><?php echo htmlspecialchars($reportWindow['window_label']); ?></span>
        </div>

        <div class="stats-grid">
            <div class="kr-stat">
                <div class="lbl">Items Served</div>
                <div class="val"><?php echo number_format($totalItems); ?></div>
            </div>
            <div class="kr-stat">
                <div class="lbl">Total Qty</div>
                <div class="val"><?php echo number_format($totalQty, 2); ?></div>
            </div>
            <div class="kr-stat">
                <div class="lbl">Revenue</div>
                <div class="val"><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></div>
            </div>
            <div class="kr-stat">
                <div class="lbl">Avg Prep Time</div>
                <div class="val"><?php echo rh_fmt_dur($avgPrep); ?></div>
            </div>
            <div class="kr-stat">
                <div class="lbl">Min · Max</div>
                <div class="val" style="font-size:16px;"><?php echo rh_fmt_dur($minPrep) . ' · ' . rh_fmt_dur($maxPrep); ?></div>
            </div>
            <div class="kr-stat danger">
                <div class="lbl">Voided / Cancelled</div>
                <div class="val"><?php echo $totalVoid; ?></div>
            </div>
        </div>

        <?php if ($byStation && $reqStation === 'all'): ?>
            <div class="panel">
                <h3><i class="fas fa-layer-group"></i> By station</h3>
                <table class="kr-table">
                    <thead>
                        <tr>
                            <th>Station</th>
                            <th>Items</th>
                            <th>Qty</th>
                            <th>Revenue</th>
                            <th>Avg Prep</th>
                            <th>Voids</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byStation as $s => $d): ?>
                            <tr>
                                <td><span class="stn-pill <?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($STATION_LABEL[$s] ?? ucfirst($s)); ?></span></td>
                                <td><?php echo number_format($d['items']); ?></td>
                                <td><?php echo number_format($d['qty'], 2); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($d['revenue'], 2); ?></td>
                                <td><?php echo rh_fmt_dur((int)$d['avg_seconds']); ?></td>
                                <td><?php echo $d['voids']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($topItems): ?>
            <div class="panel">
                <h3><i class="fas fa-trophy"></i> Top items by revenue</h3>
                <table class="kr-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Station</th>
                            <th>Qty sold</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 0;
                        foreach ($topItems as $it): $i++; ?>
                            <tr>
                                <td><?php echo $i; ?></td>
                                <td><?php echo htmlspecialchars($it['name']); ?></td>
                                <td><span class="stn-pill <?php echo htmlspecialchars($it['station'] ?: 'unknown'); ?>"><?php echo htmlspecialchars($STATION_LABEL[$it['station']] ?? ucfirst($it['station'] ?: '—')); ?></span></td>
                                <td><?php echo number_format($it['qty'], 2); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($it['revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h3><i class="fas fa-list"></i> All tickets (<?php echo count($rows); ?>)</h3>
            <?php if (!$rows): ?>
                <div class="empty"><i class="fas fa-inbox" style="font-size:32px; color:#d1d5db;"></i>
                    <p>No tickets for the selected day &amp; station.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="kr-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Type</th>
                                <th>Table / Guest</th>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Stn</th>
                                <th>Status</th>
                                <th>Fired</th>
                                <th>Started</th>
                                <th>Ready</th>
                                <th>Served</th>
                                <th>Prep</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $prepSec = ($r['fired_at'] && $r['served_at']) ? max(0, (int)(strtotime($r['served_at']) - strtotime($r['fired_at']))) : 0;
                            ?>
                                <tr>
                                    <td><a href="order-lifecycle.php?id=<?php echo (int)$r['order_id']; ?>" target="_blank" style="color:#8B7355; font-weight:600;"><?php echo htmlspecialchars($r['reference']); ?></a></td>
                                    <td><?php echo htmlspecialchars($r['order_type']); ?></td>
                                    <td><?php echo htmlspecialchars(($r['table_number'] ? 'T' . $r['table_number'] : '') . ($r['customer_name'] ? ' · ' . $r['customer_name'] : '')); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($r['item_name']); ?>
                                        <?php if ($r['item_notes']): ?><br><small style="color:#8B7355; font-style:italic;"><?php echo htmlspecialchars($r['item_notes']); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.'); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format((float)$r['line_total'], 2); ?></td>
                                    <td><span class="stn-pill <?php echo htmlspecialchars($r['station'] ?: 'unknown'); ?>"><?php echo htmlspecialchars($STATION_LABEL[$r['station']] ?? '—'); ?></span></td>
                                    <td><span class="ks-pill <?php echo htmlspecialchars($r['kds_status']); ?>"><?php echo htmlspecialchars($r['kds_status']); ?></span></td>
                                    <td><?php echo rh_fmt_dt($r['fired_at']); ?></td>
                                    <td><?php echo rh_fmt_dt($r['started_at']); ?></td>
                                    <td><?php echo rh_fmt_dt($r['ready_at']); ?></td>
                                    <td><?php echo rh_fmt_dt($r['served_at']); ?></td>
                                    <td style="font-variant-numeric:tabular-nums;"><?php echo $prepSec ? rh_fmt_dur($prepSec) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($r['bumped_by_name'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Email modal -->
    <div id="emailModal" class="modal-overlay" data-modal role="dialog" aria-modal="true" aria-labelledby="emailModalTitle">
        <form method="POST" class="modal-content" style="max-width:min(96vw,32.5rem); width:min(96vw,32.5rem);">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="email_report">
            <div class="modal-header">
                <h3 class="modal-title" id="emailModalTitle"><i class="fas fa-envelope" style="color:#17a2b8;"></i> Email station report</h3>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeKdsEmailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px; color:#6c757d; margin:0 0 12px;">Sends an HTML summary of <strong><?php echo htmlspecialchars($STATION_OPTIONS[$reqStation]); ?></strong> for <strong><?php echo $reqDate; ?></strong>.</p>
                <label style="font-size:12px; color:#6c757d; font-weight:600;">Recipient email</label>
                <input type="email" name="to" required value="<?php echo htmlspecialchars(getEmailSetting('email_admin_email', '')); ?>" style="width:100%; padding:10px; border:1px solid #d8dde3; border-radius:6px; font-size:13px; margin-top:4px; margin-bottom:14px;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeKdsEmailModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
            </div>
        </form>
    </div>
    <script>
        function openKdsEmailModal() {
            var modal = document.getElementById('emailModal');
            if (!modal) {
                return;
            }
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function closeKdsEmailModal() {
            var modal = document.getElementById('emailModal');
            if (!modal) {
                return;
            }
            modal.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.classList.remove('modal-open');
            }
        }
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

